<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Cli;

use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Plugin;
use WP_CLI;

/**
 * WP-CLI: `wp bootspruefung status|migrate`. Import und Abgleich
 * (`import`, `reconcile`) folgen mit den Schritten 4 und 9.
 */
final class Commands
{
    public static function register(): void
    {
        WP_CLI::add_command('bootspruefung status', [self::class, 'status']);
        WP_CLI::add_command('bootspruefung migrate', [self::class, 'migrate']);
        WP_CLI::add_command('bootspruefung import', [self::class, 'import']);
        WP_CLI::add_command('bootspruefung rollback', [self::class, 'rollback']);
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
     * Importiert ein Content-Paket (ZIP oder entpackter Ordner).
     *
     * Ablauf wie im Admin: prüfen, Vorschau (Dry-Run), Freigabe, Import.
     *
     * <paket>
     * : Pfad zur ZIP-Datei oder zum Paketordner.
     *
     * [--dry-run]
     * : Nur prüfen und Vorschau anzeigen, nichts importieren.
     *
     * [--yes]
     * : Ohne Rückfrage freigeben und importieren.
     *
     * [--note=<text>]
     * : Begründung/Notiz für die Freigabe (Audit-Log).
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function import(array $args, array $assoc): void
    {
        $service = Plugin::instance()->importService();
        $path = $args[0];
        try {
            $batchId = is_dir($path) ? $service->createFromDirectory($path) : $service->createFromZip($path);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        $batch = $service->batch($batchId);
        if ($batch->status === 'failed') {
            WP_CLI::error("Paket {$batch->package_id} ungültig:\n".$batch->error);
        }
        WP_CLI::log("Import #{$batchId}: Paket {$batch->package_id} geprüft.");
        $preview = $service->preview($batchId);
        $rows = [];
        foreach ($preview as $entity => $counts) {
            if ($entity !== '_rights') {
                $rows[] = ['entity' => $entity] + $counts;
            }
        }
        WP_CLI\Utils\format_items('table', $rows, ['entity', 'create', 'update', 'unchanged', 'archive']);
        if (isset($assoc['dry-run'])) {
            WP_CLI::success('Dry-Run abgeschlossen, nichts geändert.');

            return;
        }
        WP_CLI::confirm('Paket freigeben und importieren?', $assoc);
        $service->approve($batchId, $assoc['note'] ?? 'WP-CLI');
        $service->runAll($batchId, static function (array $step): void {
            WP_CLI::log(sprintf('  %-18s %d/%d', $step['phase'], $step['processed'], $step['total']));
        });
        WP_CLI::success("Paket {$batch->package_id} veröffentlicht.");
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
            $preview = $service->preview($batchId);
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }
        $rows = [];
        foreach ($preview as $entity => $counts) {
            if ($entity !== '_rights') {
                $rows[] = ['entity' => $entity] + $counts;
            }
        }
        WP_CLI\Utils\format_items('table', $rows, ['entity', 'create', 'update', 'unchanged', 'archive']);
        WP_CLI::confirm('Auf diesen Stand zurücksetzen?', $assoc);
        $service->approve($batchId, 'Rollback: '.$assoc['reason']);
        $service->runAll($batchId);
        WP_CLI::success("Rollback als Import #{$batchId} veröffentlicht.");
    }

    /** Führt ausstehende Datenbankmigrationen aus. */
    public static function migrate(): void
    {
        if (! Plugin::instance()->migrator()->migrate()) {
            WP_CLI::error('Eine andere Migration läuft gerade.');
        }
        WP_CLI::success('Datenbankschema ist aktuell.');
    }
}
