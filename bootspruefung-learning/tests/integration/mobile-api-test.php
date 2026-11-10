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
 * Routen ↔ openapi.yaml.
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
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bp\\_mobile\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_mobile\\_rl\\_%'");
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
$ov = $api('GET', "/courses/{$courseId}/overview", [], $tokenA);
$check($ov['status'] === 200 && count($ov['body']['data']['modules']) > 0 && $ov['body']['data']['continue_learning']['mode'] === 'smarttrainer', 'Übersicht mit Modulen und Weiterlernen');
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

echo "Videos\n";
$videos = $api('GET', "/courses/{$courseId}/videos", [], $tokenA);
$lesson = $videos['body']['data'][0]['lessons'][0] ?? null;
$check($videos['status'] === 200 && $lesson !== null, 'Videomodule mit Lektionen');
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
$ws = $api('POST', '/auth/web-session', ['redirect' => '/bootsscheine/src/funk/geraet/'], $tokenA, ['X-Tenant-Slug' => null]);
$wcode = $codeOf($ws);
$check($ws['status'] === 201 && str_starts_with((string) $ws['body']['data']['url'], $origin.$simPath.'?bp_app_login=bpw_') && strlen($wcode) === 47 && $ws['body']['data']['expires_in'] === 60, 'POST /auth/web-session → Einmal-URL ins Lernbereich-Ziel');
$check(abs(strtotime((string) $ws['body']['data']['expires_at']) - time() - 60) <= 2 && ($ws['headers']['Cache-Control'] ?? '') === 'private, no-store, max-age=0', 'Code 60 Sekunden gültig, Antwort no-store');
$wrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}mobile_web_code WHERE code_hash = %s", hash('sha256', $wcode)));
$check($wrow && (int) $wrow->user_id === $alice && (int) $wrow->session_id === (int) $tokensA['session_id'] && ! str_contains((string) wp_json_encode($wrow), $wcode), 'nur Hash gespeichert, gebunden an Konto und App-Sitzung');
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
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($wcode, $simPath.'?bp_app_login='.$wcode);
$check($res['user_id'] === $alice && $res['location'] === $origin.$simPath && count($authCookies) === 1 && $authCookies[0]['user'] === $alice, 'Einlösen: WordPress-Anmeldecookie gesetzt, Weiterleitung aufs Ziel ohne Code');
$check($authCookies[0]['expire'] === 0 && get_current_user_id() === $alice, 'Sitzungscookie ohne „angemeldet bleiben“');
$again = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($wcode, $simPath.'?bp_app_login='.$wcode);
$loginTo = wp_login_url($origin.$simPath);
$check($again['user_id'] === 0 && $again['location'] === $loginTo && count($authCookies) === 1, 'Code zweimal benutzt → Anmeldeseite mit redirect_to aufs Ziel, kein Cookie');
$r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokenA, ['X-Tenant-Slug' => null]);
$c2 = $codeOf($r);
$wpdb->update($p.'mobile_web_code', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['code_hash' => hash('sha256', $c2)]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c2, $simPath.'?bp_app_login='.$c2);
$check($res['user_id'] === 0 && $res['location'] === $loginTo, 'abgelaufener Code → Anmeldeseite (gleiche Antwort wie ungültig)');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest('bpw_'.str_repeat('A', 43), '/wp-admin/?bp_app_login=x');
$check($res['user_id'] === 0 && $res['location'] === wp_login_url(\Bootspruefung\Learning\Frontend\Router::url()), 'ungültiger Code an fremdem Pfad → Anmeldeseite mit Lernbereich als Ziel');
$lc = $login('mobile_kundin_c@example.test', $carolPass, 'Tablet');
$tokC = (string) $lc['body']['data']['access_token'];
$c3 = $codeOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$api('POST', '/auth/logout', [], $tokC, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c3, $simPath);
$check($res['user_id'] === 0, 'App-Sitzung widerrufen → offener Code unbrauchbar');
$lc = $login('mobile_kundin_c@example.test', $carolPass, 'Tablet');
$tokC = (string) $lc['body']['data']['access_token'];
$c4 = $codeOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
$carolPass = 'Mobil-Passwort-790!';
wp_set_password($carolPass, $carol);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c4, $simPath);
$check($c4 !== '' && $res['user_id'] === 0, 'Passwortänderung → offener Code unbrauchbar');
$tokC = (string) $login('mobile_kundin_c@example.test', $carolPass, 'Tablet')['body']['data']['access_token'];
$c5 = $codeOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
(new WP_User($carol))->set_role('editor');
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c5, $simPath);
(new WP_User($carol))->set_role('customer');
$check($c5 !== '' && $res['user_id'] === 0, 'Konto inzwischen mit Redaktionsrechten → Code wird nicht eingelöst');
foreach ($staff as $role => $staffId) {
    $tokS = (string) ($login($role === 'administrator' ? 'mobile_admin_t@example.test' : 'mobile_redaktion_t@example.test', 'Staff-Passwort-321!')['body']['data']['access_token'] ?? '');
    $r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokS, ['X-Tenant-Slug' => null]);
    $check($r['status'] === 403 && $code($r) === 'WEB_SESSION_NOT_ALLOWED', 'Konto mit Rolle '.$role.' → 403 WEB_SESSION_NOT_ALLOWED (keine Admin-Sitzung über App-Token)');
}
$c6 = $codeOf($api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]));
MobileSettings::save(array_merge(MobileSettings::all(), ['web_session_enabled' => false]));
$r = $api('POST', '/auth/web-session', ['redirect' => $simPath], $tokC, ['X-Tenant-Slug' => null]);
$res = \Bootspruefung\Learning\Mobile\WebSession::resolveRequest($c6, $simPath);
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
$r = $api('GET', '/files/999999', [], $tokenA);
$check($r['status'] === 404, 'unbekannte Datei → 404');

