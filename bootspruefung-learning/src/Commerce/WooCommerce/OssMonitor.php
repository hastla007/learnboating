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
        foreach (['woocommerce_order_status_changed', 'woocommerce_order_refunded'] as $hook) {
            add_action($hook, static function (): void {
                delete_transient(self::CACHE.gmdate('Y'));
            });
        }
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
        $tz = wp_timezone();
        $from = (new \DateTimeImmutable($year.'-01-01 00:00:00', $tz))->getTimestamp();
        $to = (new \DateTimeImmutable(($year + 1).'-01-01 00:00:00', $tz))->getTimestamp() - 1;
        $page = 1;
        do {
            $orders = wc_get_orders(['status' => self::PAID, 'date_paid' => $from.'...'.$to, 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order']);
            foreach ($orders as $order) {
                if (! $order instanceof \WC_Order) {
                    continue;
                }
                $country = strtoupper((string) $order->get_billing_country());
                if ($country === '' || $country === $base || ! in_array($country, $eu, true)) {
                    continue;
                }
                $net = (float) $order->get_total() - (float) $order->get_total_tax()
                    - ((float) $order->get_total_refunded() - (float) $order->get_total_tax_refunded());
                $result['by_country'][$country] = round(($result['by_country'][$country] ?? 0.0) + $net, 2);
                $result['total'] = round($result['total'] + $net, 2);
                $result['orders']++;
            }
            $page++;
        } while (count($orders) === 200);
        arsort($result['by_country']);
        set_transient(self::CACHE.$year, $result, HOUR_IN_SECONDS);

        return $result;
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
        $year = (int) wp_date('Y');
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
        printf('<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>', esc_html__('OSS-Schwelle:', 'bootspruefung-learning'), esc_html($text));
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
                ? __('ja – Pflicht zur Schweizer MWST-Registrierung mit der Steuerberatung klären (docs/steuer-fragen.md)', 'bootspruefung-learning')
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
