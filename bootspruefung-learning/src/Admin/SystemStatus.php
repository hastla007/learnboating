<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Requirements;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;

/** Sammelt die Angaben der Systemstatus-Seite (Kap. 9). */
final class SystemStatus
{
    /**
     * Warnung, wenn WooCommerce die Katalogpreise nicht als Bruttopreise behandelt (sonst null).
     * @param array<string,mixed>|null $woo Teil 'woocommerce' aus collect(); null = aktuell ermitteln
     */
    public static function taxWarning(?array $woo = null): ?string
    {
        $woo ??= [
            'tax_enabled' => function_exists('wc_tax_enabled') ? wc_tax_enabled() : null,
            'prices_include_tax' => function_exists('wc_prices_include_tax') ? wc_prices_include_tax() : null,
        ];
        if (($woo['prices_include_tax'] ?? null) !== false) {
            return null;
        }
        if (empty($woo['tax_enabled']) && self::smallBusiness($woo['price_note'] ?? null)) {
            return null; // Kleinunternehmer: keine Steuer gewollt, der Hinweis am Preis sagt es.
        }

        return ! empty($woo['tax_enabled'])
            ? __('WooCommerce behandelt eingegebene Preise als Nettopreise („Preise inklusive Steuer“ = Nein). Die Katalog- und Preisangaben des Plugins sind Bruttopreise – die MwSt. käme an der Kasse noch hinzu. Bitte unter WooCommerce › Einstellungen › Steuer „Ja, ich gebe Preise inklusive Steuer ein“ wählen.', 'bootspruefung-learning')
            : __('Die Steuerberechnung in WooCommerce ist ausgeschaltet. Die Preise des Plugins sind Bruttopreise; an der Kasse wird keine MwSt. ausgewiesen. Bitte Steuern aktivieren und „Preise inklusive Steuer“ wählen (oder bewusst so lassen, z. B. als Kleinunternehmer).', 'bootspruefung-learning');
    }

    /**
     * Kleinunternehmer-Regelung erkennbar am Hinweis zum Preis (Einstellung „Hinweis zum Preis“, z. B. vom
     * Einrichtungs-Assistenten des Themes: „ohne MwSt. – Kleinunternehmer gem. § 19 UStG“).
     */
    public static function smallBusiness(?string $priceNote = null): bool
    {
        $note = $priceNote ?? Settings::priceNote();

        return (bool) preg_match('/Kleinunternehmer|§\s*19\s*UStG|§\s*6\s*Abs\.\s*1\s*Z\s*27\s*UStG/u', $note);
    }

    /** @return array<string,mixed> */
    public static function collect(): array
    {
        $migrator = Plugin::instance()->migrator();
        global $wp_version;

        return [
            'plugin_version' => Plugin::VERSION,
            'schema_version' => $migrator->installedVersion(),
            'schema_current' => ! $migrator->needsMigration(),
            'expected_schema_version' => Migrator::SCHEMA_VERSION,
            'foreign_keys' => $migrator->foreignKeyCount(),
            'tables' => $migrator->tableStatus(),
            'woocommerce' => Requirements::wooCommerceStatus() + [
                'guest_checkout' => get_option('woocommerce_enable_guest_checkout') === 'yes',
                'learning_products' => count((new \Bootspruefung\Learning\Commerce\ProductAccessRepository($GLOBALS['wpdb']))->productIds()),
                // Katalog- und Preis-Seite meinen Bruttopreise (inkl. MwSt.).
                'tax_enabled' => function_exists('wc_tax_enabled') ? wc_tax_enabled() : null,
                'prices_include_tax' => function_exists('wc_prices_include_tax') ? wc_prices_include_tax() : null,
            ],
            'php_version' => PHP_VERSION,
            'wp_version' => (string) $wp_version,
        ];
    }
}
