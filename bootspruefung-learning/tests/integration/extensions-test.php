<?php

declare(strict_types=1);

/*
 * Integrationstest der Schnittstellen für Gutschein- und Ticket-Plugins (docs/schnittstellen-erweiterungen.md).
 * Die Plugins werden im Test simuliert (eigene add_filter/do_action):
 *   Gutschein: Aktion bootspruefung_voucher_redeemed (genau eine 0-€-Bestellung, Zugang ab heute, idempotent),
 *              POST /coupons/redeem mit Filter bootspruefung_voucher_redeem, Formular unter „Mein Lernen“,
 *              Filter bootspruefung_user_owns_course, Mitgliederrabatt vs. Shop-Gutschein,
 *              Garantie ohne Gutscheinanteil (_bp_paid_by_voucher) und ohne Garantie für Gutschein-Bestellungen.
 *   Tickets:   bootspruefung_ticket_issued/_redeemed/_cancelled, Kurs-Hub-Feld tickets, GET /me/tickets,
 *              practice_offers (Web und App), Datenschutz-Export/-Löschung, Produkte ohne _bp_money_back.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/extensions-test.php
 *
 * Mit WooCommerce (vorher woocommerce-setup.php) laufen alle Prüfungen; ohne WooCommerce die shopunabhängigen
 * und die Prüfung, dass eine Gutschein-Einlösung kontrolliert nichts anlegt. Voraussetzung: content-flow-test.php
 * (Kurs SKN veröffentlicht). Nur gegen eine Test-Datenbank.
 */

ob_start(); // WooCommerce setzt Session-Cookies; Ausgabe puffern, damit keine Header-Warnungen entstehen
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\MoneyBack;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\WooCommerce\CouponField;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts;
use Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo;
use Bootspruefung\Learning\Extensions\Tickets;
use Bootspruefung\Learning\Extensions\Vouchers;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\Problem;
use Bootspruefung\Learning\Mobile\TokenAuth;
use Bootspruefung\Learning\Privacy\PersonalData;
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
$woo = Integration::active();
echo $woo ? "(mit WooCommerce)\n" : "(ohne WooCommerce)\n";
wp_set_current_user(0);
$settingsBefore = get_option(Settings::OPTION);
$mobileBefore = get_option(MobileSettings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
delete_option(MobileSettings::OPTION);
$tenant = MobileSettings::tenantSlug();
add_filter('pre_wp_mail', '__return_true');

$customer = static function (string $login): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer', 'first_name' => 'Erika', 'last_name' => 'Muster']);
};
$users = [];
foreach (['ext_a', 'ext_b', 'ext_c', 'ext_d', 'ext_e', 'ext_f'] as $login) {
    $users[$login] = $customer($login);
}
['ext_a' => $userA, 'ext_b' => $userB, 'ext_c' => $userC, 'ext_d' => $userD, 'ext_e' => $userE, 'ext_f' => $userF] = $users;
$bucket = static fn (int $uid): string => hash('sha256', 'redeem|'.$uid.'|'.wp_salt('nonce'));
foreach ($users as $uid) {
    $wpdb->delete($p.'mobile_rate_limit', ['bucket_hash' => $bucket($uid)]);
}
$course = (new \Bootspruefung\Learning\Content\Repositories\ContentRepository())->courseByCode('SKN');
if ($course === null) {
    fwrite(STDERR, "Kurs SKN fehlt (content-flow-test.php zuerst).\n");
    exit(2);
}
$courseId = (string) $course->id;
$today = gmdate('Y-m-d');

/** App-Anfrage wie in mobile-api-test.php (Bearer, Tenant, rest_post_dispatch). */
$api = static function (string $method, string $path, array $body = [], ?string $token = null, array $headers = []) use ($tenant): array {
    global $current_user;
    $current_user = null;
    TokenAuth::reset();
    Problem::reset();
    $_SERVER['REQUEST_URI'] = '/wp-json/boatingschool/v1'.$path;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.20';
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    $request = new WP_REST_Request($method, '/boatingschool/v1'.$path);
    if ($body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }
    foreach ($headers + ['X-Tenant-Slug' => $tenant] as $name => $value) {
        if ($value !== null) {
            $request->set_header($name, $value);
        }
    }
    $response = rest_do_request($request);
    $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), rest_get_server(), $request);

    return ['status' => $response->get_status(), 'body' => json_decode((string) wp_json_encode($response->get_data()), true)];
};
/** Web-Lernbereich (Cookie-Sitzung): GET bootspruefung/v1/courses/{code}. */
$hub = static function (int $userId, string $code = 'skn'): array {
    global $current_user;
    $current_user = null;
    TokenAuth::reset();
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_SERVER['REQUEST_URI'] = '/wp-json/bootspruefung/v1/courses/'.$code;
    wp_set_current_user($userId);
    $response = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/'.$code));
    wp_set_current_user(0);

    return ['status' => $response->get_status(), 'body' => json_decode((string) wp_json_encode($response->get_data()), true)];
};
$token = static fn (int $uid): string => (string) TokenAuth::issue($uid, 'Erweiterungstest', 'ios', '1.0.0')['access_token'];
$voucherOrders = static function (string $code) use ($wpdb): int {
    $hash = hash('sha256', $code);
    $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    return (int) ($hpos
        ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s", Vouchers::CODE_META, $hash))
        : $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Vouchers::CODE_META, $hash)));
};
$cleanupOrders = [];
$cleanupProducts = [];

