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
// T79: admin-ajax.php/admin-post.php sind Kundenanfragen (is_admin() ist dort wahr) – nur planen.
$check(Plugin::migrationAction(false, true, false, false, time(), true) === 'schedule', 'admin-ajax.php/admin-post.php: nur Cron-Lauf planen');
$check(Plugin::migrationAction(false, false, true, ['at' => time() - 60], time()) === 'wait', 'Cron beachtet den Backoff nach einem Fehlschlag');
$check(Plugin::migrationAction(false, false, true, ['at' => time() - Plugin::MIGRATION_BACKOFF - 1], time()) === 'run', 'Cron nach dem Backoff: neuer Versuch');
$check(! Plugin::isCustomerAdminRequest(), 'CLI-Testlauf ist keine Kundenanfrage');
$ajaxFilter = static fn () => true;
add_filter('wp_doing_ajax', $ajaxFilter);
$check(Plugin::isCustomerAdminRequest(), 'admin-ajax.php erkannt');
remove_filter('wp_doing_ajax', $ajaxFilter);
$pagenowBefore = $GLOBALS['pagenow'] ?? null;
$GLOBALS['pagenow'] = 'admin-post.php';
$check(Plugin::isCustomerAdminRequest(), 'admin-post.php erkannt');
$GLOBALS['pagenow'] = $pagenowBefore;
// Cron-Handler im Backoff: migriert nicht, plant den nächsten Versuch nach dem Backoff.
$schemaT79 = get_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION);
$failedT79 = ['at' => time() - 60, 'message' => 'Test'];
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, '0.0.1');
update_option(Plugin::OPTION_MIGRATION_FAILED, $failedT79, false);
wp_clear_scheduled_hook(Plugin::MIGRATE_HOOK);
$ranT79 = Plugin::cronMigration();
$nextT79 = wp_next_scheduled(Plugin::MIGRATE_HOOK);
$check(! $ranT79 && get_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION) === '0.0.1', 'Cron-Handler im Backoff: keine Migration');
$check($nextT79 !== false && abs($nextT79 - ($failedT79['at'] + Plugin::MIGRATION_BACKOFF)) <= 2, 'Cron-Handler im Backoff: nächster Versuch nach Ablauf geplant');
$check(! Plugin::scheduleMigration($failedT79), 'Wartungssperre im Backoff: kein sofortiger Cron-Anstoß');
update_option(Plugin::OPTION_MIGRATION_FAILED, ['at' => time() - Plugin::MIGRATION_BACKOFF - 1, 'message' => 'Test'], false);
wp_clear_scheduled_hook(Plugin::MIGRATE_HOOK);
$check(Plugin::cronMigration() && get_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION) === $schemaT79 && get_option(Plugin::OPTION_MIGRATION_FAILED) === false, 'Cron-Handler nach dem Backoff: Migration läuft und Fehler gelöscht');
wp_clear_scheduled_hook(Plugin::MIGRATE_HOOK);

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

echo "Indizes für die stündlichen Löschläufe (T86)\n";
$indexes = static fn (string $table): array => array_unique(array_map(static fn ($r) => (string) $r->Key_name, (array) $wpdb->get_results("SHOW INDEX FROM {$p}{$table}")));
$check(in_array('created_at', $indexes('audit_log'), true), 'audit_log: Index auf created_at');
$check(in_array('created_at', $indexes('import_item_log'), true) && in_array('action_created', $indexes('import_item_log'), true), 'import_item_log: Indizes created_at und (action, created_at)');
// Bestehende Installation (Schema 1.14.0 ohne die Indizes): die Migration ergänzt sie.
$wpdb->query("ALTER TABLE {$p}audit_log DROP INDEX created_at");
$wpdb->query("ALTER TABLE {$p}import_item_log DROP INDEX created_at, DROP INDEX action_created");
update_option(\Bootspruefung\Learning\Infrastructure\Database\Migrator::OPTION_VERSION, '1.14.0');
$migratorT86 = new \Bootspruefung\Learning\Infrastructure\Database\Migrator($wpdb);
$check($migratorT86->needsMigration() && $migratorT86->migrate() && ! $migratorT86->needsMigration(), 'Update von 1.14.0: Migration läuft über den Migrationsmechanismus');
$check(in_array('created_at', $indexes('audit_log'), true) && in_array('created_at', $indexes('import_item_log'), true) && in_array('action_created', $indexes('import_item_log'), true), 'Update von 1.14.0: Indizes ergänzt');
$explainKey = static function (string $sql) use ($wpdb): string {
    $row = $wpdb->get_row('EXPLAIN '.$sql);

    return (string) ($row->key ?? '');
};
$keyAudit = $explainKey($wpdb->prepare("DELETE FROM {$p}audit_log WHERE created_at < %s LIMIT 5000", '2000-01-01 00:00:00'));
$keyLog = $explainKey($wpdb->prepare("DELETE FROM {$p}import_item_log WHERE batch_id <> %d AND created_at < %s LIMIT 5000", 0, '2000-01-01 00:00:00'));
$check($keyAudit === 'created_at' && in_array($keyLog, ['created_at', 'action_created'], true), 'Löschabfragen nutzen den Index (audit_log: '.$keyAudit.', import_item_log: '.$keyLog.')');

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

