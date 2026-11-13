<?php

declare(strict_types=1);

/*
 * Abnahme Kursgutschein-Connector mit echtem WordPress, WooCommerce, Voucher Captain und Lernplattform
 * (TODO Voucher Captain, „Abnahmekriterien für den Connector“):
 *  1. ein gültiger Kursgutschein schaltet über die Produktzuordnung exakt Kurs und Bereiche frei,
 *  2. derselbe Schlüssel liefert denselben Grant; parallele/wiederholte Einlösungen erzeugen keinen zweiten Zugang
 *     und verbrauchen den Gutschein nicht mehrfach,
 *  3. die Laufzeit beginnt am bestätigten Einlösezeitpunkt und folgt der Produktlaufzeit,
 *  4. derselbe Code ist nicht gleichzeitig im Warenkorb und über „Mein Lernen“ einlösbar,
 *  5. Fehler/Timeout bleiben sicher wiederholbar; die Reservierung wird erneut verarbeitet oder freigegeben,
 *  6. Verlauf und Supportansicht führen Nutzer, Gutschein, Kursprodukt, Einlöse-ID und Zeitpunkt zusammen.
 * Dazu: Admin-Verknüpfung, Widerruf bei ungültig gemachtem Gutschein, Fehlercodes der App-Route.
 *
 *   WP_DIR=/pfad/zu/wordpress php learnboating-voucher-connector/tests/connector-integration-test.php
 * (intern: … race <code> <user> <start> – ein Teilnehmer des Parallel-Tests)
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 2).'/bootspruefung-learning/tests/integration/support/guard.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\VoucherGrants;
use Bootspruefung\Learning\Infrastructure\Lock;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Controllers\VoucherController;
use Bootspruefung\Learning\Settings\Settings;
use LearnBoating\VoucherConnector\Connector;
use VCAP\API\V1\RedemptionService;

global $wpdb;

if (($argv[1] ?? '') === 'race') {
    $start = (float) $argv[4];
    while (microtime(true) < $start) {
        usleep(1000);
    }
    $r = Connector::instance()->redeem($argv[2], (int) $argv[3]);
    echo wp_json_encode(is_wp_error($r) ? ['error' => $r->get_error_code()] : ['created' => $r['grant']['created'], 'ids' => array_column($r['grant']['entitlements'], 'id')]);
    exit(0);
}

$failures = 0;
$check = static function (bool $ok, string $label, $detail = null) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label.($ok || $detail === null ? '' : ' – '.wp_json_encode($detail))."\n";
    if (! $ok) {
        $failures++;
    }
};
$connector = Connector::instance();
$check($connector instanceof Connector, 'Connector aktiv (Voucher Captain + Grant-Vertrag 1.x gefunden)');
if (! $connector) {
    exit(1);
}
$p = $wpdb->prefix.'bp_';
$course = (new \Bootspruefung\Learning\Content\Repositories\ContentRepository())->courses()[0]->code ?? null;
if ($course === null) {
    fwrite(STDERR, "Kein veröffentlichter Kurs – zuerst das Content-Paket importieren.\n");
    exit(2);
}
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
update_option('vcap_attach_pdf', 'no');
add_filter('pre_wp_mail', '__return_true');
$user = static function (string $login): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer']);
};
$alice = $user('lbvc_alice');
$bob = $user('lbvc_bob');
$carol = $user('lbvc_carol');
wp_set_current_user(1);

$products = new ProductAccessRepository($wpdb);
$entitlements = new EntitlementService($wpdb);
$access = new AccessService();
$grants = apply_filters(VoucherGrants::FILTER, null);

// Kursprodukt mit Lernzugang: zwei Bereiche, 180 Tage, Erstattung widerruft.
$wcProduct = static function (string $name, string $price = '79'): WC_Product_Simple {
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_regular_price($price);
    $p->set_virtual(true);
    $p->save();

    return $p;
};
$courseProduct = $wcProduct('Kurs (Gutscheinziel)');
$products->save($courseProduct->get_id(), 0, [['course_code' => $course, 'feature_code' => 'question_bank'], ['course_code' => $course, 'feature_code' => 'exam_simulation']], 180, 'extend', 'revoke_on_full_refund');
$unmapped = $wcProduct('Ohne Lernzugang');

/** Gutscheinprodukt von Voucher Captain mit Kursziel. */
$voucherProduct = static function (array $post, string $name = 'Kursgutschein') use ($connector, $wcProduct): WC_Product_Simple {
    $p = $wcProduct($name, '79');
    $p->update_meta_data('_vcap_enabled', 'yes');
    $p->update_meta_data('_vcap_type', Connector::TYPE);
    if (isset($post['channels'])) {
        $p->update_meta_data('_vcap_channels', $post['channels']);
    }
    $connector->productSave($p, $post + ['_vcap_type' => Connector::TYPE]);
    $p->save();

    return wc_get_product($p->get_id());
};
/** Kauf des Gutscheins: bezahlte Bestellung, Voucher Captain stellt den Gutschein aus. */
$buy = static function (WC_Product $product, int $buyer): VCAP\Voucher {
    $order = wc_create_order(['customer_id' => $buyer]);
    $order->add_product($product, 1);
    $order->set_billing_email(get_userdata($buyer)->user_email);
    $order->calculate_totals();
    $order->payment_complete('lbvc-'.wp_generate_password(6, false, false));
    $vouchers = vcap()->vouchers->for_order($order->get_id());

    return $vouchers[0];
};

