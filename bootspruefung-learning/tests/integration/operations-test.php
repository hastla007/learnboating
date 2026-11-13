<?php

declare(strict_types=1);

/*
 * Integrationstest Betrieb: atomare Sperren, robuster Wartungslauf, Migration nur außerhalb von
 * Besucheranfragen, Aufbewahrungsfristen für Paketkopien und Import-Protokoll, Inhalts-Cache,
 * Abfragen der Kursübersicht und Versionsvertrag für Theme und Add-on (Expertenrunde 3: ARC-02 bis
 * ARC-10, ARC-17). Läuft mit und ohne WooCommerce.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/operations-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse veröffentlicht).
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';

use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Import\Retention;
use Bootspruefung\Learning\Content\Repositories\ContentCache;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Infrastructure\Cron;
use Bootspruefung\Learning\Infrastructure\Lock;
use Bootspruefung\Learning\Infrastructure\RequestMemo;
use Bootspruefung\Learning\Plugin;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';
wp_set_current_user(1);
$content = new ContentRepository();
$courses = $content->courses();
if ($courses === []) {
    fwrite(STDERR, "Keine veröffentlichten Kurse – zuerst content-flow-test.php ausführen.\n");
    exit(2);
}
$user = static function (string $login): int {
    $u = get_user_by('login', $login);

    return $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'subscriber']);
};

echo "Atomare Sperre (ARC-02)\n";
$name = 'bp_ops_test_lock';
$wpdb->delete($wpdb->options, ['option_name' => $name]);
wp_cache_delete($name, 'options');
// Nachstellung aus der Prüfung: Lauf B hat die Option als „fehlt“ im Cache, Lauf A legt sie inzwischen an.
get_option($name);
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, 'A|'.time()));
$check(! Lock::acquire($name, 300, 'B') && Lock::holder($name) === 'A', 'zweiter Lauf bekommt die Sperre nicht (veralteter notoptions-Cache)');
Lock::release($name, 'B');
$check(Lock::holder($name) === 'A', 'fremder Lauf kann die Sperre nicht freigeben');
$wpdb->update($wpdb->options, ['option_value' => 'A|'.(time() - 400)], ['option_name' => $name]);
$check(Lock::acquire($name, 300, 'C') && Lock::holder($name) === 'C', 'hängengebliebene Sperre wird nach Ablauf übernommen');
$check(! Lock::acquire($name, 300, 'D'), 'übernommene Sperre gilt wieder');
Lock::release($name, 'C');
$check(Lock::holder($name) === null && Lock::acquire($name, 300), 'freigegeben: neuer Lauf bekommt sie');
Lock::release($name);
$check(Lock::holder($name) === null, 'eigene Sperre ohne Besitzerangabe freigegeben');
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, '7:'.(time() - 4000)));
$check(Lock::acquire($name, 600, '8'), 'altes Format „Import-ID:Zeit“ wird nach Ablauf übernommen');
Lock::release($name, '8');

echo "Wartungslauf robust (ARC-04)\n";
delete_option(Cron::OPTION_ERRORS);
$ran = [];
$okFirst = Cron::run('ops_test_fail', static function (): void {
    throw new RuntimeException('kaputt');
});
$okSecond = Cron::run('ops_test_ok', static function () use (&$ran): void {
    $ran[] = 'ok';
});
$check(! $okFirst && $okSecond && $ran === ['ok'], 'Fehler einer Teilaufgabe bricht die nächste nicht ab');
$check((Cron::errors()[0]['task'] ?? '') === 'ops_test_fail' && str_contains(Cron::errors()[0]['message'] ?? '', 'kaputt'), 'Fehler protokolliert');

$student = $user('ops_test_student');
$course = $courses[0];
$question = $wpdb->get_row("SELECT id, current_revision_id FROM {$p}question WHERE current_revision_id IS NOT NULL LIMIT 1");
$past = gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS);
$session = static function (string $snapshot) use ($wpdb, $p, $student, $course, $past): int {
    $wpdb->insert($p.'exam_session', ['user_id' => $student, 'course_id' => (int) $course->id, 'mode' => 'random', 'rule_snapshot' => $snapshot, 'status' => 'running',
        'start_token' => wp_generate_uuid4(), 'started_at' => $past, 'deadline_at' => $past]);

    return (int) $wpdb->insert_id;
};
// 1) Regel-Snapshot ohne „passing“ (vorher: TypeError in partResults, ganzer Cron-Lauf brach ab).
$emptySnapshot = $session('{}');
// 2) Kaputte gespeicherte Antwort: Auswertung wirft weiterhin – die Sitzung wird markiert, der Lauf geht weiter.
$broken = $session('{}');
$wpdb->insert($p.'exam_session_question', ['session_id' => $broken, 'position' => 1, 'question_id' => (int) $question->id, 'revision_id' => (int) $question->current_revision_id,
    'given_answer' => '{"answer_ids":"kaputt"}', 'answered_at' => gmdate('Y-m-d H:i:s', time() - 4 * HOUR_IN_SECONDS)]);
// 3) Ein abgelaufener Zugang, den der spätere Teil des Laufs auf „expired“ setzen muss.
$wpdb->insert($p.'entitlement', ['user_id' => $student, 'course_code' => (string) $course->code, 'feature_code' => 'question_bank', 'status' => 'active', 'source' => 'manual',
    'valid_from' => gmdate('Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS), 'valid_until' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), 'created_at' => $past, 'updated_at' => $past]);
$expiredRow = (int) $wpdb->insert_id;
$thrown = null;
try {
    do_action(Cron::HOURLY);
} catch (\Throwable $e) {
    $thrown = $e;
}
$status = static fn (int $id) => (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}exam_session WHERE id = %d", $id));
$check($thrown === null, 'stündlicher Lauf endet ohne Abbruch'.($thrown ? ' ('.$thrown->getMessage().')' : ''));
$check($status($emptySnapshot) === 'evaluated', 'Sitzung mit leerem Regel-Snapshot ausgewertet');
$check($status($broken) === 'error', 'nicht auswertbare Sitzung auf „error“ gesetzt');
$check((bool) array_filter(Cron::errors(), static fn ($e) => $e['task'] === 'exam_expire' && str_contains((string) $e['item'], (string) $broken)), 'defekte Sitzung im Fehlerprotokoll');
$check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}entitlement WHERE id = %d", $expiredRow)) === 'expired', 'spätere Aufgabe (Zugänge ablaufen lassen) lief trotzdem');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}exam_session WHERE user_id = %d", $student));
$wpdb->delete($p.'entitlement', ['user_id' => $student]);
delete_option(Cron::OPTION_ERRORS);

echo "Migration nicht in Besucheranfragen (ARC-05)\n";
$check(Plugin::migrationAction(false, false, false, false, time()) === 'schedule', 'Frontend: nur Cron-Lauf planen');
$check(Plugin::migrationAction(false, true, false, false, time()) === 'run' && Plugin::migrationAction(false, false, true, false, time()) === 'run', 'Admin und Cron: ausführen');
$check(Plugin::migrationAction(true, false, false, ['at' => time()], time()) === 'run', 'CLI: immer ausführen');
$check(Plugin::migrationAction(false, true, false, ['at' => time() - 60], time()) === 'wait', 'nach Fehlschlag: Backoff');
$check(Plugin::migrationAction(false, true, false, ['at' => time() - Plugin::MIGRATION_BACKOFF - 1], time()) === 'run', 'nach dem Backoff: neuer Versuch');
$check(has_action(Plugin::MIGRATE_HOOK) !== false, 'Cron-Ereignis für die Migration registriert');

echo "Aufbewahrung Paketkopien und Import-Protokoll (ARC-06)\n";
$service = Plugin::instance()->importService();
$realPublished = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}import_batch WHERE status = 'published'");
$active = $service->activeBatchId();
$fake = [];
foreach ([1, 2, 3, 4] as $i) {
    // Ältere veröffentlichte Stände als alle echten (die echten bleiben in diesem Test unberührt).
    $wpdb->insert($p.'import_batch', ['package_id' => 'ops-test-'.$i, 'schema_version' => '1', 'status' => 'published', 'manifest' => '{}', 'created_at' => '2001-01-0'.$i.' 00:00:00', 'published_at' => '2001-01-0'.$i.' 00:00:00']);
    $fake[$i] = (int) $wpdb->insert_id;
    wp_mkdir_p($service->packageDir($fake[$i]));
    file_put_contents($service->packageDir($fake[$i]).'/manifest.json', '{}');
}
$wpdb->insert($p.'import_batch', ['package_id' => 'ops-test-laufend', 'schema_version' => '1', 'status' => 'validated', 'manifest' => '{}', 'created_at' => gmdate('Y-m-d H:i:s')]);
$pending = (int) $wpdb->insert_id;
wp_mkdir_p($service->packageDir($pending));
$base = dirname($service->packageDir($pending));
$oldUpload = $base.'/upload-opstestalt';
$newUpload = $base.'/upload-opstestneu';
$foreign = $base.'/ops-test-fremd';
foreach ([$oldUpload, $newUpload, $foreign] as $dir) {
    wp_mkdir_p($dir);
}
touch($oldUpload, time() - 2 * DAY_IN_SECONDS);
touch($foreign, time() - 2 * DAY_IN_SECONDS);
// Behalten: das aktive und die letzten N veröffentlichten – hier so viele, dass genau die echten und der neueste Testbestand bleiben.
$keepFilter = static fn () => $realPublished - ($active !== null ? 1 : 0) + 1;
add_filter('bootspruefung_import_keep_packages', $keepFilter);
$protected = Retention::protectedBatches($service);
$check($active === null || isset($protected[$active]), 'aktives Paket ist geschützt');
add_filter('bootspruefung_import_keep_packages', '__return_zero', 20);
$none = Retention::protectedBatches($service);
remove_filter('bootspruefung_import_keep_packages', '__return_zero', 20);
$check(($active === null || isset($none[$active])) && isset($none[$pending]), 'auch ohne Rollback-Ziele: aktives Paket und laufender Import bleiben');
// Protokollzeilen: „unchanged“ nach 14 Tagen, alles andere nach 180 Tagen.
$log = static function (int $batch, string $action, int $daysAgo) use ($wpdb, $p): int {
    $wpdb->insert($p.'import_item_log', ['batch_id' => $batch, 'entity' => 'questions', 'source_id' => 'ops', 'action' => $action, 'message' => '', 'created_at' => gmdate('Y-m-d H:i:s', time() - $daysAgo * DAY_IN_SECONDS)]);

    return (int) $wpdb->insert_id;
};
$logUnchangedOld = $log($fake[4], 'unchanged', 30);
$logUnchangedNew = $log($fake[4], 'unchanged', 2);
$logCreateMid = $log($fake[4], 'create', 30);
$logCreateOld = $log($fake[4], 'create', 200);
$result = Retention::run();
remove_filter('bootspruefung_import_keep_packages', $keepFilter);
$exists = static fn (int $id) => (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}import_item_log WHERE id = %d", $id));
$check(is_array($result) && ! is_dir($service->packageDir($fake[1])) && ! is_dir($service->packageDir($fake[2])) && ! is_dir($service->packageDir($fake[3])), 'ältere Paketkopien über die Aufbewahrung hinaus gelöscht');
$check(is_dir($service->packageDir($fake[4])) && is_dir($service->packageDir($pending)), 'jüngstes Rollback-Ziel und laufender Import bleiben');
$check($active === null || is_dir($service->packageDir($active)), 'aktives Paket bleibt');
$check(! is_dir($oldUpload) && is_dir($newUpload) && is_dir($foreign), 'verwaister Upload-Ordner (älter als 1 Tag) gelöscht, frischer und fremde Ordner bleiben');
$check(! $exists($logUnchangedOld) && $exists($logUnchangedNew) && $exists($logCreateMid) && ! $exists($logCreateOld), 'Protokoll: „unchanged“ nach 14 Tagen, Änderungen nach 180 Tagen gelöscht');
foreach (array_merge($fake, [$pending]) as $id) {
    if (is_dir($service->packageDir($id))) {
        ImportService::removeDir($service->packageDir($id));
    }
}
foreach ([$newUpload, $foreign] as $dir) {
    if (is_dir($dir)) {
        ImportService::removeDir($dir);
    }
}
$wpdb->query("DELETE FROM {$p}import_batch WHERE package_id LIKE 'ops-test-%'");

echo "Inhalts-Cache und Kursübersicht (ARC-07)\n";
ContentCache::invalidate();
$before = $wpdb->num_queries;
$content->courses();
$content->modules((int) $course->id);
$first = $wpdb->num_queries - $before;
$before = $wpdb->num_queries;
$content->courses();
foreach ($courses as $c) {
    $content->modules((int) $c->id);
}
$check($first <= 2 && $wpdb->num_queries === $before, 'Kurse und Module aller Kurse: zusammen höchstens zwei Abfragen, danach aus dem Cache');
$raw = $wpdb->get_results($wpdb->prepare(
    "SELECT m.id, m.code, m.name, cm.sort_order FROM {$p}course_module cm JOIN {$p}module m ON m.id = cm.module_id WHERE cm.course_id = %d AND m.status <> 'source_archived' ORDER BY cm.sort_order, m.id",
    (int) $course->id
));
$check(wp_json_encode($raw) === wp_json_encode($content->modules((int) $course->id)), 'Module wie bisher (gleiche Felder und Reihenfolge)');
ContentCache::invalidate();
$before = $wpdb->num_queries;
$content->courses();
$check($wpdb->num_queries === $before + 1, 'nach invalidate() wird neu geladen (Import, Overrides)');
wp_cache_flush_group(ContentCache::GROUP);
$before = $wpdb->num_queries;
$content->courses();
$check($wpdb->num_queries === $before + 1, 'auch wp_cache_flush_group() leert den Inhalts-Cache');

// /me/courses: Zugangsregeln, Zugänge und Produktzuordnungen nicht mehr je Kurs abfragen.
$learner = $user('ops_test_learner');
$entitlements = new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb);
foreach (array_slice($courses, 0, 3) as $c) {
    $entitlements->grantManual($learner, (string) $c->code, ['all'], null, 'Betriebstest');
}
wp_set_current_user($learner);
$direct = (new \Bootspruefung\Learning\Rest\Controllers\LearnerController())->courses();
$before = $wpdb->num_queries;
$response = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/me/courses'));
$queries = $wpdb->num_queries - $before;
$check($response->get_status() === 200 && wp_json_encode($response->get_data()) === wp_json_encode($direct), 'gleiches Ergebnis mit Anfrage-Zwischenspeicher wie ohne');
$check($queries <= count($courses) + 20, sprintf('/me/courses: %d Abfragen für %d Kurse (Grenze %d; vorher mehrere je Kurs)', $queries, count($courses), count($courses) + 20));
$check(! RequestMemo::active(), 'Zwischenspeicher nach der Anfrage wieder aus');
// Zugang innerhalb desselben Prozesses entziehen: die nächste Anfrage sieht den neuen Stand.
$wpdb->delete($p.'entitlement', ['user_id' => $learner]);
$after = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/me/courses'))->get_data();
$code = (string) $courses[0]->code;
$row = array_values(array_filter((array) $after, static fn ($r) => ($r['code'] ?? '') === $code))[0] ?? null;
$check(is_array($row) && ($direct[0]['features']['question_bank'] ?? false) === true && ($row['features']['question_bank'] ?? null) === ((new \Bootspruefung\Learning\Learning\Services\AccessService())->features($learner, $code)['question_bank'] ?? null), 'kein veralteter Zugang zwischen zwei Anfragen');
wp_set_current_user(1);

echo "Versionsvertrag Theme/Add-on (ARC-08, ARC-09, ARC-10, ARC-17)\n";
$contract = bootspruefung_learning_contract();
$check($contract === ['version' => Plugin::VERSION, 'contract' => Plugin::API_CONTRACT] && BP_LEARNING_VERSION === Plugin::VERSION, 'Vertrag: Plugin-Version und Vertragsversion');
$check(bootspruefung_learning_compatible('0.13.0') && ! bootspruefung_learning_compatible('99.0.0') && ! bootspruefung_learning_compatible('0.13.0', 2), 'Mindestversion und Vertragsversion geprüft');
$headers = get_file_data(Plugin::instance()->file(), ['update' => 'Update URI', 'wp' => 'Requires at least', 'wc' => 'WC requires at least']);
$check($headers['update'] === 'false', 'Plugin-Kopf: Update URI gesetzt (keine fremden Updates von wordpress.org)');
$check($headers['wp'] === \Bootspruefung\Learning\Infrastructure\Requirements::MIN_WP && $headers['wc'] === \Bootspruefung\Learning\Infrastructure\Requirements::MIN_WC, 'Mindestversionen im Kopf und in Requirements gleich');
$theme = get_file_data(dirname(__DIR__, 3).'/themes/kurswasser/style.css', ['update' => 'Update URI']);
$check($theme['update'] === 'false', 'Theme-Kopf: Update URI gesetzt');
$addon = dirname(__DIR__, 3).'/learnboating-affiliate-connector';
$check(get_file_data($addon.'/learnboating-affiliate-connector.php', ['update' => 'Update URI'])['update'] === 'false', 'Add-on-Kopf: Update URI gesetzt');
// Add-on gegen das echte Plugin (statt Nachbauten): Vertrag v1 von WP Affiliate Captain nachgestellt.
if (! function_exists('wpac_contract_version')) {
    function wpac_contract_version(): string
    {
        return '1.0.0';
    }
    function wpac_resolve_partner_code($code, array $ctx = []): ?array
    {
        return null;
    }
}
if (! defined('LBAC_VERSION')) {
    define('LBAC_VERSION', 'test');
}
require_once $addon.'/src/Connector.php';
$connector = \LearnBoating\AffiliateConnector\Connector::class;
$check($connector::diagnose() === '', 'Add-on: mit dem echten Plugin kompatibel');
$old = static fn () => ['version' => '0.11.0', 'contract' => 1];
add_filter('bootspruefung_learning_contract', $old);
$check(str_contains($connector::diagnose(), '0.13.0'), 'Add-on: älteres Plugin → kontrolliert inaktiv mit Mindestversion im Hinweis');
remove_filter('bootspruefung_learning_contract', $old);
$wrong = [];
foreach (\Bootspruefung\Learning\Commerce\ShopCatalog::products() as $product) {
    $reason = $connector::exclusionReason(['sku' => $product['sku']]);
    $upgrade = $product['sku'] === \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_UPGRADE || ($product['tier'] ?? '') === 'upgrade' || ($product['group'] ?? '') === 'videoupgrade';
    $expected = $upgrade ? 'upgrade' : (($product['group'] ?? '') === 'groups' || (int) ($product['seats'] ?? 0) > 0 ? 'group' : '');
    if ($reason !== $expected) {
        $wrong[] = $product['sku'].': '.$reason.' statt '.$expected;
    }
}
$check($wrong === [], 'Add-on: Provisionsausschluss für alle Katalogprodukte des echten Plugins'.($wrong ? ' – '.implode(', ', $wrong) : ''));

$wpdb->delete($p.'entitlement', ['user_id' => $learner]);
echo "Release: Version, Dateistand, Downgrade (T48)\n";
$pluginDir = dirname(__DIR__, 2);
preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($pluginDir.'/bootspruefung-learning.php'), $vHeader);
preg_match('/^Stable tag:\s*(\S+)/m', (string) file_get_contents($pluginDir.'/readme.txt'), $vReadme);
preg_match('/^## (\d+\.\d+\.\d+)/m', (string) file_get_contents($pluginDir.'/CHANGELOG.md'), $vLog);
$check(($vHeader[1] ?? '') === Plugin::VERSION && ($vReadme[1] ?? '') === Plugin::VERSION && ($vLog[1] ?? '') === Plugin::VERSION, 'Version gleich in Plugin-Kopf, readme.txt, Plugin::VERSION und oberstem CHANGELOG-Eintrag ('.Plugin::VERSION.')');
$assetV = Plugin::assetVersion($pluginDir.'/bootspruefung-learning.php', 'assets/build/app.js');
$check(str_starts_with($assetV, Plugin::VERSION.'.') && str_ends_with($assetV, (string) filemtime($pluginDir.'/assets/build/app.js')), 'app.js mit Dateistand in der Version (Browser laden Änderungen sofort)');
$schemaNow = get_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION);
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, '99.0.0');
$migratorT48 = new \Bootspruefung\Learning\Infrastructure\Database\Migrator($wpdb);
$check($migratorT48->schemaIsNewer() && ! $migratorT48->needsMigration(), 'Downgrade (neueres Schema): keine Rückmigration, Hinweis statt Wartungssperre');
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, $schemaNow);
$check(! $migratorT48->schemaIsNewer() && ! $migratorT48->needsMigration(), 'aktuelles Schema: nichts zu tun');

echo "Wartung nach Plugin-Update (T47)\n";
$MT = \Bootspruefung\Learning\Infrastructure\Maintenance::class;
$schemaVersion = get_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION);
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, '0.0.1');
$MT::reset();
wp_clear_scheduled_hook(Plugin::MIGRATE_HOOK);
$write = rest_do_request(new WP_REST_Request('POST', '/bootspruefung/v1/courses/SBF-SEE/answers'));
$check($write->get_status() === 503 && ($write->get_data()['code'] ?? '') === 'bp_maintenance' && ($write->get_headers()['Retry-After'] ?? '') === '30', 'Migration fällig: schreibende Web-Anfrage 503 mit Retry-After (nichts halb speichern)');
$check(wp_next_scheduled(Plugin::MIGRATE_HOOK) !== false, 'Migration sofort als Cron-Lauf angestoßen');
$appWrite = rest_do_request(new WP_REST_Request('POST', '/boatingschool/v1/auth/login'));
$check($appWrite->get_status() === 503 && ($appWrite->get_data()['code'] ?? '') === 'MAINTENANCE', 'App-API: 503 MAINTENANCE');
$read = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/gibt-es-nicht'));
$check($read->get_status() !== 503, 'lesende Anfragen laufen weiter');
$other = rest_do_request(new WP_REST_Request('POST', '/wp/v2/gibt-es-nicht'));
$check($other->get_status() !== 503, 'andere Schnittstellen (z. B. WordPress, WooCommerce) unberührt');
ob_start();
\Bootspruefung\Learning\Admin\SystemStatus::renderMaintenance();
$maint = (string) ob_get_clean();
$check(str_contains($maint, 'Wartung') && str_contains($maint, 'fällig') && str_contains($maint, 'Bestellabgleich') && str_contains($maint, 'Client-IP'), 'Systemstatus: Abschnitt „Wartung“ mit Migration, Bestellabgleich und Client-IP');
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, $schemaVersion);
$MT::reset();
wp_clear_scheduled_hook(Plugin::MIGRATE_HOOK);
$after = rest_do_request(new WP_REST_Request('POST', '/bootspruefung/v1/courses/SBF-SEE/answers'));
$check($after->get_status() !== 503, 'nach der Migration wieder normal');

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