// ------------------------------------------------------------------ Gutschein ohne WooCommerce
if (! $woo) {
    echo "Gutschein ohne WooCommerce\n";
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'voucher_redeem_skipped'");
    do_action('bootspruefung_voucher_redeemed', 'OHNE-SHOP-1', $userA, 123, []);
    $result = Vouchers::redeem('OHNE-SHOP-1', $userA, 123);
    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'voucher_redeem_skipped'");
    $check($result instanceof WP_Error && $result->get_error_code() === 'bp_voucher_no_shop' && $after === $before + 2, 'ohne WooCommerce: nichts angelegt, Vorgang im Audit-Log (voucher_redeem_skipped)');
    $check((new EntitlementService($wpdb))->forUser($userA) === [], 'ohne WooCommerce: kein Zugang angelegt');
    (new EntitlementService($wpdb))->grantManual($userA, 'SKN', [AccessService::QUESTION_BANK], null, 'Erweiterungstest');
}

// ------------------------------------------------------------------ Gutschein mit WooCommerce
$productId = 0;
if ($woo) {
    wp_set_current_user(1);
    $product = new WC_Product_Simple();
    $product->set_name('Erweiterungstest SKN');
    $product->set_regular_price('30');
    $product->set_virtual(true);
    $product->set_status('publish');
    $productId = (int) $product->save();
    $cleanupProducts[] = $productId;
    (new ProductAccessRepository($wpdb))->save($productId, 0, [['course_code' => 'SKN', 'feature_code' => 'question_bank']], 182, 'extend', 'revoke_on_full_refund');
    MoneyBack::setPolicy($productId, 14, 20.0);
    wp_set_current_user(0);

    echo "Gutschein: Aktion bootspruefung_voucher_redeemed\n";
    $code = 'KURS-'.wp_generate_password(10, false);
    do_action('bootspruefung_voucher_redeemed', $code, $userA, $productId, ['voucher_order_id' => 4711]);
    $orderId = (int) Vouchers::orderForHash(hash('sha256', $code));
    $order = wc_get_order($orderId);
    $cleanupOrders[] = $orderId;
    $check($order instanceof WC_Order && $voucherOrders($code) === 1, 'Einlösung legt genau eine Bestellung an');
    $notes = $order ? implode(' | ', array_map(static fn ($n) => $n->content, wc_get_order_notes(['order_id' => $orderId]))) : '';
    $check($order && (float) $order->get_total() === 0.0 && $order->get_status() === 'completed' && (int) $order->get_customer_id() === $userA && $order->get_created_via() === Vouchers::CREATED_VIA, 'interne Bestellung über 0 €, abgeschlossen, für den Nutzer');
    $check(str_contains($notes, 'per Gutschein eingelöst') && $order->get_meta(Vouchers::CODE_META) === hash('sha256', $code) && (int) $order->get_meta(Vouchers::VOUCHER_ORDER_META) === 4711 && ! str_contains((string) wp_json_encode($order->get_meta_data()), $code), 'Notiz „per Gutschein eingelöst“, Meta _bp_voucher_code_hash (nur Hash) und _bp_voucher_order');
    $rows = (new EntitlementService($wpdb))->forOrder($orderId);
    $check(count($rows) === 1 && $rows[0]->status === 'active' && str_starts_with((string) $rows[0]->valid_from, $today) && abs(strtotime($rows[0]->valid_until.' UTC') - strtotime($rows[0]->valid_from.' UTC') - 182 * DAY_IN_SECONDS) < 5, 'Zugang aktiv, Start heute, Laufzeit 182 Tage ab Einlösung');
    $check((new AccessService())->full($userA, 'SKN', AccessService::QUESTION_BANK), 'Fragenkatalog freigeschaltet');
    do_action('bootspruefung_voucher_redeemed', $code, $userA, $productId, []);
    $again = Vouchers::redeem($code, $userA, $productId);
    $check($voucherOrders($code) === 1 && is_array($again) && $again['created'] === false && $again['order_id'] === $orderId, 'zweiter Aufruf mit gleichem Code: nichts Neues (idempotent)');
    $other = Vouchers::redeem($code, $userB, $productId);
    $check($other instanceof WP_Error && $other->get_error_code() === 'bp_voucher_used' && $voucherOrders($code) === 1, 'gleicher Code für ein anderes Konto: abgelehnt, keine Bestellung');
    $check(Vouchers::redeem('X-'.wp_generate_password(8, false), $userB, 999999999) instanceof WP_Error, 'Produkt ohne Lernzugang: abgelehnt');

    echo "Garantie bei Gutschein-Bestellungen\n";
    $s = MoneyBackWoo::status(wc_get_order($orderId));
    $check($s['applies'] === false && $s['reason'] === 'voucher', '0-€-Gutschein-Bestellung: standardmäßig keine Geld-zurück-Garantie');
    add_filter('bootspruefung_voucher_money_back', '__return_true');
    $s = MoneyBackWoo::status(wc_get_order($orderId));
    remove_filter('bootspruefung_voucher_money_back', '__return_true');
    $check($s['applies'] === true, 'Filter bootspruefung_voucher_money_back schaltet die Garantie zu');

    echo "Gutschein: App POST /coupons/redeem\n";
    $tokC = $token($userC);
    $r = $api('POST', '/coupons/redeem', ['code' => 'APP-OHNE-PLUGIN'], $tokC);
    $check($r['status'] === 422 && ($r['body']['code'] ?? '') === 'COUPON_INVALID' && str_contains((string) $r['body']['detail'], 'automatisch freigeschaltet'), 'ohne Gutschein-Plugin: bisheriges Verhalten (COUPON_INVALID)');
    $appCode = 'APP-'.wp_generate_password(10, false);
    $seen = [];
    $plugin = static function ($result, $code, $uid) use ($appCode, $productId, &$seen) {
        $seen[] = [$code, $uid];
        if ($code === $appCode || $code === 'WEB-GUT-1') {
            return ['product_id' => $productId, 'voucher_order_id' => 815];
        }
        if ($code === 'ABGELAUFEN') {
            return new WP_Error('expired', 'Dieser Gutschein ist abgelaufen.');
        }

        return $result;
    };
    add_filter('bootspruefung_voucher_redeem', $plugin, 10, 3);
    $key = wp_generate_uuid4();
    $hits = static fn (int $uid): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits), 0) FROM {$p}mobile_rate_limit WHERE bucket_hash = %s", $bucket($uid)));
    $h0 = $hits($userC);
    $r = $api('POST', '/coupons/redeem', ['code' => $appCode], $tokC, ['Idempotency-Key' => $key]);
    $d = $r['body']['data'] ?? [];
    $check($r['status'] === 200 && ($d['kind'] ?? '') === 'course_voucher' && $d['redeemed'] === true && $d['course_id'] === $courseId && ($d['courses'][0]['code'] ?? '') === 'SKN' && $d['group_name'] === null, 'Kursgutschein über die App: kind course_voucher mit Kurs');
    $check(($d['entitlement']['status'] ?? '') === 'active' && str_starts_with((string) $d['entitlement']['valid_from'], $today) && $d['entitlement']['valid_until'] !== null, 'Antwort mit entitlement (aktiv ab heute)');
    $check($voucherOrders($appCode) === 1 && $seen[0] === [$appCode, $userC] && $hits($userC) === $h0 + 1, 'genau eine Bestellung; Filter erhält Code und Nutzer; ein Versuch gezählt');
    $cleanupOrders[] = (int) Vouchers::orderForHash(hash('sha256', $appCode));
    $r2 = $api('POST', '/coupons/redeem', ['code' => $appCode], $tokC, ['Idempotency-Key' => $key]);
    $check($r2['status'] === 200 && $r2['body'] === $r['body'] && $hits($userC) === $h0 + 1, 'Wiederholung mit gleichem Idempotency-Key: gespeicherte Antwort, kein Versuch gezählt');
    $r3 = $api('POST', '/coupons/redeem', ['code' => $appCode], $tokC);
    $check($r3['status'] === 200 && $voucherOrders($appCode) === 1, 'erneutes Einlösen ohne Key: 200, weiterhin eine Bestellung');
    $r = $api('POST', '/coupons/redeem', ['code' => 'ABGELAUFEN'], $tokC);
    $check($r['status'] === 422 && ($r['body']['code'] ?? '') === 'COUPON_INVALID' && ($r['body']['detail'] ?? '') === 'Dieser Gutschein ist abgelaufen.', 'WP_Error des Gutschein-Plugins → 422 COUPON_INVALID mit dessen Meldung');
    $r = $api('POST', '/coupons/redeem', ['code' => 'KEIN-KURSGUTSCHEIN'], $tokC);
    $check($r['status'] === 422 && ($r['body']['code'] ?? '') === 'COUPON_INVALID' && str_contains((string) $r['body']['detail'], 'automatisch freigeschaltet'), 'Filter liefert null: bisheriges Verhalten');
    $tokF = $token($userF);
    $failed = [];
    add_action('bootspruefung_voucher_redeem_failed', static function ($code, $uid, $err) use (&$failed): void {
        $failed[] = [$code, $uid, $err->get_error_code()];
    }, 10, 3);
    $r = $api('POST', '/coupons/redeem', ['code' => $appCode], $tokF);
    $check($r['status'] === 422 && $failed !== [] && $failed[0][2] === 'bp_voucher_used', 'Code schon von anderem Konto eingelöst: 422, Aktion bootspruefung_voucher_redeem_failed');

    echo "Gutschein: Formular unter „Mein Lernen“\n";
    wp_set_current_user($userD);
    ob_start();
    (new Integration())->renderAccount();
    $html = (string) ob_get_clean();
    $check(str_contains($html, 'Kursgutschein einlösen') && str_contains($html, 'name="action" value="bp_voucher_redeem"') && str_contains($html, '_wpnonce'), 'Formular mit Nonce sichtbar, wenn ein Gutschein-Plugin den Filter belegt');
    $web = Vouchers::submitWeb($userD, 'WEB-GUT-1');
    $webOrder = (int) ($web['order_id'] ?? 0);
    $cleanupOrders[] = $webOrder;
    $check($web['result'] === 'redeemed' && $voucherOrders('WEB-GUT-1') === 1 && (new AccessService())->full($userD, 'SKN', AccessService::QUESTION_BANK), 'Formular-Handler löst ein und schaltet frei');
    $web2 = Vouchers::submitWeb($userD, 'WEB-GUT-1');
    $check($web2['result'] === 'already_redeemed' && $voucherOrders('WEB-GUT-1') === 1, 'zweites Absenden: schon eingelöst, keine neue Bestellung');
    $bad = Vouchers::submitWeb($userD, 'ABGELAUFEN');
    $check($bad['result'] === 'invalid' && $bad['message'] === 'Dieser Gutschein ist abgelaufen.', 'abgelehnter Gutschein: Meldung des Gutschein-Plugins');
    $last = null;
    for ($i = 0; $i < Vouchers::RATE_LIMIT + 1; $i++) {
        $last = Vouchers::submitWeb($userE, 'NIX-'.$i);
    }
    $check($last['result'] === 'rate_limited', 'Rate-Limit wie in der App (10 Versuche je Stunde)');
    remove_filter('bootspruefung_voucher_redeem', $plugin, 10);
    ob_start();
    (new Integration())->renderAccount();
    $html = (string) ob_get_clean();
    $check(! str_contains($html, 'bp_voucher_redeem'), 'ohne Gutschein-Plugin kein Formular');
    wp_set_current_user(0);

    echo "Mitgliederrabatt und Shop-Gutschein\n";
    $discountsBefore = get_option(MemberDiscounts::OPTION, '__none__');
    update_option(MemberDiscounts::OPTION, [['id' => 'ext-test', 'course' => '', 'percent' => 10.0, 'applies' => 'all', 'label' => 'Mitglieder']], false);
    $book = new WC_Product_Simple();
    $book->set_name('Erweiterungstest Buch');
    $book->set_regular_price('20');
    $book->set_status('publish');
    $bookId = (int) $book->save();
    $cleanupProducts[] = $bookId;
    if (function_exists('wc_load_cart')) {
        wc_load_cart();
        wp_set_current_user($userA);
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($bookId);
        $check(apply_filters('bootspruefung_coupon_allow_with_member_discount', true, null, WC()->cart) === false, 'Mitgliederrabatt im Warenkorb: Shop-Gutschein nicht zusätzlich');
        update_option(Vouchers::OPTION_COMBINE, 'yes', false);
        $check(apply_filters('bootspruefung_coupon_allow_with_member_discount', true, null, WC()->cart) === true, 'Option erlaubt die Kombination');
        delete_option(Vouchers::OPTION_COMBINE);
        WC()->cart->empty_cart();
        wp_set_current_user($userB); // kein Mitglied
        WC()->cart->add_to_cart($bookId);
        $check(apply_filters('bootspruefung_coupon_allow_with_member_discount', true, null, WC()->cart) === true, 'ohne Mitgliederrabatt: Gutschein erlaubt');
        WC()->cart->empty_cart();
        wp_set_current_user(0);
    }
    $discountsBefore === '__none__' ? delete_option(MemberDiscounts::OPTION) : update_option(MemberDiscounts::OPTION, $discountsBefore, false);

    echo "Gutscheinfeld in Warenkorb und Kasse (T58)\n";
    $CF = CouponField::class;
    $enableBefore = get_option('woocommerce_enable_coupons', null);
    update_option('woocommerce_enable_coupons', 'yes');
    // Vorhandene Gutscheine der Test-Datenbank vorübergehend als Entwurf, damit „kein Gutschein“ prüfbar ist.
    $parked = array_map('intval', get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']));
    foreach ($parked as $cid) {
        wp_update_post(['ID' => $cid, 'post_status' => 'draft']);
    }
    if ($CF::externalVouchers()) {
        $check(wc_coupons_enabled(), 'Gutschein-Plugin ohne shop_coupon aktiv (z. B. Voucher Captain): Feld bleibt sichtbar');
    } else {
        $check(! $CF::hasActiveCoupon() && ! wc_coupons_enabled(), 'kein Gutschein: Feld aus (klassisch und Block lesen wc_coupons_enabled)');
        $check(wc_get_template_html('checkout/form-coupon.php') === '', 'klassische Kasse: kein „Gutschein einlösen?“');
        $fieldPlugin = static fn ($result, $code) => $code === 'FELD-AUS-1' ? ['product_id' => $productId, 'voucher_order_id' => 0] : $result;
        add_filter('bootspruefung_voucher_redeem', $fieldPlugin, 10, 2);
        $fieldWeb = Vouchers::submitWeb($userB, 'FELD-AUS-1');
        remove_filter('bootspruefung_voucher_redeem', $fieldPlugin, 10);
        $cleanupOrders[] = (int) ($fieldWeb['order_id'] ?? 0);
        $check($fieldWeb['result'] === 'redeemed' && (new AccessService())->full($userB, 'SKN', AccessService::QUESTION_BANK), 'Kursgutschein unter „Mein Lernen“ bleibt einlösbar');
        $shopCoupon = new WC_Coupon();
        $shopCoupon->set_code('t58-feld-'.wp_generate_password(6, false, false));
        $shopCoupon->set_amount(5);
        $shopCouponId = (int) $shopCoupon->save();
        $check($CF::hasActiveCoupon() && wc_coupons_enabled() && wc_get_template_html('checkout/form-coupon.php') !== '', 'veröffentlichter Gutschein: Feld da');
        $shopCoupon = new WC_Coupon($shopCouponId);
        $shopCoupon->set_date_expires(time() - DAY_IN_SECONDS);
        $shopCoupon->save();
        $check(! wc_coupons_enabled(), 'nur abgelaufener Gutschein: Feld aus');
        update_option($CF::OPTION, 'no');
        $check(wc_coupons_enabled(), 'Schalter (Option „no“): Feld immer da');
        delete_option($CF::OPTION);
        add_filter('bootspruefung_hide_coupon_field', '__return_false');
        $check(wc_coupons_enabled(), 'Filter bootspruefung_hide_coupon_field = false: Feld immer da');
        remove_filter('bootspruefung_hide_coupon_field', '__return_false');
        $virtual = static fn ($data) => $data;
        add_filter('woocommerce_get_shop_coupon_data', $virtual);
        $check(wc_coupons_enabled(), 'Gutscheine eines Plugins ohne shop_coupon (woocommerce_get_shop_coupon_data): Feld da');
        remove_filter('woocommerce_get_shop_coupon_data', $virtual);
        require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH.'wp-admin/includes/screen.php';
        $GLOBALS['current_screen'] = WP_Screen::get('edit-shop_coupon');
        $check(is_admin() && wc_coupons_enabled(), 'Admin (Gutscheine verwalten): unverändert an');
        unset($GLOBALS['current_screen']);
        update_option('woocommerce_enable_coupons', 'no');
        update_option($CF::OPTION, 'no');
        $check(! wc_coupons_enabled(), 'WooCommerce-Einstellung „Gutscheine aus“ bleibt maßgeblich');
        delete_option($CF::OPTION);
        wp_delete_post($shopCouponId, true);
    }
    foreach ($parked as $cid) {
        wp_update_post(['ID' => $cid, 'post_status' => 'publish']);
    }
    $enableBefore === null ? delete_option('woocommerce_enable_coupons') : update_option('woocommerce_enable_coupons', $enableBefore);

    echo "Garantie: bezahlter Betrag, Gutscheinanteil, Produkte ohne Garantie\n";
    $ticketProduct = new WC_Product_Simple();
    $ticketProduct->set_name('Praxis-Ticket Test');
    $ticketProduct->set_regular_price('50');
    $ticketProduct->set_virtual(true);
    $ticketProduct->set_status('publish');
    $ticketProductId = (int) $ticketProduct->save();
    $cleanupProducts[] = $ticketProductId;
    $check(MoneyBack::policy($ticketProductId) === null, 'Produkt ohne _bp_money_back: keine Garantie (MoneyBack::policy null)');
    $paid = wc_create_order(['customer_id' => $userB]);
    $paid->add_product(wc_get_product($productId), 1, ['subtotal' => 30, 'total' => 24]); // 6 € Rabatt auf die Position
    $paid->add_product(wc_get_product($ticketProductId), 1, ['subtotal' => 50, 'total' => 50]);
    $paid->set_billing_email('ext_b@example.test');
    $paid->calculate_totals(false);
    $paid->save();
    $paid->payment_complete('ext-test');
    $cleanupOrders[] = (int) $paid->get_id();
    $paid = wc_get_order($paid->get_id());
    $parts = MoneyBackWoo::breakdown($paid);
    $check(abs($parts['amount'] - 24.0) < 0.001 && $parts['cash'] === $parts['amount'] && $parts['excluded'] === ['Praxis-Ticket Test'], 'Garantie erstattet den bezahlten Betrag der Position nach Rabatt (24 €), Ticket-Produkt ausgenommen');
    $paid->update_meta_data(Vouchers::PAID_META, 37);
    $paid->save();
    $parts = MoneyBackWoo::breakdown(wc_get_order($paid->get_id()));
    // 37 € von 74 € per Gutschein → die Hälfte der 24 € als Geld.
    $check(abs($parts['cash'] - 12.0) < 0.001 && abs($parts['voucher'] - 12.0) < 0.001, '_bp_paid_by_voucher: Gutscheinanteil anteilig nicht als Geld (12 € von 24 €)');
    $cashFilter = static fn ($cash, $amount) => 5.0;
    add_filter('bootspruefung_money_back_cash_amount', $cashFilter, 10, 2);
    $parts = MoneyBackWoo::breakdown(wc_get_order($paid->get_id()));
    remove_filter('bootspruefung_money_back_cash_amount', $cashFilter, 10);
    $check(abs($parts['cash'] - 5.0) < 0.001 && abs($parts['voucher'] - 19.0) < 0.001, 'Filter bootspruefung_money_back_cash_amount');
    $ticketOnly = wc_create_order(['customer_id' => $userB]);
    $ticketOnly->add_product(wc_get_product($ticketProductId), 1);
    $ticketOnly->calculate_totals(false);
    $ticketOnly->save();
    $ticketOnly->payment_complete('ext-test-2');
    $cleanupOrders[] = (int) $ticketOnly->get_id();
    $s = MoneyBackWoo::status(wc_get_order($ticketOnly->get_id()));
    $check($s['applies'] === false && $s['reason'] === 'no_policy', 'Bestellung nur mit Ticket-Produkt: keine Garantie (nichts zu setzen)');
}

