<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

/**
 * Gemeinsame Admin-Hinweise von Plugin und Theme (To-do T99): einheitlicher Absender „Bootsprüfung:“, gebündelt
 * zu einem Sammel-Hinweis, wenn mehrere gleichzeitig anstehen, und je Hinweis-Schlüssel wegklickbar (Nutzer-Meta).
 *
 * Wegklicken je nach Art:
 *  - 'forever': dauerhaft ausblenden (bis sich die Version des Hinweises ändert, z. B. neue offene Punkte);
 *  - 'snooze':  nur vertagen (Standard 7 Tage, bei Fehlern 1 Tag) – für Dringendes und Fehler;
 *  - 'none':    nicht wegklickbar (einmalige Meldungen, kritische Fehler).
 * Fehler-Hinweise (type error) sind nie dauerhaft wegklickbar, höchstens vertagbar.
 *
 * Aufruf: Notices::add([...]) im Hook admin_notices (Priorität < 100); ausgegeben wird gesammelt bei Priorität 100.
 * Das Theme ruft dieselbe Klasse über kurswasser_admin_notice() auf (Fallback ohne Plugin).
 */
final class Notices
{
    public const META = 'bp_notices_dismissed';
    public const ACTION = 'bp_notice_dismiss';
    public const SENDER = 'Bootsprüfung:';

    /** @var array<string,array<string,mixed>> */
    private static array $queue = [];

    private static bool $registered = false;

