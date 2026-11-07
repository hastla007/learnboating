<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\Labels;

/**
 * Mitgliederrabatte: Wer einen aktiven (gekauften, manuellen oder Gruppen-)
 * Lernzugang hat, erhält im Shop einen Prozentrabatt – auf andere Produkte
 * (z. B. Bücher), auf Lernprodukte (z. B. Verlängerung) oder auf alles.
 * Kostenlose Bereiche und „Zugang ohne Shop“ zählen nicht als Mitgliedschaft.
 * Der Rabatt wird im Warenkorb auf den Positionspreis angewendet (klassischer
 * Checkout und Checkout-Block) und an der Bestellposition vermerkt.
 */
final class MemberDiscounts
{
    public const OPTION = 'bp_learning_member_discounts';
    public const APPLIES = ['other', 'learning', 'all'];

    public function register(): void
    {
        add_action('woocommerce_before_calculate_totals', [$this, 'applyToCart'], 20);
        add_filter('woocommerce_get_price_html', [$this, 'priceHtml'], 20, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'lineItemMeta'], 10, 4);
    }

    /** @return list<array{id:string,course:string,percent:float,applies:string,label:string}> */
    public static function rules(): array
    {
        $rules = get_option(self::OPTION, []);

        return is_array($rules) ? array_values($rules) : [];
    }

    public static function add(string $course, float $percent, string $applies, string $label): void
    {
        if ($percent <= 0 || $percent > 100 || ! in_array($applies, self::APPLIES, true)) {
            throw new \InvalidArgumentException(__('Bitte einen Rabatt zwischen 0 und 100 % und einen Geltungsbereich angeben.', 'bootspruefung-learning'));
        }
        $rules = self::rules();
        $rules[] = ['id' => wp_generate_uuid4(), 'course' => $course, 'percent' => round($percent, 2), 'applies' => $applies, 'label' => mb_substr(trim($label), 0, 100)];
        update_option(self::OPTION, $rules, false);
    }

    public static function remove(string $id): void
    {
        update_option(self::OPTION, array_values(array_filter(self::rules(), static fn ($r) => $r['id'] !== $id)), false);
    }

    /**
     * Bester passender Rabatt für Nutzer und Produkt.
     *
     * @return array{percent:float,label:string}|null
     */
    public static function discountFor(int $userId, \WC_Product $product): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $rules = self::rules();
        if ($rules === []) {
            return null;
        }
        $isLearning = Integration::productHasLearning($product);
        $courses = self::memberCourses($userId);
        if ($courses === []) {
            return null;
        }
        $best = null;
        foreach ($rules as $rule) {
            if (($rule['applies'] === 'other' && $isLearning) || ($rule['applies'] === 'learning' && ! $isLearning)) {
                continue;
            }
            if ($rule['course'] !== '' && ! in_array($rule['course'], $courses, true)) {
                continue;
            }
            if ($best === null || $rule['percent'] > $best['percent']) {
                $best = ['percent' => (float) $rule['percent'], 'label' => $rule['label'] !== '' ? $rule['label'] : __('Mitgliederrabatt', 'bootspruefung-learning')];
            }
        }

        /** Filter: Mitgliederrabatt für Nutzer/Produkt anpassen (null = keiner). */
        return apply_filters('bootspruefung_member_discount', $best, $userId, $product);
    }

    /** @return list<string> Kurse mit aktivem Zugang (ohne Free-Regeln). */
    public static function memberCourses(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql', true);

        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT course_code FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND status = 'active'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)",
            $userId, $now, $now
        ));
    }

    public function applyToCart($cart): void
    {
        if (! $cart instanceof \WC_Cart || (is_admin() && ! wp_doing_ajax() && ! (defined('REST_REQUEST') && REST_REQUEST))) {
            return;
        }
        $userId = get_current_user_id();
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if (! $product instanceof \WC_Product) {
                continue;
            }
            // Ausgangspreis bei jeder Berechnung frisch aus dem Produkt lesen (nicht im Warenkorb speichern):
            // kein doppelter Rabatt bei wiederholter Berechnung, und Preisänderungen/Sale-Ende greifen sofort.
            $fresh = wc_get_product($product->get_id());
            $base = (float) ($fresh instanceof \WC_Product ? $fresh->get_price('edit') : $product->get_price('edit'));
            unset($cart->cart_contents[$key]['bp_base_price']);
            $discount = self::discountFor($userId, $product);
            if ($discount === null) {
                $product->set_price($base);
                unset($cart->cart_contents[$key]['bp_member_discount']);
                continue;
            }
            $product->set_price(round($base * (1 - $discount['percent'] / 100), wc_get_price_decimals()));
            $cart->cart_contents[$key]['bp_member_discount'] = $discount;
        }
    }

    public function priceHtml($html, $product)
    {
        if (! $product instanceof \WC_Product || is_admin() || ! is_user_logged_in()) {
            return $html;
        }
        $discount = self::discountFor(get_current_user_id(), $product);
        if ($discount === null || $product->get_price() === '') {
            return $html;
        }
        $price = (float) wc_get_price_to_display($product);

        return $html.' <small class="bp-member-price">'.esc_html(sprintf(__('Für dich: %1$s (−%2$s %% %3$s)', 'bootspruefung-learning'), wp_strip_all_tags(wc_price($price * (1 - $discount['percent'] / 100))), wc_format_localized_decimal($discount['percent']), $discount['label'])).'</small>';
    }

    public function lineItemMeta($item, $cartItemKey, $values, $order): void
    {
        if (! empty($values['bp_member_discount']) && $item instanceof \WC_Order_Item_Product) {
            $d = $values['bp_member_discount'];
            $item->add_meta_data(__('Mitgliederrabatt', 'bootspruefung-learning'), wc_format_localized_decimal($d['percent']).' % ('.$d['label'].')', true);
        }
    }

    public static function appliesLabel(string $applies): string
    {
        return match ($applies) {
            'other' => __('andere Produkte (ohne Lernzugang)', 'bootspruefung-learning'),
            'learning' => __('Lernprodukte (z. B. Verlängerung)', 'bootspruefung-learning'),
            default => __('alle Produkte', 'bootspruefung-learning'),
        };
    }

    public static function courseLabel(string $course): string
    {
        return $course === '' ? __('beliebiger Kurs', 'bootspruefung-learning') : Labels::course($course);
    }
}
