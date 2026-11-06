<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Deklariert die Kompatibilität mit HPOS und Cart/Checkout Blocks (Kap. 7.7).
 * Das Plugin liest Bestellungen ausschließlich über WC_Order/CRUD-APIs.
 */
final class Compatibility
{
    public static function register(string $pluginFile): void
    {
        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $pluginFile, true);
            }
        });
    }
}
