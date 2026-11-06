<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;

/**
 * Verbindet Bestellquelle, Produktzuordnung, Planer und Zugangsdienst.
 * Jedes Shop-Ereignis führt zu einem vollständigen, idempotenten Abgleich der
 * Bestellung – egal ob es doppelt, verspätet oder in anderer Reihenfolge eintrifft.
 */
final class OrderSync
{
    public function __construct(
        private readonly OrderSource $orders,
        private readonly ProductAccessRepository $products,
        private readonly EntitlementService $entitlements,
        private readonly EntitlementPlanner $planner = new EntitlementPlanner(),
        private readonly ?GroupService $groups = null,
    ) {
    }

    /** @var array{order:OrderSnapshot,groups:array<int,list<object>>,target:array{0:?string,1:string}}|null */
    private ?array $context = null;

    /**
     * @param array{0:?string,1:string}|null $target Soll-Status erzwingen (Gateway-Hooks/Admin)
     */
    public function plan(int $orderId, ?array $target = null): ?Plan
    {
        $order = $this->orders->snapshot($orderId);
        if ($order === null) {
            return null;
        }
        $mappings = [];
        $groupMappings = [];
        foreach ($order->items as $item) {
            $rows = $this->products->forItem($item->productId, $item->variationId);
            // Gruppenlizenz: Zugänge entstehen erst beim Beitritt der Mitglieder.
            if (ProductAccessRepository::seats($rows) !== null || ($this->groups !== null && $this->groups->byItem($item->id) !== null)) {
                $groupMappings[$item->id] = $rows;
                $rows = [];
            }
            $mappings[$item->id] = $rows;
        }
        $existing = $this->entitlements->forOrder($orderId);
        if ($target === null) {
            /**
             * Filter: Soll-Status für eine Bestellung ändern, z. B. für Gateways mit eigenen
             * Status (Rückgabe [Status|null, Grund]). Status: active, pending, suspended, revoked.
             */
            $filtered = apply_filters('bootspruefung_order_access_target', EntitlementPlanner::orderTarget($order), $order);
            $target = is_array($filtered) && count($filtered) === 2 ? array_values($filtered) : null;
        }
        $this->context = ['order' => $order, 'groups' => $groupMappings, 'target' => $target ?? EntitlementPlanner::orderTarget($order)];
        if ($existing === [] && ! array_filter($mappings)) {
            $plan = new Plan($orderId, $groupMappings !== [] ? $this->context['target'][0] : null, $groupMappings !== [] ? 'group_only' : 'no_learning_products');
            if ($groupMappings !== [] && $order->userId <= 0) {
                $plan->warn('no_account');
            }

            return $plan;
        }

        return $this->planner->plan($order, $mappings, $existing, $target);
    }

    public function sync(int $orderId, string $via = 'order_sync', ?array $target = null): ?Plan
    {
        $plan = $this->plan($orderId, $target);
        if ($plan === null) {
            return null;
        }
        if (in_array('no_account', $plan->warnings, true) && in_array($plan->reason, ['order_paid', 'group_only'], true) && $plan->orderStatus === EntitlementPlanner::ACTIVE) {
            AuditLog::record('order_without_account', 'order', (string) $orderId, null, ['via' => $via]);
        }
        $this->entitlements->apply($plan, $via);
        $this->syncGroups();

        return $plan;
    }

    private function syncGroups(): void
    {
        if ($this->groups === null || $this->context === null || $this->context['groups'] === []) {
            return;
        }
        $order = $this->context['order'];
        foreach ($order->items as $item) {
            if (isset($this->context['groups'][$item->id])) {
                $this->groups->syncItem($order, $item, $this->context['groups'][$item->id], $this->context['target']);
            }
        }
    }

    public function groups(): ?GroupService
    {
        return $this->groups;
    }

