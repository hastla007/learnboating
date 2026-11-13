<?php

declare(strict_types=1);

/*
 * Integrationstest Sicherheit (Expertenrunde 3: ARC-01, SEC-01, SEC-02, SEC-03, SEC-05):
 *  - Ablage: Paket- und Schutzordner mit Zufallsgeheimnis, Umzug alter Ordner (packages/ → packages-<geheim>/),
 *    Rückfall, wenn das Verschieben scheitert, Umzug in einen privaten Ordner außerhalb des Webroots und zurück;
 *  - Medien-Import: nur erlaubte Dateitypen, Ablehnung im Import-Protokoll;
 *  - Selbsttest der Statusseite (Testdatei direkt abrufbar → Warnung mit nginx-Regel, Transient);
 *  - Login: Sperre nach N Fehlversuchen (je Konto + IP, je IP), neutrale Meldungen, gemeinsame Zähler mit der App,
 *    „Passwort vergessen“ neutral, XML-RPC aus, keine Benutzer-Sitemap, Autorenarchive und /wp/v2/users für Gäste weg.
 *
 *   WP_DIR=/pfad/zu/wordpress [PACKAGE_DIR=build/<package_id>] php tests/integration/security-test.php
 *
 * Ohne PACKAGE_DIR entfällt nur der Paket-Teil des Medien-Imports.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$packageDir = rtrim((string) getenv('PACKAGE_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';

use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Gate\ProtectedFiles;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Security\Hardening;
use Bootspruefung\Learning\Security\LoginGuard;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
/** Fängt Weiterleitungen ab (wp_safe_redirect + exit) und gibt das Ziel zurück, sonst null. */
$redirectOf = static function (callable $fn): ?string {
    $catch = static function ($location): void {
        throw new RuntimeException('REDIRECT:'.$location);
    };
    add_filter('wp_redirect', $catch, 0);
    try {
        $fn();
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'REDIRECT:')) {
            return substr($e->getMessage(), 9);
        }
        throw $e;
    } finally {
        remove_filter('wp_redirect', $catch, 0);
    }

    return null;
};
global $wpdb;
$p = $wpdb->prefix.'bp_';
$base = MediaStorage::baseDir();
$tmpRoot = (is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir()).'/bp-sec-test-'.wp_generate_password(8, false);

echo "Ablage: Ordner mit Geheimnis\n";
$packages = MediaStorage::packagesDir();
$protected = MediaStorage::protectedDir();
$check((bool) preg_match('#^packages-[A-Za-z0-9_-]{16,}$#', basename($packages)) && dirname($packages) === $base, 'Paketordner uploads/bootspruefung/packages-<Geheimnis> (nicht erratbar)');
$check((bool) preg_match('#^protected-[A-Za-z0-9_-]{16,}$#', basename($protected)), 'Schutzordner protected-<Geheimnis>');
$check(! is_dir($base.'/packages'), 'kein Ordner unter dem alten, erratbaren Pfad packages/ (batch-N)');
$htaccess = (string) @file_get_contents($packages.'/.htaccess');
$check(str_contains($htaccess, 'Require all denied') && str_contains($htaccess, '<IfModule mod_authz_core.c>') && is_file($packages.'/index.php'), '.htaccess mit Apache-2.4/2.2-Weiche und index.php im Paketordner');
$check(str_contains((string) @file_get_contents(MediaStorage::mediaDir().'/.htaccess'), 'FilesMatch'), 'Medienordner: .htaccess sperrt Skripte (php, html, svg …)');
$service = Plugin::instance()->importService();
$batchIds = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}import_batch ORDER BY id DESC LIMIT 50"));
$withFiles = array_values(array_filter($batchIds, static fn (int $id): bool => is_file($packages.'/batch-'.$id.'/manifest.json')));

echo "Umzug bestehender Installationen\n";
$marker = $packages.'/batch-sec-test/data/questions.json';
wp_mkdir_p(dirname($marker));
file_put_contents($marker, '{"geheim":true}');
$legacy = $base.'/packages';
rename($packages, $legacy); // Stand vor dem Update: packages/ ohne Geheimnis, kein Layout
delete_option(MediaStorage::LAYOUT_OPTION);
$after = MediaStorage::packagesDir();
$check($after === $packages && ! is_dir($legacy) && is_file($marker) && (string) file_get_contents($marker) === '{"geheim":true}', 'alter Ordner packages/ wird nach packages-<Geheimnis>/ verschoben, Inhalt vollständig');
$check(($withFiles === [] || $service->package($withFiles[0])->manifest() !== []), 'Import/Rollback finden die Paketdateien nach dem Umzug ('.($withFiles === [] ? 'keine Pakete in der Test-DB' : 'Import #'.$withFiles[0]).')');
$check((MediaStorage::layout()['packages'] ?? null) === $packages, 'Layout-Option merkt den neuen Ordner');

echo "Rückfall, wenn das Verschieben scheitert\n";
rename($packages, $legacy);
delete_option(MediaStorage::LAYOUT_OPTION);
file_put_contents($packages, 'blockiert'); // Datei statt Ordner am Ziel → Umbenennen und Kopieren scheitern
$fallback = MediaStorage::packagesDir();
$check($fallback === $legacy && is_file($legacy.'/batch-sec-test/data/questions.json'), 'Verschieben gescheitert: alter Ordner bleibt in Gebrauch, keine Daten verloren');
$check(($withFiles === [] || $service->package($withFiles[0])->manifest() !== []), 'Import findet seine Dateien auch im Rückfall');
$check(str_contains((string) (MediaStorage::layout()['errors']['packages'] ?? ''), 'konnte nicht'), 'Fehler für den Systemstatus vermerkt');
unlink($packages);
$check(MediaStorage::packagesDir() === $legacy, 'innerhalb der Wartezeit kein erneuter Versuch bei jedem Aufruf');
MediaStorage::relocateAll(true);
$check(MediaStorage::packagesDir() === $packages && is_file($marker) && ! is_dir($legacy) && empty(MediaStorage::layout()['errors']['packages']), '„Erneut prüfen“: Umzug nachgeholt, Fehler gelöscht');
ImportService::removeDir(dirname($marker, 2));

