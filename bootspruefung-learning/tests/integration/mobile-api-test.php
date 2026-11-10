<?php

declare(strict_types=1);

/*
 * Integrationstest der App-Schnittstelle /wp-json/boatingschool/v1/ (src/Mobile):
 * Anmeldung, Token-Rotation und -Wiederverwendung, Abmeldung, Sitzungen, Passwortänderung,
 * Rate-Limit (auch hinter Proxy), Tenant, Kursliste mit WooCommerce-Kauf (ohne WooCommerce:
 * manueller Zugang), Smarttrainer (gleicher Lernstand wie im Web), Favoriten/Sammlungen,
 * Favoriten-Sortierung (saved_at/assigned_at), Prüfung Start → Antwort → Abgabe → Ergebnis,
 * Video, Navigation, Funktraining SRC/UBI (Texte, Vokabeln, Buchstabiertafel, Funkpraxis,
 * Teilumfang), Funkgerät-Simulator-Link, Prüfungsanmeldung, Codes, Datenexport, Löschantrag,
 * Schutz fremder Daten (IDOR), Prüfungsfragen markieren, Sitzungsübergabe App → Web,
 * synchronisierte App-Einstellungen, Universal Links/App Links (Reset-Link) und Abgleich
 * Routen ↔ openapi.yaml. Seit 1.4.0 zusätzlich: verlorene Refresh-Antwort (Kulanzfenster),
 * Web-Sitzung nur mit Prüfwert der Webansicht (Login-CSRF) und ohne Kontowechsel, atomare
 * Rate-Limits je E-Mail+IP/E-Mail/IP ohne automatisch vertraute Proxys, verwaiste Idempotenz-
 * Einträge, Fehlercodes statt Meldungstexten, asynchroner Datenexport, Range/Content-Disposition,
 * Code-Einlösung (Idempotenz vor Rate-Limit), Kontolöschung über App-Link und Bestätigung in der App,
 * Prüfung nach Zugangsende. Seit 1.7.0: Tipps & Tricks, Gruppen verwalten (nur Besitzer, Einladen,
 * Platz freigeben, neuer Beitrittscode), Einladungslink als App-Link (Vorschau, Einlösen, AASA) und
 * gezieltes Üben einer bestimmten Frage (mode=question).
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/mobile-api-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse importiert). Mit aktivem WooCommerce wird der
 * Zugang über eine bezahlte Bestellung freigeschaltet. Nur gegen eine Test-Datenbank.
 */

ob_start(); // WooCommerce setzt ggf. Session-Cookies; Ausgabe puffern (keine Header-Warnungen)
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
ob_end_flush();
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\LearningService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Mobile\AppLinks;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\Problem;
use Bootspruefung\Learning\Mobile\TokenAuth;
use Bootspruefung\Learning\Settings\Settings;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';

// Migration (neue Tabellen) wie beim Plugin-Update.
\Bootspruefung\Learning\Plugin::instance()->maybeMigrate();
$check((bool) $wpdb->get_var("SHOW TABLES LIKE '{$p}mobile_session'"), 'Tabelle bp_mobile_session angelegt');
$check((bool) $wpdb->get_var("SHOW TABLES LIKE '{$p}mobile_web_code'") && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$p}exam_session_question LIKE 'flagged'"), 'Migration 1.11.0: Tabelle bp_mobile_web_code und Spalte exam_session_question.flagged');
$check((bool) $wpdb->get_var("SHOW TABLES LIKE '{$p}mobile_rate_limit'") && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$p}mobile_export LIKE 'started_at'") && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$p}mobile_web_code LIKE 'verifier_hash'"), 'Migration 1.12.0: Tabelle bp_mobile_rate_limit, Spalten mobile_export.started_at und mobile_web_code.verifier_hash');

$settingsBefore = get_option(Settings::OPTION);
$mobileBefore = get_option(MobileSettings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
delete_option(MobileSettings::OPTION);
$tenant = MobileSettings::tenantSlug();

$customer = static function (string $login, string $pass): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => $pass, 'user_email' => $login.'@example.test', 'role' => 'customer', 'display_name' => 'Kunde '.$login]);
};
$passA = 'Mobil-Passwort-123!';
$alice = $customer('mobile_kundin_a', $passA);
$bob = $customer('mobile_kunde_b', 'Mobil-Passwort-456!');
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
wp_cache_flush();

/**
 * Eine App-Anfrage wie über HTTP: Bearer-Header und Pfad gesetzt, Nutzer neu ermittelt
 * (determine_current_user), Ausgabe wie WP_REST_Server::serve_request (rest_post_dispatch).
 * @return array{status:int,body:mixed,headers:array<string,string>}
 */
$api = static function (string $method, string $path, array $body = [], ?string $token = null, array $headers = [], string $ip = '192.0.2.10') use ($tenant): array {
    global $current_user;
    $current_user = null;
    TokenAuth::reset();
    Problem::reset();
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    $_SERVER['REQUEST_URI'] = '/wp-json/boatingschool/v1'.$path;
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    if (isset($headers['X-Forwarded-For'])) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $headers['X-Forwarded-For'];
    }
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    $request = new WP_REST_Request($method, '/boatingschool/v1'.$route);
    if ($query !== '') {
        parse_str($query, $params);
        $request->set_query_params($params);
    }
    if ($body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    $headers += ['X-Tenant-Slug' => $tenant];
    foreach ($headers as $name => $value) {
        if ($value !== null && $name !== 'X-Forwarded-For') {
            $request->set_header($name, $value);
        }
    }
    $response = rest_do_request($request);
    $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), rest_get_server(), $request);

    return ['status' => $response->get_status(), 'body' => json_decode((string) wp_json_encode($response->get_data()), true), 'headers' => $response->get_headers()];
};
$code = static fn (array $r): string => (string) ($r['body']['code'] ?? '');
$uuid = static fn (): string => wp_generate_uuid4();
$login = static fn (string $email, string $pass, string $device = 'Testgerät') => $api('POST', '/auth/login', ['email' => $email, 'password' => $pass, 'device_name' => $device, 'device_platform' => 'ios', 'app_version' => '1.0.0'], null, ['X-Tenant-Slug' => null]);

echo "Grundlagen\n";
$health = $api('GET', '/health');
$check($health['status'] === 200 && $health['body']['data']['tenant_slug'] === $tenant, 'GET /health ohne Anmeldung');
$check(($health['headers']['Cache-Control'] ?? '') === 'private, no-store, max-age=0' && str_starts_with((string) ($health['headers']['X-Request-Id'] ?? ''), 'req_'), 'Cache-Control private/no-store und X-Request-Id');
$r = $api('GET', '/gibt-es-nicht');
$check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND' && isset($r['body']['request_id'], $r['body']['title'], $r['body']['detail']), 'unbekannte Route: Fehlerobjekt RESOURCE_NOT_FOUND');
$check(str_starts_with((string) ($r['headers']['Content-Type'] ?? ''), 'application/problem+json'), 'Fehler als application/problem+json');

echo "Anmeldung\n";
$r = $login('mobile_kundin_a@example.test', 'falsch');
$check($r['status'] === 401 && $code($r) === 'UNAUTHENTICATED', 'falsches Passwort → 401');
$r = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED' && isset($r['body']['errors']['device_name']), 'ohne Gerätename → 422 mit Feldfehler');
$cookies = 0;
add_action('set_auth_cookie', static function () use (&$cookies): void {
    $cookies++;
});
add_action('set_logged_in_cookie', static function () use (&$cookies): void {
    $cookies++;
});
$r = $login('mobile_kundin_a@example.test', $passA);
$check($r['status'] === 200 && ($r['body']['data']['token_type'] ?? '') === 'Bearer' && str_starts_with((string) $r['body']['data']['access_token'], 'bpa_'), 'Anmeldung mit Shop-Konto (E-Mail) → Token-Paar');
$tokensA = $r['body']['data'];
$check(($tokensA['memberships'][0]['tenant_slug'] ?? '') === $tenant && $tokensA['user']['email'] === 'mobile_kundin_a@example.test' && $tokensA['user']['email_verified'] === true, 'Login liefert Profil und Mitgliedschaft (Tenant)');
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}mobile_session WHERE id = %d", (int) $tokensA['session_id']));
$check($row && $row->access_hash === hash('sha256', $tokensA['access_token']) && ! str_contains((string) wp_json_encode($row), $tokensA['refresh_token']), 'Sitzung speichert nur Hashes');
$check(abs(strtotime($row->access_expires_at.' UTC') - time() - 900) < 5 && abs(strtotime($row->absolute_expires_at.' UTC') - time() - 90 * 86400) < 5, 'Laufzeiten 15 Minuten / 90 Tage');
$check($cookies === 0, 'keine Login-Cookies gesetzt (wp_authenticate statt wp_signon)');
$r = $login('mobile_kundin_a', $passA, 'Zweitgerät');
$check($r['status'] === 200, 'Anmeldung auch mit Benutzername');
$tokensA2 = $r['body']['data'];
$tokenA = (string) $tokensA['access_token'];

echo "Profil, Tenant, Bearer nur für diesen Namespace\n";
$me = $api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($me['status'] === 200 && $me['body']['data']['user']['id'] === (string) $alice && ($me['body']['data']['memberships'][0]['tenant_name'] ?? '') === Settings::brand(), 'GET /me mit Marke aus den Einstellungen');
$check(array_key_exists('primary_color', $me['body']['data']['memberships'][0]['branding']), 'Branding im Profil');

echo "Rechtstexte und Pflicht-Update (1.8.0)\n";
$createdPages = [];
$imprint = get_page_by_path('impressum', OBJECT, 'page');
if (! $imprint instanceof WP_Post || $imprint->post_status !== 'publish') {
    $createdPages[] = $imprintId = (int) wp_insert_post(['post_type' => 'page', 'post_name' => 'impressum', 'post_title' => 'Impressum', 'post_status' => 'publish', 'post_content' => 'Test']);
    $imprint = get_post($imprintId);
}
$draftWithdrawal = null;
if (! get_page_by_path('widerruf', OBJECT, 'page')) {
    $createdPages[] = $draftWithdrawal = (int) wp_insert_post(['post_type' => 'page', 'post_name' => 'widerruf', 'post_title' => 'Widerrufsbelehrung', 'post_status' => 'draft', 'post_content' => 'Entwurf']);
}
$h = $api('GET', '/health');
$legal = $h['body']['data']['legal'] ?? [];
$check(array_keys($legal) === ['privacy_policy', 'imprint', 'terms', 'withdrawal_policy', 'withdrawal_function', 'contact'] && $legal['imprint'] === get_permalink($imprint), '/health ohne Anmeldung: Rechtstexte-Links (Impressum = veröffentlichte Seite)');
if ($draftWithdrawal !== null) {
    $check($legal['withdrawal_policy'] === null, 'Seite im Entwurf wird nicht verlinkt');
}
$check(($h['body']['data']['min_app_version'] ?? null) === ['ios' => null, 'android' => null], '/health: ohne Vorgabe keine Mindestversion');
$meLinks = $api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null])['body']['data']['memberships'][0]['links'] ?? [];
$check(($meLinks['imprint'] ?? null) === $legal['imprint'] && array_key_exists('terms', $meLinks), '/me: Links enthalten die Rechtstexte');
$mobileBefore = MobileSettings::all();
MobileSettings::save(array_merge($mobileBefore, ['min_app_version_ios' => '2.0.0', 'min_app_version_android' => '1.x']));
$check(MobileSettings::minAppVersion('ios') === '2.0.0' && MobileSettings::minAppVersion('android') === null, 'Mindestversion gespeichert, ungültige Angabe verworfen');
$r = $api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 426 && $code($r) === 'APP_UPDATE_REQUIRED' && ($r['body']['details']['min_app_version'] ?? '') === '2.0.0' && ($r['body']['details']['app_version'] ?? '') === '1.0.0', 'Sitzung mit App 1.0.0 (iOS) unter Mindestversion → 426 APP_UPDATE_REQUIRED');
$r = $api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null, 'X-Boatingschool-App-Version' => '2.1.0', 'X-Boatingschool-Platform' => 'ios']);
$check($r['status'] === 200, 'Header mit neuerer Version → erlaubt');
$r = $api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null, 'X-Boatingschool-App-Version' => '0.1', 'X-Boatingschool-Platform' => 'android']);
$check($r['status'] === 200, 'Android ohne Mindestversion → erlaubt');
$r = $login('mobile_kundin_a', $passA, 'Altes Gerät');
$check($r['status'] === 426 && $code($r) === 'APP_UPDATE_REQUIRED', 'Anmeldung mit veralteter App → 426');
$h = $api('GET', '/health', [], null, ['X-Boatingschool-App-Version' => '1.0.0', 'X-Boatingschool-Platform' => 'ios']);
$check($h['status'] === 200 && $h['body']['data']['min_app_version']['ios'] === '2.0.0', '/health bleibt erreichbar und nennt die Mindestversion');
MobileSettings::save($mobileBefore);
foreach ($createdPages as $pid) {
    wp_delete_post($pid, true);
}
$r = $api('GET', '/me');
$check($r['status'] === 401 && $code($r) === 'UNAUTHENTICATED', 'ohne Token → 401');
$r = $api('GET', '/me', [], 'bpa_'.str_repeat('x', 60));
$check($r['status'] === 401, 'ungültiges Token → 401');
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users/me';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenA;
$GLOBALS['current_user'] = null;
$check(\Bootspruefung\Learning\Mobile\MobileApi::determineUser(false) === false, 'App-Token gilt nicht für /wp/v2');
// Gemischte Anfrage: Pfad im App-Namespace, aber rest_route (GET oder POST) zeigt auf /wp/v2 – WordPress führt rest_route aus.
foreach (['_GET', '_POST'] as $source) {
    $_SERVER['REQUEST_URI'] = '/wp-json/boatingschool/v1/x?rest_route=/wp/v2/users/me';
    $GLOBALS[$source]['rest_route'] = '/wp/v2/users/me';
    $GLOBALS['current_user'] = null;
    $check(\Bootspruefung\Learning\Mobile\MobileApi::determineUser(false) === false, "App-Token gilt nicht für /wp/v2 über rest_route aus {$source}");
    unset($GLOBALS[$source]['rest_route']);
}
$_GET['rest_route'] = '/boatingschool/v1/me';
$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/boatingschool/v1/me';
$check(\Bootspruefung\Learning\Mobile\MobileApi::isMobileRequest() === true, 'rest_route auf den App-Namespace wird erkannt');
$_GET['rest_route'] = '/boatingschool/v1x/me';
$check(\Bootspruefung\Learning\Mobile\MobileApi::isMobileRequest() === false, 'ähnlicher Namespace (boatingschool/v1x) zählt nicht');
unset($_GET['rest_route']);
// Zweite Sicherung: Ist eine App-Sitzung aktiv, wird jede fremde Route abgelehnt.
TokenAuth::reset();
$_SERVER['REQUEST_URI'] = '/wp-json/boatingschool/v1/me';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tokenA;
$GLOBALS['current_user'] = null;
$uid = \Bootspruefung\Learning\Mobile\MobileApi::determineUser(false);
$blocked = \Bootspruefung\Learning\Mobile\MobileApi::preDispatch(null, rest_get_server(), new WP_REST_Request('POST', '/wp/v2/users/me'));
$check($uid === $alice && TokenAuth::current() !== null && is_wp_error($blocked) && ($blocked->get_error_data()['status'] ?? 0) === 401, 'aktive App-Sitzung: /wp/v2 wird in rest_pre_dispatch abgelehnt');
TokenAuth::reset();
$r = $api('GET', '/courses', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && isset($r['body']['errors']['X-Tenant-Slug']), 'ohne X-Tenant-Slug → 422');
$r = $api('GET', '/courses', [], $tokenA, ['X-Tenant-Slug' => 'andere-schule']);
$check($r['status'] === 403 && $code($r) === 'TENANT_MEMBERSHIP_REQUIRED', 'fremder Tenant → 403');

echo "Kurse ohne Zugang\n";
$content = new ContentRepository();
$courseRow = null;
foreach ($content->courses() as $c) {
    if ($c->code === 'SBF-SEE') {
        $courseRow = $c;
    }
}
$courseRow ??= $content->courses()[0];
$courseId = (string) $courseRow->id;
$courseCode = (string) $courseRow->code;
$list = $api('GET', '/courses?per_page=100', [], $tokenA);
$mine = null;
foreach ($list['body']['data'] ?? [] as $c) {
    if ($c['id'] === $courseId) {
        $mine = $c;
    }
}
$check($list['status'] === 200 && $mine !== null && ($list['body']['meta']['tenant']['slug'] ?? '') === $tenant && isset($list['body']['meta']['pagination']['total']), 'Kursliste mit meta.tenant und meta.pagination');
$check($mine !== null && $mine['access']['status'] === 'not_granted' && $mine['features']['smarttrainer']['available'] === false, 'ohne Kauf: not_granted, Smarttrainer gesperrt');
$check($mine !== null && in_array('smarttrainer', $mine['capabilities'], true) && in_array('exam', $mine['capabilities'], true), 'Fähigkeiten aus dem Kursinhalt');
$r = $api('GET', "/courses/{$courseId}/trainer/next", [], $tokenA);
$check($r['status'] === 403 && $code($r) === 'ENTITLEMENT_REQUIRED' && ($r['body']['details']['capability'] ?? '') === 'smarttrainer', 'Smarttrainer ohne Zugang → 403 ENTITLEMENT_REQUIRED');
$r = $api('GET', '/courses/999999', [], $tokenA);
$check($r['status'] === 404, 'unbekannter Kurs → 404');

echo "Freischaltung\n";
$entitlements = new EntitlementService($wpdb);
$productId = 0;
$orderId = 0;
$couponId = 0;
if (Integration::active()) {
    wp_set_current_user(1);
    $product = new WC_Product_Simple();
    $product->set_name('App-Test Lernpaket');
    $product->set_regular_price('39');
    $product->set_virtual(true);
    $product->set_sku('BP-MOBILE-TEST-'.wp_rand(1000, 9999));
    $product->set_status('publish');
    $productId = (int) $product->save();
    $_POST['bp_access'] = ['present' => '1', 'scope' => [$courseCode => ['question_bank' => '1', 'exam_simulation' => '1', 'video_course' => '1', 'navigation_trainer' => '1', 'learning_explanations' => '1']], 'duration' => '', 'renewal' => 'extend', 'refund' => 'revoke_on_full_refund'];
    do_action('woocommerce_process_product_meta', $productId);
    unset($_POST['bp_access']);
    $list = $api('GET', '/courses?per_page=100', [], $tokenA);
    foreach ($list['body']['data'] as $c) {
        if ($c['id'] === $courseId) {
            $mine = $c;
        }
    }
    $check(is_string($mine['webshop_url']) && str_contains($mine['webshop_url'], (string) get_post_field('post_name', $productId)) || is_string($mine['webshop_url']), 'gesperrter Kurs verweist auf ein Shop-Angebot');
    wp_set_current_user(1);
    $order = wc_create_order(['customer_id' => $alice]);
    $order->add_product(wc_get_product($productId), 1);
    $order->set_payment_method('stripe');
    $order->calculate_totals();
    $order->save();
    $order->payment_complete('pi_mobile_test');
    $orderId = (int) $order->get_id();
    $check(count($entitlements->forOrder($orderId)) >= 4, 'bezahlte Bestellung schaltet frei (WooCommerce)');
    $coupon = new WC_Coupon();
    $coupon->set_code('APPTEST10');
    $coupon->set_amount(10);
    $couponId = (int) $coupon->save();
} else {
    echo "  (WooCommerce nicht aktiv – manueller Zugang)\n";
    $entitlements->grantManual($alice, $courseCode, ['question_bank', 'exam_simulation', 'video_course', 'navigation_trainer', 'learning_explanations'], null, 'App-Test');
}
// Bob: gleicher Kurs (für IDOR-Prüfungen)
$entitlements->grantManual($bob, $courseCode, ['question_bank', 'exam_simulation', 'video_course', 'navigation_trainer'], null, 'App-Test IDOR');
$list = $api('GET', '/courses?per_page=100', [], $tokenA);
foreach ($list['body']['data'] as $c) {
    if ($c['id'] === $courseId) {
        $mine = $c;
    }
}
$check($mine['access']['status'] === 'active' && $mine['access']['purchased'] === true && $mine['features']['smarttrainer']['full'] === true && $mine['webshop_url'] === null, 'nach Kauf: active, voller Smarttrainer, kein Shop-Link');
$detail = $api('GET', "/courses/{$courseId}", [], $tokenA);
$check($detail['status'] === 200 && $detail['body']['data']['modules_count'] > 0 && $detail['body']['data']['exam_options_count'] > 0, 'Kursdetails mit Modulen und Prüfungsoptionen');
$detailByCode = $api('GET', '/courses/'.strtolower($courseCode), [], $tokenA);
$check($detailByCode['status'] === 200 && $detailByCode['body']['data']['id'] === $courseId, 'Kurs auch über den Kurscode');

