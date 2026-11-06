<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

/**
 * Shop-neutrale Sicht auf eine Bestellung (Kap. 7.4/7.5). Der WooCommerce-Adapter
 * füllt sie über WC_Order-APIs; die Freischaltungslogik kennt nur diese Werte.
 */
final class OrderSnapshot
{
    /** @param list<OrderItemSnapshot> $items */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $status,
        public readonly bool $paid,
        public readonly ?string $datePaid,
        public readonly string $paymentMethod,
        public readonly array $items,
    ) {
    }
}
