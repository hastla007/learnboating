<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Checkout-Upgrade „Praxis-Sorglos-Upgrade“ (Order Bump, Festlegung 06.11.2026):
 * Im Checkout wird der Funkgerät-Simulator (12 Monate) angeboten, wenn ein Funk-Theoriekurs
 * im Warenkorb liegt. Ohne Funkkurs lässt sich das Upgrade weder hinzufügen noch kaufen.
 */
final class SimUpgrade
{
    public function register(): void
    {
        add_filter('render_block_woocommerce/checkout', [$this, 'blockBox'], 30, 1);
        add_action('woocommerce_before_checkout_form', [$this, 'classicBox'], 5);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAdd'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'removeOrphan'], 5, 1);
    }

    /** @return array<string,int> SKU => Anzahl im Warenkorb */
    private static function cartSkus(): array
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return [];
        }
        $skus = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if ($product instanceof \WC_Product) {
                $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;
                $sku = $product->get_sku() ?: ($parent ? $parent->get_sku() : '');
                if ($sku !== '') {
                    $skus[$sku] = ($skus[$sku] ?? 0) + (int) ($item['quantity'] ?? 1);
                }
            }
        }

        return $skus;
    }

    private static function hasRadioCourse(array $skus): bool
    {
        return array_intersect(array_keys($skus), ShopCatalog::SIM_UPGRADE_FOR) !== [];
    }

    private static function hasSimulator(array $skus): bool
    {
        return array_intersect(array_keys($skus), [...ShopCatalog::SIM_PASSES, ShopCatalog::SIM_UPGRADE]) !== [];
    }

    private static function upgradeProduct(): ?\WC_Product
    {
        $id = (int) wc_get_product_id_by_sku(ShopCatalog::SIM_UPGRADE);
        $product = $id ? wc_get_product($id) : null;

        return $product && $product->get_status() === 'publish' && $product->is_purchasable() ? $product : null;
    }

    /** Angebot zeigen? Funkkurs im Warenkorb, noch kein Simulator, Simulator nicht schon freigeschaltet. */
    private static function offer(): ?\WC_Product
    {
        $skus = self::cartSkus();
        if (! self::hasRadioCourse($skus) || self::hasSimulator($skus)) {
            return null;
        }
        $userId = get_current_user_id();
        if ($userId > 0 && (new AccessService())->full($userId, 'SRC', AccessService::DEVICE) && (new AccessService())->full($userId, 'UBI', AccessService::DEVICE)) {
            return null;
        }

        return self::upgradeProduct();
    }

    public static function box(): string
    {
        $product = self::offer();
        if (! $product) {
            return '';
        }
        $price = wp_strip_all_tags(wc_price((float) $product->get_price()));
        $single = (int) wc_get_product_id_by_sku('SIM-PASS-12M');
        $singleProduct = $single ? wc_get_product($single) : null;
        $was = $singleProduct && (float) $singleProduct->get_price() > (float) $product->get_price()
            ? wp_strip_all_tags(wc_price((float) $singleProduct->get_price()))
            : null;
        $url = add_query_arg('add-to-cart', $product->get_id(), wc_get_checkout_url());

        $html = '<aside class="bp-upgrade" aria-labelledby="bp-upgrade-h">';
        $html .= '<p class="bp-upgrade-flag">'.esc_html__('Nur jetzt beim Kauf', 'bootspruefung-learning').'</p>';
        $html .= '<h2 id="bp-upgrade-h">'.esc_html__('Praxis-Sorglos-Upgrade: Funkgerät-Simulator', 'bootspruefung-learning').'</h2>';
        $html .= '<p>'.esc_html__('Übe die praktische Prüfung am virtuellen UKW-Funkgerät: alle Lektionen, Notalarm (DISTRESS), Dringlichkeits- und Sicherheitsmeldungen und der Prüfungsmodus – 12 Monate, direkt im Browser auf allen Geräten.', 'bootspruefung-learning').'</p>';
        $html .= '<p class="bp-upgrade-price"><strong>'.esc_html(sprintf(__('+ %s', 'bootspruefung-learning'), $price)).'</strong>';
        if ($was) {
            $html .= ' <span>'.esc_html(sprintf(__('statt %s als Einzelzugang', 'bootspruefung-learning'), $was)).'</span>';
        }
        $html .= '</p>';
        $html .= '<p><a class="button bp-upgrade-add" rel="nofollow" href="'.esc_url($url).'">'.esc_html__('Upgrade hinzufügen', 'bootspruefung-learning').'</a></p>';
        $html .= '<p class="bp-upgrade-note">'.esc_html__('Einmalzahlung, kein Abo. Für den Simulator gilt keine Geld-zurück-Garantie; dein gesetzliches Widerrufsrecht bleibt unberührt.', 'bootspruefung-learning').'</p>';

        return $html.'</aside>';
    }

    /** Block-Checkout: Angebot über dem Formular (Gäste sehen stattdessen die Anmeldung). */
    public function blockBox($content)
    {
        if (is_admin() || ! is_user_logged_in()) {
            return $content;
        }
        $box = self::box();

        return $box !== '' ? self::styles().$box.$content : $content;
    }

    public function classicBox(): void
    {
        $box = self::box();
        if ($box !== '') {
            echo self::styles().$box; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
        }
    }

    /** Upgrade nur zusammen mit einem Funkkurs in den Warenkorb. */
    public function validateAdd($passed, $productId)
    {
        $product = wc_get_product((int) $productId);
        if (! $passed || ! $product || $product->get_sku() !== ShopCatalog::SIM_UPGRADE) {
            return $passed;
        }
        if (! self::hasRadioCourse(self::cartSkus())) {
            wc_add_notice(__('Das Praxis-Sorglos-Upgrade gibt es nur zusammen mit einem Funkkurs (SRC, UBI oder Kombi). Den Simulator allein findest du als 14-Tage-, 3-Monats- oder 12-Monats-Zugang im Shop.', 'bootspruefung-learning'), 'error');

            return false;
        }

        return $passed;
    }

    /** Funkkurs entfernt → Upgrade ebenfalls entfernen (gilt auch für den Block-Checkout/Store API). */
    public function removeOrphan($cart): void
    {
        static $busy = false;
        if ($busy || ! $cart instanceof \WC_Cart) {
            return;
        }
        $skus = self::cartSkus();
        if (! isset($skus[ShopCatalog::SIM_UPGRADE]) || self::hasRadioCourse($skus)) {
            return;
        }
        $busy = true;
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if ($product instanceof \WC_Product && $product->get_sku() === ShopCatalog::SIM_UPGRADE) {
                $cart->remove_cart_item($key);
                if (function_exists('wc_add_notice') && ! wp_doing_cron()) {
                    wc_add_notice(__('Das Praxis-Sorglos-Upgrade wurde entfernt, weil kein Funkkurs mehr im Warenkorb liegt.', 'bootspruefung-learning'), 'notice');
                }
            }
        }
        $busy = false;
    }

    private static function styles(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;

        return '<style>.bp-upgrade{position:relative;margin:0 0 1.5rem;padding:1.2rem 1.4rem;border:2px dashed #F5B900;border-radius:16px;background:color-mix(in srgb,#F5B900 9%,transparent)}.bp-upgrade h2{margin:.1rem 0 .4rem;font-size:1.15rem}.bp-upgrade p{margin:0 0 .6rem}.bp-upgrade-flag{display:inline-block;padding:.1rem .7rem;border-radius:999px;background:#F5B900;color:#071B2E;font-size:.72rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.bp-upgrade-price strong{font-size:1.2rem}.bp-upgrade-price span{opacity:.75}.bp-upgrade-note{font-size:.82rem;opacity:.75}.bp-upgrade .bp-upgrade-add{border-radius:999px}</style>';
    }
}