echo "Admin-Verknüpfung Gutschein-Ziel ↔ Kursprodukt\n";
$options = $connector->targetOptions();
$check(isset($options[$courseProduct->get_id().':0']) && ! isset($options[$unmapped->get_id().':0']), 'Auswahl zeigt nur Kursprodukte mit Lernzugang');
$good = $voucherProduct(['_lbvc_target' => $courseProduct->get_id().':0']);
$check($good->get_meta(Connector::PRODUCT_META) === $courseProduct->get_id().':0', 'gültiges Kursprodukt wird gespeichert');
$bad = $voucherProduct(['_lbvc_target' => $unmapped->get_id().':0'], 'Gutschein mit ungültigem Ziel');
$check($bad->get_meta(Connector::PRODUCT_META) === '', 'Kursprodukt ohne Lernzugang wird abgelehnt');
$self = $wcProduct('Gutschein mit eigenem Lernzugang');
$products->save($self->get_id(), 0, [['course_code' => $course, 'feature_code' => 'question_bank']], 30, 'extend', 'revoke_on_full_refund');
$self->update_meta_data('_vcap_enabled', 'yes');
$connector->productSave($self, ['_lbvc_target' => $courseProduct->get_id().':0']);
$self->save();
$check(wc_get_product($self->get_id())->get_meta(Connector::PRODUCT_META) === '', 'Gutscheinprodukt mit eigenem Lernzugang wird abgelehnt (würde schon beim Kauf freischalten)');

echo "Kauf, Kanal und Ziel\n";
$v1 = $buy($good, $alice);
$line = wc_get_order_item_meta($v1->order_item_id, VCAP\Issuer::LINE_META, true);
$check(($line[Connector::LINE_KEY]['product_id'] ?? 0) === $courseProduct->get_id(), 'Kursziel beim Kauf in der Bestellposition festgehalten');
$check(! $access->can($alice, $course, 'question_bank'), 'Kauf des Gutscheins schaltet noch nichts frei');
$check(VCAP\Channels::allowed($v1, Connector::CHANNEL) && ! VCAP\Channels::allowed($v1, VCAP\Channels::SHOP) && ! VCAP\Channels::allowed($v1, VCAP\Channels::COUNTER), 'Kursgutschein nur über „Mein Lernen“ einlösbar (nicht im Warenkorb, nicht an der Kasse)');
$cartOrder = wc_create_order(['customer_id' => $alice]);
$cartOrder->add_product($wcProduct('Ware'), 1);
$cartOrder->calculate_totals();
$check(true !== $cartOrder->apply_coupon($v1->code), 'Kursgutschein im Warenkorb abgelehnt');
$check($connector->is_course_voucher($v1->code) && ! $connector->is_course_voucher('GIBT-ES-NICHT'), 'is_course_voucher() für den App-Hinweis');