echo "Kursempfehlungen und Ablauf (1.9.0)\n";
$byCode = [];
foreach ($content->courses() as $c) {
    $byCode[$c->code] = $c;
}
$listByCode = array_column($list['body']['data'], null, 'code');
$check(isset($mine['relation']) && $mine['relation'] === ['hidden' => false, 'recommended' => false, 'recommended_for' => []] && $mine['access']['expires_soon'] === false && $mine['access']['renew_url'] === null, 'eigener Kurs: relation neutral, expires_soon false, renew_url null');
if ($courseCode === 'SBF-SEE') {
    $combo = $listByCode['SBF-SEE-BIN'] ?? null;
    $addon = $listByCode['ADD-SEE-TO-BIN'] ?? null;
    $check($combo !== null && $combo['relation']['hidden'] === true && $combo['relation']['recommended'] === false, 'SBF-See-Besitzer: Kombi markiert (hidden), bleibt in der Liste');
    $check($addon !== null && $addon['relation']['recommended'] === true && $addon['relation']['recommended_for'] === [['id' => $courseId, 'name' => (string) $byCode['SBF-SEE']->name]], 'Ergänzung See → Binnen empfohlen mit id und Name des auslösenden Kurses');
    $r = $api('GET', '/courses/'.$byCode['SBF-SEE-BIN']->id, [], $tokenA);
    $check($r['status'] === 200 && $r['body']['data']['relation']['hidden'] === true, 'ausgeblendeter Kurs per Deep-Link weiter abrufbar (Kursdetails mit relation)');
}
$ubiUntil = gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS);
$ubiIds = $entitlements->grantManual($alice, 'UBI', ['question_bank'], $ubiUntil, 'App-Test Ablauf');
$list2 = array_column($api('GET', '/courses?per_page=100', [], $tokenA)['body']['data'], null, 'code');
$ubi = $list2['UBI'] ?? null;
$check($ubi !== null && $ubi['access']['expires_soon'] === true && $ubi['access']['expires_at'] === gmdate('Y-m-d\TH:i:s\Z', strtotime($ubiUntil.' UTC')) && is_string($ubi['access']['renew_url']) && $ubi['access']['renew_url'] !== '', 'Zugang endet in 5 Tagen: expires_soon, expires_at, renew_url');
$check(($list2['SRC']['relation']['recommended_for'] ?? null) === [['id' => (string) $byCode['UBI']->id, 'name' => (string) $byCode['UBI']->name]] && $list2['SRC-UBI']['relation']['hidden'] === true, 'UBI-Besitzer: SRC empfohlen, SRC & UBI ausgeblendet');
$entitlements->grantManual($alice, 'UBI', ['question_bank'], null, 'App-Test unbefristet');
$ubi = array_column($api('GET', '/courses?per_page=100', [], $tokenA)['body']['data'], null, 'code')['UBI'] ?? null;
$check($ubi !== null && $ubi['access']['expires_soon'] === false && $ubi['access']['renew_url'] === null, 'unbefristeter Zugang: kein Ablaufhinweis');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d AND course_code = 'UBI'", $alice));
// Kostenloser Teilumfang zählt nicht als Besitz.
$wpdb->insert($p.'access_rule', ['course_code' => 'UBI', 'feature_code' => 'question_bank', 'access_type' => 'free_limited', 'limit_type' => 'questions_per_module', 'limit_value' => 3, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
$trialRule = (int) $wpdb->insert_id;
$list3 = array_column($api('GET', '/courses?per_page=100', [], $tokenA)['body']['data'], null, 'code');
$check(($list3['UBI']['access']['trial'] ?? false) === true && $list3['UBI']['access']['expires_soon'] === false && $list3['SRC']['relation']['recommended'] === false && $list3['SRC-UBI']['relation']['hidden'] === false, 'Teilumfang UBI: keine Empfehlung, nichts ausgeblendet, kein Ablaufhinweis');
$wpdb->delete($p.'access_rule', ['id' => $trialRule]);
$ov = $api('GET', "/courses/{$courseId}/overview", [], $tokenA);
$check($ov['status'] === 200 && count($ov['body']['data']['modules']) > 0 && $ov['body']['data']['continue_learning']['mode'] === 'smarttrainer', 'Übersicht mit Modulen und Weiterlernen');
$cl = $ov['body']['data']['continue_learning'];
$clMod = array_values(array_filter($ov['body']['data']['modules'], static fn ($m) => $m['id'] === $cl['module_id']))[0] ?? null;
$check($clMod !== null && array_key_exists('topic', $cl) && $cl['open'] === $clMod['progress']['new'] + $clMod['progress']['unsure'] + $clMod['progress']['learning'] && $cl['trainer_query']['module_id'] === $cl['module_id']
    && ($cl['topic'] === null ? $cl['trainer_query']['mode'] === 'module' : ($cl['trainer_query']['mode'] === 'topic' && $cl['trainer_query']['topic'] === $cl['topic'] && in_array($cl['topic'], array_column($clMod['topics'], 'name'), true))), 'Weiterlernen: Thema, offene Fragen und Parameter für trainer/next');
$tq = $cl['trainer_query'];
$nextByQuery = $api('GET', "/courses/{$courseId}/trainer/next?".http_build_query($tq), [], $tokenA);
$check($nextByQuery['status'] === 200, 'trainer_query aus Weiterlernen funktioniert mit trainer/next');
$mods = $api('GET', "/courses/{$courseId}/modules", [], $tokenA);
$check($mods['status'] === 200 && $mods['body']['data'][0]['order'] === 1 && isset($mods['body']['data'][0]['progress']['percent']), 'Module mit Fortschritt');

echo "Smarttrainer (gleicher Lernstand wie im Web)\n";
$next = $api('GET', "/courses/{$courseId}/trainer/next", [], $tokenA);
$q = $next['body']['data'] ?? [];
$check($next['status'] === 200 && isset($q['answer_token'], $q['question']['answers'][0]['id']) && ! str_contains((string) wp_json_encode($q), 'is_correct'), 'nächste Frage mit answer_token, ohne Lösung');
$check(in_array($q['question']['answer_selection'] ?? '', ['single', 'multiple', 'open'], true) && is_array($q['question']['prompt_media']), 'Antworttyp und Medien');
$questionId = (int) $q['question']['id'];
$revisionId = (int) $q['question']['revision_id'];
$token = (string) $q['answer_token'];
$answerBody = ['answer_token' => $token, 'question_id' => (string) $questionId, 'revision_id' => (string) $revisionId, 'selected_answer_ids' => $q['question']['answer_selection'] === 'open' ? [] : [$q['question']['answers'][0]['id']], 'known' => $q['question']['answer_selection'] === 'open' ? true : null, 'mode' => 'smarttrainer'];
if ($q['question']['answer_selection'] === 'multiple') {
    $answerBody['selected_answer_ids'] = [$q['question']['answers'][0]['id']];
}
$r = $api('POST', "/courses/{$courseId}/attempts", $answerBody, $tokenA);
$check($r['status'] === 422 && isset($r['body']['errors']['Idempotency-Key']), 'Antwort ohne Idempotency-Key → 422');
$key = $uuid();
$before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d", $alice));
$ans = $api('POST', "/courses/{$courseId}/attempts", $answerBody, $tokenA, ['Idempotency-Key' => $key]);
$res = $ans['body']['data'] ?? [];
$check($ans['status'] === 201 && isset($res['attempt_id'], $res['correct'], $res['correct_answer_ids'], $res['learning_state']) && array_key_exists('explanation', $res), 'Antwort ausgewertet: richtig/falsch, Lösung, Erklärung, Lernstatus');
$after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d", $alice));
$progress = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}progress WHERE user_id = %d AND question_id = %d", $alice, $questionId));
$check($after === $before + 1 && $progress && (int) $progress->attempts === 1 && $progress->learn_status === $res['learning_status'], 'Versuch und Fortschritt in denselben Tabellen wie im Web');
$replay = $api('POST', "/courses/{$courseId}/attempts", $answerBody, $tokenA, ['Idempotency-Key' => $key]);
$check($replay['status'] === 201 && $replay['body']['data']['attempt_id'] === $res['attempt_id'] && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d", $alice)) === $after, 'Wiederholung mit gleichem Key: gleiches Ergebnis, kein zweiter Versuch');
$other = $api('GET', "/courses/{$courseId}/trainer/next", [], $tokenA);
$r = $api('POST', "/courses/{$courseId}/attempts", ['answer_token' => (string) $other['body']['data']['answer_token'], 'selected_answer_ids' => [$other['body']['data']['question']['answers'][0]['id'] ?? '1'], 'known' => true], $tokenA, ['Idempotency-Key' => $key]);
$check($r['status'] === 409 && $code($r) === 'IDEMPOTENCY_KEY_REUSED', 'gleicher Key, andere Antwort → 409');
$r = $api('POST', "/courses/{$courseId}/attempts", $answerBody, $tokenA, ['Idempotency-Key' => $uuid()]);
$check(in_array($r['status'], [200, 201], true) && $r['body']['data']['repeated'] === true && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d", $alice)) === $after, 'gleiches Frage-Token zählt nur einmal (wie Web)');
$r = $api('GET', "/courses/{$courseId}/attempts/{$res['attempt_id']}", [], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['attempt_id'] === $res['attempt_id'], 'gespeicherte Rückmeldung abrufbar');
$learning = new LearningService($content, new QuestionPresenter($content), new AccessService());
$web = $learning->courseTotal($alice, $courseRow);
$prog = $api('GET', "/courses/{$courseId}/progress", [], $tokenA);
$check($prog['status'] === 200 && $prog['body']['data']['overall']['total'] === $web['questions'] && $prog['body']['data']['overall']['unsure'] + $prog['body']['data']['overall']['learning'] + $prog['body']['data']['overall']['mastered'] === $web['unsure'] + $web['learning'] + $web['mastered'], 'Fortschritt identisch mit der Web-Berechnung');

echo "Fremde Daten (IDOR)\n";
$r = $login('mobile_kunde_b@example.test', 'Mobil-Passwort-456!');
$tokenB = (string) $r['body']['data']['access_token'];
$r = $api('POST', "/courses/{$courseId}/attempts", ['answer_token' => (string) $other['body']['data']['answer_token'], 'selected_answer_ids' => ['1']], $tokenB, ['Idempotency-Key' => $uuid()]);
$check($r['status'] === 422 && $r['body']['detail'] === 'Frage-Token gehört zu einem anderen Konto.', 'Frage-Token eines anderen Kontos abgewiesen');
$r = $api('GET', "/courses/{$courseId}/attempts/{$res['attempt_id']}", [], $tokenB);
$check($r['status'] === 404, 'fremder Versuch → 404');
$r = $api('DELETE', '/me/sessions/'.$tokensA2['session_id'], [], $tokenB, ['X-Tenant-Slug' => null]);
$check($r['status'] === 404, 'fremde Sitzung nicht widerrufbar → 404');

echo "Favoriten und Sammlungen\n";
$r = $api('PUT', "/courses/{$courseId}/favorites/{$questionId}", [], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['question_id'] === (string) $questionId, 'Favorit gespeichert');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}favorite WHERE user_id = %d AND question_id = %d", $alice, $questionId)), 'Favorit in bp_favorite (wie Web)');
$ckey = $uuid();
$col = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Vorfahrt'], $tokenA, ['Idempotency-Key' => $ckey]);
$colId = (string) ($col['body']['data']['id'] ?? '');
$check($col['status'] === 201 && $colId !== '' && $col['body']['data']['name'] === 'Vorfahrt', 'Sammlung erstellt');
$again = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Vorfahrt'], $tokenA, ['Idempotency-Key' => $ckey]);
$check($again['status'] === 201 && $again['body']['data']['id'] === $colId, 'Sammlung: Wiederholung idempotent');
// Verwaister Idempotenz-Eintrag (PHP-Prozess hart abgebrochen, status_code 0): nach 60 s übernehmbar.
$orphanKey = $uuid();
$orphanRow = ['user_id' => $alice, 'scope' => 'collection:'.$courseId, 'idem_key' => $orphanKey, 'request_hash' => hash('sha256', 'collection:'.$courseId.'|'.wp_json_encode(['name' => 'Verwaist'])), 'status_code' => 0, 'created_at' => gmdate('Y-m-d H:i:s', time() - 5), 'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS)];
$wpdb->insert($p.'mobile_idempotency', $orphanRow);
$r = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Verwaist'], $tokenA, ['Idempotency-Key' => $orphanKey]);
$check($r['status'] === 409 && $code($r) === 'REQUEST_IN_PROGRESS', 'Eintrag „in Arbeit“ (5 s alt) → 409 REQUEST_IN_PROGRESS');
$wpdb->update($p.'mobile_idempotency', ['created_at' => gmdate('Y-m-d H:i:s', time() - 120)], ['idem_key' => $orphanKey]);
$r = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Anders'], $tokenA, ['Idempotency-Key' => $orphanKey]);
$check($r['status'] === 409 && $code($r) === 'IDEMPOTENCY_KEY_REUSED', 'verwaist, aber anderer Inhalt → 409 IDEMPOTENCY_KEY_REUSED (keine Übernahme)');
$r = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Verwaist'], $tokenA, ['Idempotency-Key' => $orphanKey]);
$orphanStored = $wpdb->get_row($wpdb->prepare("SELECT status_code, resource FROM {$p}mobile_idempotency WHERE idem_key = %s", $orphanKey));
$check($r['status'] === 201 && $r['body']['data']['name'] === 'Verwaist' && (int) $orphanStored->status_code === 201, 'verwaister Eintrag (2 Min. alt) wird übernommen und ausgeführt, Antwort gespeichert');
$r2 = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'Verwaist'], $tokenA, ['Idempotency-Key' => $orphanKey]);
$check($r2['status'] === 201 && $r2['body']['data']['id'] === $r['body']['data']['id'], 'danach normale Wiederholung (gespeicherte Antwort)');
$api('DELETE', "/courses/{$courseId}/favorite-collections/".$r['body']['data']['id'], [], $tokenA);
$r = $api('POST', "/courses/{$courseId}/favorite-collections", ['name' => 'vorfahrt'], $tokenA);
$check($r['status'] === 422, 'doppelter Sammlungsname → 422');
$r = $api('PUT', "/courses/{$courseId}/favorites/{$questionId}", ['collection_id' => $colId], $tokenA);
$check($r['status'] === 200 && in_array($colId, $r['body']['data']['collection_ids'], true), 'Favorit in Sammlung einsortiert');
$favs = $api('GET', "/courses/{$courseId}/favorites?collection_id={$colId}", [], $tokenA);
$check($favs['status'] === 200 && count($favs['body']['data']) === 1 && $favs['body']['meta']['collections'][0]['favorite_count'] === 1, 'Favoriten nach Sammlung gefiltert');
$r = $api('PATCH', "/courses/{$courseId}/favorite-collections/{$colId}", ['name' => 'Vorfahrtsregeln'], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['name'] === 'Vorfahrtsregeln', 'Sammlung umbenannt');
$r = $api('PATCH', "/courses/{$courseId}/favorite-collections/{$colId}", ['name' => 'Gekapert'], $tokenB);
$check($r['status'] === 404, 'fremde Sammlung → 404');
$r = $api('GET', "/courses/{$courseId}/favorites?collection_id={$colId}", [], $tokenB);
$check($r['status'] === 404, 'fremde Sammlung als Filter → 404');
$r = $api('GET', "/courses/{$courseId}/trainer/next?mode=collection&collection_id={$colId}", [], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['question']['id'] === (string) $questionId, 'Lernen aus der Sammlung');

echo "Favoriten: saved_at/assigned_at und Sortierung\n";
$allowed = array_keys($learning->allowedQuestions($alice, $courseRow, array_map(static fn ($m) => (int) $m->id, $content->modules((int) $courseRow->id))));
$q2 = (int) (array_values(array_filter($allowed, static fn ($id) => (int) $id !== $questionId))[0] ?? 0);
$check($q2 > 0, 'zweite freigeschaltete Frage für die Sortierung');
$api('PUT', "/courses/{$courseId}/favorites/{$q2}", ['collection_id' => $colId], $tokenA);
$wpdb->update($p.'favorite', ['created_at' => '2026-01-01 10:00:00'], ['user_id' => $alice, 'question_id' => $questionId]);
$wpdb->update($p.'favorite', ['created_at' => '2026-02-01 10:00:00'], ['user_id' => $alice, 'question_id' => $q2]);
$wpdb->update($p.'favorite_collection_item', ['created_at' => '2026-03-05 08:00:00'], ['collection_id' => (int) $colId, 'question_id' => $questionId]);
$wpdb->update($p.'favorite_collection_item', ['created_at' => '2026-03-01 08:00:00'], ['collection_id' => (int) $colId, 'question_id' => $q2]);
$ids = static fn (array $r): array => array_map(static fn ($f) => (int) $f['question_id'], $r['body']['data'] ?? []);
$favs = $api('GET', "/courses/{$courseId}/favorites", [], $tokenA);
$check($favs['status'] === 200 && $ids($favs) === [$q2, $questionId] && $favs['body']['meta']['sort'] === 'saved_at' && $favs['body']['meta']['order'] === 'desc', 'Standard: saved_at absteigend');
$check(($favs['body']['data'][0]['saved_at'] ?? null) === '2026-02-01T10:00:00Z' && $favs['body']['data'][1]['saved_at'] === '2026-01-01T10:00:00Z' && ! array_key_exists('assigned_at', $favs['body']['data'][0]), 'saved_at = bp_favorite.created_at (UTC), ohne Filter kein assigned_at');
$check(array_filter($favs['body']['data'], static fn ($f) => ! is_string($f['saved_at']) || $f['saved_at'] !== $f['created_at']) === [], 'saved_at nie null, created_at (veraltet) gleich');
$r = $api('GET', "/courses/{$courseId}/favorites?order=asc", [], $tokenA);
$check($r['status'] === 200 && $ids($r) === [$questionId, $q2], 'order=asc');
$r = $api('GET', "/courses/{$courseId}/favorites?collection_id={$colId}&sort=assigned_at", [], $tokenA);
$check($r['status'] === 200 && $ids($r) === [$questionId, $q2] && $r['body']['data'][0]['assigned_at'] === '2026-03-05T08:00:00Z' && $r['body']['meta']['collection_id'] === $colId, 'Sammlung: sort=assigned_at mit assigned_at aus bp_favorite_collection_item');
$r = $api('GET', "/courses/{$courseId}/favorites?collection_id={$colId}", [], $tokenA);
$check($r['status'] === 200 && $ids($r) === [$q2, $questionId] && is_string($r['body']['data'][1]['assigned_at']), 'Sammlung ohne sort: saved_at, assigned_at trotzdem geliefert');
$r = $api('GET', "/courses/{$courseId}/favorites?sort=assigned_at", [], $tokenA);
$check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED' && isset($r['body']['errors']['sort']), 'sort=assigned_at ohne collection_id → 422');
$r = $api('GET', "/courses/{$courseId}/favorites?sort=updated_at", [], $tokenA);
$check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED', 'unbekannte Sortierung → 422');
$r = $api('GET', "/courses/{$courseId}/favorites?order=sideways", [], $tokenA);
$check($r['status'] === 422, 'unbekannte Reihenfolge → 422');
$wpdb->update($p.'favorite', ['created_at' => '2026-01-01 10:00:00'], ['user_id' => $alice, 'question_id' => $q2]);
$r = $api('GET', "/courses/{$courseId}/favorites", [], $tokenA);
$desc = $ids($r);
$r = $api('GET', "/courses/{$courseId}/favorites?order=asc", [], $tokenA);
$check($desc === [max($q2, $questionId), min($q2, $questionId)] && $ids($r) === array_reverse($desc), 'gleicher Zeitpunkt: stabile Reihenfolge nach Frage-ID');
$api('PUT', "/courses/{$courseId}/favorites/{$questionId}", ['collection_id' => $colId], $tokenA);
$check($wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$p}favorite_collection_item WHERE collection_id = %d AND question_id = %d", (int) $colId, $questionId)) === '2026-03-05 08:00:00'
    && $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$p}favorite WHERE user_id = %d AND question_id = %d", $alice, $questionId)) === '2026-01-01 10:00:00', 'erneutes Speichern ändert saved_at/assigned_at nicht');
$mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
$wpdb->query("SET SESSION sql_mode = ''");
$wpdb->query($wpdb->prepare("UPDATE {$p}favorite SET created_at = '0000-00-00 00:00:00' WHERE user_id = %d AND question_id = %d", $alice, $q2));
$wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', $mode));
$r = $api('GET', "/courses/{$courseId}/favorites", [], $tokenA);
$zero = array_values(array_filter($r['body']['data'] ?? [], static fn ($f) => (int) $f['question_id'] === $q2))[0] ?? [];
$check($r['status'] === 200 && ($zero['saved_at'] ?? null) === '1970-01-01T00:00:00Z' && $ids($r)[1] === $q2, 'Nulldatum (Altdaten): saved_at 1970-01-01T00:00:00Z, sortiert ans Ende');
$check(! array_key_exists('updated_at', $r['body']['meta']['collections'][0] ?? ['updated_at' => 1]), 'Sammlung ohne updated_at');
$api('DELETE', "/courses/{$courseId}/favorites/{$q2}", [], $tokenA);
$r = $api('DELETE', "/courses/{$courseId}/favorite-collections/{$colId}", [], $tokenA);
$favs = $api('GET', "/courses/{$courseId}/favorites", [], $tokenA);
$check($r['status'] === 204 && count($favs['body']['data']) === 1 && $favs['body']['data'][0]['collection_id'] === null, 'Sammlung gelöscht, Favorit bleibt');
$r = $api('DELETE', "/courses/{$courseId}/favorites/{$questionId}", [], $tokenA);
$check($r['status'] === 204 && $api('GET', "/courses/{$courseId}/trainer/next?mode=favorites", [], $tokenA)['status'] === 204, 'Favorit entfernt; Modus Favoriten leer → 204');
$r = $api('GET', "/courses/{$courseId}/mistakes", [], $tokenA);
$check($r['status'] === 200 && is_array($r['body']['data']), 'Fehlerliste');