echo "Privater Ordner außerhalb des Webroots (BOOTSPRUEFUNG_PRIVATE_DIR)\n";
$uploads = wp_upload_dir();
$pdf = $uploads['path'].'/bp-sec-'.wp_generate_password(6, false).'.pdf';
file_put_contents($pdf, "%PDF-1.4\n% Sicherheitstest\n");
$att = wp_insert_attachment(['post_title' => 'Sicherheitstest-PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $pdf);
update_post_meta($att, '_wp_attached_file', _wp_relative_upload_path($pdf));
$check(ProtectedFiles::protect((int) $att, 'SBF-SEE', 'downloads'), 'PDF geschützt (im Upload-Ordner)');
$oldPath = (string) get_post_meta($att, ProtectedFiles::PATH_META, true);
$privateFilter = static fn (): string => $tmpRoot;
add_filter('bootspruefung_private_dir', $privateFilter);
$privProtected = MediaStorage::protectedDir();
$newPath = (string) get_post_meta($att, ProtectedFiles::PATH_META, true);
$check(str_starts_with($privProtected, $tmpRoot.'/protected-') && ! is_dir($protected), 'Schutzordner in den privaten Ordner verschoben (auch über Dateisystemgrenzen: kopieren, dann löschen)');
$check($newPath !== $oldPath && str_starts_with($newPath, $privProtected.'/') && is_file($newPath) && get_attached_file($att) === $newPath, 'Pfad des geschützten Anhangs umgeschrieben, Datei vorhanden');
$check(MediaStorage::publicUrl($newPath) === null, 'außerhalb des Webroots: keine öffentliche URL');
$requests = 0;
$countRequests = static function ($pre) use (&$requests) {
    $requests++;

    return new WP_Error('http_request_failed', 'Testumgebung');
};
add_filter('pre_http_request', $countRequests, 10, 1);
$private = SystemStatus::storageSelfTest(true);
$check($private['overall'] === 'ok' && $private['dirs']['protected']['status'] === 'private' && $private['dirs']['packages']['status'] === 'private' && $requests === 0, 'Selbsttest: privat (keine Anfrage nötig)');
remove_filter('pre_http_request', $countRequests, 10);
remove_filter('bootspruefung_private_dir', $privateFilter);
MediaStorage::relocateAll();
$backPath = (string) get_post_meta($att, ProtectedFiles::PATH_META, true);
$check(MediaStorage::protectedDir() === $protected && MediaStorage::packagesDir() === $packages && is_file($backPath) && str_starts_with($backPath, $protected.'/'), 'Konstante entfernt: Ordner zurück in den Upload-Ordner, Pfade wieder umgeschrieben');
ProtectedFiles::unprotect((int) $att);
wp_delete_attachment((int) $att, true);
ImportService::removeDir($tmpRoot);

echo "Medien-Import: nur erlaubte Dateitypen (SEC-03)\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
wp_mkdir_p($tmpRoot.'-dateien');
$tmpPng = $tmpRoot.'-dateien/bild.png';
file_put_contents($tmpPng, $png);
$tmpPhp = $tmpRoot.'-dateien/x.php';
file_put_contents($tmpPhp, "<?php echo 'SEC-RCE-MARKER';\n");
$check(MediaStorage::mediaTypeError('media/elwis/bild.png', 'image/png', $tmpPng) === null, 'PNG mit image/png erlaubt');
$check(MediaStorage::mediaTypeError('media/x.php', 'image/png') !== null, '.php abgelehnt (auch mit angeblichem image/png)');
$check(MediaStorage::mediaTypeError('media/x.php.png', 'image/png') !== null, 'doppelte Endung x.php.png abgelehnt');
$check(MediaStorage::mediaTypeError('media/x.svg', 'image/svg+xml') !== null && MediaStorage::mediaTypeError('media/x.html', 'text/html') !== null, 'SVG und HTML abgelehnt (Skripte auf der eigenen Domain)');
$check(MediaStorage::mediaTypeError('media/a/.htaccess', 'text/plain') !== null, 'versteckte Datei (.htaccess) abgelehnt');
$check(MediaStorage::mediaTypeError('media/x.png', 'image/png', $tmpPhp) !== null, 'PHP-Inhalt mit Endung .png abgelehnt (Inhalt geprüft)');
$check(MediaStorage::mediaTypeError('media/x.png', 'text/html') !== null, 'angegebener MIME-Typ passt nicht zur Endung → abgelehnt');
$threw = false;
try {
    MediaStorage::store($tmpPhp, 'media/sectest/sec-test.php', hash_file('sha256', $tmpPhp));
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'nicht erlaubt');
}
$check($threw && ! file_exists(MediaStorage::mediaDir().'/sectest/sec-test.php'), 'MediaStorage::store() schreibt kein PHP in den öffentlichen Medienordner');
// Altbestand: unerlaubte Datei im Medienordner wird beim Umzug einmalig in die Quarantäne verschoben.
wp_mkdir_p(MediaStorage::mediaDir().'/sectest');
copy($tmpPhp, MediaStorage::mediaDir().'/sectest/alt.php');
$layout = MediaStorage::layout();
unset($layout['media_checked']);
update_option(MediaStorage::LAYOUT_OPTION, $layout);
MediaStorage::relocateAll();
$check(! file_exists(MediaStorage::mediaDir().'/sectest/alt.php') && is_file($packages.'/quarantine/sectest/alt.php') && ! empty(MediaStorage::layout()['media_checked']), 'Altbestand: .php aus dem Medienordner in die Quarantäne verschoben (nicht gelöscht)');
ImportService::removeDir($packages.'/quarantine');
ImportService::removeDir(MediaStorage::mediaDir().'/sectest');

if ($packageDir !== '' && is_file($packageDir.'/manifest.json')) {
    $evilDir = $tmpRoot.'-paket';
    wp_mkdir_p($evilDir);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
        $dest = $evilDir.'/'.substr($file->getPathname(), strlen($packageDir) + 1);
        $file->isDir() ? wp_mkdir_p($dest) : copy($file->getPathname(), $dest);
    }
    copy($tmpPhp, $evilDir.'/media/x.php');
    $media = json_decode((string) file_get_contents($evilDir.'/data/media.json'), true);
    $evil = $media[0];
    $evil['source_id'] = 'sec/x.php';
    $evil['path'] = 'media/x.php';
    $evil['mime_type'] = 'image/png';
    $evil['size'] = filesize($evilDir.'/media/x.php');
    $evil['sha256'] = hash_file('sha256', $evilDir.'/media/x.php');
    $media[] = $evil;
    file_put_contents($evilDir.'/data/media.json', wp_json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $manifest = json_decode((string) file_get_contents($evilDir.'/manifest.json'), true);
    foreach ($manifest['files'] as &$entry) {
        if ($entry['path'] === 'data/media.json') {
            $entry['size'] = filesize($evilDir.'/data/media.json');
            $entry['sha256'] = hash_file('sha256', $evilDir.'/data/media.json');
        }
    }
    unset($entry);
    $manifest['files'][] = ['path' => 'media/x.php', 'size' => filesize($evilDir.'/media/x.php'), 'mime_type' => 'image/png', 'sha256' => hash_file('sha256', $evilDir.'/media/x.php')];
    file_put_contents($evilDir.'/manifest.json', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    wp_set_current_user(1);
    $evilBatch = $service->createFromDirectory($evilDir);
    $batch = $service->batch($evilBatch);
    $rejected = $service->logs($evilBatch, 'rejected');
    $check($batch->status === 'failed' && str_contains((string) $batch->error, 'Medium nicht erlaubt: media/x.php'), 'Paket mit media/x.php: Prüfung schlägt fehl, Fehler nennt die Datei');
    $check(count($rejected) === 1 && $rejected[0]->source_id === 'media/x.php' && $rejected[0]->entity === 'media' && $rejected[0]->message !== '', 'Import-Protokoll: Eintrag „abgelehnt“ für media/x.php');
    $service->validate($evilBatch);
    $check(count($service->logs($evilBatch, 'rejected')) === 1, 'erneute Prüfung: Protokolleintrag nicht doppelt');
    $service->discard($evilBatch);
    ImportService::removeDir($evilDir);
} else {
    echo "  (PACKAGE_DIR fehlt: Paket-Teil übersprungen)\n";
}
ImportService::removeDir($tmpRoot.'-dateien');

echo "Selbsttest Statusseite (SEC-05)\n";
delete_transient(SystemStatus::STORAGE_TEST_TRANSIENT);
$requests = 0;
$requested = [];
// Simuliert einen Webserver ohne .htaccess (nginx): liefert die Datei aus dem Upload-Ordner aus.
$exposeServer = static function ($pre, $args, $url) use (&$requests, &$requested, $uploads) {
    $requests++;
    $requested[] = $url;
    $path = str_replace(trailingslashit($uploads['baseurl']), trailingslashit($uploads['basedir']), rawurldecode((string) $url));

    return is_file($path)
        ? ['headers' => [], 'body' => (string) file_get_contents($path), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]
        : ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $exposeServer, 10, 3);
$legacyDrop = \Bootspruefung\Learning\Content\Import\ImportSources::legacyDropDir();
if (is_dir($legacyDrop)) {
    \Bootspruefung\Learning\Content\Import\ImportService::removeDir($legacyDrop);
}
$result = SystemStatus::storageSelfTest();
$check($result['overall'] === 'exposed' && $result['dirs']['packages']['status'] === 'exposed' && $result['dirs']['protected']['status'] === 'exposed' && $result['dirs']['import']['status'] === 'exposed' && $requests === 3, 'Testdatei direkt abrufbar → „direkt abrufbar“ (je Ordner eine Anfrage)');
$check(count($requested) === 3 && str_contains($requested[0], '/bootspruefung/packages-') && str_contains($requested[1], '/bootspruefung/protected-') && preg_match('#/bootspruefung/packages-[^/]+/import/bp-selftest-#', $requested[2]) === 1, 'angefragt wird eine Testdatei im Paket-, Schutz- und Ablageordner (T108)');
$check(glob($packages.'/bp-selftest-*') === [] && glob($protected.'/bp-selftest-*') === [] && glob($packages.'/import/bp-selftest-*') === [], 'Testdateien danach wieder gelöscht');
SystemStatus::storageSelfTest();
$check($requests === 3, 'Ergebnis zwischengespeichert (Transient): keine neue Anfrage beim nächsten Seitenaufruf');
// Früherer Ablageordner, der sich nicht leeren ließ (gleichnamige Datei im neuen Ordner): wird mitgeprüft.
$dropNow = \Bootspruefung\Learning\Content\Import\ImportSources::dropDir();
file_put_contents($dropNow.'/t108-doppelt.zip', 'neu');
wp_mkdir_p($legacyDrop);
file_put_contents($legacyDrop.'/t108-doppelt.zip', 'alt');
\Bootspruefung\Learning\Content\Import\ImportSources::migrateLegacy(true);
$requests = 0;
$result = SystemStatus::storageSelfTest(true);
$check(isset($result['dirs']['import_legacy']) && $result['dirs']['import_legacy']['status'] === 'exposed' && $requests === 4, 'früherer Ablageordner noch nicht leer: Selbsttest prüft ihn mit');
\Bootspruefung\Learning\Content\Import\ImportService::removeDir($legacyDrop);
unlink($dropNow.'/t108-doppelt.zip');
delete_option(\Bootspruefung\Learning\Content\Import\ImportSources::MOVED_OPTION);
$rule = SystemStatus::nginxRule();
$check(str_contains($rule, 'location ~* ^/wp-content/uploads/bootspruefung/(packages|protected)') && str_contains($rule, 'deny all;'), 'nginx-Regel für diese Installation: '.strtok($rule, "\n"));
$check(str_contains($rule, 'location ~* ^/wp-content/uploads/bootspruefung-import/ {'), 'nginx-Regel sperrt auch den früheren Ablageordner (der neue liegt unter packages-…)');
ob_start();
SystemStatus::renderStorageSelfTest();
$html = (string) ob_get_clean();
$check(str_contains($html, 'notice-error') && str_contains($html, 'deny all;') && str_contains($html, 'BOOTSPRUEFUNG_PRIVATE_DIR'), 'Statusseite: rote Warnung mit nginx-Regel und Alternative');
$check(str_contains($html, 'Ablageordner für den Import') && str_contains($html, 'bootspruefung-import/'), 'Statusseite: Ablageordner in der Tabelle, nginx-Regel mit früherem Ablageordner');
remove_filter('pre_http_request', $exposeServer, 10);
$deny = static fn () => ['headers' => [], 'body' => '<h1>403 Forbidden</h1>', 'response' => ['code' => 403, 'message' => 'Forbidden'], 'cookies' => [], 'filename' => null];
add_filter('pre_http_request', $deny);
$result = SystemStatus::storageSelfTest(true);
$check($result['overall'] === 'ok' && $result['dirs']['packages']['code'] === 403, 'Webserver sperrt (403) → grün');
ob_start();
SystemStatus::renderStorageSelfTest();
$html = (string) ob_get_clean();
$check(str_contains($html, 'notice-success') && ! str_contains($html, 'deny all;'), 'Statusseite: grüner Hinweis ohne Regel');
remove_filter('pre_http_request', $deny);
$fail = static fn () => new WP_Error('http_request_failed', 'cURL error 7');
add_filter('pre_http_request', $fail);
$result = SystemStatus::storageSelfTest(true);
$check($result['overall'] === 'unknown' && str_contains((string) $result['dirs']['packages']['error'], 'cURL'), 'Anfrage nicht möglich → „nicht prüfbar“ (gelb, mit Regel zum manuellen Prüfen)');
remove_filter('pre_http_request', $fail);
delete_transient(SystemStatus::STORAGE_TEST_TRANSIENT);

echo "Login: Fehlversuche begrenzen (SEC-01)\n";
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$pass = 'Sicher-Test-Passwort-789!';
$login = 'sec_test_'.wp_generate_password(6, false, false);
$userId = wp_create_user($login, $pass, $login.'@example.test');
$check(is_int($userId), 'Testkonto angelegt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.71';
$known = wp_authenticate($login, 'falsch-1');
$unknown = wp_authenticate('gibt_es_nicht_'.wp_generate_password(6, false, false), 'falsch');
$unknownMail = wp_authenticate('niemand-'.wp_generate_password(6, false, false).'@example.test', 'falsch');
$check(is_wp_error($known) && is_wp_error($unknown) && is_wp_error($unknownMail) && $known->get_error_code() === 'bp_login_failed' && $known->get_error_message() === $unknown->get_error_message() && $unknown->get_error_message() === $unknownMail->get_error_message(), 'neutrale Meldung: falsches Passwort, unbekannter Name und unbekannte E-Mail gleich');
$check(! str_contains($known->get_error_message(), $login) && str_contains($known->get_error_message(), 'nicht korrekt'), 'Meldung nennt den Benutzernamen nicht: „'.wp_strip_all_tags($known->get_error_message()).'“');
for ($i = 2; $i <= LoginGuard::FAILS_PER_LOGIN_IP - 1; $i++) {
    wp_authenticate($login, 'falsch-'.$i);
}
$fifth = wp_authenticate($login.'@example.test', 'falsch-5'); // E-Mail statt Benutzername zählt für dasselbe Konto
$check(is_wp_error($fifth) && $fifth->get_error_code() === 'bp_login_locked', LoginGuard::FAILS_PER_LOGIN_IP.'. Fehlversuch (Benutzername und E-Mail gemeinsam) → gesperrt');
$right = wp_authenticate($login, $pass);
$check(is_wp_error($right) && $right->get_error_code() === 'bp_login_locked' && str_contains($right->get_error_message(), 'Zu viele Anmeldeversuche'), 'gesperrt: auch das richtige Passwort wird abgewiesen (befristete Sperre, neutrale Meldung)');
$signon = wp_signon(['user_login' => $login, 'user_password' => $pass, 'remember' => false]);
$check(is_wp_error($signon) && $signon->get_error_code() === 'bp_login_locked', 'wp_signon (wp-login.php, WooCommerce „Mein Konto“) ebenso gesperrt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.72';
$check(wp_authenticate($login, $pass) instanceof WP_User, 'von einer anderen IP weiter anmeldbar (kein Aussperren fremder Konten)');
$check(LoginGuard::locked(LoginGuard::buckets($login.'@example.test', '203.0.113.71')), 'App-Login sieht dieselbe Sperre (gemeinsame Zähler mit POST /auth/login)');
$hits = static fn (string $bucket): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits), 0) FROM {$p}mobile_rate_limit WHERE bucket_hash = %s", hash('sha256', $bucket.'|'.wp_salt('nonce'))));
$before = $hits('login-ip|203.0.113.72');
LoginGuard::uncounted(static fn () => wp_authenticate($login, 'falsch'));
$check($hits('login-ip|203.0.113.72') === $before, 'App-Login zählt selbst: authenticate-Filter zählt dabei nicht doppelt');
// Erfolg setzt Konto + IP zurück.
$_SERVER['REMOTE_ADDR'] = '203.0.113.73';
for ($round = 0; $round < 2; $round++) {
    for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN_IP - 1; $i++) {
        wp_authenticate($login, 'falsch');
    }
    $ok = wp_authenticate($login, $pass);
}
$check($ok instanceof WP_User, 'erfolgreiche Anmeldung setzt den Zähler Konto + IP zurück (2 × '.(LoginGuard::FAILS_PER_LOGIN_IP - 1).' Fehlversuche)');
// Je IP: viele verschiedene Namen.
$_SERVER['REMOTE_ADDR'] = '203.0.113.74';
for ($i = 0; $i < LoginGuard::FAILS_PER_IP; $i++) {
    wp_authenticate('rate_'.$i.'_'.wp_generate_password(4, false, false), 'falsch');
}
$byIp = wp_authenticate($login, $pass);
$check(is_wp_error($byIp) && $byIp->get_error_code() === 'bp_login_locked', LoginGuard::FAILS_PER_IP.' Fehlversuche einer IP mit verschiedenen Namen → IP gesperrt');
// Bekanntes Gerät (T41): IP gesperrt, aber dieses Konto hat sich hier schon angemeldet → kommt rein.
$kd = \Bootspruefung\Learning\Security\KnownDevice::class;
$token = $kd::issue((int) $userId);
$check($kd::valid($token, (int) $userId) && ! $kd::valid($token, (int) $userId + 1) && ! $kd::valid($token.'0', (int) $userId) && ! $kd::valid($token, (int) $userId, time() + $kd::LIFETIME + 60), 'Gerätenachweis: nur für dieses Konto, unverfälscht und befristet');
$_COOKIE[$kd::COOKIE] = $token;
$check(wp_authenticate($login, $pass) instanceof WP_User, 'IP gesperrt: bekanntes Gerät meldet sich mit richtigem Passwort trotzdem an (Fahrschul-WLAN, Mobilfunk)');
$check(is_wp_error(wp_authenticate('rate_x_'.wp_generate_password(4, false, false), 'falsch')) && wp_authenticate('rate_y_'.wp_generate_password(4, false, false), $pass) instanceof WP_Error, 'Nachweis gilt nur für sein Konto: andere Namen bleiben gesperrt');
unset($_COOKIE[$kd::COOKIE]);
$check(is_wp_error(wp_authenticate($login, $pass)), 'ohne Nachweis bleibt die IP gesperrt');
// Fremdes Konto aussperren (viele IPs, 30 Fehlversuche je Konto): der Besitzer mit Nachweis kommt weiter rein.
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.'.($i + 1);
    wp_authenticate($login, 'falsch');
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
$check(is_wp_error(wp_authenticate($login, $pass)), LoginGuard::FAILS_PER_LOGIN.' Fehlversuche von vielen IPs: Konto ohne Nachweis gesperrt');
$_COOKIE[$kd::COOKIE] = $token;
$check(wp_authenticate($login, $pass) instanceof WP_User, 'Besitzer mit bekanntem Gerät wird nicht ausgesperrt');
unset($_COOKIE[$kd::COOKIE]);
// Passwort-Reset setzt die Kontozähler zurück und merkt das Gerät.
do_action('after_password_reset', get_userdata((int) $userId), $pass);
$check(! empty($_COOKIE[$kd::COOKIE]) && $kd::valid((string) $_COOKIE[$kd::COOKIE], (int) $userId), 'nach Passwort-Reset: Gerät als bekannt gemerkt');
unset($_COOKIE[$kd::COOKIE]);
$check(wp_authenticate($login, $pass) instanceof WP_User, 'nach Passwort-Reset: Kontosperre aufgehoben');
wp_set_password($pass.'-neu', (int) $userId);
$check(! $kd::valid($token, (int) $userId), 'Passwortänderung macht alte Gerätenachweise ungültig');
wp_set_password($pass, (int) $userId);
// IPv6: ein /64-Netz zählt wie eine Adresse.
$check(\Bootspruefung\Learning\Mobile\RateLimiter::ipKey('2001:db8:1:2:aaaa::1') === \Bootspruefung\Learning\Mobile\RateLimiter::ipKey('2001:db8:1:2:bbbb::9') && \Bootspruefung\Learning\Mobile\RateLimiter::ipKey('2001:db8:1:2:aaaa::1') === '2001:db8:1:2::/64' && \Bootspruefung\Learning\Mobile\RateLimiter::ipKey('::ffff:203.0.113.5') === '203.0.113.5' && \Bootspruefung\Learning\Mobile\RateLimiter::ipKey('203.0.113.5') === '203.0.113.5', 'IPv6 je /64-Netz, IPv4 unverändert');
// T83: Versuche werden vor der Passwortprüfung atomar vorgemerkt – parallele Anfragen überholen die Grenze nicht.
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$_SERVER['REMOTE_ADDR'] = '203.0.113.90';
for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN_IP - 1; $i++) {
    wp_authenticate($login, 'falsch');
}
$idT83 = strtolower($login.'@example.test');
$inflight = LoginGuard::reserve(LoginGuard::buckets($login, '203.0.113.90')); // Anfrage A: letzter erlaubter Versuch läuft noch
$parallel = wp_authenticate($login, $pass); // Anfrage B gleichzeitig, mit richtigem Passwort
$check($inflight['ok'] && is_wp_error($parallel) && $parallel->get_error_code() === 'bp_login_locked', 'paralleler Versuch über der Grenze abgewiesen, obwohl A noch nicht gezählt war (früher: prüfen, dann zählen)');
$check($hits('login-email-ip|'.$idT83.'|203.0.113.90') === LoginGuard::FAILS_PER_LOGIN_IP && $hits('login-ip|203.0.113.90') === LoginGuard::FAILS_PER_LOGIN_IP, 'abgewiesener Versuch zählt nicht mit (Vormerkung zurückgenommen)');
$check(LoginGuard::settle(LoginGuard::buckets($login, '203.0.113.90'), $inflight['counts'], false, $login, '203.0.113.90'), 'A scheitert: danach gesperrt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.91';
wp_authenticate($login, 'falsch');
$check(wp_authenticate($login, $pass) instanceof WP_User && $hits('login-ip|203.0.113.91') === 1, 'erfolgreiche Anmeldung nimmt ihre Vormerkung zurück (nur Fehlversuche zählen)');
$appBuckets = LoginGuard::buckets($login, '203.0.113.92');
$first = LoginGuard::reserve($appBuckets);
$wpdb->query($wpdb->prepare("UPDATE {$p}mobile_rate_limit SET hits = %d WHERE bucket_hash = %s", LoginGuard::FAILS_PER_LOGIN_IP, hash('sha256', 'login-email-ip|'.$idT83.'|203.0.113.92|'.wp_salt('nonce'))));
$second = LoginGuard::reserve($appBuckets);
$check($first['ok'] && ! $second['ok'] && $second['retry'] > 0 && $hits('login-ip|203.0.113.92') === 1, 'App-Login nutzt dieselbe Vormerkung: zweiter Versuch über der Grenze abgewiesen, ohne zu zählen');
// T88: unbekanntes Konto – gleich viele Passwortprüfungen wie bei einem bekannten (keine Aufzählung über die Antwortzeit).
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$_SERVER['REMOTE_ADDR'] = '203.0.113.93';
$checks = 0;
$countChecks = static function ($result) use (&$checks) {
    $checks++;

    return $result;
};
add_filter('check_password', $countChecks);
wp_authenticate($login, 'falsch');
$knownChecks = $checks;
$checks = 0;
$unknownT88 = wp_authenticate('gibt_es_nicht_'.wp_generate_password(6, false, false), 'falsch');
$unknownName = $checks;
$checks = 0;
wp_authenticate('niemand-'.wp_generate_password(6, false, false).'@example.test', 'falsch');
remove_filter('check_password', $countChecks);
$check($knownChecks === 1 && $unknownName === 1 && $checks === 1 && is_wp_error($unknownT88) && $unknownT88->get_error_code() === 'bp_login_failed', 'unbekannter Name bzw. unbekannte E-Mail: Dummy-Passwortprüfung, gleiche Meldung');
$check(str_starts_with((string) get_option('bp_learning_login_dummy_hash'), '$'), 'Dummy-Hash mit dem Verfahren von WordPress gespeichert');
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
// Hinweis zur Begrenzung der Reset-Mails (ohne Kontoaufzählung).
$check(str_contains((string) apply_filters('woocommerce_lost_password_confirmation_message', 'Gesendet.'), 'höchstens drei Links pro Stunde'), 'Bestätigung „Link gesendet“ nennt die Begrenzung (Spam-Ordner, eine Stunde warten)');
// Proxy nicht eingetragen: Warnung.
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
$check(is_string(\Bootspruefung\Learning\Mobile\RateLimiter::proxyWarning()), 'Proxy davor, aber nicht eingetragen: Warnung im Systemstatus');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.81';
$check(\Bootspruefung\Learning\Mobile\RateLimiter::proxyWarning() === null, 'öffentliche Adresse ohne Proxy: keine Warnung');
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
// Unbekannter Name: gleiche Sperre wie bei einem echten Konto (keine Aufzählung über die Sperre).
$_SERVER['REMOTE_ADDR'] = '203.0.113.75';
$ghost = 'gibt_es_nicht_'.wp_generate_password(6, false, false);
for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN_IP; $i++) {
    $last = wp_authenticate($ghost, 'falsch');
}
$check(is_wp_error($last) && $last->get_error_code() === 'bp_login_locked' && $last->get_error_message() === $right->get_error_message(), 'unbekannter Name: gleiche Sperre und Meldung wie bei einem echten Konto');
$check(in_array('bp_login_failed', apply_filters('shake_error_codes', []), true), 'wp-login: Formular wackelt bei der neutralen Meldung');
// Abschaltbar (eigenes Sicherheits-Plugin).
$off = static fn ($on, $feature) => in_array($feature, ['login_limit', 'login_errors'], true) ? false : $on;
add_filter('bootspruefung_hardening', $off, 10, 2);
$_SERVER['REMOTE_ADDR'] = '203.0.113.76';
for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN_IP + 2; $i++) {
    $last = wp_authenticate($login, 'falsch');
}
$check(is_wp_error($last) && $last->get_error_code() === 'incorrect_password' && wp_authenticate($login, $pass) instanceof WP_User, 'Filter bootspruefung_hardening: Sperre und neutrale Meldung abschaltbar');
remove_filter('bootspruefung_hardening', $off, 10);
$check(Hardening::externalLoginLimiter() === null && Hardening::enabled('login_limit'), 'ohne Sicherheits-Plugin: Begrenzung aktiv');