echo "Mailversand (T54)\n";
$ms = \Bootspruefung\Learning\Admin\MailStatus::class;
$fromBefore = get_option('woocommerce_email_from_address', null);
$nameBefore = get_option('woocommerce_email_from_name', null);
update_option('woocommerce_email_from_address', 'kurse@example.test');
update_option('woocommerce_email_from_name', 'Testschule');
$check(apply_filters('wp_mail_from', 'wordpress@example.test') === 'kurse@example.test' && apply_filters('wp_mail_from_name', 'WordPress') === 'Testschule', 'WordPress-Mails mit dem Shop-Absender');
$check(apply_filters('wp_mail_from', 'info@eigene.test') === 'info@eigene.test' && apply_filters('wp_mail_from_name', 'Eigener Name') === 'Eigener Name', 'eigene Absender-Einstellung bleibt');
update_option('woocommerce_email_from_address', 'keine-adresse');
$check(apply_filters('wp_mail_from', 'wordpress@example.test') === 'wordpress@example.test', 'ohne gültigen Shop-Absender unverändert');
update_option('woocommerce_email_from_address', 'kurse@example.test');
$sent = [];
$catchMail = static function ($short, $atts) use (&$sent) { $sent[] = $atts; return true; };
add_filter('pre_wp_mail', $catchMail, 10, 2);
$res = $ms::sendTest('admin@example.test');
$check($res['ok'] && count($sent) === 1 && $sent[0]['to'] === 'admin@example.test' && str_contains(implode("\n", (array) $sent[0]['headers']), 'From: Testschule <kurse@example.test>') && get_option($ms::OPTION_TEST)['ok'] === true, 'Testmail mit Absender, Ergebnis gespeichert');
$check(str_contains($ms::transport(), 'pre_wp_mail'), 'Versandweg erkannt');
remove_filter('pre_wp_mail', $catchMail, 10);
$failMail = static function () {
    do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP connect() failed.'));

    return false;
};
add_filter('pre_wp_mail', $failMail);
$res = $ms::sendTest('admin@example.test');
$check(! $res['ok'] && $res['error'] === 'SMTP connect() failed.', 'fehlgeschlagene Testmail mit Fehlermeldung');
remove_filter('pre_wp_mail', $failMail);
$rows = $ms::rows();
$check(isset($rows['SPF'], $rows['DMARC'], $rows['DKIM']) && str_contains($rows['Letzte Testmail'], 'fehlgeschlagen: SMTP connect() failed.') && $ms::domain('a@B.example') === 'b.example', 'Statuszeilen (SPF, DMARC, DKIM, letzte Testmail)');
ob_start();
$ms::render();
$check(str_contains((string) ob_get_clean(), 'E-Mail-Versand'), 'Abschnitt im Systemstatus');
delete_option($ms::OPTION_TEST);