    /** Admin/Gateway: bezahlte Bestellung suspendieren (z. B. PayPal-/Klarna-Konflikt). */
    public function suspendOrder(int $orderId, string $reason): int
    {
        return $this->manualForOrder($orderId, EntitlementPlanner::SUSPENDED, $reason) + $this->manualGroups($orderId, GroupService::SUSPENDED, $reason);
    }

    /** Konflikt verloren / Rückbuchung endgültig: alle Zugänge und Gruppen der Bestellung widerrufen. */
    public function revokeOrder(int $orderId, string $reason): int
    {
        return $this->manualForOrder($orderId, EntitlementPlanner::REVOKED, $reason) + $this->manualGroups($orderId, GroupService::REVOKED, $reason);
    }

    private function manualGroups(int $orderId, string $status, string $reason): int
    {
        $n = 0;
        foreach ($this->groups?->forOrder($orderId) ?? [] as $group) {
            if ($group->status !== GroupService::REVOKED) {
                $this->groups->setStatus((int) $group->id, $status, $reason, true);
                $n++;
            }
        }

        return $n;
    }

    /** Admin: vor Zahlungseingang freischalten (z. B. Überweisung angekündigt, Kap. 7.5). */
    public function approveOrder(int $orderId, string $reason): int
    {
        $plan = $this->plan($orderId, [EntitlementPlanner::ACTIVE, 'manual_approval']);
        if ($plan === null) {
            return 0;
        }
        $this->entitlements->apply($plan, 'manual_approval');
        $this->syncGroups();
        $this->manualGroups($orderId, GroupService::ACTIVE, $reason);

        return $this->manualForOrder($orderId, EntitlementPlanner::ACTIVE, $reason);
    }

    /** Hebt manuelle Entscheidungen der Bestellung auf und gleicht mit dem Shop ab. */
    public function releaseOrder(int $orderId, string $reason): ?Plan
    {
        foreach ($this->entitlements->forOrder($orderId) as $row) {
            if (isset(EntitlementPlanner::meta($row)['manual'])) {
                $this->entitlements->releaseManual((int) $row->id, $reason);
            }
        }
        foreach ($this->groups?->forOrder($orderId) ?? [] as $group) {
            if ($group->status !== GroupService::REVOKED) {
                // Manuelle Entscheidung aufheben; der Abgleich setzt den Shop-Stand.
                $this->groups->setStatus((int) $group->id, $group->status, $reason, false);
            }
        }

        return $this->sync($orderId, 'manual_release');
    }

    private function manualForOrder(int $orderId, string $status, string $reason): int
    {
        $n = 0;
        foreach ($this->entitlements->forOrder($orderId) as $row) {
            if ($row->status !== EntitlementPlanner::REVOKED || $status === EntitlementPlanner::REVOKED) {
                $this->entitlements->setStatusManual((int) $row->id, $status, $reason);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Abgleich aller Bestellungen mit Lernprodukten (Kap. 7.6 Reconciliation).
     *
     * @return \Generator<int,Plan>
     */
    public function reconcile(?string $since = null, int $perPage = 50): \Generator
    {
        $productIds = $this->products->productIds();
        $seen = [];
        $page = 1;
        do {
            $batch = $productIds === [] ? ['ids' => [], 'more' => false] : $this->orders->orderIds($productIds, $since, $page, $perPage);
            foreach ($batch['ids'] as $id) {
                $seen[$id] = true;
                $plan = $this->plan($id);
                if ($plan !== null) {
                    yield $id => $plan;
                }
            }
            $page++;
        } while ($batch['more']);
        // Bestellungen mit Zugängen, deren Produktzuordnung inzwischen entfernt wurde.
        foreach ($this->entitlements->orderIds($since) as $id) {
            if (! isset($seen[$id]) && ($plan = $this->plan($id)) !== null) {
                yield $id => $plan;
            }
        }
    }

    public function entitlements(): EntitlementService
    {
        return $this->entitlements;
    }
}