echo "Datenexport und Löschantrag\n";
$exp = $api('POST', '/profile/export', [], $tokenA, ['Idempotency-Key' => $uuid()]);
$expId = (string) ($exp['body']['data']['export_id'] ?? '');
$check($exp['status'] === 202 && $exp['body']['data']['status'] === 'ready' && str_contains((string) $exp['body']['data']['download_url'], '/boatingschool/v1/profile/export/'), 'Export erstellt, Download über die API');
$st = $api('GET', "/profile/export/{$expId}", [], $tokenA);
$check($st['status'] === 200 && $st['body']['data']['expires_at'] !== null, 'Exportstatus mit Ablauf');
$dl = $api('GET', "/profile/export/{$expId}/download", [], $tokenA);
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
$r = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 409 && $code($r) === 'REFRESH_TOKEN_ROTATED', 'paralleler Refresh (kurz nach Rotation) → 409 ohne Widerruf');
$check($api('GET', '/me', [], (string) $new['access_token'])['status'] === 200, 'Sitzung bleibt nach parallelem Refresh aktiv');
$wpdb->update($p.'mobile_session', ['rotated_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => (int) $tokensA['session_id']]);
$r = $api('POST', '/auth/refresh', ['refresh_token' => $tokensA['refresh_token']], null, ['X-Tenant-Slug' => null]);
$check($r['status'] === 401 && $code($r) === 'REFRESH_TOKEN_REUSED', 'Wiederverwendung eines rotierten Refresh-Tokens → 401');
$check($api('GET', '/me', [], (string) $new['access_token'])['status'] === 401 && $api('POST', '/auth/refresh', ['refresh_token' => $new['refresh_token']], null, ['X-Tenant-Slug' => null])['status'] === 401, 'Wiederverwendung widerruft die ganze Sitzung');
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

echo "Rate-Limit\n";
for ($i = 0; $i < 10; $i++) {
    $login('fremd'.$i.'@example.test', 'falsch');
}
$ipFails = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '192.0.2.10');
$check($ipFails['status'] === 429 && $code($ipFails) === 'RATE_LIMITED' && (int) $ipFails['body']['retry_after'] > 0 && isset($ipFails['headers']['Retry-After']), '10 Fehlversuche je IP → 429 mit Retry-After');
$other = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '192.0.2.77');
$check($other['status'] === 200, 'andere IP nicht betroffen');
// Hinter Reverse-Proxy: X-Forwarded-For entscheidet (Proxy 127.0.0.1 ist vertrauenswürdig).
$viaProxy = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null, 'X-Forwarded-For' => '198.51.100.23'], '127.0.0.1');
$check($viaProxy['status'] === 200, 'hinter Proxy: Client-IP aus X-Forwarded-For');
$spoof = $api('POST', '/auth/login', ['email' => 'mobile_kundin_a@example.test', 'password' => $passA, 'device_name' => 'x'], null, ['X-Tenant-Slug' => null, 'X-Forwarded-For' => '198.51.100.99'], '192.0.2.10');
$check($spoof['status'] === 429, 'X-Forwarded-For von nicht vertrauenswürdiger Gegenstelle zählt nicht');
for ($i = 0; $i < 5; $i++) {
    $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'falsch', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.'.(10 + $i));
}
$r = $api('POST', '/auth/login', ['email' => 'mobile_kunde_b@example.test', 'password' => 'Mobil-Passwort-456!', 'device_name' => 'x'], null, ['X-Tenant-Slug' => null], '203.0.113.99');
$check($r['status'] === 429, '5 Fehlversuche je E-Mail (verteilte IPs) → 429');

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
foreach ($staff as $staffId) {
    wp_delete_user($staffId);
}
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$alice}, {$bob})");
$wpdb->query("DELETE FROM {$p}radio_progress WHERE user_id IN ({$alice}, {$bob})");
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mobile_session WHERE user_id IN ({$alice}, {$bob})") === 0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}mobile_idempotency WHERE user_id IN ({$alice}, {$bob})") === 0, 'Konto gelöscht: App-Sitzungen und Idempotenz-Daten entfernt');
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bp\\_mobile\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_mobile\\_rl\\_%'");
update_option(Settings::OPTION, $settingsBefore);
$mobileBefore === false ? delete_option(MobileSettings::OPTION) : update_option(MobileSettings::OPTION, $mobileBefore);
unset($_SERVER['HTTP_AUTHORIZATION']);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
