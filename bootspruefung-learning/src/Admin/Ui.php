<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

defined('ABSPATH') || exit;

/** Kleine Helfer für die Adminseiten (Tabellen, Hinweise, Status-Badges). */
final class Ui
{
    private const FLASH = 'bp_learning_flash_';
    private const CONFIRM = 'bp_learning_confirm_';

    /** @param array<string,scalar> $args */
    public static function url(string $page, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $page], array_filter($args, static fn ($v) => $v !== '' && $v !== null)), admin_url('admin.php'));
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        set_transient(self::FLASH.get_current_user_id(), ['message' => $message, 'type' => $type], 60);
    }

    public static function notice(): void
    {
        $key = self::FLASH.get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', in_array($flash['type'], ['error', 'warning', 'info'], true) ? $flash['type'] : 'success', esc_html($flash['message']));
        }
    }

    /** Wurde die Rückfrage bestätigt (JS-Dialog setzt bp_confirmed, sonst der Knopf auf der Zwischenseite)? */
    public static function confirmed(): bool
    {
        return ! empty($_POST['bp_confirmed']); // phpcs:ignore WordPress.Security.NonceVerification -- Nonce prüft der Aufrufer
    }

    /**
     * Rückfrage ohne JavaScript (T103): Formularwerte merken und zur Seite $back zurück, die sie mit confirmBox()
     * als Zwischenschritt „Wirklich ausführen?“ zeigt. Mit JavaScript bestätigt der Dialog vorher (bp_confirmed=1).
     * @param array<string,string> $fields
     */
    public static function rememberConfirm(string $action, string $nonce, array $fields, string $message, string $back): string
    {
        set_transient(self::CONFIRM.get_current_user_id(), ['action' => $action, 'nonce' => $nonce, 'fields' => $fields, 'message' => $message], 10 * MINUTE_IN_SECONDS);

        return add_query_arg('bp_confirm', '1', $back);
    }

    /** Zwischenschritt anzeigen, wenn ein Formular auf Bestätigung wartet (siehe rememberConfirm()). */
    public static function confirmBox(): void
    {
        if (empty($_GET['bp_confirm'])) { // phpcs:ignore WordPress.Security.NonceVerification -- nur Anzeige
            return;
        }
        $pending = get_transient(self::CONFIRM.get_current_user_id());
        if (! is_array($pending) || ! isset($pending['action'], $pending['nonce'], $pending['fields'], $pending['message'])) {
            return;
        }
        echo '<div class="notice notice-warning bp-confirm"><p><strong>'.esc_html__('Bitte bestätigen', 'bootspruefung-learning').':</strong> '.esc_html((string) $pending['message']).'</p>';
        if (($pending['fields']['reason'] ?? '') !== '') {
            echo '<p>'.esc_html__('Begründung', 'bootspruefung-learning').': '.esc_html((string) $pending['fields']['reason']).'</p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><p>'.wp_nonce_field((string) $pending['nonce'], '_wpnonce', true, false); // phpcs:ignore
        printf('<input type="hidden" name="action" value="%s"><input type="hidden" name="bp_confirmed" value="1">', esc_attr((string) $pending['action']));
        foreach ((array) $pending['fields'] as $name => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr((string) $name), esc_attr((string) $value));
        }
        echo '<button type="submit" class="button button-primary">'.esc_html__('Ja, ausführen', 'bootspruefung-learning').'</button> ';
        echo '<a class="button" href="'.esc_url(remove_query_arg('bp_confirm')).'">'.esc_html__('Abbrechen', 'bootspruefung-learning').'</a></p></form></div>';
    }

    /**
     * @param list<string> $headers bereits als Text (wird escaped)
     * @param list<list<string|int>> $rows bereits escaptes HTML
     */
    public static function table(array $headers, array $rows, ?string $empty = null): void
    {
        // T126: am Handy seitlich scrollen statt die Seite zu verbreitern (CSS .bp-table-wrap in admin.css).
        echo '<div class="bp-table-wrap"><table class="widefat striped bp-table"><thead><tr>';
        foreach ($headers as $h) {
            echo '<th scope="col">'.esc_html($h).'</th>';
        }
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="'.count($headers).'">'.esc_html($empty ?? __('Keine Einträge.', 'bootspruefung-learning')).'</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>'.$cell.'</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Zellen werden vom Aufrufer escaped.
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /** @param array<string,string> $pairs Label => bereits escaptes HTML */
    public static function keyValue(array $pairs): void
    {
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($pairs as $label => $html) {
            echo '<tr><th scope="row" style="width:220px">'.esc_html($label).'</th><td>'.$html.'</td></tr>'; // phpcs:ignore
        }
        echo '</tbody></table>';
    }

    public static function status(?string $status): string
    {
        $status = (string) $status;
        $tones = [
            'published' => 'green', 'validated' => 'blue', 'previewed' => 'blue', 'approved' => 'blue', 'importing' => 'amber',
            'failed' => 'red', 'discarded' => 'gray', 'draft' => 'gray', 'review' => 'amber', 'deprecated' => 'gray',
            'source_archived' => 'gray', 'unknown' => 'red', 'restricted' => 'red', 'licensed' => 'green', 'public' => 'green',
        ];

        return self::badge(self::label($status), $tones[$status] ?? 'dark', $status);
    }

    /**
     * Farben der Status-Badges mit weißer Schrift, alle mit Kontrast ≥ 4,5:1 (WCAG AA, T104):
     * grün 6,3 · blau 5,2 · gelbbraun 4,8 · rot 6,3 · grau 5,5 · dunkelgrau 7,3.
     */
    public const BADGE_COLORS = ['green' => '#007017', 'blue' => '#2271b1', 'amber' => '#996800', 'red' => '#b32d2e', 'gray' => '#646970', 'dark' => '#50575e'];

    /** Badge mit Text (nie nur Farbe); $title = Rohwert als Tooltip. */
    public static function badge(string $text, string $tone, string $title = ''): string
    {
        $color = self::BADGE_COLORS[$tone] ?? self::BADGE_COLORS['dark'];

        return sprintf('<span class="bp-badge"%s style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:#fff;background:%s">%s</span>', $title !== '' ? ' title="'.esc_attr($title).'"' : '', esc_attr($color), esc_html($text));
    }

    /** Ja/Nein als Text statt ✓/✗ (Bildschirmleser, T104). */
    public static function yesNo(bool $value): string
    {
        return $value ? esc_html__('ja', 'bootspruefung-learning') : esc_html__('nein', 'bootspruefung-learning');
    }

    /** Deutsche Bezeichnung für interne Status- und Typwerte (Admin-Anzeige; der Rohwert bleibt im title). */
    public static function label(?string $value): string
    {
        $value = (string) $value;
        $labels = [
            // Inhalte und Regelwerke
            'published' => __('veröffentlicht', 'bootspruefung-learning'),
            'draft' => __('Entwurf', 'bootspruefung-learning'),
            'source_archived' => __('archiviert', 'bootspruefung-learning'),
            'deprecated' => __('veraltet', 'bootspruefung-learning'),
            'live' => __('Prüfungsmodus', 'bootspruefung-learning'),
            // Rechte
            'unknown' => __('ungeklärt', 'bootspruefung-learning'),
            'review' => __('in Prüfung', 'bootspruefung-learning'),
            'licensed' => __('lizenziert', 'bootspruefung-learning'),
            'public' => __('frei (gemeinfrei)', 'bootspruefung-learning'),
            'restricted' => __('gesperrt', 'bootspruefung-learning'),
            // Importe
            'uploaded' => __('hochgeladen', 'bootspruefung-learning'),
            'validated' => __('geprüft', 'bootspruefung-learning'),
            'failed' => __('fehlerhaft', 'bootspruefung-learning'),
            'previewed' => __('Vorschau erstellt', 'bootspruefung-learning'),
            'approved' => __('freigegeben', 'bootspruefung-learning'),
            'importing' => __('wird importiert', 'bootspruefung-learning'),
            'discarded' => __('verworfen', 'bootspruefung-learning'),
            // Kurstypen
            'full' => __('Vollkurs', 'bootspruefung-learning'),
            'combo' => __('Kombikurs', 'bootspruefung-learning'),
            'addon' => __('Ergänzungskurs', 'bootspruefung-learning'),
            // Fragetypen
            'single_choice' => __('Einfachauswahl', 'bootspruefung-learning'),
            'multi_choice' => __('Mehrfachauswahl', 'bootspruefung-learning'),
            'open_text' => __('Freitext', 'bootspruefung-learning'),
            'chart_task' => __('Kartenaufgabe', 'bootspruefung-learning'),
        ];

        return $labels[$value] ?? $value;
    }

    public static function date(?string $mysqlGmt): string
    {
        if ($mysqlGmt === null || $mysqlGmt === '') {
            return '–';
        }

        return get_date_from_gmt($mysqlGmt, get_option('date_format').' '.get_option('time_format'));
    }

    public static function entityLabel(string $entity): string
    {
        $labels = [
            'courses' => __('Kurse', 'bootspruefung-learning'),
            'modules' => __('Module', 'bootspruefung-learning'),
            'questions' => __('Fragen', 'bootspruefung-learning'),
            'question' => __('Frage', 'bootspruefung-learning'),
            'media' => __('Medien', 'bootspruefung-learning'),
            'exam_rules' => __('Prüfungsregeln', 'bootspruefung-learning'),
            'exam_rule' => __('Prüfungsregel', 'bootspruefung-learning'),
            'exam_papers' => __('Prüfungsbögen', 'bootspruefung-learning'),
            'navigation_tasks' => __('Navigationsaufgaben', 'bootspruefung-learning'),
            'navigation_questions' => __('Navigations-Teilaufgaben', 'bootspruefung-learning'),
            'praxis_tasks' => __('Praxiskarten', 'bootspruefung-learning'),
            'praxis_task' => __('Praxiskarte', 'bootspruefung-learning'),
            'module_links' => __('Modul-Fragen-Zuordnung', 'bootspruefung-learning'),
            'course_links' => __('Kurs-Modul-Zuordnung', 'bootspruefung-learning'),
            'archive' => __('Archivierung', 'bootspruefung-learning'),
            'video_modules' => __('Videokapitel', 'bootspruefung-learning'),
            'video_module' => __('Videokapitel', 'bootspruefung-learning'),
            'video_lessons' => __('Videolektionen', 'bootspruefung-learning'),
            'radio_texts' => __('Funktexte', 'bootspruefung-learning'),
            'radio_text' => __('Funktext', 'bootspruefung-learning'),
            'radio_vocabulary' => __('Funk-Vokabeln', 'bootspruefung-learning'),
            'radio_practice' => __('Funkpraxis', 'bootspruefung-learning'),
            'device_simulation' => __('Gerätesimulation', 'bootspruefung-learning'),
            'exam_committees' => __('Prüfungsausschüsse', 'bootspruefung-learning'),
            'exam_guides' => __('Anleitungen Prüfungsanmeldung', 'bootspruefung-learning'),
        ];

        return $labels[$entity] ?? $entity;
    }

    /** @param callable(int):string $url */
    public static function pager(int $page, bool $hasMore, callable $url): void
    {
        echo '<p class="bp-pager">';
        if ($page > 1) {
            echo '<a class="button" href="'.esc_url($url($page - 1)).'">&larr; '.esc_html__('zurück', 'bootspruefung-learning').'</a> ';
        }
        echo '<span>'.sprintf(esc_html__('Seite %d', 'bootspruefung-learning'), $page).'</span> ';
        if ($hasMore) {
            echo '<a class="button" href="'.esc_url($url($page + 1)).'">'.esc_html__('weiter', 'bootspruefung-learning').' &rarr;</a>';
        }
        echo '</p>';
    }
}
