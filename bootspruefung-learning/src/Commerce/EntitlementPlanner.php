<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

/**
 * Leitet aus einer Bestellung den Soll-Zustand der Zugänge ab (Kap. 7.5) –
 * ohne Datenbank- oder Shopzugriff, damit Synchronisation und Abgleich
 * dieselbe Logik verwenden und wiederholte Ereignisse idempotent bleiben.
 *
 * Zahlungsarten-neutral: maßgeblich sind is_paid() (WooCommerce: Status
 * processing/completed bzw. wc_get_is_paid_statuses()), das Zahldatum und der
 * Bestellstatus. So verhalten sich Überweisung (on-hold bis Zahlungseingang),
 * Klarna, PayPal, Stripe und künftige Gateways gleich.
 */
final class EntitlementPlanner
{
    public const ACTIVE = 'active';
    public const PENDING = 'pending';
    public const SUSPENDED = 'suspended';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    public const STATUSES = [self::PENDING, self::ACTIVE, self::SUSPENDED, self::REVOKED, self::EXPIRED];

    /**
     * Soll-Status auf Bestellebene: [Status|null (= nicht ändern), Grund].
     *
     * @return array{0:?string,1:string}
     */
    public static function orderTarget(OrderSnapshot $order): array
    {
        $status = $order->status;
        if ($status === 'cancelled') {
            return [self::REVOKED, 'order_cancelled'];
        }
        if ($status === 'refunded') {
            return [self::REVOKED, 'order_refunded'];
        }
        if ($order->paid) {
            return [self::ACTIVE, 'order_paid'];
        }
        if ($status === 'on-hold' && $order->datePaid !== null) {
            // Bereits bezahlte Bestellung zurück auf „In Wartestellung“: z. B. Stripe-Dispute.
            return [self::SUSPENDED, 'payment_disputed'];
        }
        if ($status === 'failed') {
            return [self::REVOKED, 'payment_failed'];
        }
        if (in_array($status, ['pending', 'on-hold', 'checkout-draft'], true)) {
            return [self::PENDING, 'awaiting_payment'];
        }

        // Unbekannte/eigene Status (Papierkorb, Gateway-Status): bestehenden Zustand behalten.
        return [null, 'status_'.$status];
    }

    /**
     * @param array<int,list<object>> $mappings Order-Item-ID => wirksame Produktzuordnungen
     * @param list<object> $existing Entitlement-Zeilen dieser Bestellung
     * @param array{0:?string,1:string}|null $target überschreibt orderTarget() (Filter/Gateway-Hooks)
     */
    public function plan(OrderSnapshot $order, array $mappings, array $existing, ?array $target = null): Plan
    {
        [$orderStatus, $orderReason] = $target ?? self::orderTarget($order);
        $plan = new Plan($order->id, $orderStatus, $orderReason);
        $byKey = [];
        foreach ($existing as $row) {
            $byKey[self::key((int) $row->order_item_id, $row->course_code, $row->feature_code)] = $row;
        }
        if ($order->userId <= 0) {
            $plan->warn('no_account');
        }

        $itemIds = [];
        foreach ($order->items as $item) {
            $itemIds[$item->id] = true;
            $scopes = [];
            foreach ($existing as $row) {
                if ((int) $row->order_item_id === $item->id) {
                    $scopes[$row->course_code.'|'.$row->feature_code] = ['course' => $row->course_code, 'feature' => $row->feature_code, 'mapping' => null];
                }
            }
            foreach ($mappings[$item->id] ?? [] as $m) {
                $k = $m->course_code.'|'.$m->feature_code;
                $scopes[$k] = ['course' => $m->course_code, 'feature' => $m->feature_code, 'mapping' => $m];
            }
            foreach ($scopes as $scope) {
                $row = $byKey[self::key($item->id, $scope['course'], $scope['feature'])] ?? null;
                $refundRule = $row !== null ? (self::meta($row)['refund_rule'] ?? 'revoke_on_full_refund') : ($scope['mapping']->refund_rule ?? 'revoke_on_full_refund');
                [$status, $reason] = [$orderStatus, $orderReason];
                if ($status === self::REVOKED && $orderReason === 'order_refunded' && $refundRule === 'keep') {
                    [$status, $reason] = [null, 'refund_kept'];
                } elseif ($status === self::ACTIVE && $refundRule !== 'keep'
                    && (($refundRule === 'revoke_on_full_refund' && $item->fullyRefunded()) || ($refundRule === 'revoke_on_any_refund' && $item->anyRefund()))) {
                    [$status, $reason] = [self::REVOKED, 'item_refunded'];
                }
                $this->decide($plan, $order, $item, $scope, $row, $status, $reason, $refundRule);
            }
        }
        // Zeilen zu Positionen, die nicht mehr in der Bestellung stehen (Position gelöscht).
        foreach ($existing as $row) {
            if (! isset($itemIds[(int) $row->order_item_id]) && $row->status !== self::REVOKED) {
                $plan->change(['type' => 'status', 'id' => (int) $row->id, 'item_id' => (int) $row->order_item_id, 'course' => $row->course_code, 'feature' => $row->feature_code, 'from' => $row->status, 'to' => self::REVOKED, 'reason' => 'item_removed']);
            }
        }

        return $plan;
    }