echo "Pflichtangaben in Plugin-Mails (T58)\n";
$mail = \Bootspruefung\Learning\Support\Mail::class;
$storeKeys = ['woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_postcode', 'woocommerce_store_city'];
$storeBefore = [];
foreach ($storeKeys as $key) {
    $storeBefore[$key] = get_option($key, null);
}
update_option('woocommerce_store_address', 'Hafenstraße 1');
update_option('woocommerce_store_address_2', '');
update_option('woocommerce_store_postcode', '20457');
update_option('woocommerce_store_city', 'Hamburg');
$siteName = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
$sent = [];
add_filter('pre_wp_mail', $catchMail, 10, 2);
// Standard des Plugins (Website-Name, Shop-Adresse, Absender) – vor einem möglichen Theme-Filter abgegriffen.
$defaultFooter = null;
$grabDefault = static function ($value) use (&$defaultFooter) {
    $defaultFooter = (string) $value;

    return $value;
};
add_filter('bootspruefung_mail_footer', $grabDefault, 1);
$footer = $mail::footer();
remove_filter('bootspruefung_mail_footer', $grabDefault, 1);
$check(is_string($defaultFooter) && str_starts_with($defaultFooter, $siteName."\nHafenstraße 1, 20457 Hamburg") && str_ends_with($defaultFooter, "\nE-Mail: kurse@example.test") && substr_count($defaultFooter, "\n") === 2, 'Standard-Fuß: Website-Name, Shop-Adresse, E-Mail ('.str_replace("\n", ' | ', (string) $defaultFooter).')');
$mail::send('kunde@example.test', 'Test', "Hallo,\n\nText.\n");
$check(count($sent) === 1 && $sent[0]['message'] === "Hallo,\n\nText.\n\n-- \n".$footer, 'Mail an Kunden endet mit Trenner und Anbieter-Angaben');
$mail::send('betreiber@example.test', 'Intern', 'Interne Nachricht', [], footer: false);
$check($sent[1]['message'] === 'Interne Nachricht', 'interne Mail (footer: false) ohne Fuß');
$ownFooter = static fn () => "Wasser & Wind GmbH\nHafenstraße 1, 20457 Hamburg\nE-Mail: info@example.test";
add_filter('bootspruefung_mail_footer', $ownFooter, 99);
$mail::send('kunde@example.test', 'Test', 'Text');
$check(str_ends_with($sent[2]['message'], "Text\n\n-- \nWasser & Wind GmbH\nHafenstraße 1, 20457 Hamburg\nE-Mail: info@example.test"), 'Filter bootspruefung_mail_footer ersetzt den Fuß (Klartext, & bleibt &)');
remove_filter('bootspruefung_mail_footer', $ownFooter, 99);
add_filter('bootspruefung_mail_footer', '__return_empty_string', 99);
$mail::send('kunde@example.test', 'Test', 'Text');
$check($sent[3]['message'] === 'Text', 'leerer Filterwert: kein Fuß');
remove_filter('bootspruefung_mail_footer', '__return_empty_string', 99);
$once = $mail::withFooter('Text', $footer);
$check($mail::withFooter($once, $footer) === $once, 'Fuß nie doppelt (schon enthalten → unverändert)');
// Ablauf-Erinnerung: eigener Abschnitt nach „-- “ (Abmeldehinweis) und Kopfzeile List-Unsubscribe bleiben; kein zweiter Trenner.
$remUser = get_userdata($user('bp_ops_mail_footer'));
[$remSubject, $remBody] = \Bootspruefung\Learning\Commerce\ExpiryReminder::mail($remUser, [(string) $courses[0]->code => gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS)]);
$optOut = \Bootspruefung\Learning\Commerce\ReminderOptOut::url((int) $remUser->ID);
$mail::send((string) $remUser->user_email, $remSubject, $remBody, ['List-Unsubscribe: <'.$optOut.'>']);
$rem = $sent[4] ?? ['message' => '', 'headers' => []];
$check(substr_count($rem['message'], "\n-- \n") === 1 && str_ends_with($rem['message'], "„Mein Lernen“.\n\n".$footer) && str_contains($rem['message'], 'Abbestellen: '.$optOut), 'Ablauf-Erinnerung: Abmeldelink bleibt, Fuß am Ende, nur ein Trenner');
$check(in_array('List-Unsubscribe: <'.$optOut.'>', (array) $rem['headers'], true), 'Kopfzeile List-Unsubscribe bleibt erhalten');
remove_filter('pre_wp_mail', $catchMail, 10);
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user((int) $remUser->ID);
foreach ($storeBefore as $key => $value) {
    $value === null ? delete_option($key) : update_option($key, $value);
}
$fromBefore === null ? delete_option('woocommerce_email_from_address') : update_option('woocommerce_email_from_address', $fromBefore);
$nameBefore === null ? delete_option('woocommerce_email_from_name') : update_option('woocommerce_email_from_name', $nameBefore);

