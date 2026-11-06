<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Infrastructure;

/** Mindestanforderungen (Konzept Kap. 8). */
final class Requirements
{
    public const MIN_PHP = '8.1';
    public const MIN_WP = '6.4';
    public const MIN_WC = '8.0';

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
            'supported' => version_compare(WC_VERSION, self::MIN_WC, '>='),
            'hpos' => $hpos,
        ];
    }
}
