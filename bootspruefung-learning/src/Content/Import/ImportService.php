<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Import;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Package\Package;
use Bootspruefung\Learning\Content\Package\SchemaValidator;
use RuntimeException;
use wpdb;

/**
 * Ablauf eines Imports (Konzept Kap. 3.3, 9):
 *   uploaded → validated | failed → previewed (Dry-Run) → approved → importing → published
 * Veröffentlicht wird nur nach ausdrücklicher Freigabe. Der Import läuft in
 * Teilschritten (runStep), damit große Pakete keinen Request-Timeout auslösen;
 * der Fortschritt steht im Batch und ist nach Abbruch fortsetzbar.
 */
final class ImportService
{
    /** Reihenfolge der Phasen; *_links verknüpfen bereits angelegte Datensätze. */
    public const PHASES = [
        'courses' => 'courses',
        'modules' => 'modules',
        'media' => 'media',
        'questions' => 'questions',
        'module_links' => 'modules',
        'course_links' => 'courses',
        'exam_rules' => 'exam_rules',
        'exam_papers' => 'exam_papers',
        'navigation_tasks' => 'navigation_tasks',
        'praxis_tasks' => 'praxis_tasks',
        'video_modules' => 'video_modules',
        'radio_texts' => 'radio_texts',
        'radio_vocabulary' => 'radio_vocabulary',
        'radio_practice' => 'radio_practice',
        'device_simulation' => 'device_simulation',
        'exam_committees' => 'exam_committees',
        'exam_guides' => 'exam_guides',
        'archive' => null,
    ];

    private const LOCK = 'bp_learning_import_lock';

    public function __construct(private readonly wpdb $db, private readonly string $schemaDir)
    {
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** Legt einen Batch aus einem hochgeladenen ZIP an und prüft ihn sofort. */
    public function createFromZip(string $zipPath): int
    {
        $tmp = MediaStorage::packagesDir().'/upload-'.wp_generate_password(12, false);
        try {
            $package = Package::extract($zipPath, $tmp);
            $batchId = $this->createBatch($package);
        } catch (RuntimeException $e) {
            if (is_dir($tmp)) {
                self::removeDir($tmp);
            }
            throw $e;
        }
        $this->clearBatchDir($batchId);
        rename($tmp, $this->batchDir($batchId));
        $this->validate($batchId);

        return $batchId;
    }

    /** Legt einen Batch aus einem entpackten Paketordner an (WP-CLI). */
    public function createFromDirectory(string $dir): int
    {
        $batchId = $this->createBatch(new Package(rtrim($dir, '/')));
        $this->clearBatchDir($batchId);
        self::copyDir(rtrim($dir, '/'), $this->batchDir($batchId));
        $this->validate($batchId);

        return $batchId;
    }

    /**
     * Rollback (Konzept Kap. 9): legt aus einem früher veröffentlichten Paket einen
     * neuen Import an. Er durchläuft wie jeder Import Prüfung, Vorschau und Freigabe.
     * Beim Import werden die damaligen Fragenrevisionen wieder aktiv (nichts wird
     * gelöscht), seitdem hinzugekommene Inhalte werden archiviert.
     */
    public function rollback(int $targetBatchId, string $reason): int
    {
        $target = $this->batch($targetBatchId);
        if ($target->status !== 'published') {
            throw new RuntimeException('Zurücksetzen ist nur auf einen veröffentlichten Stand möglich.');
        }
        if ($target->package_id === get_option('bp_learning_active_package')) {
            throw new RuntimeException('Dieses Paket ist bereits aktiv.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Für das Zurücksetzen ist eine Begründung erforderlich.');
        }
        $dir = $this->batchDir($targetBatchId);
        if (! is_dir($dir)) {
            throw new RuntimeException("Die Paketdateien von Import #{$targetBatchId} sind nicht mehr vorhanden.");
        }
        $batchId = $this->createFromDirectory($dir);
        $stats = $this->stats($this->batch($batchId));
        $stats['rollback_of'] = $targetBatchId;
        $stats['rollback_reason'] = $reason;
        $this->updateBatch($batchId, ['stats' => wp_json_encode($stats)]);
        AuditLog::record('import_rollback_requested', 'import_batch', (string) $batchId, $reason, ['target_batch' => $targetBatchId, 'package_id' => $target->package_id]);

        return $batchId;
    }

    public function validate(int $batchId): bool
    {
        $package = $this->package($batchId);
        try {
            $errors = $package->validate(new SchemaValidator($this->schemaDir));
        } catch (RuntimeException $e) {
            $errors = [$e->getMessage()];
        }
        $this->updateBatch($batchId, [
            'status' => $errors === [] ? 'validated' : 'failed',
            'error' => $errors === [] ? null : implode("\n", $errors),
        ]);
        AuditLog::record($errors === [] ? 'import_validated' : 'import_validation_failed', 'import_batch', (string) $batchId, null, ['errors' => count($errors)]);

        return $errors === [];
    }

    /**
     * Dry-Run: ermittelt je Entität, was der Import anlegen, ändern, unverändert
     * lassen oder archivieren würde, ohne zu schreiben.
     * @return array<string,array<string,int>>
     */
    public function preview(int $batchId): array
    {
        $batch = $this->batch($batchId);
        if (! in_array($batch->status, ['validated', 'previewed'], true)) {
            throw new RuntimeException('Vorschau ist nur für geprüfte Pakete möglich.');
        }
        $package = $this->package($batchId);
        $importer = new EntityImporter($this->db, $package, $batch->package_id, true);
        $summary = [];
        foreach (Package::ENTITIES as $entity) {
            $summary[$entity] = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'archive' => 0];
            foreach ($package->entities($entity) as $record) {
                $summary[$entity][$importer->import($entity, $record)]++;
            }
        }
        foreach ($importer->archiveMissing() as $entity => $ids) {
            $summary[$entity] ??= ['create' => 0, 'update' => 0, 'unchanged' => 0, 'archive' => 0];
            $summary[$entity]['archive'] = count($ids);
        }
        $summary['_rights'] = $this->rightsSummary($package);
        $stats = $this->stats($batch);
        $stats['preview'] = $summary;
        $this->updateBatch($batchId, ['status' => 'previewed', 'stats' => wp_json_encode($stats)]);

        return $summary;
    }