echo "Wartungslauf selbstheilend, Ablauf-Erinnerung robust (T80)\n";
$ER = \Bootspruefung\Learning\Commerce\ExpiryReminder::class;
$check(has_action('init', [Plugin::class, 'ensureSchedules']) !== false, 'Prüfung der Läufe bei jeder Anfrage verbunden');
wp_clear_scheduled_hook(Cron::HOURLY);
wp_clear_scheduled_hook($ER::HOOK);
Plugin::ensureSchedules();
$check(wp_get_schedule(Cron::HOURLY) === 'hourly' && wp_get_schedule($ER::HOOK) === 'daily', 'fehlende Läufe (z. B. nach Umzug) neu eingeplant');
$hourlyAt = wp_next_scheduled(Cron::HOURLY);
Plugin::ensureSchedules();
$check(wp_next_scheduled(Cron::HOURLY) === $hourlyAt, 'vorhandener Lauf bleibt unverändert (keine Doppelplanung)');
$settingsT80 = \Bootspruefung\Learning\Settings\Settings::all();
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge($settingsT80, ['expiry_reminder_enabled' => true, 'expiry_reminder_days' => 14, 'default_access' => 'entitlement']));
$freeCodes = $wpdb->get_col("SELECT DISTINCT course_code FROM {$p}access_rule WHERE feature_code = 'question_bank' AND module_code IS NULL AND access_type = 'free'");
$remCourse = '';
foreach ($courses as $c) {
    if (! in_array((string) $c->code, $freeCodes, true)) {
        $remCourse = (string) $c->code;
        break;
    }
}
$entT80 = new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb);
$remUntil = gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS);
$remUsers = ['a' => $user('ops_t80_a'), 'b' => $user('ops_t80_b')];
$ordersT80 = [];
foreach ($remUsers as $uid) {
    delete_user_meta($uid, $ER::META);
    $wpdb->delete($p.'entitlement', ['user_id' => $uid]);
    $entT80->grantManual($uid, $remCourse, ['question_bank'], $remUntil, 'Test T80');
    $orderId = 990000 + $uid;
    if (function_exists('wc_create_order')) {
        // Mit Shop zählt nur eine eigene, bezahlte Bestellung über 0 € (ExpiryReminder::boughtCourses).
        $order = wc_create_order(['customer_id' => $uid]);
        $order->set_total('10.00');
        $order->set_date_paid(time());
        $order->save();
        $orderId = (int) $order->get_id();
        $ordersT80[] = $orderId;
    }
    $wpdb->query($wpdb->prepare("UPDATE {$p}entitlement SET source = 'woocommerce', order_id = %d WHERE user_id = %d", $orderId, $uid));
}
$emailOf = static fn (int $uid): string => (string) get_userdata($uid)->user_email;
$t80Mails = [];
$t80Mode = ['a' => 'ok'];
$t80MarkedBeforeSend = null;
$t80Catch = static function ($null, $atts) use (&$t80Mails, &$t80Mode, &$t80MarkedBeforeSend, $remUsers, $emailOf, $ER, $remCourse, $remUntil) {
    $to = is_array($atts['to']) ? (string) reset($atts['to']) : (string) $atts['to'];
    if ($to === $emailOf($remUsers['a']) && $t80Mode['a'] === 'throw') {
        throw new RuntimeException('Mailserver kaputt');
    }
    if ($to === $emailOf($remUsers['a']) && $t80Mode['a'] === 'fail') {
        return false;
    }
    if ($to === $emailOf($remUsers['b'])) {
        $t80MarkedBeforeSend = array_key_exists($remCourse.'|'.$remUntil, (array) get_user_meta($remUsers['b'], $ER::META, true));
    }
    $t80Mails[] = $to;

    return true;
};
add_filter('pre_wp_mail', $t80Catch, 1, 2);
$countTo = static function (int $uid) use (&$t80Mails, $emailOf): int {
    return count(array_filter($t80Mails, static fn ($m) => $m === $emailOf($uid)));
};
// Paralleler Lauf hält die Sperre: kein Versand.
Lock::acquire($ER::LOCK, 3600, 'anderer-lauf');
$check($remCourse !== '' && $ER::run() === 0 && $countTo($remUsers['a']) === 0 && $countTo($remUsers['b']) === 0, 'paralleler Lauf (Sperre gehalten): keine Mail, kein Doppelversand');
Lock::release($ER::LOCK, 'anderer-lauf');
// Fehler bei einem Empfänger: protokolliert, die übrigen bekommen ihre Mail.
delete_option(Cron::OPTION_ERRORS);
$t80Mode['a'] = 'throw';
$thrownT80 = null;
try {
    $ER::run();
} catch (\Throwable $e) {
    $thrownT80 = $e;
}
$check($thrownT80 === null && $countTo($remUsers['b']) === 1, 'Fehler bei einem Empfänger bricht den Lauf nicht ab');
$check((bool) array_filter(Cron::errors(), static fn ($e) => $e['task'] === 'expiry_reminder' && $e['item'] === 'Nutzer '.$remUsers['a'] && str_contains($e['message'], 'Mailserver kaputt')), 'Fehler mit Empfänger im Wartungsprotokoll');
$check($t80MarkedBeforeSend === true, 'Zugang vor dem Versand als erinnert markiert');
$check(! array_key_exists($remCourse.'|'.$remUntil, (array) get_user_meta($remUsers['a'], $ER::META, true)), 'nicht gesendet: Merker zurückgenommen');
$check(Lock::holder($ER::LOCK) === null, 'Sperre nach dem Lauf freigegeben');
$t80Mode['a'] = 'fail';
$ER::run();
$check($countTo($remUsers['a']) === 0 && ! array_key_exists($remCourse.'|'.$remUntil, (array) get_user_meta($remUsers['a'], $ER::META, true)), 'Versand fehlgeschlagen: kein Merker, nächster Lauf versucht es erneut');
$t80Mode['a'] = 'ok';
$ER::run();
$ER::run();
$check($countTo($remUsers['a']) === 1 && $countTo($remUsers['b']) === 1, 'danach genau eine Mail je Empfänger, auch bei wiederholten Läufen');
remove_filter('pre_wp_mail', $t80Catch, 1);
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $settingsT80);
delete_option(Cron::OPTION_ERRORS);
foreach ($remUsers as $uid) {
    $wpdb->delete($p.'entitlement', ['user_id' => $uid]);
    delete_user_meta($uid, $ER::META);
}
foreach ($ordersT80 as $orderId) {
    $o = wc_get_order($orderId);
    if ($o) {
        $o->delete(true);
    }
}