// ------------------------------------------------------------------ besitzt Kurs
echo "Filter bootspruefung_user_owns_course\n";
$check(apply_filters('bootspruefung_user_owns_course', false, $userA, 'SKN') === true && apply_filters('bootspruefung_user_owns_course', false, $userA, 'skn') === true, 'voller Zugang zum Fragenkatalog: besitzt den Kurs (Code oder Slug)');
$check(apply_filters('bootspruefung_user_owns_course', false, $userE, 'SKN') === false && apply_filters('bootspruefung_user_owns_course', false, 0, 'SKN') === false, 'ohne Zugang bzw. ohne Konto: false');
$check(apply_filters('bootspruefung_user_owns_course', true, $userE, 'SKN') === true, 'Vorgabe true des aufrufenden Plugins bleibt');

// ------------------------------------------------------------------ Tickets
echo "Tickets: Aktionen, Kurs-Hub, App\n";
delete_user_meta($userA, Tickets::META);
delete_user_meta($userA, Tickets::LOOKUP_META);
$ticketId = 'T-'.wp_generate_password(8, false);
do_action('bootspruefung_ticket_issued', $ticketId, 555, $userA, ['course_code' => 'SKN', 'title' => 'Praxisstunde Motorboot', 'units' => 5, 'date' => '2026-12-01', 'location' => 'Hafen Teststadt']);
$stored = get_user_meta($userA, Tickets::META, true);
$check(is_array($stored) && count($stored) === 1 && $stored[0]['id'] === $ticketId && $stored[0]['units_total'] === 5 && $stored[0]['units_left'] === 5 && $stored[0]['status'] === 'issued' && $stored[0]['course_code'] === 'SKN' && $stored[0]['location'] === 'Hafen Teststadt', 'Ticket ausgegeben: gespeichert in _bp_tickets');
do_action('bootspruefung_ticket_issued', $ticketId, 555, $userA, ['course_code' => 'SKN', 'title' => 'Praxisstunde Motorboot', 'units' => 5, 'date' => '2026-12-01', 'location' => 'Hafen Teststadt']);
$check(count(get_user_meta($userA, Tickets::META, true)) === 1, 'erneute Ausgabe derselben Ticket-ID: kein Duplikat');
do_action('bootspruefung_ticket_redeemed', $ticketId, 2, 'scanner-mitarbeiter-7');
$stored = get_user_meta($userA, Tickets::META, true);
$check($stored[0]['units_left'] === 2 && $stored[0]['status'] === 'issued' && ! str_contains((string) wp_json_encode($stored), 'scanner-mitarbeiter-7'), 'Entwertung: 3 von 5 absolviert, Scanner-Kennung nicht gespeichert');
$h = $hub($userA);
$t = $h['body']['tickets'][0] ?? [];
$check($h['status'] === 200 && count($h['body']['tickets']) === 1 && $t['units_done'] === 3 && $t['units_total'] === 5 && $t['title'] === 'Praxisstunde Motorboot', 'Kurs-Hub-Antwort: Feld tickets (3 von 5 Einheiten)');
$hOther = $hub($userA, 'src');
$check($hOther['status'] !== 200 || ($hOther['body']['tickets'] ?? null) === [], 'Kurs-Hub eines anderen Kurses: keine Tickets dieses Kurses');
$js = (string) file_get_contents(dirname(__DIR__, 2).'/assets/build/app.js');
$strings = \Bootspruefung\Learning\Frontend\Strings::all();
$check(str_contains($js, 'ticketsSection(c.tickets)') && str_contains($js, 'practiceSection(c.practice_offers)') && ($strings['hub_ticket_units'] ?? '') === 'Praxis: {done} von {total} Einheiten absolviert' && str_contains($strings['hub_tickets_hint'] ?? '', 'kein amtlicher'), 'Web-App: Abschnitte „Praxis-Tickets“ (Hinweis kein amtlicher Nachweis) und „Praxis in deiner Nähe“');
$tokA = $token($userA);
$r = $api('GET', '/me/tickets', [], $tokA, ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && count($r['body']['data']) === 1 && $r['body']['data'][0]['units_done'] === 3 && $r['body']['data'][0]['status'] === 'issued' && $r['body']['data'][0]['course_code'] === 'SKN', 'GET /me/tickets: gespeicherte Liste');
do_action('bootspruefung_ticket_cancelled', $ticketId, 'Ausfall wegen Sturm');
$r = $api('GET', '/me/tickets', [], $tokA, ['X-Tenant-Slug' => null]);
$check(($r['body']['data'][0]['status'] ?? '') === 'cancelled' && $r['body']['data'][0]['reason'] === 'Ausfall wegen Sturm', 'Storno sichtbar (status cancelled, Grund)');
$check(($hub($userA)['body']['tickets'][0]['status'] ?? '') === 'cancelled', 'Storno im Kurs-Hub');
$r = $api('GET', '/me/tickets', [], $token($userE), ['X-Tenant-Slug' => null]);
$check($r['status'] === 200 && $r['body']['data'] === [], 'anderes Konto: keine Tickets (keine fremden Daten)');
$check($api('GET', '/me/tickets')['status'] === 401, 'ohne Anmeldung → 401');
$live = static fn ($list, $uid) => $uid === $userA ? [['id' => 'LIVE-1', 'course_code' => 'skn', 'title' => 'Live aus dem Ticket-Plugin', 'units_total' => 4, 'units_left' => 4, 'status' => 'issued']] : $list;
add_filter('bootspruefung_tickets_for_user', $live, 10, 2);
$r = $api('GET', '/me/tickets', [], $tokA, ['X-Tenant-Slug' => null]);
$check(count($r['body']['data']) === 1 && $r['body']['data'][0]['id'] === 'LIVE-1' && $r['body']['data'][0]['course_code'] === 'SKN' && $r['body']['data'][0]['units_done'] === 0, 'Filter bootspruefung_tickets_for_user hat Vorrang');
remove_filter('bootspruefung_tickets_for_user', $live, 10);

