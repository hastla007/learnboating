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
ob_start();
do_action('woocommerce_email_after_order_table', wc_get_order($bacs), false, true, null);
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

update_option(Settings::OPTION, $settingsBefore);
echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