echo "FAQ-, Kunden-FAQ- und Tipps-Import nicht in Besucheranfragen (T84)\n";
$check(Plugin::backgroundContext(false, false, false, false) === false, 'Besucheranfrage (Frontend): kein Import');
$check(Plugin::backgroundContext(false, false, true, true) === false, 'admin-ajax.php/admin-post.php: kein Import');
$check(Plugin::backgroundContext(false, false, true, false) && Plugin::backgroundContext(false, true, false, false) && Plugin::backgroundContext(true, false, false, false), 'Admin, Cron und WP-CLI: Import läuft');
$check(has_action('init', [\Bootspruefung\Learning\Faq\FaqImporter::class, 'maybeImport']) === false
    && has_action('init', [\Bootspruefung\Learning\Faq\CustomerFaqImporter::class, 'maybeImport']) === false
    && has_action('init', [\Bootspruefung\Learning\Tips\Tips::class, 'maybeSeed']) === false, 'Importe hängen nicht mehr direkt an init');
$check(has_action('init', [\Bootspruefung\Learning\Faq\Faq::class, 'maybeImportInBackground']) !== false
    && has_action('init', [\Bootspruefung\Learning\Faq\CustomerFaq::class, 'maybeImportInBackground']) !== false
    && has_action('init', [\Bootspruefung\Learning\Tips\Tips::class, 'maybeSeedInBackground']) !== false, 'Importe nur über die geprüften Einstiege (Admin, Cron, CLI)');
// Tipps gegen Doppelung: Sperre und Prüfung auf vorhandene Starttexte.
$T = \Bootspruefung\Learning\Tips\Tips::class;
\Bootspruefung\Learning\Tips\Tips::registerTypes();
$T::maybeSeed();
$tipCount = static fn (): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft'", $T::POST_TYPE));
$tipsBefore = $tipCount();
$seededBefore = get_option($T::SEEDED_OPTION);
delete_option($T::SEEDED_OPTION); // z. B. Option verloren oder zweiter Lauf parallel
Lock::acquire($T::SEED_LOCK, 300, 'anderer-lauf');
$T::maybeSeed();
$check($tipCount() === $tipsBefore && get_option($T::SEEDED_OPTION) === false, 'Sperre gehalten (paralleler Lauf): keine Tipps angelegt');
Lock::release($T::SEED_LOCK, 'anderer-lauf');
$T::maybeSeed();
$check($tipsBefore >= 13 && $tipCount() === $tipsBefore && get_option($T::SEEDED_OPTION) !== false, 'Starttexte vorhanden: keine doppelten Tipps');
$check(Lock::holder($T::SEED_LOCK) === null, 'Sperre der Tipps-Anlage freigegeben');
$seededBefore !== false ? update_option($T::SEEDED_OPTION, $seededBefore, false) : null;

