<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Import;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Infrastructure\Lock;
use Bootspruefung\Learning\Plugin;

/**
 * Aufbewahrungsfristen für Import-Daten (stündlich im Wartungslauf).
 *
 * Paketkopien (je Import rund 17 MB): Behalten werden immer das aktive Paket (Ziel der Wiederherstellung)
 * und die letzten KEEP_PUBLISHED weiteren veröffentlichten Pakete (Ziele für „Zurücksetzen“), außerdem
 * Importe in Arbeit (hochgeladen bis importierend) bis STALE_DAYS. Ältere Pakete lassen sich bei Bedarf
 * erneut hochladen. Pfade kommen aus ImportService::packageDir() – die Ablage wird hier nicht festgelegt.
 *
 * Import-Protokoll: Einträge „unchanged“ (rund 98 % der Zeilen) nach UNCHANGED_DAYS, alle übrigen nach
 * LOG_DAYS löschen – beim aktiven Import bleiben die Änderungen (create/update/…) erhalten.
 */
final class Retention
{
    public const KEEP_PUBLISHED = 2;
    public const STALE_DAYS = 30;
    public const ORPHAN_HOURS = 24;
    public const UNCHANGED_DAYS = 14;
    public const LOG_DAYS = 180;

    /** Höchstzahl gelöschter Protokollzeilen je Lauf (kurze Sperren auf der Tabelle). */
    public const LOG_BATCH = 20000;

    private const IN_PROGRESS = ['uploaded', 'validated', 'previewed', 'approved', 'importing'];

    /** @return array{packages:list<int>,orphans:int,log_rows:int}|null null = anderer Lauf aktiv */
    public static function run(?int $now = null): ?array
    {
        if (! Lock::acquire('bp_learning_retention_lock', HOUR_IN_SECONDS)) {
            return null;
        }
        try {
            $service = Plugin::instance()->importService();
            $now ??= time();
            $result = ['packages' => self::prunePackages($service, $now), 'orphans' => self::pruneOrphans($service, $now), 'log_rows' => self::pruneLogs($service, $now)];
            if ($result['packages'] !== [] || $result['orphans'] > 0 || $result['log_rows'] > 0) {
                AuditLog::record('import_retention', 'import_batch', null, null, $result);
            }

            return $result;
        } finally {
            Lock::release('bp_learning_retention_lock');
        }
    }

    /**
     * Imports, deren Paketdateien behalten werden (aktives Paket, letzte veröffentlichte, laufende).
     *
     * @return array<int,true>
     */
    public static function protectedBatches(ImportService $service, ?int $now = null): array
    {
        global $wpdb;
        $now ??= time();
        $keep = [];
        $active = $service->activeBatchId();
        if ($active !== null) {
            $keep[$active] = true;
        }
        /** Filter: Anzahl der veröffentlichten Pakete (außer dem aktiven), die für „Zurücksetzen“ erhalten bleiben. */
        $n = max(0, (int) apply_filters('bootspruefung_import_keep_packages', self::KEEP_PUBLISHED));
        $published = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}bp_import_batch WHERE status = 'published' ORDER BY published_at DESC, id DESC");
        foreach ($published as $id) {
            if (count($keep) >= $n + ($active !== null ? 1 : 0)) {
                break;
            }
            $keep[(int) $id] = true;
        }
        // Importe in Arbeit (auch abgebrochene, die fortgesetzt oder verworfen werden) bis STALE_DAYS.
        $since = gmdate('Y-m-d H:i:s', $now - self::STALE_DAYS * DAY_IN_SECONDS);
        $placeholders = implode(',', array_fill(0, count(self::IN_PROGRESS), '%s'));
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bp_import_batch WHERE status IN ({$placeholders}) AND created_at >= %s", array_merge(self::IN_PROGRESS, [$since]))) as $id) {
            $keep[(int) $id] = true;
        }
        // Ein abgebrochener Import nach dem letzten veröffentlichten wird ggf. noch mit dem aktiven Paket repariert.
        $aborted = $service->abortedImport();
        if ($aborted !== null) {
            $keep[$aborted] = true;
        }

        return $keep;
    }

    /** @return list<int> Imports, deren Paketdateien gelöscht wurden */
    private static function prunePackages(ImportService $service, int $now): array
    {
        global $wpdb;
        $keep = self::protectedBatches($service, $now);
        $removed = [];
        foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}bp_import_batch ORDER BY id") as $id) {
            $id = (int) $id;
            if (isset($keep[$id])) {
                continue;
            }
            $dir = $service->packageDir($id);
            if (is_dir($dir)) {
                ImportService::removeDir($dir);
                $removed[] = $id;
            }
        }

        return $removed;
    }

    /** Verwaiste Ordner (abgebrochener Upload, Import-Zeile gelöscht) im Paketordner nach ORPHAN_HOURS. */
    private static function pruneOrphans(ImportService $service, int $now): int
    {
        global $wpdb;
        $known = [];
        foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}bp_import_batch") as $id) {
            $known[realpath($service->packageDir((int) $id)) ?: $service->packageDir((int) $id)] = true;
        }
        $base = dirname($service->packageDir(1));
        $count = 0;
        foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            // Nur eigene Ablagen (Upload-Zwischenordner, Paketordner) – nichts anderes im Upload-Verzeichnis.
            if (! preg_match('/^(upload|batch)-[A-Za-z0-9]+$/', $name) || isset($known[realpath($dir) ?: $dir])) {
                continue;
            }
            if ($now - (int) filemtime($dir) < self::ORPHAN_HOURS * HOUR_IN_SECONDS) {
                continue;
            }
            ImportService::removeDir($dir);
            $count++;
        }

        return $count;
    }

    private static function pruneLogs(ImportService $service, int $now): int
    {
        global $wpdb;
        $t = $wpdb->prefix.'bp_import_item_log';
        /** Filter: Tage, nach denen Protokollzeilen „unverändert“ bzw. alle übrigen gelöscht werden. */
        $unchangedDays = max(1, (int) apply_filters('bootspruefung_import_log_unchanged_days', self::UNCHANGED_DAYS));
        $logDays = max($unchangedDays, (int) apply_filters('bootspruefung_import_log_days', self::LOG_DAYS));
        $active = (int) ($service->activeBatchId() ?? 0);
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$t} WHERE action = 'unchanged' AND created_at < %s LIMIT %d",
            gmdate('Y-m-d H:i:s', $now - $unchangedDays * DAY_IN_SECONDS),
            self::LOG_BATCH
        ));
        $deleted += (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$t} WHERE batch_id <> %d AND created_at < %s LIMIT %d",
            $active,
            gmdate('Y-m-d H:i:s', $now - $logDays * DAY_IN_SECONDS),
            self::LOG_BATCH
        ));

        return $deleted;
    }
}
