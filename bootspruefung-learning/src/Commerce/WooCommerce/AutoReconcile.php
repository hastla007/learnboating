<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Infrastructure\Cron;
use Bootspruefung\Learning\Infrastructure\Lock;

/**
 * Automatischer Abgleich der Bestellungen mit den Zugängen (Kap. 7.6), stündlich im Wartungslauf.
 *
 * Zieht Bestellereignisse nach, die das Plugin verpasst hat – z. B. weil es zeitweise deaktiviert war
 * oder ein Hook mit einem Fehler abbrach. Grundlage ist das Änderungsdatum der Bestellung: Jeder Lauf
 * gleicht die seit dem letzten Lauf geänderten Bestellungen über OrderSync::sync() ab (derselbe
 * idempotente Abgleich wie bei jedem Shop-Ereignis, manuelle Entscheidungen bleiben erhalten), höchstens
 * BATCH Bestellungen je Lauf; der Rest folgt im nächsten Lauf (Cursor).
 */
final class AutoReconcile
{
    /** Stand: Änderungsdatum (UTC, Unix-Zeit) und ID der zuletzt abgeglichenen Bestellung. */
    public const OPTION_CURSOR = 'bp_learning_reconcile_cursor';

    /** Zeitpunkt der letzten Deaktivierung (Systemstatus, Abgleich ab dort). */
    public const OPTION_DEACTIVATED = 'bp_learning_deactivated_at';

    public const BATCH = 100;

    /** Erster Lauf ohne Stand: Bestellungen der letzten 48 Stunden. */
    public const INITIAL_WINDOW = 2 * DAY_IN_SECONDS;

    /** Gerade geänderte Bestellungen überlässt der Lauf noch den Shop-Hooks (kein Wettlauf mit dem Checkout). */
    public const SETTLE = 5 * MINUTE_IN_SECONDS;

    /** Einmaliger Abgleich kurz nach der Aktivierung (danach stündlich im Wartungslauf). */
    public const HOOK = 'bp_learning_reconcile';

    private const LOCK = 'bp_learning_reconcile_lock';

    /**
     * @param int|null $limit Höchstzahl Bestellungen (Standard BATCH)
     * @return array{checked:int,changed:int,failed:int,more:bool}|null null = WooCommerce inaktiv oder Lauf gesperrt
     */
    public static function run(?int $limit = null, ?int $now = null): ?array
    {
        if (! Integration::active() || ! function_exists('wc_get_orders')) {
            return null;
        }
        if (! Lock::acquire(self::LOCK, 30 * MINUTE_IN_SECONDS)) {
            return null;
        }
        try {
            return self::batch(max(1, $limit ?? self::BATCH), $now ?? time());
        } finally {
            Lock::release(self::LOCK);
        }
    }

    /** @return array{checked:int,changed:int,failed:int,more:bool} */
    private static function batch(int $limit, int $now): array
    {
        [$since, $lastId] = self::cursor($now);
        $until = $now - self::SETTLE;
        $stats = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'more' => false];
        if ($since > $until) {
            return $stats;
        }
        // Eine Bestellung mehr lesen, um zu wissen, ob noch etwas übrig ist. Nur IDs (keine Objekte).
        $ids = wc_get_orders([
            'type' => 'shop_order',
            'status' => array_keys(wc_get_order_statuses()),
            'date_modified' => $since.'...'.$until,
            'orderby' => ['modified' => 'ASC', 'ID' => 'ASC'],
            'limit' => $limit + 50,
            'return' => 'ids',
        ]);
        $cursor = [$since, $lastId];
        $sync = Integration::sync();
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if (! $order instanceof \WC_Order) {
                continue;
            }
            $modified = $order->get_date_modified();
            $ts = $modified ? $modified->getTimestamp() : $since;
            // Bereits im letzten Lauf abgeglichen (gleiche Sekunde, kleinere oder gleiche ID).
            if ($ts === $since && (int) $id <= $lastId) {
                continue;
            }
            if ($stats['checked'] >= $limit) {
                $stats['more'] = true;
                break;
            }
            $stats['checked']++;
            try {
                $plan = $sync->sync((int) $id, 'auto_reconcile');
                $stats['changed'] += $plan !== null && $plan->changes !== [] ? 1 : 0;
            } catch (\Throwable $e) {
                $stats['failed']++;
                Cron::recordError('order_reconcile', $e, 'order '.$id);
            }
            // Aufsteigend nach Änderungsdatum und ID: die zuletzt abgeglichene Bestellung ist der neue Stand.
            $cursor = [max($since, $ts), (int) $id];
        }
        if (! $stats['more'] && count($ids) >= $limit + 50) {
            $stats['more'] = true;
        }
        // Ohne weitere Bestellungen bis $until weiterrücken (ältere ändern sich nur mit neuem Änderungsdatum).
        update_option(self::OPTION_CURSOR, $stats['more'] ? ['time' => $cursor[0], 'id' => $cursor[1]] : ['time' => $until, 'id' => 0], false);
        if (! $stats['more']) {
            // Alles bis $until nachgezogen: auch eine frühere Deaktivierung ist damit erledigt.
            delete_option(self::OPTION_DEACTIVATED);
        }
        if ($stats['changed'] > 0 || $stats['failed'] > 0) {
            AuditLog::record('order_reconcile', 'order', null, null, $stats);
        }

        return $stats;
    }

    /** @return array{0:int,1:int} Änderungsdatum (Unix-Zeit) und Bestell-ID, ab denen abgeglichen wird */
    private static function cursor(int $now): array
    {
        $stored = get_option(self::OPTION_CURSOR);
        if (is_array($stored) && isset($stored['time'])) {
            $since = (int) $stored['time'];
            $lastId = (int) ($stored['id'] ?? 0);
        } else {
            // Noch kein Stand (erster Lauf nach dem Update): die letzten 48 Stunden, nach einer
            // Deaktivierung mindestens ab deren Zeitpunkt. Danach steht der Stand auf dem letzten
            // Lauf vor einer Deaktivierung und deckt die Lücke damit von selbst ab.
            $since = $now - self::INITIAL_WINDOW;
            $deactivated = (int) get_option(self::OPTION_DEACTIVATED, 0);
            if ($deactivated > 0 && $deactivated < $since) {
                $since = $deactivated;
            }
            $lastId = 0;
        }

        return [$since, $lastId];
    }

    /** Plugin-Deaktivierung: Zeitpunkt merken, damit der Abgleich nach der Reaktivierung dort ansetzt. */
    public static function deactivated(): void
    {
        update_option(self::OPTION_DEACTIVATED, time(), false);
    }

    /** Plugin-Aktivierung: verpasste Bestellungen bald nachziehen (nicht erst in einer Stunde). */
    public static function activated(): void
    {
        if ((int) get_option(self::OPTION_DEACTIVATED, 0) > 0 && ! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
        }
    }
}
