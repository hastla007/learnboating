<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Audit\AuditLog;

/**
 * Livegang-Checkliste auf der Übersicht (To-do T99): die Voraussetzungen aus dem Livegang-Drehbuch (Abschnitt 1)
 * als Liste mit Status. Was sich prüfen lässt, prüft das Plugin selbst (Livegang-Ampel, Shop-Grundeinstellungen);
 * alles andere hakt der Betreiber ab. Abgehakt wird für die ganze Website (Option, mit Name und Datum, im Protokoll).
 */
final class GoLiveChecklist
{
    public const OPTION = 'bp_learning_golive_checklist';
    public const ACTION = 'bp_golive_tick';

    public static function register(): void
    {
        add_action('admin_post_'.self::ACTION, [self::class, 'handle']);
    }

    /**
     * Punkte der Checkliste. auto = vom Plugin geprüft (ok true/false), sonst abhakbar (done).
     * @return list<array{key:string,label:string,auto:bool,ok:bool,detail:string,url:string,link:string,by?:int,at?:int}>
     */
    public static function items(): array
    {
        $items = [];
        foreach (GoLiveCheck::run() as $c) {
            $items[] = $c + ['auto' => true];
        }
        if (class_exists('WooCommerce')) {
            $open = array_values(array_filter(SystemStatus::shopBasics(), static fn ($b) => ! $b['ok']));
            $items[] = ['key' => 'shop_basics', 'label' => __('WooCommerce-Grundeinstellungen ohne Hinweis', 'bootspruefung-learning'), 'auto' => true, 'ok' => $open === [],
                'detail' => $open === [] ? __('Sprache, Bankverbindung, Shop-Adresse, E-Mail-Format und Absender in Ordnung.', 'bootspruefung-learning') : implode('; ', array_map(static fn ($b) => $b['label'], $open)),
                'url' => Ui::url('bootspruefung-status'), 'link' => __('Systemstatus', 'bootspruefung-learning')];
        }
        $manual = self::manualItems();
        $ticks = self::ticks();
        foreach ($manual as [$key, $label, $url, $link]) {
            $tick = $ticks[$key] ?? null;
            $items[] = ['key' => $key, 'label' => $label, 'auto' => false, 'ok' => $tick !== null, 'detail' => '', 'url' => $url, 'link' => $link]
                + ($tick !== null ? ['by' => (int) $tick['by'], 'at' => (int) $tick['at']] : []);
        }

        return $items;
    }

    /**
     * Abhakbare Punkte: [Schlüssel, Bezeichnung, Link, Linktext].
     * @return list<array{0:string,1:string,2:string,3:string}>
     */
    private static function manualItems(): array
    {
        $status = Ui::url('bootspruefung-status');
        $manual = [
            ['legal_review', __('Rechtstexte inhaltlich geprüft (Impressum, Datenschutz, AGB, Widerruf, Garantie)', 'bootspruefung-learning'), '', ''],
            ['tax', __('Steuer-Weg entschieden, Rechnungen geklärt', 'bootspruefung-learning'), '', ''],
            ['mail', __('Mailversand eingerichtet, Zustelltest bestanden', 'bootspruefung-learning'), $status, __('Testmail im Systemstatus', 'bootspruefung-learning')],
            ['payments', __('Zahlungsarten in der Testumgebung von Anfang bis Ende getestet (Kauf, Erstattung, Zahlungskonflikt)', 'bootspruefung-learning'), '', ''],
            ['staging', __('Abnahme auf der Testkopie mit dem Zielsystem bestanden', 'bootspruefung-learning'), '', ''],
            ['backup', __('Datensicherung und Wiederherstellung einmal geübt, Prüfung nach dem Wiederherstellen ohne Fehler', 'bootspruefung-learning'), $status, __('Prüfung im Systemstatus', 'bootspruefung-learning')],
            ['prices', __('Preise final', 'bootspruefung-learning'), Ui::url(PricePage::SLUG), __('Preise', 'bootspruefung-learning')],
        ];
        if (function_exists('kurswasser_content_open_items')) {
            $manual[] = ['content_pages', __('Inhaltsseiten ohne Warnung (Hinweis „Inhaltsseiten aus dem Theme“)', 'bootspruefung-learning'), admin_url('themes.php?page=kurswasser-setup'), __('Kurswasser einrichten', 'bootspruefung-learning')];
        }

        return $manual;
    }

    /** Bezeichnung eines abhakbaren Punkts (für das Protokoll), sonst null. */
    public static function label(string $key): ?string
    {
        foreach (self::manualItems() as [$k, $label]) {
            if ($k === $key) {
                return $label;
            }
        }

        return null;
    }

