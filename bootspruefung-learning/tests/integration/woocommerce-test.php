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
require __DIR__.'/support/guard.php';

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

echo "OSS-Schwelle (T53)\n";
$oss = \Bootspruefung\Learning\Commerce\WooCommerce\OssMonitor::class;
$ossBase = get_option('woocommerce_default_country');
update_option('woocommerce_default_country', 'DE');
$year = (int) wp_date('Y');
$before = $oss::totals($year, true);
$ossOrder = static function (string $country) use ($alice, $book): int {
    $o = wc_create_order(['customer_id' => $alice]);
    $o->add_product(wc_get_product($book), 1);
    $o->set_billing_country($country);
    $o->calculate_totals();
    $o->update_status('processing', 'bezahlt');

    return (int) $o->get_id();
};
$at = $ossOrder('AT');
$de = $ossOrder('DE');
$ch = $ossOrder('CH');
$after = $oss::totals($year, true);
$atNet = (float) wc_get_order($at)->get_total() - (float) wc_get_order($at)->get_total_tax();
$check(abs($after['total'] - $before['total'] - $atNet) < 0.01 && $after['orders'] === $before['orders'] + 1 && isset($after['by_country']['AT']), 'nur EU-Ausland gezählt (AT ja, DE und CH nein)');
wc_create_refund(['order_id' => $at, 'amount' => wc_get_order($at)->get_total(), 'reason' => 'Test']);
$check(abs($oss::totals($year, true)['total'] - $before['total']) < 0.01, 'Erstattung abgezogen');
$lowThreshold = static fn () => 1.0;
add_filter('bootspruefung_oss_threshold', $lowThreshold);
$ossOrder('FR');
delete_transient('bp_oss_totals_'.$year);
$st = $oss::status();
$check($st['state'] === 'exceeded' && $st['threshold'] === 1.0, 'Schwelle überschritten erkannt');
wp_set_current_user(1);
ob_start();
$oss::adminNotice();
$notice = (string) ob_get_clean();
$check($st['oss'] || str_contains($notice, 'OSS-Schwelle:'), 'Hinweis ohne OSS-Steuersätze');
ob_start();
$oss::renderStatus();
$check(str_contains((string) ob_get_clean(), 'Steuer: OSS-Schwelle'), 'Abschnitt im Systemstatus');
remove_filter('bootspruefung_oss_threshold', $lowThreshold);

echo "OSS-Schwelle: Summen per SQL, Jahreswechsel (T85)\n";
// Erwartung wie bisher aus den Bestellobjekten berechnen und mit den SQL-Summen vergleichen.
$ossExpected = static function (int $y) use ($oss): array {
    $tz = wp_timezone();
    $from = (new DateTimeImmutable($y.'-01-01 00:00:00', $tz))->getTimestamp();
    $to = (new DateTimeImmutable(($y + 1).'-01-01 00:00:00', $tz))->getTimestamp() - 1;
    $foreign = array_diff($oss::euCountries(), [$oss::baseCountry()]);
    $out = ['total' => 0.0, 'orders' => 0, 'by_country' => []];
    foreach (wc_get_orders(['status' => ['processing', 'completed'], 'date_paid' => $from.'...'.$to, 'limit' => -1, 'type' => 'shop_order']) as $o) {
        $c = strtoupper((string) $o->get_billing_country());
        if (! in_array($c, $foreign, true)) {
            continue;
        }
        $net = (float) $o->get_total() - (float) $o->get_total_tax() - ((float) $o->get_total_refunded() - (float) $o->get_total_tax_refunded());
        $out['by_country'][$c] = round(($out['by_country'][$c] ?? 0.0) + $net, 2);
        $out['total'] = round($out['total'] + $net, 2);
        $out['orders']++;
    }

    return $out;
};
$partial = $ossOrder('NL');
wc_create_refund(['order_id' => $partial, 'amount' => 5, 'reason' => 'Teilerstattung']);
$expected = $ossExpected($year);
$queriesBefore = $wpdb->num_queries;
$sqlTotals = $oss::totals($year, true);
$ossQueries = $wpdb->num_queries - $queriesBefore;
$check(abs($sqlTotals['total'] - $expected['total']) < 0.02 && $sqlTotals['orders'] === $expected['orders'] && abs(($sqlTotals['by_country']['NL'] ?? 0) - ($expected['by_country']['NL'] ?? -1)) < 0.01, 'SQL-Summen gleich den Bestellobjekten (inkl. Teilerstattung)');
$check($ossQueries <= 4, 'Summen mit wenigen Abfragen statt Bestellobjekten ('.$ossQueries.' Abfragen)');
$tz = wp_timezone();
$from = (new DateTimeImmutable($year.'-01-01 00:00:00', $tz))->getTimestamp();
$to = (new DateTimeImmutable(($year + 1).'-01-01 00:00:00', $tz))->getTimestamp() - 1;
$foreign = array_values(array_diff($oss::euCountries(), ['DE']));
$cptRows = $oss::sums($from, $to, $foreign, false);
// HPOS-Variante: Bestellungen in die HPOS-Tabellen übertragen (wie die WooCommerce-Synchronisierung) und vergleichen.
$hposChecked = false;
$syncClass = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;
if (! $oss::hposActive() && class_exists($syncClass)) {
    try {
        $syncer = wc_get_container()->get($syncClass);
        $hadTables = $syncer->check_orders_table_exists();
        if ($hadTables || $syncer->create_database_tables()) {
            $all = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_refund') ORDER BY ID"));
            foreach (array_chunk($all, 100) as $chunk) {
                $syncer->process_batch($chunk);
            }
            $norm = static function (array $rows): array {
                $out = [];
                foreach ($rows as $r) {
                    $out[$r['country']] = [$r['orders'], round($r['total'], 2), round($r['tax'], 2), round($r['refunded'], 2), round($r['tax_refunded'], 2)];
                }
                ksort($out);

                return $out;
            };
            $hposRows = $oss::sums($from, $to, $foreign, true);
            $check($norm($hposRows) === $norm($cptRows) && $hposRows !== [], 'HPOS-Tabellen (wc_orders) liefern dieselben Summen wie wp_posts');
            $hposChecked = true;
            if (! $hadTables) {
                $syncer->delete_database_tables();
            }
        }
    } catch (Throwable $e) {
        echo '  (HPOS-Vergleich übersprungen: '.$e->getMessage().")\n";
    }
} elseif ($oss::hposActive()) {
    $check($oss::sums($from, $to, $foreign, true) !== [], 'HPOS aktiv: Summen aus wc_orders');
    $hposChecked = true;
}
$check($hposChecked || ! class_exists($syncClass), 'HPOS-Variante geprüft');
// Jahreswechsel: 31.12. 23:30 UTC ist in Berlin schon der 1.1. – der Schlüssel folgt der Zeitzone der Website.
$tzBak = [get_option('timezone_string'), get_option('gmt_offset')];
update_option('timezone_string', 'Europe/Berlin');
$silvester = gmmktime(23, 30, 0, 12, 31, 2026);
$check($oss::cacheYear($silvester) === 2027 && (int) gmdate('Y', $silvester) === 2026, 'Jahreswechsel: Schlüsseljahr nach Ortszeit (2027), nicht UTC (2026)');
set_transient('bp_oss_totals_2027', ['year' => 2027], HOUR_IN_SECONDS);
set_transient('bp_oss_totals_2026', ['year' => 2026], HOUR_IN_SECONDS);
set_transient('bp_oss_totals_2025', ['year' => 2025], HOUR_IN_SECONDS);
$oss::flush($silvester);
$check(get_transient('bp_oss_totals_2027') === false && get_transient('bp_oss_totals_2026') === false && is_array(get_transient('bp_oss_totals_2025')), 'Silvesternacht: laufendes Jahr (2027) und Vorjahr geleert, älteres Jahr unberührt');
delete_transient('bp_oss_totals_2025');
update_option('timezone_string', $tzBak[0]);
update_option('gmt_offset', $tzBak[1]);
set_transient('bp_oss_totals_'.$oss::cacheYear(), ['year' => 1], HOUR_IN_SECONDS);
wc_get_order($partial)->update_status('completed');
$check(get_transient('bp_oss_totals_'.$oss::cacheYear()) === false, 'Statuswechsel leert den Zwischenspeicher des laufenden Jahres');
update_option('woocommerce_default_country', $ossBase);
delete_transient('bp_oss_totals_'.$year);

echo "Vertragsbestätigung: Laufzeit je Position und Garantie (T52)\n";
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($video, 14, 20.0);
$cs = \Bootspruefung\Learning\Commerce\WooCommerce\ContractSummary::class;
$order = wc_create_order(['customer_id' => $alice]);
$order->add_product(wc_get_product($video), 1);
$order->add_product(wc_get_product($lern), 1);
$order->add_product(wc_get_product($book), 1);
$order->set_payment_method('bacs');
$order->calculate_totals();
$order->update_status('on-hold', 'Warte auf Überweisung');
$csId = (int) $order->get_id();
$text = $cs::text(wc_get_order($csId));
$check(str_contains($text, 'Videokurs Test: 12 Monate ab Zahlungseingang') && str_contains($text, 'Lernpaket Test: unbefristet') && ! str_contains($text, 'Buch'), 'vor Zahlung: Laufzeit ab Zahlungseingang, nur Lernprodukte');
wc_get_order($csId)->update_status('processing', 'Zahlung eingegangen');
$text = $cs::text(wc_get_order($csId));
$check((bool) preg_match('/Videokurs Test: 12 Monate, bis \S+/u', $text), 'nach Zahlung: Enddatum je Position');
$guarantorFilter = static fn () => 'Muster GmbH, Hafenstraße 1, 20457 Hamburg';
add_filter('bootspruefung_guarantor', $guarantorFilter);
$text = $cs::text(wc_get_order($csId));
$check(str_contains($text, 'Für Videokurs Test gilt unsere kostenlose Geld-zurück-Garantie') && ! str_contains($text, 'Für Videokurs Test, Lernpaket') && str_contains($text, 'Garantiegeber: Muster GmbH, Hafenstraße 1, 20457 Hamburg.') && str_contains($text, 'gesetzlichen Rechte bei Mängeln'), 'Garantie-Erklärung: begünstigte Position, Garantiegeber, Mängelrechte');
remove_filter('bootspruefung_guarantor', $guarantorFilter);
ob_start();
(new $cs())->email(wc_get_order($csId), false, false, (object) ['id' => 'customer_refunded_order']);
$check(trim((string) ob_get_clean()) === '', 'nicht in der Erstattungsmail');
\Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($video, null, null);

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
$check(array_keys($menu) === ['dashboard', 'mein-lernen', 'orders', 'meine-daten'], 'Mein Konto: Menüpunkte „Mein Lernen“ und „Meine Daten“');
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
    // T51/UX4-11: Gäste sehen vor der Anmeldung Produkt und Summe.
    WC()->cart->calculate_totals();
    $summary = \Bootspruefung\Learning\Commerce\WooCommerce\Integration::guestCartSummary();
    $prevUser = get_current_user_id();
    wp_set_current_user(0);
    $guestBlock = (new \Bootspruefung\Learning\Commerce\WooCommerce\Integration())->blockCheckoutForGuests('<div>Kasse</div>');
    wp_set_current_user($prevUser);
    $check(str_contains($summary, 'bp-checkout-summary') && str_contains($summary, esc_html(get_the_title($lern))) && str_contains($summary, 'Summe')
        && str_contains($guestBlock, 'bp-checkout-summary') && ! str_contains($guestBlock, 'Kasse'), 'Kasse für Gäste: Produkt und Summe über dem Anmeldekasten');
    WC()->cart->empty_cart();
    $check(\Bootspruefung\Learning\Commerce\WooCommerce\Integration::guestCartSummary() === '', 'leerer Warenkorb: keine Übersicht');
}
echo "Zwischenspeicher Shop: Zuordnungen, Warenkorb, Mitgliedskurse (T85)\n";
$shopCache = \Bootspruefung\Learning\Commerce\WooCommerce\ShopCache::class;
$tableQueries = ['bp_product_access' => 0, 'bp_entitlement' => 0];
$countTables = static function ($q) use (&$tableQueries) {
    foreach (array_keys($tableQueries) as $t) {
        if (is_string($q) && str_contains($q, $t) && stripos(ltrim($q), 'SELECT') === 0) {
            $tableQueries[$t]++;
        }
    }

    return $q;
};
add_filter('query', $countTables);
$shopCache::flush();
$products = array_map('wc_get_product', [$lern, $video, $book]);
for ($i = 0; $i < 10; $i++) {
    foreach ($products as $prod) {
        Integration::productHasLearning($prod);
        Integration::isGroupProduct($prod);
    }
}
$check($tableQueries['bp_product_access'] === 1, 'Produktzuordnungen einmal je Anfrage geladen statt je Produkt (60 Prüfungen, '.$tableQueries['bp_product_access'].' Abfrage)');
$check(Integration::productHasLearning($products[0]) && ! Integration::productHasLearning($products[2]) && ! Integration::isGroupProduct($products[0]), 'Ergebnis unverändert (Lernpaket ja, Buch nein)');
$repoT85 = new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb);
$repoT85->save($book, 0, [['course_code' => $course, 'feature_code' => 'question_bank']], null, 'extend', 'revoke_on_full_refund', 3);
$check(Integration::productHasLearning(wc_get_product($book)) && Integration::isGroupProduct(wc_get_product($book)), 'Zuordnung geändert → Zwischenspeicher sofort ungültig (Lernzugang, Plätze)');
$repoT85->save($book, 0, [], null, 'extend', 'revoke_on_full_refund');
$check(! Integration::productHasLearning(wc_get_product($book)), 'Zuordnung entfernt → wieder kein Lernprodukt');
if (function_exists('wc_load_cart')) {
    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($book);
    $loads = $shopCache::$loads['carts'];
    for ($i = 0; $i < 5; $i++) {
        get_option('woocommerce_enable_guest_checkout');
        apply_filters('woocommerce_checkout_registration_required', false);
    }
    $check($shopCache::$loads['carts'] - $loads === 1 && Integration::cartHasLearning() === false, 'Warenkorb einmal je Stand geprüft (10 Abfragen der Kasse, 1 Prüfung)');
    WC()->cart->add_to_cart($lern);
    $check(Integration::cartHasLearning() === true && $shopCache::$loads['carts'] - $loads === 2, 'Warenkorb geändert → neu geprüft');
    WC()->cart->empty_cart();
    $check(Integration::cartHasLearning() === false, 'Warenkorb geleert → kein Lernprodukt');
}
$md85 = \Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts::class;
update_option($md85::OPTION, []);
$md85::add('', 10, 'other', 'Test T85');
$shopCache::flush();
$tableQueries['bp_entitlement'] = 0;
foreach ($products as $prod) {
    for ($i = 0; $i < 5; $i++) {
        $md85::discountFor($alice, $prod);
    }
}
$check($tableQueries['bp_entitlement'] === 1 && $md85::memberCourses($alice) !== [], 'Mitgliedskurse einmal je Nutzer und Anfrage (15 Preisprüfungen, '.$tableQueries['bp_entitlement'].' Abfrage)');
$aliceUntil = $wpdb->get_results($wpdb->prepare("SELECT id, valid_until FROM {$p}entitlement WHERE user_id = %d AND status = 'active'", $alice));
$wpdb->query($wpdb->prepare("UPDATE {$p}entitlement SET valid_until = '2000-01-01 00:00:00' WHERE user_id = %d AND status = 'active'", $alice));
$check($md85::memberCourses($alice) === [], 'Zugang geändert → Mitgliedskurse neu gelesen');
foreach ($aliceUntil as $row) {
    $wpdb->update($p.'entitlement', ['valid_until' => $row->valid_until], ['id' => (int) $row->id]);
}
$check($md85::memberCourses($alice) !== [] && $md85::memberCourses($customer('wc_ohne_zugang')) === [], 'je Nutzer getrennt');
update_option($md85::OPTION, []);
remove_filter('query', $countTables);

