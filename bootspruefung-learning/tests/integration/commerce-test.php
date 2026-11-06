<?php

declare(strict_types=1);

/*
 * Integrationstest Zugangslogik (Kap. 7.3–7.6) gegen eine echte WordPress-
 * Datenbank, mit Testbestellungen statt WooCommerce. Deckt die
 * Zahlungsarten-Szenarien ab: Überweisung (on-hold bis Zahlung), sofort
 * bezahlte Gateways (Stripe/PayPal/Klarna), Dispute, Erstattung, Storno.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/commerce-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require_once __DIR__.'/support/FakeOrders.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSync;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Tests\FakeOrders;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};

global $wpdb;
wp_set_current_user(1);
$p = $wpdb->prefix.'bp_';
$course = (new \Bootspruefung\Learning\Content\Repositories\ContentRepository())->courses()[0]->code ?? 'TESTKURS';
$userId = static function (string $login): int {
    $u = get_user_by('login', $login);

    return $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer']);
};
$alice = $userId('bp_buyer_alice');
$bob = $userId('bp_buyer_bob');

// Testdaten zurücksetzen (Bestell-IDs 900001+, Produkt-IDs 990001+).
$wpdb->query("DELETE FROM {$p}entitlement WHERE order_id >= 900001 OR user_id IN ({$alice}, {$bob})");
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id >= 990001");
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));

$products = new ProductAccessRepository($wpdb);
$entitlements = new EntitlementService($wpdb);
$orders = new FakeOrders();
$sync = new OrderSync($orders, $products, $entitlements);
$access = new AccessService();
$item = static fn (int $id, int $product, int $variation = 0, float $total = 49.0, int $refQty = 0, float $refTotal = 0.0) => new OrderItemSnapshot($id, $product, $variation, 1, 'SKU-'.$product, $total, $refQty, $refTotal);
$rows = static fn (int $orderId) => $entitlements->forOrder($orderId);
$statuses = static fn (int $orderId) => array_values(array_unique(array_map(static fn ($r) => $r->status, $entitlements->forOrder($orderId))));
$changes = static fn (?\Bootspruefung\Learning\Commerce\Plan $plan) => $plan === null ? -1 : count($plan->changes);

echo "Produktzuordnung\n";
$products->save(990001, 0, [['course_code' => $course, 'feature_code' => 'question_bank'], ['course_code' => $course, 'feature_code' => 'exam_simulation']], null, 'extend', 'revoke_on_full_refund');
$products->save(990002, 0, [['course_code' => $course, 'feature_code' => 'video_course']], null, 'extend', 'revoke_on_full_refund');
$products->save(990002, 990012, [['course_code' => $course, 'feature_code' => 'all']], 30, 'extend', 'revoke_on_full_refund');
$products->save(990003, 0, [['course_code' => $course, 'feature_code' => 'radio_training']], 30, 'parallel', 'keep');
$check(count($products->forItem(990001, 0)) === 2, 'Produkt mit zwei Features');
$check($products->forItem(990002, 990012)[0]->feature_code === 'all', 'Variation überschreibt Produkt');
$check($products->forItem(990002, 990099)[0]->feature_code === 'video_course', 'andere Variation erbt Produkt');
$check($products->save(990001, 0, [['course_code' => $course, 'feature_code' => 'question_bank'], ['course_code' => $course, 'feature_code' => 'exam_simulation']], null, 'extend', 'revoke_on_full_refund') === ['added' => 0, 'removed' => 0], 'erneutes Speichern ohne Änderung');

echo "Überweisung (BACS): on-hold bis Zahlungseingang\n";
$orders->put(900001, $alice, 'on-hold', null, [$item(1001, 990001)], 'bacs');
$sync->sync(900001);
$check(count($rows(900001)) === 2 && $statuses(900001) === ['pending'], 'zwei Zugänge „wartet auf Zahlung“');
$check(! $access->can($alice, $course, 'question_bank'), 'noch kein Zugriff');
$orders->put(900001, $alice, 'processing', '2026-01-10 09:00:00', [$item(1001, 990001)], 'bacs');
$sync->sync(900001);
$check($statuses(900001) === ['active'] && $rows(900001)[0]->valid_from === '2026-01-10 09:00:00' && $rows(900001)[0]->valid_until === null, 'Zahlungseingang aktiviert ab Zahldatum, dauerhaft');
$check($access->can($alice, $course, 'question_bank') && $access->can($alice, $course, 'exam_simulation') && ! $access->can($alice, $course, 'video_course'), 'Zugriff genau auf die gekauften Features');
$check($changes($sync->sync(900001)) === 0, 'wiederholtes Zahlungsereignis ändert nichts');
$orders->put(900001, $alice, 'completed', '2026-01-10 09:00:00', [$item(1001, 990001)], 'bacs');
$check($changes($sync->sync(900001)) === 0 && count($rows(900001)) === 2, 'processing → completed: keine doppelte Freischaltung');

echo "Sofort bezahlt (Stripe/PayPal/Klarna) und Dispute\n";
$orders->put(900002, $bob, 'processing', '2026-02-01 12:00:00', [$item(2001, 990001)], 'stripe');
$sync->sync(900002);
$check($statuses(900002) === ['active'], 'bezahlte Bestellung aktiviert');
$orders->put(900002, $bob, 'on-hold', '2026-02-01 12:00:00', [$item(2001, 990001)], 'stripe');
$sync->sync(900002);
$check($statuses(900002) === ['suspended'] && ! $access->can($bob, $course, 'question_bank'), 'Dispute (bezahlt → on-hold) setzt aus');
$orders->put(900002, $bob, 'completed', '2026-02-01 12:00:00', [$item(2001, 990001)], 'stripe');
$sync->sync(900002);
$check($statuses(900002) === ['active'] && $rows(900002)[0]->valid_from === '2026-02-01 12:00:00', 'Dispute gewonnen: reaktiviert, Beginn unverändert');
foreach (['klarna_payments', 'ppcp-gateway'] as $n => $method) {
    $orders->put(900010 + $n, $bob, 'pending', null, [$item(2100 + $n, 990002)], $method);
    $sync->sync(900010 + $n);
    $orders->put(900010 + $n, $bob, 'processing', '2026-02-02 08:00:00', [$item(2100 + $n, 990002)], $method);
    $sync->sync(900010 + $n);
    $check($statuses(900010 + $n) === ['active'], "{$method}: pending → bezahlt aktiviert");
}

echo "Erstattungen\n";
$orders->put(900003, $alice, 'completed', '2026-03-01 10:00:00', [$item(3001, 990002), $item(3002, 12345)]);
$sync->sync(900003);
$orders->put(900003, $alice, 'completed', '2026-03-01 10:00:00', [$item(3001, 990002), $item(3002, 12345, 0, 20.0, 1, 20.0)]);
$sync->sync(900003);
$check($statuses(900003) === ['active'], 'Erstattung einer anderen Position: Zugang bleibt');
$orders->put(900003, $alice, 'completed', '2026-03-01 10:00:00', [$item(3001, 990002, 0, 49.0, 0, 10.0), $item(3002, 12345, 0, 20.0, 1, 20.0)]);
$sync->sync(900003);
$check($statuses(900003) === ['active'], 'Teilerstattung des Lernprodukts (Standardregel): Zugang bleibt');
$orders->put(900003, $alice, 'completed', '2026-03-01 10:00:00', [$item(3001, 990002, 0, 49.0, 1, 49.0), $item(3002, 12345, 0, 20.0, 1, 20.0)]);
$sync->sync(900003);
$check($statuses(900003) === ['revoked'], 'vollständige Erstattung der Position widerruft');
$orders->put(900003, $alice, 'completed', '2026-03-01 10:00:00', [$item(3001, 990002), $item(3002, 12345)]);
$check($changes($sync->sync(900003)) === 0, 'Widerruf nach Erstattung ist endgültig');

// Bundle: zwei Lernpositionen, nur eine erstattet.
$orders->put(900004, $bob, 'processing', '2026-03-02 10:00:00', [$item(4001, 990001), $item(4002, 990002)]);
$sync->sync(900004);
$orders->put(900004, $bob, 'processing', '2026-03-02 10:00:00', [$item(4001, 990001), $item(4002, 990002, 0, 49.0, 1, 49.0)]);
$sync->sync(900004);
$byItem = [];
foreach ($rows(900004) as $r) {
    $byItem[(int) $r->order_item_id][] = $r->status;
}
$check(array_unique($byItem[4001]) === ['active'] && $byItem[4002] === ['revoked'], 'Bundle: nur die erstattete Position wird widerrufen');
$orders->put(900004, $bob, 'refunded', '2026-03-02 10:00:00', [$item(4001, 990001, 0, 49.0, 1, 49.0), $item(4002, 990002, 0, 49.0, 1, 49.0)]);
$sync->sync(900004);
$check($statuses(900004) === ['revoked'], 'Bestellung vollständig erstattet: alle Positionen widerrufen');

// Regel "keep": Erstattung lässt Zugang bestehen.
$orders->put(900005, $bob, 'completed', '2026-03-03 10:00:00', [$item(5001, 990003)]);
$sync->sync(900005);
$orders->put(900005, $bob, 'refunded', '2026-03-03 10:00:00', [$item(5001, 990003, 0, 49.0, 1, 49.0)]);
$sync->sync(900005);
$check($statuses(900005) === ['active'], 'Erstattungsregel „bleibt bestehen“');

echo "Storno, fehlgeschlagene Zahlung, Konto\n";
$orders->put(900006, $alice, 'processing', '2026-03-04 10:00:00', [$item(6001, 990003)]);
$sync->sync(900006);
$orders->put(900006, $alice, 'cancelled', '2026-03-04 10:00:00', [$item(6001, 990003)]);
$sync->sync(900006);
$check($statuses(900006) === ['revoked'], 'bezahlte Bestellung storniert: widerrufen (auch bei Regel „bleibt“)');
$orders->put(900007, $alice, 'failed', null, [$item(7001, 990003)]);
$sync->sync(900007);
$check($rows(900007) === [], 'fehlgeschlagene Zahlung legt nichts an');
$orders->put(900007, $alice, 'pending', null, [$item(7001, 990003)]);
$sync->sync(900007);
$orders->put(900007, $alice, 'failed', null, [$item(7001, 990003)]);
$sync->sync(900007);
$check($statuses(900007) === ['revoked'], 'erneut fehlgeschlagen: wartender Zugang widerrufen');
$orders->put(900007, $alice, 'processing', '2026-03-05 10:00:00', [$item(7001, 990003)]);
$sync->sync(900007);
$check($statuses(900007) === ['active'], 'später doch bezahlt: nie aktiver Zugang lebt wieder auf');
$orders->put(900008, 0, 'processing', '2026-03-05 10:00:00', [$item(8001, 990001)]);
$plan = $sync->sync(900008);
$check($rows(900008) === [] && in_array('no_account', $plan->warnings, true), 'Bestellung ohne Kundenkonto: kein Zugang, Warnung');
$orders->put(900008, $bob, 'processing', '2026-03-05 10:00:00', [$item(8001, 990001)]);
$sync->sync(900008);
$check($statuses(900008) === ['active'] && (int) $rows(900008)[0]->user_id === $bob, 'nach Kundenzuordnung freigeschaltet');
$orders->put(900008, $alice, 'processing', '2026-03-05 10:00:00', [$item(8001, 990001)]);
$sync->sync(900008);
$check((int) $rows(900008)[0]->user_id === $alice, 'Kundenwechsel überträgt den Zugang');
$orders->put(900008, $alice, 'processing', '2026-03-05 10:00:00', []);
$sync->sync(900008);
$check($statuses(900008) === ['revoked'], 'entfernte Position: widerrufen');
$check($sync->plan(900099) === null, 'unbekannte Bestellung: kein Plan');

echo "Laufzeit und Verlängerung\n";
$orders->put(900020, $bob, 'completed', '2026-04-01 00:00:00', [$item(20001, 990002, 990012)]);
$sync->sync(900020);
$orders->put(900021, $bob, 'completed', '2026-04-10 00:00:00', [$item(21001, 990002, 990012)]);
$sync->sync(900021);
$check($rows(900020)[0]->valid_until === '2026-05-01 00:00:00', 'Variation 30 Tage ab Zahldatum');
$check($rows(900021)[0]->valid_from === '2026-05-01 00:00:00' && $rows(900021)[0]->valid_until === '2026-05-31 00:00:00', 'Zweitkauf verlängert ab Ende des bestehenden Zugangs');
$orders->put(900022, $bob, 'completed', '2026-04-10 00:00:00', [$item(22001, 990003)]);
$sync->sync(900022);
$orders->put(900023, $bob, 'completed', '2026-04-12 00:00:00', [$item(23001, 990003)]);
$sync->sync(900023);
$check($rows(900023)[0]->valid_from === '2026-04-12 00:00:00', 'Modus „parallel“: Beginn am Zahldatum');
$summary = array_values(array_filter($entitlements->summaryForUser($bob), static fn ($s) => $s['feature'] === 'all'));
$check(count($summary) === 1 && $summary[0]['valid_until'] === '2026-05-31 00:00:00', 'Mein Lernen: ein Eintrag mit spätestem Ende');
$check(! $access->can($bob, $course, 'device_simulation'), 'abgelaufene „alle Funktionen“ (2026) sperren');
$check($entitlements->expireDue() >= 2 && $statuses(900020) === ['expired'], 'Cron markiert abgelaufene Zugänge');
$orders->put(900020, $bob, 'completed', '2026-04-01 00:00:00', [$item(20001, 990002, 990012)]);
$check($changes($sync->sync(900020)) === 0, 'abgelaufener Zugang lebt durch Abgleich nicht wieder auf');

echo "Doppelkauf\n";
$orders->put(900030, $bob, 'completed', '2026-05-01 00:00:00', [$item(30001, 990001)]);
$sync->sync(900030);
$orders->put(900031, $bob, 'completed', '2026-05-02 00:00:00', [$item(31001, 990001)]);
$sync->sync(900031);
$orders->put(900031, $bob, 'refunded', '2026-05-02 00:00:00', [$item(31001, 990001, 0, 49.0, 1, 49.0)]);
$sync->sync(900031);
$check($statuses(900031) === ['revoked'] && $access->can($bob, $course, 'question_bank'), 'eine von zwei Bestellungen erstattet: Zugang bleibt über die andere');

echo "Manuelle Aktionen\n";
$check($sync->suspendOrder(900030, 'PayPal-Konflikt') === 2 && $statuses(900030) === ['suspended'], 'Bestellung manuell ausgesetzt (Gateway ohne Dispute-Status)');
$check($changes($sync->sync(900030)) === 0 && $statuses(900030) === ['suspended'], 'manuelle Aussetzung bleibt trotz bezahlter Bestellung');
$sync->releaseOrder(900030, 'Konflikt geklärt');
$check($statuses(900030) === ['active'], 'Aufhebung: Shop-Status gilt wieder');
$orders->put(900040, $alice, 'on-hold', null, [$item(40001, 990002)], 'bacs');
$sync->sync(900040);
$sync->approveOrder(900040, 'Überweisungsbeleg liegt vor');
$check($statuses(900040) === ['active'], 'Überweisung vor Zahlungseingang freigegeben');
$check($changes($sync->sync(900040)) === 0, 'Freigabe bleibt, solange die Bestellung wartet');
$orders->put(900040, $alice, 'cancelled', null, [$item(40001, 990002)], 'bacs');
$sync->sync(900040);
$check($statuses(900040) === ['revoked'], 'Storno widerruft auch die manuelle Freigabe');
do_action('bootspruefung_suspend_order', 900001, 'Test'); // ohne WooCommerce nicht registriert – darf nichts tun
$threw = false;
try {
    $entitlements->grantManual($alice, $course, ['praxis_trainer'], null, '  ');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
$check($threw, 'manuelle Freigabe ohne Begründung abgewiesen');
$ids = $entitlements->grantManual($alice, $course, ['praxis_trainer', 'navigation_trainer'], '2030-01-01 00:00:00', 'Kulanz');
$check(count($ids) === 2 && $access->can($alice, $course, 'praxis_trainer'), 'manuelle Freigabe zweier Features');
$entitlements->extend($ids[0], '2020-01-01 00:00:00', 'Test abgelaufen');
$check(! $access->can($alice, $course, 'praxis_trainer'), 'Enddatum in der Vergangenheit sperrt');
$entitlements->extend($ids[0], null, 'dauerhaft');
$check($access->can($alice, $course, 'praxis_trainer'), 'dauerhaft verlängert');
$entitlements->setStatusManual($ids[1], 'revoked', 'Missbrauch');
$check(! $access->can($alice, $course, 'navigation_trainer'), 'manueller Widerruf');
$audit = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action IN ('entitlement_manual_grant','entitlement_extended','entitlement_manual_revoked','entitlement_create','entitlement_status')");
$check($audit > 10, 'Änderungen im Audit-Log');

echo "Abgleich und Filter\n";
$orders->put(900050, $alice, 'completed', '2026-06-01 00:00:00', [$item(50001, 990004)]);
$plans = iterator_to_array($sync->reconcile(), true);
$check(! isset($plans[900050]) || $plans[900050]->isEmpty(), 'Produkt ohne Zuordnung: nichts zu tun');
$products->save(990004, 0, [['course_code' => $course, 'feature_code' => 'device_simulation']], null, 'extend', 'revoke_on_full_refund');
$plans = iterator_to_array($sync->reconcile(), true);
$check(isset($plans[900050]) && $plans[900050]->changes[0]['type'] === 'create', 'Abgleich findet nachträglich zugeordnetes Produkt (Vorschau)');
$check($rows(900050) === [], 'Vorschau schreibt nichts');
$nonEmpty = array_filter($plans, static fn ($pl) => ! $pl->isEmpty());
$check(array_keys($nonEmpty) === [900050], 'Abgleich: alle übrigen Bestellungen ohne Abweichung');
$products->save(990004, 0, [], null, 'extend', 'revoke_on_full_refund');
$orders->put(900051, $alice, 'wc-paid-custom', '2026-06-02 00:00:00', [$item(51001, 990001)]);
$filter = static fn ($target, $order) => $order->status === 'wc-paid-custom' ? ['active', 'custom_gateway_paid'] : $target;
add_filter('bootspruefung_order_access_target', $filter, 10, 2);
$sync->sync(900051);
remove_filter('bootspruefung_order_access_target', $filter, 10);
$check($statuses(900051) === ['active'], 'Filter: eigener Gateway-Status als bezahlt');
$products->save(990001, 0, [], null, 'extend', 'revoke_on_full_refund');
$orders->put(900051, $alice, 'refunded', '2026-06-02 00:00:00', [$item(51001, 990001, 0, 49.0, 1, 49.0)]);
$sync->sync(900051);
$check($statuses(900051) === ['revoked'], 'Zuordnung gelöscht: Erstattung widerruft trotzdem (Regel im Zugang gespeichert)');

// Aufräumen.
$wpdb->query("DELETE FROM {$p}entitlement WHERE order_id >= 900001 OR user_id IN ({$alice}, {$bob})");
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id >= 990001");
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
