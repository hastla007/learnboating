<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

/** Liefert Bestellungen als Snapshot (WooCommerce oder Testquelle). */
interface OrderSource
{
    public function snapshot(int $orderId): ?OrderSnapshot;

    /**
     * Bestell-IDs mit Lernprodukten für den Abgleich, neueste zuerst.
     *
     * @param list<int> $productIds
     * @return array{ids:list<int>,more:bool}
     */
    public function orderIds(array $productIds, ?string $since, int $page, int $perPage): array;
}
