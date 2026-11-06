<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Übersicht der aktiven Zahlungsarten und wie der Adapter sie behandelt
 * (Kap. 7.5, 13 Nr. 9). Der Adapter ist gateway-neutral; die Profile
 * beschreiben nur, welche Ereignisse das jeweilige Gateway in WooCommerce
 * abbildet. Weitere Gateways über den Filter bootspruefung_gateway_profiles.
 */
final class Gateways
{
    /** @return array<string,array{label:string,payment:string,dispute:string}> Präfix => Profil */
    public static function profiles(): array
    {
        $d = 'bootspruefung-learning';
        $profiles = [
            'bacs' => [
                'label' => __('Überweisung', $d),
                'payment' => __('Bestellung bleibt „In Wartestellung“ (Zugang: wartet auf Zahlung), bis der Zahlungseingang durch Statuswechsel auf „In Bearbeitung“/„Abgeschlossen“ bestätigt wird; optional manuelle Freigabe vorab.', $d),
                'dispute' => __('Keine Rückbuchung über das Gateway; Erstattung/Storno in WooCommerce widerruft.', $d),
            ],
            'stripe' => [
                'label' => 'Stripe',
                'payment' => __('Freischaltung bei bestätigter Zahlung (is_paid).', $d),
                'dispute' => __('Dispute setzt die Bestellung auf „In Wartestellung“ → Zugang wird automatisch ausgesetzt; gewonnener Dispute (zurück auf bezahlt) aktiviert wieder.', $d),
            ],
            'ppcp' => [
                'label' => 'PayPal',
                'payment' => __('Freischaltung bei bestätigter Zahlung (is_paid).', $d),
                'dispute' => __('Erstattungen über PayPal werden als WooCommerce-Erstattung gebucht und widerrufen. Konflikte (Disputes) bitte manuell über die Bestellaktion „Lernzugang aussetzen“ behandeln oder die Bestellung auf „In Wartestellung“ setzen.', $d),
            ],
            'paypal' => [
                'label' => 'PayPal',
                'payment' => __('Freischaltung bei bestätigter Zahlung (is_paid).', $d),
                'dispute' => __('Konflikte manuell über „Lernzugang aussetzen“ behandeln oder die Bestellung auf „In Wartestellung“ setzen.', $d),
            ],
            'klarna' => [
                'label' => 'Klarna',
                'payment' => __('Freischaltung, sobald Klarna die Bestellung bestätigt und WooCommerce sie als bezahlt führt; Bestellungen in Prüfung bleiben „wartet auf Zahlung“.', $d),
                'dispute' => __('Stornierung/Erstattung in WooCommerce widerruft; Konflikte manuell über „Lernzugang aussetzen“.', $d),
            ],
            'kco' => [
                'label' => 'Klarna Checkout',
                'payment' => __('Wie Klarna.', $d),
                'dispute' => __('Wie Klarna.', $d),
            ],
        ];

        /** Filter: Profile für weitere Zahlungsarten (Schlüssel = Gateway-ID-Präfix). */
        return (array) apply_filters('bootspruefung_gateway_profiles', $profiles);
    }

    /** @return list<array{id:string,title:string,profile:?array}> */
    public static function enabled(): array
    {
        if (! function_exists('WC') || WC()->payment_gateways() === null) {
            return [];
        }
        $out = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if (($gateway->enabled ?? 'no') !== 'yes') {
                continue;
            }
            $out[] = ['id' => (string) $id, 'title' => (string) $gateway->get_title(), 'profile' => self::profileFor((string) $id)];
        }

        return $out;
    }

    /** @return array{label:string,payment:string,dispute:string}|null */
    public static function profileFor(string $gatewayId): ?array
    {
        $best = null;
        foreach (self::profiles() as $prefix => $profile) {
            if (str_starts_with($gatewayId, (string) $prefix) && ($best === null || strlen((string) $prefix) > strlen($best[0]))) {
                $best = [(string) $prefix, $profile];
            }
        }

        return $best[1] ?? null;
    }
}