echo "Einlösung: exakt Kurs und Bereiche, Laufzeit ab Einlösung\n";
$preview = $connector->check($v1->code, $alice);
$check(is_array($preview) && count($preview['target']['scopes']) === 2, 'Vorschau ohne Reservierung', $preview);
$check(! is_wp_error(vcap()->redemptions->check($v1->code, ['target' => Connector::targetString(['product_id' => $courseProduct->get_id(), 'variation_id' => 0]), 'user_id' => $alice])), 'Vorschau lässt den Gutschein frei');
$t0 = time();
$r1 = $connector->redeem($v1->code, $alice);
$check(is_array($r1) && $r1['grant']['created'] === true, 'eingelöst', is_wp_error($r1) ? $r1->get_error_code() : null);
$key1 = Connector::key($v1);
$rows = $entitlements->forReference(VoucherGrants::reference($key1));
$check(array_map(static fn ($r) => $r->course_code.'/'.$r->feature_code, $rows) === [$course.'/exam_simulation', $course.'/question_bank'], 'genau Kurs und Bereiche des Kursprodukts');
$check($access->can($alice, $course, 'question_bank') && $access->can($alice, $course, 'exam_simulation') && ! $access->can($alice, $course, 'video_course'), 'Zugriff genau auf diese Bereiche');
$check(abs(strtotime($rows[0]->valid_from.' UTC') - $t0) < 120 && strtotime($rows[0]->valid_until.' UTC') - strtotime($rows[0]->valid_from.' UTC') === 180 * DAY_IN_SECONDS, 'Laufzeit ab bestätigter Einlösung, 180 Tage aus der Produktzuordnung');
$vr = vcap()->redemptions->get($key1);
$check(! is_wp_error($vr) && $vr['state'] === RedemptionService::STATE_COMMITTED && vcap()->vouchers->get($v1->id)->status === VCAP\Voucher::STATUS_REDEEMED, 'Gutschein erst nach der Freischaltung verbucht (eingelöst)');

echo "Idempotenz und Wiederholung\n";
$r1b = $connector->redeem($v1->code, $alice);
$check(is_array($r1b) && $r1b['grant']['created'] === false && array_column($r1b['grant']['entitlements'], 'id') === array_column($r1['grant']['entitlements'], 'id'), 'erneut eingelöst: derselbe Grant');
$check(count($entitlements->forUser($alice)) === 2, 'kein zweiter Zugang');
$r1c = $connector->redeem($v1->code, $bob);
$check(is_wp_error($r1c) && $r1c->get_error_code() === 'vcap_already_redeemed' && $entitlements->forUser($bob) === [], 'anderer Nutzer: bereits eingelöst, kein Zugang');
// Parallel: zwei Prozesse lösen denselben Gutschein im selben Augenblick ein.
$v2 = $buy($good, $bob);
$startAt = microtime(true) + 2.0;
$procs = [];
$pipes = [];
foreach ([1, 2] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, 'race', $v2->code, (string) $bob, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, getenv());
}
$out = [];
foreach ([1, 2] as $i) {
    $out[$i] = json_decode((string) stream_get_contents($pipes[$i][1]), true);
    stream_get_contents($pipes[$i][2]);
    proc_close($procs[$i]);
}
$bobRows = $entitlements->forUser($bob);
$commits = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.VCAP\Install::tables()['redemptions'].' WHERE voucher_id = %d AND state = %s', $v2->id, 'committed'));
$check(count($bobRows) === 2 && $commits === 1, 'parallel: ein Zugang (zwei Bereiche), Gutschein genau einmal verbucht', $out);
$again = $connector->redeem($v2->code, $bob);
$check(is_array($again) && $again['grant']['created'] === false && count($entitlements->forUser($bob)) === 2, 'Wiederholung nach dem Wettlauf: derselbe Grant');