echo "Passwort vergessen (SEC-02)\n";
$mails = [];
$catchMail = static function ($null, $atts) use (&$mails) {
    $mails[] = (array) $atts['to'];

    return true;
};
add_filter('pre_wp_mail', $catchMail, 10, 2);
$GLOBALS['pagenow'] = 'wp-login.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
$target = $redirectOf(static fn () => retrieve_password('niemand-'.wp_generate_password(6, false, false).'@example.test'));
$check($target !== null && str_contains($target, 'checkemail=confirm') && $mails === [], 'unbekannte E-Mail: dieselbe Bestätigung wie bei bekanntem Konto, keine Mail');
$target = $redirectOf(static fn () => retrieve_password('gibt_es_nicht_'.wp_generate_password(6, false, false)));
$check($target !== null && str_contains($target, 'checkemail=confirm'), 'unbekannter Benutzername: ebenso neutral');
$sent = 0;
for ($i = 0; $i < 3; $i++) {
    $r = null;
    $target = $redirectOf(static function () use ($login, &$r): void {
        $r = retrieve_password($login);
    });
    $sent += $target === null && $r === true ? 1 : 0;
}
$check($sent === 3 && count($mails) === 3, 'bekanntes Konto: Reset-Mail wird verschickt');
$target = $redirectOf(static fn () => retrieve_password($login));
$check($target !== null && str_contains($target, 'checkemail=confirm') && count($mails) === 3, 'mehr als 3 Reset-Mails je Konto und Stunde: Bestätigung ohne weitere Mail');
$GLOBALS['pagenow'] = 'index.php';
$r = $redirectOf(static fn () => retrieve_password('niemand@example.test'));
$check($r === null, 'außerhalb von wp-login/„Mein Konto“ (z. B. App, Admin) greift die Umleitung nicht');
remove_filter('wp_redirect', [LoginGuard::class, 'padRedirect'], PHP_INT_MAX);

