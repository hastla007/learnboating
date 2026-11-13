<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

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

    /** Fehlgeschlagene Bestellungen [ID => Versuche], die die nächsten Läufe erneut abgleichen (T47). */
    public const OPTION_RETRY = 'bp_learning_reconcile_retry';

    /** So oft wird eine fehlgeschlagene Bestellung erneut versucht; danach bleibt sie nur im Fehlerprotokoll. */
    public const MAX_ATTEMPTS = 5;

    /** Letzter Lauf {at, checked, changed, failed, retried, more} für die Wartungsanzeige. */
    public const OPTION_LAST_RUN = 'bp_learning_reconcile_last';

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
        $stats = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'more' => false, 'retried' => 0];
        $sync = Integration::sync();
        // Zuerst früher fehlgeschlagene Bestellungen erneut versuchen (T47: Fehler wurden bisher nie wiederholt).
        $retry = self::retryList();
        foreach ($retry as $id => $attempts) {
            $stats['retried']++;
            try {
                $sync->sync((int) $id, 'auto_reconcile_retry');
                unset($retry[$id]);
            } catch (\Throwable $e) {
                $retry[$id] = $attempts + 1;
                if ($retry[$id] >= self::MAX_ATTEMPTS) {
                    unset($retry[$id]);
                }
                Cron::recordError('order_reconcile', $e, 'order '.$id.' (Versuch '.($attempts + 1).')');
            }
        }
        if ($since > $until) {
            self::saveRetry($retry);
            self::rememberRun($now, $stats);

            return $stats;
        }
        // Seitenweise lesen, bis unbearbeitete Bestellungen kommen: Liegen mehr als eine Seite bereits abgeglichener
        // Bestellungen in derselben Sekunde, blieb der Lauf sonst für immer an derselben Stelle stehen (T47).
        $pageSize = $limit + 50;
        $ids = [];
        for ($page = 1; $page <= 200; $page++) {
            $chunk = wc_get_orders([
                'type' => 'shop_order',
                'status' => array_keys(wc_get_order_statuses()),
                'date_modified' => $since.'...'.$until,
                'orderby' => ['modified' => 'ASC', 'ID' => 'ASC'],
                'limit' => $pageSize,
                'paged' => $page,
                'return' => 'ids',
            ]);
            $ids = array_merge($ids, array_map('intval', (array) $chunk));
            $fresh = array_filter((array) $chunk, static fn ($id) => (int) $id > $lastId);
            if (count($chunk) < $pageSize || count($fresh) > $limit || self::hasNewer((array) $chunk, $since)) {
                break;
            }
        }
        $cursor = [$since, $lastId];
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
                $retry[(int) $id] = $retry[(int) $id] ?? 1;
                Cron::recordError('order_reconcile', $e, 'order '.$id);
            }
            // Aufsteigend nach Änderungsdatum und ID: die zuletzt abgeglichene Bestellung ist der neue Stand.
            $cursor = [max($since, $ts), (int) $id];
        }
        if (! $stats['more'] && $stats['checked'] >= $limit && count($ids) > $stats['checked']) {
            $stats['more'] = true;
        }
        self::saveRetry($retry);
        self::rememberRun($now, $stats);
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

    /** Liegt in dieser Seite eine Bestellung mit späterem Änderungsdatum als $since? @param list<int|string> $ids */
    private static function hasNewer(array $ids, int $since): bool
    {
        $last = $ids === [] ? null : wc_get_order((int) end($ids));
        $modified = $last instanceof \WC_Order ? $last->get_date_modified() : null;

        return $modified !== null && $modified->getTimestamp() > $since;
    }

    /** @return array<int,int> Bestell-ID => bisherige Versuche */
    public static function retryList(): array
    {
        $list = get_option(self::OPTION_RETRY, []);

        return is_array($list) ? array_map('intval', $list) : [];
    }

    /** @param array<int,int> $retry */
    private static function saveRetry(array $retry): void
    {
        $retry === [] ? delete_option(self::OPTION_RETRY) : update_option(self::OPTION_RETRY, array_slice($retry, 0, 200, true), false);
    }

    /** @param array<string,int|bool> $stats */
    private static function rememberRun(int $now, array $stats): void
    {
        update_option(self::OPTION_LAST_RUN, ['at' => $now] + $stats, false);
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