echo "Aktivieren und Deaktivieren: Permalinks und Cron (T81)\n";
$ruleKeys = static fn (): string => implode("\n", array_keys((array) get_option('rewrite_rules')));
$base = preg_quote(\Bootspruefung\Learning\Frontend\Router::base(), '#');
foreach (Plugin::CRON_HOOKS as $hook) {
    wp_schedule_single_event(time() + 600, $hook, in_array($hook, [\Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK, \Bootspruefung\Learning\Privacy\AccountDeletion::EXPORT_HOOK], true) ? ['ops-t81'] : []);
}
// Mit „schönen“ Permalinks prüfen (die Testinstallation nutzt sonst einfache Links ohne Regeln).
global $wp_rewrite;
$permalinkBefore = (string) get_option('permalink_structure');
$wp_rewrite->set_permalink_structure('/%postname%/');
if (function_exists('WC')) {
    WC()->query->add_endpoints(); // set_permalink_structure() verwirft die Endpunkte dieser Anfrage
}
flush_rewrite_rules(false);
$check(str_contains($ruleKeys(), '^'.$base.'/?$') && (! class_exists('WooCommerce') || str_contains($ruleKeys(), '/mein-lernen(')), 'Ausgangslage: Lernbereich und Endpunkte in den Permalinks');
$check(count(Plugin::CRON_HOOKS) === 7 && in_array('bp_learning_migrate', Plugin::CRON_HOOKS, true) && in_array('bp_privacy_export_generate', Plugin::CRON_HOOKS, true) && in_array('bp_learning_expiry_reminder_continue', Plugin::CRON_HOOKS, true), 'vollständige Liste der eigenen Cron-Ereignisse');
Plugin::deactivate();
$cronLeft = array_filter(Plugin::CRON_HOOKS, static function (string $hook): bool {
    foreach ((array) _get_cron_array() as $events) {
        if (isset($events[$hook])) {
            return true;
        }
    }

    return false;
});
$check($cronLeft === [], 'Deaktivieren: alle eigenen Cron-Ereignisse ausgetragen (auch einmalige und mit Argumenten)'.($cronLeft ? ' – übrig: '.implode(', ', $cronLeft) : ''));
$afterDeactivate = $ruleKeys();
$check(! str_contains($afterDeactivate, '^'.$base.'/'), 'Deaktivieren: /'.\Bootspruefung\Learning\Frontend\Router::base().'/ nicht mehr in den Permalinks');
$check(! str_contains($afterDeactivate, '/mein-lernen(') && ! str_contains($afterDeactivate, '/meine-daten('), 'Deaktivieren: Endpunkte „mein-lernen“/„meine-daten“ entfernt');
$check(get_option(\Bootspruefung\Learning\Frontend\Router::REWRITE_OPTION) === false && get_option(\Bootspruefung\Learning\Privacy\SelfService::REWRITE_OPTION) === false, 'Deaktivieren: Permalink-Stand vergessen (nach Reaktivierung neu schreiben)');
// Reaktivierung: wie in der echten Aktivierungsanfrage fehlen die Endpunkte (Filter wirkten nach init nicht mehr).
Plugin::activate();
$afterActivate = $ruleKeys();
$check(str_contains($afterActivate, '^'.$base.'/?$') && str_contains($afterActivate, '^'.$base.'/(.+?)/?$'), 'Reaktivieren: /'.\Bootspruefung\Learning\Frontend\Router::base().'/ wieder in den Permalinks');
if (class_exists('WooCommerce')) {
    $check(str_contains($afterActivate, '/mein-lernen(') && str_contains($afterActivate, '/meine-daten('), 'Reaktivieren: „mein-lernen“ und „meine-daten“ sofort erreichbar (kein 404)');
    $wpq = new WP();
    $wpq->main_query_vars = [];
    $_SERVER['REQUEST_URI'] = '/'.get_post_field('post_name', (int) get_option('woocommerce_myaccount_page_id')).'/mein-lernen/';
    $wpq->parse_request();
    $_SERVER['REQUEST_URI'] = '/';
    $check(array_key_exists('mein-lernen', $wpq->query_vars), 'Reaktivieren: /mein-konto/mein-lernen/ wird erkannt');
}
$check(wp_get_schedule(Cron::HOURLY) === 'hourly' && wp_get_schedule(\Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK) === 'daily', 'Reaktivieren: stündlicher und täglicher Lauf wieder geplant');
$check(get_option(\Bootspruefung\Learning\Frontend\Router::REWRITE_OPTION) === false, 'Reaktivieren: nächste Anfrage schreibt die Permalinks mit allen Hooks neu');
(new \Bootspruefung\Learning\Frontend\Router(Plugin::instance()))->init();
$check(get_option(\Bootspruefung\Learning\Frontend\Router::REWRITE_OPTION) !== false && str_contains($ruleKeys(), '^'.$base.'/?$'), 'nächste Anfrage: Permalinks neu geschrieben');
wp_clear_scheduled_hook(\Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::HOOK);
$wp_rewrite->set_permalink_structure($permalinkBefore);
if (function_exists('WC')) {
    WC()->query->add_endpoints();
}
flush_rewrite_rules(false);

echo "Überwachung und Zeitzone (T55)\n";
$mon = \Bootspruefung\Learning\Infrastructure\Monitor::class;
$hourlyBefore = get_option($mon::OPTION_HOURLY, null);
$tzBefore = get_option('timezone_string');
$gmtBefore = get_option('gmt_offset');
\Bootspruefung\Learning\Plugin::hourly();
$check(time() - (int) get_option($mon::OPTION_HOURLY) < 60, 'stündlicher Lauf merkt sich den Zeitpunkt');
wp_set_current_user(0);
$res = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/monitor'));
$data = $res->get_data();
$check($res->get_status() === 200 && $data['status'] === 'ok' && $data['checks']['database'] && $data['checks']['cron'] && ! isset($data['version']) && ! str_contains((string) wp_json_encode($data), \Bootspruefung\Learning\Plugin::VERSION), 'Monitor ohne Anmeldung: 200, keine Version');
update_option($mon::OPTION_HOURLY, time() - 4 * HOUR_IN_SECONDS, false);
$res = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/monitor'));
$check($res->get_status() === 503 && $res->get_data()['checks']['cron'] === false, 'Cron seit mehr als 3 Stunden still: 503');
update_option('timezone_string', '');
update_option('gmt_offset', 1);
$check(! $mon::timezoneOk() && $mon::checks()['timezone'] === false, 'feste Zeitverschiebung: keine Ortszeit');
update_option('timezone_string', 'Europe/Berlin');
$check($mon::timezoneOk(), 'Europe/Berlin ist eine Ortszeit');
update_option('timezone_string', 'Etc/GMT-1');
$check(! $mon::timezoneOk(), 'Etc/… gilt nicht als Ortszeit');
update_option('timezone_string', $tzBefore);
update_option('gmt_offset', $gmtBefore);
$hourlyBefore === null ? delete_option($mon::OPTION_HOURLY) : update_option($mon::OPTION_HOURLY, $hourlyBefore, false);
$dropins = dirname(__DIR__, 2).'/dropins';
$check(is_file($dropins.'/maintenance.php') && is_file($dropins.'/php-error.php') && str_contains((string) file_get_contents($dropins.'/maintenance.php'), 'http_response_code(503)'), 'Vorlagen für Wartungs- und Fehlerseite (Drop-ins)');

