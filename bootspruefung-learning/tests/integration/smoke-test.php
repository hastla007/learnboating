<?php

declare(strict_types=1);

/*
 * Rauchtest gegen eine echte WordPress-Installation mit MySQL/MariaDB.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/smoke-test.php
 *
 * Voraussetzung: WP_DIR enthält wp-config.php mit einer leeren Testdatenbank;
 * das Plugin liegt (z. B. als Symlink) unter wp-content/plugins/bootspruefung-learning.
 * Der Test installiert WordPress bei Bedarf, aktiviert das Plugin zweimal
 * (Idempotenz), prüft Tabellen, InnoDB, Fremdschlüssel, Capabilities, REST,
 * Datenschutz-Löschung und die Deinstallation mit und ohne Datenlöschung.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if ($wpDir === '' || ! is_file($wpDir.'/wp-config.php')) {
    fwrite(STDERR, "WP_DIR mit wp-config.php angeben.\n");
    exit(2);
}

define('WP_INSTALLING', ! is_file($wpDir.'/.bp-installed'));
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};

if (! is_blog_installed()) {
    wp_install('Bootsprüfung Test', 'admin', 'admin@example.invalid', false, '', wp_generate_password(24));
    touch($wpDir.'/.bp-installed');
    echo "WordPress installiert.\n";
}

$plugin = 'bootspruefung-learning/bootspruefung-learning.php';
global $wpdb;

echo "Aktivierung\n";
$result = activate_plugin($plugin);
$check(! is_wp_error($result), 'activate_plugin ohne Fehler'.(is_wp_error($result) ? ': '.$result->get_error_message() : ''));
$check(is_plugin_active($plugin), 'Plugin aktiv');

$migrator = \Bootspruefung\Learning\Plugin::instance()->migrator();
$status = $migrator->tableStatus();
$missing = array_keys(array_filter($status, static fn ($t) => ! $t['exists']));
$check($missing === [], count($status).' Tabellen angelegt'.($missing ? ' – fehlen: '.implode(', ', $missing) : ''));
$notInno = array_keys(array_filter($status, static fn ($t) => $t['exists'] && $t['engine'] !== 'InnoDB'));
$check($notInno === [], 'alle Tabellen InnoDB');
$expectedFks = count(\Bootspruefung\Learning\Infrastructure\Database\Schema::foreignKeys());
$check($migrator->foreignKeyCount() === $expectedFks, "Fremdschlüssel {$migrator->foreignKeyCount()}/{$expectedFks}");
$check($migrator->installedVersion() === \Bootspruefung\Learning\Infrastructure\Database\Migrator::SCHEMA_VERSION, 'Schema-Version gespeichert');

$admin = get_role('administrator');
foreach (\Bootspruefung\Learning\Security\Capabilities::ALL as $cap) {
    $check($admin->has_cap($cap), "Administrator hat {$cap}");
}

echo "Idempotenz\n";
deactivate_plugins($plugin);
$check(! is_plugin_active($plugin), 'Deaktivierung');
$check($migrator->tableStatus()['question']['exists'], 'Tabellen bleiben nach Deaktivierung erhalten');
$result = activate_plugin($plugin);
$check(! is_wp_error($result), 'erneute Aktivierung ohne Fehler');
$check($migrator->migrate(), 'zweiter Migrationslauf');
$check($migrator->foreignKeyCount() === $expectedFks, 'keine doppelten Fremdschlüssel');
$delta = dbDelta(array_values($migrator->schema()->definitions()), false);
$check($delta === [], 'dbDelta meldet keine ausstehenden Änderungen'.($delta ? ': '.implode('; ', array_slice($delta, 0, 5)) : ''));

echo "Fremdschlüssel greifen\n";
$p = $wpdb->prefix.'bp_';
$wpdb->suppress_errors(true);
$ok = $wpdb->insert($p.'answer', ['revision_id' => 999999, 'answer_key' => 'A', 'answer_text' => 'x']);
$check($ok === false, 'Antwort ohne Revision wird abgewiesen');
$wpdb->suppress_errors(false);

echo "REST\n";
wp_set_current_user(1);
do_action('rest_api_init');
$response = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/status'));
$check($response->get_status() === 200, 'GET /bootspruefung/v1/status als Admin → 200');
$check(($response->get_data()['schema_current'] ?? false) === true, 'Status meldet aktuelles Schema');
wp_set_current_user(0);
$response = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/status'));
$check(in_array($response->get_status(), [401, 403], true), 'ohne Anmeldung → '.$response->get_status());

echo "Datenschutz\n";
$userId = wp_insert_user(['user_login' => 'lerner'.wp_rand(), 'user_email' => 'lerner'.wp_rand().'@example.invalid', 'user_pass' => wp_generate_password()]);
$wpdb->insert($p.'course', ['source_id' => 'T', 'code' => 'T-'.wp_rand(), 'name' => 'Test']);
$wpdb->insert($p.'question', ['source_id' => 'Q-'.wp_rand()]);
$questionId = (int) $wpdb->insert_id;
$wpdb->insert($p.'progress', ['user_id' => $userId, 'question_id' => $questionId]);
$wpdb->insert($p.'favorite', ['user_id' => $userId, 'question_id' => $questionId]);
$email = get_userdata($userId)->user_email;
// Export ist seitenweise (je Datenquelle eine Seite) – alle Seiten einsammeln.
$exported = [];
$pageNo = 1;
do {
    $export = \Bootspruefung\Learning\Privacy\PersonalData::export($email, $pageNo++);
    $exported = array_merge($exported, $export['data']);
} while (! $export['done'] && $pageNo < 100);
$groups = array_unique(array_column($exported, 'group_id'));
$check(in_array('bootspruefung-learning-progress', $groups, true) && in_array('bootspruefung-learning-favorite', $groups, true), 'Export liefert Daten');
$erase = \Bootspruefung\Learning\Privacy\PersonalData::erase($email);
$check($erase['items_removed'] === true, 'Löschung entfernt Lerndaten');
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}progress WHERE user_id = %d", $userId))
    + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}favorite WHERE user_id = %d", $userId));
$check($left === 0, 'keine Lerndaten des Nutzers übrig');
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'personal_data_erased'") > 0, 'Löschvermerk im Audit-Log');

echo "Deinstallation\n";
$runUninstall = static function () use ($plugin): void {
    deactivate_plugins($plugin);
    // uninstall_plugin() lädt uninstall.php in einem eigenen Scope.
    uninstall_plugin($plugin);
};
$runUninstall();
$check($migrator->tableStatus()['question']['exists'], 'Standard: Tabellen bleiben bei Deinstallation');
activate_plugin($plugin);
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, ['delete_data_on_uninstall' => true]);
// uninstall.php wird per include_once geladen; ein zweiter Lauf braucht einen eigenen Prozess.
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/uninstall-phase.php'), $code);
$check($code === 0, 'Deinstallation mit Datenlöschung (eigener Prozess)');
$remaining = array_keys(array_filter($migrator->tableStatus(), static fn ($t) => $t['exists']));
$check($remaining === [], 'mit Einstellung: alle Tabellen entfernt'.($remaining ? ' – übrig: '.implode(', ', $remaining) : ''));
wp_cache_flush();
$roles = new WP_Roles();
$check(! $roles->get_role('administrator')->has_cap(\Bootspruefung\Learning\Security\Capabilities::MANAGE_CONTENT), 'Capabilities entfernt');
$check($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", \Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION)) === '0', 'Schema-Version entfernt');

// Für weitere Läufe wieder aktivieren.
activate_plugin($plugin);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