    private static bool $scriptPrinted = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action('admin_notices', [self::class, 'flush'], 100);
        add_action('wp_ajax_'.self::ACTION, [self::class, 'ajaxDismiss']);
        add_action('admin_post_'.self::ACTION, [self::class, 'postDismiss']);
    }

    /**
     * Hinweis vormerken (wird nicht gezeigt, wenn weggeklickt bzw. vertagt).
     *
     * @param array{key:string,type?:string,title?:string,text?:string,html?:string,buttons?:list<array{0:string,1:string,2?:bool}>,dismiss?:string,snooze_days?:int,version?:string} $args
     */
    public static function add(array $args): void
    {
        $n = self::normalize($args);
        if ($n === null || self::isDismissed($n['key'], $n['version'])) {
            return;
        }
        self::register();
        self::$queue[$n['key']] = $n;
    }

    /** Vorgemerkte Hinweise (für Tests). @return array<string,array<string,mixed>> */
    public static function queued(): array
    {
        return self::$queue;
    }

    /** Ausgabe bei admin_notices (Priorität 100): ein Hinweis einzeln, mehrere als Sammel-Hinweis. */
    public static function flush(): void
    {
        $queue = self::$queue;
        self::$queue = [];
        if ($queue === []) {
            return;
        }
        echo self::bundle(array_values($queue)); // phpcs:ignore WordPress.Security.EscapeOutput -- in render maskiert
        self::script();
    }

    /** @param list<array<string,mixed>> $notices */
    public static function bundle(array $notices): string
    {
        if (count($notices) === 1) {
            return self::render($notices[0]);
        }
        $rank = ['error' => 3, 'warning' => 2, 'info' => 1, 'success' => 0];
        usort($notices, static fn ($a, $b) => $rank[$b['type']] <=> $rank[$a['type']]);
        $type = $notices[0]['type'];
        $html = '<div class="notice notice-'.esc_attr($type).' bp-notices"><p><strong>'.esc_html(sprintf(__('Bootsprüfung: %d Hinweise', 'bootspruefung-learning'), count($notices))).'</strong></p>';
        foreach ($notices as $n) {
            $html .= self::render($n, true);
        }

        return $html.'</div>';
    }

    /**
     * HTML eines Hinweises. Erwartet die Felder aus add() (wird bei Bedarf normalisiert).
     * @param array<string,mixed> $args
     */
    public static function render(array $args, bool $inner = false): string
    {
        $n = self::normalize($args);
        if ($n === null) {
            return '';
        }
        $colors = ['error' => '#d63638', 'warning' => '#dba617', 'info' => '#72aee6', 'success' => '#00a32a'];
        $open = $inner
            ? '<div class="bp-notice-item" data-bp-notice="'.esc_attr($n['key']).'" style="border-left:4px solid '.esc_attr($colors[$n['type']]).';padding:.1em 0 .1em .8em;margin:.6em 0">'
            : '<div class="notice notice-'.esc_attr($n['type']).' bp-notice" data-bp-notice="'.esc_attr($n['key']).'">';
        $title = $n['title'] !== '' ? $n['title'] : '';
        $html = $open.'<p>'.($inner ? '' : '<strong>'.esc_html(self::SENDER).'</strong> ').($title !== '' ? '<strong>'.esc_html($title).'</strong>' : '')
            .($n['text'] !== '' ? ($title !== '' ? ' ' : '').esc_html($n['text']) : '').'</p>'.$n['html'];
        $actions = [];
        foreach ($n['buttons'] as $b) {
            $actions[] = '<a class="button'.(! empty($b[2]) ? ' button-primary' : '').'" href="'.esc_url((string) $b[1]).'">'.esc_html((string) $b[0]).'</a>';
        }
        if ($n['dismiss'] !== 'none' && get_current_user_id() > 0) {
            $days = $n['dismiss'] === 'snooze' ? $n['snooze_days'] : 0;
            $url = wp_nonce_url(add_query_arg(['action' => self::ACTION, 'key' => $n['key'], 'days' => $days, 'v' => $n['version']], admin_url('admin-post.php')), self::ACTION);
            $label = $days > 0
                ? sprintf(_n('Für %d Tag ausblenden', 'Für %d Tage ausblenden', $days, 'bootspruefung-learning'), $days)
                : __('Ausblenden', 'bootspruefung-learning');
            $actions[] = '<a class="button-link bp-notice-dismiss" href="'.esc_url($url).'" data-key="'.esc_attr($n['key']).'" data-days="'.(int) $days.'" data-v="'.esc_attr($n['version']).'">'.esc_html($label).'</a>';
        }
        if ($actions !== []) {
            $html .= '<p class="bp-notice-actions">'.implode(' ', $actions).'</p>';
        }

        return $html.'</div>';
    }

    /** @return array<string,mixed>|null */
    private static function normalize(array $args): ?array
    {
        $key = sanitize_key((string) ($args['key'] ?? ''));
        if ($key === '') {
            return null;
        }
        $type = in_array($args['type'] ?? '', ['error', 'warning', 'info', 'success'], true) ? (string) $args['type'] : 'info';
        $dismiss = in_array($args['dismiss'] ?? '', ['forever', 'snooze', 'none'], true) ? (string) $args['dismiss'] : ($type === 'error' ? 'snooze' : ($type === 'warning' ? 'snooze' : 'forever'));
        if ($type === 'error' && $dismiss === 'forever') {
            $dismiss = 'snooze'; // Fehler nie dauerhaft wegklickbar
        }
        $days = (int) ($args['snooze_days'] ?? ($type === 'error' ? 1 : 7));

        return [
            'key' => $key,
            'type' => $type,
            'title' => (string) ($args['title'] ?? ''),
            'text' => (string) ($args['text'] ?? ''),
            'html' => (string) ($args['html'] ?? ''),
            'buttons' => array_values(array_filter((array) ($args['buttons'] ?? []), static fn ($b) => is_array($b) && isset($b[0], $b[1]))),
            'dismiss' => $dismiss,
            'snooze_days' => max(1, min(90, $days)),
            'version' => substr(sanitize_key((string) ($args['version'] ?? '')), 0, 40),
        ];
    }

    /** Weggeklickt bzw. noch vertagt? Eine andere Version (z. B. neue offene Punkte) zeigt den Hinweis wieder. */
    public static function isDismissed(string $key, string $version = '', ?int $userId = null): bool
    {
        $userId ??= get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        $all = get_user_meta($userId, self::META, true);
        $entry = is_array($all) ? ($all[sanitize_key($key)] ?? null) : null;
        if (! is_array($entry) || (string) ($entry['v'] ?? '') !== substr(sanitize_key($version), 0, 40)) {
            return false;
        }
        $until = (int) ($entry['until'] ?? 0);

        return $until === 0 || $until > time();
    }

    /** Ausblenden: $days = 0 dauerhaft, sonst vertagen. */
    public static function dismiss(string $key, int $days = 0, string $version = '', ?int $userId = null): void
    {
        $userId ??= get_current_user_id();
        $key = sanitize_key($key);
        if ($userId <= 0 || $key === '') {
            return;
        }
        $all = get_user_meta($userId, self::META, true);
        $all = is_array($all) ? $all : [];
        $all[$key] = ['until' => $days > 0 ? time() + min(90, $days) * DAY_IN_SECONDS : 0, 'v' => substr(sanitize_key($version), 0, 40)];
        update_user_meta($userId, self::META, $all);
    }

    public static function ajaxDismiss(): void
    {
        check_ajax_referer(self::ACTION);
        if (! is_user_logged_in() || ! current_user_can('read')) {
            wp_send_json_error(null, 403);
        }
        self::dismiss(sanitize_key(wp_unslash($_POST['key'] ?? '')), absint($_POST['days'] ?? 0), sanitize_key(wp_unslash($_POST['v'] ?? '')));
        wp_send_json_success();
    }

    public static function postDismiss(): void
    {
        check_admin_referer(self::ACTION);
        if (! is_user_logged_in()) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        self::dismiss(sanitize_key(wp_unslash($_GET['key'] ?? '')), absint($_GET['days'] ?? 0), sanitize_key(wp_unslash($_GET['v'] ?? '')));
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** Wegklicken ohne Neuladen (ohne JavaScript führt der Link über admin-post.php zurück). */
    private static function script(): void
    {
        if (self::$scriptPrinted) {
            return;
        }
        self::$scriptPrinted = true;
        $nonce = wp_create_nonce(self::ACTION);
        echo '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".bp-notice-dismiss");if(!a){return;}e.preventDefault();'
            .'var d=new FormData();d.append("action",'.wp_json_encode(self::ACTION).');d.append("_ajax_nonce",'.wp_json_encode($nonce).');d.append("key",a.dataset.key);d.append("days",a.dataset.days);d.append("v",a.dataset.v||"");'
            .'fetch('.wp_json_encode(admin_url('admin-ajax.php')).',{method:"POST",body:d,credentials:"same-origin"}).then(function(r){if(!r.ok){window.location.href=a.href;return;}'
            .'var box=a.closest("[data-bp-notice]");var outer=box&&box.parentNode&&box.parentNode.closest?box.parentNode.closest(".bp-notices"):null;if(box){box.remove();}'
            .'if(outer&&!outer.querySelector("[data-bp-notice]")){outer.remove();}}).catch(function(){window.location.href=a.href;});});</script>'; // phpcs:ignore
    }
}
