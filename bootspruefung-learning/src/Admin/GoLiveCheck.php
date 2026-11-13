<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Commerce\WooCommerce\LegalTextsEmail;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Livegang-Ampel (To-do T97): Was muss stehen, bevor der Shop für Kunden sichtbar wird? Je Punkt grün/rot mit
 * Erklärung und Link zur Stelle. Genutzt vom Theme (Design › Kurswasser einrichten › Shop, „Shop für Kunden sichtbar
 * machen“), von der Übersicht (Livegang-Checkliste) und vom Hinweis, wenn der Shop schon sichtbar ist.
 * Rot blockiert nicht: Wer trotzdem sichtbar machen will, bestätigt das ausdrücklich.
 *
 * Filter bootspruefung_golive_checks: Liste ändern oder ergänzen (z. B. eigene Punkte).
 */
final class GoLiveCheck
{
    /**
     * @return list<array{key:string,label:string,ok:bool,detail:string,url:string,link:string}>
     */
    public static function run(): array
    {
        $checks = [
            self::legal(),
            self::products(),
            self::examRules(),
            self::freeTier(),
            self::access(),
            self::package(),
        ];
        /** Filter: Livegang-Ampel ändern oder ergänzen. */
        $checks = apply_filters('bootspruefung_golive_checks', $checks);
        $out = [];
        foreach (is_array($checks) ? $checks : [] as $c) {
            if (is_array($c) && isset($c['key'], $c['label'])) {
                $out[] = ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'ok' => ! empty($c['ok']), 'detail' => (string) ($c['detail'] ?? ''), 'url' => (string) ($c['url'] ?? ''), 'link' => (string) ($c['link'] ?? '')];
            }
        }