echo "Prüfung\n";
$exams = $api('GET', "/courses/{$courseId}/exams", [], $tokenA);
$kinds = array_column($exams['body']['data'] ?? [], 'kind');
$check($exams['status'] === 200 && in_array('fixed_paper', $kinds, true) && in_array('rule_set', $kinds, true), 'Übersicht mit Regelwerk und festen Bögen');
// Seit 1.6.0: Bestehensgrenzen strukturiert und Fragetypen vor dem Start.
$sim = array_values(array_filter($exams['body']['data'], static fn ($o) => $o['kind'] === 'rule_set'))[0];
$pr = $sim['pass_rule'];
$partsText = implode(' · ', array_map(static fn ($pt) => "{$pt['label']} {$pt['minimum_correct']}/{$pt['of']}", $pr['parts']));
$check(($pr['parts'] !== [] ? ($pr['minimum_correct'] === null && str_starts_with($pr['description'], $partsText) && array_sum(array_column($pr['parts'], 'of')) === $sim['question_count']) : is_int($pr['minimum_correct'])), 'pass_rule: Grenzen je Teil passend zum Text ('.$pr['description'].')');
$check($courseCode !== 'SBF-SEE' || ($pr['parts'] !== [] && array_column($pr['parts'], 'minimum_correct') === [5, 18] && array_column($pr['parts'], 'of') === [7, 23]), 'SBF See: Basis 5/7 und See 18/23');
$check($sim['question_types'] === null || array_sum($sim['question_types']) === $sim['question_count'], 'Zufallsprüfung: question_types null oder Summe = Fragenzahl');
$fixed = array_values(array_filter($exams['body']['data'], static fn ($o) => $o['kind'] === 'fixed_paper'))[0];
$check(is_array($fixed['question_types']) && array_sum($fixed['question_types']) === $fixed['question_count'] && array_diff(array_keys($fixed['question_types']), ['single_choice', 'multi_choice', 'open_text']) === [], 'fester Bogen: Fragen je Typ, Summe = Fragenzahl');
$paper = null;
foreach ($exams['body']['data'] as $opt) {
    if ($opt['kind'] === 'fixed_paper' && $opt['startable']) {
        $paper = $opt;
        break;
    }
}
$check($paper !== null && array_key_exists('locked', $paper) && array_key_exists('lock_reason', $paper) && array_key_exists('unlock_at', $paper), 'Bogen mit locked/lock_reason/unlock_at');
$startKey = $uuid();
$start = $api('POST', "/courses/{$courseId}/exams", ['mode' => 'official_simulation', 'paper_id' => $paper['id']], $tokenA, ['Idempotency-Key' => $startKey]);
$exam = $start['body']['data'] ?? [];
$examId = (string) ($exam['exam_id'] ?? '');
$check($start['status'] === 201 && $exam['status'] === 'running' && count($exam['questions']) === $paper['question_count'] && $exam['expires_at'] !== null, 'Bogen gestartet (Serverzeit, Fragen)');
$check(! str_contains((string) wp_json_encode($exam), 'correct') && ! str_contains((string) wp_json_encode($exam), 'explanation'), 'laufende Prüfung ohne Lösungen/Erklärungen');
$r = $api('POST', "/courses/{$courseId}/exams", ['paper_id' => $paper['id']], $tokenA);
$check($r['status'] === 201 && $r['body']['data']['exam_id'] === $examId, 'erneuter Start setzt die laufende Prüfung fort');
$first = $exam['questions'][0];
$pick = $first['answer_selection'] === 'open' ? [] : [$first['answers'][0]['id']];
if ($first['answer_selection'] === 'open') {
    $api('POST', "/exams/{$examId}/answers/1/reveal", [], $tokenA);
}
$save = $api('PUT', "/exams/{$examId}/answers/1", ['selected_answer_ids' => $pick, 'known' => $first['answer_selection'] === 'open' ? true : null], $tokenA);
$check($save['status'] === 200 && $save['body']['data']['saved'] === true && $save['body']['data']['answered'] === true && ! isset($save['body']['data']['correct']), 'Antwort gespeichert ohne Bewertung');
$r = $api('GET', "/exams/{$examId}", [], $tokenB);
$check($r['status'] === 404, 'fremde Prüfung → 404');
$r = $api('PUT', "/exams/{$examId}/answers/1", ['selected_answer_ids' => $pick], $tokenB);
$check($r['status'] === 404, 'Antwort in fremder Prüfung → 404');
$resume = $api('GET', "/exams/{$examId}", [], $tokenA);
$check($resume['status'] === 200 && $resume['body']['data']['answers'][0]['answered'] === true && $resume['body']['data']['remaining_seconds'] > 0, 'laufende Prüfung fortsetzen (gespeicherte Antworten)');
$r = $api('GET', "/exams/{$examId}/result", [], $tokenA);
$check($r['status'] === 409 && $code($r) === 'EXAM_NOT_SUBMITTED', 'Ergebnis vor Abgabe → 409');
$check($resume['body']['data']['flagged_count'] === 0 && $resume['body']['data']['answers'][0]['flagged'] === false && $resume['body']['data']['questions'][0]['flagged'] === false, 'Markierung: anfangs keine Frage markiert');
$f = $api('PUT', "/exams/{$examId}/answers/2/flag", ['flagged' => true], $tokenA);
$check($f['status'] === 200 && $f['body']['data'] === ['question_id' => '2', 'flagged' => true, 'flagged_count' => 1], 'Frage 2 zur Überprüfung markiert (Adressierung über Position)');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT flagged FROM {$p}exam_session_question WHERE session_id = %d AND position = 2", (int) $examId)) === 1, 'Markierung serverseitig gespeichert (exam_session_question.flagged)');
$f = $api('PUT', "/exams/{$examId}/answers/2/flag", ['flagged' => true], $tokenA);
$check($f['status'] === 200 && $f['body']['data']['flagged_count'] === 1, 'erneutes Markieren idempotent');
$api('PUT', "/exams/{$examId}/answers/3/flag", ['flagged' => true], $tokenA);
$f = $api('PUT', "/exams/{$examId}/answers/3/flag", ['flagged' => false], $tokenA);
$check($f['status'] === 200 && $f['body']['data']['flagged'] === false && $f['body']['data']['flagged_count'] === 1, 'Markierung entfernt');
$r = $api('PUT', "/exams/{$examId}/answers/2/flag", [], $tokenA);
$check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED' && isset($r['body']['errors']['flagged']), 'flagged fehlt → 422');
$r = $api('PUT', "/exams/{$examId}/answers/999/flag", ['flagged' => true], $tokenA);
$check($r['status'] === 404, 'unbekannte Position → 404');
$r = $api('PUT', "/exams/{$examId}/answers/2/flag", ['flagged' => true], $tokenB);
$check($r['status'] === 404, 'Markierung in fremder Prüfung → 404');
$save = $api('PUT', "/exams/{$examId}/answers/1", ['selected_answer_ids' => $pick, 'known' => $first['answer_selection'] === 'open' ? true : null, 'marked_for_review' => true], $tokenA);
$check($save['status'] === 200 && $save['body']['data']['marked_for_review'] === true && $save['body']['data']['flagged'] === true, 'marked_for_review beim Antworten speichert die Markierung');
$resume = $api('GET', "/exams/{$examId}", [], $tokenA)['body']['data'] ?? [];
$check(($resume['flagged_count'] ?? null) === 2 && $resume['answers'][1]['flagged'] === true && $resume['answers'][1]['marked_for_review'] === true && $resume['questions'][1]['flagged'] === true && $resume['answers'][2]['flagged'] === false, 'Fortsetzen stellt Markierungen wieder her (flagged je Frage, flagged_count)');
$sub = $api('POST', "/exams/{$examId}/submit", [], $tokenA);
$result = $sub['body']['data'] ?? [];
$check($sub['status'] === 200 && $result['status'] === 'evaluated' && is_bool($result['passed']) && $result['question_count'] === $paper['question_count'] && isset($result['parts'], $result['by_topic'], $result['review']) && array_key_exists('reduced', $result), 'Abgabe: Ergebnis mit Teilergebnissen, Themen, Auswertung');
$check(count($result['wrong_question_ids']) >= $paper['question_count'] - 1, 'falsch beantwortete Fragen aufgeführt');
$check($api('POST', "/exams/{$examId}/submit", [], $tokenA)['body']['data']['correct_count'] === $result['correct_count'], 'erneute Abgabe liefert dasselbe Ergebnis');
$r = $api('PUT', "/exams/{$examId}/answers/2", ['selected_answer_ids' => $pick], $tokenA);
$check($r['status'] === 409 && $code($r) === 'EXAM_ALREADY_SUBMITTED', 'Antwort nach Abgabe → 409');
$r = $api('PUT', "/exams/{$examId}/answers/3/flag", ['flagged' => true], $tokenA);
$check($r['status'] === 409 && $code($r) === 'EXAM_ALREADY_SUBMITTED' && (int) $wpdb->get_var($wpdb->prepare("SELECT flagged FROM {$p}exam_session_question WHERE session_id = %d AND position = 3", (int) $examId)) === 0, 'Markieren nach Abgabe → 409, nichts gespeichert');
$check($result['flagged_count'] === 2 && $result['review'][1]['flagged'] === true && $result['review'][2]['flagged'] === false, 'Ergebnis zeigt markierte Fragen');
$r = $api('GET', "/exams/{$examId}/result", [], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['exam_id'] === $examId, 'Ergebnis abrufbar');
$r = $api('GET', "/courses/{$courseId}/trainer/next?mode=exam_mistakes&exam_id={$examId}", [], $tokenA);
$check($r['status'] === 200 && in_array($r['body']['data']['question']['id'], $result['wrong_question_ids'], true), 'Fehler der Prüfung üben');
$exams = $api('GET', "/courses/{$courseId}/exams", [], $tokenA);
$again = array_values(array_filter($exams['body']['data'], static fn ($o) => $o['id'] === $paper['id']))[0] ?? [];
$check(($again['last_result']['exam_id'] ?? '') === $examId, 'letztes Ergebnis am Bogen');
$DE = \Bootspruefung\Learning\Support\DomainException::class;
$mapped = static fn (\Throwable $e): array => (static function () use ($e): array {
    $res = \Bootspruefung\Learning\Mobile\Controllers\BaseController::wrap(static function () use ($e) {
        throw $e;
    }, false)(new WP_REST_Request('GET', '/boatingschool/v1/x'));

    return [$res->get_status(), $res->get_data()['code'] ?? '', $res->get_data()['detail'] ?? ''];
})();
$check($mapped(new $DE($DE::EXAM_EXPIRED, 'Irgendein neuer Text')) === [410, 'EXAM_EXPIRED', 'Irgendein neuer Text'] && $mapped(new $DE($DE::EXAM_FINISHED, 'x'))[1] === 'EXAM_ALREADY_SUBMITTED' && $mapped(new $DE($DE::ANSWER_RATE_LIMITED, 'x'))[0] === 429 && $mapped(new $DE($DE::QUESTION_TOKEN_EXPIRED, 'x'))[1] === 'QUESTION_TOKEN_EXPIRED' && $mapped(new $DE($DE::EXAM_START_BUSY, 'x'))[1] === 'REQUEST_IN_PROGRESS', 'Fachfehler werden über den Code abgebildet, nicht über den Meldungstext');
$check($mapped(new \RuntimeException('Die Prüfungszeit ist abgelaufen.')) === [422, 'VALIDATION_FAILED', 'Die Prüfungszeit ist abgelaufen.'] && $mapped(new $DE($DE::EXAM_NOT_STARTABLE, 'y'))[0] === 409, 'Meldungstext allein bestimmt den Code nicht mehr; Fachfehler ohne Code → 422');
try {
    (new \Bootspruefung\Learning\Exams\Services\ExamService($content, new QuestionPresenter($content), new AccessService()))->saveAnswer($alice, (int) $examId, 1, [], null);
    $svcErr = null;
} catch (\RuntimeException $e) {
    $svcErr = $e;
}
$check($svcErr instanceof $DE && $svcErr->errorCode() === $DE::EXAM_FINISHED && $svcErr->getMessage() === 'Die Prüfung ist bereits abgeschlossen.', 'ExamService wirft Code EXAM_FINISHED, Meldung (Web) unverändert');

echo "Prüfung nach Zugangsende (Erstattung, Sperre)\n";
$dora = $customer('mobile_kundin_d', 'Mobil-Passwort-321!');
$doraIds = $entitlements->grantManual($dora, $courseCode, ['question_bank', 'exam_simulation'], null, 'App-Test Zugangsende');
$tokD = (string) ($login('mobile_kundin_d@example.test', 'Mobil-Passwort-321!')['body']['data']['access_token'] ?? '');
$ds = $api('POST', "/courses/{$courseId}/exams", ['paper_id' => $paper['id']], $tokD);
$dExam = (string) ($ds['body']['data']['exam_id'] ?? '');
$dFirst = $ds['body']['data']['questions'][0] ?? [];
$dPick = ($dFirst['answer_selection'] ?? '') === 'open' ? [] : [$dFirst['answers'][0]['id'] ?? '1'];
if (($dFirst['answer_selection'] ?? '') === 'open') {
    $api('POST', "/exams/{$dExam}/answers/1/reveal", [], $tokD);
}
$da = $api('PUT', "/exams/{$dExam}/answers/1", ['selected_answer_ids' => $dPick, 'known' => ($dFirst['answer_selection'] ?? '') === 'open' ? true : null], $tokD);
$check($ds['status'] === 201 && $da['status'] === 200, 'Prüfung gestartet und erste Antwort gespeichert');
wp_set_current_user(1);
foreach ($doraIds as $eid) {
    $entitlements->setStatusManual($eid, 'revoked', 'App-Test: Erstattung');
}
wp_set_current_user(0);
if ((new AccessService())->can($dora, $courseCode, AccessService::EXAM)) {
    echo "  (kostenloser Teilumfang der Prüfungssimulation aktiv – Zugangsende nicht prüfbar)\n";
} else {
    $r = $api('PUT', "/exams/{$dExam}/answers/2", ['selected_answer_ids' => $dPick], $tokD);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED', 'nach Zugangsende: weitere Antworten → 403 CONTENT_LOCKED (gleicher ExamService wie im Web)');
    $sub = $api('POST', "/exams/{$dExam}/submit", [], $tokD);
    $check($sub['status'] === 200 && $sub['body']['data']['status'] === 'evaluated' && $api('GET', "/exams/{$dExam}/result", [], $tokD)['status'] === 200, 'nach Zugangsende: Abgabe möglich, gespeicherte Antworten werden gewertet, Ergebnis abrufbar');
    $r = $api('POST', "/courses/{$courseId}/exams", ['paper_id' => $paper['id']], $tokD);
    $check($r['status'] === 403 && str_starts_with($code($r), 'ENTITLEMENT_'), 'nach Zugangsende: keine neue Prüfung (403 '.$code($r).')');
}

echo "Videos\n";
$videos = $api('GET', "/courses/{$courseId}/videos", [], $tokenA);
$lesson = $videos['body']['data'][0]['lessons'][0] ?? null;
$check($videos['status'] === 200 && $lesson !== null, 'Videomodule mit Lektionen');
// Knoten als eigener Bereich: Kursdetails nennen das Kapitel „Knoten“ (falls vorhanden) passend zu /videos.
$knotMod = array_values(array_filter($videos['body']['data'], static fn ($m) => $m['title'] === 'Knoten'))[0] ?? null;
$knots = $api('GET', "/courses/{$courseId}", [], $tokenA)['body']['data']['knots'] ?? 'fehlt';
$check($knots !== 'fehlt' && ($knotMod === null ? $knots === null : ($knots['module_id'] === $knotMod['id'] && $knots['lessons'] === count($knotMod['lessons']) && $knots['locked'] === false && $knots['webshop_url'] === null)), 'Kursdetails: knots passend zum Videokapitel „Knoten“'.($knotMod === null ? ' (Kurs ohne Knoten-Kapitel → null)' : ''));
if ($lesson !== null) {
    $l = $api('GET', '/videos/'.$lesson['id'], [], $tokenA);
    $media = $l['body']['data']['media'] ?? [];
    $check($l['status'] === 200 && in_array($media['kind'] ?? '', ['video', 'placeholder'], true) && ($media['kind'] !== 'placeholder' || ($media['url'] === null && $media['available'] === false)), 'Lektion: Video oder Platzhalter ohne URL');
    $pr = $api('PUT', '/videos/'.$lesson['id'].'/progress', ['position_seconds' => 42, 'completed' => true], $tokenA);
    $check($pr['status'] === 200 && $pr['body']['data']['completed'] === true && (int) $wpdb->get_var($wpdb->prepare("SELECT position_seconds FROM {$p}video_progress WHERE user_id = %d AND lesson_id = %d", $alice, (int) $lesson['id'])) === 42, 'Sehfortschritt gespeichert (bp_video_progress)');
    $r = $api('GET', '/videos/'.$lesson['id'], [], $tokenA, ['X-Tenant-Slug' => 'falsch']);
    $check($r['status'] === 403, 'Lektion nur mit richtigem Tenant');
}

echo "Navigation\n";
$tasks = $api('GET', "/courses/{$courseId}/navigation-tasks?per_page=5", [], $tokenA);
$task = $tasks['body']['data'][0] ?? null;
$check($tasks['status'] === 200 && $task !== null && $tasks['body']['meta']['pagination']['per_page'] === 5, 'Aufgabenliste mit Seitenumbruch');
if ($task !== null) {
    $t = $api('GET', '/navigation-tasks/'.$task['id']."?course_id={$courseId}", [], $tokenA);
    $part = $t['body']['data']['parts'][0] ?? null;
    $check($t['status'] === 200 && $part !== null && $part['solution'] === null, 'Teilaufgaben ohne Lösung');
    $a = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/'.$part['id'], ['answer' => '045°', 'self_assessment' => 'partly', 'course_id' => $courseId], $tokenA);
    $check($a['status'] === 200 && is_string($a['body']['data']['solution']) && $a['body']['data']['my_answer'] === '045°' && $a['body']['data']['done'] === true, 'eigene Antwort + Einschätzung → amtliche Lösung');
    $t = $api('GET', '/navigation-tasks/'.$task['id'].'?show_solution=true', [], $tokenA);
    $check($t['status'] === 200 && array_filter($t['body']['data']['parts'], static fn ($x) => $x['solution'] === null) === [], 'Lösung auf Anforderung (show_solution=true)');
    $r = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/999999', ['answer' => 'x'], $tokenA);
    $check($r['status'] === 404, 'fremde Teilaufgabe → 404');
}

// --- 1.4.0 (Navigation): automatische Prüfung der eigenen Werte (auto_check/check) -----------------
echo "Navigation: automatische Prüfung (1.4.0)\n";
if ($task !== null && $courseCode === 'SBF-SEE') {
    $t = $api('GET', '/navigation-tasks/'.$task['id']."?course_id={$courseId}", [], $tokenA);
    $parts = $t['body']['data']['parts'] ?? [];
    $byNumber = array_column($parts, null, 'number');
    $check(isset($byNumber[1], $byNumber[7]) && $byNumber[1]['auto_check'] === true && $byNumber[7]['auto_check'] === false && array_key_exists('check', $byNumber[2] ?? []), 'Aufgabe: auto_check je Teilaufgabe (NAV-01-01 ja, „Siehe Karte“ nein), Feld check');
    $check(isset($byNumber[2]) && array_key_exists('check', $byNumber[2]) && $byNumber[2]['check'] === null, 'check null ohne eigene Antwort');
    if (isset($byNumber[1], $byNumber[2], $byNumber[6], $byNumber[7])) {
        // NAV-01-01: amtlich rwK = 079° (±2°); 045° aus dem Block oben weicht ab.
        $a = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/'.$byNumber[1]['id'], ['answer' => 'rwK = 80°', 'course_id' => $courseId], $tokenA);
        $c = $a['body']['data']['check'] ?? null;
        $check($a['status'] === 200 && $a['body']['data']['auto_check'] === true && ($c['status'] ?? null) === 'within' && ($c['values'][0]['label'] ?? null) === 'rwK' && $c['values'][0]['ok'] === true && $c['values'][0]['given'] === '080°' && $c['values'][0]['expected'] === '079°' && $c['values'][0]['deviation'] == 1, 'Antwort: check im Toleranzbereich (given, expected, deviation)');
        $a = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/'.$byNumber[2]['id'], ['answer' => 'MgK 069°', 'course_id' => $courseId], $tokenA);
        $c = $a['body']['data']['check'] ?? null;
        $check(($c['status'] ?? null) === 'deviates' && $c['values'][0]['ok'] === false && $c['values'][0]['deviation'] == -5 && $c['values'][0]['expected'] === '074°' && is_string($c['message']), 'Antwort: Abweichung erkannt (berichtigter MgK 074°)');
        $a = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/'.$byNumber[6]['id'], ['answer' => 'ST 066°, 4a 171°', 'course_id' => $courseId], $tokenA);
        $c = $a['body']['data']['check'] ?? null;
        $check(($c['status'] ?? null) === 'within' && count($c['values']) === 2 && $c['values'][0]['given'] === '171°' && $c['values'][1]['given'] === '066°', 'mehrere Peilungen über Beschriftung zugeordnet');
        $a = $api('POST', '/navigation-tasks/'.$task['id'].'/parts/'.$byNumber[7]['id'], ['answer' => 'eingetragen', 'course_id' => $courseId], $tokenA);
        $check($a['status'] === 200 && $a['body']['data']['auto_check'] === false && $a['body']['data']['check'] === null && is_string($a['body']['data']['solution']), 'Teilaufgabe ohne Werte: wie bisher, check null');
        $t = $api('GET', '/navigation-tasks/'.$task['id']."?course_id={$courseId}", [], $tokenA);
        $byNumber = array_column($t['body']['data']['parts'] ?? [], null, 'number');
        $check(($byNumber[1]['check']['status'] ?? null) === 'within' && ($byNumber[2]['check']['status'] ?? null) === 'deviates', 'Aufgabe liefert check der gespeicherten Antworten');
        $check(($byNumber[1]['self_assessment'] ?? null) === 'partly' && $byNumber[1]['done'] === true && $byNumber[1]['my_answer'] === 'rwK = 80°', 'Selbsteinschätzung bleibt maßgeblich und unverändert gespeichert');
    }
}
// --- Ende 1.4.0 (Navigation) -----------------------------------------------------------------------

