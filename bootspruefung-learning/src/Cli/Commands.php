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
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function shopSetup(array $args, array $assoc): void
    {
        $dry = isset($assoc['dry-run']);
        $only = $assoc['only'] ?? '';
        if ($only !== 'free') {
            try {
                $rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts($dry, isset($assoc['publish']));
            } catch (\RuntimeException $e) {
                WP_CLI::error($e->getMessage());
            }
            WP_CLI\Utils\format_items('table', $rows, ['sku', 'product_id', 'action']);
        }
        if ($only !== 'products') {
            $free = \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier($dry);
            WP_CLI::log(sprintf('Schnupper-Paket: %d Regel(n) %s für %d Kurse.', $free['added'], $dry ? 'würden angelegt' : 'angelegt', $free['courses']));
        }
        WP_CLI::success($dry ? 'Vorschau beendet.' : 'Shop-Katalog abgeglichen.');
    }

    /**
     * Gibt alle noch nicht freigegebenen Inhalte frei (außer Seekarten).
     *
     * [--reason=<text>]
     * : Begründung (Audit-Log).
     *
     * [--dry-run]
     * : Nur zählen.
     *
     * @param list<string> $args
     * @param array<string,string> $assoc
     */
    public static function rightsRelease(array $args, array $assoc): void
    {
        $reason = (string) ($assoc['reason'] ?? \Bootspruefung\Learning\Content\RightsRelease::DEFAULT_REASON);
        $counts = \Bootspruefung\Learning\Content\RightsRelease::releaseAll($reason, isset($assoc['dry-run']));
        WP_CLI\Utils\format_items('table', array_map(static fn ($t, $n) => ['type' => $t, 'count' => $n], array_keys($counts), $counts), ['type', 'count']);
        WP_CLI::success(isset($assoc['dry-run']) ? 'Vorschau.' : array_sum($counts).' Inhalte freigegeben.');
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
}
