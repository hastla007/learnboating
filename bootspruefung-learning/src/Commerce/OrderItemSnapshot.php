<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

defined('ABSPATH') || exit;

/** Eine Bestellposition; Erstattungen als positive Werte. */
final class OrderItemSnapshot
{
    public function __construct(
        public readonly int $id,
        public readonly int $productId,
        public readonly int $variationId,
        public readonly int $quantity,
        public readonly string $sku,
        public readonly float $total,
        public readonly int $refundedQuantity = 0,
        public readonly float $refundedTotal = 0.0,
    ) {
    }

    public function fullyRefunded(): bool
    {
        return ($this->quantity > 0 && $this->refundedQuantity >= $this->quantity)
            || ($this->total > 0 && $this->refundedTotal + 0.005 >= $this->total);
    }

    public function anyRefund(): bool
    {
        return $this->refundedQuantity > 0 || $this->refundedTotal > 0.004;
    }
}