echo "Funktraining SRC/UBI\n";
$src = null;
foreach ($content->courses() as $c) {
    if ($c->code === 'SRC') {
        $src = $c;
    }
}
$r = $api('GET', "/courses/{$courseId}/radio", [], $tokenA);
$check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND', 'Kurs ohne Funkinhalte → 404');
$ruleId = 0;
if ($src === null) {
    echo "  (kein SRC-Kurs importiert – Funktraining übersprungen)\n";
} else {
    $srcId = (string) $src->id;
    $r = $api('GET', "/courses/{$srcId}/radio", [], $tokenA);
    $check($r['status'] === 403 && $code($r) === 'ENTITLEMENT_REQUIRED' && ($r['body']['details']['capability'] ?? '') === 'funk', 'ohne Zugang → 403 ENTITLEMENT_REQUIRED (funk)');
    $entitlements->grantManual($alice, 'SRC', ['radio_training'], null, 'App-Test Funk');
    $ov = $api('GET', "/courses/{$srcId}/radio", [], $tokenA);
    $o = $ov['body']['data'] ?? [];
    $radioWeb = new \Bootspruefung\Learning\Trainers\RadioService(new AccessService());
    $webOv = $radioWeb->overview($alice, $src);
    $check($ov['status'] === 200 && count($o['texts']) === count($webOv['texts']) && count($o['vocabulary']) === count($webOv['vocabulary']) && count($o['practice']) === count($webOv['practice']) && $ov['body']['meta']['limit'] === null, 'Übersicht wie im Web (Texte, Vokabeln, Funkpraxis), ohne Teilumfang');
    $check(($o['device_simulator']['available'] ?? false) === true && $o['device_simulator']['premium'] === false && str_ends_with((string) $o['device_simulator']['web_url'], '/bootsscheine/src/funk/geraet/') && $o['device_simulator']['open_in'] === 'browser', 'Simulator: verfügbar (Testversion) mit Link in den Web-Lernbereich');
    $sp = $api('GET', "/courses/{$srcId}/radio/spelling", [], $tokenA);
    $check($sp['status'] === 200 && count($sp['body']['data']['letters']) === 26 && in_array('SRC', $sp['body']['data']['catalogs'], true), 'Buchstabiertafel mit Ziffern je Katalog');
    $textId = (string) ($o['texts'][0]['items'][0]['id'] ?? '');
    $t = $api('GET', "/courses/{$srcId}/radio/texts/{$textId}", [], $tokenA);
    $td = $t['body']['data'] ?? [];
    $check($t['status'] === 200 && $td['id'] === $textId && $td['type'] === 'translation' && $td['evaluable'] === true && $td['done'] === false && is_string($td['text_de']), 'Funktext abrufen');
    $check($td['translation_direction'] === 'de-en' && $td['solution_hidden'] === true && $td['text_en'] === null, 'Übersetzung: Lösung (Zielsprache) vor der Auswertung zurückgehalten');
    $webText = $radioWeb->text($alice, $src, $textId);
    $r = $api('POST', "/courses/{$srcId}/radio/texts/{$textId}/evaluate", ['direction' => 'de-en'], $tokenA);
    $check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED', 'Auswertung ohne Eingabe → 422');
    $r = $api('POST', "/courses/{$srcId}/radio/texts/{$textId}/evaluate", ['transcript' => 'x'], $tokenA);
    $check($r['status'] === 422, 'Übersetzung ohne Richtung → 422');
    $ev = $api('POST', "/courses/{$srcId}/radio/texts/{$textId}/evaluate", ['direction' => 'de-en', 'transcript' => (string) $td['text_de'], 'translation' => 'mayday'], $tokenA);
    $e = $ev['body']['data'] ?? [];
    $check($ev['status'] === 200 && $e['transcript']['percent'] === 100 && is_int($e['translation']['percent']) && is_array($e['translation']['missing']) && $e['solution']['to'] === $webText['text_en'] && is_string($e['solution']['to']), 'Auswertung: Prozent, fehlende Wörter, Lösung');
    $after = $api('GET', "/courses/{$srcId}/radio/texts/{$textId}?direction=de-en", [], $tokenA)['body']['data'] ?? [];
    $check($after['solution_hidden'] === false && $after['text_en'] === $webText['text_en'], 'nach der Auswertung: Lösung in dieser Richtung enthalten');
    $other = $api('GET', "/courses/{$srcId}/radio/texts/{$textId}?direction=en-de", [], $tokenA)['body']['data'] ?? [];
    $check($other['translation_direction'] === 'en-de' && $other['solution_hidden'] === true && $other['text_de'] === null && $other['text_en'] === $webText['text_en'], 'andere Richtung: deutsche Lösung zurückgehalten');
    $stored = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT data FROM {$p}radio_progress WHERE user_id = %d AND item_type = 'text' AND item_source_id = %s", $alice, $textId)), true);
    $check(($stored['de-en']['translation'] ?? '') === 'mayday', 'Eingabe in bp_radio_progress gespeichert (wie Web)');
    $d = $api('PUT', "/courses/{$srcId}/radio/texts/{$textId}/done", ['done' => true], $tokenA);
    $check($d['status'] === 200 && $d['body']['data']['done'] === true && $radioWeb->text($alice, $src, $textId)['done'] === true, 'erledigt markiert – im Web sichtbar');
    $t = $api('GET', "/courses/{$srcId}/radio/texts/{$textId}", [], $tokenA);
    $check($t['body']['data']['done'] === true && ($t['body']['data']['answers']['de-en']['translation'] ?? '') === 'mayday', 'Text zeigt gespeicherte Eingabe und Status');
    $r = $api('PUT', "/courses/{$srcId}/radio/texts/{$textId}/done", [], $tokenA);
    $check($r['status'] === 422, 'done fehlt → 422');
    $r = $api('GET', "/courses/{$srcId}/radio/texts/TXT-GIBT-ES-NICHT", [], $tokenA);
    $check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND', 'unbekannter Text → 404');
    $r = $api('GET', "/courses/{$srcId}/radio/vocabulary", [], $tokenA);
    $check($r['status'] === 200 && $r['body']['meta']['catalog'] === 'SRC' && count($r['body']['data']) > 0, 'Vokabeln ohne Katalog: einziger Katalog des Kurses (SRC)');
    $voc = $api('GET', "/courses/{$srcId}/radio/vocabulary?catalog=SRC", [], $tokenA);
    $card = $voc['body']['data'][0] ?? null;
    $check($voc['status'] === 200 && $card !== null && $card['streak'] === 0 && $voc['body']['meta']['streak_to_learn'] === 3 && $voc['body']['meta']['total'] === count($voc['body']['data']), 'Vokabelkarten mit Serie');
    $cat = $api('GET', "/courses/{$srcId}/radio/vocabulary?catalog=SRC&category=".rawurlencode((string) $card['category']), [], $tokenA);
    $check($cat['status'] === 200 && array_filter($cat['body']['data'], static fn ($c) => $c['category'] !== $card['category']) === [], 'Vokabeln nach Kategorie');
    $vkey = $uuid();
    $va = $api('POST', "/courses/{$srcId}/radio/vocabulary/{$card['id']}/answers", ['known' => true, 'streak' => 0], $tokenA, ['Idempotency-Key' => $vkey]);
    $check($va['status'] === 200 && $va['body']['data']['streak'] === 1 && $va['body']['data']['repeated'] === false, 'gewusst: Serie +1');
    $va2 = $api('POST', "/courses/{$srcId}/radio/vocabulary/{$card['id']}/answers", ['known' => true, 'streak' => 0], $tokenA, ['Idempotency-Key' => $vkey]);
    $check($va2['status'] === 200 && $va2['body']['data']['streak'] === 1 && (int) $wpdb->get_var($wpdb->prepare("SELECT streak FROM {$p}radio_progress WHERE user_id = %d AND item_type = 'vocab' AND item_source_id = %s", $alice, $card['id'])) === 1, 'Wiederholung mit gleichem Key zählt nicht doppelt');
    $va3 = $api('POST', "/courses/{$srcId}/radio/vocabulary/{$card['id']}/answers", ['known' => true, 'streak' => 0], $tokenA);
    $check($va3['status'] === 200 && $va3['body']['data']['repeated'] === true && $va3['body']['data']['streak'] === 1, 'veraltete Serie (zweites Gerät) → repeated, keine Doppelzählung');
    $r = $api('POST', "/courses/{$srcId}/radio/vocabulary/{$card['id']}/answers", ['streak' => 1], $tokenA);
    $check($r['status'] === 422, 'known fehlt → 422');
    $pr = $api('GET', "/courses/{$srcId}/radio/practice", [], $tokenA);
    $scenario = $pr['body']['data'][0]['tasks'][0] ?? null;
    $check($pr['status'] === 200 && $scenario !== null && $scenario['locked'] === false, 'Funkpraxis-Übersicht');
    $sc = $api('GET', "/courses/{$srcId}/radio/practice/{$scenario['id']}", [], $tokenA);
    $field = $sc['body']['data']['fields'][0]['name'] ?? '';
    $check($sc['status'] === 200 && $field !== '' && is_string($sc['body']['data']['situation_de']) && ! str_contains((string) wp_json_encode($sc['body']['data']), '"solution"'), 'Funkpraxis-Aufgabe ohne Musterlösung');
    $r = $api('POST', "/courses/{$srcId}/radio/practice/{$scenario['id']}/evaluate", ['answers' => [$field => '']], $tokenA);
    $check($r['status'] === 422, 'Funkpraxis ohne Eingabe → 422');
    $r = $api('POST', "/courses/{$srcId}/radio/practice/{$scenario['id']}/evaluate", ['answers' => 'Mayday'], $tokenA);
    $check($r['status'] === 422, 'answers kein Objekt → 422');
    $pe = $api('POST', "/courses/{$srcId}/radio/practice/{$scenario['id']}/evaluate", ['answers' => [$field => 'Notverkehr Mayday Bestätigung']], $tokenA);
    $pd = $pe['body']['data'] ?? [];
    $check($pe['status'] === 200 && $pd['total'] > 0 && $pd['passed'] >= 1 && isset($pd['fields'][0]['checks'], $pd['fields'][0]['solution']), 'Funkpraxis ausgewertet: Prüfpunkte und Musterlösung je Feld');
    $r = $api('PUT', "/courses/{$srcId}/radio/practice/{$scenario['id']}/done", ['done' => true], $tokenA);
    $pr = $api('GET', "/courses/{$srcId}/radio/practice", [], $tokenA);
    $check($r['status'] === 200 && $pr['body']['data'][0]['tasks'][0]['done'] === true && $pr['body']['data'][0]['tasks'][0]['evaluated'] === true, 'Funkpraxis erledigt und ausgewertet');
    $sm = $api('GET', "/courses/{$srcId}/radio/samples", [], $tokenA);
    $check($sm['status'] === 200 && isset($sm['body']['data'][0]['samples'][0]['message']), 'Muster-Funksprüche');
    // Teilumfang: Bob ohne SRC-Zugang, kostenlos die erste Übung je Übungsart/Katalog.
    $wpdb->insert($p.'access_rule', ['course_code' => 'SRC', 'feature_code' => 'radio_training', 'access_type' => 'free_limited', 'limit_type' => 'first_items', 'limit_value' => 1, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
    $ruleId = (int) $wpdb->insert_id;
    $bo = $api('GET', "/courses/{$srcId}/radio", [], $tokenB);
    $items = $bo['body']['data']['texts'][0]['items'] ?? [];
    $check($bo['status'] === 200 && $bo['body']['meta']['limit'] === 1 && $items[0]['locked'] === false && ($items[1]['locked'] ?? false) === true && $items[1]['teaser'] === null, 'Teilumfang: weitere Texte gesperrt (ohne Inhalt)');
    $r = $api('GET', "/courses/{$srcId}/radio/texts/{$items[1]['id']}", [], $tokenB);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED', 'gesperrter Text → 403 CONTENT_LOCKED');
    $tasks = $api('GET', "/courses/{$srcId}/radio/practice", [], $tokenB)['body']['data'][0]['tasks'] ?? [];
    $r = $api('POST', "/courses/{$srcId}/radio/practice/{$tasks[1]['id']}/evaluate", ['answers' => [$field => 'Mayday']], $tokenB);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED', 'gesperrte Funkpraxis-Aufgabe → 403 CONTENT_LOCKED');
    $wpdb->delete($p.'access_rule', ['id' => $ruleId]);

    echo "Funkgerät-Simulator (Link)\n";
    $sd = $api('GET', "/courses/{$srcId}", [], $tokenA);
    $sim = $sd['body']['data']['device_simulator'] ?? [];
    $check($sd['status'] === 200 && $sim['available'] === true && $sim['premium'] === false && $sim['tier'] === 'free' && str_ends_with((string) $sim['web_url'], '/bootsscheine/src/funk/geraet/') && is_string($sim['hint']), 'Kursdetails: Simulator als Testversion mit Browser-Link');
    $entitlements->grantManual($alice, 'SRC', ['device_simulation'], null, 'App-Test Simulator');
    $sim = $api('GET', "/courses/{$srcId}", [], $tokenA)['body']['data']['device_simulator'] ?? [];
    $devWeb = new \Bootspruefung\Learning\Trainers\DeviceSimService(new AccessService(), new \Bootspruefung\Learning\Trainers\RadioPracticeService(new AccessService()));
    $check($sim['premium'] === true && $sim['tier'] === 'premium' && $sim['webshop_url'] === null && $devWeb->premium($alice, $src) === true, 'mit Simulator-Zugang: premium (DeviceSimService::premium)');
}
$sim = $detail['body']['data']['device_simulator'] ?? null;
$check(is_array($sim) && $sim['available'] === false && $sim['web_url'] === null, 'Kurs ohne Funk: Simulator nicht verfügbar');

echo "Prüfungsanmeldung (nur lesen)\n";
update_option(Settings::OPTION, array_merge(Settings::all(), ['exam_guide_enabled' => false]));
$r = $api('GET', "/courses/{$courseId}/exam-registration", [], $tokenA);
$check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND', 'abgeschaltet → 404');
$check(($api('GET', "/courses/{$courseId}", [], $tokenA)['body']['data']['exam_registration']['available'] ?? null) === false, 'Kursdetails: exam_registration.available false');
update_option(Settings::OPTION, array_merge(Settings::all(), ['exam_guide_enabled' => true]));
$guideWeb = (new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->payload($courseCode);
$g = $api('GET', "/courses/{$courseId}/exam-registration", [], $tokenA);
$gd = $g['body']['data'] ?? [];
if ($guideWeb['tabs'] === []) {
    $check($g['status'] === 404, 'Kurs ohne Anleitung → 404');
} else {
    $check($g['status'] === 200 && count($gd['guides']) === count($guideWeb['tabs']) && $gd['guides'][0]['title'] === $guideWeb['tabs'][0]['title'] && $gd['registration_in_app'] === false, 'Anleitung wie im Web (ExamGuideService::payload)');
    $check(count($gd['committees']) === count($guideWeb['committees']) && is_string($gd['committees'][0]['id'] ?? null) && array_key_exists('matches', $gd['committees'][0]), 'Prüfungsausschüsse mit passenden zuerst');
    $check(str_ends_with((string) $gd['web_url'], '/bootsscheine/'.strtolower($courseCode).'/pruefung-anmelden/'), 'Link zur Web-Seite „Prüfung anmelden“');
    $er = $api('GET', "/courses/{$courseId}", [], $tokenA)['body']['data']['exam_registration'] ?? [];
    $check(($er['available'] ?? false) === true && $er['web_url'] === $gd['web_url'], 'Kursdetails: exam_registration mit Link');
}
$noGuide = null;
foreach ($content->courses() as $c) {
    if ((new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->pagesFor((string) $c->code) === []) {
        $noGuide = $c;
        break;
    }
}
if ($noGuide !== null) {
    $r = $api('GET', '/courses/'.$noGuide->id.'/exam-registration', [], $tokenA);
    $check($r['status'] === 404 && str_contains((string) $r['body']['detail'], 'keine Anleitung'), 'Kurs ohne Anleitung → 404');
}
$r = $api('GET', "/courses/{$courseId}/exam-registration", [], $tokenB, ['X-Tenant-Slug' => 'falsch']);
$check($r['status'] === 403 && $code($r) === 'TENANT_MEMBERSHIP_REQUIRED', 'Prüfungsanmeldung nur mit richtigem Tenant');

echo "E-Mail-Bestätigung entfällt\n";
$r = $api('POST', '/auth/email/verification-notification', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND', 'Route /auth/email/verification-notification entfernt → 404');
$check($api('GET', '/me', [], $tokenA, ['X-Tenant-Slug' => null])['body']['data']['user']['email_verified'] === true, '/me: email_verified bleibt true');

echo "Sitzungsübergabe App → Web\n";
$carolPass = 'Mobil-Passwort-789!';
$carol = $customer('mobile_kundin_c', $carolPass);
$staff = [];
foreach (['administrator' => 'mobile_admin_t', 'editor' => 'mobile_redaktion_t'] as $role => $staffLogin) {
    $u = get_user_by('login', $staffLogin);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }
    $staff[$role] = (int) wp_insert_user(['user_login' => $staffLogin, 'user_pass' => 'Staff-Passwort-321!', 'user_email' => $staffLogin.'@example.test', 'role' => $role]);
}
$origin = (static function (): string {
    $h = wp_parse_url(home_url('/'));

    return $h['scheme'].'://'.$h['host'].(isset($h['port']) ? ':'.$h['port'] : '');
})();
$simPath = '/'.trim((string) wp_parse_url(home_url('/bootsscheine/src/funk/geraet/'), PHP_URL_PATH), '/').'/';
$codeOf = static fn (array $r): string => (string) (preg_match('/[?&]bp_app_login=([^&]+)/', (string) ($r['body']['data']['url'] ?? ''), $m) ? $m[1] : '');
$_SERVER['REQUEST_METHOD'] = 'GET'; // Aufruf der Einmal-URL (wp_validate_auth_cookie liest die Methode)
$verOf = static fn (array $r): string => (string) ($r['body']['data']['verifier'] ?? '');
$ws = $api('POST', '/auth/web-session', ['redirect' => '/bootsscheine/src/funk/geraet/'], $tokenA, ['X-Tenant-Slug' => null]);
$wcode = $codeOf($ws);
$wver = $verOf($ws);
$check(preg_match('/^bpv_[A-Za-z0-9_-]{43}$/', $wver) === 1 && $ws['body']['data']['verifier_header'] === 'X-Boatingschool-Web-Verifier' && ! str_contains((string) $ws['body']['data']['url'], $wver), 'Antwort mit Prüfwert (verifier) und Header-Name für die Webansicht, Prüfwert nicht in der URL');
$check($ws['status'] === 201 && str_starts_with((string) $ws['body']['data']['url'], $origin.$simPath.'?bp_app_login=bpw_') && strlen($wcode) === 47 && $ws['body']['data']['expires_in'] === 60, 'POST /auth/web-session → Einmal-URL ins Lernbereich-Ziel');
$check(abs(strtotime((string) $ws['body']['data']['expires_at']) - time() - 60) <= 2 && ($ws['headers']['Cache-Control'] ?? '') === 'private, no-store, max-age=0', 'Code 60 Sekunden gültig, Antwort no-store');
$wrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}mobile_web_code WHERE code_hash = %s", hash('sha256', $wcode)));
$check($wrow && (int) $wrow->user_id === $alice && (int) $wrow->session_id === (int) $tokensA['session_id'] && ! str_contains((string) wp_json_encode($wrow), $wcode), 'nur Hash gespeichert, gebunden an Konto und App-Sitzung');
$check($wrow && $wrow->verifier_hash === hash('sha256', $wver) && ! str_contains((string) wp_json_encode($wrow), $wver), 'Prüfwert nur als Hash gespeichert');
$check($api('POST', '/auth/web-session', ['redirect' => '/bootsscheine/'])['status'] === 401, 'ohne Anmeldung → 401');
$evil = ['https://evil.example/bootsscheine/', '//evil.example/bootsscheine/', '/wp-admin/', '/bootsscheine/../wp-admin/', '/bootsscheine/%2e%2e/wp-admin/', 'javascript:alert(1)', '/bootsscheine\\..\\wp-admin', $origin.'.evil.example/bootsscheine/', 'http://user@localhost/bootsscheine/', '/bootsscheinex/', '/bootsscheine/#//evil.example', '/bootsscheine/src/?next=https://evil.example', ''];
$rejected = 0;
foreach ($evil as $target) {
    $r = $api('POST', '/auth/web-session', ['redirect' => $target], $tokenA, ['X-Tenant-Slug' => null]);
    $rejected += $r['status'] === 422 && $code($r) === 'VALIDATION_FAILED' ? 1 : 0;
}
$check($rejected === count($evil), 'offene Weiterleitung verhindert: fremde Hosts, //, .., %2e, Schema, Pfade außerhalb des Lernbereichs → 422 ('.$rejected.'/'.count($evil).')');
$r = $api('POST', '/auth/web-session', ['redirect' => home_url('/bootsscheine/src/?modus=frei&bp_app_login=alt')], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 201 && str_contains((string) $r['body']['data']['redirect'], '/bootsscheine/src/?modus=frei') && substr_count((string) $r['body']['data']['url'], 'bp_app_login=') === 1, 'absolute URL derselben Website erlaubt (alter Code-Parameter entfernt)');
$authCookies = [];
add_filter('send_auth_cookies', '__return_false', 99);
add_action('set_auth_cookie', static function ($cookie, $expire, $expiration, $userId) use (&$authCookies): void {
    $authCookies[] = ['expire' => (int) $expire, 'user' => (int) $userId];
}, 10, 4);
// Login-CSRF: Link ohne bzw. mit falschem Prüfwert (z. B. aus Mail/Chat im Browser geöffnet) meldet nicht an.
$lost = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokenA, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($codeOf($lost), $simPath.'?bp_app_login='.$codeOf($lost));
$check($res['user_id'] === 0 && $res['location'] === wp_login_url($origin.$simPath) && $authCookies === [], 'ohne Header X-Boatingschool-Web-Verifier (Browser statt Webansicht) → keine Anmeldung, Anmeldeseite ohne Grund');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($codeOf($lost), $simPath, $verOf($lost));
$check($res['user_id'] === 0 && $authCookies === [], 'Code nach Versuch ohne Prüfwert verbraucht (auch mit richtigem Prüfwert nicht mehr gültig)');
$wrong = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokenA, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($codeOf($wrong), $simPath, $wver);
$check($res['user_id'] === 0 && $authCookies === [], 'Prüfwert eines anderen Codes → keine Anmeldung');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($wcode, $simPath.'?bp_app_login='.$wcode, $wver);
$check($res['user_id'] === $alice && $res['location'] === $origin.$simPath && count($authCookies) === 1 && $authCookies[0]['user'] === $alice, 'Einlösen: WordPress-Anmeldecookie gesetzt, Weiterleitung aufs Ziel ohne Code');
$check($authCookies[0]['expire'] === 0 && get_current_user_id() === $alice, 'Sitzungscookie ohne „angemeldet bleiben“');
$again = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($wcode, $simPath.'?bp_app_login='.$wcode, $wver);
$loginTo = wp_login_url($origin.$simPath);
$check($again['user_id'] === 0 && $again['location'] === $loginTo && count($authCookies) === 1, 'Code zweimal benutzt → Anmeldeseite mit redirect_to aufs Ziel, kein Cookie');
$r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokenA, ['X-Tenant-Slug' => null]);
$c2 = $codeOf($r);
$v2 = $verOf($r);
$wpdb->update($p.'mobile_web_code', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['code_hash' => hash('sha256', $c2)]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c2, $simPath.'?bp_app_login='.$c2, $v2);
$check($res['user_id'] === 0 && $res['location'] === $loginTo, 'abgelaufener Code → Anmeldeseite (gleiche Antwort wie ungültig)');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest('bpw_'.str_repeat('A', 43), '/wp-admin/?bp_app_login=x');
$check($res['user_id'] === 0 && $res['location'] === wp_login_url(\Bootspruefung\Learning\Frontend\Router::url()), 'ungültiger Code an fremdem Pfad → Anmeldeseite mit Lernbereich als Ziel');
$lc = $login('mobile_kundin_c@example.test', $carolPass, 'Tablet');
$tokC = (string) $lc['body']['data']['access_token'];
$wsOf = static fn (array $r): array => [$codeOf($r), $verOf($r)];
[$c3, $v3] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$api('POST', '/auth/logout', [], $tokC, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c3, $simPath, $v3);
$check($res['user_id'] === 0, 'App-Sitzung widerrufen → offener Code unbrauchbar');
$lc = $login('mobile_kundin_c@example.test', $carolPass, 'Tablet');
$tokC = (string) $lc['body']['data']['access_token'];
[$c4, $v4] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$carolPass = 'Mobil-Passwort-790!';
wp_set_password($carolPass, $carol);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c4, $simPath, $v4);
$check($c4 !== '' && $res['user_id'] === 0, 'Passwortänderung → offener Code unbrauchbar');
$tokC = (string) $login('mobile_kundin_c@example.test', $carolPass, 'Tablet')['body']['data']['access_token'];
[$c5, $v5] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
(new WP_User($carol))->set_role('editor');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c5, $simPath, $v5);
(new WP_User($carol))->set_role('customer');
$check($c5 !== '' && $res['user_id'] === 0, 'Konto inzwischen mit Redaktionsrechten → Code wird nicht eingelöst');
// Im Browser ist bereits ein anderes Konto angemeldet: kein Kontowechsel, Code verbraucht.
$cookiesBefore = count($authCookies);
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($bob, time() + HOUR_IN_SECONDS, 'logged_in');
[$c7, $v7] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c7, $simPath, $v7);
$check($c7 !== '' && $res['user_id'] === 0 && $res['location'] === wp_login_url($origin.$simPath) && count($authCookies) === $cookiesBefore, 'anderes Konto im Browser angemeldet → kein Kontowechsel (Anmeldeseite, kein neues Cookie)');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c7, $simPath, $v7);
$check($res['user_id'] === 0, 'Code dabei verbraucht');
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($carol, time() + HOUR_IN_SECONDS, 'logged_in');
[$c8, $v8] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c8, $simPath, $v8);
$check($res['user_id'] === $carol, 'gleiches Konto bereits angemeldet → Übergabe klappt');
unset($_COOKIE[LOGGED_IN_COOKIE]);
foreach ($staff as $role => $staffId) {
    $tokS = (string) ($login($role === 'administrator' ? 'mobile_admin_t@example.test' : 'mobile_redaktion_t@example.test', 'Staff-Passwort-321!')['body']['data']['access_token'] ?? '');
    $r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokS, ['X-Tenant-Slug' => null]);
    $check($r['status'] === 403 && $code($r) === 'WEB_SESSION_NOT_ALLOWED', 'Konto mit Rolle '.$role.' → 403 WEB_SESSION_NOT_ALLOWED (keine Admin-Sitzung über App-Token)');
}
[$c6, $v6] = $wsOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
MobileSettings::save(array_merge(MobileSettings::all(), ['web_session_enabled' => false]));
$r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c6, $simPath, $v6);
$check($r['status'] === 403 && $code($r) === 'WEB_SESSION_DISABLED' && $res['user_id'] === 0, 'Einstellung aus → 403 WEB_SESSION_DISABLED, offene Codes werden nicht eingelöst');
MobileSettings::save(array_merge(MobileSettings::all(), ['web_session_enabled' => true]));
$check(MobileSettings::webSessionEnabled() && MobileSettings::sanitize([])['web_session_enabled'] === true, 'Standard: Übergabe an');
\Bootspruefung\Learning\Mobile\RateLimiter::clear('web-session|'.$carol);
$statuses = [];
for ($i = 0; $i < \Bootspruefung\Learning\Mobile\WebSession::RATE_LIMIT + 1; $i++) {
    $statuses[] = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null])['status'];
}
$check(count(array_filter($statuses, static fn ($s) => $s === 201)) === \Bootspruefung\Learning\Mobile\WebSession::RATE_LIMIT && end($statuses) === 429, 'Erzeugung begrenzt ('.\Bootspruefung\Learning\Mobile\WebSession::RATE_LIMIT.' je 15 Minuten) → 429');
remove_filter('send_auth_cookies', '__return_false', 99);
wp_set_current_user(0);
$wpdb->update($p.'mobile_web_code', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)], ['user_id' => $carol]);
\Bootspruefung\Learning\Mobile\MobileApi::cleanup();
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}mobile_web_code WHERE user_id = %d", $carol)) === 0, 'alte Codes werden automatisch gelöscht');

