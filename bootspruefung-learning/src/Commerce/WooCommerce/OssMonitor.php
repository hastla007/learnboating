<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * OSS-Schwelle überwachen (To-do T53): Summe der Netto-Umsätze an Kunden in anderen EU-Ländern je Kalenderjahr, aus
 * bezahlten WooCommerce-Bestellungen (abzüglich Erstattungen), verglichen mit der EU-Lieferschwelle von 10.000 €
 * (Art. 59c MwStSystRL, § 3a Abs. 5 UStG). Gezählt wird nach dem Land der Rechnungsadresse.
 *
 * Bewusst vorsichtig: Bestellungen von Firmen mit USt-IdNr. (B2B, Reverse Charge) gehören nicht in die Schwelle, sind
 * aber ohne Rechnungs-Plugin nicht sicher zu erkennen und werden mitgezählt; der Wert ist eine Obergrenze. Die
 * steuerliche Bewertung bleibt bei der Steuerberatung (docs/steuer-fragen.md). Nur EUR-Shops werden gerechnet.
 */
final class OssMonitor
{
    public const THRESHOLD = 10000.0;
    public const WARN_SHARE = 0.8;
    private const CACHE = 'bp_oss_totals_';
    private const PAID = ['processing', 'completed'];

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'adminNotice']);
        // Neue oder geänderte Bestellungen: Summen neu berechnen.
        foreach (['woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_refund_deleted'] as $hook) {
            add_action($hook, static function (): void {
                self::flush();
            }, 10, 0);
        }
    }

    /**
     * Jahr des Zwischenspeichers: Kalenderjahr in der Zeitzone der Website (wie status() und totals()).
     * Früher wurde beim Löschen gmdate('Y') benutzt – in der Silvesternacht (UTC noch im alten Jahr) traf das den
     * falschen Schlüssel und das neue Jahr blieb bis zu einer Stunde veraltet.
     */
    public static function cacheYear(?int $timestamp = null): int
    {
        return (int) wp_date('Y', $timestamp ?? time());
    }

    /**
     * Zwischengespeicherte Summen verwerfen: laufendes Jahr und Vorjahr (Erstattung oder Statuswechsel einer
     * Vorjahresbestellung ändert die Vorjahressumme, die status() ebenfalls zeigt).
     */
    public static function flush(?int $timestamp = null): void
    {
        $year = self::cacheYear($timestamp);
        delete_transient(self::CACHE.$year);
        delete_transient(self::CACHE.($year - 1));
    }

    public static function threshold(): float
    {
        return (float) apply_filters('bootspruefung_oss_threshold', self::THRESHOLD);
    }

    /** Land des Shops (zweistellig) oder '' ohne WooCommerce. */
    public static function baseCountry(): string
    {
        return function_exists('WC') && WC()->countries ? (string) WC()->countries->get_base_country() : '';
    }

    /** @return list<string> EU-Länder (USt.-Gebiet) */
    public static function euCountries(): array
    {
        return function_exists('WC') && WC()->countries ? array_values((array) WC()->countries->get_european_union_countries('eu_vat')) : [];
    }

    /**
     * Netto-Umsatz an Kunden in anderen EU-Ländern im Kalenderjahr (Zeitzone der Website), nach Land.
     *
     * @return array{year:int,total:float,by_country:array<string,float>,orders:int,currency_ok:bool}
     */
    public static function totals(int $year, bool $fresh = false): array
    {
        $cached = $fresh ? false : get_transient(self::CACHE.$year);
        if (is_array($cached)) {
            return $cached;
        }
        $result = ['year' => $year, 'total' => 0.0, 'by_country' => [], 'orders' => 0, 'currency_ok' => get_option('woocommerce_currency') === 'EUR'];
        $base = self::baseCountry();
        $eu = self::euCountries();
        if (! function_exists('wc_get_orders') || $base === '' || $eu === []) {
            return $result;
        }
        $foreign = array_values(array_diff(array_map('strtoupper', $eu), [strtoupper($base)]));
        $tz = wp_timezone();
        $from = (new \DateTimeImmutable($year.'-01-01 00:00:00', $tz))->getTimestamp();
        $to = (new \DateTimeImmutable(($year + 1).'-01-01 00:00:00', $tz))->getTimestamp() - 1;
        // Summen direkt in der Datenbank (T85) statt alle Bestellungen des Jahres als Objekte zu laden.
        foreach (self::sums($from, $to, $foreign, self::hposActive()) as $row) {
            $net = (float) $row['total'] - (float) $row['tax'] - ((float) $row['refunded'] - (float) $row['tax_refunded']);
            $country = (string) $row['country'];
            $result['by_country'][$country] = round(($result['by_country'][$country] ?? 0.0) + $net, 2);
            $result['total'] = round($result['total'] + $net, 2);
            $result['orders'] += (int) $row['orders'];
        }
        arsort($result['by_country']);
        set_transient(self::CACHE.$year, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /** Bestellungen in den eigenen WooCommerce-Tabellen (HPOS) statt in wp_posts? */
    public static function hposActive(): bool
    {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Summen bezahlter Bestellungen (processing/completed, Zahlungsdatum im Zeitraum) je Land der Rechnungsadresse,
     * mit Erstattungen – wie WC_Order::get_total(), get_total_tax(), get_total_refunded() und
     * get_total_tax_refunded(), aber in einer Abfrage. $hpos wählt den Bestellspeicher (wc_orders bzw. wp_posts).
     *
     * @param list<string> $countries
     * @return list<array{country:string,orders:int,total:float,tax:float,refunded:float,tax_refunded:float}>
     */
    public static function sums(int $from, int $to, array $countries, bool $hpos): array
    {
        global $wpdb;
        $countries = array_values(array_filter(array_map(static fn ($c): string => strtoupper((string) $c), $countries), static fn (string $c): bool => preg_match('/^[A-Z]{2}$/', $c) === 1));
        if ($countries === [] || $to < $from) {
            return [];
        }
        $in = implode(',', array_fill(0, count($countries), '%s'));
        $paid = implode(',', array_fill(0, count(self::PAID), '%s'));
        $statuses = array_map(static fn (string $s): string => 'wc-'.$s, self::PAID);
        $items = $wpdb->prefix.'woocommerce_order_items';
        $itemmeta = $wpdb->prefix.'woocommerce_order_itemmeta';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- Tabellennamen fest, Platzhalter dynamisch
        if ($hpos) {
            $orders = $wpdb->prefix.'wc_orders';
            $addresses = $wpdb->prefix.'wc_order_addresses';
            $operational = $wpdb->prefix.'wc_order_operational_data';
            $sql = "SELECT UPPER(a.country) AS country, COUNT(*) AS orders, SUM(o.total_amount) AS total, SUM(COALESCE(o.tax_amount, 0)) AS tax,
                    SUM(COALESCE(r.refunded, 0)) AS refunded, SUM(COALESCE(rt.tax_refunded, 0)) AS tax_refunded
                FROM {$orders} o
                INNER JOIN {$addresses} a ON a.order_id = o.id AND a.address_type = 'billing'
                INNER JOIN {$operational} od ON od.order_id = o.id
                LEFT JOIN (SELECT parent_order_id AS order_id, -SUM(total_amount) AS refunded FROM {$orders} WHERE type = 'shop_order_refund' GROUP BY parent_order_id) r ON r.order_id = o.id
                LEFT JOIN (SELECT ro.parent_order_id AS order_id, ABS(SUM(im.meta_value)) AS tax_refunded FROM {$orders} ro
                    INNER JOIN {$items} oi ON oi.order_id = ro.id AND oi.order_item_type = 'tax'
                    INNER JOIN {$itemmeta} im ON im.order_item_id = oi.order_item_id AND im.meta_key IN ('tax_amount', 'shipping_tax_amount')
                    WHERE ro.type = 'shop_order_refund' GROUP BY ro.parent_order_id) rt ON rt.order_id = o.id
                WHERE o.type = 'shop_order' AND o.status IN ({$paid}) AND od.date_paid_gmt BETWEEN %s AND %s AND UPPER(a.country) IN ({$in})
                GROUP BY UPPER(a.country)";
            $args = array_merge($statuses, [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)], $countries);
        } else {
            $num = static fn (string $col): string => "CAST(COALESCE(NULLIF({$col}, ''), '0') AS DECIMAL(26,8))";
            $sql = "SELECT UPPER(bc.meta_value) AS country, COUNT(*) AS orders, SUM({$num('ot.meta_value')}) AS total,
                    SUM({$num('tx.meta_value')} + {$num('st.meta_value')}) AS tax,
                    SUM(COALESCE(r.refunded, 0)) AS refunded, SUM(COALESCE(rt.tax_refunded, 0)) AS tax_refunded
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} dp ON dp.post_id = p.ID AND dp.meta_key = '_date_paid'
                INNER JOIN {$wpdb->postmeta} bc ON bc.post_id = p.ID AND bc.meta_key = '_billing_country'
                LEFT JOIN {$wpdb->postmeta} ot ON ot.post_id = p.ID AND ot.meta_key = '_order_total'
                LEFT JOIN {$wpdb->postmeta} tx ON tx.post_id = p.ID AND tx.meta_key = '_order_tax'
                LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_order_shipping_tax'
                LEFT JOIN (SELECT rp.post_parent AS order_id, SUM({$num('rm.meta_value')}) AS refunded FROM {$wpdb->posts} rp
                    INNER JOIN {$wpdb->postmeta} rm ON rm.post_id = rp.ID AND rm.meta_key = '_refund_amount'
                    WHERE rp.post_type = 'shop_order_refund' GROUP BY rp.post_parent) r ON r.order_id = p.ID
                LEFT JOIN (SELECT rp.post_parent AS order_id, ABS(SUM(im.meta_value)) AS tax_refunded FROM {$wpdb->posts} rp
                    INNER JOIN {$items} oi ON oi.order_id = rp.ID AND oi.order_item_type = 'tax'
                    INNER JOIN {$itemmeta} im ON im.order_item_id = oi.order_item_id AND im.meta_key IN ('tax_amount', 'shipping_tax_amount')
                    WHERE rp.post_type = 'shop_order_refund' GROUP BY rp.post_parent) rt ON rt.order_id = p.ID
                WHERE p.post_type = 'shop_order' AND p.post_status IN ({$paid}) AND CAST(dp.meta_value AS UNSIGNED) BETWEEN %d AND %d AND UPPER(bc.meta_value) IN ({$in})
                GROUP BY UPPER(bc.meta_value)";
            $args = array_merge($statuses, [$from, $to], $countries);
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        // phpcs:enable

        return array_map(static fn (array $r): array => [
            'country' => (string) $r['country'], 'orders' => (int) $r['orders'], 'total' => (float) $r['total'], 'tax' => (float) $r['tax'],
            'refunded' => (float) $r['refunded'], 'tax_refunded' => (float) $r['tax_refunded'],
        ], $rows);
    }

    /** Sind Steuersätze für mehrere EU-Länder angelegt (Einrichtung „OSS“)? */
    public static function ossConfigured(): bool
    {
        global $wpdb;
        $eu = self::euCountries();
        if ($eu === []) {
            return false;
        }
        $countries = $wpdb->get_col("SELECT DISTINCT tax_rate_country FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country <> ''");

        return count(array_intersect(array_map('strtoupper', (array) $countries), $eu)) > 1;
    }

    /** Wird in die Schweiz verkauft (Verkaufsländer)? */
    public static function sellsToSwitzerland(): bool
    {
        if (! function_exists('WC') || ! WC()->countries) {
            return false;
        }

        return array_key_exists('CH', (array) WC()->countries->get_allowed_countries());
    }

    /**
     * Stand für Anzeige und Hinweis: Schwelle im laufenden oder im Vorjahr erreicht (ab dann gilt der Satz des
     * Kundenlands), Warnung ab 80 %.
     *
     * @return array{state:string,current:array,previous:array,oss:bool,threshold:float}
     */
    public static function status(): array
    {
        $year = self::cacheYear();
        $current = self::totals($year);
        $previous = self::totals($year - 1);
        $threshold = self::threshold();
        $max = max($current['total'], $previous['total']);
        $state = $max > $threshold ? 'exceeded' : ($current['total'] >= $threshold * self::WARN_SHARE ? 'warning' : 'ok');

        return ['state' => $state, 'current' => $current, 'previous' => $previous, 'oss' => self::ossConfigured(), 'threshold' => $threshold];
    }

    /** Hinweis im Admin nur, wenn Handlungsbedarf besteht (Schwelle nah/erreicht und kein OSS eingerichtet). */
    public static function adminNotice(): void
    {
        if (! current_user_can('manage_woocommerce') || self::baseCountry() === '' || ! in_array(self::baseCountry(), self::euCountries(), true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && ! in_array($screen->id, ['dashboard', 'woocommerce_page_wc-settings', 'toplevel_page_bootspruefung'], true)) {
            return;
        }
        $s = self::status();
        if ($s['state'] === 'ok' || $s['oss']) {
            return;
        }
        $amount = static fn (float $v): string => number_format_i18n($v, 2).' €';
        $text = $s['state'] === 'exceeded'
            ? sprintf(__('Die Umsätze an Privatkunden in anderen EU-Ländern liegen über %1$s (%2$d: %3$s, %4$d: %5$s). Ab dann gilt in der Regel die Mehrwertsteuer des Kundenlands (OSS-Verfahren) – bitte mit der Steuerberatung klären und unter Design › Kurswasser einrichten auf „OSS“ umstellen.', 'bootspruefung-learning'), $amount($s['threshold']), $s['previous']['year'], $amount($s['previous']['total']), $s['current']['year'], $amount($s['current']['total']))
            : sprintf(__('Die Umsätze an Privatkunden in anderen EU-Ländern erreichen %1$d %% der Schwelle von %2$s (%3$d: %4$s). Bitte die OSS-Registrierung mit der Steuerberatung vorbereiten.', 'bootspruefung-learning'), (int) round($s['current']['total'] * 100 / max(1.0, $s['threshold'])), $amount($s['threshold']), $s['current']['year'], $amount($s['current']['total']));
        \Bootspruefung\Learning\Admin\Notices::add(['key' => 'oss_threshold', 'type' => 'warning', 'version' => $s['state'].$s['current']['year'], 'title' => __('OSS-Schwelle:', 'bootspruefung-learning'), 'text' => $text]);
    }

    /** Abschnitt im Systemstatus. */
    public static function renderStatus(): void
    {
        if (! function_exists('WC')) {
            return;
        }
        $s = self::status();
        $amount = static fn (float $v): string => number_format_i18n($v, 2).' €';
        $countries = static function (array $t): string {
            $parts = [];
            foreach (array_slice($t['by_country'], 0, 5, true) as $c => $v) {
                $parts[] = $c.' '.number_format_i18n($v, 2);
            }

            return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
        };
        $rows = [
            __('Shop-Land', 'bootspruefung-learning') => self::baseCountry() !== '' ? self::baseCountry() : '–',
            sprintf(__('EU-Ausland %d (netto)', 'bootspruefung-learning'), $s['current']['year']) => $amount($s['current']['total']).' · '.sprintf(_n('%d Bestellung', '%d Bestellungen', $s['current']['orders'], 'bootspruefung-learning'), $s['current']['orders']).$countries($s['current']),
            sprintf(__('EU-Ausland %d (netto)', 'bootspruefung-learning'), $s['previous']['year']) => $amount($s['previous']['total']).$countries($s['previous']),
            __('Schwelle', 'bootspruefung-learning') => $amount($s['threshold']).' · '.match ($s['state']) {
                'exceeded' => __('überschritten', 'bootspruefung-learning'),
                'warning' => __('mehr als 80 % erreicht', 'bootspruefung-learning'),
                default => __('unterschritten', 'bootspruefung-learning'),
            },
            __('Steuersätze', 'bootspruefung-learning') => $s['oss'] ? __('OSS: Sätze für mehrere EU-Länder angelegt', 'bootspruefung-learning') : __('ein Satz für alle (kein OSS)', 'bootspruefung-learning'),
            __('Verkauf in die Schweiz', 'bootspruefung-learning') => self::sellsToSwitzerland()
                ? __('ja – Pflicht zur Schweizer MWST-Registrierung mit der Steuerberatung klären', 'bootspruefung-learning')
                : __('nein', 'bootspruefung-learning'),
        ];
        if (! $s['current']['currency_ok']) {
            $rows[__('Hinweis', 'bootspruefung-learning')] = __('Shop-Währung ist nicht EUR – Beträge sind nicht umgerechnet.', 'bootspruefung-learning');
        }
        echo '<h2>'.esc_html__('Steuer: OSS-Schwelle', 'bootspruefung-learning').'</h2>';
        echo '<p class="description">'.esc_html__('Netto-Umsätze bezahlter Bestellungen mit Rechnungsadresse in einem anderen EU-Land, abzüglich Erstattungen. Firmenkunden mit USt-IdNr. sind mitgezählt (Obergrenze). Keine Steuerberatung.', 'bootspruefung-learning').'</p>';
        echo '<table class="widefat striped" style="max-width:720px"><tbody>';
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        echo '</tbody></table>';
    }
}
