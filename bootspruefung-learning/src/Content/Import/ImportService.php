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
 *   (verworfen: discarded; abgebrochen = importing mit Fehler oder ohne Schritt seit STALE_AFTER)
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

    /** Ohne Schrittaktivität seit so vielen Sekunden gilt ein laufender Import als hängengeblieben (Fatal/Timeout). */
    public const STALE_AFTER = 600;

    /** Höchstens so viele Bytes Mediendateien je Teilschritt (große Dateien sonst Timeout). */
    public const MEDIA_STEP_BYTES = 64 * 1024 * 1024;

    /** Zeitbudget je Vorschau-Teilschritt im Admin (Sekunden). */
    public const PREVIEW_STEP_SECONDS = 20.0;

    public function __construct(private readonly wpdb $db, private readonly string $schemaDir)
    {
    }

    private function t(string $name): string
    {
        return $this->db->prefix.'bp_'.$name;
    }

    /** Mehr Zeit und Speicher für Prüfung/Vorschau großer Pakete (synchroner Request). */
    private static function raiseLimits(): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- auf manchen Hosts gesperrt
        }
    }

    /** Legt einen Batch aus einem hochgeladenen ZIP an und prüft ihn sofort. */
    public function createFromZip(string $zipPath): int
    {
        self::raiseLimits();
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
        if (! @rename($tmp, $this->batchDir($batchId))) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            // z. B. anderes Dateisystem: kopieren und Quelle entfernen.
            self::copyDir($tmp, $this->batchDir($batchId));
            self::removeDir($tmp);
            if (! is_file($this->batchDir($batchId).'/manifest.json')) {
                $this->updateBatch($batchId, ['status' => 'failed', 'error' => __('Paketdateien konnten nicht abgelegt werden (Schreibrechte im Upload-Ordner prüfen).', 'bootspruefung-learning')]);
                throw new RuntimeException(sprintf(__('Paketdateien zu Import #%d konnten nicht abgelegt werden (Schreibrechte im Upload-Ordner prüfen).', 'bootspruefung-learning'), $batchId));
            }
        }
        $this->validate($batchId);

        return $batchId;
    }

    /** Legt einen Batch aus einem entpackten Paketordner an (WP-CLI). */
    public function createFromDirectory(string $dir): int
    {
        self::raiseLimits();
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
     * Auf das aktive Paket ist das nur möglich, wenn danach ein Import abgebrochen ist
     * (Mischstand aus altem und neuem Inhalt wiederherstellen).
     */
    public function rollback(int $targetBatchId, string $reason): int
    {
        $target = $this->batch($targetBatchId);
        if ($target->status !== 'published') {
            throw new RuntimeException(__('Zurücksetzen ist nur auf einen veröffentlichten Stand möglich.', 'bootspruefung-learning'));
        }
        if ($target->package_id === get_option('bp_learning_active_package') && $this->abortedImport() === null) {
            throw new RuntimeException(__('Dieses Paket ist bereits aktiv.', 'bootspruefung-learning'));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(__('Für das Zurücksetzen ist eine Begründung erforderlich.', 'bootspruefung-learning'));
        }
        $dir = $this->batchDir($targetBatchId);
        if (! is_dir($dir)) {
            throw new RuntimeException(sprintf(__('Die Paketdateien von Import #%d sind nicht mehr vorhanden.', 'bootspruefung-learning'), $targetBatchId));
        }
        $batchId = $this->createFromDirectory($dir);
        $stats = $this->stats($this->batch($batchId));
        $stats['rollback_of'] = $targetBatchId;
        $stats['rollback_reason'] = $reason;
        $this->updateBatch($batchId, ['stats' => wp_json_encode($stats)]);
        AuditLog::record('import_rollback_requested', 'import_batch', (string) $batchId, $reason, ['target_batch' => $targetBatchId, 'package_id' => $target->package_id]);

        return $batchId;
    }

    /** Zuletzt veröffentlichter Import des aktiven Pakets (Ziel für die Wiederherstellung), sonst null. */
    public function activeBatchId(): ?int
    {
        $active = (string) get_option('bp_learning_active_package', '');
        if ($active === '') {
            return null;
        }
        $id = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t('import_batch')} WHERE status = 'published' AND package_id = %s ORDER BY published_at DESC, id DESC LIMIT 1", $active));

        return $id === null ? null : (int) $id;
    }

    /**
     * Abgebrochener (oder verworfener abgebrochener) Import nach dem letzten veröffentlichten:
     * Inhalte können dann ein Mischstand aus altem und neuem Paket sein.
     */
    public function abortedImport(): ?int
    {
        $last = (int) $this->db->get_var("SELECT id FROM {$this->t('import_batch')} WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT 1");
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('import_batch')} WHERE id > %d AND status IN ('importing','discarded') ORDER BY id DESC", $last));
        foreach ($rows as $row) {
            if ($row->status === 'importing' ? $this->isAborted($row) : isset($this->stats($row)['aborted_at'])) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /** Letzte Schrittaktivität (Unix-Zeit) eines Imports. */
    public function lastActivity(object $batch): int
    {
        $stats = $this->stats($batch);
        if (isset($stats['heartbeat'])) {
            return (int) $stats['heartbeat'];
        }
        $ts = $batch->approved_at ?: $batch->created_at;

        return $ts ? (int) strtotime($ts.' UTC') : 0;
    }

    /** Läuft angeblich noch, aber seit STALE_AFTER Sekunden ohne Schritt (Fatal, Timeout, Tab geschlossen). */
    public function isStale(object $batch): bool
    {
        return $batch->status === 'importing' && (string) ($batch->error ?? '') === '' && time() - $this->lastActivity($batch) > self::STALE_AFTER;
    }

    /** Abgebrochen: mit Fehler stehengeblieben oder hängengeblieben. Lässt sich fortsetzen oder verwerfen. */
    public function isAborted(object $batch): bool
    {
        return $batch->status === 'importing' && ((string) ($batch->error ?? '') !== '' || $this->isStale($batch));
    }

    /**
     * Ein anderer Import, der nach diesem bzw. nach dessen Vorschau veröffentlicht wurde.
     * Dann ist Vorschau/Freigabe veraltet: Fortsetzen würde den neueren Stand überschreiben.
     */
    public function supersededBy(object $batch): ?int
    {
        $stats = $this->stats($batch);
        $since = (string) ($stats['preview_at'] ?? $batch->created_at);
        $id = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t('import_batch')} WHERE status = 'published' AND id <> %d AND (id > %d OR published_at > %s) ORDER BY id DESC LIMIT 1",
            (int) $batch->id,
            (int) $batch->id,
            $since
        ));

        return $id === null ? null : (int) $id;
    }

    private function assertNotSuperseded(object $batch): void
    {
        $newer = $this->supersededBy($batch);
        if ($newer !== null) {
            throw new RuntimeException(sprintf(__('Seit diesem Import bzw. seiner Vorschau wurde Import #%d veröffentlicht. Vorschau und Freigabe sind damit ungültig – bitte diesen Import verwerfen und das Paket neu hochladen (oder „Zurücksetzen“ verwenden).', 'bootspruefung-learning'), $newer));
        }
    }

    public function validate(int $batchId): bool
    {
        self::raiseLimits();
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
        // Abgelehnte Mediendateien (Dateityp nicht erlaubt) einzeln im Import-Protokoll.
        $this->db->delete($this->t('import_item_log'), ['batch_id' => $batchId, 'action' => 'rejected']);
        $rejected = $package->mediaTypeErrors();
        $this->writeLogs($batchId, array_map(static fn (string $path, string $message): array => ['media', $path, 'rejected', $message], array_keys($rejected), array_values($rejected)));
        AuditLog::record($errors === [] ? 'import_validated' : 'import_validation_failed', 'import_batch', (string) $batchId, null, ['errors' => count($errors)]);

        return $errors === [];
    }

    /**
     * Dry-Run: ermittelt je Entität, was der Import anlegen, ändern, unverändert
     * lassen oder archivieren würde, ohne zu schreiben.
     *
     * Mit $budget (Sekunden) in Teilschritten: Ist die Zeit um, wird der Stand gemerkt
     * und null zurückgegeben; der nächste Aufruf macht dort weiter.
     *
     * @return array<string,array<string,int>>|null
     */
    public function preview(int $batchId, ?float $budget = null): ?array
    {
        self::raiseLimits();
        $started = microtime(true);
        $batch = $this->batch($batchId);
        if (! in_array($batch->status, ['validated', 'previewed'], true)) {
            throw new RuntimeException(__('Vorschau ist nur für geprüfte Pakete möglich.', 'bootspruefung-learning'));
        }
        $stats = $this->stats($batch);
        $progress = $stats['preview_progress'] ?? null;
        if (! is_array($progress)) {
            // Neue Vorschau: alte Ergebnisse gelten nicht mehr (Freigabe erst nach vollständiger Vorschau).
            $progress = ['entity' => 0, 'offset' => 0, 'summary' => [], 'started_at' => current_time('mysql', true)];
            unset($stats['preview'], $stats['preview_at']);
            if ($batch->status === 'previewed') {
                $batch->status = 'validated';
            }
        }
        $package = $this->package($batchId);
        $importer = new EntityImporter($this->db, $package, $batch->package_id, true);
        $summary = $progress['summary'];
        $entities = Package::ENTITIES;
        $done = true;
        for ($e = (int) $progress['entity']; $e < count($entities); $e++) {
            $entity = $entities[$e];
            $summary[$entity] ??= ['create' => 0, 'update' => 0, 'unchanged' => 0, 'archive' => 0];
            $records = $package->entities($entity);
            for ($i = $e === (int) $progress['entity'] ? (int) $progress['offset'] : 0, $n = count($records); $i < $n; $i++) {
                $summary[$entity][$importer->import($entity, $records[$i])]++;
                if ($budget !== null && ($i % 50) === 49 && microtime(true) - $started > $budget) {
                    $progress = ['entity' => $e, 'offset' => $i + 1, 'summary' => $summary, 'started_at' => $progress['started_at']];
                    $done = false;
                    break 2;
                }
            }
        }
        if (! $done) {
            $stats['preview_progress'] = $progress;
            $this->updateBatch($batchId, ['status' => $batch->status, 'stats' => wp_json_encode($stats)]);

            return null;
        }
        foreach ($importer->archiveMissing() as $entity => $ids) {
            $summary[$entity] ??= ['create' => 0, 'update' => 0, 'unchanged' => 0, 'archive' => 0];
            $summary[$entity]['archive'] = count($ids);
        }
        $summary['_rights'] = $this->rightsSummary($package);
        unset($stats['preview_progress']);
        $stats['preview'] = $summary;
        // Vergleichsstand der Vorschau: wird danach ein anderer Import veröffentlicht, ist sie veraltet.
        $stats['preview_at'] = (string) $progress['started_at'];
        $this->updateBatch($batchId, ['status' => 'previewed', 'stats' => wp_json_encode($stats)]);

        return $summary;
    }

    /** Anteil der Vorschau in Prozent, solange sie in Teilschritten läuft (sonst null). */
    public function previewProgress(object $batch): ?int
    {
        $progress = $this->stats($batch)['preview_progress'] ?? null;
        if (! is_array($progress)) {
            return null;
        }

        return (int) floor(100 * (int) $progress['entity'] / max(1, count(Package::ENTITIES)));
    }

    public function approve(int $batchId, ?string $note = null): void
    {
        $batch = $this->batch($batchId);
        if ($batch->status !== 'previewed') {
            throw new RuntimeException(__('Freigabe erst nach der Vorschau (Dry-Run) möglich.', 'bootspruefung-learning'));
        }
        $this->assertNotSuperseded($batch);
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
        // Abgebrochene oder hängengebliebene Importe lassen sich verwerfen, laufende nicht.
        if ($batch->status === 'published' || ($batch->status === 'importing' && ! $this->isAborted($batch))) {
            throw new RuntimeException(__('Laufende oder veröffentlichte Importe können nicht verworfen werden.', 'bootspruefung-learning'));
        }
        $update = ['status' => 'discarded'];
        if ($batch->status === 'importing') {
            // Merken: Inhalte sind ein Mischstand, bis ein vollständiger Import veröffentlicht ist.
            $stats = $this->stats($batch);
            $stats['aborted_at'] = current_time('mysql', true);
            $update['stats'] = wp_json_encode($stats);
            $this->releaseLock($batchId);
        }
        $this->updateBatch($batchId, $update);
        if (is_dir($this->batchDir($batchId))) {
            self::removeDir($this->batchDir($batchId));
        }
        AuditLog::record('import_discarded', 'import_batch', (string) $batchId, null, ['aborted' => $batch->status === 'importing']);
    }

    /**
     * Nach einem abgebrochenen Import: legt einen Rollback-Import auf das aktive Paket an
     * (Prüfung, Vorschau und Freigabe wie üblich). null, wenn kein veröffentlichter Stand existiert.
     */
    public function restoreActive(string $reason): ?int
    {
        $target = $this->activeBatchId();
        if ($target === null) {
            return null;
        }

        return $this->rollback($target, $reason);
    }

    /**
     * Verarbeitet bis zu $limit Datensätze der aktuellen Phase (Medien zusätzlich bis
     * MEDIA_STEP_BYTES).
     * @return array{done:bool,phase:string,processed:int,total:int}
     */
    public function runStep(int $batchId, int $limit = 200): array
    {
        $batch = $this->batch($batchId);
        if (! in_array($batch->status, ['approved', 'importing'], true)) {
            throw new RuntimeException(__('Import ist nicht freigegeben.', 'bootspruefung-learning'));
        }
        // Inzwischen wurde ein anderer Import veröffentlicht: nicht (weiter) importieren, das würde neuere Inhalte überschreiben.
        $this->assertNotSuperseded($batch);
        // Nur ein Import zur Zeit: ein anderer, noch laufender Import hat Vorrang (abgebrochene/hängengebliebene blockieren nicht).
        $others = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t('import_batch')} WHERE status = 'importing' AND id <> %d", $batchId));
        foreach ($others as $other) {
            if (! $this->isAborted($other)) {
                throw new RuntimeException(sprintf(__('Import #%d läuft noch – bitte erst abschließen.', 'bootspruefung-learning'), (int) $other->id));
            }
        }
        if (! $this->acquireLock($batchId)) {
            throw new RuntimeException(__('Ein anderer Import läuft gerade.', 'bootspruefung-learning'));
        }

        try {
            return $this->step($batch, $limit);
        } catch (\Throwable $e) {
            // Fehler außerhalb der Datensatz-Transaktion (z. B. fehlende Paketdateien): festhalten,
            // damit der Import als abgebrochen gilt und fortgesetzt oder verworfen werden kann.
            $current = $this->batch($batchId);
            if ($current->status === 'importing' && (string) ($current->error ?? '') === '') {
                $this->updateBatch($batchId, ['error' => $e->getMessage()]);
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        } finally {
            $this->releaseLock($batchId);
        }
    }

    /** @return array{done:bool,phase:string,processed:int,total:int} */
    private function step(object $batch, int $limit): array
    {
        $batchId = (int) $batch->id;
        $stats = $this->stats($batch);
        $cursor = $stats['cursor'] ?? ['phase' => array_key_first(self::PHASES), 'offset' => 0];
        $stats['actions'] ??= [];
        // Lebenszeichen vor der Arbeit: bricht der Request ab (Fatal/Timeout), gilt der Import nach STALE_AFTER als hängengeblieben.
        $stats['heartbeat'] = time();
        $update = ['stats' => wp_json_encode($stats)];
        if ($batch->status === 'approved') {
            $update['status'] = 'importing';
        }
        $this->updateBatch($batchId, $update);
        if ($batch->status === 'approved') {
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
                if ($phase === 'media') {
                    $records = self::limitBytes($records, self::MEDIA_STEP_BYTES);
                }
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
            throw new RuntimeException(sprintf(__('Import in Phase %1$s abgebrochen: %2$s', 'bootspruefung-learning'), $phase, $e->getMessage()), 0, $e);
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
        $stats['heartbeat'] = time();
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
    }

    /**
     * Schneidet die Datensätze so zu, dass ihre Dateien zusammen höchstens $maxBytes groß
     * sind (mindestens ein Datensatz, damit es immer vorangeht).
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    public static function limitBytes(array $records, int $maxBytes): array
    {
        $out = [];
        $bytes = 0;
        foreach ($records as $record) {
            $size = (int) ($record['size'] ?? 0);
            if ($out !== [] && $bytes + $size > $maxBytes) {
                break;
            }
            $out[] = $record;
            $bytes += $size;
        }

        return $out;
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
            throw new RuntimeException(sprintf(__('Import #%d nicht gefunden.', 'bootspruefung-learning'), $batchId));
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
            throw new RuntimeException(sprintf(__('Paketdateien zu Import #%d fehlen.', 'bootspruefung-learning'), $batchId));
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
            throw new RuntimeException(sprintf(__('Import konnte nicht angelegt werden: %s', 'bootspruefung-learning'), $this->db->last_error));
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