    public function approve(int $batchId, ?string $note = null): void
    {
        $batch = $this->batch($batchId);
        if ($batch->status !== 'previewed') {
            throw new RuntimeException('Freigabe erst nach der Vorschau (Dry-Run) möglich.');
        }
        $this->updateBatch($batchId, [
            'status' => 'approved',
            'approved_by' => get_current_user_id() ?: null,
            'approved_at' => current_time('mysql', true),
        ]);
        AuditLog::record('import_approved', 'import_batch', (string) $batchId, $note, ['package_id' => $batch->package_id]);
    }

    public function discard(int $batchId): void
    {
        $batch = $this->batch($batchId);
        if (in_array($batch->status, ['importing', 'published'], true)) {
            throw new RuntimeException('Laufende oder veröffentlichte Importe können nicht verworfen werden.');
        }
        $this->updateBatch($batchId, ['status' => 'discarded']);
        if (is_dir($this->batchDir($batchId))) {
            self::removeDir($this->batchDir($batchId));
        }
        AuditLog::record('import_discarded', 'import_batch', (string) $batchId);
    }

    /**
     * Verarbeitet bis zu $limit Datensätze der aktuellen Phase.
     * @return array{done:bool,phase:string,processed:int,total:int}
     */
    public function runStep(int $batchId, int $limit = 200): array
    {
        $batch = $this->batch($batchId);
        if (! in_array($batch->status, ['approved', 'importing'], true)) {
            throw new RuntimeException('Import ist nicht freigegeben.');
        }
        // Nur ein Import zur Zeit: ein anderer, noch nicht abgeschlossener Import hat Vorrang.
        $other = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->db->prefix}bp_import_batch WHERE status = 'importing' AND id <> %d LIMIT 1", $batchId));
        if ($other !== null) {
            throw new RuntimeException(sprintf('Import #%d läuft noch – bitte erst abschließen.', (int) $other));
        }
        if (! $this->acquireLock($batchId)) {
            throw new RuntimeException('Ein anderer Import läuft gerade.');
        }