    /** @param array{course:string,feature:string,mapping:?object} $scope */
    private function decide(Plan $plan, OrderSnapshot $order, OrderItemSnapshot $item, array $scope, ?object $row, ?string $status, string $reason, string $refundRule): void
    {
        $base = ['item_id' => $item->id, 'course' => $scope['course'], 'feature' => $scope['feature'], 'reason' => $reason];
        if ($row === null) {
            if ($order->userId <= 0 || $scope['mapping'] === null || ! in_array($status, [self::ACTIVE, self::PENDING], true)) {
                return;
            }
            $m = $scope['mapping'];
            $plan->change($base + [
                'type' => 'create', 'to' => $status, 'user_id' => $order->userId,
                'product_id' => $item->productId, 'variation_id' => $item->variationId, 'sku' => $item->sku,
                'duration_days' => $m->duration_days === null ? null : (int) $m->duration_days,
                'renewal_mode' => (string) $m->renewal_mode, 'refund_rule' => $refundRule, 'date_paid' => $order->datePaid,
            ]);

            return;
        }
        if ($order->userId > 0 && (int) $row->user_id !== $order->userId) {
            $plan->change($base + ['type' => 'user', 'id' => (int) $row->id, 'from_user' => (int) $row->user_id, 'user_id' => $order->userId]);
        }
        if ($status === null || $status === $row->status) {
            return;
        }
        $manual = self::meta($row)['manual'] ?? null;
        // Manuelle Adminentscheidung gilt weiter, nur Storno/Erstattung widerrufen trotzdem.
        if (is_array($manual) && $status !== self::REVOKED) {
            return;
        }
        // Widerruf nach Freischaltung ist endgültig (Erstattung/Storno); ein nie aktiver Zugang
        // (fehlgeschlagene Zahlung, später doch bezahlt) darf wieder aufleben.
        if (($row->status === self::REVOKED && $row->valid_from !== null) || ($row->status === self::EXPIRED && $status === self::ACTIVE)) {
            return;
        }
        if ($row->status === self::PENDING && $status === self::SUSPENDED) {
            return;
        }
        $plan->change($base + ['type' => 'status', 'id' => (int) $row->id, 'from' => $row->status, 'to' => $status, 'date_paid' => $order->datePaid,
            'first_activation' => $status === self::ACTIVE && $row->valid_from === null]);
    }

    public static function key(int $itemId, string $course, string $feature): string
    {
        return $itemId.'|'.$course.'|'.$feature;
    }

    /** @return array<string,mixed> */
    public static function meta(object $row): array
    {
        $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : null;

        return is_array($meta) ? $meta : [];
    }
}
