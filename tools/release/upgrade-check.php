<?php

declare(strict_types=1);

/*
 * Update-Prüfung von der Vorversion (CI-Job „Update von der Vorversion“, T94).
 *
 *   WP_DIR=… php tools/release/upgrade-check.php before <stand.json>   mit der alten Plugin-Version
 *   WP_DIR=… php tools/release/upgrade-check.php after  <stand.json>   nach dem Einspielen der neuen Version
 *
 * „before“ hält Plugin-Version, Schema und die Zeilenzahl jeder Plugin-Tabelle fest (es müssen Lerndaten da sein).
 * „after“ lädt WordPress mit der neuen Version ohne erneute Aktivierung – wie nach „Plugin hochladen › Ersetzen“.
 * In der CLI läuft die fällige Migration dabei sofort (Plugin::maybeMigrate). Geprüft wird: neue Version aktiv,
 * Schema aktuell, kein Migrationsfehler, alle Tabellen und Fremdschlüssel da, keine Zeilen verloren.
 * Nur gegen eine Test-Datenbank (guard.php).
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
// Als Konstanten: wp-load.php benutzt selbst globale Variablen wie $file und $plugin.
define('BP_UPGRADE_MODE', (string) ($argv[1] ?? ''));
define('BP_UPGRADE_STATE', (string) ($argv[2] ?? ''));
if (! is_file($wpDir.'/wp-load.php') || ! in_array(BP_UPGRADE_MODE, ['before', 'after'], true) || BP_UPGRADE_STATE === '') {
    fwrite(STDERR, "Aufruf: WP_DIR=… php tools/release/upgrade-check.php before|after <stand.json>\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 2).'/bootspruefung-learning/tests/integration/support/guard.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\Database\Schema;
use Bootspruefung\Learning\Plugin;

global $wpdb;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};

$plugin = 'bootspruefung-learning/bootspruefung-learning.php';
if (! is_plugin_active($plugin) || ! class_exists(Plugin::class)) {
    fwrite(STDERR, "Lern-Plugin nicht aktiv.\n");
    exit(1);
}

/** Zeilenzahl je Plugin-Tabelle (genau, nicht die Schätzung aus information_schema). */
$counts = static function () use ($wpdb): array {
    $rows = [];
    $like = $wpdb->esc_like($wpdb->prefix.'bp_').'%';
    foreach ((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like)) as $table) {
        $rows[substr((string) $table, strlen($wpdb->prefix))] = (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.esc_sql((string) $table).'`');
    }
    ksort($rows);

    return $rows;
};

if (BP_UPGRADE_MODE === 'before') {
    $state = [
        'version' => Plugin::VERSION,
        'schema' => (string) get_option(Migrator::OPTION_VERSION),
        'tables' => $counts(),
    ];
    echo 'Vorversion '.$state['version'].', Schema '.$state['schema'].', '.count($state['tables'])." Tabellen\n";
    $check($state['tables'] !== [], 'Plugin-Tabellen vorhanden');
    $check(array_sum($state['tables']) > 0, 'Daten angelegt ('.array_sum($state['tables']).' Zeilen)');
    $check(($state['tables']['bp_progress'] ?? 0) > 0, 'Lernstand vorhanden (bp_progress)');
    file_put_contents(BP_UPGRADE_STATE, json_encode($state, JSON_PRETTY_PRINT));
} else {
    $before = json_decode((string) file_get_contents(BP_UPGRADE_STATE), true);
    $migrator = new Migrator($wpdb);
    echo 'Update '.($before['version'] ?? '?').' → '.Plugin::VERSION.', Schema '.($before['schema'] ?? '?').' → '.Migrator::SCHEMA_VERSION."\n";
    $check(version_compare(Plugin::VERSION, (string) ($before['version'] ?? '0'), '>'), 'neue Plugin-Version aktiv');
    $check(get_option(Migrator::OPTION_VERSION) === Migrator::SCHEMA_VERSION && ! $migrator->needsMigration(), 'Migration gelaufen: Schema '.get_option(Migrator::OPTION_VERSION));
    $check(get_option(Plugin::OPTION_MIGRATION_FAILED) === false, 'kein Migrationsfehler gemerkt');
    $missing = array_keys(array_filter($migrator->tableStatus(), static fn ($t) => ! $t['exists']));
    $check($missing === [], 'alle Tabellen des neuen Schemas da'.($missing ? ' – fehlen: '.implode(', ', $missing) : ''));
    $check($migrator->foreignKeyCount() === count(Schema::foreignKeys()), 'Fremdschlüssel vollständig ('.$migrator->foreignKeyCount().')');
    $now = $counts();
    $lost = [];
    foreach ((array) ($before['tables'] ?? []) as $table => $rows) {
        if (($now[$table] ?? -1) < $rows) {
            $lost[] = $table.' '.$rows.' → '.($now[$table] ?? 'fehlt');
        }
    }
    $check($lost === [], 'keine Daten verloren'.($lost ? ': '.implode(', ', $lost) : ' ('.array_sum($now).' Zeilen)'));
}

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
