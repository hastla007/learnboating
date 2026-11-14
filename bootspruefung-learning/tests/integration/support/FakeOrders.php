<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tests;

use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSnapshot;
use Bootspruefung\Learning\Commerce\OrderSource;

/** Testbestellungen statt WooCommerce (Integrationstests). */
final class FakeOrders implements OrderSource
{
    /** @var array<int,OrderSnapshot> */
    public array $orders = [];

    public function snapshot(int $orderId): ?OrderSnapshot
    {
        return $this->orders[$orderId] ?? null;
    }

    public function orderIds(array $productIds, ?string $since, int $page, int $perPage): array
    {
        $ids = [];
        foreach ($this->orders as $id => $o) {
            foreach ($o->items as $i) {
                if (in_array($i->productId, $productIds, true)) {
                    $ids[] = $id;
                    break;
                }
            }
        }
        rsort($ids);

        return ['ids' => array_slice($ids, ($page - 1) * $perPage, $perPage), 'more' => count($ids) > $page * $perPage];
    }

    /** @param list<OrderItemSnapshot> $items */
    public function put(int $id, int $user, string $status, ?string $datePaid, array $items, string $method = 'stripe'): void
    {
        $paid = in_array($status, ['processing', 'completed'], true);
        $this->orders[$id] = new OrderSnapshot($id, $user, $status, $paid, $datePaid, $method, $items);
    }
}