echo "Passwort vergessen: Antwortzeit verrät kein Konto (T110)\n";
use Bootspruefung\Learning\Support\AfterResponse;

$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$GLOBALS['pagenow'] = 'wp-login.php';
$t110Login = 'sec_t110_'.wp_generate_password(6, false, false);
$t110Id = wp_create_user($t110Login, wp_generate_password(20), $t110Login.'@example.test');
// Unbekannte Adressen zählen im IP-Zähler mit: nach 10 Anfragen bekommt auch ein echtes Konto keine Mail mehr.
$_SERVER['REMOTE_ADDR'] = '203.0.113.110';
$mailsBefore = count($mails);
for ($i = 0; $i < LoginGuard::FORGOT_PER_IP; $i++) {
    $redirectOf(static fn () => retrieve_password('niemand-'.$i.'-'.wp_generate_password(6, false, false).'@example.test'));
}
$target = $redirectOf(static fn () => retrieve_password($t110Login));
$check($target !== null && str_contains($target, 'checkemail=confirm') && count($mails) === $mailsBefore, 'unbekannte Adressen zählen je IP mit: danach dieselbe Bestätigung, keine Mail');
// Antwort vor dem Mailversand (PHP-FPM/LiteSpeed): erst die Weiterleitung, dann Schlüssel und Mail; die Weiterleitung
// von WordPress danach läuft ins Leere.
$_SERVER['REMOTE_ADDR'] = '203.0.113.111';
$defer = static fn () => true;
add_filter('bootspruefung_defer_after_response', $defer);
$seen = [];
$record = static function ($location) use (&$seen, &$mails) {
    $seen[] = [(string) $location, count($mails)];

    return false; // in der Kommandozeile keine Kopfzeilen senden
};
add_filter('wp_redirect', $record, 0);
$mailsBefore = count($mails);
$r = retrieve_password($t110Login);
$check($r === true && count($seen) === 1 && str_contains($seen[0][0], 'checkemail=confirm') && $seen[0][1] === $mailsBefore && count($mails) === $mailsBefore + 1, 'bekanntes Konto (wp-login): gleiche Bestätigung zuerst, Mail erst danach');
$check(wp_safe_redirect(admin_url()) === false, 'zweite Weiterleitung nach vorzeitig gesendeter Antwort unterdrückt');
remove_filter('wp_redirect', [LoginGuard::class, 'suppressRedirect'], PHP_INT_MAX);
remove_filter('wp_redirect', $record, 0);
$target = $redirectOf(static fn () => retrieve_password('niemand-'.wp_generate_password(6, false, false).'@example.test'));
$check($target !== null && str_contains($target, 'checkemail=confirm') && count($mails) === $mailsBefore + 1, 'unbekannte Adresse: dieselbe Weiterleitung sofort, keine Mail');
if (class_exists('WC_Shortcode_My_Account') && function_exists('wc_get_account_endpoint_url')) {
    // WooCommerce „Mein Konto › Passwort vergessen“: gleiche Reihenfolge.
    $GLOBALS['pagenow'] = 'index.php';
    $_POST = ['wc_reset_password' => 'true', 'user_login' => $t110Login.'@example.test'];
    $seen = [];
    $mailsBefore = count($mails);
    add_filter('wp_redirect', $record, 0);
    $r = WC_Shortcode_My_Account::retrieve_password();
    remove_filter('wp_redirect', $record, 0);
    $check($r === true && count($seen) === 1 && str_contains($seen[0][0], 'reset-link-sent=true') && $seen[0][1] === $mailsBefore && count($mails) === $mailsBefore + 1, 'WooCommerce „Mein Konto“: Bestätigung zuerst, Mail danach');
    remove_filter('wp_redirect', [LoginGuard::class, 'suppressRedirect'], PHP_INT_MAX);
    $_POST['user_login'] = 'niemand-'.wp_generate_password(6, false, false).'@example.test';
    $target = $redirectOf(static fn () => WC_Shortcode_My_Account::retrieve_password());
    $check($target !== null && str_contains($target, 'reset-link-sent=true') && count($mails) === $mailsBefore + 1, 'WooCommerce „Mein Konto“: unbekannte Adresse bekommt dieselbe Bestätigung');
    $_POST = [];
    $GLOBALS['pagenow'] = 'wp-login.php';
} else {
    echo "  (WooCommerce nicht aktiv: „Mein Konto“-Teil übersprungen)\n";
}
remove_filter('bootspruefung_defer_after_response', $defer);
// Rückfall ohne vorzeitiges Senden (mod_php): jede Antwort dauert gleich lang (Mindestdauer).
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$_SERVER['REMOTE_ADDR'] = '203.0.113.112';
$short = static fn () => 0.4;
add_filter('bootspruefung_neutral_response_seconds', $short);
$requestStart = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
$_SERVER['REQUEST_TIME_FLOAT'] = $t0 = microtime(true);
$redirectOf(static fn () => retrieve_password('niemand-'.wp_generate_password(6, false, false).'@example.test'));
$unknownTime = microtime(true) - $t0;
$_SERVER['REQUEST_TIME_FLOAT'] = $t0 = microtime(true);
$mailsBefore = count($mails);
$seen = [];
add_filter('wp_redirect', $record, 0); // zeichnet auf, ohne abzubrechen – die Mindestdauer hängt an der letzten Priorität
if (retrieve_password($t110Login) === true) {
    wp_safe_redirect('wp-login.php?checkemail=confirm'); // wie wp-login.php nach dem Versand
}
$knownTime = microtime(true) - $t0;
remove_filter('wp_redirect', $record, 0);
$check(count($mails) === $mailsBefore + 1 && count($seen) === 1 && $unknownTime >= 0.38 && $knownTime >= 0.38 && abs($knownTime - $unknownTime) < 0.25, sprintf('Rückfall: bekannte und unbekannte Adresse gleich lang (%.2f s / %.2f s, Mindestdauer 0,4 s)', $knownTime, $unknownTime));
$check(! has_filter('wp_redirect', [LoginGuard::class, 'padRedirect']), 'Rückfall: Mindestdauer nur für diese eine Weiterleitung');
$requestStart === null ? $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) : $_SERVER['REQUEST_TIME_FLOAT'] = $requestStart;
remove_filter('bootspruefung_neutral_response_seconds', $short);
$check(AfterResponse::minSeconds() === AfterResponse::MIN_SECONDS && AfterResponse::possible() === false, 'Standard: 2 Sekunden Mindestdauer; Kommandozeile ohne vorzeitiges Senden');
$GLOBALS['pagenow'] = 'index.php';
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user((int) $t110Id);
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
remove_filter('pre_wp_mail', $catchMail, 10);