echo "Synchronisierte App-Einstellungen\n";
$st = $api('GET', '/me/settings', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($st['status'] === 200 && $st['body']['data']['settings'] === [] && $st['body']['data']['version'] === 0 && $st['body']['data']['updated_at'] === null && ($st['headers']['ETag'] ?? '') === '"0"', 'GET /me/settings ohne Daten: leeres Objekt, Version 0');
$check(\Bootspruefung\Learning\Mobile\AppSettings::get($alice)['settings'] instanceof stdClass, 'leere Einstellungen als Objekt (nicht als Liste)');
$prefs = ['theme' => 'dark', 'audio.volume' => 0.8, 'trainer' => ['daily_goal' => 20, 'modes' => ['smarttrainer', 'favorites'], 'sound' => true, 'nested' => ['deep' => ['x' => null]]], 'path' => 'C:\\Ordner "x" – Übung', 'empty' => new stdClass()];
$put = $api('PUT', '/me/settings', ['settings' => $prefs], $tokenA, ['X-Tenant-Slug' => null]);
$sd = $put['body']['data'] ?? [];
$check($put['status'] === 200 && $sd['version'] === 1 && is_string($sd['updated_at']) && ($put['headers']['ETag'] ?? '') === '"1"' && $sd['settings']['trainer']['modes'] === ['smarttrainer', 'favorites'], 'PUT /me/settings speichert (Version 1, ETag)');
$stored = \Bootspruefung\Learning\Mobile\AppSettings::get($alice);
$check($stored['settings']->path === 'C:\\Ordner "x" – Übung' && $stored['settings']->empty instanceof stdClass && $stored['settings']->{'audio.volume'} === 0.8, 'Sonderzeichen, Backslashes, Zahlen und leere Objekte bleiben erhalten');
$check(is_string(get_user_meta($alice, 'bp_app_settings', true)), 'gespeichert in der Nutzer-Meta bp_app_settings');
$g = $api('GET', '/me/settings', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($g['status'] === 200 && $g['body']['data']['version'] === 1 && $g['body']['data']['settings']['theme'] === 'dark', 'GET liefert gespeicherten Stand (anderes Gerät)');
$r = $api('PUT', '/me/settings', ['settings' => ['theme' => 'light']], $tokenA, ['X-Tenant-Slug' => null, 'If-Match' => '"0"']);
$check($r['status'] === 409 && $code($r) === 'VERSION_CONFLICT' && ($r['body']['details']['current']['version'] ?? null) === 1 && ($r['body']['details']['current']['settings']['theme'] ?? '') === 'dark', 'veraltete Version (If-Match) → 409 VERSION_CONFLICT mit aktuellem Stand');
$r = $api('PUT', '/me/settings', ['settings' => ['theme' => 'light'], 'expected_version' => 5], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 409 && $code($r) === 'VERSION_CONFLICT', 'expected_version passt nicht → 409');
$r = $api('PUT', '/me/settings', ['settings' => ['theme' => 'light']], $tokenA, ['X-Tenant-Slug' => null, 'If-Match' => '"1"']);
$check($r['status'] === 200 && $r['body']['data']['version'] === 2 && $r['body']['data']['settings'] === ['theme' => 'light'], 'passende Version → ersetzt vollständig (Version 2)');
$r = $api('PUT', '/me/settings', ['settings' => ['theme' => 'sepia'], 'expected_version' => 2], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data']['version'] === 3, 'expected_version im Body');
$bad = [
    'zu groß' => ['settings' => ['blob' => str_repeat('x', 8200)]],
    'zu tief' => ['settings' => ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]],
    'Schlüssel' => ['settings' => ['Theme' => 'dark']],
    'Schlüssel verschachtelt' => ['settings' => ['ok' => ['bad key' => 1]]],
    'Liste' => ['settings' => [1, 2]],
    'Text' => ['settings' => 'dark'],
    'fehlt' => ['theme' => 'dark'],
];
foreach ($bad as $label => $payload) {
    $r = $api('PUT', '/me/settings', $payload, $tokenA, ['X-Tenant-Slug' => null]);
    $check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED' && isset($r['body']['errors']), 'ungültige Einstellungen ('.$label.') → 422');
}
$r = $api('PUT', '/me/settings', ['settings' => ['a' => ['b' => ['c' => ['d' => 1]]]]], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data']['version'] === 4, '4 Ebenen erlaubt');
$r = $api('PUT', '/me/settings', ['settings' => ['blob' => str_repeat('x', 8150)]], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200, 'knapp unter 8 KB erlaubt');
$api('PUT', '/me/settings', ['settings' => $prefs], $tokenA, ['X-Tenant-Slug' => null]);
$r = $api('GET', '/me/settings', [], $tokenB, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data']['version'] === 0, 'Einstellungen je Konto (fremde nicht sichtbar)');
$check($api('GET', '/me/settings')['status'] === 401, 'ohne Anmeldung → 401');

echo "Vertrag (openapi.yaml) und Routen\n";
$spec = dirname(__DIR__, 3).'/docs/mobile-api/openapi.yaml';
if (! is_readable($spec)) {
    echo "  (openapi.yaml nicht im Paket – übersprungen)\n";
} else {
    $documented = [];
    $path = null;
    foreach (file($spec) as $line) {
        if (preg_match('#^  (/\S*):\s*$#', $line, $m)) {
            $path = $m[1];
        } elseif (preg_match('#^\S#', $line)) {
            $path = null;
        } elseif ($path !== null && preg_match('#^    (get|post|put|patch|delete):#', $line, $m)) {
            $documented[] = $m[1].' '.$path;
        }
    }
    $implemented = [];
    foreach (rest_get_server()->get_routes('boatingschool/v1') as $route => $handlers) {
        if ($route === '/boatingschool/v1') {
            continue;
        }
        $pathOf = preg_replace('#\(\?P<(\w+)>[^)]+\)#', '{$1}', substr($route, strlen('/boatingschool/v1')));
        foreach ($handlers as $h) {
            foreach (array_keys($h['methods']) as $m) {
                $implemented[] = strtolower($m).' '.$pathOf;
            }
        }
    }
    $onlyCode = array_diff($implemented, $documented);
    $onlySpec = array_diff($documented, $implemented);
    $check($onlyCode === [] && $onlySpec === [], 'jede Route im Vertrag und umgekehrt ('.count($implemented).' Operationen)'.($onlyCode || $onlySpec ? ' – nur Code: '.implode(', ', $onlyCode).' / nur Vertrag: '.implode(', ', $onlySpec) : ''));
}

echo "Codes, Dateien\n";
$r = $api('POST', '/coupons/redeem', ['code' => 'GIBTESNICHT'], $tokenA);
$check($r['status'] === 422 && $code($r) === 'COUPON_INVALID' && str_contains($r['body']['detail'], 'automatisch freigeschaltet'), 'unbekannter Code → 422 mit Hinweis auf automatische Freischaltung');
if ($couponId > 0) {
    $r = $api('POST', '/coupons/redeem', ['code' => 'APPTEST10'], $tokenA);
    $check($r['status'] === 422 && $code($r) === 'COUPON_NOT_SUPPORTED', 'WooCommerce-Gutschein ist kein Zugangscode → 422');
}
$r = $api('POST', '/coupons/redeem', ['code' => 'g'.str_repeat('0', 32)], $tokenA);
$check($r['status'] === 422 && $code($r) === 'COUPON_INVALID', 'ungültiger Gruppen-Beitrittscode → 422');
// Gruppen-Beitrittscode: Idempotenz vor Rate-Limit (Netzwiederholung verbraucht keinen Versuch).
$joinToken = 'g'.bin2hex(random_bytes(16));
$wpdb->insert($p.'learning_group', ['owner_user_id' => $bob, 'name' => 'App-Testgruppe', 'scopes' => wp_json_encode([['course' => $courseCode, 'feature' => 'question_bank']]), 'seats' => 3, 'status' => 'active', 'join_token' => $joinToken]);
$groupId = (int) $wpdb->insert_id;
$redeemHits = static fn (): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits), 0) FROM {$p}mobile_rate_limit WHERE bucket_hash = %s", hash('sha256', 'redeem|'.$alice.'|'.wp_salt('nonce'))));
$rkey = $uuid();
$hits0 = $redeemHits();
$r = $api('POST', '/coupons/redeem', ['code' => $joinToken], $tokenA, ['Idempotency-Key' => $rkey]);
$hits1 = $redeemHits();
$check($r['status'] === 200 && $r['body']['data']['kind'] === 'group_license' && $r['body']['data']['group_name'] === 'App-Testgruppe' && $hits1 === $hits0 + 1, 'Beitrittscode eingelöst (zählt einen Versuch)');
$r2 = $api('POST', '/coupons/redeem', ['code' => $joinToken], $tokenA, ['Idempotency-Key' => $rkey]);
$check($r2['status'] === 200 && $r2['body'] === $r['body'] && $redeemHits() === $hits1, 'Wiederholung mit gleichem Idempotency-Key: gespeicherte Antwort, kein weiterer Versuch gezählt');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}learning_group_member WHERE group_id = %d", $groupId));
$wpdb->delete($p.'learning_group', ['id' => $groupId]);
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE reference_key LIKE %s", 'group:'.$groupId.':%'));
$r = $api('GET', '/files/999999', [], $tokenA);
$check($r['status'] === 404, 'unbekannte Datei → 404');
$FC = \Bootspruefung\Learning\Mobile\Controllers\FileController::class;
$check($FC::parseRange(null, 1000) === null && $FC::parseRange('items=0-1', 1000) === null, 'Range: ohne bzw. andere Einheit → ganze Datei (200)');
$check($FC::parseRange('bytes=0-99', 1000) === [0, 99] && $FC::parseRange('bytes=900-', 1000) === [900, 999] && $FC::parseRange('bytes=-100', 1000) === [900, 999] && $FC::parseRange('bytes=990-5000', 1000) === [990, 999] && $FC::parseRange('bytes=-5000', 1000) === [0, 999], 'Range: Bereich, offenes Ende, Suffix, Ende hinter Dateiende → 206-Bereich');
$check($FC::parseRange('bytes=1000-', 1000) === false && $FC::parseRange('bytes=5-2', 1000) === false && $FC::parseRange('bytes=0-1,5-6', 1000) === false && $FC::parseRange('bytes=abc', 1000) === false && $FC::parseRange('bytes=-0', 1000) === false && $FC::parseRange('bytes=0-0', 0) === false, 'Range: nicht erfüllbar, verkehrt, mehrere Bereiche, Unsinn → 416');
$check($FC::contentDisposition('inline', 'Seekarte Übung "Nord".pdf') === 'inline; filename="Seekarte_Ubung_Nord_.pdf"; filename*=UTF-8\'\'Seekarte%20%C3%9Cbung%20%22Nord%22.pdf', 'Content-Disposition mit ASCII-Ersatz und filename*=UTF-8');
$check(! str_contains($FC::contentDisposition('attachment', "a\r\nX-Evil: 1.pdf"), "\n"), 'Content-Disposition ohne Zeilenumbrüche (Header-Injection)');

echo "Tipps & Tricks (1.7.0)\n";
$r = $api('GET', '/tips', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && isset($r['body']['errors']['X-Tenant-Slug']), 'Tipps ohne X-Tenant-Slug → 422 (wie Kursliste)');
$check($api('GET', '/tips')['status'] === 401, 'Tipps ohne Anmeldung → 401');
$tipTerm = wp_insert_term('App-Test Tipps', \Bootspruefung\Learning\Tips\Tips::TAXONOMY);
$tipTermId = is_wp_error($tipTerm) ? 0 : (int) $tipTerm['term_id'];
$tipPost = (int) wp_insert_post(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Link & Liste', 'post_content' => "Erster Absatz mit <a href=\"/bootsscheine/\">Lernbereich</a>.\n\n<ul><li>Punkt eins</li><li>Punkt zwei</li></ul>", 'menu_order' => 9999]);
wp_set_object_terms($tipPost, [$tipTermId], \Bootspruefung\Learning\Tips\Tips::TAXONOMY);
$webTips = \Bootspruefung\Learning\Tips\Tips::payload();
$r = $api('GET', '/tips', [], $tokenA);
$appTips = $r['body']['data'] ?? [];
$check($r['status'] === 200 && array_column($appTips, 'name') === array_column($webTips, 'name') && array_map(static fn ($g) => array_column($g['tips'], 'title'), $appTips) === array_map(static fn ($g) => array_map(static fn ($t) => html_entity_decode($t['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $g['tips']), $webTips), 'GET /tips: gleiche Kategorien, Reihenfolge und Tipps wie im Web (GET bootspruefung/v1/tips)');
$check(($r['body']['meta']['groups'] ?? -1) === count($webTips) && ($r['body']['meta']['tips'] ?? -1) === array_sum(array_map(static fn ($g) => count($g['tips']), $webTips)), 'meta: Anzahl Kategorien und Tipps');
$plainSame = true;
foreach ($webTips as $gi => $g) {
    foreach ($g['tips'] as $ti => $t) {
        $a = $appTips[$gi]['tips'][$ti] ?? [];
        if (! str_contains($t['html'], 'href="/') && ($a['html'] ?? null) !== $t['html']) {
            $plainSame = false;
        }
        if (($a['id'] ?? '') !== (string) $t['id'] || str_contains((string) ($a['text'] ?? '<'), '<') || trim((string) ($a['text'] ?? '')) === '') {
            $plainSame = false;
        }
    }
}
$check($plainSame, 'je Tipp: id, HTML wie im Web (wp_kses_post), reiner Text ohne Tags');
$tipMine = array_values(array_filter($appTips, static fn ($g) => $g['name'] === 'App-Test Tipps'))[0] ?? null;
$mt = $tipMine['tips'][0] ?? [];
$check($tipMine !== null && $tipMine['id'] === (string) $tipTermId && $tipMine['slug'] === get_term($tipTermId)->slug && $mt['title'] === 'Link & Liste', 'Kategorie mit id und slug (Tipp-Kategorie)');
$check(str_contains((string) ($mt['html'] ?? ''), 'href="'.home_url('/bootsscheine/').'"') && ($mt['links'] ?? []) === [['url' => home_url('/bootsscheine/'), 'text' => 'Lernbereich']], 'relative Links werden absolut (HTML und links[])');
$check(($mt['text'] ?? '') === "Erster Absatz mit Lernbereich.\n\n• Punkt eins\n• Punkt zwei", 'reiner Text: Absätze und Listenpunkte');
$general = array_values(array_filter($appTips, static fn ($g) => $g['id'] === null));
$check($general === [] || $general[0]['slug'] === sanitize_title($general[0]['name']), 'Tipps ohne Kategorie: id null, slug aus dem Namen');
wp_delete_post($tipPost, true);
wp_delete_term($tipTermId, \Bootspruefung\Learning\Tips\Tips::TAXONOMY);
$check(\Bootspruefung\Learning\Tips\Tips::payload() === array_values(array_filter($webTips, static fn ($g) => $g['name'] !== 'App-Test Tipps')), 'Web-Ausgabe von /tips unverändert (nur name und tips)');

echo "Gruppen verwalten (1.7.0)\n";
$gs = new \Bootspruefung\Learning\Commerce\GroupService($wpdb);
$mkGroup = static function (int $owner, string $name, int $seats) use ($wpdb, $p, $courseCode): array {
    $token = 'g'.bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert($p.'learning_group', ['owner_user_id' => $owner, 'name' => $name, 'scopes' => wp_json_encode([['course' => $courseCode, 'feature' => 'question_bank']]), 'seats' => $seats, 'status' => 'active', 'join_token' => $token, 'valid_until' => gmdate('Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS), 'created_at' => $now, 'updated_at' => $now]);

    return [(int) $wpdb->insert_id, $token];
};
[$gA, $joinA] = $mkGroup($alice, 'App-Gruppe Alice', 3);
[$gB, $joinB] = $mkGroup($bob, 'App-Gruppe Bob', 2);
$groupMails = [];
$captureGroup = static function ($return, $atts) use (&$groupMails) {
    $groupMails[] = ['to' => is_array($atts['to'] ?? null) ? implode(',', $atts['to']) : (string) ($atts['to'] ?? ''), 'message' => (string) ($atts['message'] ?? '')];

    return true;
};
add_filter('pre_wp_mail', $captureGroup, 10, 2);
$r = $api('GET', '/me/groups', [], $tokenA, ['X-Tenant-Slug' => null]);
$ga = array_values(array_filter($r['body']['data'] ?? [], static fn ($g) => $g['id'] === (string) $gA))[0] ?? null;
$check($r['status'] === 200 && $ga !== null && ! in_array((string) $gB, array_column($r['body']['data'], 'id'), true), 'GET /me/groups (ohne Tenant wie /me*): nur eigene Gruppen');
$check($ga !== null && $ga['seats'] === ['total' => 3, 'used' => 0, 'free' => 3] && $ga['join_code'] === $joinA && $ga['join_url'] === \Bootspruefung\Learning\Commerce\GroupService::joinUrl($joinA) && $ga['members'] === [] && $ga['invite_valid_days'] === 30, 'Plätze, Beitrittscode und -link (wie im Web)');
$check($ga !== null && ($ga['scopes'][0] ?? []) === ['course_id' => $courseId, 'course_code' => $courseCode, 'course_name' => \Bootspruefung\Learning\Commerce\Labels::course($courseCode), 'feature' => 'question_bank', 'capability' => 'smarttrainer', 'feature_label' => \Bootspruefung\Learning\Commerce\Labels::feature('question_bank')] && $ga['valid_until'] !== null && str_ends_with($ga['valid_until'], 'Z'), 'Umfang mit Kurs-ID und Fähigkeit, Laufzeit (UTC)');
$check(($r['body']['meta']['groups'] ?? 0) >= 1 && isset($r['body']['meta']['seats_total'], $r['body']['meta']['seats_used']), 'meta: Gruppen und Plätze gesamt');
$check($api('GET', '/me/groups')['status'] === 401, 'ohne Anmeldung → 401');
$r = $api('GET', "/me/groups/{$gA}", [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data']['id'] === (string) $gA, 'GET /me/groups/{id}');
$check($api('GET', "/me/groups/{$gB}", [], $tokenA)['status'] === 404 && $api('GET', '/me/groups/999999', [], $tokenA)['status'] === 404, 'fremde bzw. unbekannte Gruppe → 404');
$r = $api('POST', "/me/groups/{$gB}/invites", ['emails' => ['x@example.test']], $tokenA);
$check($r['status'] === 404 && $groupMails === [] && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}learning_group_member WHERE group_id = %d", $gB)) === 0, 'in fremde Gruppe einladen → 404, keine Mail');
$check($api('POST', "/me/groups/{$gB}/join-code", [], $tokenA)['status'] === 404 && $wpdb->get_var($wpdb->prepare("SELECT join_token FROM {$p}learning_group WHERE id = %d", $gB)) === $joinB, 'fremden Beitrittslink erneuern → 404, Link unverändert');
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => []], $tokenA);
$check($r['status'] === 422 && isset($r['body']['errors']['emails']), 'Einladen ohne Adresse → 422');
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => array_fill(0, 201, 'a@example.test')], $tokenA);
$check($r['status'] === 422 && $code($r) === 'VALIDATION_FAILED', 'mehr als 200 Adressen → 422 (wie Web)');
$ikey = $uuid();
$inv = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['eins@example.test', 'kaputt', ' EINS@example.test ']], $tokenA, ['Idempotency-Key' => $ikey]);
$invMember = array_values(array_filter($inv['body']['data']['group']['members'] ?? [], static fn ($m) => $m['email'] === 'eins@example.test'))[0] ?? null;
$check($inv['status'] === 200 && $inv['body']['data']['invited'] === 1 && $inv['body']['data']['skipped'] === ['kaputt'] && $invMember !== null && $invMember['status'] === 'invited' && $invMember['removable'] === true, 'Einladung: gültige Adresse eingeladen, ungültige übersprungen, doppelte zusammengefasst');
$check($invMember !== null && strtotime($invMember['invite_expires_at']) - strtotime($invMember['invited_at']) === 30 * DAY_IN_SECONDS && $inv['body']['data']['group']['seats'] === ['total' => 3, 'used' => 1, 'free' => 2], 'offene Einladung: Ablauf nach 30 Tagen, belegt einen Platz');
$personal = (string) $wpdb->get_var($wpdb->prepare("SELECT invite_token FROM {$p}learning_group_member WHERE group_id = %d AND email = %s", $gA, 'eins@example.test'));
$check(count($groupMails) === 1 && $groupMails[0]['to'] === 'eins@example.test' && str_contains($groupMails[0]['message'], \Bootspruefung\Learning\Commerce\GroupService::joinUrl($personal)) && str_starts_with($personal, 'i'), 'Einladungsmail wie im Web mit persönlichem Link …/gruppe/i…/');
$again = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['eins@example.test', 'kaputt']], $tokenA, ['Idempotency-Key' => $ikey]);
$check($again['status'] === 200 && $again['body'] === $inv['body'] && count($groupMails) === 1, 'Wiederholung mit gleichem Idempotency-Key: gespeicherte Antwort, keine zweite Mail');
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['andere@example.test']], $tokenA, ['Idempotency-Key' => $ikey]);
$check($r['status'] === 409 && $code($r) === 'IDEMPOTENCY_KEY_REUSED', 'gleicher Key, andere Adressen → 409');
$r = $api('POST', "/me/groups/{$gA}/invites", ['email' => 'eins@example.test'], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['invited'] === 0 && $r['body']['data']['skipped'] === ['eins@example.test'], 'schon eingeladen (Feld email) → übersprungen');
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['zwei@example.test', 'drei@example.test', 'vier@example.test']], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['invited'] === 2 && $r['body']['data']['skipped'] === ['vier@example.test'] && $r['body']['data']['group']['seats']['free'] === 0, 'Platzgrenze: mehr Einladungen als freie Plätze → Rest übersprungen');
$wpdb->query($wpdb->prepare("UPDATE {$p}learning_group_member SET invited_at = %s WHERE group_id = %d AND email = %s", gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS), $gA, 'zwei@example.test'));
$r = $api('GET', "/me/groups/{$gA}", [], $tokenA);
$zwei = array_values(array_filter($r['body']['data']['members'], static fn ($m) => $m['email'] === 'zwei@example.test'))[0] ?? null;
$check($zwei !== null && $zwei['status'] === 'expired' && $zwei['removable'] === false && strtotime($zwei['invite_expires_at']) < time() && $r['body']['data']['seats'] === ['total' => 3, 'used' => 2, 'free' => 1], 'abgelaufene Einladung: Status expired (auch vor dem Cron), belegt keinen Platz');
$check($r['body']['data']['invites_remaining_today'] === $gs->inviteBudget($gs->find($gA)) && $r['body']['data']['invites_remaining_today'] === \Bootspruefung\Learning\Commerce\GroupService::INVITE_LIMIT - 2, 'invites_remaining_today = Tageslimit des GroupService');
// Tageslimit (Schutz vor Mail-Relay): wie im Web werden Adressen über dem Limit übersprungen.
$filler = [];
for ($i = 0; $i < 18; $i++) {
    $wpdb->insert($p.'learning_group_member', ['group_id' => $gA, 'email' => null, 'status' => 'removed', 'invited_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    $filler[] = (int) $wpdb->insert_id;
}
$mailsBefore = count($groupMails);
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['fuenf@example.test']], $tokenA);
$check($r['status'] === 200 && $r['body']['data']['invited'] === 0 && $r['body']['data']['skipped'] === ['fuenf@example.test'] && $r['body']['data']['group']['invites_remaining_today'] === 0 && count($groupMails) === $mailsBefore, 'Tageslimit erreicht: übersprungen, keine Mail (wie Web)');
$wpdb->query('DELETE FROM '.$p.'learning_group_member WHERE id IN ('.implode(',', $filler).')');
// Rate-Limit je Konto: 20 Einladungsvorgänge pro Stunde; Wiederholung mit altem Key bleibt möglich.
for ($i = 0; $i < 20; $i++) {
    \Bootspruefung\Learning\Mobile\RateLimiter::increment('group-invite|'.$alice, HOUR_IN_SECONDS);
}
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['sechs@example.test']], $tokenA);
$check($r['status'] === 429 && $code($r) === 'RATE_LIMITED' && ($r['body']['retry_after'] ?? 0) > 0, 'zu viele Einladungsvorgänge → 429 RATE_LIMITED');
$check($api('POST', "/me/groups/{$gA}/invites", ['emails' => ['eins@example.test', 'kaputt']], $tokenA, ['Idempotency-Key' => $ikey])['status'] === 200, 'gespeicherte Antwort trotz Limit (Idempotenz vor Limit)');
\Bootspruefung\Learning\Mobile\RateLimiter::clear('group-invite|'.$alice);

