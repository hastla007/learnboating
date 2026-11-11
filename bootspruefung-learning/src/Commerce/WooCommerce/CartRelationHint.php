<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\CourseRelations;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Gate\SectionFiles;

/**
 * Hinweis im Warenkorb und an der Kasse (Festlegung 10.11.2026): Hinweis, keine Sperre.
 * Liegt ein Kurs im Warenkorb, den ein besessener Kurs schon abdeckt (CourseRelations: hidden),
 * nennt der Hinweis die passende günstigere Alternative aus den Empfehlungen des besessenen
 * Kurses, z. B. Binnen-Besitzer + Kombi See & Binnen → Ergänzung Binnen → See. Der Knopf tauscht
 * die Position in einem Schritt (Alternative hinzufügen, Position entfernen).
 * Nur für angemeldete Kunden; abschaltbar über den Filter `bootspruefung_cart_relation_hint`.
 */
final class CartRelationHint
{
    public const QUERY_ARG = 'bp-swap';
    public const NONCE_ARG = 'bp-swap-nonce';
    private const NONCE_ACTION = 'bp_cart_swap_';
    /** Katalog-Gruppen mit einem Hauptkurs (Einzelkurse, Kombis, Ergänzungen); Bundle, Pässe, Gruppen und Simulator nicht. */
    private const GROUPS = ['sbf', 'funk', 'signal', 'addon'];

    public function register(): void
    {
        add_action('woocommerce_before_cart', [$this, 'classicCartBox'], 5);
        add_action('woocommerce_before_checkout_form', [$this, 'classicCheckoutBox'], 4);
        add_filter('render_block_woocommerce/cart', [$this, 'blockCartBox'], 32, 1);
        add_filter('render_block_woocommerce/checkout', [$this, 'blockCheckoutBox'], 32, 1);
        add_action('wp_loaded', [$this, 'handleSwap'], 25);
    }

    /**
     * Ermittlung ohne Datenbank und ohne WooCommerce.
     *
     * @param list<string> $owned besessene Kurse
     * @return array{sku:string,course:string,owned:string,covered_by:list<string>,alternative:?string,alternative_skus:list<string>}|null
     */
    public static function evaluate(array $owned, string $sku): ?array
    {
        $base = ShopCatalog::tierBase($sku);
        if ($base !== null && str_ends_with($sku, ShopCatalog::VIDEO_SUFFIX)) {
            return null; // Video-Upgrade: kein eigener Kurs
        }
        $def = ShopCatalog::find($base ?? $sku);
        if ($def === null || ! in_array($def['group'] ?? '', self::GROUPS, true) || ($def['courses'] ?? []) === []) {
            return null;
        }
        $courses = array_values(array_map('strval', $def['courses']));
        $main = $courses[0];
        if (! (CourseRelations::evaluate($owned, [$main])[$main]['hidden'] ?? false)) {
            return null; // nicht abgedeckt oder selbst besessen (Verlängerung)
        }
        $rules = CourseRelations::rules();
        $coveredBy = null;
        $alternative = null;
        foreach ($owned as $code) {
            if (! in_array($main, $rules[$code]['hide'] ?? [], true)) {
                continue;
            }
            $coveredBy ??= $code;
            foreach ($rules[$code]['recommend'] as $candidate) {
                if (in_array($candidate, $owned, true)) {
                    continue;
                }
                if (in_array($candidate, $courses, true) || array_intersect(SectionFiles::PARTS[$candidate] ?? [], $courses) !== []) {
                    $coveredBy = $code;
                    $alternative = $candidate;
                    break 2;
                }
            }
        }
        if ($coveredBy === null) {
            return null;
        }
        // Alle besessenen Kurse, die den Kurs abdecken (Reihenfolge der Tabelle, z. B. SBF Binnen vor der Ergänzung).
        $coveredAll = array_values(array_filter(array_keys($rules), static fn ($code) => in_array($code, $owned, true) && in_array($main, $rules[$code]['hide'], true)));

        return [
            'sku' => $sku,
            'course' => $main,
            'owned' => $coveredBy,
            'covered_by' => $coveredAll !== [] ? $coveredAll : [$coveredBy],
            'alternative' => $alternative,
            'alternative_skus' => $alternative !== null ? self::alternativeSkus($alternative, $sku, $base) : [],
        ];
    }