echo "XML-RPC, Sitemap, Autoren, REST (SEC-01/SEC-02)\n";
$check(apply_filters('xmlrpc_enabled', true) === false && apply_filters('xmlrpc_methods', ['wp.getUsersBlogs' => 'x', 'system.multicall' => 'y']) === [], 'XML-RPC aus: xmlrpc_enabled = false, keine Methoden');
require_once ABSPATH.WPINC.'/class-IXR.php';
require_once ABSPATH.WPINC.'/class-wp-xmlrpc-server.php';
$server = new wp_xmlrpc_server();
$xmlUser = $server->login($login, $pass);
$check($xmlUser === false && $server->error instanceof IXR_Error && (int) $server->error->code === 405, 'XML-RPC-Login: 405 (abgeschaltet), auch mit richtigem Passwort');
$check(! isset(apply_filters('wp_headers', ['X-Pingback' => 'x'])['X-Pingback']), 'kein X-Pingback-Header');
$xmlOn = static fn ($on, $feature) => $feature === 'xmlrpc_off' ? false : $on;
add_filter('bootspruefung_hardening', $xmlOn, 10, 2);
$check(apply_filters('xmlrpc_enabled', true) === true && apply_filters('xmlrpc_methods', ['a' => 'b']) === ['a' => 'b'], 'per Filter wieder einschaltbar');
remove_filter('bootspruefung_hardening', $xmlOn, 10);
$postsProvider = new WP_Sitemaps_Posts();
$check(apply_filters('wp_sitemaps_add_provider', new WP_Sitemaps_Users(), 'users') === false && apply_filters('wp_sitemaps_add_provider', $postsProvider, 'posts') === $postsProvider, 'Sitemap ohne Benutzer (wp-sitemap-users-*.xml), Beiträge/Seiten bleiben');
$yoastTitles = apply_filters('option_wpseo_titles', ['disable-author' => false, 'noindex-author-wpseo' => false]);
$check(apply_filters('wpseo_sitemap_exclude_author', [(object) ['ID' => 1]]) === [] && $yoastTitles['disable-author'] === true && $yoastTitles['noindex-author-wpseo'] === true, 'Yoast: keine Autoren-Sitemap (author-sitemap.xml), Autoren-Archive aus');
if (wp_sitemaps_get_server()->sitemaps_enabled()) {
    $check(wp_sitemaps_get_server()->registry->get_provider('users') === null, 'Sitemap-Register: kein Anbieter „users“');
}
wp_set_current_user(0);
$routes = rest_get_server()->get_routes();
$check(! isset($routes['/wp/v2/users']) && ! isset($routes['/wp/v2/users/(?P<id>[\d]+)']), 'Gäste: /wp/v2/users nicht vorhanden');
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));
$check(isset(rest_get_server()->get_routes()['/wp/v2/users']), 'Redakteure: /wp/v2/users bleibt (Block-Editor)');
wp_set_current_user(0);
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['author' => (int) ($admins[0] ?? 1)]);
$target = $redirectOf(static fn () => do_action('template_redirect'));
$check($target === home_url('/'), '?author=N für Gäste: Weiterleitung auf die Startseite (kein /author/<login>/)');
$embedPost = wp_insert_post(['post_title' => 'Sicherheitstest oEmbed', 'post_status' => 'publish', 'post_author' => (int) ($admins[0] ?? 1)]);
$embed = get_oembed_response_data($embedPost, 600);
$check(is_array($embed) && ! isset($embed['author_name']) && ! isset($embed['author_url']) && ($embed['title'] ?? '') === 'Sicherheitstest oEmbed', 'oEmbed ohne Autorenname/-URL (/author/<login>/)');
wp_delete_post((int) $embedPost, true);
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query();