echo "Untertitel der Lernvideos (T62)\n";
$vs = \Bootspruefung\Learning\Trainers\VideoService::class;
$setBefore = get_option(\Bootspruefung\Learning\Settings\Settings::OPTION);
$check($vs::captionsUrl('https://videos.example.test/sbf/lektion-01.mp4', 1) === null, 'Standard: keine Untertitel');
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge(\Bootspruefung\Learning\Settings\Settings::all(), ['video_captions' => 'vtt']));
$check($vs::captionsUrl('https://videos.example.test/sbf/lektion-01.mp4', 1) === 'https://videos.example.test/sbf/lektion-01.vtt'
    && $vs::captionsUrl('https://videos.example.test/a.webm?sig=x', 1) === 'https://videos.example.test/a.vtt?sig=x'
    && $vs::captionsUrl('https://player.example.test/embed/123', 1) === null, 'WebVTT neben dem Video (auch mit Parametern), keine Vermutung ohne Dateiendung');
$capFilter = static fn ($default, $video, $id) => $id === 7 ? 'https://cdn.example.test/untertitel/7.vtt' : ($id === 8 ? 'http://unsicher.example.test/8.vtt' : $default);
add_filter('bootspruefung_video_captions_url', $capFilter, 10, 3);
$check($vs::captionsUrl('https://videos.example.test/x.mp4', 7) === 'https://cdn.example.test/untertitel/7.vtt' && $vs::captionsUrl('https://videos.example.test/x.mp4', 8) === null, 'Filter für eigene Ablage; nur HTTPS');
remove_filter('bootspruefung_video_captions_url', $capFilter, 10);
$check(\Bootspruefung\Learning\Settings\Settings::sanitize(['video_captions' => 'quatsch'])['video_captions'] === 'off', 'Einstellung nur off/vtt');
$setBefore === false ? delete_option(\Bootspruefung\Learning\Settings\Settings::OPTION) : update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $setBefore);

echo "Prüfung nach Restore (T66)\n";
$bc = \Bootspruefung\Learning\Infrastructure\BackupCheck::class;
$ms = \Bootspruefung\Learning\Content\Media\MediaStorage::class;
$layoutBefore = get_option($ms::LAYOUT_OPTION, null);
$optionsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
$rows = $bc::run();
$byCheck = array_column($rows, null, 'check');
$check(isset($byCheck['Datenbank-Schema'], $byCheck['Tabellen'], $byCheck['Paketordner'], $byCheck['Schutzordner'], $byCheck['Geschützte Dateien'], $byCheck['Lernzugänge']) && $byCheck['Datenbank-Schema']['status'] === 'ok' && $byCheck['Tabellen']['status'] === 'ok', 'Prüfungen vorhanden, Schema und Tabellen ok');
$check(get_option($ms::LAYOUT_OPTION, null) === $layoutBefore && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}") === $optionsBefore, 'nur lesend: Ordner-Layout und Optionen unverändert');
// Restore neben dem Original: Layout zeigt auf einen fremden, vorhandenen Ordner – Fehler, aber nichts verschieben.
$foreign = sys_get_temp_dir().'/bp-t66-original-'.wp_generate_password(6, false).'/packages-x';
wp_mkdir_p($foreign);
file_put_contents($foreign.'/probe.txt', 'x');
update_option($ms::LAYOUT_OPTION, array_merge(is_array($layoutBefore) ? $layoutBefore : [], ['packages' => $foreign]), true);
$byCheck = array_column($bc::run(), null, 'check');
$check($byCheck['Paketordner']['status'] === 'fail' && str_contains($byCheck['Paketordner']['detail'], $foreign) && $bc::overall(array_values($byCheck)) === 'fail', 'fremder Paketordner im Layout: Fehler mit Pfad');
$check(is_file($foreign.'/probe.txt'), 'fremder Ordner bleibt, wo er ist');
$layoutBefore === null ? delete_option($ms::LAYOUT_OPTION) : update_option($ms::LAYOUT_OPTION, $layoutBefore, true);
@unlink($foreign.'/probe.txt');
@rmdir($foreign);
@rmdir(dirname($foreign));
$fakeId = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}") + 1000;
$wpdb->insert($wpdb->postmeta, ['post_id' => $fakeId, 'meta_key' => \Bootspruefung\Learning\Gate\ProtectedFiles::PATH_META, 'meta_value' => '/gibt/es/nicht/t66.pdf']);
$byCheck = array_column($bc::run(), null, 'check');
$check($byCheck['Geschützte Dateien']['status'] === 'fail' && str_contains($byCheck['Geschützte Dateien']['detail'], '#'.$fakeId), 'fehlende geschützte Datei gemeldet');
$wpdb->delete($wpdb->postmeta, ['post_id' => $fakeId, 'meta_key' => \Bootspruefung\Learning\Gate\ProtectedFiles::PATH_META]);