echo "Kennzahlen der Kurse zwischengespeichert (T85)\n";
$catalog = \Bootspruefung\Learning\Commerce\ShopCatalog::class;
$memo = new ReflectionProperty($catalog, 'statsMemo');
$memo->setAccessible(true);
$catalog::flushCourseStats();
$fresh = $catalog::courseStats([$course]);
$stored = get_transient($catalog::STATS_TRANSIENT);
$check(is_array($stored) && ($stored['stats'][$course] ?? null) === $fresh && $fresh['questions'] > 0, 'Kennzahlen im Transient abgelegt');
$memo->setValue(null, []); // neue Anfrage
$stored['stats'][$course]['questions'] = 4242;
set_transient($catalog::STATS_TRANSIENT, $stored, HOUR_IN_SECONDS);
$q0 = $wpdb->num_queries;
$cached = $catalog::courseStats([$course]);
$check($cached['questions'] === 4242 && $wpdb->num_queries - $q0 <= 1, 'nächste Anfrage: aus dem Transient, ohne Zählabfragen');
\Bootspruefung\Learning\Content\Repositories\ContentCache::invalidate();
$check(get_transient($catalog::STATS_TRANSIENT) === false && $catalog::courseStats([$course]) === $fresh, 'Import/Paketwechsel (ContentCache::invalidate) leert die Kennzahlen');
$stored = get_transient($catalog::STATS_TRANSIENT);
$stored['stamp'] = 'anderes-paket';
$stored['stats'][$course]['questions'] = 4242;
set_transient($catalog::STATS_TRANSIENT, $stored, HOUR_IN_SECONDS);
$memo->setValue(null, []);
$check($catalog::courseStats([$course]) === $fresh, 'anderes aktives Paket: Kennzahlen neu gezählt');
wc_get_product($book)->save();
$check(get_transient($catalog::STATS_TRANSIENT) === false, 'Produktänderung leert die Kennzahlen');

// Zahlungsarten, Verkaufsländer, Lieferung vor Beginn des Bestellvorgangs (§ 312j Abs. 1 BGB): Produktseite und Warenkorb.
echo "Hinweis Zahlungsarten und Lieferung\n";
$pi = \Bootspruefung\Learning\Commerce\WooCommerce\PurchaseInfo::class;
$piOptions = [];
foreach (['woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_all_except_countries'] as $k) {
    $piOptions[$k] = get_option($k, '__bp_missing__');
}
$bacsTitle = trim(wp_strip_all_tags((string) (WC()->payment_gateways()->payment_gateways()['bacs'] ?? null)?->get_title()));
$check($bacsTitle !== '' && in_array($bacsTitle, $pi::paymentMethods(), true), 'Zahlungsarten aus den aktiven Gateways („'.$bacsTitle.'“)');
$noGateways = static fn () => [];
add_filter('woocommerce_available_payment_gateways', $noGateways, 999);
$check(! str_contains(implode(' ', $pi::lines(true)), 'Zahlungsarten'), 'ohne aktive Zahlungsart: keine Zahlungsarten-Zeile');
remove_filter('woocommerce_available_payment_gateways', $noGateways, 999);
update_option('woocommerce_allowed_countries', 'all');
$check($pi::salesCountries() === '' && ! str_contains(implode(' ', $pi::lines(false)), 'Verkaufsländer'), 'Verkauf an alle Länder: keine Länder-Zeile');
$euCh = array_merge(WC()->countries->get_european_union_countries(), ['CH']);
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', $euCh);
$chName = html_entity_decode(WC()->countries->get_countries()['CH'], ENT_QUOTES, 'UTF-8');
$check($pi::salesCountries() === 'EU-Staaten, '.$chName, 'EU und Schweiz: „'.$pi::salesCountries().'“');
update_option('woocommerce_specific_allowed_countries', ['DE', 'AT']);
$check(str_contains($pi::salesCountries(), html_entity_decode(WC()->countries->get_countries()['AT'], ENT_QUOTES, 'UTF-8')) && ! str_contains($pi::salesCountries(), 'EU-Staaten'), 'einzelne Länder namentlich');
update_option('woocommerce_allowed_countries', 'all_except');
update_option('woocommerce_all_except_countries', ['US']);
$check(str_starts_with($pi::salesCountries(), 'alle außer '), 'alle außer …: „'.$pi::salesCountries().'“');
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', $euCh);
// Produktseite (unter dem Kaufen-Knopf; auch im Block „add-to-cart-form“).
$GLOBALS['product'] = wc_get_product($lern);
ob_start();
do_action('woocommerce_after_add_to_cart_form');
$piProduct = (string) ob_get_clean();
$check(str_contains($piProduct, 'class="bp-purchase-info"') && str_contains($piProduct, 'Zahlungsarten: ') && str_contains($piProduct, esc_html($bacsTitle)) && str_contains($piProduct, 'Verkaufsländer: EU-Staaten') && str_contains($piProduct, 'Lieferung: digital'), 'Produktseite: Zahlungsarten, Verkaufsländer, Lieferung digital');
$GLOBALS['product'] = wc_get_product($book);
ob_start();
do_action('woocommerce_after_add_to_cart_form');
$piBook = (string) ob_get_clean();
$check(str_contains($piBook, 'Zahlungsarten: ') && ! str_contains($piBook, 'Lieferung'), 'kein Lernprodukt: keine Aussage zur Lieferung');
unset($GLOBALS['product']);
if (function_exists('wc_load_cart')) {
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($lern);
    $piBlock = (string) apply_filters('render_block_woocommerce/cart', '<div class="wp-block-woocommerce-cart"></div>', ['blockName' => 'woocommerce/cart', 'attrs' => []]);
    ob_start();
    do_action('woocommerce_after_cart_totals');
    $piClassic = (string) ob_get_clean();
    $check(str_contains($piBlock, 'bp-purchase-info') && str_contains($piBlock, 'Zahlungsarten: ') && str_contains($piBlock, 'Lieferung: digital'), 'Warenkorb (Block): Hinweis mit Zahlungsarten und Lieferung');
    $check(str_contains($piClassic, 'bp-purchase-info') && str_contains($piClassic, 'Zahlungsarten: ') && str_contains($piClassic, 'Verkaufsländer: '), 'Warenkorb (klassisch): Hinweis unter der Summe');
    WC()->cart->add_to_cart($book);
    $check(! $pi::cartIsDigital() && ! str_contains((string) apply_filters('render_block_woocommerce/cart', '', []), 'Lieferung'), 'gemischter Warenkorb: keine Aussage zur Lieferung');
    WC()->cart->empty_cart();
}
foreach ($piOptions as $k => $v) {
    $v === '__bp_missing__' ? delete_option($k) : update_option($k, $v);
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
if (class_exists(\Automattic\WooCommerce\Blocks\Package::class)) {
    // Ein festes required=true würde WooCommerce als enum [true] ins Store-API-Schema schreiben – dann scheitern
    // Warenkörbe ohne Lernprodukte (Tickets, Gutscheine), obwohl das Kästchen ausgeblendet ist.
    $fieldsService = \Automattic\WooCommerce\Blocks\Package::container()->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);
    $consentField = $fieldsService->get_additional_fields()[\Bootspruefung\Learning\Commerce\WooCommerce\DigitalConsent::FIELD] ?? null;
    $check(is_array($consentField) && is_array($consentField['required']) && isset($consentField['required']['not']) && $fieldsService->is_conditional_field($consentField), 'Checkout-Block: Zustimmung digitale Inhalte nur bedingt Pflicht (Warenkorb ohne Lernprodukte)');
}

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
// Feste Ereignis-IDs dieses Tests: Merker früherer Läufe entfernen (Transients, 90 Tage).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bp\\_dispute\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_dispute\\_%'");
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

// Pflichtfelder dispute_id und timestamp (Einstellung, T39)
update_option($dw::OPTION, array_merge($dw::settings(), ['generic_strict' => true]));
$strictBody = wp_json_encode(['id' => 'K-3', 'event' => 'opened', 'order_id' => $klarna, 'source' => 'klarna']);
$check($req($strictBody, $sign($strictBody))->get_status() === 400 && $statuses($klarna) === ['active'], 'Pflichtfelder an: ohne dispute_id/timestamp 400, Zugang unverändert');
$strictBody = wp_json_encode(['id' => 'K-4', 'event' => 'opened', 'order_id' => $klarna, 'source' => 'klarna', 'dispute_id' => 'KD-9', 'timestamp' => 'kein Datum']);
$check($req($strictBody, $sign($strictBody))->get_status() === 400, 'Pflichtfelder an: ungültiger timestamp 400');
update_option($dw::OPTION, array_merge($dw::settings(), ['generic_strict' => false]));