echo "Einladungslink als App-Link (1.7.0)\n";
$erin = $customer('mobile_gruppe_e', 'Mobil-Passwort-654!');
$tokE = (string) ($login('mobile_gruppe_e@example.test', 'Mobil-Passwort-654!')['body']['data']['access_token'] ?? '');
$check((new AccessService())->limit($erin, $courseCode, AccessService::QUESTION_BANK) !== null, 'neues Konto ohne vollen Zugang');
$pv = $api('GET', '/groups/join/'.$personal, [], $tokE);
$check($pv['status'] === 200 && $pv['body']['data']['name'] === 'App-Gruppe Alice' && $pv['body']['data']['personal'] === true && $pv['body']['data']['joinable'] === true && $pv['body']['data']['already_member'] === false && $pv['body']['data']['scopes'][0]['course_id'] === $courseId, 'Vorschau des persönlichen Einladungslinks (wie Web groups/join/{token})');
$check($api('GET', '/groups/join/'.$personal, [], $tokE, ['X-Tenant-Slug' => null])['status'] === 422, 'Vorschau braucht X-Tenant-Slug (wie /coupons/redeem)');
$r = $api('GET', '/groups/join/g'.str_repeat('0', 32), [], $tokE);
$check($r['status'] === 422 && $code($r) === 'COUPON_INVALID' && $api('GET', '/groups/join/kaputt', [], $tokE)['status'] === 422, 'unbekannter bzw. kaputter Link → 422 COUPON_INVALID');
$r = $api('POST', '/coupons/redeem', ['code' => \Bootspruefung\Learning\Commerce\GroupService::joinUrl($personal)], $tokE);
$joined = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}learning_group_member WHERE group_id = %d AND user_id = %d", $gA, $erin));
$check($r['status'] === 200 && $r['body']['data']['kind'] === 'group_license' && $r['body']['data']['group_name'] === 'App-Gruppe Alice' && $joined && $joined->status === 'active' && $joined->email === 'eins@example.test' && $joined->invite_token === null, 'App-Link (ganzer Einladungslink i…) über POST /coupons/redeem eingelöst – wie Web groups/join');
$check((new AccessService())->can($erin, $courseCode, AccessService::QUESTION_BANK) && (new AccessService())->limit($erin, $courseCode, AccessService::QUESTION_BANK) === null, 'Platz-Zugang freigeschaltet');
$r = $api('GET', '/groups/join/'.$personal, [], $tokE);
$check($r['status'] === 422 && $code($r) === 'COUPON_INVALID', 'persönlicher Link nach dem Annehmen ungültig');
$pv = $api('GET', '/groups/join/'.$joinA, [], $tokE);
$check($pv['status'] === 200 && $pv['body']['data']['personal'] === false && $pv['body']['data']['already_member'] === true, 'Gruppenlink: Vorschau erkennt bestehende Mitgliedschaft');
$r = $api('GET', "/me/groups/{$gA}", [], $tokE);
$check($r['status'] === 404, 'Mitglied sieht die Gruppe nicht in der Verwaltung (wie Web: nur Besitzer)');
$r = $api('GET', "/me/groups/{$gA}", [], $tokenA);
$erinM = array_values(array_filter($r['body']['data']['members'], static fn ($m) => $m['user_id'] === (string) $erin))[0] ?? null;
$check($erinM !== null && $erinM['status'] === 'active' && $erinM['name'] === 'Kunde mobile_gruppe_e' && $erinM['joined_at'] !== null && $erinM['invite_expires_at'] === null && $erinM['is_me'] === false, 'Besitzerin sieht das neue Mitglied (aktiv, Name, Beitritt)');
wp_set_current_user($alice);
$webMine = (new \Bootspruefung\Learning\Rest\Controllers\GroupController())->mine();
wp_set_current_user(0);
$webA = array_values(array_filter($webMine, static fn ($g) => $g['id'] === $gA))[0] ?? null;
$check($webA !== null && array_map('strval', array_column($webA['members'], 'id')) === array_column($r['body']['data']['members'], 'id') && array_column($webA['members'], 'status') === array_column($r['body']['data']['members'], 'status') && $webA['free_seats'] === $r['body']['data']['seats']['free'], 'gleiche Mitglieder, Status und freie Plätze wie im Web (GET /me/groups)');
// App-Link-Datei
$check(! AppLinks::configured() && AppLinks::wellKnown('/.well-known/apple-app-site-association')['status'] === 404, 'ohne App-Kennungen: keine apple-app-site-association (404)');
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => 'ABCDE12345.at.example.bootsschule']));
$aj = json_decode((string) (AppLinks::wellKnown('/.well-known/apple-app-site-association')['body'] ?? ''), true);
$paths = array_column($aj['applinks']['details'][0]['components'] ?? [], '/');
$joinPath = (string) wp_parse_url(\Bootspruefung\Learning\Commerce\GroupService::joinUrl($personal), PHP_URL_PATH);
$check(in_array(AppLinks::groupJoinPathPattern(), $paths, true) && AppLinks::groupJoinPathPattern() === '/'.\Bootspruefung\Learning\Frontend\Router::base().'/gruppe/*' && fnmatch(AppLinks::groupJoinPathPattern(), $joinPath) && in_array(AppLinks::resetPathPattern(), $paths, true) && in_array(AppLinks::deletionPathPattern(), $paths, true), 'apple-app-site-association enthält /bootsscheine/gruppe/* (passt auf den Einladungslink), bisherige Pfade bleiben');
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => '']));
$check(AppLinks::wellKnown($joinPath) === null, 'Browser ohne App: Einladungslink wird von AppLinks nicht abgefangen');
// Browser ohne App: der Pfad bleibt die Seite des Lernbereichs (Rewrite → bp_app, bp_path=gruppe/<token>).
global $wp_rewrite;
$permalinkBefore = (string) get_option('permalink_structure');
$wp_rewrite->set_permalink_structure('/%postname%/');
$wp_rewrite->flush_rules(false);
$_SERVER['REQUEST_URI'] = $joinPath;
$wpParse = new WP();
$wpParse->parse_request();
$check((string) ($wpParse->query_vars['bp_app'] ?? '') === '1' && ($wpParse->query_vars['bp_path'] ?? '') === 'gruppe/'.$personal, 'Einladungslink im Browser: Seite des Lernbereichs (Weg „gruppe/<token>“ wie bisher)');
$wp_rewrite->set_permalink_structure($permalinkBefore);
$wp_rewrite->flush_rules(false);
$_SERVER['REQUEST_URI'] = '/';

echo "Gruppen: Platz freigeben, Beitrittslink erneuern (1.7.0)\n";
$r = $api('DELETE', "/me/groups/{$gA}/members/{$erinM['id']}", [], $tokenB);
$check($r['status'] === 404 && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}learning_group_member WHERE id = %d", (int) $erinM['id'])) === 'active', 'fremde Person gibt keinen Platz frei → 404');
$wpdb->insert($p.'learning_group_member', ['group_id' => $gB, 'email' => 'b1@example.test', 'status' => 'invited', 'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
$bobMember = (int) $wpdb->insert_id;
$r = $api('DELETE', "/me/groups/{$gA}/members/{$bobMember}", [], $tokenA);
$check($r['status'] === 404 && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}learning_group_member WHERE id = %d", $bobMember)) === 'invited', 'Mitglied einer anderen Gruppe über die eigene Gruppe → 404');
$check($api('DELETE', "/me/groups/{$gA}/members/999999", [], $tokenA)['status'] === 404, 'unbekanntes Mitglied → 404');
$r = $api('DELETE', "/me/groups/{$gA}/members/{$erinM['id']}", ['reason' => 'Kurs abgebrochen'], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && ! in_array($erinM['id'], array_column($r['body']['data']['members'], 'id'), true) && (new AccessService())->limit($erin, $courseCode, AccessService::QUESTION_BANK) !== null, 'Platz freigegeben: Mitglied entfernt, Platz-Zugang widerrufen (GroupService::removeMember)');
$check($wpdb->get_var($wpdb->prepare("SELECT reason FROM {$p}entitlement WHERE reference_key = %s LIMIT 1", \Bootspruefung\Learning\Commerce\GroupService::reference($gA, (int) $erinM['id']))) === 'Kurs abgebrochen' && $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}entitlement WHERE reference_key = %s LIMIT 1", \Bootspruefung\Learning\Commerce\GroupService::reference($gA, (int) $erinM['id']))) === 'revoked', 'Grund wird wie im Web am Zugang gespeichert');
$check($api('DELETE', "/me/groups/{$gA}/members/{$erinM['id']}", [], $tokenA)['status'] === 404, 'bereits entfernt → 404');
$drei = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}learning_group_member WHERE group_id = %d AND email = %s", $gA, 'drei@example.test'));
$r = $api('DELETE', "/me/groups/{$gA}/members/{$drei}", [], $tokenA);
$check($r['status'] === 200 && $wpdb->get_var($wpdb->prepare("SELECT email FROM {$p}learning_group_member WHERE id = %d", $drei)) === null, 'offene Einladung zurückgezogen (Adresse sofort gelöscht, wie Web)');
// Besitzerin tritt der eigenen Gruppe bei und gibt den eigenen Platz frei – im Web ebenso erlaubt.
$api('POST', '/coupons/redeem', ['code' => $joinA], $tokenA);
$selfM = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}learning_group_member WHERE group_id = %d AND user_id = %d AND status = 'active'", $gA, $alice));
$r = $api('GET', "/me/groups/{$gA}", [], $tokenA);
$selfRow = array_values(array_filter($r['body']['data']['members'], static fn ($m) => $m['id'] === (string) $selfM))[0] ?? null;
$r = $api('DELETE', "/me/groups/{$gA}/members/{$selfM}", [], $tokenA);
$check($selfM > 0 && $selfRow !== null && $selfRow['is_me'] === true && $r['status'] === 200, 'eigener Platz der Besitzerin: is_me, freigeben erlaubt (wie Web)');
$ckey = $uuid();
$nc = $api('POST', "/me/groups/{$gA}/join-code", [], $tokenA, ['Idempotency-Key' => $ckey, 'X-Tenant-Slug' => null]);
$newJoin = (string) ($nc['body']['data']['join_code'] ?? '');
$check($nc['status'] === 200 && preg_match('/^g[0-9a-f]{32}$/', $newJoin) === 1 && $newJoin !== $joinA && $wpdb->get_var($wpdb->prepare("SELECT join_token FROM {$p}learning_group WHERE id = %d", $gA)) === $newJoin && $nc['body']['data']['join_url'] === \Bootspruefung\Learning\Commerce\GroupService::joinUrl($newJoin), 'neuer Beitrittscode (GroupService::regenerateToken)');
$nc2 = $api('POST', "/me/groups/{$gA}/join-code", [], $tokenA, ['Idempotency-Key' => $ckey]);
$check($nc2['status'] === 200 && $nc2['body']['data']['join_code'] === $newJoin, 'Wiederholung mit gleichem Key erzeugt keinen weiteren Code');
$r = $api('POST', '/coupons/redeem', ['code' => $joinA], $tokenB);
$check($r['status'] === 422 && $code($r) === 'COUPON_INVALID' && $api('GET', '/groups/join/'.$joinA, [], $tokenB)['status'] === 422, 'alter Beitrittscode ungültig (Einlösen und Vorschau)');
$check($api('GET', '/groups/join/'.$newJoin, [], $tokenB)['status'] === 200, 'neuer Beitrittscode gültig');
$wpdb->update($p.'learning_group', ['status' => 'suspended'], ['id' => $gA]);
$r = $api('GET', "/me/groups/{$gA}", [], $tokenA);
$check($r['body']['data']['status'] === 'suspended' && $r['body']['data']['join_code'] === null && $r['body']['data']['join_url'] === null && $r['body']['data']['invites_remaining_today'] === 0, 'ausgesetzte Gruppe: kein Beitrittslink (wie Web)');
$r = $api('POST', "/me/groups/{$gA}/invites", ['emails' => ['sieben@example.test']], $tokenA);
$r2 = $api('POST', "/me/groups/{$gA}/join-code", [], $tokenA);
$check($r['status'] === 409 && $code($r) === 'GROUP_NOT_ACTIVE' && $r2['status'] === 409 && $code($r2) === 'GROUP_NOT_ACTIVE' && $wpdb->get_var($wpdb->prepare("SELECT join_token FROM {$p}learning_group WHERE id = %d", $gA)) === $newJoin, 'ausgesetzte Gruppe: Einladen und neuer Link → 409 GROUP_NOT_ACTIVE');
$pv = $api('GET', '/groups/join/'.$newJoin, [], $tokenB);
$check($pv['status'] === 200 && $pv['body']['data']['joinable'] === false && $pv['body']['data']['status'] === 'suspended', 'Vorschau: ausgesetzte Gruppe nicht beitretbar');
remove_filter('pre_wp_mail', $captureGroup, 10);
foreach ([$gA, $gB] as $gid) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE reference_key LIKE %s", 'group:'.$gid.':%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}learning_group_member WHERE group_id = %d", $gid));
    $wpdb->delete($p.'learning_group', ['id' => $gid]);
}
wp_delete_user($erin);