    /**
     * Produkte der Alternative in Wunschreihenfolge: Theorie-Stufe → Theorie, Komplett → Komplett,
     * ohne Stufen (Kombi, enthält das Video) → Komplett, sonst Basis.
     * @return list<string>
     */
    private static function alternativeSkus(string $course, string $cartSku, ?string $cartBase): array
    {
        $altBase = null;
        foreach (ShopCatalog::products() as $def) {
            if (($def['courses'][0] ?? null) === $course && in_array($def['group'] ?? '', self::GROUPS, true) && ($def['tier'] ?? 'theorie') === 'theorie') {
                $altBase = (string) $def['sku'];
                break;
            }
        }
        if ($altBase === null) {
            return [];
        }
        $tiered = isset(ShopCatalog::VIDEO_TIERS[$altBase]);
        if (! $tiered) {
            return [$altBase];
        }
        if ($cartBase !== null) {
            return [ShopCatalog::komplettSku($altBase)]; // Komplett gewählt: Video nicht verlieren
        }
        if (isset(ShopCatalog::VIDEO_TIERS[$cartSku])) {
            return [$altBase]; // Theorie-Stufe
        }

        return [ShopCatalog::komplettSku($altBase), $altBase];
    }

    /**
     * Hinweise für einen Nutzer: je Warenkorb-Position höchstens einer, gleiche Hinweise nur einmal.
     *
     * @param array<string,string> $items Warenkorb-Schlüssel => SKU
     * @return array<string,array{sku:string,course:string,owned:string,covered_by:list<string>,alternative:?string,alternative_skus:list<string>}>
     */
    public static function forUser(int $userId, array $items): array
    {
        /** Filter: Hinweis auf schon abgedeckte Kurse im Warenkorb abschalten (false). */
        if ($userId <= 0 || $items === [] || ! apply_filters('bootspruefung_cart_relation_hint', true, $userId)) {
            return [];
        }
        $owned = CourseRelations::ownedCourses($userId);
        if ($owned === []) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($items as $key => $sku) {
            $hint = self::evaluate($owned, (string) $sku);
            if ($hint === null || isset($seen[$hint['course'].'|'.$hint['alternative']])) {
                continue;
            }
            $seen[$hint['course'].'|'.$hint['alternative']] = true;
            $out[(string) $key] = $hint;
        }

        return $out;
    }

    /** Text des Hinweises (ohne Knopf). */
    public static function message(array $hint, bool $withAlternative): string
    {
        $owned = Labels::course($hint['owned']);
        if ($withAlternative && $hint['alternative'] !== null) {
            /* translators: 1: besessener Kurs, 2: Alternative mit Artikel, z. B. „die Ergänzung Binnen → See“ */
            return sprintf(__('Du hast %1$s schon – dafür reicht %2$s.', 'bootspruefung-learning'), $owned, self::withArticle(Labels::course($hint['alternative'])));
        }

        // Mehrere besessene Kurse decken den Kurs gemeinsam ab (z. B. SBF Binnen und Ergänzung Binnen → See = Kombi).
        $covered = array_map(static fn ($code) => Labels::course((string) $code), (array) ($hint['covered_by'] ?? [$hint['owned']]));
        $last = array_pop($covered);
        $owned = $covered === [] ? (string) $last : sprintf(__('%1$s und %2$s', 'bootspruefung-learning'), implode(', ', $covered), $last);

        /* translators: 1: besessene(r) Kurs(e), 2: Kurs im Warenkorb */
        return sprintf(__('Du hast %1$s schon – der Inhalt von %2$s ist darin schon enthalten.', 'bootspruefung-learning'), $owned, Labels::course($hint['course']));
    }

