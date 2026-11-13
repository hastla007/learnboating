<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

/**
 * Mindestanforderungen (Konzept Kap. 8). Ehrlich nach getestetem Stand: Die CI prüft WordPress 7.1.4 mit
 * WooCommerce 11.2.2 (und 11.3 Beta); WooCommerce 11.2 setzt selbst WordPress 7.0 voraus. Ältere
 * Versionen sind nicht getestet (u. a. bedingte Pflichtfelder im Checkout-Block, DigitalConsent).
 */
final class Requirements
{
    public const MIN_PHP = '8.1';
    public const MIN_WP = '7.0';
    public const MIN_WC = '11.2';

    /** @return list<string> Fehlermeldungen; leer, wenn alles erfüllt ist. */
    public static function problems(): array
    {
        $problems = [];
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            $problems[] = sprintf(
                /* translators: 1: benötigte, 2: vorhandene PHP-Version */
                __('Das Lernplugin benötigt PHP %1$s oder neuer (vorhanden: %2$s).', 'bootspruefung-learning'),
                self::MIN_PHP,
                PHP_VERSION
            );
        }
        global $wp_version;
        if (isset($wp_version) && version_compare((string) $wp_version, self::MIN_WP, '<')) {
            $problems[] = sprintf(
                /* translators: 1: benötigte, 2: vorhandene WordPress-Version */
                __('Das Lernplugin benötigt WordPress %1$s oder neuer (vorhanden: %2$s).', 'bootspruefung-learning'),
                self::MIN_WP,
                (string) $wp_version
            );
        }
        if (is_multisite() && is_network_admin()) {
            $problems[] = __('Netzwerkweite Aktivierung wird nicht unterstützt. Bitte das Plugin pro Website aktivieren.', 'bootspruefung-learning');
        }

        return $problems;
    }

    /**
     * Hinweis im Admin, wenn WooCommerce älter als die getestete Mindestversion ist (To-do T34). WordPress selbst
     * verhindert das Aktivieren auf zu altem WordPress (Kopfzeile „Requires at least“); WooCommerce nicht.
     */
    public static function adminNotice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }
        $woo = self::wooCommerceStatus();
        if (! $woo['active'] || $woo['supported']) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: vorhandene, 2: benötigte WooCommerce-Version */
                __('Lernplattform: WooCommerce %1$s ist älter als die getestete Mindestversion %2$s. Kasse, Freischaltung und Zustimmung zur sofortigen Ausführung sind damit nicht getestet. Bitte WooCommerce aktualisieren.', 'bootspruefung-learning'),
                (string) $woo['version'],
                self::MIN_WC
            ))
        );
    }

    /** Erfüllt diese WooCommerce-Version die getestete Mindestversion? */
    public static function wooCommerceSupported(string $version): bool
    {
        return version_compare($version, self::MIN_WC, '>=');
    }

    /** WooCommerce ist optional für den Lernbereich, aber Pflicht für den Verkauf. */
    public static function wooCommerceStatus(): array
    {
        if (! class_exists('WooCommerce') || ! defined('WC_VERSION')) {
            return ['active' => false, 'version' => null, 'supported' => false, 'hpos' => null];
        }
        $hpos = null;
        if (class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }

        return [
            'active' => true,
            'version' => WC_VERSION,
            'supported' => self::wooCommerceSupported((string) WC_VERSION),
            'hpos' => $hpos,
        ];
    }
}
