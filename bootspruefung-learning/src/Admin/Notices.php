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
 * Wegklicken nur mit gültigem Link (T125): Schlüssel, Tage und Version sind je Nutzer signiert (wp_hash), so lassen
 * sich nur tatsächlich gezeigte Hinweise ausblenden – mit genau der angebotenen Dauer (bei Fehlern mindestens 1 Tag).
 * Das Nutzer-Meta behält höchstens MAX_ENTRIES Einträge (abgelaufene Vertagungen fallen zuerst heraus).
 *
 * Aufruf: Notices::add([...]) im Hook admin_notices (Priorität < 100); ausgegeben wird gesammelt bei Priorität 100.
 * Das Theme ruft dieselbe Klasse über kurswasser_admin_notice() auf (Fallback ohne Plugin).
 */
final class Notices
{
    public const META = 'bp_notices_dismissed';
    public const ACTION = 'bp_notice_dismiss';
    public const SENDER = 'Bootsprüfung:';
    /** Höchstzahl gemerkter Einträge je Nutzer (Nutzer-Meta). */
    public const MAX_ENTRIES = 50;

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
        // T126: Vorlagen für den Zähler, den das Skript nach dem Wegklicken neu setzt (Einzahl/Mehrzahl).
        $html = '<div class="notice notice-'.esc_attr($type).' bp-notices" data-bp-one="'.esc_attr(sprintf(_n('Bootsprüfung: %d Hinweis', 'Bootsprüfung: %d Hinweise', 1, 'bootspruefung-learning'), 1)).'" data-bp-many="'.esc_attr(_n('Bootsprüfung: %d Hinweis', 'Bootsprüfung: %d Hinweise', 2, 'bootspruefung-learning')).'">'
            .'<p><strong class="bp-notices-count">'.esc_html(sprintf(_n('Bootsprüfung: %d Hinweis', 'Bootsprüfung: %d Hinweise', count($notices), 'bootspruefung-learning'), count($notices))).'</strong></p>';
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
            ? '<div class="bp-notice-item" data-bp-notice="'.esc_attr($n['key']).'" data-bp-type="'.esc_attr($n['type']).'" style="border-left:4px solid '.esc_attr($colors[$n['type']]).';padding:.1em 0 .1em .8em;margin:.6em 0">'
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
            $sig = self::signature($n['key'], $days, $n['version']);
            $url = wp_nonce_url(add_query_arg(['action' => self::ACTION, 'key' => $n['key'], 'days' => $days, 'v' => $n['version'], 'sig' => $sig], admin_url('admin-post.php')), self::ACTION);
            $label = $days > 0
                ? sprintf(_n('Für %d Tag ausblenden', 'Für %d Tage ausblenden', $days, 'bootspruefung-learning'), $days)
                : __('Ausblenden', 'bootspruefung-learning');
            // T126: Bildschirmleser hören, welcher Hinweis ausgeblendet wird.
            $about = $n['title'] !== '' ? $n['title'] : wp_html_excerpt($n['text'], 60, '…');
            $aria = $about !== '' ? ' aria-label="'.esc_attr($label.': '.$about).'"' : '';
            $actions[] = '<a class="button-link bp-notice-dismiss" href="'.esc_url($url).'"'.$aria.' data-key="'.esc_attr($n['key']).'" data-days="'.(int) $days.'" data-v="'.esc_attr($n['version']).'" data-sig="'.esc_attr($sig).'">'.esc_html($label).'</a>';
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