echo "Warenkorb und „Mein Lernen“ schließen sich aus\n";
$both = $voucherProduct(['_lbvc_target' => $courseProduct->get_id().':0', 'channels' => ['shop']], 'Kursgutschein auch im Shop');
$check(in_array(Connector::CHANNEL, (array) $both->get_meta('_vcap_channels'), true), 'mit Kanalauswahl wird „learnboating“ ergänzt');
$v3 = $buy($both, $carol);
$shopOrder = wc_create_order(['customer_id' => $carol]);
$shopOrder->add_product($wcProduct('Ware 2'), 1);
$shopOrder->set_billing_email(get_userdata($carol)->user_email);
$shopOrder->calculate_totals();
$check(true === $shopOrder->apply_coupon($v3->code), 'mit Shop-Kanal: im Warenkorb anwendbar');
$shopOrder->update_status('on-hold');
$blocked = $connector->redeem($v3->code, $carol);
$check(is_wp_error($blocked) && in_array($blocked->get_error_code(), ['vcap_already_redeemed', 'vcap_reserved'], true) && $entitlements->forUser($carol) === [], 'Gutschein in offener Shop-Bestellung: „Mein Lernen“ abgelehnt', is_wp_error($blocked) ? $blocked->get_error_code() : $blocked);
wc_get_order($shopOrder->get_id())->update_status('cancelled');
$freed = $connector->redeem($v3->code, $carol);
$check(is_array($freed) && count($entitlements->forUser($carol)) === 2, 'Bestellung storniert: jetzt über „Mein Lernen“ eingelöst');
$shopOrder2 = wc_create_order(['customer_id' => $carol]);
$shopOrder2->add_product($wcProduct('Ware 3'), 1);
$shopOrder2->calculate_totals();
$check(true !== $shopOrder2->apply_coupon($v3->code), 'danach im Warenkorb abgelehnt (bereits eingelöst)');

echo "Fehler und Zeitüberschreitung: sicher wiederholbar\n";
$v4 = $buy($good, $carol);
$key4 = Connector::key($v4);
$lock = 'bp_voucher_grant_'.md5(VoucherGrants::reference($key4));
Lock::acquire($lock, 600, 'test-outage'); // Lernplattform „hängt“: der Grant meldet einen vorübergehenden Fehler.
$fail = $connector->redeem($v4->code, $carol);
$check(is_wp_error($fail) && $fail->get_error_code() === 'lb_temporary_failure', 'vorübergehender Fehler wird gemeldet');
$vr4 = vcap()->redemptions->get($key4);
$check(! is_wp_error($vr4) && $vr4['state'] === RedemptionService::STATE_RESERVED && isset(get_option(Connector::PENDING_OPTION, [])[$key4]), 'Gutschein bleibt reserviert und steht in der Warteschlange (kein Verlust)');
$cart4 = wc_create_order();
$cart4->add_product($wcProduct('Ware 4'), 1);
$cart4->calculate_totals();
$check(true !== $cart4->apply_coupon($v4->code), 'während der Reservierung auch sonst nicht einlösbar');
Lock::release($lock, 'test-outage');
$connector->retry();
$vr4 = vcap()->redemptions->get($key4);
$check(! is_wp_error($vr4) && $vr4['state'] === RedemptionService::STATE_COMMITTED && $grants->get($key4) !== null && ! isset(get_option(Connector::PENDING_OPTION, [])[$key4]), 'Warteschlange: freigeschaltet und verbucht, sobald die Lernplattform antwortet');
$retry = $connector->redeem($v4->code, $carol);
$check(is_array($retry) && $retry['grant']['created'] === false, 'Kunde wiederholt danach: dieselbe Freischaltung');
// Endgültiger Fehler nach der Reservierung: Kursprodukt verliert den Lernzugang → Reservierung wird freigegeben.
$v5 = $buy($good, $carol);
$key5 = Connector::key($v5);
$lock5 = 'bp_voucher_grant_'.md5(VoucherGrants::reference($key5));
Lock::acquire($lock5, 600, 'test-outage');
$connector->redeem($v5->code, $carol);
Lock::release($lock5, 'test-outage');
$wpdb->update($p.'product_access', ['active' => 0], ['product_id' => $courseProduct->get_id()]);
$connector->retry();
$vr5 = vcap()->redemptions->get($key5);
$check(! is_wp_error($vr5) && $vr5['state'] === RedemptionService::STATE_RELEASED && vcap()->vouchers->get($v5->id)->status === VCAP\Voucher::STATUS_ACTIVE && $grants->get($key5) === null, 'kein Zugang mehr möglich: Reservierung freigegeben, Gutschein wieder gültig');
$unavailable = $connector->redeem($v5->code, $carol);
$check(is_wp_error($unavailable) && $unavailable->get_error_code() === 'lb_target_unmapped' && vcap()->vouchers->get($v5->id)->status === VCAP\Voucher::STATUS_ACTIVE, 'Ziel ohne Lernzugang: abgelehnt ohne Reservierung');
$connector->targetNotice();
$check(isset(get_transient('lbvc_broken_targets')[$good->get_id()]), 'Admin-Hinweis nennt Kursgutscheine mit ungültigem Ziel');
$wpdb->update($p.'product_access', ['active' => 1], ['product_id' => $courseProduct->get_id()]);
delete_transient('lbvc_broken_targets');