echo "Praxis in deiner Nähe\n";
$check(($hub($userA)['body']['practice_offers'] ?? null) === [], 'ohne Ticket-Plugin: practice_offers leer (Web)');
$r = $api('GET', '/courses/'.$courseId, [], $tokA);
$check($r['status'] === 200 && ($r['body']['data']['practice_offers'] ?? null) === [], 'ohne Ticket-Plugin: practice_offers leer (App)');
$regions = [];
$offers = static function ($list, $code, $region) use (&$regions) {
    $regions[] = [$code, $region];

    return [
        ['title' => 'Praxistermin Elbe', 'url' => 'https://tickets.example.test/termin/1', 'date' => '2026-12-05', 'location' => 'Hamburg', 'price' => '89 €'],
        ['title' => '', 'url' => 'https://tickets.example.test/leer'],
        ['title' => 'Unsicher', 'url' => 'javascript:alert(1)'],
    ];
};
add_filter('bootspruefung_practice_offers', $offers, 10, 3);
$h = $hub($userA);
$check(count($h['body']['practice_offers']) === 1 && $h['body']['practice_offers'][0]['title'] === 'Praxistermin Elbe' && $h['body']['practice_offers'][0]['url'] === 'https://tickets.example.test/termin/1' && $h['body']['practice_offers'][0]['price'] === '89 €', 'Kurs-Hub: Angebote des Ticket-Plugins (ungültige verworfen)');
$check(($regions[0] ?? null) === ['SKN', null], 'Filter erhält Kurscode und Region null (kein gespeichertes Prüfungsprofil)');
$region = static fn () => 'DE-HH';
add_filter('bootspruefung_practice_region', $region);
$r = $api('GET', '/courses/'.$courseId, [], $tokA);
remove_filter('bootspruefung_practice_region', $region);
$check(count($r['body']['data']['practice_offers'] ?? []) === 1 && end($regions) === ['SKN', 'DE-HH'], 'App-Kursantwort: practice_offers; Region aus bootspruefung_practice_region');
remove_filter('bootspruefung_practice_offers', $offers, 10);