echo "Disputes: Reihenfolge nach Ereigniszeit, abgeschlossener Fall endgültig (SEC-04)\n";
$paidOrder = static function (int $customer, string $method, string $tx = '') use ($video): int {
    $order = wc_create_order(['customer_id' => $customer]);
    $order->add_product(wc_get_product($video), 1);
    $order->set_payment_method($method);
    $order->calculate_totals();
    $order->save();
    $order->payment_complete($tx);

    return (int) $order->get_id();
};
$send = static function (array $data) use ($req, $sign) {
    $body = wp_json_encode($data);

    return $req($body, $sign($body));
};
// Gewonnen kommt vor eröffnet an (Zustellverzögerung): der Zugang bleibt aktiv.
$k2 = $paidOrder($bob, 'klarna_payments');
// Ablauf-Erinnerung nur an Käufer (T44): eigene bezahlte Bestellung zählt, 0-€-Bestellung nicht.
$erUser = $customer('wc_kunde_erinnerung');
$erOrder = $paidOrder($erUser, 'bacs');
$erCode = (string) $wpdb->get_var($wpdb->prepare("SELECT course_code FROM {$p}entitlement WHERE order_id = %d LIMIT 1", $erOrder));
$ERm = \Bootspruefung\Learning\Commerce\ExpiryReminder::class;
$check($erCode !== '' && $ERm::boughtCourses($erUser, [$erCode => '2099-01-01 00:00:00']) === [$erCode => '2099-01-01 00:00:00'], 'Erinnerung: eigene bezahlte Bestellung zählt als Kauf');
$zeroOrder = wc_get_order($erOrder);
$zeroOrder->set_total(0);
$zeroOrder->save();
$check($ERm::boughtCourses($erUser, [$erCode => '2099-01-01 00:00:00']) === [], 'Erinnerung: 0-€-Bestellung (z. B. Gutschein) zählt nicht als Kauf');
$r = $send(['id' => 'K2-won', 'event' => 'won', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-1', 'timestamp' => '2026-11-10T12:00:00Z']);
$check($r->get_status() === 200 && $statuses($k2) === ['active'], '„gewonnen“ vor „eröffnet“: Zugang aktiv');
$r = $send(['id' => 'K2-open', 'event' => 'opened', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-1', 'timestamp' => '2026-11-01T12:00:00Z']);
$check($statuses($k2) === ['active'] && ($r->get_data()['ignored'] ?? []) === [$k2], 'verspätetes „eröffnet“ (älter) wird ignoriert');
$send(['id' => 'K2-open-neu', 'event' => 'opened', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-1', 'timestamp' => '2026-11-11T12:00:00Z']);
$check($statuses($k2) === ['active'], 'auch ein neueres „eröffnet“ öffnet den abgeschlossenen Fall nicht wieder');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}audit_log WHERE action = 'dispute_ignored' AND object_id = %s", (string) $k2)), 'ignoriertes Ereignis protokolliert');
// Wiederholung nach Ablauf des Transients (z. B. nicht persistenter Object-Cache): Bestellung kennt das Ereignis.
delete_transient('bp_dispute_'.md5('klarna|K2-won'));
$check(! empty($send(['id' => 'K2-won', 'event' => 'won', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-1'])->get_data()['duplicate']), 'Ereignis-ID dauerhaft in der Bestellung (nicht nur im Transient)');
// Ergebnis gegen Ergebnis: das jüngere gilt, ein älteres wird ignoriert.
$send(['id' => 'K2-lost-alt', 'event' => 'lost', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-1', 'timestamp' => '2026-11-05T12:00:00Z']);
$check($statuses($k2) === ['active'], 'älteres „verloren“ ändert ein jüngeres „gewonnen“ nicht');
// Neuer Konfliktfall derselben Bestellung (eigene dispute_id): wird normal ausgewertet.
$send(['id' => 'K2-open-2', 'event' => 'opened', 'order_id' => $k2, 'source' => 'klarna', 'dispute_id' => 'KD-2', 'timestamp' => '2026-11-12T08:00:00Z']);
$check($statuses($k2) === ['suspended'], 'neuer Fall (andere dispute_id) setzt aus');
// Ohne ID: Duplikat trotz anderer Leerzeichen/Schlüsselreihenfolge.
$k3 = $paidOrder($bob, 'klarna_payments');
$b1 = '{"event":"opened","order_id":'.$k3.',"source":"klarna-ohne-id"}';
$b2 = '{ "source": "klarna-ohne-id",  "order_id": '.$k3.', "event": "opened" }';
$req($b1, $sign($b1));
$check(! empty($req($b2, $sign($b2))->get_data()['duplicate']), 'ohne ID: gleiche Nachricht mit anderen Leerzeichen ist ein Duplikat');
// PayPal: RESOLVED vor CREATED, UPDATED mit status RESOLVED wird als Ergebnis gewertet.
add_filter('bootspruefung_paypal_verify', '__return_true');
$pp2 = $paidOrder($alice, 'ppcp-gateway', 'CAPTURE-'.wp_generate_password(6, false));
$capture2 = wc_get_order($pp2)->get_transaction_id();
$ppEvent = static fn (string $id, string $type, string $time, string $outcome = '', string $status = '') => ['id' => $id, 'event_type' => $type, 'create_time' => $time,
    'resource' => ['dispute_id' => 'PP-D-2', 'update_time' => $time, 'status' => $status, 'disputed_transactions' => [['seller_transaction_id' => $capture2]], 'dispute_outcome' => ['outcome_code' => $outcome]]];
$postHook('paypal', $ppEvent('WH-R', 'CUSTOMER.DISPUTE.RESOLVED', '2026-11-10T10:00:00Z', 'RESOLVED_SELLER_FAVOUR', 'RESOLVED'));
$postHook('paypal', $ppEvent('WH-C', 'CUSTOMER.DISPUTE.CREATED', '2026-11-01T10:00:00Z'));
$postHook('paypal', $ppEvent('WH-U', 'CUSTOMER.DISPUTE.UPDATED', '2026-11-11T10:00:00Z', '', 'UNDER_REVIEW'));
$check($statuses($pp2) === ['active'], 'PayPal: RESOLVED vor CREATED/UPDATED – Zugang bleibt aktiv');
$check($dw::paypalAction(['event_type' => 'CUSTOMER.DISPUTE.UPDATED', 'resource' => ['status' => 'RESOLVED', 'dispute_outcome' => ['outcome_code' => 'RESOLVED_BUYER_FAVOUR']]]) === 'lost'
    && $dw::paypalAction(['event_type' => 'CUSTOMER.DISPUTE.UPDATED', 'resource' => ['status' => 'WAITING_FOR_SELLER_RESPONSE']]) === 'opened', 'PayPal: UPDATED nach resource.status ausgewertet');
remove_filter('bootspruefung_paypal_verify', '__return_true');

echo "PayPal: echte Signaturprüfung über verify-webhook-signature (T64)\n";
// HTTP-Antworten von PayPal über pre_http_request vorgeben: Token, dann SUCCESS / FAILURE / Netzwerkfehler.
wp_set_current_user(1);
update_option($dw::OPTION, ['paypal_client_id' => 'cid', 'paypal_secret' => 'sec', 'paypal_webhook_id' => 'WH-ID-1', 'paypal_mode' => 'sandbox', 'generic_secret' => 'test-secret']);
delete_option($dw::OPTION_LAST_VERIFY);
foreach (['bp_paypal_verify_calls', 'bp_webhook_rejected_paypal', 'bp_webhook_rejected_paypal_unconfigured'] as $t) {
    delete_transient($t);
}
$dropTokens = static function (): void {
    foreach (['sandbox', 'live'] as $mode) {
        delete_transient('bp_paypal_token_'.md5($mode.'|cid'));
    }
};
$dropTokens();
$auditMax = (int) $wpdb->get_var("SELECT MAX(id) FROM {$p}audit_log");
$ppHttp = ['verify' => 'SUCCESS', 'token_error' => false, 'requests' => []];
$ppMock = static function ($pre, $args, $url) use (&$ppHttp) {
    if (! str_contains((string) $url, 'paypal.com/')) {
        return $pre;
    }
    $ppHttp['requests'][] = ['url' => (string) $url, 'args' => $args];
    $json = static fn (array $data, int $code = 200) => ['headers' => [], 'body' => (string) wp_json_encode($data), 'response' => ['code' => $code, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    if (str_ends_with((string) $url, '/v1/oauth2/token')) {
        return $ppHttp['token_error'] ? new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host') : $json(['access_token' => 'TOKEN-1', 'token_type' => 'Bearer', 'expires_in' => 32400]);
    }
    if ($ppHttp['verify'] === 'network') {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    }

    return $json(['verification_status' => $ppHttp['verify']]);
};
add_filter('pre_http_request', $ppMock, 10, 3);
$ppHeaders = static fn (string $tid) => ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1', 'paypal-transmission-id' => $tid,
    'paypal-transmission-sig' => 'c2lnbmF0dXI=', 'paypal-transmission-time' => '2026-11-13T10:00:00Z'];
$pp3 = $paidOrder($alice, 'ppcp-gateway', 'CAPTURE-'.wp_generate_password(6, false));
$capture3 = wc_get_order($pp3)->get_transaction_id();
$ppEvent3 = static fn (string $id, string $type, string $outcome = '', string $case = 'PP-D-3') => ['id' => $id, 'event_type' => $type, 'create_time' => '2026-11-13T10:00:00Z',
    'resource' => ['dispute_id' => $case, 'reason' => 'Ware nicht erhalten – „Prüfung“', 'amount' => ['value' => 19.9], 'disputed_transactions' => [['seller_transaction_id' => $capture3]], 'dispute_outcome' => ['outcome_code' => $outcome]]];

// Erfolgreich: Zugang ausgesetzt; Anfrage an die Sandbox mit Token, Webhook-ID, Kopfzeilen und unverändertem Rohtext.
$rawEvent = (string) wp_json_encode($ppEvent3('WH-S-1', 'CUSTOMER.DISPUTE.CREATED'), JSON_UNESCAPED_UNICODE);
$request = new WP_REST_Request('POST', '/bootspruefung/v1/webhooks/paypal');
$request->set_body($rawEvent);
$request->set_header('content-type', 'application/json');
foreach ($ppHeaders('TX-1') as $k => $v) {
    $request->set_header($k, $v);
}
$r = rest_do_request($request);
$check($r->get_status() === 200 && $statuses($pp3) === ['suspended'], 'PayPal-Prüfung SUCCESS: Nachricht verarbeitet, Zugang ausgesetzt');
$tokenReq = $ppHttp['requests'][0] ?? null;
$verifyReq = $ppHttp['requests'][1] ?? null;
$check($tokenReq !== null && $tokenReq['url'] === 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
    && ($tokenReq['args']['headers']['Authorization'] ?? '') === 'Basic '.base64_encode('cid:sec'), 'OAuth-Token mit Client-ID/Secret bei der Sandbox geholt');
$sent = json_decode((string) ($verifyReq['args']['body'] ?? ''), true);
$check($verifyReq !== null && $verifyReq['url'] === 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature'
    && ($verifyReq['args']['headers']['Authorization'] ?? '') === 'Bearer TOKEN-1'
    && ($sent['webhook_id'] ?? '') === 'WH-ID-1' && ($sent['transmission_id'] ?? '') === 'TX-1' && ($sent['auth_algo'] ?? '') === 'SHA256withRSA'
    && ($sent['cert_url'] ?? '') === 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1' && ($sent['transmission_sig'] ?? '') === 'c2lnbmF0dXI=' && ($sent['transmission_time'] ?? '') === '2026-11-13T10:00:00Z',
    'verify-webhook-signature: Webhook-ID aus den Einstellungen und alle PAYPAL-Kopfzeilen');
$check(str_contains((string) ($verifyReq['args']['body'] ?? ''), '"webhook_event":'.$rawEvent), 'webhook_event als unveränderter Rohtext (Umlaute, Zahlen) an PayPal');
$check(($dw::paypalStatus()['last']['result'] ?? '') === 'success' && str_contains($dw::paypalStatusText(), 'erfolgreich'), 'Ergebnis der letzten Prüfung für Einstellungen/Systemstatus gemerkt');

// Token wird wiederverwendet (keine zweite Token-Anfrage).
$ppHttp['requests'] = [];
$postHook('paypal', $ppEvent3('WH-S-2', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR'), $ppHeaders('TX-2'));
$check($statuses($pp3) === ['active'] && count($ppHttp['requests']) === 1 && str_ends_with($ppHttp['requests'][0]['url'], '/verify-webhook-signature'), 'zweite Nachricht: Token aus dem Zwischenspeicher, gewonnen → wieder aktiv');

// FAILURE: 401, nichts verarbeitet, Audit-Log (neuer Konfliktfall PP-D-4; PP-D-3 ist gewonnen und damit abgeschlossen).
$ppHttp['verify'] = 'FAILURE';
$r = $postHook('paypal', $ppEvent3('WH-F-1', 'CUSTOMER.DISPUTE.CREATED', '', 'PP-D-4'), $ppHeaders('TX-3'));
$check($r->get_status() === 401 && $statuses($pp3) === ['active'], 'PayPal-Prüfung FAILURE: 401, Zugang unverändert');
$check((bool) $wpdb->get_var("SELECT 1 FROM {$p}audit_log WHERE action = 'dispute_webhook_rejected' AND object_id = 'paypal' AND context LIKE '%WH-F-1%failure%' AND id > {$auditMax}"), 'FAILURE im Audit-Log');
$check(! wc_get_order($pp3)->get_meta($dw::META_EVENTS) || ! in_array(md5('paypal|WH-F-1'), (array) wc_get_order($pp3)->get_meta($dw::META_EVENTS), true), 'abgewiesenes Ereignis nicht als verarbeitet vermerkt');
$check(($dw::paypalStatus()['last']['result'] ?? '') === 'failure', 'letzte Prüfung: abgelehnt');
// Dieselbe Nachricht nach erfolgreicher Prüfung (PayPal stellt erneut zu) wird normal verarbeitet.
$ppHttp['verify'] = 'SUCCESS';
$postHook('paypal', $ppEvent3('WH-F-1', 'CUSTOMER.DISPUTE.CREATED', '', 'PP-D-4'), $ppHeaders('TX-3'));
$check($statuses($pp3) === ['suspended'], 'nach Ablehnung erneut zugestellt und geprüft: verarbeitet');

// Netzwerkfehler bei der Prüfung: 503 (PayPal stellt erneut zu), nichts verarbeitet.
$ppHttp['verify'] = 'network';
$r = $postHook('paypal', $ppEvent3('WH-N-1', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR', 'PP-D-4'), $ppHeaders('TX-4'));
$check($r->get_status() === 503 && $statuses($pp3) === ['suspended'], 'Netzwerkfehler bei verify-webhook-signature: 503, Zugang unverändert');
$check(($dw::paypalStatus()['last']['result'] ?? '') === 'error', 'letzte Prüfung: PayPal nicht erreichbar');
// Netzwerkfehler beim Token: ebenfalls 503.
$dropTokens();
$ppHttp['verify'] = 'SUCCESS';
$ppHttp['token_error'] = true;
$r = $postHook('paypal', $ppEvent3('WH-N-2', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR', 'PP-D-4'), $ppHeaders('TX-5'));
$check($r->get_status() === 503 && $statuses($pp3) === ['suspended'], 'Netzwerkfehler beim Token: 503, Zugang unverändert');
$ppHttp['token_error'] = false;

// Ohne PayPal-Kopfzeilen: 401 ohne Anfrage bei PayPal.
$ppHttp['requests'] = [];
$r = $postHook('paypal', $ppEvent3('WH-H-1', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR', 'PP-D-4'));
$check($r->get_status() === 401 && $ppHttp['requests'] === [] && $statuses($pp3) === ['suspended'], 'ohne PAYPAL-Kopfzeilen: 401, keine Anfrage bei PayPal');

// Live-Umgebung: api-m.paypal.com.
update_option($dw::OPTION, array_merge($dw::settings(), ['paypal_mode' => 'live']));
$ppHttp['requests'] = [];
$postHook('paypal', $ppEvent3('WH-L-1', 'CUSTOMER.DISPUTE.RESOLVED', 'RESOLVED_SELLER_FAVOUR', 'PP-D-4'), $ppHeaders('TX-6'));
$check(($ppHttp['requests'][0]['url'] ?? '') === 'https://api-m.paypal.com/v1/oauth2/token' && $statuses($pp3) === ['active'], 'Live-Einstellung: Anfrage an api-m.paypal.com');

// Nicht eingerichtet: wie bisher 503, nichts verarbeitet; Systemstatus und Einstellungen zeigen „Signatur nicht geprüft“.
update_option($dw::OPTION, array_merge($dw::settings(), ['paypal_webhook_id' => '']));
$ppHttp['requests'] = [];
$r = $postHook('paypal', $ppEvent3('WH-U-1', 'CUSTOMER.DISPUTE.CREATED', '', 'PP-D-4'), $ppHeaders('TX-7'));
$check($r->get_status() === 503 && $ppHttp['requests'] === [] && $statuses($pp3) === ['active'], 'ohne Webhook-ID: 503 wie bisher, nichts verarbeitet');
$check(! $dw::paypalStatus()['verified'] && $dw::paypalStatus()['missing'] === ['Webhook-ID'] && str_contains($dw::paypalStatusText(), 'Signatur nicht geprüft'), 'Status: „Signatur nicht geprüft“, fehlende Angabe benannt');
ob_start();
$dw::renderStatus();
$statusHtml = (string) ob_get_clean();
$check(str_contains($statusHtml, 'Zahlungskonflikte') && str_contains($statusHtml, 'Signatur nicht geprüft'), 'Systemstatus: Abschnitt „Zahlungskonflikte“ mit „Signatur nicht geprüft“');
ob_start();
$dw::renderSettings();
$check(str_contains((string) ob_get_clean(), 'Signatur nicht geprüft'), 'Einstellungen zeigen „Signatur nicht geprüft“');
$check(\Bootspruefung\Learning\Admin\SystemStatus::collect()['disputes']['paypal_signature_verified'] === false, 'Systemstatus (REST/CLI): paypal_signature_verified = false');
remove_filter('pre_http_request', $ppMock, 10);

echo "Klarna-Konflikt von Hand melden (Bestellaktion, T65)\n";
$k4 = $paidOrder($bob, 'klarna_payments');
$check(isset(apply_filters('woocommerce_order_actions', [], wc_get_order($k4))['bp_dispute_klarna_opened']), 'Bestellaktion „Klarna-Konflikt melden“ bei Klarna-Bestellung');
$check(! isset(apply_filters('woocommerce_order_actions', [], wc_get_order($pp3))['bp_dispute_klarna_opened']), 'nicht bei anderen Zahlungsarten');
ob_start();
do_action('woocommerce_order_actions_end', $k4);
$check(str_contains((string) ob_get_clean(), 'bp_dispute_case'), 'Feld „Klarna-Fall-ID“ bei Klarna-Bestellung');
\WC_Admin_Meta_Boxes::$meta_box_errors = [];
unset($_POST['bp_access_reason'], $_POST['bp_dispute_case']);
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k4));
$check($statuses($k4) === ['active'] && \WC_Admin_Meta_Boxes::$meta_box_errors !== [], 'ohne Begründung: nichts geändert, Hinweis im Admin');
$_POST['bp_access_reason'] = 'Kundin hat bei Klarna reklamiert';
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k4));
$check($statuses($k4) === ['suspended'], 'eröffnet: Zugang ausgesetzt');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}audit_log WHERE action = 'dispute_opened' AND object_id = %s AND actor_user_id = 1 AND reason LIKE %s", (string) $k4, '%Klarna-Konflikt eröffnet (Kundin hat bei Klarna reklamiert)%')), 'Audit-Log mit Begründung und handelnder Person');
$notes = implode("\n", array_map(static fn ($n) => $n->content, wc_get_order_notes(['order_id' => $k4])));
$check(str_contains($notes, 'Klarna-Konflikt eröffnet (Kundin hat bei Klarna reklamiert)'), 'Bestellnotiz wie beim Webhook');
$state = wc_get_order($k4)->get_meta($dw::META_STATE);
$check(($state['klarna|']['status'] ?? '') === 'opened', 'Stand des Falls wie beim allgemeinen Webhook (Quelle klarna, ohne Fall-ID)');
$_POST['bp_access_reason'] = 'Klarna hat zu unseren Gunsten entschieden';
do_action('woocommerce_order_action_bp_dispute_klarna_won', wc_get_order($k4));
$check($statuses($k4) === ['active'], 'gewonnen: Zugang wiederhergestellt');
// Abgeschlossener Fall: ein weiteres „eröffnet“ ohne Fall-ID wird nicht angewendet (wie beim Webhook), Hinweis im Admin.
\WC_Admin_Meta_Boxes::$meta_box_errors = [];
$_POST['bp_access_reason'] = 'neuer Konflikt';
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k4));
$check($statuses($k4) === ['active'] && str_contains(implode(' ', \WC_Admin_Meta_Boxes::$meta_box_errors), 'Klarna-Fall-ID'), 'abgeschlossener Fall: „eröffnet“ ignoriert, Hinweis auf Fall-ID');
// Mit eigener Fall-ID: neuer Fall, dann verloren → widerrufen.
$_POST['bp_dispute_case'] = 'KL-777';
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k4));
$check($statuses($k4) === ['suspended'], 'neuer Fall mit Klarna-Fall-ID: ausgesetzt');
// Gleiche Fall-ID über den allgemeinen Webhook: derselbe Fall (ein „eröffnet“ ändert nichts mehr).
$r = $send(['id' => 'K4-open-webhook', 'event' => 'opened', 'order_id' => $k4, 'source' => 'klarna', 'dispute_id' => 'KL-777']);
$state = wc_get_order($k4)->get_meta($dw::META_STATE);
$check($r->get_status() === 200 && $statuses($k4) === ['suspended'] && ($state['klarna|KL-777']['status'] ?? '') === 'opened'
    && str_starts_with((string) ($state['klarna|KL-777']['event'] ?? ''), 'admin-') && count($state) === 2, 'Webhook mit derselben Fall-ID: derselbe Fall (kein zweiter Fall)');
\WC_Admin_Meta_Boxes::$meta_box_errors = [];
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k4));
$check(str_contains(implode(' ', \WC_Admin_Meta_Boxes::$meta_box_errors), 'bereits gemeldet'), 'doppelte Meldung: Hinweis „bereits gemeldet“');
$_POST['bp_access_reason'] = 'Klarna hat für die Kundin entschieden';
do_action('woocommerce_order_action_bp_dispute_klarna_lost', wc_get_order($k4));
$check($statuses($k4) === ['revoked'], 'verloren: Zugang widerrufen');
$check((bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$p}audit_log WHERE action = 'dispute_lost' AND object_id = %s", (string) $k4)), 'verloren im Audit-Log');
// Ohne Recht „Zugänge verwalten“: keine Aktion, keine Wirkung.
$k5 = $paidOrder($bob, 'klarna_payments');
wp_set_current_user($alice);
$check(! isset(apply_filters('woocommerce_order_actions', [], wc_get_order($k5))['bp_dispute_klarna_opened']), 'ohne Recht: keine Klarna-Aktion');
do_action('woocommerce_order_action_bp_dispute_klarna_opened', wc_get_order($k5));
$check($statuses($k5) === ['active'], 'ohne Recht: Zugang unverändert');
wp_set_current_user(1);
unset($_POST['bp_access_reason'], $_POST['bp_dispute_case']);
\WC_Admin_Meta_Boxes::$meta_box_errors = [];
update_option($dw::OPTION, []);

echo "Automatischer Abgleich verpasster Bestellereignisse (ARC-03)\n";
$AR = \Bootspruefung\Learning\Commerce\WooCommerce\AutoReconcile::class;
$missed = [$paidOrder($alice, 'bacs'), $paidOrder($bob, 'bacs')];
foreach ($missed as $id) {
    $o = wc_get_order($id);
    $o->set_status('processing');
    $o->save();
}
// Plugin war „aus“: die Zugänge zu diesen Bestellungen fehlen.
$wpdb->query("DELETE FROM {$p}entitlement WHERE order_id IN (".implode(',', $missed).')');
$firstModified = min(array_map(static fn ($id) => wc_get_order($id)->get_date_modified()->getTimestamp(), $missed));
// Stand direkt vor diese Bestellungen setzen (ältere Testbestellungen derselben Sekunde haben kleinere IDs).
update_option($AR::OPTION_CURSOR, ['time' => $firstModified, 'id' => min($missed) - 1], false);
$later = time() + 10 * MINUTE_IN_SECONDS; // nach der Wartezeit für gerade geänderte Bestellungen
$stats = $AR::run(1, $later);
$check(is_array($stats) && $stats['checked'] === 1 && $stats['more'] && $statuses($missed[0]) === ['active'] && $statuses($missed[1]) === [], 'Abgleich je Lauf begrenzt: erste verpasste Bestellung nachgezogen');
$stats = $AR::run(10, $later);
$check(is_array($stats) && $statuses($missed[1]) === ['active'] && ! $stats['more'], 'nächster Lauf zieht den Rest nach');
$check((int) (get_option($AR::OPTION_CURSOR)['time'] ?? 0) === $later - $AR::SETTLE, 'Stand rückt bis zur Wartezeit vor');
$check($AR::run(10, $later)['checked'] === 0, 'ohne neue Änderungen nichts zu tun');
$check(has_action('bp_learning_hourly', [\Bootspruefung\Learning\Plugin::class, 'hourly']) !== false, 'stündlicher Wartungslauf registriert (enthält den Abgleich)');
\Bootspruefung\Learning\Plugin::deactivate();
$check((int) get_option($AR::OPTION_DEACTIVATED) > 0, 'Deaktivierung gemerkt');
\Bootspruefung\Learning\Plugin::activate();
$check(wp_next_scheduled($AR::HOOK) !== false, 'nach der Aktivierung: Abgleich in Kürze geplant');
wp_clear_scheduled_hook($AR::HOOK);
delete_option($AR::OPTION_DEACTIVATED);
delete_option($AR::OPTION_CURSOR);

// T47: mehr als eine Seite bereits abgeglichener Bestellungen in derselben Sekunde – der Lauf darf nicht hängen bleiben.
$setModified = static function (array $ids, int $ts) use ($wpdb): void {
    $gmt = gmdate('Y-m-d H:i:s', $ts);
    $in = implode(',', array_map('intval', $ids));
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'")) {
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_orders SET date_updated_gmt = %s WHERE id IN ({$in})", $gmt));
    }
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_modified_gmt = %s, post_modified = %s WHERE ID IN ({$in})", $gmt, $gmt));
    wp_cache_flush();
};
$sameSecond = [];
for ($i = 0; $i < 53; $i++) {
    $bulk = wc_create_order();
    $sameSecond[] = (int) $bulk->get_id();
}
$stuckFresh = $paidOrder($alice, 'bacs');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE order_id = %d", $stuckFresh));
$T = time() - HOUR_IN_SECONDS;
$setModified($sameSecond, $T);
$setModified([$stuckFresh], $T + 1);
update_option($AR::OPTION_CURSOR, ['time' => $T, 'id' => max($sameSecond)], false);
$stats = $AR::run(1, time() + 10 * MINUTE_IN_SECONDS);
$check(is_array($stats) && $stats['checked'] === 1 && $statuses($stuckFresh) === ['active'], '53 bereits abgeglichene Bestellungen in derselben Sekunde: Lauf blättert weiter und zieht die nächste nach (T47)');
// T47: fehlgeschlagene Bestellung wird in späteren Läufen erneut versucht.
$failing = $paidOrder($bob, 'bacs');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE order_id = %d", $failing));
$failTs = time() - 20 * MINUTE_IN_SECONDS;
$setModified([$failing], $failTs);
update_option($AR::OPTION_CURSOR, ['time' => $failTs, 'id' => $failing - 1], false); // direkt vor die Testbestellung
$boom = static function ($target, $order) use ($failing) {
    if (is_object($order) && (int) ($order->id ?? 0) === $failing) {
        throw new RuntimeException('Testfehler');
    }

    return $target;
};
add_filter('bootspruefung_order_access_target', $boom, 10, 2);
$stats = $AR::run(1, time());
$check(is_array($stats) && $stats['failed'] >= 1 && isset($AR::retryList()[$failing]), 'Fehler beim Abgleich: Bestellung kommt auf die Wiederholungsliste');
remove_filter('bootspruefung_order_access_target', $boom, 10);
$stats = $AR::run(1, time());
$check(! isset($AR::retryList()[$failing]) && $statuses($failing) === ['active'] && ($stats['retried'] ?? 0) >= 1, 'nächster Lauf wiederholt sie erfolgreich und nimmt sie von der Liste');
$check(is_array(get_option($AR::OPTION_LAST_RUN)) && isset(get_option($AR::OPTION_LAST_RUN)['at']), 'letzter Lauf gemerkt (Wartungsanzeige)');
foreach ($sameSecond as $bulkId) {
    wc_get_order($bulkId)?->delete(true);
}
delete_option($AR::OPTION_CURSOR);
delete_option($AR::OPTION_RETRY);

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
$catalogRows = array_filter($rows, static fn ($r) => ! str_starts_with((string) $r['action'], 'deaktiviert'));
$catalogImages = static fn (): int => count(get_posts(['post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image']));
$imagesAfterFirst = $catalogImages();
$check(count($catalogRows) === 59 && $kombi && $kombi->get_regular_price() === '60.80' && $kombi->get_status() === 'draft' && $kombi->is_virtual(), 'Katalog: 59 Produkte, Kombi 60,80 €, Entwurf, virtuell');
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
$check($imagesAfterFirst >= 59 && $catalogImages() === $imagesAfterFirst, 'Bilder nur einmal importiert');
$check(wc_get_product($byS['BP-SBF-SEE-VIDEO']['product_id'])->get_catalog_visibility() === 'hidden' && wc_get_product($byS['BP-SBF-SEE-KOMPLETT']['product_id'])->get_catalog_visibility() === 'visible' && \Bootspruefung\Learning\Commerce\MoneyBack::policy($byS['BP-SBF-SEE-VIDEO']['product_id']) !== null, 'Video-Upgrade nicht gelistet, mit Geld-zurück-Garantie; Komplett im Shop');
// Sperrhinweis/Testkurs „Vollzugang freischalten“: Kurs vor Pässen, auch wenn der 1-Monats-Pass billiger ist.
$seeOffers = array_column(Integration::offers('SBF-SEE', ['question_bank' => false])['question_bank'] ?? [], 'url');
$passAt = array_search(get_permalink($byS['BP-PASS-1M']['product_id']), $seeOffers, true);
$check(($seeOffers[0] ?? '') === get_permalink($byS['BP-SBF-SEE']['product_id']) && $passAt !== false && $passAt > array_search(get_permalink($byS['BP-SBF-SEE-BIN']['product_id']), $seeOffers, true), 'Angebote SBF See: Einzelkurs zuerst, Kombi vor Pass (Pass 16,90 € < Kurs 28,90 €)');
// Mobile Warenkorb-Tabelle: Hinweis-Zeile ohne „: “-Beschriftung von WooCommerce.
if (! wp_style_is('woocommerce-smallscreen', 'registered')) {
    wp_register_style('woocommerce-smallscreen', false);
}
(new \Bootspruefung\Learning\Commerce\WooCommerce\PriceNote())->smallScreenStyle();
$check(str_contains(implode('', (array) wp_styles()->get_data('woocommerce-smallscreen', 'after')), 'tr.bp-price-note-row td::before{display:none}'), 'Preis-Hinweis im mobilen Warenkorb ohne Doppelpunkt-Beschriftung');
$fam = wc_get_product($byS['GRP-FAM-02']['product_id']);
$famRows = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->forItem($fam->get_id(), 0);
$check($fam->get_regular_price() === '148.90' && \Bootspruefung\Learning\Commerce\ProductAccessRepository::seats($famRows) === 3 && \Bootspruefung\Learning\Commerce\MoneyBack::policy($fam->get_id()) === null && ! $fam->is_sold_individually(), 'Familien-Flotte: 3 Plätze, 148,90 €, ohne Geld-zurück-Garantie');
$check(wc_get_product($byS['GRP-CLUB-50']['product_id'])->get_regular_price() === '1725.00', 'Partner-Flat 50: 1.725,00 €');
// Verlängern für Gruppenmitglieder (T44): nie das Gruppenpaket, sondern ein Einzelangebot des Kurses.
$grpRenew = \Bootspruefung\Learning\Commerce\AccessStatus::renewUrl('SBF-SEE', ['sku' => 'GRP-PAIR-01']);
parse_str((string) wp_parse_url($grpRenew, PHP_URL_QUERY), $grpQuery);
$grpPost = get_page_by_path((string) ($grpQuery['product'] ?? basename((string) wp_parse_url($grpRenew, PHP_URL_PATH))), OBJECT, 'product');
$grpRenewId = $grpPost ? (int) $grpPost->ID : url_to_postid($grpRenew);
$grpRenewSku = $grpRenewId ? (string) wc_get_product($grpRenewId)->get_sku() : '';
$check($grpRenewId > 0 && ! str_starts_with($grpRenewSku, 'GRP-'), 'Gruppenmitglied: Verlängern führt zum Einzelangebot, nicht zum Gruppenpaket ('.$grpRenewSku.')');
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
    $vPrevUser = get_current_user_id();
    wp_set_current_user(0);
    $check(str_contains((string) apply_filters('render_block_woocommerce/checkout', '<div class="wc-block-checkout"></div>'), 'bp-video-upgrade'), 'Gast: Video-Angebot auch in der Kasse (Block)');
    ob_start();
    (new \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade())->classicBox();
    $check(str_contains((string) ob_get_clean(), 'bp-video-upgrade'), 'Gast: Video-Angebot auch in der klassischen Kasse');
    wp_set_current_user($vPrevUser);
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
    $mark = static fn ($url) => $url.(str_contains((string) $url, '?') ? '&' : '?').'partner=TEST';
    add_filter('bootspruefung_shop_offer_url', $mark);
    $offerP = \Bootspruefung\Learning\Commerce\WooCommerce\VideoUpgrade::offerFor($vidUser, 'SBF-SEE');
    $links = \Bootspruefung\Learning\Mobile\Controllers\AccountController::links();
    remove_filter('bootspruefung_shop_offer_url', $mark);
    $check(str_ends_with((string) ($offerP['url'] ?? ''), 'partner=TEST') && str_ends_with((string) ($links['shop'] ?? ''), 'partner=TEST'), 'Video-Upgrade und Shop-Link der App laufen durch bootspruefung_shop_offer_url (Partnercode)');
    $wpdb->delete($wpdb->prefix.'bp_entitlement', ['reference_key' => 'video-test-'.$vidUser]);
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(0);
}
echo "Hinweis im Warenkorb: Kurs durch besessenen Kurs abgedeckt\n";
$crh = \Bootspruefung\Learning\Commerce\WooCommerce\CartRelationHint::class;
$h = $crh::evaluate(['SBF-BIN-MOTOR'], 'BP-SBF-SEE-BIN');
$check($h !== null && $h['owned'] === 'SBF-BIN-MOTOR' && $h['alternative'] === 'ADD-BIN-TO-SEE' && $h['alternative_skus'] === ['BP-ADD-BIN-TO-SEE-KOMPLETT', 'BP-ADD-BIN-TO-SEE'], 'Binnen + Kombi See & Binnen → Ergänzung Binnen → See (Komplett, Kombi enthält Video)');
$check($h !== null && $crh::message($h, true) === 'Du hast SBF Binnen schon – dafür reicht die Ergänzung Binnen → See.', 'Text: „Du hast SBF Binnen schon – dafür reicht die Ergänzung Binnen → See.“');
$check($h !== null && $crh::message($h, false) === 'Du hast SBF Binnen schon – SBF See & Binnen enthält ihn noch einmal. Dafür reicht eigentlich die Ergänzung Binnen → See (derzeit nicht im Shop).', 'Ergänzung nicht im Shop: kein „schon enthalten“, sondern Hinweis auf die Ergänzung');
$h = $crh::evaluate(['SRC'], 'BP-SRC-UBI');
$check($h !== null && $h['alternative'] === 'ADD-UBI-TO-SRC' && $h['alternative_skus'][0] === 'BP-ADD-UBI-TO-SRC-KOMPLETT', 'SRC + SRC & UBI → Ergänzung SRC → UBI');
$h = $crh::evaluate(['UBI'], 'BP-SRC-UBI');
$check($h !== null && $h['alternative'] === 'SRC' && $h['alternative_skus'][0] === 'BP-SRC-KOMPLETT' && $crh::message($h, true) === 'Du hast UBI Vollkurs schon – dafür reicht der SRC Vollkurs.', 'UBI + SRC & UBI → SRC Vollkurs');
$h = $crh::evaluate(['SBF-SEE-BIN'], 'BP-SBF-SEE-KOMPLETT');
$check($h !== null && $h['course'] === 'SBF-SEE' && $h['alternative'] === null && $crh::message($h, false) === 'Du hast SBF See & Binnen schon – der Inhalt von SBF See ist darin schon enthalten.', 'Kombi + SBF See (Komplett-Stufe): Hinweis ohne Alternative');
$h = $crh::evaluate(['ADD-BIN-TO-SEE', 'SBF-BIN-MOTOR'], 'BP-SBF-SEE-BIN');
$check($h !== null && $h['alternative'] === null && $crh::message($h, false) === 'Du hast SBF Binnen und Ergänzung Binnen → See schon – der Inhalt von SBF See & Binnen ist darin schon enthalten.', 'Binnen + Ergänzung + Kombi: Text nennt beide besessenen Kurse');
$check($crh::evaluate(['SBF-BIN-MOTOR', 'SBF-SEE-BIN'], 'BP-SBF-SEE-BIN') === null && $crh::evaluate(['SBF-BIN-MOTOR'], 'BP-SBF-BIN') === null && $crh::evaluate(['SRC'], 'BP-SRC-KOMPLETT') === null, 'Verlängerung (Kurs selbst besessen): kein Hinweis');
$check($crh::evaluate(['SBF-BIN-MOTOR'], 'BP-KAPITAEN') === null && $crh::evaluate(['SBF-BIN-MOTOR'], 'GRP-PAIR-03') === null && $crh::evaluate(['SBF-SEE-BIN'], 'BP-SBF-SEE-VIDEO') === null && $crh::evaluate([], 'BP-SBF-SEE-BIN') === null, 'kein Hinweis für Bundle, Gruppenpaket, Video-Upgrade oder ohne Besitz');
if (function_exists('wc_load_cart')) {
    $hintUser = $customer('wc_hinweis_kunde');
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $hintUser));
    $now = current_time('mysql', true);
    $wpdb->insert($p.'entitlement', ['user_id' => $hintUser, 'course_code' => 'SBF-BIN-MOTOR', 'feature_code' => 'question_bank', 'status' => 'active', 'valid_from' => $now, 'valid_until' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'source' => 'manual', 'reference_key' => 'hint-test-'.$hintUser, 'created_at' => $now, 'updated_at' => $now]);
    wp_set_current_user($hintUser);
    wc_load_cart();
    WC()->cart->empty_cart();
    wc_clear_notices();
    $kombiId = (int) $byS['BP-SBF-SEE-BIN']['product_id'];
    $addId = (int) $byS['BP-ADD-BIN-TO-SEE-KOMPLETT']['product_id'];
    $kKey = (string) WC()->cart->add_to_cart($kombiId);
    $hBox = $crh::box(wc_get_cart_url());
    $check(str_contains($hBox, 'Du hast SBF Binnen schon – dafür reicht die Ergänzung Binnen → See.') && str_contains($hBox, 'Stattdessen Ergänzung Binnen → See') && str_contains($hBox, 'bp-swap='.$kKey) && str_contains($hBox, 'bp-upgrade'), 'Warenkorb: Box mit Text und Tausch-Knopf');
    $check(substr_count($crh::box(), 'bp-relation-swap') === 1, 'je Position höchstens ein Hinweis');
    $check(str_contains($hBox, 'class="button wp-element-button bp-upgrade-add bp-relation-swap"'), 'Tausch-Knopf als Knopf (Block-Theme-Klasse), nicht als Textlink');
    $rendered = (string) apply_filters('render_block_woocommerce/checkout', '<div class="wc-block-checkout"></div>');
    $check(str_contains($rendered, 'bp-relation-hint') && str_contains($rendered, 'Stattdessen Ergänzung Binnen → See'), 'Kasse (Block): Hinweis über dem Formular');
    add_filter('bootspruefung_cart_relation_hint', '__return_false');
    $check($crh::box() === '' && $crh::forUser($hintUser, [$kKey => 'BP-SBF-SEE-BIN']) === [], 'Filter bootspruefung_cart_relation_hint aus: kein Hinweis');
    remove_filter('bootspruefung_cart_relation_hint', '__return_false');
    wp_set_current_user(0);
    $check($crh::box() === '' && $crh::forUser(0, [$kKey => 'BP-SBF-SEE-BIN']) === [], 'Gast: kein Hinweis');
    wp_set_current_user($hintUser);
    preg_match('/href="([^"]*bp-swap=[^"]*)"/', $hBox, $m);
    parse_str((string) parse_url(html_entity_decode($m[1] ?? '', ENT_QUOTES), PHP_URL_QUERY), $query);
    $_GET = ['bp-swap' => $query['bp-swap'] ?? '', 'bp-swap-nonce' => 'falsch'];
    $check($crh::swapFromRequest() === false && isset(WC()->cart->get_cart()[$kKey]), 'Tausch mit falscher Nonce abgelehnt');
    $_GET = $query;
    $swapped = $crh::swapFromRequest();
    $_GET = [];
    $inCart = array_map(static fn ($i) => (int) $i['product_id'], WC()->cart->get_cart());
    $check($swapped === true && in_array($addId, $inCart, true) && ! in_array($kombiId, $inCart, true) && count($inCart) === 1, 'Tausch-Link: Kombi entfernt, Ergänzung Binnen → See (Komplett) im Warenkorb');
    $check($crh::box() === '', 'nach dem Tausch kein Hinweis mehr');
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart((int) $byS['BP-SBF-BIN']['product_id']);
    $check($crh::box() === '', 'Verlängerung im Warenkorb (SBF Binnen): kein Hinweis');
    $wpdb->delete($p.'entitlement', ['reference_key' => 'hint-test-'.$hintUser]);
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(0);
}
echo "Hinweis im Warenkorb: Kurs schon gekauft bzw. bestellt (Doppelkauf, QA-03/QA-17)\n";
$cdh = \Bootspruefung\Learning\Commerce\WooCommerce\CartDuplicateHint::class;
$tNow = '2026-11-12 10:00:00';
$st = static fn (string $status, ?string $until, array $extra = []): array => ['SBF-SEE' => array_merge(['status' => $status, 'valid_until' => $until, 'order_id' => null, 'product_id' => null, 'sku' => 'BP-SBF-SEE', 'source' => 'woo', 'duration_days' => 365, 'pending_orders' => [], 'video' => false], $extra)];
$h = $cdh::evaluate($st('pending', null, ['order_id' => 4711, 'pending_orders' => [4711]]), 'BP-SBF-SEE', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::ORDERED && $h['order_id'] === 4711 && str_contains($cdh::message($h)['text'], 'wartet noch auf deine Zahlung'), 'offene Bestellung: „schon bestellt“ mit Bestellung');
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00', ['pending_orders' => [4712]]), 'BP-SBF-SEE-KOMPLETT', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::ORDERED && $h['order_id'] === 4712, 'aktiver Zugang mit offener Verlängerung: „schon bestellt“');
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-SEE', 365, $tNow, 14);
$msg = $h !== null ? $cdh::message($h)['text'] : '';
$check($h !== null && $h['kind'] === $cdh::OWNED && $h['new_until'] === '2028-05-31 12:00:00' && str_contains($msg, '01.06.2027') && str_contains($msg, '31.05.2028') && ! $h['video_hint'], 'aktiver Zugang: Warnung mit bisherigem und neuem Laufzeitende');
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-SEE-KOMPLETT', 365, $tNow, 14);
$check($h !== null && $h['video_hint'] && str_contains($cdh::message($h)['text'], 'Video-Upgrade'), 'Theorie aktiv, Komplett im Warenkorb: Hinweis auf das Video-Upgrade');
$h = $cdh::evaluate($st('active', '2026-11-20 12:00:00'), 'BP-SBF-SEE', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::RENEW_SOON && $cdh::message($h)['title'] === 'Du verlängerst deinen Zugang' && str_contains($cdh::message($h)['text'], 'verlängerst du ihn bis'), 'bald ablaufend: als Verlängerung erklärt');
$h = $cdh::evaluate($st('expired', '2026-10-01 12:00:00'), 'BP-SBF-SEE', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::RENEW_EXPIRED && str_contains($cdh::message($h)['text'], 'abgelaufen') && str_contains($cdh::message($h)['text'], '12 Monate ab Zahlungseingang'), 'abgelaufen: als Verlängerung erklärt (Laufzeit ab Zahlungseingang)');
$check(($cdh::evaluate($st('suspended', '2027-06-01 12:00:00'), 'BP-SBF-SEE', 365, $tNow, 14)['kind'] ?? null) === $cdh::SUSPENDED && ($cdh::evaluate($st('active', null), 'BP-SBF-SEE', 365, $tNow, 14)['kind'] ?? null) === $cdh::OWNED_UNLIMITED, 'gesperrt bzw. unbefristet: eigener Hinweis');
$check($cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-SEE-VIDEO', 365, $tNow, 14) === null
    && $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-BIN', 365, $tNow, 14) === null && $cdh::evaluate([], 'BP-SBF-SEE', 365, $tNow, 14) === null && $cdh::evaluate([], 'BP-KAPITAEN', 365, $tNow, 14) === null, 'kein Hinweis für Video-Upgrade, anderen Kurs oder ohne Kauf');
// T50: Bundle, Pass und Kombi prüfen die enthaltenen Kurse.
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-KAPITAEN', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::OVERLAP && $h['parts'] === ['SBF-SEE'] && ! $h['all_parts'] && str_contains($cdh::message($h)['text'], 'SBF See') && $cdh::message($h)['title'] === 'Einen Teil davon hast du schon', 'Kapitäns-Bundle mit vorhandenem SBF See: Hinweis „Einen Teil davon hast du schon“');
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-SEE-BIN', 365, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::OVERLAP && in_array('SBF-SEE', $h['parts'], true), 'Kombi See & Binnen mit vorhandenem SBF See: Hinweis auf den enthaltenen Kurs');
$h = $cdh::evaluate($st('pending', null, ['order_id' => 4713, 'pending_orders' => [4713]]), 'BP-PASS-3M', 90, $tNow, 14);
$check($h !== null && $h['kind'] === $cdh::ORDERED && $h['order_id'] === 4713, 'Pass mit offener Bestellung eines enthaltenen Kurses: „schon bestellt“');
// T50: Theorie-Verlängerung bei vorhandenem Videokurs.
$h = $cdh::evaluate($st('active', '2026-11-20 12:00:00', ['video' => true]), 'BP-SBF-SEE', 365, $tNow, 14);
$check($h !== null && $h['video_lost'] && str_contains($cdh::message($h)['text'], 'Videokurs ist in der Theorie-Stufe nicht enthalten') && str_contains($cdh::message($h)['text'], '20.11.2026'), 'Theorie verlängern trotz Videokurs: Hinweis, dass der Videokurs nicht mitverlängert wird');
$h = $cdh::evaluate($st('active', '2026-11-20 12:00:00', ['video' => true]), 'BP-SBF-SEE-KOMPLETT', 365, $tNow, 14);
$check($h !== null && ! $h['video_lost'], 'Komplett verlängern: kein Video-Hinweis');
$h = $cdh::evaluate($st('active', '2027-06-01 12:00:00'), 'BP-SBF-SEE', 365, $tNow, 14, 'parallel');
$check($h !== null && $h['kind'] === $cdh::OWNED && $h['new_until'] === null, 'parallele Laufzeit: kein neues Enddatum behauptet');
if (function_exists('wc_load_cart')) {
    $dupUser = $customer('wc_doppelkauf_kunde');
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $dupUser));
    $seeId = (int) $byS['BP-SBF-SEE']['product_id'];
    $dupOrder = wc_create_order(['customer_id' => $dupUser]);
    $dupOrder->add_product(wc_get_product($seeId), 1);
    $dupOrder->set_payment_method('bacs');
    $dupOrder->calculate_totals();
    $dupOrder->update_status('on-hold', 'Warte auf Überweisung');
    // T50: Bankdaten bei offener Überweisung auch in der Bestellansicht.
    $bacsAccountsBefore = get_option('woocommerce_bacs_accounts', null);
    update_option('woocommerce_bacs_accounts', [['account_name' => 'Testschule GmbH', 'account_number' => '', 'bank_name' => 'Testbank', 'sort_code' => '', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']]);
    $gwAll = WC()->payment_gateways()->payment_gateways();
    if (isset($gwAll['bacs'])) {
        $gwAll['bacs']->account_details = get_option('woocommerce_bacs_accounts');
    }
    $bankHtml = \Bootspruefung\Learning\Commerce\WooCommerce\BankDetails::html((int) $dupOrder->get_id());
    $check(str_contains($bankHtml, 'Zahlung per Überweisung') && str_contains($bankHtml, 'DE02120300000000202051') && has_action('woocommerce_view_order') !== false, 'offene Überweisung: Bankdaten auch in „Bestellung ansehen“ (T50)');
    $bacsAccountsBefore === null ? delete_option('woocommerce_bacs_accounts') : update_option('woocommerce_bacs_accounts', $bacsAccountsBefore);
    $dupId = (int) $dupOrder->get_id();
    $states = \Bootspruefung\Learning\Commerce\AccessStatus::forUser($dupUser);
    $check(($states['SBF-SEE']['status'] ?? null) === 'pending' && ($states['SBF-SEE']['pending_orders'] ?? []) === [$dupId] && ($states['SBF-SEE']['duration_days'] ?? null) === 365, 'Überweisung offen: Kaufstatus pending mit Bestellung und Laufzeit');
    wp_set_current_user($dupUser);
    ob_start();
    do_action('woocommerce_account_mein-lernen_endpoint');
    $account = (string) ob_get_clean();
    $check(str_contains($account, 'wartet auf Zahlung') && str_contains($account, '12 Monate ab Zahlungseingang') && ! str_contains($account, 'dauerhaft') && str_contains($account, 'Bestellung #'.$dupOrder->get_order_number()), '„Mein Lernen“: „12 Monate ab Zahlungseingang“ statt „dauerhaft“, Link zur Bestellung (QA-07/UX-03)');
    wc_load_cart();
    WC()->cart->empty_cart();
    wc_clear_notices();
    WC()->cart->add_to_cart($seeId);
    $box = $cdh::box();
    $check(str_contains($box, 'Diesen Kurs hast du schon bestellt') && str_contains($box, '#'.$dupOrder->get_order_number()) && str_contains($box, esc_url($dupOrder->get_view_order_url())), 'Warenkorb: „schon bestellt“ mit Link zur Bestellung');
    $rendered = (string) apply_filters('render_block_woocommerce/checkout', '<div class="wc-block-checkout"></div>');
    $check(str_contains($rendered, 'bp-duplicate-hint') && str_contains($rendered, 'wc-block-checkout'), 'Kasse (Block): Hinweis über dem Formular, Kasse bleibt (keine Sperre)');
    add_filter('bootspruefung_cart_duplicate_hint', '__return_false');
    $check($cdh::box() === '', 'Filter bootspruefung_cart_duplicate_hint aus: kein Hinweis');
    remove_filter('bootspruefung_cart_duplicate_hint', '__return_false');
    wp_set_current_user(0);
    $check($cdh::box() === '', 'Gast: kein Hinweis');
    wp_set_current_user(1);
    $dupOrder = wc_get_order($dupId);
    $dupOrder->update_status('cancelled');
    $check(! isset(\Bootspruefung\Learning\Commerce\AccessStatus::forUser($dupUser)['SBF-SEE']), 'storniert: kein offener Kauf mehr');
    // Verlängern-Link zur gekauften Stufe (QA-12): Komplett-Käufer → Komplett, Theorie-Käufer → Theorie.
    $komplettId = (int) $byS['BP-SBF-SEE-KOMPLETT']['product_id'];
    foreach ([$seeId, $komplettId] as $pid) {
        $prod = wc_get_product($pid);
        $prod->set_status('publish');
        $prod->save();
    }
    $past = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $start = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
    $wpdb->insert($p.'entitlement', ['user_id' => $dupUser, 'course_code' => 'SBF-SEE', 'feature_code' => 'all', 'status' => 'expired', 'valid_from' => $start, 'valid_until' => $past, 'source' => 'woo', 'reference_key' => 'renew-test-k', 'order_id' => 990901, 'product_id' => $komplettId, 'sku_snapshot' => 'BP-SBF-SEE-KOMPLETT', 'created_at' => $start, 'updated_at' => $start]);
    $komplettUrl = (string) get_permalink($komplettId);
    $check(\Bootspruefung\Learning\Commerce\ExpiryReminder::renewUrl('SBF-SEE', $dupUser) === $komplettUrl, 'Komplett-Käufer: Verlängern führt zur Komplett-Stufe');
    $wpdb->update($p.'entitlement', ['feature_code' => 'question_bank', 'sku_snapshot' => 'BP-SBF-SEE', 'product_id' => $seeId], ['reference_key' => 'renew-test-k']);
    $check(\Bootspruefung\Learning\Commerce\ExpiryReminder::renewUrl('SBF-SEE', $dupUser) === (string) get_permalink($seeId), 'Theorie-Käufer: Verlängern führt zur Theorie-Stufe');
    $wpdb->insert($p.'entitlement', ['user_id' => $dupUser, 'course_code' => 'SBF-SEE', 'feature_code' => 'video_course', 'status' => 'expired', 'valid_from' => $start, 'valid_until' => $past, 'source' => 'woo', 'reference_key' => 'renew-test-v', 'order_id' => 990902, 'sku_snapshot' => 'BP-SBF-SEE-VIDEO', 'created_at' => $start, 'updated_at' => $start]);
    $check(\Bootspruefung\Learning\Commerce\ExpiryReminder::renewUrl('SBF-SEE', $dupUser) === $komplettUrl, 'Theorie + Video-Upgrade: Verlängern führt zur Komplett-Stufe');
    wp_set_current_user($dupUser);
    $me = array_column((array) rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/me/courses'))->get_data(), null, 'code');
    $check(($me['SBF-SEE']['access']['status'] ?? null) === 'expired' && ($me['SBF-SEE']['access']['renew_url'] ?? null) === $komplettUrl, 'Lernbereich: abgelaufen mit Verlängern-Link zur gekauften Stufe');
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($komplettId);
    $check(str_contains($cdh::box(), 'Du verlängerst deinen Zugang'), 'Warenkorb nach Ablauf: als Verlängerung erklärt');
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(0);
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d", $dupUser));
}
// Widerrufsfunktion (§ 356a BGB): WooCommerce-Funktion „Order withdrawal“ + Anbindung.
$wf = \Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::class;
$check($wf::available(), 'WooCommerce bringt die Widerrufsfunktion mit');
$wfFeatures = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class);
$wfWasEnabled = $wf::enabled();
$wfAutoBefore = get_option($wf::AUTOENABLED_OPTION, null);
// Einmaliges automatisches Einschalten; späteres bewusstes Ausschalten bleibt bestehen.
delete_option($wf::AUTOENABLED_OPTION);
$wfFeatures->change_feature_enable($wf::FEATURE, false);
$wf::autoEnable();
$check($wf::enabled() && get_option($wf::AUTOENABLED_OPTION) === 'notice', 'Widerrufsfunktion beim ersten Admin-Aufruf automatisch eingeschaltet');
$wfFeatures->change_feature_enable($wf::FEATURE, false);
$wf::autoEnable();
$check(! $wf::enabled(), 'bewusst ausgeschaltet: bleibt aus');
// Ausgeschaltet: kein Link ins Leere.
$wfRedirect = static function () use ($wf): ?string {
    $location = null;
    $catch = static function ($to) use (&$location) {
        $location = (string) $to;
        throw new RuntimeException('redirect');
    };
    add_filter('wp_redirect', $catch, 1);
    $uri = $_SERVER['REQUEST_URI'];
    $_SERVER['REQUEST_URI'] = (string) wp_parse_url(home_url('/vertrag-widerrufen/'), PHP_URL_PATH);
    try {
        (new $wf())->redirect();
    } catch (RuntimeException $e) {
    }
    $_SERVER['REQUEST_URI'] = $uri;
    remove_filter('wp_redirect', $catch, 1);

    return $location;
};
$legacyBefore = get_page_by_path($wf::SLUG, OBJECT, 'page');
if (! $legacyBefore instanceof WP_Post) {
    $dcUrl = \Bootspruefung\Learning\Commerce\WooCommerce\DigitalConsent::withdrawalUrl();
    $check($wfRedirect() === $dcUrl, 'ausgeschaltet: /vertrag-widerrufen/ leitet zur Widerrufsbelehrung');
    $prettyPermalinks = static fn () => '/%postname%/';
    add_filter('pre_option_permalink_structure', $prettyPermalinks);
    $check($wf::endpointUrl() === $dcUrl && rtrim($wf::url(), '/') === rtrim(home_url('/vertrag-widerrufen/'), '/'), 'ausgeschaltet: Knöpfe führen zur Widerrufsbelehrung, feste Adresse bleibt');
    remove_filter('pre_option_permalink_structure', $prettyPermalinks);
    $check(str_contains($wf::shortcode(), 'Widerrufsbelehrung') && ! str_contains($wf::shortcode(), 'order-withdrawal'), 'ausgeschaltet: Shortcode verweist auf die Widerrufsbelehrung');
    // Seite der früheren Funktion bleibt erhalten und wird von WordPress ausgeliefert.
    $legacyId = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Vertrag widerrufen', 'post_name' => $wf::SLUG, 'post_content' => '[bootspruefung_vertrag_widerrufen]']);
    $legacyOptBefore = get_option('bp_learning_withdrawal_page', null);
    update_option('bp_learning_withdrawal_page', $legacyId);
    $wf::removeLegacyPage();
    $check(get_post($legacyId) instanceof WP_Post && $wfRedirect() === null && $wf::endpointUrl() === get_permalink($legacyId), 'ausgeschaltet: alte Seite bleibt und wird ausgeliefert');
    $wf::enable();
    $wf::removeLegacyPage();
    $check(! get_post($legacyId) instanceof WP_Post, 'eingeschaltet: alte Seite entfernt');
    $legacyOptBefore === null ? delete_option('bp_learning_withdrawal_page') : update_option('bp_learning_withdrawal_page', $legacyOptBefore);
}
$wf::enable();
$check($wf::enabled(), 'Widerrufsfunktion eingeschaltet');
$check($wfRedirect() === $wf::endpointUrl(), 'eingeschaltet: /vertrag-widerrufen/ leitet zum Formular');
$plainPermalinks = static fn () => '';
add_filter('pre_option_permalink_structure', $plainPermalinks);
$check($wf::url() === $wf::endpointUrl() && ! str_contains($wf::url(), 'vertrag-widerrufen'), 'einfache Permalinks: Link direkt zum Formular');
remove_filter('pre_option_permalink_structure', $plainPermalinks);
$wfAutoBefore === null ? delete_option($wf::AUTOENABLED_OPTION) : update_option($wf::AUTOENABLED_OPTION, $wfAutoBefore, false);
$check(str_contains($wf::endpointUrl(), 'order-withdrawal') || str_contains($wf::endpointUrl(), 'withdraw-order'), 'Formular-Adresse unter Mein Konto');
$prettyPermalinks = static fn () => '/%postname%/';
add_filter('pre_option_permalink_structure', $prettyPermalinks);
$check(rtrim($wf::url(), '/') === rtrim(home_url('/vertrag-widerrufen/'), '/'), 'feste Adresse /vertrag-widerrufen/ (sprechende Permalinks)');
remove_filter('pre_option_permalink_structure', $prettyPermalinks);
$wOrder = wc_create_order();
$wOrder->set_billing_email('widerruf@example.org');
$wOrder->set_status('pending');
$wOrder->save();
$deadline = $wf::deadline($wOrder);
$expected = (new DateTimeImmutable('@'.$wOrder->get_date_created()->getTimestamp()))->setTimezone(wp_timezone())->setTime(23, 59, 59)->modify('+14 days')->getTimestamp();
$check($deadline === $expected, 'Widerrufsfrist endet mit Ablauf des 14. Tages');
$check($wf::offered($wOrder), 'Widerruf innerhalb der Frist angeboten');
$wOrder->set_date_created(time() - 20 * DAY_IN_SECONDS);
$wOrder->save();
$check(! $wf::offered($wOrder), 'nach Ablauf der Frist nicht mehr angeboten');
$wOrder->set_date_paid(time() - DAY_IN_SECONDS);
$wOrder->save();
$check($wf::offered($wOrder), 'später bezahlt: Frist ab Zahlungseingang');
$wOrder->set_status('cancelled');
$wOrder->save();
$check(! $wf::offered($wOrder), 'stornierte Bestellung: nicht angeboten');
$wOrder->delete(true);
// „Mein Lernen“ bei 390 px: Tabelle stapelt (WooCommerce-Muster shop_table_responsive), Knopf nicht abgeschnitten, Spalte benannt.
$wOrder = wc_create_order(['customer_id' => $alice]);
$wOrder->set_status('pending');
$wOrder->save();
ob_start();
$wf::renderAccountSection($alice);
$wHtml = (string) ob_get_clean();
$wNumber = $wOrder->get_order_number();
$wOrder->delete(true);
$check(str_contains($wHtml, 'shop_table_responsive bp-withdrawal-table') && str_contains($wHtml, 'data-title="Bestellung">#'.$wNumber) && str_contains($wHtml, 'data-title="Widerrufsfrist bis"'), 'Widerrufs-Tabelle: mobil gestapelt mit Beschriftung je Zelle');
$check(str_contains($wHtml, '<td class="actions bp-withdrawal-action"><a class="button bp-withdrawal-button"') && str_contains($wHtml, '<span class="screen-reader-text">Aktion</span>') && ! str_contains($wHtml, '<th></th>'), 'Widerrufs-Knopf in eigener Zeile, Spaltenkopf „Aktion“ für Screenreader');

// Widerrufe aus der früheren eigenen Funktion: erkannt, angezeigt, exportiert, anonymisiert.
$lOrder = wc_create_order(['customer_id' => $alice]);
$lTime = time() - HOUR_IN_SECONDS;
$lOrder->update_meta_data($wf::LEGACY_META, [['time' => $lTime, 'name' => 'Erika Widerruf', 'email' => 'erika.widerruf@example.org', 'order_ref' => (string) $lOrder->get_id(), 'note' => 'Bitte Geld zurück', 'user_id' => $alice]]);
$lOrder->update_meta_data(\Bootspruefung\Learning\Commerce\WooCommerce\DigitalConsent::META, ['time' => $lTime, 'text' => 'Wortlaut Test', 'source' => 'classic']);
$lOrder->save();
$lOrder = wc_get_order($lOrder->get_id());
$check($wf::requested($lOrder), 'früherer Widerruf (_bp_withdrawal) wird erkannt');
ob_start();
(new $wf())->adminLegacy($lOrder);
$lAdmin = (string) ob_get_clean();
$check(str_contains($lAdmin, 'Erika Widerruf') && str_contains($lAdmin, 'erika.widerruf@example.org') && str_contains($lAdmin, 'Eingegangen am'), 'Bestell-Admin zeigt früheren Widerruf (Eingang, Name, E-Mail)');
$lExport = (string) wp_json_encode(apply_filters('woocommerce_privacy_export_order_personal_data', [], $lOrder));
$check(str_contains($lExport, 'Erika Widerruf') && str_contains($lExport, 'Bitte Geld zur') && str_contains($lExport, 'Wortlaut Test'), 'Datenexport enthält früheren Widerruf und Zustimmung');
do_action('woocommerce_privacy_remove_order_personal_data', $lOrder);
$lEntry = $wf::legacyEntries(wc_get_order($lOrder->get_id()))[0] ?? [];
$check(($lEntry['time'] ?? 0) === $lTime && ! str_contains((string) wp_json_encode($lEntry), 'Erika') && ! str_contains((string) wp_json_encode($lEntry), 'erika.widerruf') && ($lEntry['note'] ?? 'x') === '', 'Datenlöschung: Name, E-Mail, Notiz anonymisiert, Zeitpunkt bleibt');
$lOrder->delete(true);

// Pflichtbeschriftungen: immer der gesetzliche Wortlaut. switch_to_locale() geht nur mit installiertem
// Sprachpaket (lädt dann die Textdomain neu); sonst Website-Sprache per Filter auf Deutsch.
$switched = switch_to_locale('de_DE');
$deLocale = static fn () => 'de_DE';
if (! $switched) {
    add_filter('locale', $deLocale);
}
$check(str_starts_with(determine_locale(), 'de'), 'Testsprache Deutsch ('.($switched ? 'Sprachpaket' : 'ohne Sprachpaket').')');
$check(__('Withdraw from contract', 'woocommerce') === 'Vertrag hier widerrufen' && __('Confirm withdrawal', 'woocommerce') === 'Widerruf bestätigen', 'Pflichtbeschriftungen „Vertrag hier widerrufen“ / „Widerruf bestätigen“');
$overrideLabel = static fn ($t, $text) => $text === 'Confirm withdrawal' ? 'Widerruf abschicken' : $t;
add_filter('gettext_woocommerce', $overrideLabel, 10, 2);
$check(__('Confirm withdrawal', 'woocommerce') === 'Widerruf bestätigen', 'abweichende Übersetzung des Sprachpakets wird ersetzt');
remove_filter('gettext_woocommerce', $overrideLabel, 10);
$check(__('Order number', 'woocommerce') !== 'Order number', 'übrige Texte auf Deutsch');
$switched ? restore_previous_locale() : remove_filter('locale', $deLocale);
$check(str_starts_with(determine_locale(), 'de') || __('Confirm withdrawal', 'woocommerce') === 'Confirm withdrawal', 'englische Website: kein Ersatz');
echo "Zustimmung digitale Inhalte (§ 356 Abs. 5 BGB), klassisch und „Bestellung bezahlen“\n";
$dc = \Bootspruefung\Learning\Commerce\WooCommerce\DigitalConsent::class;
$consent = new $dc();
$errorNotices = static fn (): array => array_map(static fn ($n) => is_array($n) ? (string) $n['notice'] : (string) $n, wc_get_notices('error'));
if (function_exists('wc_load_cart')) {
    $consentUser = $customer('wc_consent_kunde');
    wp_set_current_user($consentUser);
    wc_load_cart();
    WC()->cart->empty_cart();
    wc_clear_notices();
    WC()->cart->add_to_cart($lern);
    unset($_POST[$dc::POST_FIELD]);
    $consent->classicValidate();
    $check(in_array($dc::errorMessage(), $errorNotices(), true), 'klassisch: ohne Haken Fehlermeldung');
    wc_clear_notices();
    $_POST[$dc::POST_FIELD] = '1';
    $consent->classicValidate();
    $check(wc_notice_count('error') === 0, 'klassisch: mit Haken keine Fehlermeldung');
    ob_start();
    $consent->classicField();
    $field = (string) ob_get_clean();
    $check(str_contains($field, 'aria-required="true"') && str_contains($field, 'öffnet in neuem Fenster') && str_contains($field, 'screen-reader-text') && str_contains($field, ' checked'), 'klassisch: Kästchen barrierearm (aria-required, neues Fenster, „erforderlich“)');
    $cOrder = wc_create_order(['customer_id' => $consentUser]);
    $cOrder->add_product(wc_get_product($lern), 1);
    $cOrder->calculate_totals();
    $consent->classicSave($cOrder, []);
    $cOrder->save();
    $saved = wc_get_order($cOrder->get_id())->get_meta($dc::META, true);
    $check(is_array($saved) && ! empty($saved['time']) && ($saved['source'] ?? '') === 'classic' && ($saved['text'] ?? '') === $dc::label(), 'klassisch: Zustimmung mit Zeitpunkt in _bp_digital_consent gespeichert');
    $cOrder->delete(true);

    // „Bestellung bezahlen“: Prüfung in woocommerce_before_pay_action.
    $pOrder = wc_create_order(['customer_id' => $consentUser]);
    $pOrder->add_product(wc_get_product($lern), 1);
    $pOrder->calculate_totals();
    $pOrder->save();
    wc_clear_notices();
    unset($_POST[$dc::POST_FIELD]);
    $consent->payValidate($pOrder);
    $check(in_array($dc::errorMessage(), $errorNotices(), true) && $dc::consentFor(wc_get_order($pOrder->get_id())) === null, 'Bestellung bezahlen: ohne Haken Fehlermeldung, nichts gespeichert');
    wc_clear_notices();
    $_POST[$dc::POST_FIELD] = '1';
    $consent->payValidate($pOrder);
    $pOrder = wc_get_order($pOrder->get_id());
    $payPending = $dc::consentFor($pOrder, true);
    $check(wc_notice_count('error') === 0 && $dc::consentFor($pOrder) === null && $payPending !== null && $payPending['source'] === 'order_pay' && $payPending['user_id'] === $consentUser && $payPending['customer_match'] === true, 'Bestellung bezahlen: Zustimmung vorläufig gespeichert (mit Benutzer, passt zur Kundin)');
    set_query_var('order-pay', $pOrder->get_id());
    $pOrder->update_status('failed');
    $_POST[$dc::POST_FIELD] = '';
    ob_start();
    $consent->payField();
    $payFieldHtml = (string) ob_get_clean();
    $check($dc::consentFor(wc_get_order($pOrder->get_id())) === null && str_contains($payFieldHtml, $dc::POST_FIELD), 'Zahlung fehlgeschlagen: keine endgültige Zustimmung, Kästchen erscheint wieder');
    unset($_POST[$dc::POST_FIELD]);
    $pOrder = wc_get_order($pOrder->get_id());
    $pOrder->update_status('on-hold'); // z. B. Überweisung
    $pOrder = wc_get_order($pOrder->get_id());
    $paySaved = $dc::consentFor($pOrder);
    $check($paySaved !== null && $paySaved['source'] === 'order_pay' && $paySaved['time'] === $payPending['time'] && $dc::consentFor($pOrder, true) === null, 'Zahlung angestoßen (in Wartestellung): Zustimmung endgültig');
    ob_start();
    $consent->payField();
    $check((string) ob_get_clean() === '', 'Bestellung bezahlen: nach Zustimmung kein Kästchen mehr');
    set_query_var('order-pay', 0);
    $consent->payValidate($pOrder);
    $check(wc_notice_count('error') === 0, 'Bestellung bezahlen: bereits zugestimmt, keine erneute Abfrage');
    // Andere angemeldete Person bezahlt: wird vermerkt.
    $mOrder = wc_create_order(['customer_id' => $alice]);
    $mOrder->add_product(wc_get_product($lern), 1);
    $mOrder->calculate_totals();
    $mOrder->save();
    $_POST[$dc::POST_FIELD] = '1';
    $consent->payValidate($mOrder);
    unset($_POST[$dc::POST_FIELD]);
    $mOrder->payment_complete('bp-test-'.wp_generate_password(6, false));
    $mSaved = $dc::consentFor(wc_get_order($mOrder->get_id()));
    $check($mSaved !== null && $mSaved['user_id'] === $consentUser && $mSaved['customer_match'] === false, 'Zahlung abgeschlossen: Zustimmung endgültig, abweichendes Benutzerkonto vermerkt');
    $wpdb->delete($wpdb->prefix.'bp_entitlement', ['order_id' => $mOrder->get_id()]);
    $wpdb->delete($wpdb->prefix.'bp_entitlement', ['order_id' => $pOrder->get_id()]);
    $mOrder->delete(true);
    $bOrder = wc_create_order(['customer_id' => $consentUser]);
    $bOrder->add_product(wc_get_product($book), 1);
    $bOrder->calculate_totals();
    $bOrder->save();
    $consent->payValidate($bOrder);
    $check(wc_notice_count('error') === 0, 'Bestellung bezahlen: ohne Lernprodukt keine Abfrage');
    $pOrder->delete(true);
    $bOrder->delete(true);
    WC()->cart->empty_cart();
    wc_clear_notices();
    wp_set_current_user(1);
}

echo "Geld-zurück-Garantie: teilerstattete Position\n";
$gOrder = wc_create_order(['customer_id' => $alice]);
$gKeep = $gOrder->add_product(wc_get_product($byS['BP-SKN']['product_id']), 1);
$gPart = $gOrder->add_product(wc_get_product($byS['BP-SBF-SEE-VIDEO']['product_id']), 1);
$gOrder->set_payment_method('stripe');
$gOrder->calculate_totals();
$gOrder->save();
$gOrder->payment_complete('ch_bd_'.wp_generate_password(6, false));
$check(\Bootspruefung\Learning\Commerce\MoneyBack::policy((int) $byS['BP-SKN']['product_id']) !== null && \Bootspruefung\Learning\Commerce\MoneyBack::policy((int) $byS['BP-SBF-SEE-VIDEO']['product_id']) !== null, 'beide Positionen mit Geld-zurück-Garantie');
$gRefund = wc_create_refund(['order_id' => $gOrder->get_id(), 'amount' => 1, 'reason' => 'Kulanz', 'line_items' => [$gPart => ['qty' => 0, 'refund_total' => 1]], 'refund_payment' => false, 'restock_items' => false]);
$gOrder = wc_get_order($gOrder->get_id());
$keepItem = $gOrder->get_item($gKeep);
$expected = round((float) $keepItem->get_total() + (float) $keepItem->get_total_tax(), 2);
$parts = \Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo::breakdown($gOrder);
$check(! is_wp_error($gRefund) && $expected > 0 && abs($parts['amount'] - $expected) < 0.001, 'breakdown(): Betrag nur aus der nicht erstatteten Garantie-Position');
$gOrder->delete(true);

echo "Bestell-Schaltfläche (§ 312j Abs. 3 BGB)\n";
$freeProduct = $make('Gratis-Test (Bestellknopf)', '0');
$blockLabel = static fn (): string => (string) (apply_filters('render_block_data', ['blockName' => 'woocommerce/checkout-actions-block', 'attrs' => ['placeOrderButtonLabel' => 'Place Order'], 'innerBlocks' => []], [], null)['attrs']['placeOrderButtonLabel'] ?? '');
if (function_exists('wc_load_cart')) {
    wp_set_current_user($alice);
    wc_load_cart();
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($book);
    WC()->cart->calculate_totals();
    $check(apply_filters('woocommerce_order_button_text', 'Place order') === 'Zahlungspflichtig bestellen', 'klassische Kasse: „Zahlungspflichtig bestellen“');
    $check($blockLabel() === 'Zahlungspflichtig bestellen', 'Kassen-Block: placeOrderButtonLabel gesetzt');
    WC()->cart->empty_cart();
    WC()->cart->add_to_cart($freeProduct);
    WC()->cart->calculate_totals();
    $check(apply_filters('woocommerce_order_button_text', 'Place order') === 'Place order' && $blockLabel() === 'Place Order', 'nichts zu bezahlen (0 €): Standardbeschriftung');
    WC()->cart->empty_cart();
    wp_set_current_user(1);
}
$payBtnOrder = wc_create_order(['customer_id' => $alice]);
$payBtnOrder->add_product(wc_get_product($book), 1);
$payBtnOrder->calculate_totals();
$payBtnOrder->save();
set_query_var('order-pay', $payBtnOrder->get_id());
$check(apply_filters('woocommerce_pay_order_button_text', 'Pay for order') === 'Zahlungspflichtig bestellen', 'Bestellung bezahlen: „Zahlungspflichtig bestellen“');
$freeOrder = wc_create_order(['customer_id' => $alice]);
$freeOrder->add_product(wc_get_product($freeProduct), 1);
$freeOrder->calculate_totals();
$freeOrder->save();
set_query_var('order-pay', $freeOrder->get_id());
$check(apply_filters('woocommerce_pay_order_button_text', 'Pay for order') === 'Pay for order', 'Bestellung bezahlen ohne Betrag: Standardbeschriftung');
set_query_var('order-pay', 0);
$payBtnOrder->delete(true);
$freeOrder->delete(true);
wp_delete_post($freeProduct, true);
$fakeGateway = new class() extends WC_Payment_Gateway {
    public function __construct()
    {
        $this->id = 'bp_fake';
        $this->order_button_text = 'Weiter zu Fake';
    }
};
$check(apply_filters('woocommerce_available_payment_gateways', ['bp_fake' => $fakeGateway])['bp_fake']->order_button_text === null, 'Zahlungsart überschreibt die Beschriftung nicht');

echo "AGB und Widerrufsbelehrung in der Bestellmail (§ 312f Abs. 2 BGB)\n";
$lt = \Bootspruefung\Learning\Commerce\WooCommerce\LegalTextsEmail::class;
$termsBefore = get_option('woocommerce_terms_page_id');
$termsPage = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AGB Test', 'post_name' => 'agb-test-'.wp_generate_password(6, false, false), 'post_content' => "<!-- wp:paragraph {\"className\":\"kw-placeholder\"} -->\n<p class=\"kw-placeholder\">Hinweis für den Betreiber</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">1. Geltungsbereich</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Diese AGB gelten für alle <a href=\"https://example.org/kurse/\">Kurse</a>.</p>\n<!-- /wp:paragraph -->"]);
update_option('woocommerce_terms_page_id', $termsPage);
// Nur die Test-AGB (die echte Widerrufsbelehrung der Website kann noch Platzhalter enthalten).
$onlyTerms = static fn ($pages) => array_values(array_filter((array) $pages, static fn ($p) => $p->ID === $termsPage));
add_filter('bootspruefung_legal_texts_email_pages', $onlyTerms);
$mailOrder = wc_create_order(['customer_id' => $alice]);
$mailFor = static function (string $id, bool $admin, bool $plain, ?WC_Order $o = null) use ($lt, &$mailOrder): string {
    $o = $o ?? wc_get_order($mailOrder->get_id());
    ob_start();
    (new $lt())->email($o, $admin, $plain, (object) ['id' => $id]);

    return (string) ob_get_clean();
};
$freshMail = static function () use (&$mailOrder, $alice): void {
    $mailOrder->delete(true);
    $mailOrder = wc_create_order(['customer_id' => $alice]);
};
$htmlMail = $mailFor('customer_processing_order', false, false);
$sentAt = (int) wc_get_order($mailOrder->get_id())->get_meta($lt::SENT_META, true);
$check($sentAt > 0 && str_contains($htmlMail, 'Stand: '.wp_date('d.m.Y', $sentAt)), 'Überschrift mit Stand-Datum (Versanddatum), Versand in der Bestellung vermerkt');
$check($mailFor('customer_completed_order', false, false) === '', 'spätere Mail derselben Bestellung: Texte nicht erneut angehängt');
$freshMail();
$plainMail = $mailFor('customer_completed_order', false, true);
$freshMail();
$check(str_contains($htmlMail, $lt::heading($sentAt)) && str_contains($htmlMail, '<h2>1. Geltungsbereich</h2>') && str_contains($htmlMail, 'href="https://example.org/kurse/"') && ! str_contains($htmlMail, 'Hinweis für den Betreiber') && ! str_contains($htmlMail, '<mark'), 'HTML-Mail: vollständiger AGB-Text mit Überschriften, ohne Betreiber-Hinweis');
$check(str_contains($plainMail, 'AGB TEST') && str_contains($plainMail, 'Kurse (https://example.org/kurse/)') && ! str_contains($plainMail, '<'), 'Text-Mail: AGB als Klartext mit ausgeschriebenen Links');
$check($mailFor('new_order', true, false) === '' && $mailFor('customer_invoice', false, false) === '' && $mailFor('customer_on_hold_order', false, false) !== '', 'nur Kunden-Mails in Bearbeitung/Wartestellung/abgeschlossen');
remove_filter('bootspruefung_legal_texts_email_pages', $onlyTerms);
$widerrufPage = get_page_by_path('widerruf', OBJECT, 'page');
if ($widerrufPage instanceof WP_Post && $widerrufPage->post_status === 'publish') {
    $freshMail();
    $withWiderruf = $mailFor('customer_processing_order', false, false);
    if (str_contains($widerrufPage->post_content, $lt::PLACEHOLDER)) {
        $check($withWiderruf === '' && (string) wc_get_order($mailOrder->get_id())->get_meta($lt::SENT_META, true) === '', 'Widerrufsbelehrung mit [[Betreiber:]]-Platzhaltern: nichts angehängt, nichts vermerkt');
    } else {
        $check(str_contains($withWiderruf, 'Widerrufsbelehrung') && str_contains($withWiderruf, 'Muster'), 'veröffentlichte Widerrufsbelehrung samt Muster-Formular angehängt');
    }
}
add_filter('bootspruefung_legal_texts_email_pages', $onlyTerms);
$freshMail();
$placeholderTerms = (string) get_post($termsPage)->post_content;
wp_update_post(['ID' => $termsPage, 'post_content' => $placeholderTerms."\n<!-- wp:paragraph --><p>[[Betreiber: Name]]</p><!-- /wp:paragraph -->"]);
$check($mailFor('customer_processing_order', false, false) === '' && (string) wc_get_order($mailOrder->get_id())->get_meta($lt::SENT_META, true) === '', 'AGB mit Platzhalter: nicht angehängt, nichts vermerkt');
$noticeFor = static function (int $user) use ($lt): string {
    wp_set_current_user($user);
    ob_start();
    (new $lt())->adminNotice();

    return (string) ob_get_clean();
};
$check(str_contains($noticeFor(1), 'Rechtstexte enthalten Platzhalter') && $noticeFor($alice) === '', 'Admin-Hinweis zu Platzhaltern (nur für Shop-Verwaltung)');
wp_set_current_user(1);
wp_update_post(['ID' => $termsPage, 'post_content' => $placeholderTerms]);
$freshMail();
wp_update_post(['ID' => $termsPage, 'post_status' => 'draft']);
$check(! str_contains($mailFor('customer_processing_order', false, false), 'Geltungsbereich'), 'unveröffentlichte AGB-Seite wird nicht angehängt');
remove_filter('bootspruefung_legal_texts_email_pages', $onlyTerms);
update_option('woocommerce_terms_page_id', $termsBefore);
wp_delete_post($termsPage, true);
$mailOrder->delete(true);

echo "Livegang-Warnung „Zugang ohne Shop“ (T15)\n";
$soonBak = get_option('woocommerce_coming_soon');
$mapped = (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->productIds();
$probe = (int) ($mapped[0] ?? 0);
$probeStatus = $probe ? get_post_status($probe) : '';
if ($probe) {
    wp_update_post(['ID' => $probe, 'post_status' => 'publish']);
}
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'logged_in']));
update_option('woocommerce_coming_soon', 'yes');
$RQ = \Bootspruefung\Learning\Infrastructure\Requirements::class;
ob_start();
$RQ::adminNotice();
$rqNotice = (string) ob_get_clean();
$check($RQ::wooCommerceStatus()['supported'] === true && $rqNotice === '' && ! $RQ::wooCommerceSupported('11.1.9') && $RQ::wooCommerceSupported('11.2.0') && $RQ::wooCommerceSupported('11.3'), 'Mindestversion WooCommerce 11.2: getestete Version ohne Hinweis, ältere wird erkannt (T34)');
$check(has_action('admin_notices', [$RQ, 'adminNotice']) !== false, 'Hinweis bei zu altem WooCommerce im Admin registriert');
$check(! \Bootspruefung\Learning\Admin\GoLiveNotice::shouldWarn(), 'Shop noch „Demnächst verfügbar“: keine Warnung');
update_option('woocommerce_coming_soon', 'no');
$check($probe > 0 && \Bootspruefung\Learning\Admin\GoLiveNotice::shouldWarn(), 'Shop sichtbar, Lernprodukt veröffentlicht, Zugang für alle Angemeldeten: Warnung');
\Bootspruefung\Learning\Admin\GoLiveNotice::apply();
$check(Settings::get('default_access') === 'entitled_only' && ! \Bootspruefung\Learning\Admin\GoLiveNotice::shouldWarn(), 'Umstellen: nur kostenlose Regeln und Freischaltungen, Warnung weg');
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bp_audit_log WHERE action = 'settings_default_access'") > 0, 'Umstellung im Audit-Log');
update_option('woocommerce_coming_soon', $soonBak);
if ($probe && $probeStatus) {
    wp_update_post(['ID' => $probe, 'post_status' => $probeStatus]);
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
