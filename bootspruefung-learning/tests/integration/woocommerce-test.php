<?php

declare(strict_types=1);

/*
 * Integrationstest gegen echtes WooCommerce (Kap. 7): Produktfeld, Bestell-
 * und Zahlungsereignisse (Überweisung, bezahlte Gateways, Dispute),
 * Erstattungen, Storno, Gastbestellung, Kundenansichten, Bestellaktionen,
 * HPOS-Deklaration und Abgleich über die Order-APIs.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/woocommerce-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse veröffentlicht) und woocommerce-setup.php.
 */

ob_start(); // WooCommerce setzt Session-Cookies; Ausgabe puffern, damit keine Header-Warnungen entstehen
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\WooCommerce\Gateways;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Settings\Settings;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
if (! Integration::active()) {
    fwrite(STDERR, "WooCommerce ist nicht aktiv.\n");
    exit(2);
}
global $wpdb;
wp_set_current_user(1);
$p = $wpdb->prefix.'bp_';
$course = (new \Bootspruefung\Learning\Content\Repositories\ContentRepository())->courses()[0]->code;
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
$entitlements = new EntitlementService($wpdb);
$access = new AccessService();
$statuses = static fn (int $orderId) => array_values(array_unique(array_map(static fn ($r) => $r->status, $entitlements->forOrder($orderId))));
$customer = static function (string $login): int {
    $u = get_user_by('login', $login);

    return $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer']);
};
$alice = $customer('wc_kundin_alice');
$bob = $customer('wc_kunde_bob');
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$alice}, {$bob})");

echo 'WooCommerce '.WC_VERSION.', HPOS '.(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'aktiv' : 'aus')."\n";
echo "Kompatibilität\n";
$features = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class);
$compatible = $features->get_compatible_plugins_for_feature('custom_order_tables', true);
$check(in_array('bootspruefung-learning/bootspruefung-learning.php', $compatible['compatible'] ?? $compatible, true), 'HPOS-Kompatibilität deklariert');
$blocks = $features->get_compatible_plugins_for_feature('cart_checkout_blocks', true);
$check(in_array('bootspruefung-learning/bootspruefung-learning.php', $blocks['compatible'] ?? $blocks, true), 'Checkout-Blocks-Kompatibilität deklariert');

echo "Produktfeld „Lernzugang“\n";
$make = static function (string $name, string $price): int {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price($price);
    $product->set_virtual(true);
    $product->set_status('publish');

    return (int) $product->save();
};
$lern = $make('Lernpaket Test', '49');
$video = $make('Videokurs Test', '19');
$book = $make('Buch (kein Lernprodukt)', '25');
$_POST['bp_access'] = ['present' => '1', 'scope' => [$course => ['question_bank' => '1', 'exam_simulation' => '1']], 'duration' => '', 'renewal' => 'extend', 'refund' => 'revoke_on_full_refund'];
do_action('woocommerce_process_product_meta', $lern);
$_POST['bp_access'] = ['present' => '1', 'scope' => [$course => ['video_course' => '1'], 'UNBEKANNT' => ['all' => '1']], 'duration' => '365', 'renewal' => 'extend', 'refund' => 'revoke_on_full_refund'];
do_action('woocommerce_process_product_meta', $video);
unset($_POST['bp_access']);
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}product_access WHERE product_id IN (%d, %d) ORDER BY product_id, feature_code", $lern, $video));
$check(count($rows) === 3, 'Zuordnung gespeichert, unbekannter Kurs verworfen');
$check((int) end($rows)->duration_days === 365, 'Dauer gespeichert');
$GLOBALS['post'] = get_post($lern);
ob_start();
do_action('woocommerce_product_data_panels');
$panel = (string) ob_get_clean();
$check(str_contains($panel, 'bp_learning_access_data') && substr_count($panel, 'checked') >= 2, 'Produktfeld zeigt die Zuordnung');
$check(isset(apply_filters('woocommerce_product_data_tabs', [])['bp_learning_access']), 'Reiter „Lernzugang“');
$check(wc_get_product($lern)->is_sold_individually() && ! wc_get_product($book)->is_sold_individually(), 'Lernprodukte nur einzeln');