// ------------------------------------------------------------------ Datenschutz
echo "Datenschutz-Export und -Löschung\n";
$export = PersonalData::export('ext_a@example.test', 1);
$items = array_values(array_filter($export['data'], static fn ($i) => $i['group_id'] === 'bootspruefung-learning-tickets'));
$values = $items ? array_column($items[0]['data'], 'value', 'name') : [];
$check(count($items) === 1 && $values['id'] === $ticketId && $values['status'] === 'cancelled' && $values['title'] === 'Praxisstunde Motorboot', 'Export enthält die Praxis-Tickets');
$erase = PersonalData::eraseUser($userA, 'ext_a@example.test');
clean_user_cache($userA);
$check($erase['items_removed'] === true && Tickets::stored($userA) === [] && get_user_meta($userA, Tickets::LOOKUP_META, false) === [], 'Löschung entfernt die Ticket-Liste');

// ------------------------------------------------------------------ Aufräumen
foreach (array_filter(array_unique($cleanupOrders)) as $oid) {
    $wpdb->delete($p.'entitlement', ['order_id' => $oid]);
    $o = wc_get_order($oid);
    if ($o) {
        $o->delete(true);
    }
}
foreach ($cleanupProducts as $pid) {
    (new ProductAccessRepository($wpdb))->save($pid, 0, [], null, 'extend', 'revoke_on_full_refund');
    MoneyBack::setPolicy($pid, null, null);
    wp_delete_post($pid, true);
}
foreach ($users as $uid) {
    $wpdb->delete($p.'entitlement', ['user_id' => $uid]);
    $wpdb->delete($p.'mobile_rate_limit', ['bucket_hash' => $bucket($uid)]);
    $wpdb->delete($p.'mobile_session', ['user_id' => $uid]);
    wp_delete_user($uid);
}
update_option(Settings::OPTION, $settingsBefore);
$mobileBefore === false ? delete_option(MobileSettings::OPTION) : update_option(MobileSettings::OPTION, $mobileBefore);
ob_end_flush();

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
