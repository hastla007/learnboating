<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Gutscheinfeld in Warenkorb und Kasse nur, wenn es etwas einzulösen gibt (To-do T58).
 *
 * Ein leeres Feld „Gutscheincode“ lädt dazu ein, die Kasse zu verlassen und nach Rabattcodes zu suchen. Das Feld
 * erscheint deshalb nur, wenn es mindestens einen veröffentlichten, nicht abgelaufenen WooCommerce-Gutschein gibt.
 * Umgesetzt über den Filter `woocommerce_coupons_enabled` (gilt für klassischen Warenkorb, klassische Kasse,
 * Warenkorb- und Kassen-Block, Mini-Warenkorb und die Store API).
 *
 * Bleibt immer sichtbar, wenn
 * - ein Plugin Gutscheine ohne eigenen Beitrag „shop_coupon“ bereitstellt (Filter `woocommerce_get_shop_coupon_data`,
 *   z. B. Wertgutscheine von Voucher Captain mit Kanal „Shop“),
 * - Voucher Captain aktiv ist (Funktion `vcap()`).
 *
 * Nur im Frontend: Im Admin (Gutscheine anlegen, Gutschein zu einer Bestellung hinzufügen) bleibt alles, wie es ist.
 * Kursgutscheine werden ohnehin nicht hier, sondern unter „Mein Lernen“ bzw. in der App eingelöst (Extensions\Vouchers,
 * Add-on learnboating-voucher-connector) – daran ändert dieser Filter nichts.
 *
 * Abschalten: Option `bp_learning_hide_unused_coupon_field` = „no“ oder Filter `bootspruefung_hide_coupon_field`
 * (false = Feld nie ausblenden). Die WooCommerce-Einstellung „Gutscheincodes aktivieren“ bleibt maßgeblich: Ist sie
 * aus, bleibt das Feld aus.
 */
final class CouponField
{
    /** Option „no“ schaltet das Ausblenden ab (Standard: ausblenden, wenn es keinen Gutschein gibt). */
    public const OPTION = 'bp_learning_hide_unused_coupon_field';

    private static ?bool $available = null;

    public function register(): void
    {
        add_filter('woocommerce_coupons_enabled', [self::class, 'filter'], 20);
        // Gutschein angelegt, geändert (auch nur Ablaufdatum), veröffentlicht oder gelöscht: Merker der Anfrage zurücksetzen.
        add_action('clean_post_cache', static function ($postId, $post = null): void {
            if ($post instanceof \WP_Post && $post->post_type === 'shop_coupon') {
                self::$available = null;
            }
        }, 10, 2);
        foreach (['woocommerce_new_coupon', 'woocommerce_update_coupon', 'woocommerce_delete_coupon', 'woocommerce_trash_coupon'] as $hook) {
            add_action($hook, static function (): void {
                self::$available = null;
            });
        }
    }

    /** @param mixed $enabled Wert aus der WooCommerce-Einstellung „Gutscheincodes aktivieren“ */
    public static function filter($enabled): bool
    {
        if (! $enabled) {
            return false;
        }
        if (is_admin()) {
            return true; // Admin und admin-ajax (Gutscheine verwalten, Gutschein zur Bestellung hinzufügen); der Shop nutzt wc-ajax
        }

        return ! self::hide();
    }

    /** Soll das Feld ausgeblendet werden? */
    public static function hide(): bool
    {
        $hide = get_option(self::OPTION, 'yes') !== 'no' && ! self::externalVouchers() && ! self::hasActiveCoupon();

        /**
         * Gutscheinfeld in Warenkorb und Kasse ausblenden?
         *
         * @param bool $hide true = ausblenden (Standard, wenn es keinen veröffentlichten, gültigen Gutschein gibt)
         */
        return (bool) apply_filters('bootspruefung_hide_coupon_field', $hide);
    }

    /** Gibt es mindestens einen veröffentlichten Gutschein ohne bzw. mit künftigem Ablaufdatum? */
    public static function hasActiveCoupon(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'date_expires'
             WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish'
               AND (m.meta_value IS NULL OR m.meta_value = '' OR CAST(m.meta_value AS UNSIGNED) > %d)
             LIMIT 1",
            time()
        ));

        return self::$available = $id !== null;
    }

    /** Stellt ein Plugin Gutscheine ohne eigenen Beitrag „shop_coupon“ bereit (z. B. Voucher Captain)? */
    public static function externalVouchers(): bool
    {
        return function_exists('vcap') || has_filter('woocommerce_get_shop_coupon_data') !== false;
    }
}