echo "Überweisung (BACS)\n";
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product(wc_get_product($lern), 1);
$order->add_product(wc_get_product($book), 1);
$order->set_payment_method('bacs');
$order->calculate_totals();
$order->update_status('on-hold', 'Warte auf Überweisung');
$bacs = (int) $order->get_id();
$check($statuses($bacs) === ['pending'] && count($entitlements->forOrder($bacs)) === 2, 'on-hold: Zugang wartet auf Zahlung');
$check(! $access->can($alice, $course, 'question_bank'), 'noch kein Zugriff');
$order = wc_get_order($bacs);
$order->update_status('processing', 'Zahlung eingegangen');
$check($statuses($bacs) === ['active'] && $access->can($alice, $course, 'question_bank'), 'Zahlungseingang (processing) schaltet frei');
$order = wc_get_order($bacs);
$order->update_status('completed');
$check(count($entitlements->forOrder($bacs)) === 2 && $statuses($bacs) === ['active'], 'completed: keine Doppelfreischaltung');
$notes = wc_get_order_notes(['order_id' => $bacs]);
$check((bool) array_filter($notes, static fn ($n) => str_contains($n->content, 'Lernzugang')), 'Bestellnotiz für den Support');

echo "Bezahlt per Gateway, Dispute, Erstattung\n";
$order = wc_create_order(['customer_id' => $bob]);
$lernItem = $order->add_product(wc_get_product($lern), 1);
$videoItem = $order->add_product(wc_get_product($video), 1);
$order->set_payment_method('stripe');
$order->calculate_totals();
$order->save();
$order->payment_complete('pi_test_123');
$paidId = (int) $order->get_id();
$check($statuses($paidId) === ['active'] && count($entitlements->forOrder($paidId)) === 3, 'payment_complete schaltet alle Positionen frei');
$videoRow = $entitlements->forItem($videoItem)[0];
$check($videoRow->valid_until !== null && substr($videoRow->valid_until, 0, 4) >= gmdate('Y'), 'Videokurs mit Enddatum (365 Tage)');
$order = wc_get_order($paidId);
$order->update_status('on-hold', 'Stripe-Dispute');
$check($statuses($paidId) === ['suspended'] && ! $access->can($bob, $course, 'question_bank'), 'Dispute (on-hold nach Zahlung) setzt aus');
$order = wc_get_order($paidId);
$order->update_status('processing', 'Dispute gewonnen');
$check($statuses($paidId) === ['active'], 'Dispute gewonnen: reaktiviert');
$refund = wc_create_refund(['order_id' => $paidId, 'amount' => 19, 'reason' => 'Test', 'line_items' => [$videoItem => ['qty' => 1, 'refund_total' => 19]], 'refund_payment' => false, 'restock_items' => false]);
$check(! is_wp_error($refund), 'Teilerstattung angelegt');
$check($entitlements->forItem($videoItem)[0]->status === 'revoked' && array_unique(array_map(static fn ($r) => $r->status, $entitlements->forItem($lernItem))) === ['active'], 'Erstattung einer Position widerruft nur diese');
wc_create_refund(['order_id' => $paidId, 'amount' => 49, 'reason' => 'Test', 'line_items' => [$lernItem => ['qty' => 1, 'refund_total' => 49]], 'refund_payment' => false, 'restock_items' => false]);
$check(wc_get_order($paidId)->get_status() === 'refunded' && $statuses($paidId) === ['revoked'], 'vollständig erstattet: alles widerrufen');

echo "Storno, Gast, fehlgeschlagen\n";
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product(wc_get_product($video), 1);
$order->calculate_totals();
$order->save();
$order->payment_complete();
$cancel = (int) $order->get_id();
wc_get_order($cancel)->update_status('cancelled', 'Storno');
$check($statuses($cancel) === ['revoked'], 'bezahlte Bestellung storniert: widerrufen');
$order = wc_create_order(['customer_id' => 0]);
$order->add_product(wc_get_product($lern), 1);
$order->calculate_totals();
$order->save();
$order->payment_complete();
$check($entitlements->forOrder((int) $order->get_id()) === [], 'Gastbestellung: kein Zugang ohne Konto');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}audit_log WHERE action = 'order_without_account' AND object_id = %s", (string) $order->get_id())), 'Gastbestellung protokolliert');
$order->set_customer_id($bob);
$order->save();
Integration::sync()->sync((int) $order->get_id(), 'admin_save'); // wie woocommerce_process_shop_order_meta
$check($statuses((int) $order->get_id()) === ['active'], 'nach Kundenzuordnung freigeschaltet');
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product(wc_get_product($lern), 1);
$order->calculate_totals();
$order->update_status('pending');
$order->update_status('failed');
$check(array_diff($statuses((int) $order->get_id()), ['revoked']) === [], 'fehlgeschlagene Zahlung: kein Zugang');

