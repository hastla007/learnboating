<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Pflichtangaben „spätestens bei Beginn des Bestellvorgangs“ (§ 312j Abs. 1 BGB): akzeptierte Zahlungsarten und
 * Lieferbeschränkungen. Kurzer Hinweis auf Produktseiten (unter dem Kaufen-Knopf) und im Warenkorb (Block und
 * klassisch), im Stil des Preis-Hinweises. Alles kommt aus den WooCommerce-Einstellungen:
 * - Zahlungsarten: Titel der aktiven Zahlungsarten (wie in der Kasse),
 * - Verkaufsländer: nur, wenn der Shop sie einschränkt (Einstellungen › Allgemein › Verkaufsort(e)),
 * - Lieferung: nur bei digitalen Lernprodukten („digital, Zugang nach Zahlungseingang“) – so gilt es für jede
 *   Zahlungsart, auch für die Überweisung (Freischaltung erst nach Zahlungseingang).
 * Zeilen ändern bzw. abschalten: Filter `bootspruefung_purchase_info_lines`.
 */
final class PurchaseInfo
{
    public function register(): void
    {
        add_action('woocommerce_after_add_to_cart_form', [$this, 'productNote']);
        add_filter('render_block_woocommerce/cart', [$this, 'blockCartNote'], 41, 1);
        add_action('woocommerce_after_cart_totals', [$this, 'classicCartNote']);
    }

    /**
     * Titel der aktiven Zahlungsarten, ohne Dubletten und ohne HTML. Vorrang haben die in der Kasse verfügbaren
     * (WooCommerce prüft dabei z. B. Zugangsdaten und Beträge); ohne WooCommerce leer.
     *
     * @return list<string>
     */
    public static function paymentMethods(): array
    {
        if (! function_exists('WC') || WC()->payment_gateways() === null) {
            return [];
        }
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $titles = [];
        foreach ($gateways as $gateway) {
            $title = trim(wp_strip_all_tags(html_entity_decode((string) $gateway->get_title(), ENT_QUOTES, 'UTF-8')));
            if ($title !== '' && ! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Eingeschränkte Verkaufsländer als kurze Liste, sonst '' (Verkauf in alle Länder).
     * Alle EU-Staaten werden zu „EU-Staaten“ zusammengefasst, z. B. „EU-Staaten, Schweiz“.
     */
    public static function salesCountries(): string
    {
        if (! function_exists('WC') || ! WC()->countries) {
            return '';
        }
        $mode = (string) get_option('woocommerce_allowed_countries', 'all');
        $names = WC()->countries->get_countries();
        $name = static fn (string $code): string => html_entity_decode((string) ($names[$code] ?? $code), ENT_QUOTES, 'UTF-8');
        if ($mode === 'all_except') {
            $except = array_values(array_filter(array_map('strval', (array) get_option('woocommerce_all_except_countries', []))));
            if ($except === []) {
                return '';
            }

            /* translators: %s: Länder, in die nicht verkauft wird */
            return sprintf(__('alle außer %s', 'bootspruefung-learning'), implode(', ', array_map($name, $except)));
        }
        if ($mode !== 'specific') {
            return '';
        }
        $allowed = array_map('strval', array_keys(WC()->countries->get_allowed_countries()));
        if ($allowed === []) {
            return '';
        }
        $eu = array_map('strval', WC()->countries->get_european_union_countries());
        $parts = [];
        if ($eu !== [] && array_diff($eu, $allowed) === []) {
            $parts[] = __('EU-Staaten', 'bootspruefung-learning');
            $allowed = array_values(array_diff($allowed, $eu));
        }
        $others = array_map($name, $allowed);
        sort($others, SORT_LOCALE_STRING);

        return implode(', ', array_merge($parts, $others));
    }

    /**
     * Zeilen des Hinweises.
     *
     * @param bool $digital Nur digitale Lernprodukte (Produktseite: dieses Produkt; Warenkorb: alle Positionen).
     * @return list<string>
     */
    public static function lines(bool $digital): array
    {
        $lines = [];
        $methods = self::paymentMethods();
        if ($methods !== []) {
            /* translators: %s: Zahlungsarten, z. B. „PayPal, Überweisung“ */
            $lines[] = sprintf(__('Zahlungsarten: %s', 'bootspruefung-learning'), implode(', ', $methods));
        }
        $countries = self::salesCountries();
        if ($countries !== '') {
            /* translators: %s: Verkaufsländer, z. B. „EU-Staaten, Schweiz“ */
            $lines[] = sprintf(__('Verkaufsländer: %s', 'bootspruefung-learning'), $countries);
        }
        if ($digital) {
            $lines[] = __('Lieferung: digital, kein Versand – Zugang nach Zahlungseingang', 'bootspruefung-learning');
        }

        /** Filter: Zeilen des Hinweises zu Zahlungsarten, Verkaufsländern und Lieferung ([] = kein Hinweis). */
        return array_values(array_filter(array_map('strval', (array) apply_filters('bootspruefung_purchase_info_lines', $lines, $digital)), 'strlen'));
    }

    public static function html(bool $digital, string $tag = 'p'): string
    {
        $lines = self::lines($digital);
        if ($lines === []) {
            return '';
        }

        return '<'.$tag.' class="bp-purchase-info"><small>'.implode(' · ', array_map('esc_html', $lines)).'</small></'.$tag.'>';
    }

    /** Digitales Lernprodukt (virtuell und mit Lernzugang)? */
    public static function productIsDigital(\WC_Product $product): bool
    {
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product($childId);
                if (! $child instanceof \WC_Product || ! $child->is_virtual()) {
                    return false;
                }
            }

            return $product->get_children() !== [] && Integration::productHasLearning($product);
        }

        return $product->is_virtual() && Integration::productHasLearning($product);
    }

    /** Enthält der Warenkorb nur digitale Lernprodukte (und mindestens eines)? */
    public static function cartIsDigital(): bool
    {
        if (! function_exists('WC') || WC()->cart === null || WC()->cart->is_empty() || WC()->cart->needs_shipping()) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $line) {
            $product = $line['data'] ?? null;
            if (! $product instanceof \WC_Product || ! $product->is_virtual() || ! Integration::productHasLearning($product)) {
                return false;
            }
        }

        return true;
    }

    public function productNote(): void
    {
        global $product;
        $digital = $product instanceof \WC_Product && self::productIsDigital($product);
        echo self::html($digital); // phpcs:ignore WordPress.Security.EscapeOutput -- in html() maskiert
    }

    public function blockCartNote($content)
    {
        if (is_admin()) {
            return $content;
        }

        return $content.self::html(self::cartIsDigital());
    }

    public function classicCartNote(): void
    {
        echo self::html(self::cartIsDigital()); // phpcs:ignore WordPress.Security.EscapeOutput -- in html() maskiert
    }
}