        try {
            $stats = $this->stats($batch);
            $cursor = $stats['cursor'] ?? ['phase' => array_key_first(self::PHASES), 'offset' => 0];
            $stats['actions'] ??= [];
            if ($batch->status === 'approved') {
                $this->updateBatch($batchId, ['status' => 'importing']);
                AuditLog::record('import_started', 'import_batch', (string) $batchId);
            }

            $package = $this->package($batchId);
            $importer = new EntityImporter($this->db, $package, $batch->package_id, false);
            $phase = $cursor['phase'];
            $logs = [];

            $this->db->query('START TRANSACTION');
            try {
                if ($phase === 'archive') {
                    foreach ($importer->archiveMissing() as $entity => $ids) {
                        foreach ($ids as $id) {
                            $logs[] = [$entity, $id, 'archive', null];
                        }
                        $stats['actions'][$entity]['archive'] = count($ids);
                    }
                    $records = [];
                    $total = 0;
                } else {
                    $entity = self::PHASES[$phase];
                    $all = $package->entities($entity);
                    $total = count($all);
                    $records = array_slice($all, (int) $cursor['offset'], $limit);
                    foreach ($records as $record) {
                        $action = $importer->import($phase, $record);
                        $stats['actions'][$phase][$action] = ($stats['actions'][$phase][$action] ?? 0) + 1;
                        if (! str_ends_with($phase, '_links')) {
                            $logs[] = [$entity, $record['source_id'], $action, null];
                        }
                    }
                }
                $this->writeLogs($batchId, $logs);
                $this->db->query('COMMIT');
            } catch (\Throwable $e) {
                $this->db->query('ROLLBACK');
                $stats['cursor'] = $cursor;
                $this->updateBatch($batchId, ['error' => $e->getMessage(), 'stats' => wp_json_encode($stats)]);
                $this->writeLogs($batchId, [[$phase, null, 'error', $e->getMessage()]]);
                throw new RuntimeException("Import in Phase {$phase} abgebrochen: {$e->getMessage()}", 0, $e);
            }

            $offset = (int) $cursor['offset'] + count($records);
            $done = false;
            if ($phase === 'archive') {
                $done = true;
            } elseif ($offset >= $total) {
                $phases = array_keys(self::PHASES);
                $cursor = ['phase' => $phases[array_search($phase, $phases, true) + 1], 'offset' => 0];
            } else {
                $cursor['offset'] = $offset;
            }
            $stats['cursor'] = $cursor;
            $update = ['stats' => wp_json_encode($stats), 'error' => null];
            if ($done) {
                $update['status'] = 'published';
                $update['published_at'] = current_time('mysql', true);
                unset($stats['cursor']);
                $update['stats'] = wp_json_encode($stats);
            }
            $this->updateBatch($batchId, $update);
            if ($done) {
                update_option('bp_learning_active_package', $batch->package_id, true);
                wp_cache_flush_group('bootspruefung');
                AuditLog::record(isset($stats['rollback_of']) ? 'import_rolled_back' : 'import_published', 'import_batch', (string) $batchId, $stats['rollback_reason'] ?? null, ['package_id' => $batch->package_id, 'actions' => $stats['actions'], 'rollback_of' => $stats['rollback_of'] ?? null]);
            }

            return ['done' => $done, 'phase' => $phase, 'processed' => min($offset, $total), 'total' => $total];
        } finally {
            $this->releaseLock($batchId);
        }
    }

    /** Führt den freigegebenen Import vollständig aus (CLI). */
    public function runAll(int $batchId, ?callable $progress = null): void
    {
        do {
            $step = $this->runStep($batchId, 500);
            if ($progress) {
                $progress($step);
            }
        } while (! $step['done']);
    }

    public function batch(int $batchId): object
    {
        $batch = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t('import_batch')} WHERE id = %d", $batchId));
        if (! $batch) {
            throw new RuntimeException("Import #{$batchId} nicht gefunden.");
        }

        return $batch;
    }

    /** @return array<string,mixed> */
    public function stats(object $batch): array
    {
        $stats = $batch->stats ? json_decode((string) $batch->stats, true) : [];

        return is_array($stats) ? $stats : [];
    }

    /** @return list<object> */
    public function batches(int $limit = 50): array
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT id, package_id, schema_version, source_commit, status, created_at, approved_at, published_at, error FROM {$this->t('import_batch')} ORDER BY id DESC LIMIT %d",
            $limit
        ));
    }

    /** @return list<object> */
    public function logs(int $batchId, ?string $action = null, int $limit = 200, int $offset = 0): array
    {
        $sql = "SELECT entity, source_id, action, message, created_at FROM {$this->t('import_item_log')} WHERE batch_id = %d";
        $args = [$batchId];
        if ($action !== null && $action !== '') {
            $sql .= ' AND action = %s';
            $args[] = $action;
        }
        $sql .= ' ORDER BY id LIMIT %d OFFSET %d';
        array_push($args, $limit, $offset);

        return $this->db->get_results($this->db->prepare($sql, $args));
    }

    public function package(int $batchId): Package
    {
        $dir = $this->batchDir($batchId);
        if (! is_dir($dir)) {
            throw new RuntimeException("Paketdateien zu Import #{$batchId} fehlen.");
        }

        return new Package($dir);
    }

    private function createBatch(Package $package): int
    {
        $manifest = $package->manifest();
        $this->db->insert($this->t('import_batch'), [
            'package_id' => substr((string) ($manifest['package_id'] ?? 'unbekannt'), 0, 64),
            'schema_version' => substr((string) ($manifest['schema_version'] ?? ''), 0, 16),
            'source_commit' => isset($manifest['source']['commit_sha']) ? substr((string) $manifest['source']['commit_sha'], 0, 40) : null,
            'status' => 'uploaded',
            'manifest' => wp_json_encode($manifest),
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql', true),
        ]);
        $batchId = (int) $this->db->insert_id;
        if ($batchId === 0) {
            throw new RuntimeException('Import konnte nicht angelegt werden: '.$this->db->last_error);
        }
        AuditLog::record('import_uploaded', 'import_batch', (string) $batchId, null, ['package_id' => $manifest['package_id'] ?? null]);

        return $batchId;
    }

    /** @return array<string,array<string,int>> Rechte-Status je Entität (für die Vorschau). */
    private function rightsSummary(Package $package): array
    {
        $summary = [];
        foreach (['questions', 'media', 'navigation_tasks', 'praxis_tasks', 'video_modules', 'radio_texts', 'radio_vocabulary', 'radio_practice', 'device_simulation', 'exam_committees', 'exam_guides'] as $entity) {
            foreach ($package->entities($entity) as $record) {
                $status = $record['revision']['rights_status'] ?? $record['rights_status'] ?? 'unknown';
                $summary[$entity][$status] = ($summary[$entity][$status] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /** @param list<array{0:string,1:?string,2:string,3:?string}> $logs */
    private function writeLogs(int $batchId, array $logs): void
    {
        $now = current_time('mysql', true);
        foreach (array_chunk($logs, 200) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as [$entity, $sourceId, $action, $message]) {
                $placeholders[] = '(%d, %s, %s, %s, %s, %s)';
                array_push($values, $batchId, $entity, (string) $sourceId, $action, (string) $message, $now);
            }
            $this->db->query($this->db->prepare(
                "INSERT INTO {$this->t('import_item_log')} (batch_id, entity, source_id, action, message, created_at) VALUES ".implode(',', $placeholders),
                $values
            ));
        }
    }

    /** @param array<string,mixed> $data */
    private function updateBatch(int $batchId, array $data): void
    {
        $this->db->update($this->t('import_batch'), $data, ['id' => $batchId]);
    }

    private function batchDir(int $batchId): string
    {
        return MediaStorage::packagesDir().'/batch-'.$batchId;
    }

    /**
     * Nach einer Neuinstallation beginnen die Import-IDs wieder bei 1; ein noch
     * vorhandener Ordner eines früheren Imports darf nicht mit dem neuen Paket
     * vermischt werden (sonst schlagen Prüfungen mit Fremddateien fehl).
     */
    private function clearBatchDir(int $batchId): void
    {
        if (is_dir($this->batchDir($batchId))) {
            self::removeDir($this->batchDir($batchId));
        }
    }

    private function acquireLock(int $batchId): bool
    {
        if (add_option(self::LOCK, $batchId.':'.time(), '', false)) {
            return true;
        }
        [, $since] = array_pad(explode(':', (string) get_option(self::LOCK)), 2, '0');
        if (time() - (int) $since > 600) {
            delete_option(self::LOCK);

            return add_option(self::LOCK, $batchId.':'.time(), '', false);
        }

        return false;
    }

    /** Sperre nur freigeben, wenn sie noch diesem Lauf gehört (nach Ablauf kann ein anderer sie übernommen haben). */
    private function releaseLock(int $batchId): void
    {
        [$owner] = array_pad(explode(':', (string) get_option(self::LOCK, '')), 1, '');
        if ($owner === (string) $batchId) {
            delete_option(self::LOCK);
        }
    }

    private static function copyDir(string $from, string $to): void
    {
        wp_mkdir_p($to);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $target = $to.'/'.substr($file->getPathname(), strlen($from) + 1);
            $file->isDir() ? wp_mkdir_p($target) : copy($file->getPathname(), $target);
        }
    }

    public static function removeDir(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