echo "Gateway-Hooks und Bestellaktionen\n";
do_action('bootspruefung_suspend_order', $bacs, 'PayPal-Konflikt');
$check($statuses($bacs) === ['suspended'], 'Hook bootspruefung_suspend_order');
wc_get_order($bacs)->update_status('processing');
$check($statuses($bacs) === ['suspended'], 'manuelle Aussetzung übersteht Statuswechsel');
do_action('bootspruefung_reactivate_order', $bacs, 'geklärt');
$check($statuses($bacs) === ['active'], 'Hook bootspruefung_reactivate_order');
$actions = apply_filters('woocommerce_order_actions', [], wc_get_order($bacs));
$check(isset($actions['bp_access_sync'], $actions['bp_access_approve'], $actions['bp_access_suspend'], $actions['bp_access_release']), 'Bestellaktionen vorhanden');
$_POST['bp_access_reason'] = 'Rückbuchung angekündigt';
do_action('woocommerce_order_action_bp_access_suspend', wc_get_order($bacs));
$check($statuses($bacs) === ['suspended'], 'Bestellaktion „aussetzen“');
do_action('woocommerce_order_action_bp_access_release', wc_get_order($bacs));
$check($statuses($bacs) === ['active'], 'Bestellaktion „aufheben“');
$order = wc_create_order(['customer_id' => $bob]);
$order->add_product(wc_get_product($video), 1);
$order->set_payment_method('bacs');
$order->calculate_totals();
$order->update_status('on-hold');
do_action('woocommerce_order_action_bp_access_approve', wc_get_order($order->get_id()));
$check($statuses((int) $order->get_id()) === ['active'], 'Bestellaktion „vor Zahlungseingang freischalten“');
unset($_POST['bp_access_reason']);

echo "Kundenansichten\n";
$menu = apply_filters('woocommerce_account_menu_items', ['dashboard' => 'Dashboard', 'orders' => 'Bestellungen']);
$check(array_keys($menu) === ['dashboard', 'mein-lernen', 'orders'], 'Mein Konto: Menüpunkt „Mein Lernen“');
wp_set_current_user($alice);
ob_start();
do_action('woocommerce_account_mein-lernen_endpoint');
$account = (string) ob_get_clean();
$check(str_contains($account, '/bootsscheine/') && str_contains($account, 'aktiv'), 'Mein Lernen listet Zugänge mit Kurslink');
wp_set_current_user(1);
remove_all_actions('woocommerce_thankyou', 10);
ob_start();
do_action('woocommerce_thankyou', $bacs);
$thanks = (string) ob_get_clean();
$check(str_contains($thanks, 'freigeschaltet'), 'Bestellbestätigung mit Kurslink');
// Nur den eigenen Callback ausführen (andere Woo-Callbacks erwarten ein WC_Email-Objekt).
global $wp_filter;
$emailHook = $wp_filter['woocommerce_email_after_order_table'] ?? null;
$own = [];
foreach ($emailHook?->callbacks ?? [] as $prio => $callbacks) {
    foreach ($callbacks as $cb) {
        if (is_array($cb['function']) && $cb['function'][0] instanceof Integration) {
            $own[] = $cb['function'];
        }
    }
}
ob_start();
foreach ($own as $fn) {
    $fn(wc_get_order($bacs), false, true, null);
}
$mail = (string) ob_get_clean();
$check(str_contains($mail, '/bootsscheine/'), 'Kunden-E-Mail (Text) mit Kurslink');
$offers = Integration::offers($course, ['video_course' => false, 'question_bank' => true]);
$check(isset($offers['video_course'][0]) && $offers['video_course'][0]['name'] === 'Videokurs Test' && ! isset($offers['question_bank']), 'Sperrhinweis nennt das passende Produkt');
$check(Gateways::profileFor('stripe_sepa')['label'] === 'Stripe' && Gateways::profileFor('ppcp-gateway')['label'] === 'PayPal' && Gateways::profileFor('klarna_payments')['label'] === 'Klarna' && Gateways::profileFor('bacs') !== null, 'Zahlungsarten-Profile (Überweisung, Klarna, PayPal, Stripe)');
$check((bool) array_filter(Gateways::enabled(), static fn ($g) => $g['id'] === 'bacs'), 'aktive Zahlungsart erkannt');