    /** @return array<string,array{by:int,at:int}> */
    public static function ticks(): array
    {
        $raw = get_option(self::OPTION, []);

        return is_array($raw) ? array_filter($raw, static fn ($t) => is_array($t) && isset($t['by'], $t['at'])) : [];
    }

    /** Punkt abhaken bzw. zurücknehmen (mit Name, Datum und Eintrag im Protokoll). */
    public static function tick(string $key, bool $done, ?int $userId = null): void
    {
        $key = sanitize_key($key);
        $ticks = self::ticks();
        if ($done) {
            $ticks[$key] = ['by' => $userId ?? get_current_user_id(), 'at' => time()];
        } else {
            unset($ticks[$key]);
        }
        update_option(self::OPTION, $ticks, false);
        AuditLog::record($done ? 'golive_checklist_done' : 'golive_checklist_undone', 'settings', $key);
    }

    public static function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        check_admin_referer(self::ACTION);
        $key = sanitize_key((string) wp_unslash($_POST['key'] ?? ''));
        $known = array_column(array_filter(self::items(), static fn ($i) => ! $i['auto']), 'key');
        if (in_array($key, $known, true)) {
            self::tick($key, ! empty($_POST['done']));
        }
        wp_safe_redirect(Ui::url(Menu::SLUG).'#bp-golive-checklist');
        exit;
    }

    /** Checkliste auf der Übersicht (aufgeklappt, solange etwas offen ist). */
    public static function render(): void
    {
        $items = self::items();
        $open = count(array_filter($items, static fn ($i) => ! $i['ok']));
        $canTick = current_user_can('manage_options');
        echo '<details id="bp-golive-checklist" class="bp-golive-checklist"'.($open > 0 ? ' open' : '').' style="margin:1em 0;max-width:1100px">';
        echo '<summary style="cursor:pointer"><h2 style="display:inline">'.esc_html__('Livegang-Checkliste', 'bootspruefung-learning').'</h2> '
            .esc_html($open > 0 ? sprintf(_n('(%d Punkt offen)', '(%d Punkte offen)', $open, 'bootspruefung-learning'), $open) : __('(alles erledigt)', 'bootspruefung-learning')).'</summary>';
        echo '<p class="description">'.esc_html__('Voraussetzungen vor dem Livegang. „geprüft“ ermittelt das Plugin selbst; die übrigen Punkte hakst du ab, wenn sie erledigt sind.', 'bootspruefung-learning').'</p>';
        $rows = [];
        foreach ($items as $i) {
            $state = $i['ok']
                ? '<span style="color:#008a20;font-weight:600">'.esc_html($i['auto'] ? __('grün', 'bootspruefung-learning') : __('erledigt', 'bootspruefung-learning')).'</span>'
                : '<span style="color:#b32d2e;font-weight:600">'.esc_html($i['auto'] ? __('rot', 'bootspruefung-learning') : __('offen', 'bootspruefung-learning')).'</span>';
            $detail = esc_html($i['detail']);
            if (! $i['auto'] && isset($i['by'])) {
                $user = get_userdata((int) $i['by']);
                $detail = esc_html(sprintf(__('abgehakt von %1$s am %2$s', 'bootspruefung-learning'), $user ? $user->display_name : '#'.(int) $i['by'], wp_date((string) get_option('date_format'), (int) $i['at'])));
            }
            if ($i['url'] !== '') {
                $detail .= ($detail !== '' ? ' · ' : '').'<a href="'.esc_url($i['url']).'">'.esc_html($i['link'] !== '' ? $i['link'] : __('Zur Stelle', 'bootspruefung-learning')).'</a>';
            }
            $action = $i['auto'] ? esc_html__('geprüft', 'bootspruefung-learning') : '';
            if (! $i['auto'] && $canTick) {
                $action = '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0">'.wp_nonce_field(self::ACTION, '_wpnonce', true, false)
                    .'<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="key" value="'.esc_attr($i['key']).'"><input type="hidden" name="done" value="'.($i['ok'] ? '0' : '1').'">'
                    .'<button type="submit" class="button button-small">'.esc_html($i['ok'] ? __('Zurücknehmen', 'bootspruefung-learning') : __('Abhaken', 'bootspruefung-learning')).'</button></form>';
            }
            $rows[] = [$state, '<span data-checklist="'.esc_attr($i['key']).'">'.esc_html($i['label']).'</span>', $detail, $action];
        }
        Ui::table([__('Stand', 'bootspruefung-learning'), __('Punkt', 'bootspruefung-learning'), __('Details', 'bootspruefung-learning'), __('Prüfung', 'bootspruefung-learning')], $rows);
        echo '</details>';
    }
}
