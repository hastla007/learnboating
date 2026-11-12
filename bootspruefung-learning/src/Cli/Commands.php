<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Cli;

use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Plugin;
use WP_CLI;

/**
 * WP-CLI: `wp bootspruefung status|migrate|import|rollback|reconcile|access|shop setup|rights release|chart`.
 */
final class Commands
{
    public static function register(): void
    {
        WP_CLI::add_command('bootspruefung status', [self::class, 'status']);
        WP_CLI::add_command('bootspruefung migrate', [self::class, 'migrate']);
        WP_CLI::add_command('bootspruefung import', [self::class, 'import']);
        WP_CLI::add_command('bootspruefung rollback', [self::class, 'rollback']);
        WP_CLI::add_command('bootspruefung reconcile', [self::class, 'reconcile']);
        WP_CLI::add_command('bootspruefung access', [self::class, 'access']);
        WP_CLI::add_command('bootspruefung shop setup', [self::class, 'shopSetup']);
        WP_CLI::add_command('bootspruefung rights release', [self::class, 'rightsRelease']);
        WP_CLI::add_command('bootspruefung chart', [self::class, 'chart']);
        WP_CLI::add_command('bootspruefung export customer-faqs', [self::class, 'exportCustomerFaqs']);
    }

    /**
     * Zeigt Schema-Version, Tabellen und WooCommerce-Status.
     *
     * [--format=<format>]
     * : table oder json. Standard: table.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function status(array $args, array $assoc): void
    {
        $status = SystemStatus::collect();
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT));

            return;
        }
        WP_CLI::line(sprintf('Plugin %s, Schema %s (erwartet %s), Fremdschlüssel %d', $status['plugin_version'], $status['schema_version'] ?? '–', $status['expected_schema_version'], $status['foreign_keys']));
        $rows = [];
        foreach ($status['tables'] as $table => $info) {
            $rows[] = ['table' => 'bp_'.$table, 'engine' => $info['engine'] ?? 'fehlt', 'rows' => $info['rows'] ?? '–'];
        }
        WP_CLI\Utils\format_items('table', $rows, ['table', 'engine', 'rows']);
    }

    /**
     * Importiert ein Content-Paket, setzt einen Import fort oder verwirft ihn.
     *
     * Ablauf wie im Admin: prüfen, Vorschau (Dry-Run), Freigabe, Import. Pakete
     * sind ZIP-Dateien oder entpackte Ordner; „resume <ID>“ setzt einen
     * freigegebenen oder abgebrochenen Import fort, „discard <ID>“ verwirft ihn.
     *
     * ## OPTIONS
     *
     * <paket>
     * : Pfad zur ZIP-Datei oder zum Paketordner – oder „resume“ bzw. „discard“.
     *
     * [<import-id>]
     * : Nur mit resume/discard: ID des Imports (siehe Admin → Content-Pakete).
     *
     * [--dry-run]
     * : Nur prüfen und Vorschau anzeigen, nichts importieren.
     *
     * [--yes]
     * : Ohne Rückfrage freigeben und importieren (bzw. verwerfen/wiederherstellen).
     *
     * [--note=<text>]
     * : Begründung/Notiz für die Freigabe (Audit-Log).
     *
     * [--restore]
     * : Nur mit discard: danach den aktiven Stand per Rollback-Import wiederherstellen.
     *
     * ## EXAMPLES
     *
     *     wp bootspruefung import build/boatingschool-1.2.3.zip
     *     wp bootspruefung import resume 12
     *     wp bootspruefung import discard 12 --restore
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function import(array $args, array $assoc): void
    {
        $service = Plugin::instance()->importService();
        $path = $args[0];
        if (in_array($path, ['resume', 'discard'], true) && isset($args[1]) && ! file_exists($path)) {
            $path === 'resume' ? self::resume((int) $args[1], $assoc) : self::discard((int) $args[1], $assoc);

            return;
        }
        try {
            $batchId = is_dir($path) ? $service->createFromDirectory($path) : $service->createFromZip($path);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        $batch = $service->batch($batchId);
        if ($batch->status === 'failed') {
            WP_CLI::error("Paket {$batch->package_id} ungültig (Import #{$batchId}):\n".$batch->error);
        }
        WP_CLI::log("Import #{$batchId}: Paket {$batch->package_id} geprüft.");
        try {
            $preview = (array) $service->preview($batchId);
        } catch (\RuntimeException $e) {
            WP_CLI::error(sprintf("Vorschau für Import #%d fehlgeschlagen: %s\nVerwerfen: wp bootspruefung import discard %d", $batchId, $e->getMessage(), $batchId));
        }
        self::previewTable($preview);
        if (isset($assoc['dry-run'])) {
            WP_CLI::success("Dry-Run abgeschlossen, nichts geändert (Import #{$batchId} bleibt zur Freigabe im Admin).");

            return;
        }
        self::confirmOrDiscard($batchId, 'Paket freigeben und importieren?', $assoc);
        self::approveAndRun($batchId, $assoc['note'] ?? 'WP-CLI');
        WP_CLI::success("Paket {$batch->package_id} veröffentlicht (Import #{$batchId}).");
    }

    /** @param array<string,string> $assoc */
    private static function resume(int $batchId, array $assoc): void
    {
        $service = Plugin::instance()->importService();
        try {
            $batch = $service->batch($batchId);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        if ($batch->status === 'importing' && ! $service->isAborted($batch)) {
            WP_CLI::warning(sprintf('Import #%d hatte vor %d Sekunden noch einen Schritt – läuft er evtl. noch woanders? Die Sperre verhindert parallele Schritte.', $batchId, time() - $service->lastActivity($batch)));
        }
        if (! in_array($batch->status, ['approved', 'importing'], true)) {
            WP_CLI::error(sprintf('Import #%d hat Status %s – fortsetzen geht nur bei freigegebenen oder abgebrochenen Importen.', $batchId, $batch->status));
        }
        if ((string) ($batch->error ?? '') !== '') {
            WP_CLI::log("Letzter Fehler:\n".$batch->error);
        }
        WP_CLI::confirm(sprintf('Import #%d (Paket %s) fortsetzen?', $batchId, $batch->package_id), $assoc);
        self::run($batchId);
        WP_CLI::success("Paket {$batch->package_id} veröffentlicht (Import #{$batchId}).");
    }

    /** @param array<string,string> $assoc */
    private static function discard(int $batchId, array $assoc): void
    {
        $service = Plugin::instance()->importService();
        try {
            $batch = $service->batch($batchId);
            WP_CLI::confirm(sprintf('Import #%d (Paket %s, Status %s) verwerfen?', $batchId, $batch->package_id, $batch->status), $assoc);
            $aborted = $batch->status === 'importing';
            $service->discard($batchId);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        WP_CLI::log("Import #{$batchId} verworfen.");
        if (! $aborted) {
            WP_CLI::success('Erledigt.');

            return;
        }
        if (! isset($assoc['restore'])) {
            $active = $service->activeBatchId();
            WP_CLI::warning($active === null
                ? 'Die Inhalte sind ein Mischstand; es gibt keinen veröffentlichten Stand zum Wiederherstellen – bitte ein vollständiges Paket importieren.'
                : sprintf('Die Inhalte sind ein Mischstand aus altem und neuem Paket. Wiederherstellen: wp bootspruefung rollback %d --reason="Wiederherstellung nach abgebrochenem Import"', $active));

            return;
        }
        try {
            $restore = $service->restoreActive((string) ($assoc['note'] ?? 'Wiederherstellung nach abgebrochenem Import (WP-CLI)'));
            if ($restore === null) {
                WP_CLI::error('Kein veröffentlichter Stand zum Wiederherstellen – bitte ein vollständiges Paket importieren.');
            }
            self::previewTable((array) $service->preview($restore));
        } catch (\RuntimeException $e) {
            WP_CLI::error('Wiederherstellen fehlgeschlagen: '.$e->getMessage());
        }
        self::confirmOrDiscard($restore, 'Aktiven Stand wiederherstellen?', $assoc);
        self::approveAndRun($restore, 'Wiederherstellung nach abgebrochenem Import');
        WP_CLI::success("Aktiver Stand als Import #{$restore} wiederhergestellt.");
    }

    /**
     * Setzt den Content auf den Stand eines früher veröffentlichten Imports zurück.
     *
     * <import-id>
     * : ID des veröffentlichten Imports (siehe Admin → Content-Pakete).
     *
     * --reason=<text>
     * : Begründung (Pflicht, Audit-Log).
     *
     * [--yes]
     * : Ohne Rückfrage ausführen.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function rollback(array $args, array $assoc): void
    {
        $service = Plugin::instance()->importService();
        try {
            $batchId = $service->rollback((int) $args[0], (string) ($assoc['reason'] ?? ''));
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        try {
            $preview = (array) $service->preview($batchId);
        } catch (\RuntimeException $e) {
            self::discardQuietly($batchId);
            WP_CLI::error(sprintf('Vorschau für den Rollback-Import #%d fehlgeschlagen (verworfen): %s', $batchId, $e->getMessage()));
        }
        self::previewTable($preview);
        // Abgelehnt: Rollback-Import verwerfen, damit kein verwaister Batch zurückbleibt.
        self::confirmOrDiscard($batchId, 'Auf diesen Stand zurücksetzen?', $assoc);
        self::approveAndRun($batchId, 'Rollback: '.$assoc['reason']);
        WP_CLI::success("Rollback als Import #{$batchId} veröffentlicht.");
    }

    /** @param array<string,array<string,int>> $preview */
    private static function previewTable(array $preview): void
    {
        $rows = [];
        foreach ($preview as $entity => $counts) {
            if ($entity !== '_rights') {
                $rows[] = ['entity' => $entity] + $counts;
            }
        }
        WP_CLI\Utils\format_items('table', $rows, ['entity', 'create', 'update', 'unchanged', 'archive']);
    }

    /**
     * Rückfrage wie WP_CLI::confirm, verwirft bei „nein“ aber den angelegten Import.
     * @param array<string,string> $assoc
     */
    private static function confirmOrDiscard(int $batchId, string $question, array $assoc): void
    {
        if (isset($assoc['yes'])) {
            return;
        }
        fwrite(STDOUT, $question.' [y/n] ');
        $answer = strtolower(trim((string) fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes' && $answer !== 'j' && $answer !== 'ja') {
            self::discardQuietly($batchId);
            WP_CLI::log("Abgebrochen – Import #{$batchId} verworfen.");
            WP_CLI::halt(0);
        }
    }

    private static function discardQuietly(int $batchId): void
    {
        try {
            Plugin::instance()->importService()->discard($batchId);
        } catch (\RuntimeException) {
            // Schon verworfen oder nicht mehr verwerfbar: nichts zu tun.
        }
    }

    private static function approveAndRun(int $batchId, string $note): void
    {
        try {
            Plugin::instance()->importService()->approve($batchId, $note);
        } catch (\RuntimeException $e) {
            WP_CLI::error(sprintf("Freigabe von Import #%d fehlgeschlagen: %s\nVerwerfen: wp bootspruefung import discard %d", $batchId, $e->getMessage(), $batchId));
        }
        self::run($batchId);
    }

    private static function run(int $batchId): void
    {
        try {
            Plugin::instance()->importService()->runAll($batchId, static function (array $step): void {
                WP_CLI::log(sprintf('  %-18s %d/%d', $step['phase'], $step['processed'], $step['total']));
            });
        } catch (\RuntimeException $e) {
            $started = Plugin::instance()->importService()->batch($batchId)->status === 'importing';
            WP_CLI::error(sprintf(
                "Import #%d %s: %s\nFortsetzen (nach Behebung der Ursache): wp bootspruefung import resume %d\n%s: wp bootspruefung import discard %d%s",
                $batchId,
                $started ? 'abgebrochen' : 'nicht gestartet',
                $e->getMessage(),
                $batchId,
                $started ? 'Verwerfen und aktiven Stand wiederherstellen' : 'Verwerfen',
                $batchId,
                $started ? ' --restore' : ''
            ));
        }
    }

    /**
     * Gleicht WooCommerce-Bestellungen mit den Lernzugängen ab (Kap. 7.6).
     * Ohne --apply nur Vorschau. Widerrufe/Aussetzungen werden vor dem
     * Anwenden einzeln aufgeführt und bestätigt – keine stillen Massenwiderrufe.
     *
     * [--apply]
     * : Änderungen anwenden.
     *
     * [--order=<id>]
     * : Nur diese Bestellung.
     *
     * [--since=<datum>]
     * : Nur Bestellungen ab diesem Datum (YYYY-MM-DD).
     *
     * [--yes]
     * : Rückfrage bei Widerrufen überspringen.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function reconcile(array $args, array $assoc): void
    {
        if (! \Bootspruefung\Learning\Commerce\WooCommerce\Integration::active()) {
            WP_CLI::error('WooCommerce ist nicht aktiv.');
        }
        $sync = \Bootspruefung\Learning\Commerce\WooCommerce\Integration::sync();
        $since = isset($assoc['since']) ? gmdate('Y-m-d H:i:s', (int) strtotime($assoc['since'].' 00:00:00 UTC')) : null;
        $plans = isset($assoc['order']) ? array_filter([(int) $assoc['order'] => $sync->plan((int) $assoc['order'])]) : iterator_to_array($sync->reconcile($since), true);
        $rows = [];
        $removals = 0;
        foreach ($plans as $orderId => $plan) {
            // Gruppenlizenzen: Vorschau mit derselben Entscheidung wie beim Anwenden.
            $sync->plan((int) $orderId);
            foreach ($sync->groupChanges() as $g) {
                $rows[] = ['order' => $orderId, 'item' => $g['item_id'], 'scope' => $g['group'] ? 'Gruppe #'.$g['group'] : 'Gruppe', 'change' => $g['change'], 'reason' => $g['reason']];
                $removals += str_ends_with($g['change'], '→ revoked') || str_ends_with($g['change'], '→ suspended') ? 1 : 0;
            }
            foreach ($plan->warnings as $w) {
                $rows[] = ['order' => $orderId, 'item' => '', 'scope' => '', 'change' => 'Warnung: '.$w, 'reason' => $plan->reason];
            }
            foreach ($plan->changes as $c) {
                $rows[] = ['order' => $orderId, 'item' => $c['item_id'] ?? '', 'scope' => ($c['course'] ?? '').'/'.($c['feature'] ?? ''),
                    'change' => $c['type'] === 'create' ? 'neu → '.$c['to'] : ($c['type'] === 'user' ? 'Nutzer '.$c['from_user'].' → '.$c['user_id'] : $c['from'].' → '.$c['to']), 'reason' => $c['reason']];
            }
            $removals += $plan->removals();
        }
        if ($rows === []) {
            WP_CLI::success(sprintf('%d Bestellung(en) geprüft – keine Abweichungen.', count($plans)));

            return;
        }
        WP_CLI\Utils\format_items('table', $rows, ['order', 'item', 'scope', 'change', 'reason']);
        if (! isset($assoc['apply'])) {
            WP_CLI::log('Vorschau. Mit --apply anwenden.');

            return;
        }
        if ($removals > 0) {
            WP_CLI::confirm(sprintf('%d Zugang/Zugänge werden widerrufen oder ausgesetzt. Fortfahren?', $removals), $assoc);
        }
        $applied = 0;
        foreach (array_keys($plans) as $orderId) {
            // Neu berechnen und anwenden (inkl. Gruppenlizenzen); idempotent.
            $sync->plan((int) $orderId);
            $groupChanges = count($sync->groupChanges());
            $applied += count($sync->sync((int) $orderId, 'reconcile')?->changes ?? []) + $groupChanges;
        }
        WP_CLI::success("{$applied} Änderung(en) angewendet.");
    }

    /**
     * Legt Produktkatalog (Preise, Laufzeit, Zuordnung, Geld-zurück-Garantie) und
     * Schnupper-Paket an bzw. gleicht sie ab. Wiederholbar.
     *
     * [--dry-run]
     * : Nur anzeigen, was passieren würde.
     *
     * [--publish]
     * : Produkte veröffentlichen (sonst bleiben neue Produkte Entwurf).
     *
     * [--only=<teil>]
     * : products oder free.
     *
     * [--yes]
     * : Ohne Rückfrage abgleichen, auch wenn bestehende Produkte betroffen sind.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function shopSetup(array $args, array $assoc): void
    {
        $dry = isset($assoc['dry-run']);
        $only = $assoc['only'] ?? '';
        if ($only !== 'free') {
            try {
                $conflicts = \Bootspruefung\Learning\Commerce\ShopCatalog::saleConflicts();
                if ($conflicts !== []) {
                    WP_CLI::warning(sprintf('Regulärer Preis nicht höher als der Angebotspreis – WooCommerce entfernt beim Abgleich den Angebotspreis: %s', implode(', ', array_map(static fn ($sku, $sale) => "{$sku} (Angebot {$sale})", array_keys($conflicts), $conflicts))));
                }
                if (! $dry) {
                    $existing = \Bootspruefung\Learning\Commerce\ShopCatalog::existingCount(\Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts(true));
                    if ($existing > 0) {
                        WP_CLI::confirm(sprintf('%d bestehende Produkte werden mit dem Katalog abgeglichen (Preise aus der Preis-Seite; von Hand geänderte Felder bleiben). Fortfahren?', $existing), $assoc);
                    }
                }
                $rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts($dry, isset($assoc['publish']));
            } catch (\RuntimeException $e) {
                WP_CLI::error($e->getMessage());
            }
            $skippedAny = false;
            foreach ($rows as $i => $row) {
                $skippedAny = $skippedAny || $row['skipped'] !== [];
                $rows[$i]['skipped'] = implode(', ', $row['skipped']);
            }
            WP_CLI\Utils\format_items('table', $rows, ['sku', 'product_id', 'action', 'skipped']);
            if ($skippedAny) {
                WP_CLI::warning('Spalte „skipped“: im Shop von Hand geänderte Felder, die der Abgleich nicht überschrieben hat.');
            }
        }
        if ($only !== 'products') {
            $free = \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier($dry);
            WP_CLI::log(sprintf('Schnupper-Paket: %d Regel(n) %s für %d Kurse.', $free['added'], $dry ? 'würden angelegt' : 'angelegt', $free['courses']));
        }
        WP_CLI::success($dry ? 'Vorschau beendet.' : 'Shop-Katalog abgeglichen.');
    }

    /**
     * Gibt alle Inhalte mit Rechte-Status unknown ohne lokalen Override frei
     * (außer Seekarten). Bewusst gesetzte Overrides (z. B. restricted) und
     * archivierte Inhalte bleiben unverändert.
     *
     * [--reason=<text>]
     * : Begründung (Audit-Log).
     *
     * [--dry-run]
     * : Nur zählen.
     *
     * [--exam-rules]
     * : Zusätzlich alle Prüfungsregelwerke verifizieren/veröffentlichen.
     *
     * [--yes]
     * : Ohne Rückfrage freigeben.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function rightsRelease(array $args, array $assoc): void
    {
        $reason = (string) ($assoc['reason'] ?? \Bootspruefung\Learning\Content\RightsRelease::DEFAULT_REASON);
        $dry = isset($assoc['dry-run']);
        if (! $dry) {
            $pending = \Bootspruefung\Learning\Content\RightsRelease::releaseAll($reason, true);
            $rules = isset($assoc['exam-rules']) ? count(\Bootspruefung\Learning\Content\RightsRelease::releaseExamRules(\Bootspruefung\Learning\Content\RightsRelease::RULES_REASON, true)) : 0;
            if (array_sum($pending) + $rules > 0) {
                WP_CLI::confirm(sprintf('%d Inhalte mit Rechte-Status unknown%s freigeben? Sie werden danach an Lernende ausgeliefert.', array_sum($pending), $rules > 0 ? " und {$rules} Prüfungsregelwerk(e)" : ''), $assoc);
            }
        }
        $counts = \Bootspruefung\Learning\Content\RightsRelease::releaseAll($reason, $dry);
        if (isset($assoc['exam-rules'])) {
            foreach (\Bootspruefung\Learning\Content\RightsRelease::releaseExamRules(\Bootspruefung\Learning\Content\RightsRelease::RULES_REASON, $dry) as $r) {
                WP_CLI::log(sprintf('Regelwerk %s (#%d): %s', $r['course'], $r['id'], implode(', ', $r['changes'])));
            }
        }
        WP_CLI\Utils\format_items('table', array_map(static fn ($t, $n) => ['type' => $t, 'count' => $n], array_keys($counts), $counts), ['type', 'count']);
        WP_CLI::success($dry ? 'Vorschau.' : array_sum($counts).' Inhalte freigegeben.');
    }

    /**
     * Seekarte verwalten: hochladen, Lizenz bestätigen/zurückziehen, Status.
     *
     * <aktion>
     * : status | upload | license | unlicense
     *
     * [<code>]
     * : Kartencode, z. B. D49.
     *
     * [<datei>]
     * : Pfad zur Karte (PDF/PNG/JPG/WebP) für upload.
     *
     * [--note=<text>]
     * : Lizenznachweis für license.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function chart(array $args, array $assoc): void
    {
        [$action, $code, $file] = array_pad($args, 3, '');
        $code = strtoupper($code ?: 'D49');
        $charts = \Bootspruefung\Learning\Gate\Charts::class;
        try {
            if ($action === 'upload') {
                if (! is_file($file)) {
                    WP_CLI::error("Datei nicht gefunden: {$file}");
                }
                require_once ABSPATH.'wp-admin/includes/file.php';
                require_once ABSPATH.'wp-admin/includes/media.php';
                require_once ABSPATH.'wp-admin/includes/image.php';
                $tmp = wp_tempnam($file);
                copy($file, $tmp);
                $id = media_handle_sideload(['name' => wp_basename($file), 'tmp_name' => $tmp], 0, \Bootspruefung\Learning\Gate\Charts::catalog()[$code]['title'] ?? $code);
                if (is_wp_error($id)) {
                    WP_CLI::error($id->get_error_message());
                }
                $charts::attach($code, (int) $id);
            } elseif ($action === 'license' || $action === 'unlicense') {
                $charts::setLicense($code, $action === 'license', (string) ($assoc['note'] ?? ''));
            } elseif ($action !== 'status') {
                WP_CLI::error('Aktion: status, upload, license oder unlicense.');
            }
        } catch (\InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
        }
        $state = $charts::state($code);
        WP_CLI::log(sprintf('%s: Datei %s, Lizenz %s, für Lernende %s.', $code, $state && $state['attachment_id'] ? '#'.$state['attachment_id'] : 'fehlt', $state && $state['licensed'] ? 'bestätigt ('.$state['note'].')' : 'nicht bestätigt', $charts::available($code) ? 'sichtbar' : 'gesperrt'));
    }

    /**
     * Zeigt die Zugänge eines Nutzers.
     *
     * <user>
     * : Benutzer-ID, Login oder E-Mail.
     *
     * @param list<string> $args
     */
    public static function access(array $args): void
    {
        $user = get_user_by(is_numeric($args[0]) ? 'id' : (is_email($args[0]) ? 'email' : 'login'), $args[0]);
        if (! $user) {
            WP_CLI::error('Benutzer nicht gefunden.');
        }
        global $wpdb;
        $rows = array_map(static fn ($r) => ['id' => $r->id, 'course' => $r->course_code, 'feature' => $r->feature_code, 'status' => $r->status, 'valid_until' => $r->valid_until ?? 'dauerhaft', 'source' => $r->source, 'order' => $r->order_id],
            (new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb))->forUser((int) $user->ID));
        WP_CLI\Utils\format_items('table', $rows, ['id', 'course', 'feature', 'status', 'valid_until', 'source', 'order']);
    }

    /** Führt ausstehende Datenbankmigrationen aus. */
    public static function migrate(): void
    {
        if (! Plugin::instance()->migrator()->migrate()) {
            WP_CLI::error('Eine andere Migration läuft gerade.');
        }
        WP_CLI::success('Datenbankschema ist aktuell.');
    }

    /**
     * Exportiert veröffentlichte Kunden-FAQs im JSON-Format des Laravel-Imports.
     *
     *     wp bootspruefung export customer-faqs > mobile-faqs.json
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function exportCustomerFaqs(array $args, array $assoc): void
    {
        $taxonomy = \\Bootspruefung\\Learning\\Faq\\CustomerFaq::TAXONOMY;
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            WP_CLI::error($terms->get_error_message());
        }

        $termById = [];
        foreach ($terms as $term) {
            $termById[(int) $term->term_id] = $term;
        }
        $courseForTerm = [];
        foreach ($termById as $id => $term) {
            $root = $term;
            $seen = [];
            while ((int) $root->parent > 0 && isset($termById[(int) $root->parent]) && ! isset($seen[(int) $root->term_id])) {
                $seen[(int) $root->term_id] = true;
                $root = $termById[(int) $root->parent];
            }
            $courseForTerm[$id] = strtoupper(trim((string) get_term_meta($root->term_id, \\Bootspruefung\\Learning\\Faq\\CustomerFaq::TERM_KEY, true)));
        }

        $categories = [];
        foreach ($termById as $id => $term) {
            $courseCode = $courseForTerm[$id] ?? '';
            if ($courseCode === '') {
                continue;
            }
            $parentId = (int) $term->parent;
            $categories[] = [
                'source_key' => 'wp-term:'.$id,
                'course_code' => $courseCode,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent_source_key' => $parentId > 0 && isset($termById[$parentId]) && ($courseForTerm[$parentId] ?? '') === $courseCode ? 'wp-term:'.$parentId : null,
                'sort_order' => (int) get_term_meta($id, \\Bootspruefung\\Learning\\Faq\\CustomerFaq::TERM_ORDER, true),
            ];
        }

        $posts = get_posts([
            'post_type' => \\Bootspruefung\\Learning\\Faq\\CustomerFaq::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'no_found_rows' => true,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'suppress_filters' => true,
        ]);
        $faqs = [];
        foreach ($posts as $post) {
            $assigned = get_the_terms($post, $taxonomy);
            $categoryKeys = [];
            foreach (is_array($assigned) ? $assigned : [] as $term) {
                if (isset($termById[(int) $term->term_id]) && ($courseForTerm[(int) $term->term_id] ?? '') !== '') {
                    $categoryKeys[] = 'wp-term:'.(int) $term->term_id;
                }
            }
            $categoryKeys = array_values(array_unique($categoryKeys));
            if ($categoryKeys === []) {
                continue;
            }
            $faqs[] = [
                'source_key' => 'wp-post:'.(int) $post->ID,
                'question' => html_entity_decode(wp_strip_all_tags((string) $post->post_title), ENT_QUOTES, 'UTF-8'),
                'answer' => \\Bootspruefung\\Learning\\Faq\\FaqRepository::renderAnswer((string) $post->post_content),
                'status' => 'published',
                'sort_order' => (int) $post->menu_order,
                'category_source_keys' => $categoryKeys,
            ];
        }

        WP_CLI::line((string) wp_json_encode(
            ['categories' => $categories, 'faqs' => $faqs],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }


}
