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
$result = SystemStatus::storageSelfTest();
$check($result['overall'] === 'exposed' && $result['dirs']['packages']['status'] === 'exposed' && $result['dirs']['protected']['status'] === 'exposed' && $requests === 2, 'Testdatei direkt abrufbar → „direkt abrufbar“ (je Ordner eine Anfrage)');
$check(count($requested) === 2 && str_contains($requested[0], '/bootspruefung/packages-') && str_contains($requested[1], '/bootspruefung/protected-'), 'angefragt wird eine Testdatei im Paket- bzw. Schutzordner');
$check(glob($packages.'/bp-selftest-*') === [] && glob($protected.'/bp-selftest-*') === [], 'Testdateien danach wieder gelöscht');
SystemStatus::storageSelfTest();
$check($requests === 2, 'Ergebnis zwischengespeichert (Transient): keine neue Anfrage beim nächsten Seitenaufruf');
$rule = SystemStatus::nginxRule();
$check(str_contains($rule, 'location ~* ^/wp-content/uploads/bootspruefung/(packages|protected)') && str_contains($rule, 'deny all;'), 'nginx-Regel für diese Installation: '.strtok($rule, "\n"));
ob_start();
SystemStatus::renderStorageSelfTest();
$html = (string) ob_get_clean();
$check(str_contains($html, 'notice-error') && str_contains($html, 'deny all;') && str_contains($html, 'BOOTSPRUEFUNG_PRIVATE_DIR'), 'Statusseite: rote Warnung mit nginx-Regel und Alternative');
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

echo "Aufräumen\n";
require_once ABSPATH.'wp-admin/includes/user.php';
wp_delete_user((int) $userId);
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
unset($_SERVER['REMOTE_ADDR']);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
