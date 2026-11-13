<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Zwischenspeicher für Shop-Anfragen (T85): Produktzuordnungen („Lernzugang“), Mitgliedskurse je Nutzer und das
 * Ergebnis „Warenkorb enthält ein Lernprodukt“. Shopseiten, Warenkorb und Kasse fragen das sonst je Produkt bzw.
 * Position und je gelesener Option erneut ab (N+1).
 *
 * Gilt für die laufende Anfrage. Ungültig wird der Speicher
 * - bei jeder schreibenden Abfrage auf bp_product_access bzw. bp_entitlement (Filter „query“, auch direkte
 *   $wpdb-Aufrufe, z. B. Zuordnung im Produkt speichern, Zugangsabgleich, Tests),
 * - bei Nutzerwechsel (set_current_user) und
 * - für den Warenkorb, sobald sich dessen Positionen ändern (Schlüssel aus Produkt-/Variations-IDs).
 */
final class ShopCache
{
    /** @var array<string,int>|null "Produkt|Variation" => Plätze (0 = keine Gruppenlizenz) */
    private static ?array $mappings = null;

    /** @var array<int,list<string>> Nutzer-ID => Kurse mit eigenem Zugang */
    private static array $members = [];

    /** @var array<string,bool> Warenkorb-Schlüssel => enthält Lernprodukt */
    private static array $carts = [];

    private static bool $watching = false;

    /** Ladevorgänge (für Tests und Diagnose). @var array{mappings:int,members:int,carts:int} */
    public static array $loads = ['mappings' => 0, 'members' => 0, 'carts' => 0];

    /** Alles verwerfen. */
    public static function flush(): void
    {
        self::$mappings = null;
        self::$members = [];
        self::$carts = [];
    }

    /** Hat die Position (Produkt, ggf. Variation) einen aktiven Lernzugang? Variation erbt vom Produkt. */
    public static function itemHasLearning(int $productId, int $variationId = 0): bool
    {
        $map = self::mappings();

        return ($variationId > 0 && isset($map[$productId.'|'.$variationId])) || isset($map[$productId.'|0']);
    }

    /** Plätze je Stück bei Gruppenlizenzen, sonst null (wie ProductAccessRepository::forItem() + seats()). */
    public static function itemSeats(int $productId, int $variationId = 0): ?int
    {
        $map = self::mappings();
        $key = $variationId > 0 && isset($map[$productId.'|'.$variationId]) ? $productId.'|'.$variationId : $productId.'|0';
        $seats = (int) ($map[$key] ?? 0);

        return $seats > 0 ? $seats : null;
    }

    /** Hat irgendeine der Variationen eine eigene Zuordnung? @param list<int> $variationIds */
    public static function anyVariationHasLearning(int $productId, array $variationIds): bool
    {
        $map = self::mappings();
        foreach ($variationIds as $id) {
            if (isset($map[$productId.'|'.(int) $id])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,int> */
    private static function mappings(): array
    {
        self::watch();
        if (self::$mappings === null) {
            global $wpdb;
            self::$loads['mappings']++;
            $map = [];
            foreach ((array) $wpdb->get_results("SELECT product_id, variation_id, MAX(COALESCE(seats, 0)) AS seats FROM {$wpdb->prefix}bp_product_access WHERE active = 1 GROUP BY product_id, variation_id") as $row) {
                $map[(int) $row->product_id.'|'.(int) $row->variation_id] = (int) $row->seats;
            }
            self::$mappings = $map;
        }

        return self::$mappings;
    }

    /**
     * Mitgliedskurse eines Nutzers, je Anfrage einmal berechnet.
     *
     * @param callable():list<string> $compute
     * @return list<string>
     */
    public static function memberCourses(int $userId, callable $compute): array
    {
        self::watch();
        if (! array_key_exists($userId, self::$members)) {
            self::$loads['members']++;
            self::$members[$userId] = array_values(array_map('strval', $compute()));
        }

        return self::$members[$userId];
    }

    /**
     * „Warenkorb enthält Lernprodukt“ je Warenkorbstand.
     *
     * @param callable():bool $compute
     */
    public static function cart(\WC_Cart $cart, callable $compute): bool
    {
        self::watch();
        $key = spl_object_id($cart).':'.get_current_user_id();
        foreach ($cart->get_cart() as $line) {
            $key .= '|'.(int) ($line['product_id'] ?? 0).':'.(int) ($line['variation_id'] ?? 0);
        }
        if (! array_key_exists($key, self::$carts)) {
            self::$loads['carts']++;
            self::$carts[$key] = $compute();
        }

        return self::$carts[$key];
    }

    /** Schreibende Abfrage auf eine der Tabellen → betroffenen Teil verwerfen. */
    public static function onQuery($query)
    {
        if (! is_string($query) || (! str_contains($query, 'bp_product_access') && ! str_contains($query, 'bp_entitlement'))) {
            return $query;
        }
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query) !== 1) {
            return $query;
        }
        if (str_contains($query, 'bp_product_access')) {
            self::$mappings = null;
            self::$carts = [];
        }
        if (str_contains($query, 'bp_entitlement')) {
            self::$members = [];
        }

        return $query;
    }

    private static function watch(): void
    {
        if (self::$watching || ! function_exists('add_filter')) {
            return;
        }
        self::$watching = true;
        add_filter('query', [self::class, 'onQuery'], 1);
        add_action('set_current_user', static function (): void {
            self::$members = [];
            self::$carts = [];
        });
    }
}
