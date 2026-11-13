<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

defined('ABSPATH') || exit;

/** Geplante Zugangsänderungen einer Bestellung (Vorschau für Abgleich, sonst angewendet). */
final class Plan
{
    /** @var list<array<string,mixed>> */
    public array $changes = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(public readonly int $orderId, public readonly ?string $orderStatus, public readonly string $reason)
    {
    }

    /** @param array<string,mixed> $change */
    public function change(array $change): void
    {
        $this->changes[] = $change;
    }

    public function warn(string $code): void
    {
        $this->warnings[] = $code;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /** Anzahl Widerrufe/Suspendierungen – für die Sicherheitsabfrage beim Abgleich. */
    public function removals(): int
    {
        return count(array_filter($this->changes, static fn ($c) => in_array($c['to'] ?? null, [EntitlementPlanner::REVOKED, EntitlementPlanner::SUSPENDED], true)));
    }
}