echo "Bekanntes Sicherheits-Plugin\n";
define('LOGINIZER_VERSION', 'test');
$check(Hardening::externalLoginLimiter() === 'Loginizer' && ! Hardening::enabled('login_limit') && Hardening::enabled('login_errors'), 'Loginizer aktiv: eigene Sperre zurückgenommen, neutrale Meldungen bleiben');
$_SERVER['REMOTE_ADDR'] = '203.0.113.90';
for ($i = 0; $i < LoginGuard::FAILS_PER_LOGIN_IP + 1; $i++) {
    $last = wp_authenticate($login, 'falsch');
}
$check(is_wp_error($last) && $last->get_error_code() === 'bp_login_failed', 'keine doppelte Sperre neben dem Sicherheits-Plugin');

echo "Härtung Rest aus dem Audit (T49)\n";
$H = \Bootspruefung\Learning\Security\Hardening::class;
$customerT49 = get_userdata((int) $userId);
$check(apply_filters('wp_is_application_passwords_available_for_user', true, $customerT49) === false && apply_filters('wp_is_application_passwords_available_for_user', true, get_userdata(1)) === true, 'Anwendungspasswörter: nicht für Kunden, weiter für Verwaltung (WordPress verlangt zusätzlich HTTPS)');
$errT49 = new WP_Error();
$fake = (object) ['ID' => (int) $userId, 'user_email' => 'neu-'.wp_generate_password(4, false, false).'@example.test'];
$_POST['password_current'] = '';
$H::requirePasswordForEmailChange($errT49, $fake);
$check($errT49->get_error_code() === 'bp_email_change_password', 'E-Mail ändern ohne aktuelles Passwort abgelehnt');
$errT49 = new WP_Error();
$_POST['password_current'] = $pass;
$H::requirePasswordForEmailChange($errT49, $fake);
$errSame = new WP_Error();
$_POST['password_current'] = '';
$H::requirePasswordForEmailChange($errSame, (object) ['ID' => (int) $userId, 'user_email' => $customerT49->user_email]);
$check(! $errT49->has_errors() && ! $errSame->has_errors(), 'mit richtigem Passwort erlaubt; ohne Adressänderung kein Passwort nötig');
unset($_POST['password_current']);
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$_SERVER['REMOTE_ADDR'] = '203.0.113.95';
for ($i = 0; $i < 10; $i++) {
    $regErr = $H::limitRegistration(new WP_Error());
}
$check(! $regErr->has_errors() && $H::limitRegistration(new WP_Error())->get_error_code() === 'bp_register_limit', 'Registrierung: nach 10 Versuchen je IP und Stunde gesperrt');
$headersT49 = $H::securityHeaderList();
$check(($headersT49['X-Content-Type-Options'] ?? '') === 'nosniff' && ($headersT49['X-Frame-Options'] ?? '') === 'SAMEORIGIN' && str_contains($headersT49['Permissions-Policy'] ?? '', 'microphone=(self)') && has_action('send_headers', [$H, 'securityHeaders']) !== false, 'Sicherheits-Header im Frontend (Mikrofon nur für die eigene Seite – Funktraining)');
$check(has_filter('the_author') !== false, 'Feeds nennen keinen Autorennamen');
$cleaned = \Bootspruefung\Learning\Commerce\GroupService::cleanName('Segelclub St.Peter e.V. – jetzt gewinn.top und login-seite.shop besuchen');
$check(! str_contains($cleaned, 'gewinn.top') && ! str_contains($cleaned, 'login-seite.shop') && str_contains($cleaned, 'St.Peter') && str_contains($cleaned, 'e.V.'), 'Gruppenname: Domains jeder Endung entfernt, „St.Peter“ und „e.V.“ bleiben ('.$cleaned.')');
$check(\Bootspruefung\Learning\Cli\Commands::insideWebroot(ABSPATH.'export.json') && \Bootspruefung\Learning\Cli\Commands::insideWebroot(wp_upload_dir()['basedir'].'/x.json') && ! \Bootspruefung\Learning\Cli\Commands::insideWebroot(sys_get_temp_dir().'/bp-export.json'), 'CLI-Exporte: Webordner und Uploads abgelehnt, /tmp erlaubt');
wp_set_current_user(0);
$healthT49 = rest_do_request(new WP_REST_Request('GET', '/boatingschool/v1/health'))->get_data()['data'] ?? [];
wp_set_current_user(1);
$check(array_key_exists('api_version', $healthT49) && ($healthT49['plugin_version'] ?? null) === null, '/health ohne Anmeldung nennt keine Plugin-Version');

