<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

/** Kleine Helfer für die Adminseiten (Tabellen, Hinweise, Status-Badges). */
final class Ui
{
    private const FLASH = 'bp_learning_flash_';

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
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $flash['type'] === 'error' ? 'error' : 'success', esc_html($flash['message']));
        }
    }

    /**
     * @param list<string> $headers bereits als Text (wird escaped)
     * @param list<list<string|int>> $rows bereits escaptes HTML
     */
    public static function table(array $headers, array $rows, ?string $empty = null): void
    {
        echo '<table class="widefat striped bp-table"><thead><tr>';
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
        echo '</tbody></table>';
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
        $colors = [
            'published' => '#00a32a', 'validated' => '#2271b1', 'previewed' => '#2271b1', 'approved' => '#2271b1', 'importing' => '#dba617',
            'failed' => '#d63638', 'discarded' => '#787c82', 'draft' => '#787c82', 'review' => '#dba617', 'deprecated' => '#787c82',
            'source_archived' => '#787c82', 'unknown' => '#d63638', 'restricted' => '#d63638', 'licensed' => '#00a32a', 'public' => '#00a32a',
        ];
        $color = $colors[$status] ?? '#50575e';

        return sprintf('<span class="bp-badge" style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:#fff;background:%s">%s</span>', esc_attr($color), esc_html($status));
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