echo "Bestimmte Frage gezielt üben (1.7.0)\n";
$modsAll = $content->modules((int) $courseRow->id);
$courseQs = [];
foreach ($content->questionsForModules(array_map(static fn ($m) => (int) $m->id, $modsAll)) as $qrow) {
    $courseQs[(int) $qrow->question_id] ??= $qrow;
}
$fresh = null;
foreach ($courseQs as $qid => $qrow) {
    if (! $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}progress WHERE user_id = %d AND question_id = %d", $alice, $qid)) && ! in_array($qid, LearningService::blockedByRunningExam($alice), true)) {
        $fresh = $qid;
        break;
    }
}
$r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question", [], $tokenA);
$check($r['status'] === 422 && isset($r['body']['errors']['question_id']), 'mode=question ohne question_id → 422');
$r = $api('GET', "/courses/{$courseId}/trainer/next?question_id={$fresh}", [], $tokenA);
$check($r['status'] === 422 && isset($r['body']['errors']['question_id']), 'question_id ohne mode=question → 422 (keine stille Umdeutung)');
$check($api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id=999999999", [], $tokenA)['status'] === 404 && $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id=abc", [], $tokenA)['status'] === 404, 'unbekannte Frage → 404');
$foreign = 0;
foreach ($content->courses() as $oc) {
    foreach ($foreign === 0 && (int) $oc->id !== (int) $courseRow->id ? $content->questionsForModules(array_map(static fn ($m) => (int) $m->id, $content->modules((int) $oc->id))) : [] as $qrow) {
        if (! isset($courseQs[(int) $qrow->question_id])) {
            $foreign = (int) $qrow->question_id;
            break;
        }
    }
}
$check($foreign > 0, 'Testvoraussetzung: Frage, die nur in anderen Kursen vorkommt');
if ($foreign > 0) {
    $r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$foreign}", [], $tokenA);
    $check($r['status'] === 404 && $code($r) === 'RESOURCE_NOT_FOUND', 'Frage eines anderen Kurses → 404');
}
$attemptsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d AND question_id = %d", $alice, (int) $fresh));
$qq = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$fresh}", [], $tokenA);
$qd = $qq['body']['data'] ?? [];
$check($fresh !== null && $qq['status'] === 200 && $qd['question']['id'] === (string) $fresh && $qd['mode'] === 'question' && is_string($qd['answer_token']) && $qd['answer_token_expires_in'] === QuestionPresenter::TTL && ! str_contains((string) wp_json_encode($qd), 'is_correct'), 'mode=question: genau diese Frage mit answer_token (wie trainer/next)');
$qOpen = ($qd['question']['answer_selection'] ?? '') === 'open';
if ($qOpen) {
    $api('POST', "/courses/{$courseId}/trainer/reveal", ['answer_token' => $qd['answer_token']], $tokenA);
}
$qa = $api('POST', "/courses/{$courseId}/attempts", ['answer_token' => $qd['answer_token'], 'question_id' => (string) $fresh, 'selected_answer_ids' => $qOpen ? [] : [$qd['question']['answers'][0]['id']], 'known' => $qOpen ? true : null, 'mode' => 'question'], $tokenA, ['Idempotency-Key' => $uuid()]);
$qp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}progress WHERE user_id = %d AND question_id = %d", $alice, (int) $fresh));
$check($qa['status'] === 201 && $qa['body']['data']['question_id'] === (string) $fresh && $qp && (int) $qp->attempts === 1 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt WHERE user_id = %d AND question_id = %d", $alice, (int) $fresh)) === $attemptsBefore + 1 && $qp->learn_status === $qa['body']['data']['learning_status'], 'Antwort mit diesem Token zählt in denselben Lernstand (bp_attempt, bp_progress)');
$qq2 = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$fresh}", [], $tokenA);
$check($qq2['status'] === 200 && $qq2['body']['data']['question']['id'] === (string) $fresh && $qq2['body']['data']['answer_token'] !== $qd['answer_token'] && $qq2['body']['data']['progress']['attempts'] === 1, 'gleiche Frage direkt wieder abrufbar (neues Token, aktueller Fortschritt)');
// Teilumfang: Konto ohne Kauf, kostenlos die erste Frage je Modul.
$frida = $customer('mobile_frei_f', 'Mobil-Passwort-777!');
$tokF = (string) ($login('mobile_frei_f@example.test', 'Mobil-Passwort-777!')['body']['data']['access_token'] ?? '');
$wpdb->insert($p.'access_rule', ['course_code' => $courseCode, 'feature_code' => 'question_bank', 'access_type' => 'free_limited', 'limit_type' => AccessService::LIMIT_TYPES['question_bank'], 'limit_value' => 1, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
$freeRule = (int) $wpdb->insert_id;
$firstMod = (int) $modsAll[0]->id;
$modQs = array_values(array_map(static fn ($r) => (int) $r->question_id, $content->questionsForModules([$firstMod])));
$allowedF = $learning->allowedQuestions($frida, $courseRow, array_map(static fn ($m) => (int) $m->id, $modsAll));
$lockedF = null;
foreach ($modQs as $qid) {
    if (! isset($allowedF[$qid])) {
        $lockedF = $qid;
        break;
    }
}
$r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$modQs[0]}", [], $tokF);
$check($r['status'] === 200 && $r['body']['data']['question']['id'] === (string) $modQs[0], 'Teilumfang: freigegebene Frage gezielt übbar');
if ($lockedF !== null) {
    $r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$lockedF}", [], $tokF);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED' && ($r['body']['details']['reason'] ?? '') === 'partial_access' && ($r['body']['details']['question_id'] ?? '') === (string) $lockedF && array_key_exists('webshop_url', $r['body']['details']), 'Teilumfang: weitere Frage des Moduls → 403 CONTENT_LOCKED (partial_access)');
}
$wpdb->delete($p.'access_rule', ['id' => $freeRule]);
$r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$modQs[0]}", [], $tokF);
$check($r['status'] === 403 && $code($r) === 'ENTITLEMENT_REQUIRED', 'ohne Zugang → 403 ENTITLEMENT_REQUIRED (wie trainer/next)');
wp_delete_user($frida);
// Zeitgesteuerte Freigabe: Modul erst 30 Tage nach Zugangsbeginn.
$wpdb->insert($p.'access_rule', ['course_code' => $courseCode, 'feature_code' => 'question_bank', 'module_code' => (string) $modsAll[0]->code, 'access_type' => 'drip', 'limit_type' => 'days_after_start', 'limit_value' => 30, 'created_at' => current_time('mysql', true), 'updated_at' => current_time('mysql', true)]);
$dripRule = (int) $wpdb->insert_id;
$onlyFirst = null;
$otherModQs = [];
foreach (array_slice($modsAll, 1) as $m) {
    foreach ($content->questionsForModules([(int) $m->id]) as $qrow) {
        $otherModQs[(int) $qrow->question_id] = true;
    }
}
foreach ($modQs as $qid) {
    if (! isset($otherModQs[$qid])) {
        $onlyFirst = $qid;
        break;
    }
}
if ($onlyFirst !== null) {
    $r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$onlyFirst}", [], $tokenB);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED' && ($r['body']['details']['reason'] ?? '') === 'scheduled_release' && ($r['body']['details']['module_id'] ?? '') === (string) $firstMod && strtotime((string) $r['body']['details']['unlock_at']) > time() && str_contains($r['body']['detail'], 'freigeschaltet'), 'zeitgesteuerte Freigabe: Frage im gesperrten Modul → 403 CONTENT_LOCKED (scheduled_release, unlock_at)');
}
$wpdb->delete($p.'access_rule', ['id' => $dripRule]);
// Frage einer laufenden Prüfung: im Lernmodus gesperrt (wie trainer/next).
$runExam = $api('POST', "/courses/{$courseId}/exams", ['paper_id' => $paper['id']], $tokenB);
$runId = (int) ($runExam['body']['data']['exam_id'] ?? 0);
$inExam = (int) $wpdb->get_var($wpdb->prepare("SELECT question_id FROM {$p}exam_session_question WHERE session_id = %d ORDER BY position LIMIT 1", $runId));
if ($runId > 0 && $inExam > 0 && isset($courseQs[$inExam])) {
    $r = $api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$inExam}", [], $tokenB);
    $check($r['status'] === 403 && $code($r) === 'CONTENT_LOCKED' && ($r['body']['details']['reason'] ?? '') === 'running_exam', 'Frage der laufenden Prüfung → 403 CONTENT_LOCKED (running_exam)');
    $api('POST', "/exams/{$runId}/submit", [], $tokenB);
    $check($api('GET', "/courses/{$courseId}/trainer/next?mode=question&question_id={$inExam}", [], $tokenB)['status'] === 200, 'nach der Abgabe wieder übbar');
}

echo "Datenexport und Löschantrag\n";
$scheduled = static function (string $id): bool {
    $hook = \Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK;

    return function_exists('as_has_scheduled_action') ? as_has_scheduled_action($hook, [$id], 'bootspruefung-learning') : wp_next_scheduled($hook, [$id]) !== false;
};
$exp = $api('POST', '/profile/export', [], $tokenA, ['Idempotency-Key' => $uuid()]);
$expId = (string) ($exp['body']['data']['export_id'] ?? '');
$check($exp['status'] === 202 && $exp['body']['data']['status'] === 'pending' && $exp['body']['data']['download_url'] === null && preg_match('/^[0-9a-f]{32}$/', $expId) === 1, 'Export angefordert: 202 pending ohne Download-Adresse (asynchron)');
$check($scheduled($expId), 'Erzeugung im Hintergrund geplant ('.(function_exists('as_enqueue_async_action') ? 'Action Scheduler' : 'WP-Cron').')');
$st = $api('GET', "/profile/export/{$expId}", [], $tokenA);
$check($st['status'] === 200 && $st['body']['data']['status'] === 'pending' && $st['body']['data']['expires_at'] !== null, 'Status gleich nach dem Anfordern: pending');
$r = $api('GET', "/profile/export/{$expId}/download", [], $tokenA);
$check($r['status'] === 409 && $code($r) === 'EXPORT_NOT_READY' && isset($r['headers']['Retry-After']), 'Download vor Fertigstellung → 409 EXPORT_NOT_READY');
do_action(\Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK, $expId);
$st = $api('GET', "/profile/export/{$expId}", [], $tokenA);
$check($st['status'] === 200 && $st['body']['data']['status'] === 'ready' && str_contains((string) $st['body']['data']['download_url'], '/boatingschool/v1/profile/export/'.$expId.'/download') && $st['body']['data']['size_bytes'] > 0, 'Hintergrundaufgabe erzeugt den Export → ready mit Download-Adresse');
$dl = $api('GET', "/profile/export/{$expId}/download", [], $tokenA);
$check(str_contains((string) ($dl['headers']['Content-Disposition'] ?? ''), "filename*=UTF-8''datenexport-") && ($dl['headers']['Accept-Ranges'] ?? '') === 'none', 'Download: Content-Disposition mit filename*, Accept-Ranges: none');
$groups = array_keys($dl['body']['groups'] ?? []);
$check($dl['status'] === 200 && in_array('bootspruefung-learning-attempt', $groups, true) && in_array('bootspruefung-learning-mobile_session', $groups, true), 'Export enthält Lernstand und App-Sitzungen');
$check(in_array('user', $groups, true) || (bool) array_filter($groups, static fn ($g) => str_contains($g, 'user')), 'Export enthält das WordPress-Konto');
$exportedSettings = json_decode((string) ($dl['body']['groups']['bootspruefung-learning-app_settings']['items'][0]['data']['settings'] ?? ''), true);
$check(($exportedSettings['theme'] ?? '') === 'dark' && ($dl['body']['groups']['bootspruefung-learning-app_settings']['items'][0]['data']['version'] ?? '') === '6', 'Export (/profile/export) enthält die App-Einstellungen');
$check(! str_contains((string) wp_json_encode($dl['body']), (string) $row->access_hash), 'Export ohne Token-Hashes');
if (Integration::active()) {
    $check((bool) array_filter($groups, static fn ($g) => str_contains($g, 'woocommerce')), 'Export enthält WooCommerce-Daten');
}
$check($api('GET', "/profile/export/{$expId}/download", [], $tokenB)['status'] === 404 && $api('GET', "/profile/export/{$expId}/download")['status'] === 401, 'Export nur für den Eigentümer mit Anmeldung');
$uploads = wp_upload_dir(null, false)['basedir'];
$check(! glob($uploads.'/**/*'.$expId.'*'), 'kein Exportfile im Upload-Ordner');
$wpdb->update($p.'mobile_export', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['public_id' => $expId]);
\Bootspruefung\Learning\Mobile\MobileApi::cleanup();
$check(! $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}mobile_export WHERE public_id = %s", $expId)), 'abgelaufener Export wird automatisch gelöscht');
// Rückfall: Hintergrundaufgabe läuft nicht (Cron aus) → Statusabfrage erzeugt nach 30 s selbst.
$exp2 = $api('POST', '/profile/export', [], $tokenA);
$exp2Id = (string) ($exp2['body']['data']['export_id'] ?? '');
$wpdb->update($p.'mobile_export', ['created_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['public_id' => $exp2Id]);
$wpdb->update($p.'mobile_export', ['started_at' => gmdate('Y-m-d H:i:s', time() - 10)], ['public_id' => $exp2Id]);
$st = $api('GET', "/profile/export/{$exp2Id}", [], $tokenA);
$check($st['status'] === 200 && $st['body']['data']['status'] === 'pending', 'Rückfall gesperrt, solange ein anderer Lauf arbeitet (started_at)');
$wpdb->update($p.'mobile_export', ['started_at' => null], ['public_id' => $exp2Id]);
$st = $api('GET', "/profile/export/{$exp2Id}", [], $tokenA);
$check($st['status'] === 200 && $st['body']['data']['status'] === 'ready' && $api('GET', "/profile/export/{$exp2Id}/download", [], $tokenA)['status'] === 200, 'wartet der Export > 30 s, erzeugt ihn die Statusabfrage selbst');
$check(! $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}mobile_export WHERE public_id = %s", $expId)) && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}mobile_export WHERE user_id = %d", $alice)) === 1, 'nur der neueste Export eines Kontos bleibt');
$boom = static fn (array $e): array => $e + ['bp-test-boom' => ['exporter_friendly_name' => 'Test', 'callback' => static function (): array {
    throw new \RuntimeException('Exporter kaputt');
}]];
add_filter('wp_privacy_personal_data_exporters', $boom, 999);
$exp3 = $api('POST', '/profile/export', [], $tokenA);
$exp3Id = (string) ($exp3['body']['data']['export_id'] ?? '');
do_action(\Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK, $exp3Id);
remove_filter('wp_privacy_personal_data_exporters', $boom, 999);
$st = $api('GET', "/profile/export/{$exp3Id}", [], $tokenA);
$r = $api('GET', "/profile/export/{$exp3Id}/download", [], $tokenA);
$check($st['body']['data']['status'] === 'failed' && $st['body']['data']['download_url'] === null && $r['status'] === 410 && $code($r) === 'EXPORT_FAILED', 'Fehler bei der Erzeugung → failed, Download 410 EXPORT_FAILED');
foreach ([$expId, $exp2Id, $exp3Id] as $eid) {
    function_exists('as_unschedule_all_actions') ? as_unschedule_all_actions(\Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK, [$eid], 'bootspruefung-learning') : wp_clear_scheduled_hook(\Bootspruefung\Learning\Mobile\Controllers\PrivacyController::GENERATE_HOOK, [$eid]);
}
$wpdb->delete($p.'mobile_export', ['user_id' => $alice]);
$del = $api('POST', '/me/deletion-request', [], $tokenA, ['X-Tenant-Slug' => null]);
$reqId = (int) ($del['body']['data']['request_id'] ?? 0);
$check($del['status'] === 202 && $reqId > 0 && get_post_type($reqId) === 'user_request' && $del['body']['data']['status'] === 'pending_confirmation', 'Löschantrag als WordPress-Datenschutzanfrage');
$del2 = $api('POST', '/me/deletion-request', [], $tokenA, ['X-Tenant-Slug' => null]);
$check($del2['status'] === 202 && $del2['body']['data']['already_requested'] === true && (int) $del2['body']['data']['request_id'] === $reqId, 'zweiter Antrag: bestehender Antrag');

