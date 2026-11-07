<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Video-Upgrade zur Theorie-Stufe (Festlegung 07.11.2026): Der Videokurs eines Kurses lässt sich
 * zum Aufschlag dazukaufen – im Checkout (Theorie-Stufe im Warenkorb) oder später im Lernbereich
 * (Theorie-Stufe bereits gekauft). Ohne passende Theorie-Stufe lässt sich das Upgrade weder
 * hinzufügen noch kaufen; wer den Kurs komplett kauft, braucht es nicht.
 */
final class VideoUpgrade
{
    public function register(): void
    {
        add_filter('render_block_woocommerce/checkout', [$this, 'blockBox'], 31, 1);
        add_action('woocommerce_before_checkout_form', [$this, 'classicBox'], 6);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAdd'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'removeOrphan'], 6, 1);
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

    private static function product(string $sku): ?\WC_Product
    {
        $id = (int) wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;

        return $product && $product->get_status() === 'publish' && $product->is_purchasable() ? $product : null;
    }

    private static function baseCourse(string $base): ?string
    {
        $def = ShopCatalog::find($base);

        return $def ? (string) $def['courses'][0] : null;
    }

    /** Hat der Nutzer die Theorie-Stufe des Kurses gekauft (aktiver Zugang), aber noch keinen Videokurs? */
    public static function ownsTheory(int $userId, string $courseCode): bool
    {
        if ($userId <= 0 || (new AccessService())->full($userId, $courseCode, AccessService::VIDEO)) {
            return false;
        }
        global $wpdb;
        $now = current_time('mysql', true);

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND course_code = %s AND feature_code = %s AND status = 'active'
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s) LIMIT 1",
            $userId,
            $courseCode,
            AccessService::QUESTION_BANK,
            $now,
            $now
        ));
    }

    /** Theorie-Basen im Warenkorb, für die noch kein Videokurs (Komplett/Upgrade) im Warenkorb liegt. @return list<string> */
    private static function cartBasesWithoutVideo(array $skus): array
    {
        $out = [];
        foreach (array_keys(ShopCatalog::VIDEO_TIERS) as $base) {
            if (isset($skus[$base]) && ! isset($skus[ShopCatalog::komplettSku($base)]) && ! isset($skus[ShopCatalog::videoSku($base)])) {
                $out[] = $base;
            }
        }

        return $out;
    }

    public static function box(): string
    {
        $cards = '';
        foreach (self::cartBasesWithoutVideo(self::cartSkus()) as $base) {
            $upgrade = self::product(ShopCatalog::videoSku($base));
            $course = self::baseCourse($base);
            // Auch bei Verlängerung (Theorie erneut im Warenkorb, Video noch aktiv): Upgrade für die neue Laufzeit anbieten.
            if (! $upgrade || $course === null) {
                continue;
            }
            $price = wp_strip_all_tags(wc_price((float) $upgrade->get_price()));
            $url = add_query_arg('add-to-cart', $upgrade->get_id(), wc_get_checkout_url());
            $name = \Bootspruefung\Learning\Commerce\Labels::course($course);
            $cards .= '<p><strong>'.esc_html(sprintf(__('Videokurs %1$s dazu für + %2$s', 'bootspruefung-learning'), $name, $price)).'</strong><br>'
                .esc_html__('Der gesamte Prüfungsstoff Kapitel für Kapitel im Video erklärt – passend zu deinem Theoriekurs, gleiche Laufzeit.', 'bootspruefung-learning').'</p>'
                .'<p><a class="button bp-upgrade-add" rel="nofollow" href="'.esc_url($url).'">'.esc_html__('Videokurs hinzufügen', 'bootspruefung-learning').'</a></p>';
        }
        if ($cards === '') {
            return '';
        }

        return '<aside class="bp-upgrade bp-video-upgrade" aria-labelledby="bp-video-upgrade-h"><p class="bp-upgrade-flag">'.esc_html__('Passend zu deinem Theoriekurs', 'bootspruefung-learning').'</p>'
            .'<h2 id="bp-video-upgrade-h">'.esc_html__('Videokurs dazubuchen', 'bootspruefung-learning').'</h2>'.$cards
            .'<p class="bp-upgrade-note">'.esc_html__('Einmalzahlung, kein Abo. Es gilt die Geld-zurück-Garantie wie für den Kurs; dein gesetzliches Widerrufsrecht bleibt unberührt.', 'bootspruefung-learning').'</p></aside>';
    }

    /**
     * Angebot im Lernbereich: Video-Upgrade für Käufer der Theorie-Stufe.
     * @return array{name:string,url:string,price:string}|null
     */
    public static function offerFor(int $userId, string $courseCode): ?array
    {
        if (! function_exists('wc_get_product_id_by_sku') || ! self::ownsTheory($userId, $courseCode)) {
            return null;
        }
        foreach (ShopCatalog::VIDEO_TIERS as $base => $surcharge) {
            if (self::baseCourse($base) !== $courseCode) {
                continue;
            }
            $upgrade = self::product(ShopCatalog::videoSku($base));
            if ($upgrade) {
                return [
                    'name' => $upgrade->get_name(),
                    'url' => add_query_arg('add-to-cart', $upgrade->get_id(), wc_get_checkout_url()),
                    'price' => html_entity_decode(wp_strip_all_tags((string) $upgrade->get_price_html()), ENT_QUOTES, 'UTF-8'),
                ];
            }
        }

        return null;
    }

    public function blockBox($content)
    {
        if (is_admin() || ! is_user_logged_in()) {
            return $content;
        }
        $box = self::box();

        return $box !== '' ? SimUpgrade::styles().$box.$content : $content;
    }

    public function classicBox(): void
    {
        if (! is_user_logged_in()) {
            return;
        }
        $box = self::box();
        if ($box !== '') {
            echo SimUpgrade::styles().$box; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
        }
    }

    /** Upgrade nur mit passender Theorie-Stufe (im Warenkorb oder gekauft) und nicht doppelt. */
    public function validateAdd($passed, $productId)
    {
        $product = wc_get_product((int) $productId);
        $base = $product ? ShopCatalog::tierBase((string) $product->get_sku()) : null;
        if (! $passed || $base === null || ! str_ends_with((string) $product->get_sku(), ShopCatalog::VIDEO_SUFFIX)) {
            return $passed;
        }
        $skus = self::cartSkus();
        $course = (string) self::baseCourse($base);
        $renewal = isset($skus[$base]); // Theorie im Warenkorb: Upgrade gilt für die neue Laufzeit
        if (isset($skus[ShopCatalog::komplettSku($base)]) || isset($skus[ShopCatalog::videoSku($base)]) || (! $renewal && get_current_user_id() > 0 && (new AccessService())->full(get_current_user_id(), $course, AccessService::VIDEO))) {
            wc_add_notice(__('Der Videokurs ist bereits enthalten.', 'bootspruefung-learning'), 'error');

            return false;
        }
        if (! isset($skus[$base]) && ! self::ownsTheory(get_current_user_id(), $course)) {
            wc_add_notice(__('Das Video-Upgrade gibt es nur zusammen mit dem passenden Theoriekurs. Den Kurs mit Videokurs findest du als „Komplett“ im Shop.', 'bootspruefung-learning'), 'error');

            return false;
        }

        return $passed;
    }

    /**
     * Warenkorb bereinigen (kein Videokurs doppelt):
     * - Komplett-Stufe im Warenkorb → Theorie-Stufe und Upgrade derselben Basis entfernen,
     * - Videokurs schon freigeschaltet → Upgrade entfernen,
     * - Theorie-Stufe entfernt (und nicht gekauft) → Upgrade entfernen.
     */
    public function removeOrphan($cart): void
    {
        static $busy = false;
        if ($busy || ! $cart instanceof \WC_Cart) {
            return;
        }
        $skus = self::cartSkus();
        $userId = get_current_user_id();
        $busy = true;
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            $sku = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
            $base = ShopCatalog::tierBase($sku);
            if ($base === null) {
                continue;
            }
            $isUpgrade = str_ends_with($sku, ShopCatalog::VIDEO_SUFFIX);
            $isTheory = $sku === $base;
            $course = (string) self::baseCourse($base);
            $message = null;
            if (($isUpgrade || $isTheory) && isset($skus[ShopCatalog::komplettSku($base)])) {
                $message = $isTheory
                    ? __('Der Theoriekurs wurde entfernt, weil der Kurs als „Komplett mit Videokurs“ im Warenkorb liegt.', 'bootspruefung-learning')
                    : __('Das Video-Upgrade wurde entfernt, weil der Kurs als „Komplett mit Videokurs“ im Warenkorb liegt.', 'bootspruefung-learning');
            } elseif ($isUpgrade && ! isset($skus[$base]) && $userId > 0 && (new AccessService())->full($userId, $course, AccessService::VIDEO)) {
                $message = __('Das Video-Upgrade wurde entfernt, weil dein Videokurs bereits freigeschaltet ist.', 'bootspruefung-learning');
            } elseif ($isUpgrade && ! isset($skus[$base]) && ! self::ownsTheory($userId, $course)) {
                $message = __('Das Video-Upgrade wurde entfernt, weil der passende Theoriekurs nicht mehr im Warenkorb liegt.', 'bootspruefung-learning');
            }
            if ($message === null) {
                continue;
            }
            $cart->remove_cart_item($key);
            unset($skus[$sku]);
            if (function_exists('wc_add_notice') && ! wp_doing_cron()) {
                wc_add_notice($message, 'notice');
            }
        }
        $busy = false;
    }
}