    /**
     * Ausblenden: $days = 0 dauerhaft, sonst vertagen. Hält höchstens MAX_ENTRIES Einträge je Nutzer: abgelaufene
     * Vertagungen fallen weg, danach die am längsten zurückliegenden.
     */
    public static function dismiss(string $key, int $days = 0, string $version = '', ?int $userId = null): void
    {
        $userId ??= get_current_user_id();
        $key = sanitize_key($key);
        if ($userId <= 0 || $key === '') {
            return;
        }
        $all = get_user_meta($userId, self::META, true);
        $all = is_array($all) ? $all : [];
        $now = time();
        unset($all[$key]);
        $all = array_filter($all, static fn ($e): bool => is_array($e) && ((int) ($e['until'] ?? 0) === 0 || (int) $e['until'] > $now));
        if (count($all) >= self::MAX_ENTRIES) {
            uasort($all, static fn ($a, $b): int => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
            $all = array_slice($all, 0, self::MAX_ENTRIES - 1, true);
        }
        $all[$key] = ['until' => $days > 0 ? $now + min(90, $days) * DAY_IN_SECONDS : 0, 'v' => substr(sanitize_key($version), 0, 40), 'at' => $now];
        update_user_meta($userId, self::META, $all);
    }

    /**
     * Signatur eines Ausblenden-Links (T125): bindet Schlüssel, Tage und Version an den Nutzer. Nur render() erzeugt
     * sie – damit lassen sich nur gezeigte Hinweise wegklicken, und ein Fehler-Hinweis (mindestens 1 Tag) nicht
     * durch Ändern von days=0 dauerhaft.
     */
    public static function signature(string $key, int $days, string $version, ?int $userId = null): string
    {
        $userId ??= get_current_user_id();

        return substr(wp_hash('bp_notice_dismiss|'.$userId.'|'.sanitize_key($key).'|'.max(0, $days).'|'.substr(sanitize_key($version), 0, 40), 'nonce'), 0, 24);
    }

    /**
     * Wegklick-Anfrage auswerten (AJAX bzw. Link): nur mit gültiger Signatur. true = ausgeblendet.
     * @param array<string,mixed> $input $_POST bzw. $_GET
     */
    public static function dismissFromRequest(array $input): bool
    {
        $key = sanitize_key(wp_unslash((string) ($input['key'] ?? '')));
        $days = absint($input['days'] ?? 0);
        $version = sanitize_key(wp_unslash((string) ($input['v'] ?? '')));
        $sig = sanitize_key(wp_unslash((string) ($input['sig'] ?? '')));
        if ($key === '' || $sig === '' || ! hash_equals(self::signature($key, $days, $version), $sig)) {
            return false;
        }
        self::dismiss($key, $days, $version);

        return true;
    }

    public static function ajaxDismiss(): void
    {
        check_ajax_referer(self::ACTION);
        if (! is_user_logged_in() || ! current_user_can('read')) {
            wp_send_json_error(null, 403);
        }
        if (! self::dismissFromRequest($_POST)) { // phpcs:ignore WordPress.Security.NonceVerification -- oben geprüft
            wp_send_json_error(null, 400);
        }
        wp_send_json_success();
    }

    public static function postDismiss(): void
    {
        check_admin_referer(self::ACTION);
        if (! is_user_logged_in()) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        if (! self::dismissFromRequest($_GET)) { // phpcs:ignore WordPress.Security.NonceVerification -- oben geprüft
            wp_die(esc_html__('Dieser Link zum Ausblenden ist ungültig oder veraltet. Bitte die Seite neu laden.', 'bootspruefung-learning'), '', ['response' => 400, 'back_link' => true]);
        }
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
        // T126: Sammel-Hinweis nach dem Wegklicken – Zähler und Farbe (dringendster übriger Hinweis) neu setzen.
        echo '<script>function bpRecount(o){var it=o.querySelectorAll("[data-bp-notice]"),n=it.length,r={error:3,warning:2,info:1,success:0},t="success";'
            .'it.forEach(function(i){var x=i.getAttribute("data-bp-type")||"info";if((r[x]||0)>r[t]){t=x;}});var c=o.querySelector(".bp-notices-count");'
            .'if(c){c.textContent=String((n===1?o.getAttribute("data-bp-one"):o.getAttribute("data-bp-many"))||"").replace("%d",n);}'
            .'o.className=o.className.replace(/\bnotice-(error|warning|info|success)\b/,"notice-"+t);}'
            .'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".bp-notice-dismiss");if(!a){return;}e.preventDefault();'
            .'var d=new FormData();d.append("action",'.wp_json_encode(self::ACTION).');d.append("_ajax_nonce",'.wp_json_encode($nonce).');d.append("key",a.dataset.key);d.append("days",a.dataset.days);d.append("v",a.dataset.v||"");d.append("sig",a.dataset.sig||"");'
            .'fetch('.wp_json_encode(admin_url('admin-ajax.php')).',{method:"POST",body:d,credentials:"same-origin"}).then(function(r){if(!r.ok){window.location.href=a.href;return;}'
            .'var box=a.closest("[data-bp-notice]");var outer=box&&box.parentNode&&box.parentNode.closest?box.parentNode.closest(".bp-notices"):null;if(box){box.remove();}'
            .'if(outer&&!outer.querySelector("[data-bp-notice]")){outer.remove();}else if(outer){bpRecount(outer);}}).catch(function(){window.location.href=a.href;});});</script>'; // phpcs:ignore
    }
}