        return $out;
    }

    /** @param list<array{ok:bool}>|null $checks */
    public static function allGreen(?array $checks = null): bool
    {
        foreach ($checks ?? self::run() as $c) {
            if (! $c['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{ok:bool,label:string}>|null $checks @return list<string> */
    public static function redLabels(?array $checks = null): array
    {
        return array_values(array_map(static fn ($c) => $c['label'], array_filter($checks ?? self::run(), static fn ($c) => ! $c['ok'])));
    }

    /** Ampel als HTML-Liste (grün/rot, Erklärung, Link). */
    public static function html(?array $checks = null): string
    {
        $html = '<ul class="bp-golive" style="list-style:none;margin:.5em 0;padding:0">';
        foreach ($checks ?? self::run() as $c) {
            $dot = $c['ok']
                ? '<span aria-hidden="true" style="display:inline-block;width:.8em;height:.8em;border-radius:50%;background:#00a32a;margin-right:.4em"></span><span class="screen-reader-text">'.esc_html__('erfüllt:', 'bootspruefung-learning').' </span>'
                : '<span aria-hidden="true" style="display:inline-block;width:.8em;height:.8em;border-radius:50%;background:#d63638;margin-right:.4em"></span><span class="screen-reader-text">'.esc_html__('offen:', 'bootspruefung-learning').' </span>';
            $link = $c['url'] !== '' ? ' <a href="'.esc_url($c['url']).'">'.esc_html($c['link'] !== '' ? $c['link'] : __('Zur Stelle', 'bootspruefung-learning')).'</a>' : '';
            $html .= '<li data-golive="'.esc_attr($c['key']).'" data-ok="'.($c['ok'] ? '1' : '0').'" style="margin:.35em 0">'.$dot.'<strong>'.esc_html($c['label']).'</strong>'
                .($c['detail'] !== '' ? ' – '.esc_html($c['detail']) : '').$link.'</li>';
        }

        return $html.'</ul>';
    }

    /** Rechtstexte: veröffentlicht, ohne [[Betreiber: …]]-Platzhalter (Theme-Prüfung, sonst Bestellmail-Prüfung). */
    private static function legal(): array
    {
        $open = [];
        if (function_exists('kurswasser_legal_open_issues')) {
            foreach ((array) kurswasser_legal_open_issues() as $item) {
                $open[] = $item['title'].' ('.implode(', ', (array) $item['issues']).')';
            }
        }
        if (class_exists('WooCommerce')) {
            foreach (LegalTextsEmail::withPlaceholders() as $page) {
                $title = html_entity_decode(wp_strip_all_tags(get_the_title($page)), ENT_QUOTES, 'UTF-8');
                if (! array_filter($open, static fn ($o) => str_starts_with($o, $title.' '))) {
                    $open[] = $title.' ('.__('enthält [[Betreiber: …]]-Platzhalter', 'bootspruefung-learning').')';
                }
            }
            if ((int) get_option('woocommerce_terms_page_id') <= 0) {
                $open[] = __('AGB-Seite in WooCommerce nicht festgelegt', 'bootspruefung-learning');
            }
        }

        return [
            'key' => 'legal',
            'label' => __('Rechtstexte veröffentlicht, ohne Platzhalter', 'bootspruefung-learning'),
            'ok' => $open === [],
            'detail' => $open === [] ? __('Keine Platzhalter gefunden. Inhaltlich prüfen lassen bleibt deine Aufgabe.', 'bootspruefung-learning') : implode('; ', $open),
            'url' => self::operatorUrl() ?? admin_url('edit.php?post_type=page'),
            'link' => self::operatorUrl() !== null ? __('Betreiberangaben ausfüllen', 'bootspruefung-learning') : __('Seiten bearbeiten', 'bootspruefung-learning'),
        ];
    }

    /** Lernprodukte im Shop: Katalog angelegt, mindestens ein Produkt veröffentlicht. */
    private static function products(): array
    {
        $base = ['key' => 'products', 'label' => __('Lernprodukte angelegt und veröffentlicht', 'bootspruefung-learning'), 'url' => Ui::url(PricePage::SLUG), 'link' => __('Preise und Katalog', 'bootspruefung-learning')];
        if (! function_exists('wc_get_product_id_by_sku')) {
            return $base + ['ok' => false, 'detail' => __('WooCommerce ist nicht aktiv.', 'bootspruefung-learning')];
        }
        $missing = 0;
        $published = 0;
        $drafts = 0;
        $catalog = ShopCatalog::products();
        foreach ($catalog as $def) {
            $id = (int) wc_get_product_id_by_sku($def['sku']);
            $status = $id ? get_post_status($id) : false;
            if ($status === false) {
                $missing++;
            } elseif ($status === 'publish') {
                $published++;
            } else {
                $drafts++;
            }
        }
        $learning = self::publishedLearningProducts();
        $detail = sprintf(__('%1$d von %2$d Katalogprodukten veröffentlicht, %3$d Entwurf, %4$d fehlen.', 'bootspruefung-learning'), $published, count($catalog), $drafts, $missing);

        return $base + ['ok' => $learning > 0 && $missing === 0, 'detail' => $detail];
    }

    /** Anzahl veröffentlichter Produkte mit Lernzugang (auch eigene Produkte außerhalb des Katalogs). */
    public static function publishedLearningProducts(): int
    {
        global $wpdb;
        $ids = (new ProductAccessRepository($wpdb))->productIds();
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_map('intval', $ids));

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_status = 'publish'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nur Ganzzahlen
    }

    /** Prüfungsregelwerke: jeder Kurs mit gültigem, freigegebenem Regelwerk (Prüfungsmodus statt nur Übung). */
    private static function examRules(): array
    {
        $repo = new ContentRepository();
        $courses = $repo->courses();
        $open = [];
        foreach ($courses as $course) {
            if (! $repo->examRule((int) $course->id)['live']) {
                $open[] = (string) $course->code;
            }
        }

        return [
            'key' => 'exam_rules',
            'label' => __('Prüfungsregelwerke freigegeben', 'bootspruefung-learning'),
            'ok' => $courses !== [] && $open === [],
            'detail' => $courses === [] ? __('Noch keine Kurse – zuerst ein Inhaltspaket importieren.', 'bootspruefung-learning')
                : ($open === [] ? sprintf(__('Alle %d Kurse im Prüfungsmodus.', 'bootspruefung-learning'), count($courses)) : sprintf(__('Nur Übungsmodus: %s', 'bootspruefung-learning'), implode(', ', $open))),
            'url' => Ui::url('bootspruefung-rules'),
            'link' => __('Prüfungsregeln', 'bootspruefung-learning'),
        ];
    }

    /** Schnupper-Paket (kostenloser Teilumfang je Kurs für „Kostenlos testen“) vollständig angelegt. */
    private static function freeTier(): array
    {
        $free = ShopCatalog::applyFreeTier(true);

        return [
            'key' => 'free_tier',
            'label' => __('Schnupper-Paket angelegt', 'bootspruefung-learning'),
            'ok' => $free['courses'] > 0 && $free['added'] === 0,
            'detail' => $free['added'] === 0 ? sprintf(__('Für alle %d Kurse vorhanden.', 'bootspruefung-learning'), $free['courses']) : sprintf(__('%d Regeln fehlen noch.', 'bootspruefung-learning'), $free['added']),
            'url' => Ui::url(AccessPages::FREE_SLUG),
            'link' => __('Kostenlose Bereiche', 'bootspruefung-learning'),
        ];
    }

    /** „Zugang ohne Shop“ auf „Nur kostenlose Regeln und Freischaltungen“ (sonst lernen alle Angemeldeten kostenlos; T15). */
    private static function access(): array
    {
        $ok = Settings::get('default_access') === 'entitled_only';

        return [
            'key' => 'access',
            'label' => __('„Zugang ohne Shop“ eingerichtet', 'bootspruefung-learning'),
            'ok' => $ok,
            'detail' => $ok ? __('Nur kostenlose Regeln und Freischaltungen.', 'bootspruefung-learning') : __('Steht noch auf „Alle angemeldeten Nutzer“: Alle Angemeldeten lernen kostenlos, Käufe sperren nichts.', 'bootspruefung-learning'),
            'url' => Ui::url('bootspruefung-settings'),
            'link' => __('Einstellungen', 'bootspruefung-learning'),
        ];
    }

    /** Inhaltspaket aktiv und kein abgebrochener Import (Mischstand). */
    private static function package(): array
    {
        $active = (string) get_option('bp_learning_active_package', '');
        $aborted = Plugin::instance()->importService()->abortedImport();

        return [
            'key' => 'package',
            'label' => __('Inhaltspaket aktiv', 'bootspruefung-learning'),
            'ok' => $active !== '' && $aborted === null,
            'detail' => $active === '' ? __('Noch kein Inhaltspaket importiert.', 'bootspruefung-learning')
                : ($aborted !== null ? sprintf(__('Import #%d wurde abgebrochen: Die Inhalte stammen teils aus dem neuen, teils aus dem bisherigen Paket (Mischstand). Unter „Inhaltspakete“ den Import fortsetzen oder verwerfen und den aktiven Stand wiederherstellen.', 'bootspruefung-learning'), $aborted) : $active),
            'url' => Ui::url(ImportPage::SLUG),
            'link' => __('Inhaltspakete', 'bootspruefung-learning'),
        ];
    }

    /** Seite „Design › Betreiberangaben“ des Themes, falls aktiv. */
    public static function operatorUrl(): ?string
    {
        return function_exists('kurswasser_operator_page') ? admin_url('themes.php?page=kurswasser-operator') : null;
    }
}