echo "Kleinere Code-Punkte (T105)\n";
// Rücksprung-URL bei WordPress im Unterordner: Pfad relativ zum home_url-Pfad, Unterordner nicht doppelt.
add_filter('home_url', $homeSub = static fn ($url, $path) => 'https://example.test/blog'.($path === '' ? '' : '/'.ltrim((string) $path, '/')), 99, 2);
$raf = \Bootspruefung\Learning\Frontend\ReturnAfterLogin::class;
$check($raf::currentUrl('/blog/bootsscheine/gruppe/abc/?x=1') === 'https://example.test/blog/bootsscheine/gruppe/abc/?x=1', 'Unterordner: /blog/bootsscheine/… bleibt /blog/bootsscheine/… (nicht /blog/blog/…)');
$check($raf::currentUrl('/blog') === 'https://example.test/blog/' && $raf::currentUrl('/blogger/x/') === 'https://example.test/blog/blogger/x/', 'Startseite des Unterordners; ähnlich benannter Pfad wird nicht gekürzt');
remove_filter('home_url', $homeSub, 99);
$check($raf::currentUrl('/bootsscheine/?a=b') === home_url('/bootsscheine/').'?a=b', 'ohne Unterordner unverändert');
$check(str_contains((string) file_get_contents(WP_PLUGIN_DIR.'/bootspruefung-learning/templates/app.php'), 'ReturnAfterLogin::currentUrl()'), 'Lernbereich (Gäste): Anmelde-Rücksprung über currentUrl()');
// Dateiauslieferung: Ausgabepuffer geleert, Dateiname mit ASCII-Ersatz und UTF-8.
$pf = \Bootspruefung\Learning\Gate\ProtectedFiles::class;
$level = ob_get_level();
ob_start();
ob_start();
echo 'Störausgabe';
$pf::clearOutputBuffers();
$cleared = ob_get_level() === 0;
for ($i = 0; $i < $level; $i++) {
    ob_start(); // Ausgangszustand des Testlaufs wiederherstellen
}
$check($cleared, 'Ausgabepuffer vor der Datei vollständig geleert');
$cd = $pf::contentDisposition('inline', 'Prüfungsbogen Küste "1".pdf');
$check($cd === 'inline; filename="Prufungsbogen_Kuste_1_.pdf"; filename*=UTF-8\'\''.rawurlencode('Prüfungsbogen Küste "1".pdf'), 'Content-Disposition mit filename und filename*=UTF-8 (Umlaute, Anführungszeichen kodiert)');
$check(\Bootspruefung\Learning\Mobile\Controllers\FileController::contentDisposition('attachment', 'ä.json') === $pf::contentDisposition('attachment', 'ä.json'), 'App und Web nutzen dieselbe Kopfzeile');
// Speicherordner-Umzug nur, wenn etwas ansteht (nicht bei jedem Admin-Aufruf).
$ms = \Bootspruefung\Learning\Content\Media\MediaStorage::class;
$ms::relocateAll(true);
$check(! $ms::relocationPending() && has_action('admin_init', [$ms, 'relocateIfPending']) !== false && has_action('admin_init', [$ms, 'relocateAll']) === false, 'Ordner am Soll-Ort: admin_init prüft nur die Optionen');
$fsChecks = 0;
$countLayoutReads = static function ($pre, $option) use (&$fsChecks) {
    $fsChecks++;

    return $pre;
};
$layoutT105 = $ms::layout();
add_filter('pre_option_'.$ms::LAYOUT_OPTION, $countLayoutReads, 10, 2);
$ms::relocateIfPending();
remove_filter('pre_option_'.$ms::LAYOUT_OPTION, $countLayoutReads, 10);
$check($ms::layout() === $layoutT105 && $fsChecks === 1, 'kein Umzug fällig: nichts geschrieben, nur das Layout gelesen');
$pendingLayout = $layoutT105;
unset($pendingLayout['media_checked']);
update_option($ms::LAYOUT_OPTION, $pendingLayout, true);
$check($ms::relocationPending(), 'ausstehende Medienprüfung wird erkannt');
$ms::relocateIfPending();
$check(! empty($ms::layout()['media_checked']) && ! $ms::relocationPending(), 'ausstehender Schritt im Admin nachgeholt');

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