echo "Sitzungen, Rotation, Abmeldung\n";
$s = $api('GET', '/me/sessions', [], $tokenA, ['X-Tenant-Slug' => null]);
$current = array_values(array_filter($s['body']['data'] ?? [], static fn ($x) => $x['current']));
$check($s['status'] === 200 && count($s['body']['data']) === 2 && count($current) === 1 && $current[0]['id'] === (string) $tokensA['session_id'], 'Sitzungsliste mit aktueller Sitzung');
$r = $api('DELETE', '/me/sessions/'.$tokensA2['session_id'], [], $tokenA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 204 && $api('GET', '/me', [], (string) $tokensA2['access_token'])['status'] === 401, 'andere Sitzung widerrufen → deren Token ungültig');
$rot = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$new = $rot['body']['data'] ?? [];
$check($rot['status'] === 200 && $new['refresh_token'] !== $tokensA['refresh_token'] && $new['session_id'] === $tokensA['session_id'], 'Refresh rotiert das Token-Paar');
$check($api('GET', '/me', [], $tokenA)['status'] === 401 && $api('GET', '/me', [], (string) $new['access_token'])['status'] === 200, 'altes Access-Token ungültig, neues gültig');
// Antwort verloren (Funkloch): vorheriges Refresh-Token innerhalb von 60 s → neues gültiges Paar.
$retry = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$again = $retry['body']['data'] ?? [];
$check($retry['status'] === 200 && $again['session_id'] === $tokensA['session_id'] && $again['refresh_token'] !== $new['refresh_token'] && $again['access_token'] !== $new['access_token'], 'verlorene Refresh-Antwort: altes Token im Kulanzfenster → neues Token-Paar derselben Sitzung');
$check($api('GET', '/me', [], (string) $again['access_token'])['status'] === 200 && $api('GET', '/me', [], (string) $new['access_token'])['status'] === 401, 'das verlorene Paar ist ungültig, das neue gültig');
$check($api('POST', '/auth/refresh', ['refresh_token' => $new['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 401 && $api('GET', '/me', [], (string) $again['access_token'])['status'] === 200, 'verlorenes Refresh-Token → 401 ohne Widerruf der Sitzung');
$sessRow = $wpdb->get_row($wpdb->prepare("SELECT prev_refresh_hash, rotated_at FROM {$p}mobile_session WHERE id = %d", (int) $tokensA['session_id']));
$check($sessRow->prev_refresh_hash === hash('sha256', (string) $tokensA['refresh_token']), 'vorheriges Token bleibt das zuletzt rotierte (zweite verlorene Antwort ebenfalls abgefangen)');
$third = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$check($third['status'] === 200 && $api('GET', '/me', [], (string) $again['access_token'])['status'] === 401 && $api('GET', '/me', [], (string) $third['body']['data']['access_token'])['status'] === 200, 'erneute Wiederholung im Fenster: wieder neues Paar, gültig ist nur das zuletzt ausgegebene');
$new = $third['body']['data'];
$wpdb->update($p.'mobile_session', ['rotated_at' => gmdate('Y-m-d H:i:s', time() - 61)], ['id' => (int) $tokensA['session_id']]);
$r = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 401 && $code($r) === 'REFRESH_TOKEN_REUSED', 'Wiederverwendung nach dem Kulanzfenster (60 s) → 401 REFRESH_TOKEN_REUSED');
$check($api('GET', '/me', [], (string) $new['access_token'])['status'] === 401 && $api('POST', '/auth/refresh', ['refresh_token' => $new['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 401, 'Wiederverwendung widerruft die ganze Sitzung');
// Normale Rotation nach einer Kulanz-Rotation: Kette bleibt konsistent.
$ln = $login('mobile_kundin_a@example.test', $passA, 'Kette');
$t1 = $api('POST', '/auth/refresh', ['refresh_token' => $ln['body']['data']['refresh_token']], null, ['X-Tenant-Slug' => null])['body']['data'] ?? [];
$t1b = $api('POST', '/auth/refresh', ['refresh_token' => $ln['body']['data']['refresh_token']], null, ['X-Tenant-Slug' => null])['body']['data'] ?? [];
$t2 = $api('POST', '/auth/refresh', ['refresh_token' => (string) ($t1b['refresh_token'] ?? '')], null, ['X-Tenant-Slug' => null]);
$check($t2['status'] === 200 && $api('POST', '/auth/refresh', ['refresh_token' => (string) $t1b['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 200 && $api('POST', '/auth/refresh', ['refresh_token' => (string) $ln['body']['data']['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 401, 'nach Kulanz-Rotation normal weiter rotieren; vorletztes Token gilt danach nicht mehr');
\Bootspruefung\Learning\Mobile\TokenAuth::revoke((int) $ln['body']['data']['session_id'], 'test');
$l = $login('mobile_kundin_a@example.test', $passA, 'Neues Gerät');
$tok = (string) $l['body']['data']['access_token'];
$r = $api('POST', '/auth/logout', [], $tok, ['X-Tenant-Slug' => null]);
$check($r['status'] === 204 && $api('GET', '/me', [], $tok)['status'] === 401, 'Abmeldung widerruft die Sitzung');
$wpdb->update($p.'mobile_session', ['refresh_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => (int) $l['body']['data']['session_id']]);
$check($api('POST', '/auth/refresh', ['refresh_token' => $l['body']['data']['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 401, 'abgelaufenes Refresh-Token → 401');

echo "Passwort\n";
$l = $login('mobile_kundin_a@example.test', $passA, 'Gerät vor Passwortänderung');
$tok = (string) $l['body']['data']['access_token'];
$r = $api('POST', '/auth/password/forgot', ['email' => 'niemand@example.test'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 202, 'Passwort vergessen: neutral 202 (auch ohne Konto)');

echo "Universal Links / App Links (Reset-Link)\n";
$check(AppLinks::wellKnown('/.well-known/apple-app-site-association')['status'] === 404 && AppLinks::wellKnown('/.well-known/assetlinks.json')['status'] === 404 && ! AppLinks::configured(), 'ohne Einträge: .well-known-Dateien → 404 (keine Weiterleitung)');
$mails = [];
$capture = static function ($return, $atts) use (&$mails) {
    $mails[] = (string) ($atts['message'] ?? '');

    return true; // nicht wirklich senden
};
add_filter('pre_wp_mail', $capture, 10, 2);
$api('POST', '/auth/password/forgot', ['email' => 'mobile_kundin_a@example.test'], null, ['X-Tenant-Slug' => null]);
$check(count($mails) === 1 && str_contains($mails[0], 'wp-login.php') && ! str_contains($mails[0], '/app/passwort/'), 'ohne App-Kennungen: Reset-Mail aus der App mit Web-Link (unverändert)');
$hexPrint = strtolower(str_repeat('ab', 16).str_repeat('0f', 16));
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => "ABCDE12345.at.example.bootsschule\nkaputt\nabcde12345.klein", 'android_package' => 'at.example.bootsschule', 'android_cert_fingerprints' => $hexPrint."\nZZ:00"]));
$check(MobileSettings::iosAppIds() === ['ABCDE12345.at.example.bootsschule'] && MobileSettings::androidFingerprints() === [implode(':', str_split(strtoupper($hexPrint), 2))], 'Einstellungen geprüft und normalisiert (ungültige Einträge verworfen)');
$aasa = AppLinks::wellKnown('/.well-known/apple-app-site-association');
$aj = json_decode((string) ($aasa['body'] ?? ''), true);
$check($aasa !== null && $aasa['status'] === 200 && $aasa['content_type'] === 'application/json' && ($aj['applinks']['details'][0]['appIDs'] ?? null) === ['ABCDE12345.at.example.bootsschule'] && ($aj['applinks']['details'][0]['components'][0]['/'] ?? '') === AppLinks::resetPathPattern() && str_ends_with(AppLinks::resetPathPattern(), '/app/passwort/*'), 'apple-app-site-association: JSON mit appIDs und nur dem App-Link-Pfad');
$al = AppLinks::wellKnown('/.well-known/assetlinks.json');
$alj = json_decode((string) ($al['body'] ?? ''), true);
$check($al !== null && $al['status'] === 200 && ($alj[0]['relation'][0] ?? '') === 'delegate_permission/common.handle_all_urls' && ($alj[0]['target']['package_name'] ?? '') === 'at.example.bootsschule' && ($alj[0]['target']['sha256_cert_fingerprints'][0] ?? '') === implode(':', str_split(strtoupper($hexPrint), 2)), 'assetlinks.json: Paketname und SHA-256-Fingerabdruck');
$check(AppLinks::wellKnown('/.well-known/security.txt') === null && AppLinks::wellKnown('/wp-json/') === null, 'andere Adressen unberührt');
$api('POST', '/auth/password/forgot', ['email' => 'mobile_kundin_a@example.test'], null, ['X-Tenant-Slug' => null]);
$appMail = $mails[1] ?? '';
$linkOk = preg_match('#'.preg_quote(AppLinks::resetUrl(), '#').'\?key=([A-Za-z0-9]+)&login=([^&\s]+)#', $appMail, $lm) === 1;
$check($linkOk && ! str_contains($appMail, 'wp-login.php?') && rawurldecode($lm[2]) === 'mobile_kundin_a', 'Reset-Mail aus der App: Link auf /app/passwort/?key=…&login=… (öffnet die App)');
$check($linkOk && check_password_reset_key($lm[1], 'mobile_kundin_a') instanceof WP_User, 'Schlüssel im App-Link ist ein gültiger WordPress-Reset-Schlüssel (für POST /auth/password/reset)');
$r = $api('POST', '/auth/password/reset', ['login' => 'mobile_kundin_a', 'email' => 'mobile_kunde_b@example.test', 'token' => (string) ($lm[1] ?? 'x'), 'password' => 'App-Link-Passwort-1', 'password_confirmation' => 'App-Link-Passwort-1'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'PASSWORD_RESET_INVALID_OR_EXPIRED', 'Reset mit login und nicht passender E-Mail → 422');
$r = $api('POST', '/auth/password/reset', ['token' => 'x', 'password' => 'App-Link-Passwort-1', 'password_confirmation' => 'App-Link-Passwort-1'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422, 'Reset ohne login und email → 422');
$r = $api('POST', '/auth/password/reset', ['login' => 'mobile_kundin_a', 'token' => (string) ($lm[1] ?? 'x'), 'password' => 'App-Link-Passwort-1', 'password_confirmation' => 'App-Link-Passwort-1'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 204 && $login('mobile_kundin_a@example.test', 'App-Link-Passwort-1')['status'] === 200, 'Reset mit Schlüssel und login aus dem App-Link → neues Passwort gilt');
$passA = 'App-Link-Passwort-1';
$tok = (string) $login('mobile_kundin_a@example.test', $passA, 'Gerät vor Passwortänderung')['body']['data']['access_token'];
$mails = [];
retrieve_password('mobile_kundin_a');
$webMail = $mails[0] ?? '';
$check(str_contains($webMail, network_site_url('wp-login.php', 'login').'?') && str_contains($webMail, 'action=rp') && ! str_contains($webMail, '/app/passwort/'), 'Reset über die Website: Link unverändert (wp-login.php?action=rp)');
remove_filter('pre_wp_mail', $capture, 10);
$to = AppLinks::resetRedirect(['key' => 'Abc123XYZ', 'login' => 'mobile_kundin_a', 'wp_lang' => 'de_DE']);
$check($to === network_site_url('wp-login.php?action=rp&key=Abc123XYZ&login=mobile_kundin_a', 'login').'&wp_lang=de_DE', 'ohne App: /app/passwort/ leitet auf die WordPress-Seite zum Passwortsetzen weiter');
$check(AppLinks::resetRedirect([]) === wp_lostpassword_url() && AppLinks::resetRedirect(['key' => '<script>', 'login' => 'x']) === wp_lostpassword_url(), 'ohne/mit ungültigem Schlüssel → „Passwort vergessen“');
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => '', 'android_package' => 'at.example.bootsschule', 'android_cert_fingerprints' => '']));
$check(AppLinks::wellKnown('/.well-known/assetlinks.json')['status'] === 404 && AppLinks::wellKnown('/.well-known/apple-app-site-association')['status'] === 404, 'Android ohne Fingerabdruck: nicht ausgeliefert (404)');
MobileSettings::save(array_merge(MobileSettings::all(), ['android_package' => '']));
$r = $api('POST', '/auth/password/reset', ['email' => 'mobile_kundin_a@example.test', 'token' => 'ungueltigerSchluessel12345', 'password' => 'Neues-Passwort-987', 'password_confirmation' => 'Neues-Passwort-987'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'PASSWORD_RESET_INVALID_OR_EXPIRED', 'ungültiger Reset-Schlüssel → 422');
$resetKey = get_password_reset_key(get_userdata($alice));
$r = $api('POST', '/auth/password/reset', ['email' => 'mobile_kundin_a@example.test', 'token' => (string) $resetKey, 'password' => 'kurz', 'password_confirmation' => 'kurz'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && isset($r['body']['errors']['password']), 'zu kurzes Passwort → 422');
$passA = 'Neues-Passwort-987';
$r = $api('POST', '/auth/password/reset', ['email' => 'mobile_kundin_a@example.test', 'token' => (string) $resetKey, 'password' => $passA, 'password_confirmation' => $passA], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 204 && $api('GET', '/me', [], $tok)['status'] === 401, 'Reset über WordPress-Mechanismus widerruft alle App-Sitzungen');
$l = $login('mobile_kundin_a@example.test', $passA, 'Nach Reset');
$check($l['status'] === 200, 'Anmeldung mit neuem Passwort');
$tok = (string) $l['body']['data']['access_token'];
wp_set_password('Noch-ein-Passwort-555', $alice);
$check($api('GET', '/me', [], $tok)['status'] === 401, 'Passwortänderung (wp_set_password) widerruft App-Sitzungen');
$passA = 'Noch-ein-Passwort-555';

echo "Kontolöschung: Link öffnet die App, Bestätigung in der App\n";
$sent = [];
$capture2 = static function ($return, $atts) use (&$sent) {
    $sent[] = ['to' => is_array($atts['to'] ?? null) ? implode(',', $atts['to']) : (string) ($atts['to'] ?? ''), 'subject' => (string) ($atts['subject'] ?? ''), 'message' => (string) ($atts['message'] ?? '')];

    return true;
};
add_filter('pre_wp_mail', $capture2, 10, 2);
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => 'ABCDE12345.at.example.bootsschule']));
$aj = json_decode((string) (AppLinks::wellKnown('/.well-known/apple-app-site-association')['body'] ?? ''), true);
$paths = array_column($aj['applinks']['details'][0]['components'] ?? [], '/');
$check(in_array(AppLinks::deletionPathPattern(), $paths, true) && in_array(AppLinks::resetPathPattern(), $paths, true) && str_ends_with(AppLinks::deletionPathPattern(), '/app/konto-loeschen/*'), 'apple-app-site-association enthält /app/konto-loeschen/* (und weiterhin /app/passwort/*)');
$bd = $api('POST', '/me/deletion-request', [], $tokenB, ['X-Tenant-Slug' => null]);
$bobReq = (int) ($bd['body']['data']['request_id'] ?? 0);
$delMail = $sent[0]['message'] ?? '';
$hasLink = preg_match('#'.preg_quote(AppLinks::deletionUrl(), '#').'\?request_id=(\d+)&confirm_key=([A-Za-z0-9]+)#', $delMail, $dm) === 1;
$check($bd['status'] === 202 && $bobReq > 0 && $hasLink && (int) $dm[1] === $bobReq && ! str_contains($delMail, 'action=confirmaction'), 'Löschantrag aus der App: Mail-Link auf /app/konto-loeschen/?request_id=…&confirm_key=… (öffnet die App)');
$confirmKey = $hasLink ? $dm[2] : 'x';
$check(AppLinks::deletionRedirect(['request_id' => (string) $bobReq, 'confirm_key' => $confirmKey]) === add_query_arg(['action' => 'confirmaction', 'request_id' => $bobReq, 'confirm_key' => $confirmKey], wp_login_url()), 'ohne App: /app/konto-loeschen/ leitet auf die WordPress-Bestätigung (wp-login.php?action=confirmaction…) weiter');
$check(AppLinks::deletionRedirect([]) === home_url('/') && AppLinks::deletionRedirect(['request_id' => '1x', 'confirm_key' => 'abc']) === home_url('/') && AppLinks::deletionRedirect(['request_id' => '5', 'confirm_key' => '<script>']) === home_url('/'), 'ungültige Werte → Startseite');
$sent = [];
$webReq = wp_create_user_request('mobile_kundin_c@example.test', 'remove_personal_data', [], 'pending');
wp_send_user_request((int) $webReq);
$check(str_contains($sent[0]['message'] ?? '', 'action=confirmaction') && ! str_contains($sent[0]['message'] ?? '', '/app/konto-loeschen/'), 'Löschantrag über die Website/Verwaltung: Mail-Link unverändert');
wp_delete_post((int) $webReq, true);
$sent = [];
$r = $api('POST', '/me/deletion-request/confirm', ['request_id' => (string) $bobReq, 'confirm_key' => 'falscherSchluessel123'], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'DELETION_CONFIRMATION_INVALID' && get_post_status($bobReq) === 'request-pending' && $sent === [], 'falscher Schlüssel → 422 DELETION_CONFIRMATION_INVALID, Antrag bleibt offen');
$r = $api('POST', '/me/deletion-request/confirm', ['request_id' => 'abc', 'confirm_key' => $confirmKey], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'DELETION_CONFIRMATION_INVALID', 'ungültige request_id → 422');
$exportReq = wp_create_user_request('mobile_kunde_b@example.test', 'export_personal_data', [], 'pending');
$exportKey = wp_generate_user_request_key((int) $exportReq);
$r = $api('POST', '/me/deletion-request/confirm', ['request_id' => (string) $exportReq, 'confirm_key' => $exportKey], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && get_post_status((int) $exportReq) === 'request-pending', 'gültiger Schlüssel eines Export-Antrags → 422 (nur Löschanträge)');
wp_delete_post((int) $exportReq, true);
$r = $api('POST', '/me/deletion-request/confirm', ['request_id' => (string) $bobReq, 'confirm_key' => $confirmKey], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data']['status'] === 'confirmed' && $r['body']['data']['request_id'] === (string) $bobReq && get_post_status($bobReq) === 'request-confirmed' && (int) get_post_meta($bobReq, '_wp_user_request_confirmed_timestamp', true) > 0, 'Bestätigung in der App ohne Anmeldung (wie wp-login.php?action=confirmaction) → Antrag bestätigt');
$adminMail = array_values(array_filter($sent, static fn ($m) => str_contains($m['to'], (string) get_option('admin_email'))));
$check(count($adminMail) === 1 && (bool) get_post_meta($bobReq, '_wp_admin_notified', true), 'Verwaltung benachrichtigt (wie WordPress nach der Bestätigung)');
$r = $api('POST', '/me/deletion-request/confirm', ['request_id' => (string) $bobReq, 'confirm_key' => $confirmKey], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 422 && $code($r) === 'DELETION_CONFIRMATION_INVALID' && count($sent) === 1, 'Link erneut benutzt → 422, keine zweite Benachrichtigung');
$bd2 = $api('POST', '/me/deletion-request', [], $tokenB, ['X-Tenant-Slug' => null]);
$check($bd2['status'] === 202 && $bd2['body']['data']['status'] === 'confirmed' && $bd2['body']['data']['already_requested'] === true, 'erneuter Antrag zeigt den bestätigten Antrag');
remove_filter('pre_wp_mail', $capture2, 10);
MobileSettings::save(array_merge(MobileSettings::all(), ['ios_app_ids' => '']));
wp_delete_post($bobReq, true);

echo "Rate-Limit\n";
for ($i = 0; $i < 10; $i++) {
    $login('fremd'.$i.'@example.test', 'falsch');
}
$ipFails = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '192.0.2.10');
$check($ipFails['status'] === 429 && $code($ipFails) === 'RATE_LIMITED' && (int) $ipFails['body']['retry_after'] > 0 && isset($ipFails['headers']['Retry-After']), '10 Fehlversuche je IP → 429 mit Retry-After');
$other = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '192.0.2.77');
$check($other['status'] === 200, 'andere IP nicht betroffen');
$RL = \Bootspruefung\Learning\Mobile\RateLimiter::class;
$ipFor = static function (string $remote, ?string $xff) use ($RL): string {
    $_SERVER['REMOTE_ADDR'] = $remote;
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    if ($xff !== null) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    }

    return $RL::clientIp();
};
// Standard: keinem Proxy vertrauen – auch nicht Loopback/privaten Netzen.
$check(MobileSettings::trustedProxies() === [] && $ipFor('127.0.0.1', '198.51.100.23') === '127.0.0.1' && $ipFor('10.0.0.5', '198.51.100.23') === '10.0.0.5', 'ohne eingetragene Proxys zählt nur REMOTE_ADDR (X-Forwarded-For von 127.0.0.1/10.x ignoriert)');
$spoofed = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null, 'X-Forwarded-For' => '192.0.2.77'], '192.0.2.10');
$check($spoofed['status'] === 429, 'X-Forwarded-For von nicht vertrauenswürdiger Gegenstelle zählt nicht (gesperrte IP bleibt gesperrt)');
MobileSettings::save(array_merge(MobileSettings::all(), ['trusted_proxies' => "127.0.0.1\n172.16.0.0/12\nkaputt"]));
$check(MobileSettings::trustedProxies() === ['127.0.0.1', '172.16.0.0/12'], 'Einstellung „Vertrauenswürdige Proxys“: IPs/CIDRs, Ungültiges verworfen');
$check($ipFor('127.0.0.1', '198.51.100.23') === '198.51.100.23' && $ipFor('172.18.0.3', '6.6.6.6, 198.51.100.23') === '198.51.100.23' && $ipFor('172.18.0.3', '198.51.100.23, 172.20.0.9') === '198.51.100.23', 'eingetragener Proxy: Client-IP aus X-Forwarded-For, rechter nicht vertrauenswürdiger Eintrag (gefälschte linke zählen nicht)');
$check($ipFor('192.0.2.200', '198.51.100.23') === '192.0.2.200', 'nicht eingetragene Gegenstelle: X-Forwarded-For ignoriert');
$viaProxy = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null, 'X-Forwarded-For' => '198.51.100.23'], '127.0.0.1');
$check($viaProxy['status'] === 200, 'hinter eingetragenem Proxy: Client-IP aus X-Forwarded-For');
$viaProxy = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null, 'X-Forwarded-For' => '192.0.2.10'], '127.0.0.1');
$check($viaProxy['status'] === 429, 'hinter eingetragenem Proxy: gesperrte Client-IP bleibt gesperrt');
MobileSettings::save(array_merge(MobileSettings::all(), ['trusted_proxies' => '']));
$proxyFilter = static fn (): array => ['10.0.0.0/8'];
add_filter('bootspruefung_mobile_trusted_proxies', $proxyFilter);
$check($ipFor('10.1.2.3', '198.51.100.24') === '198.51.100.24', 'Filter bootspruefung_mobile_trusted_proxies');
remove_filter('bootspruefung_mobile_trusted_proxies', $proxyFilter);
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
// Kein Aussperren fremder Konten: 5 Fehlversuche zählen je E-Mail + IP.
for ($i = 0; $i < 5; $i++) {
    $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'falsch', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.10');
}
$r = $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'Mobil-Passwort-456!', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.10');
$check($r['status'] === 429 && $code($r) === 'RATE_LIMITED', '5 Fehlversuche je E-Mail + IP → 429 von dieser IP');
$r = $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'Mobil-Passwort-456!', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.99');
$check($r['status'] === 200, 'Konto von anderer IP weiter nutzbar (Angreifer kann fremdes Konto nicht mit 5 Fehlversuchen sperren)');
for ($round = 0; $round < 2; $round++) {
    for ($i = 0; $i < 4; $i++) {
        $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'falsch', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.50');
    }
    $r = $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'Mobil-Passwort-456!', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.50');
}
$check($r['status'] === 200, 'erfolgreiche Anmeldung setzt den Zähler E-Mail + IP zurück (2 × 4 Fehlversuche)');
for ($i = 0; $i < \Bootspruefung\Learning\Mobile\Controllers\AuthController::LOGIN_FAILS_PER_EMAIL; $i++) {
    $api('POST', '/auth/login', ['email' => 'mobile_kundin_c@example.test', 'password' => 'falsch', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '198.18.0.'.(10 + $i));
}
$r = $api('POST', '/auth/login', ['email' => 'mobile_kundin_c@example.test', 'password' => $carolPass, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '198.18.1.1');
$check($r['status'] === 429, 'je E-Mail insgesamt höhere Grenze ('.\Bootspruefung\Learning\Mobile\Controllers\AuthController::LOGIN_FAILS_PER_EMAIL.' verteilte Fehlversuche) → 429');
// Zähler atomar in der Datenbank (kein Transient), Name nur als Hash.
$check($RL::increment('test-atomar|x', 60) === 1 && $RL::increment('test-atomar|x', 60) === 2 && $RL::increment('test-atomar|y', 60) === 1, 'Zähler: INSERT … ON DUPLICATE KEY UPDATE liefert den neuen Stand');
$check(! $wpdb->get_var("SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bp\\_mobile\\_rl\\_%' LIMIT 1") && (int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$p}mobile_rate_limit WHERE bucket_hash = %s", hash('sha256', 'test-atomar|x|'.wp_salt('nonce')))) === 2, 'Zähler in bp_mobile_rate_limit (gehasht), keine Transients');
$wpdb->update($p.'mobile_rate_limit', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['bucket_hash' => hash('sha256', 'test-atomar|x|'.wp_salt('nonce'))]);
\Bootspruefung\Learning\Mobile\MobileApi::cleanup();
$check(! $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}mobile_rate_limit WHERE bucket_hash = %s", hash('sha256', 'test-atomar|x|'.wp_salt('nonce')))), 'abgelaufene Zählerfenster löscht der stündliche Cron');

echo "Abschalten\n";
MobileSettings::save(['enabled' => false]);
$r = $api('GET', '/me', [], (string) $other['body']['data']['access_token'], ['X-Tenant-Slug' => null]);
$check($r['status'] === 503 && $code($r) === 'MOBILE_API_DISABLED', 'App abgeschaltet → 503');
$check($api('GET', '/health')['body']['data']['status'] === 'disabled', 'Health meldet abgeschaltet');
MobileSettings::save(['enabled' => true]);

echo "Aufräumen\n";
wp_set_current_user(1);
if ($orderId > 0) {
    wc_get_order($orderId)->delete(true);
}
if ($productId > 0) {
    wc_get_product($productId)->delete(true);
}
if ($couponId > 0) {
    wp_delete_post($couponId, true);
}
foreach (get_posts(['post_type' => 'user_request', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'title' => 'mobile_kundin_a@example.test']) as $rid) {
    wp_delete_post((int) $rid, true);
}
$erased = \Bootspruefung\Learning\Privacy\PersonalData::erase('mobile_kundin_a@example.test');
$check($erased['items_removed'] === true && get_user_meta($alice, 'bp_app_settings', true) === '' && \Bootspruefung\Learning\Mobile\AppSettings::get($alice)['version'] === 0, 'Datenschutz-Löschung entfernt die App-Einstellungen');
wp_delete_user($alice);
wp_delete_user($bob);
wp_delete_user($carol);
wp_delete_user($dora);
foreach ($staff as $staffId) {
    wp_delete_user($staffId);
}
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$alice}, {$bob}, {$dora})");
$wpdb->query("DELETE FROM {$p}radio_progress WHERE user_id IN ({$alice}, {$bob})");
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mobile_session WHERE user_id IN ({$alice}, {$bob})") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mobile_idempotency WHERE user_id IN ({$alice}, {$bob})") === 0, 'Konto gelöscht: App-Sitzungen und Idempotenz-Daten entfernt');
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
update_option(Settings::OPTION, $settingsBefore);
$mobileBefore === false ? delete_option(MobileSettings::OPTION) : update_option(MobileSettings::OPTION, $mobileBefore);
unset($_SERVER['HTTP_AUTHORIZATION']);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