    private static function withArticle(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'Ergänzung') => 'die '.$name,
            (bool) preg_match('/kurs$/u', $name) => 'der '.$name,
            default => $name,
        };
    }

    /** @return array<string,string> Warenkorb-Schlüssel => SKU */
    private static function cartItems(): array
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return [];
        }
        $items = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if ($product instanceof \WC_Product) {
                $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;
                $sku = $product->get_sku() ?: ($parent ? $parent->get_sku() : '');
                if ($sku !== '') {
                    $items[(string) $key] = (string) $sku;
                }
            }
        }

        return $items;
    }

    private static function product(string $sku): ?\WC_Product
    {
        $id = (int) wc_get_product_id_by_sku($sku);
        $product = $id ? wc_get_product($id) : null;

        return $product && $product->get_status() === 'publish' && $product->is_purchasable() ? $product : null;
    }

    /** Erstes veröffentlichtes, kaufbares Produkt der Alternative. */
    private static function alternativeProduct(array $hint): ?\WC_Product
    {
        foreach ($hint['alternative_skus'] as $sku) {
            $product = self::product($sku);
            if ($product) {
                return $product;
            }
        }

        return null;
    }

    public static function swapUrl(string $key, string $base): string
    {
        return add_query_arg([self::QUERY_ARG => $key, self::NONCE_ARG => wp_create_nonce(self::NONCE_ACTION.$key)], $base);
    }

    /** Box für den aktuellen Warenkorb; $base = Seite, auf die der Tausch-Knopf zurückführt. */
    public static function box(string $base = ''): string
    {
        $hints = self::forUser(get_current_user_id(), self::cartItems());
        if ($hints === []) {
            return '';
        }
        $base = $base !== '' ? $base : wc_get_cart_url();
        $cards = '';
        foreach ($hints as $key => $hint) {
            $alt = self::alternativeProduct($hint);
            $cards .= '<p>'.esc_html(self::message($hint, $alt !== null)).'</p>';
            if ($alt !== null) {
                $cards .= '<p><a class="button wp-element-button bp-upgrade-add bp-relation-swap" rel="nofollow" href="'.esc_url(self::swapUrl($key, $base)).'">'
                    .esc_html(sprintf(__('Stattdessen %s', 'bootspruefung-learning'), Labels::course((string) $hint['alternative']))).'</a></p>';
            }
        }

        return '<aside class="bp-upgrade bp-relation-hint" aria-labelledby="bp-relation-hint-h"><p class="bp-upgrade-flag">'.esc_html__('Schon in deinem Konto', 'bootspruefung-learning').'</p>'
            .'<h2 id="bp-relation-hint-h">'.esc_html__('Das brauchst du nicht doppelt', 'bootspruefung-learning').'</h2>'.$cards.'</aside>';
    }

    /**
     * Tausch einer Warenkorb-Position gegen die Alternative (neu ermittelt, nicht aus der Anfrage).
     * Gibt den Namen der Alternative zurück oder null, wenn nichts getauscht wurde.
     */
    public static function swap(string $key): ?string
    {
        if (! function_exists('WC') || ! WC()->cart || ! is_user_logged_in()) {
            return null;
        }
        $items = self::cartItems();
        if (! isset($items[$key])) {
            return null;
        }
        $hint = self::forUser(get_current_user_id(), [$key => $items[$key]])[$key] ?? null;
        $alt = $hint ? self::alternativeProduct($hint) : null;
        if ($alt === null) {
            return null;
        }
        $inCart = false;
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) ($item['product_id'] ?? 0) === $alt->get_id() || (int) ($item['variation_id'] ?? 0) === $alt->get_id()) {
                $inCart = true;
            }
        }
        if (! $inCart && ! WC()->cart->add_to_cart($alt->get_id())) {
            return null;
        }
        WC()->cart->remove_cart_item($key);

        return $alt->get_name();
    }

    /** Tausch aus der Anfrage (Knopf mit Nonce); null = keine Tausch-Anfrage. */
    public static function swapFromRequest(): ?bool
    {
        if (! isset($_GET[self::QUERY_ARG])) {
            return null;
        }
        $key = sanitize_key(wp_unslash((string) $_GET[self::QUERY_ARG]));
        $nonce = sanitize_text_field(wp_unslash((string) ($_GET[self::NONCE_ARG] ?? '')));
        if ($key === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION.$key)) {
            return false;
        }
        $removed = self::cartItems()[$key] ?? null;
        $name = self::swap($key);
        if ($name === null) {
            return false;
        }
        if (function_exists('wc_add_notice')) {
            $old = $removed !== null ? self::product($removed) : null;
            wc_add_notice($old
                ? sprintf(__('%1$s liegt jetzt statt %2$s im Warenkorb.', 'bootspruefung-learning'), $name, $old->get_name())
                : sprintf(__('%s liegt jetzt im Warenkorb.', 'bootspruefung-learning'), $name), 'success');
        }

        return true;
    }

    public function handleSwap(): void
    {
        if (is_admin() || ! isset($_GET[self::QUERY_ARG])) {
            return;
        }
        self::swapFromRequest();
        wp_safe_redirect(remove_query_arg([self::QUERY_ARG, self::NONCE_ARG]));
        exit;
    }

    private static function blockBox($content, string $base)
    {
        if (is_admin() || ! is_user_logged_in()) {
            return $content;
        }
        $box = self::box($base);

        return $box !== '' ? SimUpgrade::styles().$box.$content : $content;
    }

    public function blockCartBox($content)
    {
        return self::blockBox($content, wc_get_cart_url());
    }

    public function blockCheckoutBox($content)
    {
        return self::blockBox($content, wc_get_checkout_url());
    }

    private static function classicBox(string $base): void
    {
        if (! is_user_logged_in()) {
            return;
        }
        $box = self::box($base);
        if ($box !== '') {
            echo SimUpgrade::styles().$box; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
        }
    }

    public function classicCartBox(): void
    {
        self::classicBox(wc_get_cart_url());
    }

    public function classicCheckoutBox(): void
    {
        self::classicBox(wc_get_checkout_url());
    }
}