echo "Checkout\n";
if (function_exists('wc_load_cart')) {
    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($book);
    $check(apply_filters('woocommerce_checkout_registration_required', false) === false, 'ohne Lernprodukt: Gastbestellung erlaubt');
    WC()->cart->add_to_cart($lern);
    $check(apply_filters('woocommerce_checkout_registration_required', false) === true, 'mit Lernprodukt: Kundenkonto erforderlich');
    $check(get_option('woocommerce_enable_guest_checkout') === 'no' && get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes', 'Checkout-Block: Gastbestellung aus, Kontoanlage an');
    WC()->cart->empty_cart();
}
$guest = wc_create_order(['customer_id' => 0]);
$guest->add_product(wc_get_product($lern), 1);
wp_set_current_user(0);
$threw = false;
try {
    do_action('woocommerce_store_api_checkout_update_order_from_request', $guest, new WP_REST_Request('POST', '/wc/store/v1/checkout'));
} catch (Throwable $e) {
    $threw = str_contains($e->getMessage(), 'Kundenkonto');
}
wp_set_current_user(1);
$check($threw, 'Checkout-Block: Gastbestellung mit Lernprodukt abgewiesen');

echo "Admin und Abgleich\n";
require_once ABSPATH.'wp-admin/includes/admin.php';
set_current_screen('woocommerce_page_wc-orders');
ob_start();
$item = current(wc_get_order($bacs)->get_items());
do_action('woocommerce_after_order_itemmeta', $item->get_id(), $item, $item->get_product());
$meta = (string) ob_get_clean();
$check(str_contains($meta, 'Lernzugang') && str_contains($meta, 'aktiv'), 'Bestellposition zeigt Zugänge');
$plans = iterator_to_array(Integration::sync()->reconcile(), true);
$open = array_filter($plans, static fn ($pl) => ! $pl->isEmpty());
$check(isset($plans[$bacs]) && $open === [], 'Abgleich über Order-API: keine Abweichungen');
$first = $entitlements->forOrder($bacs)[0];
$wpdb->delete($p.'entitlement', ['id' => $first->id]);
$plans = iterator_to_array(Integration::sync()->reconcile(), true);
$check(isset($plans[$bacs]) && count($plans[$bacs]->changes) === 1 && $plans[$bacs]->changes[0]['type'] === 'create', 'Abgleich erkennt fehlenden Zugang');
Integration::sync()->entitlements()->apply($plans[$bacs], 'reconcile');
$check(count($entitlements->forOrder($bacs)) === 2, 'Abgleich angewendet');

echo "Mitgliederrabatte\n";
$GLOBALS['current_screen'] = null; // Frontend-Kontext (nach Admin-Prüfungen oben)
$md = \Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts::class;
update_option($md::OPTION, []);
$md::add('', 10, 'other', 'Schülerrabatt');
$md::add($course, 20, 'learning', 'Verlängerung');
$nobody = $customer('wc_ohne_zugang');
$check($md::discountFor($alice, wc_get_product($book))['percent'] === 10.0, 'Mitglied: 10 % auf andere Produkte');
$check($md::discountFor($alice, wc_get_product($video))['percent'] === 20.0, 'Mitglied: 20 % auf Lernprodukte des Kurses');
$check($md::discountFor($nobody, wc_get_product($book)) === null, 'ohne gekauften Zugang kein Rabatt');
if (function_exists('wc_load_cart')) {
    wp_set_current_user($alice);
    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($book);
    WC()->cart->calculate_totals();
    WC()->cart->calculate_totals();
    $check(abs((float) WC()->cart->get_total('edit') - 22.5) < 0.001, 'Warenkorb: 25 € → 22,50 € (auch bei wiederholter Berechnung)');
    $html = wc_get_product($book)->get_price_html();
    $check(str_contains($html, 'bp-member-price') && str_contains($html, 'Schülerrabatt'), 'Preisanzeige „Für dich“');
    WC()->cart->empty_cart();
    wp_set_current_user($nobody);
    WC()->cart->add_to_cart($book);
    WC()->cart->calculate_totals();
    $check(abs((float) WC()->cart->get_total('edit') - 25.0) < 0.001, 'ohne Zugang: voller Preis');
    WC()->cart->empty_cart();
    wp_set_current_user(1);
}
update_option($md::OPTION, []);

echo "Gruppenlizenz über WooCommerce\n";
$school = $make('Schulpaket 5 Plätze', '250');
$_POST['bp_access'] = ['present' => '1', 'scope' => [$course => ['question_bank' => '1']], 'duration' => '', 'renewal' => 'extend', 'refund' => 'revoke_on_full_refund', 'seats' => '5'];
do_action('woocommerce_process_product_meta', $school);
unset($_POST['bp_access']);
$check(! wc_get_product($school)->is_sold_individually(), 'Gruppenprodukt in Mengen kaufbar');
$order = wc_create_order(['customer_id' => $bob]);
$schoolItem = $order->add_product(wc_get_product($school), 2);
$order->set_payment_method('stripe');
$order->calculate_totals();
$order->save();
$order->payment_complete();
$groups = Integration::sync()->groups()->forOrder((int) $order->get_id());
$check(count($groups) === 1 && (int) $groups[0]->seats === 10 && $entitlements->forOrder((int) $order->get_id()) === [], 'Zahlung: Gruppe mit 10 Plätzen, kein Einzelzugang');
remove_all_actions('woocommerce_thankyou', 10);
ob_start();
do_action('woocommerce_thankyou', $order->get_id());
$check(str_contains((string) ob_get_clean(), '/bootsscheine/gruppen/'), 'Bestellbestätigung verlinkt die Gruppenverwaltung');
Integration::sync()->groups()->join((string) $groups[0]->join_token, $nobody);
$check($access->can($nobody, $course, 'question_bank'), 'Beitritt über Link schaltet frei');
wc_create_refund(['order_id' => $order->get_id(), 'amount' => 500, 'reason' => 'Test', 'line_items' => [$schoolItem => ['qty' => 2, 'refund_total' => 500]], 'refund_payment' => false, 'restock_items' => false]);
$check(Integration::sync()->groups()->find((int) $groups[0]->id)->status === 'revoked' && ! $access->can($nobody, $course, 'question_bank'), 'Erstattung beendet Gruppe und Plätze');

echo "Disputes per Webhook (PayPal, allgemein)\n";
$dw = \Bootspruefung\Learning\Commerce\Disputes\DisputeWebhooks::class;
update_option($dw::OPTION, ['paypal_client_id' => 'cid', 'paypal_secret' => 'sec', 'paypal_webhook_id' => 'WH', 'paypal_mode' => 'sandbox', 'generic_secret' => 'test-secret']);
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product(wc_get_product($video), 1);
$order->set_payment_method('ppcp-gateway');
$order->calculate_totals();
$order->save();
$order->payment_complete('CAPTURE-'.wp_generate_password(6, false));
$ppOrder = (int) $order->get_id();
$capture = wc_get_order($ppOrder)->get_transaction_id();
$postHook = static function (string $route, array $body, array $headers = []) {
    $request = new WP_REST_Request('POST', '/bootspruefung/v1/webhooks/'.$route);
    $request->set_body(wp_json_encode($body));
    $request->set_header('content-type', 'application/json');
    foreach ($headers as $k => $v) {
        $request->set_header($k, $v);
    }

    return rest_do_request($request);
};
$dispute = static fn (string $id, string $type, string $outcome = '') => ['id' => $id, 'event_type' => $type, 'resource' => ['dispute_id' => 'PP-D-1', 'disputed_transactions' => [['seller_transaction_id' => $capture]], 'dispute_outcome' => ['outcome_code' => $outcome]]];
add_filter('bootspruefung_paypal_verify', '__return_false');
$check($postHook('paypal', $dispute('WH-0', 'CUSTOMER.DISPUTE.CREATED'))->get_status() === 401 && $statuses($ppOrder) === ['active'], 'PayPal: ungeprüfte Nachricht abgewiesen');
remove_filter('bootspruefung_paypal_verify', '__return_false');
add_filter('bootspruefung_paypal_verify', '__return_true');
$r = $postHook('paypal', $dispute('WH-1', 'CUSTOMER.DISPUTE.CREATED'));
$check($r->get_status() === 200 && $statuses($ppOrder) === ['suspended'], 'PayPal-Dispute eröffnet: Zugang ausgesetzt');
$check(! empty($postHook('paypal', $dispute('WH-1', 'CUSTOMER.DISPUTE.CREATED'))->get_data()['duplicate']), 'doppelte Zustellung wird ignoriert');
$postHook('paypal', $dispute('WH-2', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR'));
$check($statuses($ppOrder) === ['active'], 'zugunsten des Händlers: wieder aktiv');
$postHook('paypal', $dispute('WH-3', 'CUSTOMER.DISPUTE.CREATED'));
$postHook('paypal', $dispute('WH-4', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_BUYER_FAVOUR'));
$check($statuses($ppOrder) === ['revoked'], 'zugunsten des Käufers: widerrufen');
remove_filter('bootspruefung_paypal_verify', '__return_true');
$order = wc_create_order(['customer_id' => $bob]);
$order->add_product(wc_get_product($video), 1);
$order->set_payment_method('klarna_payments');
$order->calculate_totals();
$order->save();
$order->payment_complete();
$klarna = (int) $order->get_id();
$body = wp_json_encode(['id' => 'K-1', 'event' => 'opened', 'order_id' => $klarna, 'source' => 'klarna']);
$sign = static function (string $body, string $secret = 'test-secret'): string {
    return 'sha256='.hash_hmac('sha256', $body, $secret);
};
$req = static function (string $body, string $sig) {
    $request = new WP_REST_Request('POST', '/bootspruefung/v1/webhooks/dispute');
    $request->set_body($body);
    $request->set_header('x-bootspruefung-signature', $sig);

    return rest_do_request($request);
};
$check($req($body, $sign($body, 'falsch'))->get_status() === 401 && $statuses($klarna) === ['active'], 'allgemeiner Webhook: falsche Signatur abgewiesen');
$check($req($body, $sign($body))->get_status() === 200 && $statuses($klarna) === ['suspended'], 'Klarna-Konflikt über allgemeinen Webhook: ausgesetzt');
$won = wp_json_encode(['id' => 'K-2', 'event' => 'won', 'order_id' => $klarna, 'source' => 'klarna']);
$req($won, $sign($won));
$check($statuses($klarna) === ['active'], 'Konflikt gewonnen: aktiv');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}audit_log WHERE action = 'dispute_opened' AND object_id = %s", (string) $klarna)), 'Konflikt protokolliert');
update_option($dw::OPTION, []);

echo "Prüfung der Produktzuordnungen\n";
$noPrice = new WC_Product_Simple();
$noPrice->set_name('Ohne Preis');
$noPrice->set_status('publish');
$noPriceId = (int) $noPrice->save();
(new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->save($noPriceId, 0, [['course_code' => $course, 'feature_code' => 'question_bank'], ['course_code' => 'GIBT-ES-NICHT', 'feature_code' => 'question_bank']], null, 'extend', 'revoke_on_full_refund');
$issues = array_filter(\Bootspruefung\Learning\Commerce\MappingCheck::issues(), static fn ($i) => $i['product_id'] === $noPriceId);
$problems = implode(' | ', array_column($issues, 'problem'));
$check(str_contains($problems, 'nicht kaufbar') && str_contains($problems, 'GIBT-ES-NICHT') && str_contains($problems, 'virtuell'), 'Übersicht meldet fehlenden Preis, unbekannten Kurs, nicht virtuell');
$check(\Bootspruefung\Learning\Commerce\MappingCheck::summary()['mapping_errors'] >= 2, 'Fehlerzahl in der Übersicht');
wp_delete_post($noPriceId, true);
(new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->save($noPriceId, 0, [], null, 'extend', 'revoke_on_full_refund');

echo "Shop-Katalog und Geld-zurück-Garantie\n";
$rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts();
$byS = array_column($rows, null, 'sku');
$kombi = wc_get_product($byS['BP-SBF-SEE-BIN']['product_id']);
$features = static fn (string $sku): array => array_values(array_unique(array_map(static fn ($r) => $r->feature_code, (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->forItem($byS[$sku]['product_id'], 0))));
$check(count($rows) === 62 && $kombi && $kombi->get_regular_price() === '60.80' && $kombi->get_status() === 'draft' && $kombi->is_virtual(), 'Katalog: 62 Produkte, Kombi 60,80 €, Entwurf, virtuell');
$check(! in_array('video_course', $features('BP-SBF-SEE'), true) && ! in_array('all', $features('BP-SBF-SEE'), true) && in_array('video_course', $features('BP-SBF-SEE-VIDEO'), true) && count($features('BP-SBF-SEE-VIDEO')) === 1 && in_array('all', $features('BP-SBF-SEE-KOMPLETT'), true), 'Theorie ohne Videokurs, Komplett mit allem, Upgrade nur Videokurs');
$check(! in_array('device_simulation', $features('BP-SRC'), true) && ! in_array('all', $features('BP-SRC'), true) && in_array('question_bank', $features('BP-SRC'), true), 'SRC-Kurs ohne Gerätesimulation (Upgrade)');
$check(in_array('all', $features('BP-KAPITAEN'), true) && in_array('all', $features('BP-PASS-1M'), true), 'Bundle und Pass behalten alle Funktionen');
$simRows = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->forItem($byS['SIM-PASS-14D']['product_id'], 0);
$sim3 = wc_get_product($byS['SIM-PASS-3M']['product_id']);
$check($features('SIM-PASS-14D') === ['device_simulation'] && count($simRows) === 4 && (int) $simRows[0]->duration_days === 14 && $sim3->get_regular_price() === '23.50' && \Bootspruefung\Learning\Commerce\MoneyBack::policy($sim3->get_id()) === null, 'Simulator-Pässe: nur Gerätesimulation, 4 Funkkurse, 14 Tage, 23,50 €, ohne Garantie');
$check(wc_get_product($byS['SIM-UPGRADE-CHECKOUT']['product_id'])->get_catalog_visibility() === 'hidden' && ! str_contains($sim3->get_description(), 'Geld-zurück-Garantie:'), 'Upgrade nicht im Shop gelistet; Simulator-Text ohne Garantiezusage');
$kRows = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->forItem($kombi->get_id(), 0);
$check(count(array_unique(array_map(static fn ($r) => $r->course_code, $kRows))) === 3 && (int) $kRows[0]->duration_days === 365, 'Kombi schaltet SBF See & Binnen, See und Binnen für 12 Monate frei');
$second = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts();
$check(array_unique(array_column($second, 'action')) === ['unverändert'] && array_column($second, 'product_id') === array_column($rows, 'product_id'), 'zweiter Lauf: keine Dubletten, unverändert');
$kombi = wc_get_product($kombi->get_id());
$cat = get_term($kombi->get_category_ids()[0] ?? 0, 'product_cat');
$check($kombi->get_image_id() > 0 && get_post_meta($kombi->get_image_id(), '_bp_catalog_image', true) === 'BP-SBF-SEE-BIN' && $cat instanceof WP_Term && $cat->name === 'Sportbootführerscheine' && $cat->slug === 'sbf', 'Produktbild und Kategorie (Kürzel sbf) gesetzt');
$check(str_contains($kombi->get_description(), 'Geld-zurück-Garantie') && str_contains($kombi->get_description(), 'Navigationsaufgaben') && str_contains($kombi->get_short_description(), 'Prüfungsfragen'), 'Produkttexte aus den Kursinhalten');
$edited = wc_get_product($byS['BP-SBF-SEE']['product_id']);
$edited->set_description('Eigener Text des Betreibers');
$edited->save();
$published = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts(false, true);
$check(wc_get_product($byS['BP-SBF-SEE']['product_id'])->get_description() === 'Eigener Text des Betreibers' && wc_get_product($kombi->get_id())->get_status() === 'publish', 'veröffentlicht; von Hand geänderter Text bleibt');
$check(count(get_posts(['post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image'])) === 62, 'Bilder nur einmal importiert');
$check(wc_get_product($byS['BP-SBF-SEE-VIDEO']['product_id'])->get_catalog_visibility() === 'hidden' && wc_get_product($byS['BP-SBF-SEE-KOMPLETT']['product_id'])->get_catalog_visibility() === 'visible' && \Bootspruefung\Learning\Commerce\MoneyBack::policy($byS['BP-SBF-SEE-VIDEO']['product_id']) !== null, 'Video-Upgrade nicht gelistet, mit Geld-zurück-Garantie; Komplett im Shop');
$fam = wc_get_product($byS['GRP-FAM-02']['product_id']);
$famRows = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->forItem($fam->get_id(), 0);
$check($fam->get_regular_price() === '148.90' && \Bootspruefung\Learning\Commerce\ProductAccessRepository::seats($famRows) === 3 && \Bootspruefung\Learning\Commerce\MoneyBack::policy($fam->get_id()) === null && ! $fam->is_sold_individually(), 'Familien-Flotte: 3 Plätze, 148,90 €, ohne Geld-zurück-Garantie');
$check(wc_get_product($byS['GRP-CLUB-50']['product_id'])->get_regular_price() === '1725.00', 'Partner-Flat 50: 1.725,00 €');
$skn = wc_get_product($byS['BP-SKN']['product_id']);
$skn->set_status('publish');
$skn->save();
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product($skn, 1);
$order->set_payment_method('stripe');
$order->calculate_totals();
$order->save();
$order->payment_complete('ch_mb_'.wp_generate_password(6, false));
$mb = \Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo::status(wc_get_order($order->get_id()));
$check($mb['applies'] && $mb['eligible'] && (float) $mb['max_progress'] === 20.0, 'bezahlte Bestellung: Geld zurück möglich (Grenze 20 %)');
wp_set_current_user($alice);
ob_start();
\Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo::renderAccountSection($alice);
$html = (string) ob_get_clean();
wp_set_current_user(1);
$check(str_contains($html, 'Geld zurück anfordern') && str_contains($html, '#'.$order->get_id()), '„Mein Lernen“ zeigt Garantie mit Anfrage-Knopf');
$order = wc_get_order($order->get_id());
$order->update_meta_data(\Bootspruefung\Learning\Commerce\MoneyBack::REQUEST_META, \Bootspruefung\Learning\Commerce\MoneyBack::recordRequest($order->get_id(), $alice, $mb));
$order->save();
wc_create_refund(['order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'Geld-zurück-Garantie', 'line_items' => array_map(static fn ($i) => ['qty' => $i->get_quantity(), 'refund_total' => $i->get_total()], $order->get_items())]);
$check($statuses($order->get_id()) === ['revoked'] && \Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo::status(wc_get_order($order->get_id()))['reason'] === 'refunded', 'nach Erstattung: Zugang widerrufen, Garantie erledigt');
echo "Simulator-Upgrade im Checkout\n";
if (function_exists('wc_load_cart')) {
    $simUser = $customer('wc_sim_kunde');
    wp_set_current_user($simUser);
    wc_load_cart();
    WC()->cart->empty_cart();
    wc_clear_notices();
    $upId = (int) $byS['SIM-UPGRADE-CHECKOUT']['product_id'];
    $srcId = (int) $byS['BP-SRC']['product_id'];
    // Kauf-Link/Store API prüfen beim Hinzufügen; direkt eingefügt wird es bei der nächsten Berechnung entfernt.
    $check(apply_filters('woocommerce_add_to_cart_validation', true, $upId, 1) === false, 'Upgrade ohne Funkkurs beim Hinzufügen abgelehnt');
    WC()->cart->add_to_cart($upId);
    WC()->cart->calculate_totals();
    $check(WC()->cart->is_empty(), 'Upgrade ohne Funkkurs aus dem Warenkorb entfernt');
    wc_clear_notices();
    $check(\Bootspruefung\Learning\Commerce\WooCommerce\SimUpgrade::box() === '', 'kein Angebot ohne Funkkurs');
    $srcKey = WC()->cart->add_to_cart($srcId);
    $box = \Bootspruefung\Learning\Commerce\WooCommerce\SimUpgrade::box();
    $check(str_contains($box, 'Praxis-Sorglos-Upgrade') && preg_match('/13[.,]90/', $box) && str_contains($box, 'add-to-cart='.$upId) && preg_match('/34[.,]50/', $box), 'mit SRC-Kurs: Upgrade für 13,90 € angeboten (statt 34,50 €)');
    $check((bool) WC()->cart->add_to_cart($upId) && \Bootspruefung\Learning\Commerce\WooCommerce\SimUpgrade::box() === '', 'Upgrade im Warenkorb, Angebot verschwindet');
    WC()->cart->remove_cart_item($srcKey);
    WC()->cart->calculate_totals();
    $check(WC()->cart->is_empty(), 'Funkkurs entfernt → Upgrade entfernt');
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(0);
}
echo "Video-Upgrade zur Theorie-Stufe\n";
if (function_exists('wc_load_cart')) {
    $vidUser = $customer('wc_video_kunde');
    wp_set_current_user($vidUser);
    wc_load_cart();
    WC()->cart->empty_cart();
    wc_clear_notices();
    $vUp = (int) $byS['BP-SBF-SEE-VIDEO']['product_id'];
    $vBase = (int) $byS['BP-SBF-SEE']['product_id'];
    $vFull = (int) $byS['BP-SBF-SEE-KOMPLETT']['product_id'];
    $check(apply_filters('woocommerce_add_to_cart_validation', true, $vUp, 1) === false, 'Video-Upgrade ohne Theorie abgelehnt');
    wc_clear_notices();
    $check(\Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::box() === '', 'kein Video-Angebot ohne Theorie im Warenkorb');
    $baseKey = WC()->cart->add_to_cart($vBase);
    $vBox = \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::box();
    $check(str_contains($vBox, 'Videokurs') && preg_match('/11[.,]90/', $vBox) && str_contains($vBox, 'add-to-cart='.$vUp), 'Theorie im Warenkorb: Videokurs für 11,90 € angeboten');
    $check(apply_filters('woocommerce_add_to_cart_validation', true, $vUp, 1) === true && (bool) WC()->cart->add_to_cart($vUp) && \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::box() === '', 'Upgrade im Warenkorb, Angebot verschwindet');
    WC()->cart->remove_cart_item($baseKey);
    WC()->cart->calculate_totals();
    $check(WC()->cart->is_empty(), 'Theorie entfernt → Video-Upgrade entfernt');
    WC()->cart->add_to_cart($vFull);
    wc_clear_notices();
    $check(apply_filters('woocommerce_add_to_cart_validation', true, $vUp, 1) === false, 'Komplett im Warenkorb: Upgrade nicht nötig');
    WC()->cart->empty_cart();
    wc_clear_notices();
    // Theorie bereits gekauft: Upgrade später im Lernbereich und ohne Theorie im Warenkorb kaufbar.
    $now = current_time('mysql', true);
    $wpdb->insert($wpdb->prefix.'bp_entitlement', ['user_id' => $vidUser, 'course_code' => 'SBF-SEE', 'feature_code' => 'question_bank', 'status' => 'active', 'valid_from' => $now, 'valid_until' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'source' => 'manual', 'reference_key' => 'video-test-'.$vidUser, 'created_at' => $now, 'updated_at' => $now]);
    $offer = \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::offerFor($vidUser, 'SBF-SEE');
    $check($offer !== null && str_contains($offer['url'], 'add-to-cart='.$vUp) && apply_filters('woocommerce_add_to_cart_validation', true, $vUp, 1) === true, 'Theorie gekauft: Upgrade im Lernbereich angeboten und kaufbar');
    $wpdb->delete($wpdb->prefix.'bp_entitlement', ['reference_key' => 'video-test-'.$vidUser]);
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(0);
}
foreach (array_column($rows, 'product_id') as $pid) {
    (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->save((int) $pid, 0, [], null, 'extend', 'revoke_on_full_refund');
    wp_delete_post((int) $pid, true);
}
foreach (get_posts(['post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image']) as $att) {
    wp_delete_attachment((int) $att, true);
}

update_option(Settings::OPTION, $settingsBefore);
echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
ob_end_flush();
exit($failures === 0 ? 0 : 1);