echo "Fehlercodes der App-Route\n";
$v6 = $buy($good, $alice);
$v6->expires_at = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
vcap()->vouchers->update($v6);
$app = static function (string $code, int $as, array $extra = []): array {
    wp_set_current_user($as);
    $request = new WP_REST_Request('POST', '/boatingschool/v1/vouchers/redeem');
    $request->set_param('code', $code);
    foreach ($extra as $k => $v) {
        $request->set_param($k, $v);
    }
    try {
        $r = (new VoucherController())->redeem($request);

        return ['status' => $r->get_status(), 'code' => '', 'data' => $r->get_data()['data']];
    } catch (ApiException $e) {
        return ['status' => $e->status, 'code' => $e->errorCode, 'message' => $e->getMessage()];
    } finally {
        wp_set_current_user(1);
    }
};
$wpdb->query("DELETE FROM {$p}mobile_rate_limit");
$check($app('GIBT-ES-NICHT', $alice)['code'] === 'VOUCHER_INVALID', 'unbekannter Code → VOUCHER_INVALID');
$check($app($v1->code, $bob)['code'] === 'VOUCHER_REDEEMED', 'eingelöst → VOUCHER_REDEEMED');
$check($app($v6->code, $alice)['code'] === 'VOUCHER_EXPIRED', 'abgelaufen → VOUCHER_EXPIRED');
$v7 = $buy($good, $alice);
$wrong = $app($v7->code, $alice, ['course_id' => '999999']);
$check($wrong['code'] === 'VOUCHER_WRONG_COURSE' && $entitlements->forReference(VoucherGrants::reference(Connector::key($v7))) === [], 'falscher Kurs → VOUCHER_WRONG_COURSE, nichts eingelöst');
$shopVoucher = new VCAP\Voucher();
$shopCoupon = new WC_Coupon();
$shopCoupon->set_code('lbvc-shop-'.strtolower(wp_generate_password(6, false, false)));
$shopCoupon->set_discount_type('fixed_cart');
$shopCoupon->set_amount(10);
$shopCoupon->update_meta_data('_vcap_voucher', 'yes');
$shopCoupon->save();
$shopVoucher->code = wc_format_coupon_code($shopCoupon->get_code());
$shopVoucher->coupon_id = $shopCoupon->get_id();
$shopVoucher->order_item_id = 96000000 + $shopCoupon->get_id();
$shopVoucher->initial_amount = 10;
$shopVoucher->currency = get_woocommerce_currency();
$shopVoucher->token = VCAP\VoucherRepository::new_token();
$shopVoucher->id = vcap()->vouchers->insert($shopVoucher);
$check($app($shopVoucher->code, $alice)['code'] === 'VOUCHER_NOT_FOR_COURSES', 'Shop-Gutschein → VOUCHER_NOT_FOR_COURSES');
$courseId = (string) $grants->target($courseProduct->get_id())['scopes'][0]['course_id'];
$ok = $app($v7->code, $alice, ['course_id' => $courseId]);
$check($ok['status'] === 200 && $ok['data']['redeemed'] === true, 'aus dem passenden Kurs heraus eingelöst (App-Route, Nutzer aus der Anmeldung)', $ok);
$check(VoucherController::problem(new WP_Error('vcap_reserved', ''))->errorCode === 'VOUCHER_RESERVED' && VoucherController::problem(new WP_Error('lb_access_exists', 'x'))->errorCode === 'ACCESS_NOT_POSSIBLE' && VoucherController::problem(new WP_Error('lb_temporary_failure', ''))->status === 503, 'Abbildung reserviert / kein Zugang möglich / vorübergehend');

