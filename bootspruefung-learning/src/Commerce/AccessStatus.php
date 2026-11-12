<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Kaufstatus je Kurs für Web-REST, Web-App, App-API und Warenkorb: „Zahlung offen“ (mit Link zur
 * Bestellung in „Mein Konto“, wo die Zahlungsdaten stehen), „Abgelaufen – verlängern“ (Link zur
 * gekauften Stufe) und „Gesperrt“ (Kontakt). Grundlage ist AccessService::purchaseStates(); hier
 * kommen die Shop-Daten dazu (Bestellnummer, Produktseite). Rechte entscheidet weiter AccessService.
 */
final class AccessStatus
{
    /** Status, die den kostenlosen Teilumfang (limited) in der Anzeige überdecken. */
    public const NOTICE_STATUSES = ['pending', 'suspended', 'expired'];

    /**
     * Kaufstatus je Kurs (eine Abfrage je Aufruf; Aufrufer halten das Ergebnis selbst). Offene Zugänge zählen nur, solange
     * die Bestellung noch auf Zahlung wartet (WooCommerce: ausstehend oder in Wartestellung).
     *
     * @return array<string,array<string,mixed>> Kurscode => AccessService::stateFromRows()
     */
    public static function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return (new AccessService())->purchaseStates($userId, null, [self::class, 'orderAwaitsPayment']);
    }

    /** Bestellung wartet noch auf Zahlung (ohne WooCommerce: Zugang bleibt als offen stehen). */
    public static function orderAwaitsPayment(int $orderId): bool
    {
        if (! Integration::active() || ! function_exists('wc_get_order')) {
            return true;
        }
        $order = wc_get_order($orderId);

        return $order instanceof \WC_Order && in_array($order->get_status(), ['pending', 'on-hold'], true);
    }

    /**
     * Anzeige-Status eines Kurses: active (voller Fragenkatalog), sonst pending/suspended/expired aus
     * den Käufen vor limited (kostenloser Teilumfang, bleibt nutzbar) vor not_granted.
     *
     * @param array<string,mixed>|null $state AccessService::stateFromRows()
     */
    public static function status(bool $fullBank, bool $limited, ?array $state): string
    {
        if ($fullBank) {
            return 'active';
        }
        if ($state !== null && in_array($state['status'], self::NOTICE_STATUSES, true)) {
            return (string) $state['status'];
        }

        return $limited ? 'limited' : 'not_granted';
    }

    /**
     * Angaben zum Status (Web-REST und App): Bestellung, Laufzeit ab Zahlungseingang, Ablaufdatum,
     * Verlängern-Link, Kontakt.
     *
     * @param array<string,mixed>|null $state
     * @return array{order:?array{id:int,number:string,url:?string},duration_days:?int,duration:?string,expired_at:?string,renew_url:?string,contact_url:?string}
     */
    public static function details(string $status, string $courseCode, ?array $state): array
    {
        $out = ['order' => null, 'duration_days' => null, 'duration' => null, 'expired_at' => null, 'renew_url' => null, 'contact_url' => null];
        if ($state === null) {
            return $out;
        }
        if ($status === 'pending') {
            $out['order'] = self::order((int) ($state['order_id'] ?? 0));
            $days = $state['duration_days'] ?? null;
            $out['duration_days'] = $days;
            $out['duration'] = $days !== null ? Labels::duration((int) $days) : null;
        } elseif ($status === 'expired') {
            $out['expired_at'] = $state['valid_until'] ?? null;
            $out['renew_url'] = self::renewUrl($courseCode, $state);
        } elseif ($status === 'suspended') {
            $out['contact_url'] = self::contactUrl();
        }

        return $out;
    }

    /** @return array{id:int,number:string,url:?string}|null */
    public static function order(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $order = Integration::active() && function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order instanceof \WC_Order) {
            return ['id' => $orderId, 'number' => (string) $orderId, 'url' => Integration::accountUrl()];
        }

        return ['id' => $orderId, 'number' => (string) $order->get_order_number(), 'url' => (string) $order->get_view_order_url()];
    }

    /**
     * Verlängern: die gekaufte Stufe (Produkt des Zugangs; mit Videokurs die Komplett-Stufe), sonst das
     * erste passende Shop-Angebot, ohne Shop die Kursseite im Lernbereich.
     *
     * @param array<string,mixed>|null $state
     */
    public static function renewUrl(string $courseCode, ?array $state): string
    {
        $url = $state !== null ? self::purchasedProductUrl($state, $courseCode) : null;
        if ($url !== null) {
            return $url;
        }
        $offers = Integration::offers($courseCode, [AccessService::QUESTION_BANK => false]);

        return (string) ($offers[AccessService::QUESTION_BANK][0]['url'] ?? \Bootspruefung\Learning\Frontend\Router::url(strtolower($courseCode)));
    }

    /**
     * Produktseite der gekauften Stufe, wenn sie im Shop kaufbar ist. Theorie-Stufe + Videokurs (Komplett
     * oder Video-Upgrade) → Komplett-Stufe; Kombis, Bundle und Pässe → dasselbe Produkt. Gruppenpakete nicht
     * (Verlängerung durch die Gruppe), ebenso manuelle Zugänge ohne Produkt.
     *
     * @param array<string,mixed> $state
     */
    public static function purchasedProductUrl(array $state, string $courseCode = ''): ?string
    {
        if (! Integration::active() || ! function_exists('wc_get_product')) {
            return null;
        }
        $sku = (string) ($state['sku'] ?? '');
        $def = $sku !== '' ? ShopCatalog::find(ShopCatalog::tierBase($sku) ?? $sku) : null;
        if (($def['group'] ?? null) === 'groups') {
            return null;
        }
        $candidates = [];
        if ($sku !== '') {
            $base = ShopCatalog::tierBase($sku) ?? $sku;
            if (isset(ShopCatalog::VIDEO_TIERS[$base])) {
                $candidates[] = ! empty($state['video']) ? ShopCatalog::komplettSku($base) : $base;
            } else {
                $candidates[] = $sku;
            }
        }
        $ids = array_filter(array_map(static fn ($s) => (int) wc_get_product_id_by_sku($s), $candidates));
        if ($candidates === [] && ! empty($state['product_id'])) {
            $ids[] = (int) $state['product_id'];
        }
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
            if ($product && $parent && $parent->get_status() === 'publish' && $product->is_purchasable() && ! in_array($parent->get_catalog_visibility(), ['hidden', 'search'], true)) {
                return (string) apply_filters('bootspruefung_shop_offer_url', (string) $parent->get_permalink(), $parent, $courseCode !== '' ? $courseCode : (string) ($def['courses'][0] ?? ''), AccessService::QUESTION_BANK);
            }
        }

        return null;
    }

    /** Kontakt bei gesperrtem Zugang: Seite „kontakt“ (wie in der App), sonst „Mein Konto“. */
    public static function contactUrl(): ?string
    {
        $links = \Bootspruefung\Learning\Mobile\Controllers\AccountController::legalLinks();

        return $links['contact'] ?? Integration::accountUrl();
    }
}
