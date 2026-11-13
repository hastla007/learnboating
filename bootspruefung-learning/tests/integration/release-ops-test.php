<?php

declare(strict_types=1);

/*
 * Integrationstest Versionsprüfung und Website-Zustand (To-do T117, T127):
 * - „Auf neue Versionen prüfen“: Standard aus (keine Anfrage), höchstens einmal am Tag im stündlichen Lauf, Quelle
 *   per Filter (nur https), Auswertung der Releases-API (Tag, Entwurf/Vorabversion, 404), Ergebnis im Systemstatus
 *   und als Admin-Hinweis (Sicherheitsupdate als Warnung), keine Website-Daten in der Anfrage, Ausschalten verwirft.
 * - Website-Zustand: Abschnitt „Bootsprüfung“ in debug_information ohne Geheimnisse/Pfade, direkte Tests
 *   „Migration“ und „Speicher“ ohne Anfragen nach außen, Vorschlag für die Datenschutzerklärung.
 * HTTP wird nie wirklich ausgeführt: pre_http_request beantwortet bzw. blockiert jede Anfrage.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/release-ops-test.php
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
require_once ABSPATH.'wp-admin/includes/admin.php';

use Bootspruefung\Learning\Admin\Notices;
use Bootspruefung\Learning\Admin\SiteHealth;
use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Infrastructure\Cron;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Infrastructure\UpdateCheck;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
wp_set_current_user(1);
$settingsBefore = get_option(Settings::OPTION, null);
$stateBefore = get_option(UpdateCheck::OPTION, null);
$setEnabled = static function (bool $on): void {
    $s = get_option(Settings::OPTION, []);
    $s = is_array($s) ? $s : [];
    $s['update_check_enabled'] = $on;
    update_option(Settings::OPTION, $s);
};
$setEnabled(false);
delete_option(UpdateCheck::OPTION);

// Jede HTTP-Anfrage abfangen: Antwort aus $reply, Aufrufe in $calls.
$calls = [];
$reply = null;
$http = static function ($pre, array $args, string $url) use (&$calls, &$reply) {
    $calls[] = ['url' => $url, 'args' => $args];
    if ($reply instanceof WP_Error) {
        return $reply;
    }

    return ['headers' => [], 'body' => (string) ($reply['body'] ?? ''), 'response' => ['code' => (int) ($reply['code'] ?? 200), 'message' => ''], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $http, 10, 3);
$release = static fn (string $tag, array $extra = []): array => ['code' => 200, 'body' => (string) wp_json_encode($extra + [
    'tag_name' => $tag, 'name' => $tag, 'html_url' => 'https://github.com/hastla007/learnboating/releases/tag/'.$tag,
    'draft' => false, 'prerelease' => false, 'body' => 'Änderungen …',
])];

echo "Auf neue Versionen prüfen: Standard aus (T117)\n";
$check(Settings::sanitize([])['update_check_enabled'] === false && Settings::sanitize(['update_check_enabled' => '1'])['update_check_enabled'] === true && Settings::sanitize(['update_check_enabled' => '0'])['update_check_enabled'] === false, 'Einstellung: Standard aus, an/aus speicherbar');
$check(UpdateCheck::enabled() === false && UpdateCheck::maybeRun() === false && $calls === [], 'aus: keine Anfrage nach außen');
$check(has_action(Cron::HOURLY) !== false && str_contains(UpdateCheck::statusLine(), 'Prüfung aus'), 'stündlicher Lauf registriert; Systemstatus: „Prüfung aus“');
do_action(Cron::HOURLY);
$check(array_filter($calls, static fn ($c) => str_contains($c['url'], 'api.github.com')) === [], 'stündlicher Wartungslauf fragt bei „aus“ nicht');
$calls = [];
ob_start();
UpdateCheck::renderSettingsSection(Settings::all());
$section = (string) ob_get_clean();
$check(str_contains($section, 'name="'.Settings::OPTION.'[update_check_enabled]"') && str_contains($section, 'Auf neue Versionen prüfen') && ! str_contains($section, 'checked') && str_contains($section, 'api.github.com'), 'Einstellungen › Betrieb: Häkchen nicht gesetzt, Host genannt');
ob_start();
(new \Bootspruefung\Learning\Admin\Menu(Plugin::instance()))->renderSettings();
$page = (string) ob_get_clean();
$form = substr($page, (int) strpos($page, 'action="options.php"'), (int) strpos($page, 'id="bp-set-security"') - (int) strpos($page, 'action="options.php"'));
$check(str_contains($page, 'href="#bp-set-operations"') && str_contains($form, 'id="bp-set-operations"') && str_contains($form, '[update_check_enabled]'), 'Einstellungsseite: Bereich „Betrieb“ im gespeicherten Formular, Sprungmarke oben');
$storageBefore = get_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
$reply = new WP_Error('http_request_failed', 'blockiert');
ob_start();
(new \Bootspruefung\Learning\Admin\Menu(Plugin::instance()))->renderStatus();
$page = (string) ob_get_clean();
$check(str_contains($page, 'Neue Version') && str_contains($page, 'Prüfung aus') && array_filter($calls, static fn ($c) => str_contains($c['url'], 'api.github.com')) === [], 'Systemstatus: Zeile „Neue Version“, keine Anfrage an GitHub');
is_array($storageBefore) ? set_transient(SystemStatus::STORAGE_TEST_TRANSIENT, $storageBefore, HOUR_IN_SECONDS) : delete_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
$calls = [];
$reply = null;

echo "Abfrage höchstens einmal am Tag\n";
$setEnabled(true);
$reply = $release('v99.0.0');
$now = time();
$check(UpdateCheck::maybeRun($now) === true && count($calls) === 1 && $calls[0]['url'] === UpdateCheck::DEFAULT_SOURCE, 'an: eine Anfrage an die Releases-API');
$ua = (string) ($calls[0]['args']['user-agent'] ?? '');
$check($ua !== '' && ! str_contains($ua, (string) wp_parse_url(home_url(), PHP_URL_HOST)) && ! str_contains($ua, 'WordPress/') && ($calls[0]['args']['method'] ?? 'GET') === 'GET' && empty($calls[0]['args']['body']), 'keine Website-Daten: eigene Kennung ohne Adresse, GET ohne Inhalt');
$state = UpdateCheck::state();
$check($state['latest'] === '99.0.0' && $state['error'] === null && $state['checked_at'] === $now && ! $state['security'], 'Ergebnis gespeichert: 99.0.0');
$check(UpdateCheck::maybeRun($now + 3600) === false && UpdateCheck::maybeRun($now + UpdateCheck::INTERVAL - 1) === false && count($calls) === 1, 'innerhalb von 24 Stunden keine weitere Anfrage');
$reply = new WP_Error('http_request_failed', 'Verbindung abgelehnt');
$check(UpdateCheck::maybeRun($now + UpdateCheck::INTERVAL) === true && count($calls) === 2, 'nach 24 Stunden erneut');
$state = UpdateCheck::state();
$check($state['error'] === 'Verbindung abgelehnt' && $state['latest'] === '99.0.0' && $state['checked_at'] === $now + UpdateCheck::INTERVAL, 'Fehler: gespeichert (zählt als Prüfung), letzter Stand bleibt');
$check(UpdateCheck::maybeRun($now + UpdateCheck::INTERVAL + 60) === false && count($calls) === 2, 'nach einem Fehler kein stündlicher Neuversuch');

echo "Systemstatus und Admin-Hinweis\n";
$reply = $release('v99.0.0');
UpdateCheck::run($now);
$line = UpdateCheck::statusLine();
$check(str_contains($line, '99.0.0 verfügbar') && str_contains($line, 'https://github.com/hastla007/learnboating/releases/tag/v99.0.0') && str_contains($line, 'nichts wird automatisch installiert'), 'Zeile „Neue Version“: Version und Release-Seite ('.$line.')');
$args = UpdateCheck::noticeArgs();
$check(is_array($args) && $args['type'] === 'info' && $args['dismiss'] === 'forever' && $args['version'] === '99-0-0' && $args['buttons'][0][1] === 'https://github.com/hastla007/learnboating/releases/tag/v99.0.0' && str_contains($args['text'], 'nichts automatisch installiert'), 'Hinweis: Info, wegklickbar bis zur nächsten Version, Link zur Release-Seite');
$html = Notices::render($args);
$check(str_contains($html, 'Bootsprüfung:') && str_contains($html, 'Release-Seite ansehen') && str_contains($html, Plugin::VERSION), 'Hinweis über die gemeinsame Notices-Klasse');
$flush = static function (): string {
    ob_start();
    Notices::flush();

    return (string) ob_get_clean();
};
$flush();
set_current_screen('dashboard');
UpdateCheck::notice();
$check(isset(Notices::queued()[UpdateCheck::NOTICE_KEY]), 'Dashboard: Hinweis vorgemerkt');
$flush();
set_current_screen('edit-post');
UpdateCheck::notice();
$check(! isset(Notices::queued()[UpdateCheck::NOTICE_KEY]), 'Beitragsliste: kein Hinweis');
set_current_screen('dashboard');
wp_set_current_user(0);
UpdateCheck::notice();
$check(! isset(Notices::queued()[UpdateCheck::NOTICE_KEY]), 'ohne Rechte: kein Hinweis');
wp_set_current_user(1);
$GLOBALS['current_screen'] = null;
$flush();

$reply = $release('v99.0.1', ['body' => "Sicherheitsupdate: Anmeldung …\n"]);
UpdateCheck::run($now);
$args = UpdateCheck::noticeArgs();
$check(UpdateCheck::state()['security'] && is_array($args) && $args['type'] === 'warning' && $args['dismiss'] === 'snooze' && $args['snooze_days'] === 1 && str_contains($args['title'], 'Sicherheitsupdate') && str_contains(UpdateCheck::statusLine(), 'Sicherheitsupdate'), 'Sicherheitsupdate: Warnung, nur einen Tag vertagbar');

$reply = $release('v0.1.0');
UpdateCheck::run($now);
$check(UpdateCheck::available() === null && UpdateCheck::noticeArgs() === null && str_contains(UpdateCheck::statusLine(), Plugin::VERSION.' ist aktuell'), 'ältere/gleiche Version: kein Hinweis, „aktuell“');
$reply = $release('v'.Plugin::VERSION);
UpdateCheck::run($now);
$check(UpdateCheck::available() === null, 'gleiche Version: kein Hinweis');

echo "Auswertung der Antwort\n";
$check(is_wp_error(UpdateCheck::parse(404, '{}')) && is_wp_error(UpdateCheck::parse(500, '')) && is_wp_error(UpdateCheck::parse(200, 'kein json')), '404, 500, kein JSON: Fehler');
$check(is_wp_error(UpdateCheck::parse(200, (string) wp_json_encode(['tag_name' => 'v1.0.0', 'prerelease' => true]))) && is_wp_error(UpdateCheck::parse(200, (string) wp_json_encode(['tag_name' => 'v1.0.0', 'draft' => true]))), 'Entwurf und Vorabversion zählen nicht');
$check(is_wp_error(UpdateCheck::parse(200, (string) wp_json_encode(['tag_name' => 'latest']))) && UpdateCheck::normalizeVersion('v0.15.0') === '0.15.0' && UpdateCheck::normalizeVersion('1.0.0-rc.1') === '1.0.0-rc.1' && UpdateCheck::normalizeVersion('v1.0') === null, 'Tag v<Version> erkannt, sonst Fehler');
$p = UpdateCheck::parse(200, (string) wp_json_encode(['tag_name' => 'v1.2.3', 'html_url' => 'javascript:alert(1)']), UpdateCheck::DEFAULT_SOURCE);
$check(is_array($p) && $p['url'] === 'https://github.com/hastla007/learnboating/releases', 'unsichere Release-Adresse ersetzt durch die Release-Übersicht');

echo "Quelle (Konstante/Filter)\n";
$calls = [];
$insecure = static fn (): string => 'http://example.org/releases/latest';
add_filter('bootspruefung_update_source', $insecure);
$state = UpdateCheck::run($now);
$check(UpdateCheck::source() === '' && $calls === [] && $state['error'] !== null, 'http statt https: keine Anfrage, Fehler gespeichert');
remove_filter('bootspruefung_update_source', $insecure);
$own = static fn (): string => 'https://updates.example.org/learnboating/latest.json';
add_filter('bootspruefung_update_source', $own);
$reply = $release('v99.1.0', ['html_url' => '']);
UpdateCheck::run($now);
$check(($calls[0]['url'] ?? '') === 'https://updates.example.org/learnboating/latest.json' && UpdateCheck::state()['latest'] === '99.1.0' && UpdateCheck::state()['url'] === null, 'eigene Quelle per Filter bootspruefung_update_source');
remove_filter('bootspruefung_update_source', $own);

echo "Ausschalten\n";
$setEnabled(false);
$check(get_option(UpdateCheck::OPTION, null) === null && UpdateCheck::available() === null && UpdateCheck::noticeArgs() === null, 'aus: gespeichertes Ergebnis verworfen, kein Hinweis');

echo "Website-Zustand: debug_information (T127)\n";
$info = apply_filters('debug_information', []);
$section = $info[SiteHealth::SECTION] ?? null;
$check(is_array($section) && $section['label'] === 'Bootsprüfung' && isset($section['fields']['version'], $section['fields']['schema'], $section['fields']['migration'], $section['fields']['cron_next'], $section['fields']['storage'], $section['fields']['default_access'], $section['fields']['update_check']), 'Abschnitt „Bootsprüfung“ mit Versionen, Schema, Migration, Cron, Speicher, Zugang, Versionsprüfung');
$values = is_array($section) ? implode("\n", array_map(static fn ($f) => (string) $f['value'].' '.(string) ($f['debug'] ?? ''), $section['fields'])) : '';
$secretDirs = [\Bootspruefung\Learning\Content\Media\MediaStorage::targetDir('packages'), \Bootspruefung\Learning\Content\Media\MediaStorage::targetDir('protected')];
$check(is_array($section) && $section['fields']['version']['value'] === Plugin::VERSION && ! str_contains($values, basename($secretDirs[0])) && ! str_contains($values, basename($secretDirs[1])) && ! str_contains($values, ABSPATH) && ! str_contains($values, WP_CONTENT_DIR), 'keine Pfade bzw. Ordnergeheimnisse in den Werten');
$check(is_array($section) && array_filter($section['fields'], static fn ($f) => ! is_string($f['value'])) === [], 'alle Werte als Text');

echo "Website-Zustand: direkte Tests ohne Anfragen nach außen\n";
$tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
$check(isset($tests['direct']['bootspruefung_migration'], $tests['direct']['bootspruefung_storage']) && ! isset($tests['async']['bootspruefung_storage']), 'Tests „Migration“ und „Speicher“ als direct');
$calls = [];
$schemaBefore = get_option(Migrator::OPTION_VERSION);
$failedBefore = get_option(Plugin::OPTION_MIGRATION_FAILED, null);
delete_option(Plugin::OPTION_MIGRATION_FAILED);
$r = SiteHealth::testMigration();
$check($r['status'] === 'good' && $r['test'] === 'bootspruefung_migration' && str_contains($r['actions'], 'bootspruefung-status'), 'Migration aktuell: gut, Link zum Systemstatus');
update_option(Migrator::OPTION_VERSION, '1.0.0');
$check(SiteHealth::testMigration()['status'] === 'recommended', 'Migration fällig: empfohlen');
update_option(Plugin::OPTION_MIGRATION_FAILED, ['at' => time(), 'message' => 'Testfehler <b>x</b>'], false);
$r = SiteHealth::testMigration();
$check($r['status'] === 'critical' && str_contains($r['description'], 'Testfehler &lt;b&gt;x&lt;/b&gt;') && $r['badge']['color'] === 'red', 'Migration fehlgeschlagen: kritisch, Meldung maskiert');
update_option(Migrator::OPTION_VERSION, $schemaBefore);
$failedBefore === null ? delete_option(Plugin::OPTION_MIGRATION_FAILED) : update_option(Plugin::OPTION_MIGRATION_FAILED, $failedBefore, false);

$storageBefore = get_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
set_transient(SystemStatus::STORAGE_TEST_TRANSIENT, ['checked_at' => time(), 'overall' => 'exposed', 'dirs' => []], 60);
$check(SiteHealth::testStorage()['status'] === 'critical', 'Selbsttest „direkt abrufbar“: kritisch');
set_transient(SystemStatus::STORAGE_TEST_TRANSIENT, ['checked_at' => time(), 'overall' => 'unknown', 'dirs' => []], 60);
$check(SiteHealth::testStorage()['status'] === 'recommended', 'Selbsttest „nicht prüfbar“: empfohlen');
set_transient(SystemStatus::STORAGE_TEST_TRANSIENT, ['checked_at' => time(), 'overall' => 'ok', 'dirs' => []], 60);
$check(SiteHealth::testStorage()['status'] === 'good', 'Selbsttest „gesperrt“: gut');
delete_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
$r = SiteHealth::testStorage();
$check(in_array($r['status'], ['good', 'recommended'], true) && in_array(SiteHealth::storageState()['status'], ['private', 'unchecked'], true), 'ohne gespeicherten Selbsttest: „außerhalb des Webroots“ bzw. „noch nicht geprüft“ ('.SiteHealth::storageState()['status'].')');
SiteHealth::fields();
$check($calls === [], 'Tests und Abschnitt lösen keine HTTP-Anfrage aus');
is_array($storageBefore) ? set_transient(SystemStatus::STORAGE_TEST_TRANSIENT, $storageBefore, HOUR_IN_SECONDS) : delete_transient(SystemStatus::STORAGE_TEST_TRANSIENT);

echo "Datenschutzerklärung: Vorschlagstext\n";
$check(has_action('admin_init', [SiteHealth::class, 'privacyPolicyContent']) !== false, 'wp_add_privacy_policy_content bei admin_init');
$text = SiteHealth::privacyPolicyText();
$check(str_contains($text, 'keine Rechtsberatung') && str_contains($text, 'Lernstand') && str_contains($text, 'App-Sitzungen') && str_contains($text, '90 Tagen') && str_contains($text, 'bp_known_device') && str_contains($text, '180 Tage'), 'Lernstand, App-Sitzungen (90 Tage), Cookie „bekanntes Gerät“ (180 Tage)');
$check(str_contains($text, 'nach 4 Jahren gelöscht') && str_contains($text, 'Zahlungsdaten und IP-Adressen stehen nicht im Protokoll'), 'Protokoll: 4 Jahre, ohne Zahlungsdaten und IP-Adressen');
$s = get_option(Settings::OPTION, []);
$s = is_array($s) ? $s : [];
update_option(Settings::OPTION, ['expiry_reminder_enabled' => true, 'expiry_reminder_days' => 21] + $s);
$check(str_contains(SiteHealth::privacyPolicyText(), 'etwa 21 Tage vorher'), 'Ablauf-Erinnerung mit eingestellten Tagen');
update_option(Settings::OPTION, ['expiry_reminder_enabled' => false] + $s);
$check(! str_contains(SiteHealth::privacyPolicyText(), 'Erinnerung vor Ablauf'), 'Ablauf-Erinnerung aus: Abschnitt entfällt');
$check(! preg_match('#https?://#', $text), 'keine externen Links im Vorschlag');

// Aufräumen
remove_filter('pre_http_request', $http, 10);
$settingsBefore === null ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $settingsBefore);
$stateBefore === null ? delete_option(UpdateCheck::OPTION) : update_option(UpdateCheck::OPTION, $stateBefore, false);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