echo "Widerruf: Gutschein ungültig gemacht\n";
$check(vcap()->issuer->void(vcap()->vouchers->get($v1->id), 'Kauf erstattet (Test)'), 'Gutschein ungültig gemacht');
$after = array_unique(array_map(static fn ($r) => $r->status, $entitlements->forReference(VoucherGrants::reference($key1))));
$check($after === ['revoked'], 'Zugang nach Erstattungsregel widerrufen', $after);

echo "Verlauf und Supportansicht\n";
$events = vcap()->vouchers->events($v2->id);
$granted = array_values(array_filter((array) $events, static fn ($e) => ($e['event'] ?? $e->event ?? '') === 'learnboating_granted'));
$note = (string) ($granted[0]['note'] ?? $granted[0]->note ?? '');
$check(count($granted) === 1 && str_contains($note, '#'.$bob) && str_contains($note, Connector::key($v2)) && str_contains($note, (string) $courseProduct->get_id()), 'Gutscheinverlauf (Voucher Captain): Nutzer, Kursprodukt, Einlöse-ID – genau einmal', $granted);
$meta = json_decode((string) $entitlements->forReference(VoucherGrants::reference(Connector::key($v2)))[0]->meta, true);
$check(($meta['voucher_id'] ?? 0) === $v2->id && ($meta['redemption_key'] ?? '') === Connector::key($v2) && ($meta['source_reference'] ?? '') === 'vcap:voucher:'.$v2->id && ! empty($meta['redeemed_at']), 'Zugang (Lernplattform): Gutschein-ID, Einlöse-ID, Herkunft, Zeitpunkt');
$url = apply_filters('bootspruefung_voucher_admin_url', '', $meta, null);
$check(str_contains((string) $url, 'page=vcap-vouchers&voucher='.$v2->id), 'Supportansicht verlinkt den Gutschein in Voucher Captain');
$found = $entitlements->search(['search' => Connector::key($v2)]);
$check($found['total'] === 2 && (int) $found['rows'][0]->user_id === $bob, 'Adminsuche nach Einlöse-ID findet Nutzer und Zugänge');

// Aufräumen.
foreach ([$alice, $bob, $carol] as $id) {
    $wpdb->delete($p.'entitlement', ['user_id' => $id]);
    wp_delete_user($id);
}
$wpdb->delete($p.'product_access', ['product_id' => $courseProduct->get_id()]);
$wpdb->delete($p.'product_access', ['product_id' => $self->get_id()]);
update_option(Settings::OPTION, $settingsBefore);
delete_option('vcap_attach_pdf');
delete_option(Connector::PENDING_OPTION);

echo $failures === 0 ? "\nAlle Abnahmeprüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