echo "Tipps in der Core-REST-API, IPv6 in allen Limits (T88)\n";
$tipIds = get_posts(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
$tipId = (int) ($tipIds[0] ?? wp_insert_post(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Tipp T88', 'post_content' => 'Text']));
$tipStatus = static function (int $user, string $route, array $query = []): int {
    wp_set_current_user($user);
    $request = new WP_REST_Request('GET', $route);
    $request->set_query_params($query);
    $status = rest_do_request($request)->get_status();
    wp_set_current_user(1);

    return $status;
};
$check($tipStatus(0, '/wp/v2/bp_tip') === 401 && $tipStatus(0, '/wp/v2/bp_tip/'.$tipId) === 401 && $tipStatus(0, '/wp/v2/bp_tip_category') === 401, 'Gast: Tipps und Tipp-Kategorien über /wp/v2 gesperrt (401)');
$check($tipStatus((int) $userId, '/wp/v2/bp_tip') === 403 && $tipStatus((int) $userId, '/wp/v2/bp_tip/'.$tipId) === 403, 'Kunde ohne Bearbeitungsrecht: 403');
$check($tipStatus(1, '/wp/v2/bp_tip') === 200 && $tipStatus(1, '/wp/v2/bp_tip/'.$tipId, ['context' => 'edit']) === 200 && $tipStatus(1, '/wp/v2/bp_tip_category') === 200, 'Redaktion: lesen und Block-Editor (context=edit) wie bisher');
$check($tipStatus((int) $userId, '/bootspruefung/v1/tips') === 200, 'Lernbereich: /bootspruefung/v1/tips für angemeldete Lernende unverändert');
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
for ($i = 0; $i < 10; $i++) {
    $_SERVER['REMOTE_ADDR'] = '2001:db8:42:1:'.dechex($i + 1).'::1';
    $H::limitRegistration(new WP_Error());
}
$_SERVER['REMOTE_ADDR'] = '2001:db8:42:1:ffff::abcd';
$v6Blocked = $H::limitRegistration(new WP_Error())->get_error_code() === 'bp_register_limit';
$_SERVER['REMOTE_ADDR'] = '2001:db8:42:2::1';
$check($v6Blocked && ! $H::limitRegistration(new WP_Error())->has_errors() && \Bootspruefung\Learning\Mobile\RateLimiter::clientKey() === '2001:db8:42:2::/64', 'IPv6: neue Adressen im selben /64-Netz teilen sich das Limit, anderes Netz frei');
$rawIp = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() === 'php' && preg_match_all("/\\|'\\s*\\.\\s*[\\\\\\w]*RateLimiter::clientIp\\(\\)/", (string) file_get_contents($f->getPathname()), $mm) > 0) {
        $rawIp[] = basename($f->getPathname());
    }
}
$check($rawIp === [], 'kein Zähler mit roher Client-IP (immer RateLimiter::clientKey(), IPv6 je /64)'.($rawIp !== [] ? ': '.implode(', ', $rawIp) : ''));
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");

echo "Seiten-Cache: persönliche Seiten nicht zwischenspeichern (T78)\n";
$NC = \Bootspruefung\Learning\Frontend\NoCache::class;
$ncSignals = ['headers' => 0, 'litespeed' => 0];
$ncHeaders = static function (array $h) use (&$ncSignals): array {
    $ncSignals['headers']++;

    return $h;
};
$ncLs = static function () use (&$ncSignals): void {
    $ncSignals['litespeed']++;
};
add_filter('nocache_headers', $ncHeaders);
add_action('litespeed_control_set_nocache', $ncLs);
// T123: nur Seitenaufrufe im Frontend bekommen die Konstante; Verwaltung, Cron und WP-CLI nichts; REST/Ajax nur Kopfzeilen.
$check($NC::context() === 'page', 'Kommandozeilen-Test ohne WP-CLI: gilt als Seitenaufruf');
add_filter('wp_doing_cron', '__return_true');
$ncCron = $NC::context();
remove_filter('wp_doing_cron', '__return_true');
add_filter('wp_doing_ajax', '__return_true');
$ncAjax = $NC::context();
remove_filter('wp_doing_ajax', '__return_true');
require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH.'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get('dashboard');
$ncAdmin = $NC::context();
unset($GLOBALS['current_screen']);
$check($ncCron === 'none' && $ncAjax === 'api' && $ncAdmin === 'none', "Erkennung: Cron → keine Kennzeichnung ({$ncCron}), Ajax → nur Kopfzeilen ({$ncAjax}), Verwaltung → keine ({$ncAdmin})");
$ncPageBefore = defined('DONOTCACHEPAGE');
$ncContext = null;
$ncForce = static function ($c) use (&$ncContext) {
    return $ncContext ?? $c;
};
add_filter('bootspruefung_nocache_context', $ncForce);
$NC::reset();
$ncContext = 'none';
$NC::mark('protected-content');
$check($NC::reasons() === [] && $ncSignals === ['headers' => 0, 'litespeed' => 0] && defined('DONOTCACHEPAGE') === $ncPageBefore, 'Verwaltung, Cron, WP-CLI: keine Kennzeichnung, keine Konstante, keine Kopfzeilen');
$ncContext = 'api';
$NC::mark('protected-content');
$check($NC::reasons() === ['protected-content'] && $ncSignals['litespeed'] === 1 && defined('DONOTCACHEPAGE') === $ncPageBefore, 'REST/Ajax: nur Kopfzeilen (LiteSpeed, Cache-Control), keine Konstante'.($ncPageBefore ? ' (Konstante war schon vorher gesetzt – nur Kopfzeilen geprüft)' : ''));
$ncContext = null;
remove_filter('bootspruefung_nocache_context', $ncForce);
$NC::reset();
$ncSignals = ['headers' => 0, 'litespeed' => 0];
$ncRun = static function (array $query, ?callable $before = null) use ($NC): array {
    $GLOBALS['wp_query'] = new WP_Query($query);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    if ($before !== null) {
        $before();
    }
    $NC::reset();
    $NC::detect();

    return $NC::reasons();
};
$ncPlain = wp_insert_post(['post_title' => 'Normale Seite T78', 'post_content' => 'Für alle gleich.', 'post_status' => 'publish', 'post_type' => 'page']);
$ncGated = wp_insert_post(['post_title' => 'Geschützte Seite T78', 'post_content' => 'Nur mit Zugang.', 'post_status' => 'publish', 'post_type' => 'page']);
update_post_meta($ncGated, \Bootspruefung\Learning\Gate\ContentGate::META, ['course' => 'SBF-SEE', 'feature' => 'any']);
$ncSection = wp_insert_post(['post_title' => 'Abschnitt T78', 'post_content' => '<!-- wp:bootspruefung/zugang {"course":"SBF-SEE"} --><p>x</p><!-- /wp:bootspruefung/zugang -->', 'post_status' => 'publish', 'post_type' => 'page']);
$ncShort = wp_insert_post(['post_title' => 'Widerruf T78', 'post_content' => '[bootspruefung_vertrag_widerrufen]', 'post_status' => 'publish', 'post_type' => 'page']);
$check($ncRun(['page_id' => $ncPlain]) === [] && $ncSignals['headers'] === 0 && $ncSignals['litespeed'] === 0, 'normale Seite: darf zwischengespeichert werden');
foreach (['' => 'Lernbereich', 'gruppe/g'.str_repeat('0', 32) => 'Gruppeneinladung', 'erinnerungen' => 'Abmeldeseite'] as $path => $label) {
    $reasons = $ncRun([], static function () use ($path): void {
        set_query_var('bp_app', '1');
        set_query_var('bp_path', $path);
    });
    $check($reasons === ['learning-area'], $label.' (/bootsscheine/'.$path.'): nicht zwischenspeichern');
}
$check(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'Konstante DONOTCACHEPAGE gesetzt');
$check(! defined('DONOTCACHEOBJECT') && ! defined('DONOTCACHEDB'), 'DONOTCACHEOBJECT und DONOTCACHEDB nicht gesetzt: Objekt- und Datenbank-Cache (W3 Total Cache) bleiben an (T123)');
// Auf der Kommandozeile gelten die Kopfzeilen nach der ersten Ausgabe als gesendet: dann nur LiteSpeed-Aktion prüfbar.
$check($ncSignals['litespeed'] === 3 && ($ncSignals['headers'] === 3 || headers_sent()), 'je Anfrage nocache_headers() und LiteSpeed (litespeed_control_set_nocache)');
$check($ncRun(['page_id' => $ncGated]) === ['protected-content'], 'Seite mit „Lernzugang erforderlich“: nicht zwischenspeichern');
$check($ncRun(['page_id' => $ncSection]) === ['protected-content'], 'Seite mit Block „Nur mit Lernzugang“: nicht zwischenspeichern');
$check($ncRun(['page_id' => $ncShort]) === ['protected-content'], 'Seite mit Widerrufsknopf: nicht zwischenspeichern');
$GLOBALS['wp_query'] = new WP_Query();
$NC::reset();
do_shortcode('[bootspruefung_zugang kurs="SBF-SEE"]geheim[/bootspruefung_zugang]');
$check($NC::reasons() === ['protected-section'], 'Shortcode „Nur mit Lernzugang“ in Widget o. Ä.: Antwort nicht zwischenspeichern');
$NC::reset();
set_query_var('bp_app', '1');
$tpl = (new \Bootspruefung\Learning\Frontend\Router(Plugin::instance()))->template('index.php');
$check(str_ends_with($tpl, 'templates/app.php') && $NC::reasons() === ['learning-area'], 'Vorlage des Lernbereichs kennzeichnet die Antwort');
set_query_var('bp_app', '');
remove_filter('nocache_headers', $ncHeaders);
remove_action('litespeed_control_set_nocache', $ncLs);
foreach ([$ncPlain, $ncGated, $ncSection, $ncShort] as $ncId) {
    wp_delete_post($ncId, true);
}
$GLOBALS['wp_query'] = new WP_Query();
$NC::reset();

echo "Aufräumen\n";
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user((int) $userId);
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
unset($_SERVER['REMOTE_ADDR']);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
