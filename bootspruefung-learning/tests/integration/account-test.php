<?php

declare(strict_types=1);

/*
 * Integrationstest Konto und Garantie mit echtem WooCommerce: Geld-zurück-Anfrage nur einmal (QA-09),
 * Nutzungsstand bleibt nach Datenlöschung (QA-06), Selbstbedienung „Meine Daten“ (QA-08),
 * Kontolöschung nach bestätigtem Antrag mit anonymisierten Bestellungen (LAW-04), Gruppenplatz (QA-05).
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/account-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse veröffentlicht) und woocommerce-setup.php.
 */

ob_start(); // WooCommerce setzt Session-Cookies; Ausgabe puffern, damit keine Header-Warnungen entstehen
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\MoneyBack;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Commerce\WooCommerce\MoneyBackWoo;
use Bootspruefung\Learning\Privacy\AccountDeletion;
use Bootspruefung\Learning\Privacy\PersonalData;
use Bootspruefung\Learning\Privacy\SelfService;
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
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only', 'privacy_requests_auto' => true]));
$mails = [];
add_filter('pre_wp_mail', static function ($null, $atts) use (&$mails) {
    $mails[] = ['to' => is_array($atts['to'] ?? null) ? implode(',', $atts['to']) : (string) ($atts['to'] ?? ''), 'subject' => (string) ($atts['subject'] ?? ''), 'message' => (string) ($atts['message'] ?? '')];

    return true;
}, 10, 2);
$customer = static function (string $login, string $role = 'customer'): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => $role, 'first_name' => 'Erika', 'last_name' => 'Muster']);
};
foreach (get_posts(['post_type' => 'user_request', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'acct_']) as $rid) {
    wp_delete_post((int) $rid, true);
}

// Produkt mit Garantie (14 Tage, 20 %) auf den Fragenkatalog SKN.
$product = new WC_Product_Simple();
$product->set_name('Garantie-Test SKN');
$product->set_regular_price('30');
$product->set_virtual(true);
$product->set_status('publish');
$productId = (int) $product->save();
(new ProductAccessRepository($wpdb))->save($productId, 0, [['course_code' => 'SKN', 'feature_code' => 'question_bank']], 182, 'extend', 'revoke_on_full_refund');
MoneyBack::setPolicy($productId, 14, 20.0);
$qids = $wpdb->get_col("SELECT DISTINCT mq.question_id FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id JOIN {$p}question q ON q.id = mq.question_id WHERE c.code = 'SKN' AND q.status = 'published' ORDER BY mq.question_id");
$buy = static function (int $userId) use ($productId): WC_Order {
    $order = wc_create_order(['customer_id' => $userId]);
    $order->add_product(wc_get_product($productId), 1);
    $order->set_billing_first_name('Erika');
    $order->set_billing_last_name('Muster');
    $order->set_billing_address_1('Hafenstraße 1');
    $order->set_billing_city('Kiel');
    $order->set_billing_postcode('24103');
    $order->set_billing_country('DE');
    $order->set_billing_company('Segelverein Kiel e. V.');
    $order->set_billing_phone('0431 123456');
    $order->set_billing_email((string) get_userdata($userId)->user_email);
    $order->set_customer_ip_address('192.0.2.44');
    $order->set_payment_method('stripe');
    $order->calculate_totals();
    $order->save();
    $order->payment_complete('ch_acct_'.wp_generate_password(8, false));

    return wc_get_order($order->get_id());
};
$learn = static function (int $userId, int $count) use ($wpdb, $p, $qids): void {
    $now = gmdate('Y-m-d H:i:s');
    foreach (array_slice($qids, 0, $count) as $qid) {
        $wpdb->replace($p.'progress', ['user_id' => $userId, 'question_id' => (int) $qid, 'attempts' => 1, 'correct_count' => 1, 'learn_status' => 'learning', 'first_answered_at' => $now, 'last_answered_at' => $now]);
    }
};

echo "Geld-zurück-Anfrage nur einmal (QA-09)\n";
$mbUser = $customer('acct_garantie');
$order = $buy($mbUser);
$check(MoneyBackWoo::status($order)['eligible'], 'bezahlte Bestellung: Geld zurück möglich');
// Zwei gleichzeitige Anfragen: Beide haben die Bestellung geladen, bevor die erste gespeichert hat.
$tabA = wc_get_order($order->get_id());
$tabB = wc_get_order($order->get_id());
$mails = [];
$r1 = MoneyBackWoo::submitRequest($tabA, $mbUser);
$r2 = MoneyBackWoo::submitRequest($tabB, $mbUser);
$audits = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'money_back_requested' AND object_type = 'order' AND object_id = %s", (string) $order->get_id()));
$notes = array_filter(wc_get_order_notes(['order_id' => $order->get_id()]), static fn ($n) => str_contains($n->content, 'Geld-zurück-Garantie angefragt'));
$check($r1['result'] === 'requested' && $r2['result'] === 'already_requested' && $r2['request'] !== null && $r2['request']['at'] === $r1['request']['at'], 'zweiter Aufruf liefert die bestehende Anfrage zurück');
$check($audits === 1 && count($notes) === 1 && count($mails) === 2, "genau eine Anfrage, eine Notiz, zwei Mails (Betreiber + Kunde) – {$audits} Protokoll, ".count($notes).' Notiz(en), '.count($mails).' Mail(s)');
$check(MoneyBackWoo::status(wc_get_order($order->get_id()))['reason'] === 'requested', 'Bestellung zeigt „angefragt“');
$mbFooter = \Bootspruefung\Learning\Support\Mail::footer();
$mbCustomer = array_values(array_filter($mails, static fn ($m) => str_starts_with($m['subject'], 'Deine Geld-zurück-Anfrage')));
$mbAdmin = array_values(array_filter($mails, static fn ($m) => str_starts_with($m['subject'], 'Geld-zurück-Anfrage')));
$check($mbFooter !== '' && count($mbCustomer) === 1 && str_ends_with($mbCustomer[0]['message'], "\n-- \n".$mbFooter) && count($mbAdmin) === 1 && ! str_contains($mbAdmin[0]['message'], "\n-- \n"), 'Bestätigung an den Kunden mit Anbieter-Angaben, interne Mail an den Betreiber ohne (T58)');
wp_set_current_user($mbUser);
$order2 = $buy($mbUser);
ob_start();
MoneyBackWoo::renderAccountSection($mbUser);
$html = (string) ob_get_clean();
wp_set_current_user(1);
$check(str_contains($html, 'this.dataset.sent') && str_contains($html, 'Prüfungssimulationen zählen nicht'), 'Knopf sperrt nach dem ersten Absenden; Hinweis nennt, was als Nutzung zählt (Prüfungssimulationen nicht)');
$check(str_contains($html, 'shop_table_responsive bp-money-back-table') && str_contains($html, 'data-title="Gelernt / Grenze"') && str_contains($html, 'data-title="Frist bis"') && str_contains($html, '<td class="actions bp-money-back-action"><form') && str_contains($html, '<span class="screen-reader-text">Aktion</span>'), 'Garantie-Tabelle mobil gestapelt (shop_table_responsive, Beschriftung je Zelle, Knopf eigene Zeile, Spaltenkopf „Aktion“ für Screenreader)');

echo "Datenlöschung setzt die Garantie-Nutzung nicht zurück (QA-06)\n";
$learn($mbUser, (int) ceil(count($qids) * 0.25));
$before = MoneyBackWoo::status(wc_get_order($order2->get_id()));
$check($before['reason'] === 'too_much_progress', "vor der Löschung: Lernstand {$before['progress']} % über der Grenze");
$erased = PersonalData::erase((string) get_userdata($mbUser)->user_email);
$frozen = wc_get_order($order2->get_id())->get_meta(MoneyBack::USAGE_META);
$after = MoneyBackWoo::status(wc_get_order($order2->get_id()));
$check($erased['items_removed'] && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}progress WHERE user_id = %d", $mbUser)) === 0, 'Lerndaten gelöscht');
$check(is_array($frozen) && abs((float) $frozen['exact'] - (float) $before['usage']['exact']) < 0.0001 && $after['reason'] === 'too_much_progress' && abs($after['progress'] - $before['progress']) < 0.01, "nach der Löschung: festgeschriebener Stand {$after['progress']} % gilt weiter (Status {$after['reason']})");
$learn($mbUser, 2);
PersonalData::erase((string) get_userdata($mbUser)->user_email);
$check(abs((float) wc_get_order($order2->get_id())->get_meta(MoneyBack::USAGE_META)['exact'] - (float) $before['usage']['exact']) < 0.0001, 'zweite Löschung mit weniger Lernstand: Höchstwert bleibt');
ob_start();
(new MoneyBackWoo())->adminOrder(wc_get_order($order2->get_id()));
$check(str_contains((string) ob_get_clean(), 'Lerndaten am'), 'Bestellansicht (Verwaltung) nennt den festgeschriebenen Stand');

echo "Selbstbedienung „Meine Daten“ (QA-08)\n";
$selfUser = $customer('acct_selbst');
$menu = SelfService::menu(['dashboard' => 'Dashboard', 'orders' => 'Bestellungen', 'edit-account' => 'Konto', 'customer-logout' => 'Abmelden']);
$keys = array_keys($menu);
$check(isset($menu[SelfService::ENDPOINT]) && array_search(SelfService::ENDPOINT, $keys, true) === array_search('customer-logout', $keys, true) - 1, '„Meine Daten“ im Menü „Mein Konto“ vor „Abmelden“');
$check(isset(WC()->query->get_query_vars()[SelfService::ENDPOINT]) && str_contains((string) SelfService::url(), SelfService::ENDPOINT), 'Endpunkt registriert');
wp_set_current_user($selfUser);
ob_start();
SelfService::render();
$page = (string) ob_get_clean();
$check(str_contains($page, 'Meine Daten exportieren') && str_contains($page, 'Konto löschen beantragen') && str_contains($page, 'Was aus gesetzlichen Gründen bleibt') && str_contains($page, 'Bestellnummer') && str_contains($page, 'bp_privacy_request'), 'Seite erklärt Export und Löschung, was gelöscht wird und was bleibt');
$mails = [];
$ex = SelfService::request($selfUser, 'export');
$exportReq = (int) $ex['request_id'];
$check($ex['result'] === 'created' && get_post_status($exportReq) === 'request-pending' && count($mails) === 1 && str_contains($mails[0]['message'], 'action=confirmaction'), 'Export angefordert: WordPress-Antrag mit Bestätigungsmail');
$check(SelfService::request($selfUser, 'export')['result'] === 'resent' && count($mails) === 2, 'erneut: Bestätigungsmail noch einmal, kein zweiter Antrag');
ob_start();
SelfService::render();
$page = (string) ob_get_clean();
$check(str_contains($page, 'Bestätigungsmail erneut senden') && str_contains($page, 'einen Datenexport angefordert'), 'Seite zeigt den offenen Antrag');
wp_set_current_user(1);
$key = wp_generate_user_request_key($exportReq);
$check(! is_wp_error(wp_validate_user_request_key($exportReq, $key)), 'Bestätigungslink gültig');
do_action('user_request_action_confirmed', $exportReq); // wie wp-login.php?action=confirmaction
$check(get_post_status($exportReq) === 'request-confirmed' && (function_exists('as_has_scheduled_action') ? as_has_scheduled_action(AccountDeletion::EXPORT_HOOK, [$exportReq], 'bootspruefung-learning') : (bool) wp_next_scheduled(AccountDeletion::EXPORT_HOOK, [$exportReq])), 'Bestätigung: Export im Hintergrund geplant');
$check(str_contains((string) apply_filters('user_request_action_confirmed_message', '', $exportReq), 'Download-Link'), 'Bestätigungsseite: Download-Link kommt per E-Mail');
if (class_exists('ZipArchive')) {
    $mails = [];
    $ok = AccountDeletion::processExport($exportReq);
    $file = (string) get_post_meta($exportReq, '_export_file_name', true);
    $check($ok && get_post_status($exportReq) === 'request-completed' && $file !== '' && is_file(wp_privacy_exports_dir().$file), 'Export erzeugt (ZIP), Antrag erledigt');
    $check(count($mails) === 1 && $mails[0]['to'] === 'acct_selbst@example.test' && str_contains($mails[0]['message'], wp_privacy_exports_url()), 'Download-Link an die Kontoadresse gemailt');
    if ($file !== '') {
        wp_delete_file(wp_privacy_exports_dir().$file);
    }
} else {
    echo "  (ZipArchive fehlt: Export-Erzeugung übersprungen)\n";
}
function_exists('as_unschedule_all_actions') ? as_unschedule_all_actions(AccountDeletion::EXPORT_HOOK, [$exportReq], 'bootspruefung-learning') : wp_clear_scheduled_hook(AccountDeletion::EXPORT_HOOK, [$exportReq]);
wp_delete_post($exportReq, true);
$staff = $customer('acct_shopmanager', 'shop_manager');
$check(SelfService::request($staff, 'delete')['result'] === 'protected', 'Shop-Manager: keine Selbstlöschung');
wp_set_current_user($staff);
ob_start();
SelfService::render();
$check(str_contains((string) ob_get_clean(), 'Verwaltungsrechte'), 'Seite für Verwaltungskonten ohne Löschknopf');
wp_set_current_user(1);

echo "Kontolöschung nach bestätigtem Antrag (LAW-04, QA-05)\n";
$erasureBefore = get_option('woocommerce_erasure_request_removes_order_data', 'no');
update_option('woocommerce_erasure_request_removes_order_data', 'yes'); // T45: WooCommerce-Einstellung „Daten aus Bestellungen entfernen“ an
$delUser = $customer('acct_loeschen');
$delOrder = $buy($delUser);
$txn = $delOrder->get_transaction_id();
$total = $delOrder->get_total();
$number = $delOrder->get_order_number();
$learn($delUser, 5);
// Mitglied in der Gruppe einer anderen Person (Platz soll frei werden).
$groups = new GroupService($wpdb);
$owner = $customer('acct_gruppe');
$now = gmdate('Y-m-d H:i:s');
$wpdb->insert($p.'learning_group', ['owner_user_id' => $owner, 'name' => 'Testgruppe', 'scopes' => wp_json_encode([['course' => 'SKN', 'feature' => 'question_bank']]), 'seats' => 1, 'status' => 'active', 'valid_from' => $now, 'valid_until' => null, 'join_token' => 'g'.bin2hex(random_bytes(16)), 'reason' => 'test', 'created_at' => $now, 'updated_at' => $now]);
$groupId = (int) $wpdb->insert_id;
$groups->join((string) $groups->find($groupId)->join_token, $delUser);
$check($groups->freeSeats($groups->find($groupId)) === 0, 'Gruppenplatz belegt');
$mails = [];
$dr = SelfService::request($delUser, 'delete');
$delReq = (int) $dr['request_id'];
$check($dr['result'] === 'created' && get_userdata($delUser) !== false && preg_match('/confirm_key=([A-Za-z0-9]+)/', $mails[0]['message'] ?? '', $km) === 1, 'Löschantrag gestellt, Bestätigungsmail mit Link; Konto besteht noch');
$check(! is_wp_error(wp_validate_user_request_key($delReq, $km[1] ?? '')), 'Schlüssel aus der Mail gültig');
// T42: Mail-Link löscht nicht beim Aufruf, erst der Knopf (POST).
$AD = \Bootspruefung\Learning\Privacy\AccountDeletion::class;
$check(str_contains((string) ($mailsBefore = $mails[0]['message'] ?? ''), 'Konto jetzt löschen') && str_contains($mailsBefore, 'sofort und endgültig'), 'Mail erklärt: Link öffnet eine Seite, erst der Knopf löscht');
$check($AD::needsButton($delReq, 'GET', false) && $AD::needsButton($delReq, 'POST', false) && ! $AD::needsButton($delReq, 'POST', true), 'Aufruf des Links (GET, z. B. Link-Scanner) braucht den Knopf; erst POST mit Bestätigung führt aus');
$check(! $AD::needsButton((int) $exportReq, 'GET', false), 'Export-Anträge unverändert (kein Knopf)');
$form = $AD::confirmForm($delReq, (string) ($km[1] ?? ''));
$check(str_contains($form, 'method="post"') && str_contains($form, 'name="bp_confirm_deletion"') && str_contains($form, 'confirm_key=') && str_contains($form, 'Konto jetzt löschen') && str_contains($form, 'Abbrechen'), 'Bestätigungsseite: Knopf „Konto jetzt löschen“ (POST) und Abbrechen');
$check(has_action('login_form_confirmaction', [$AD, 'confirmPage']) !== false && get_userdata($delUser) !== false, 'wp-login.php?action=confirmaction zeigt zuerst die Seite; Konto besteht noch');
// Zugang ohne Bestellung (manuell) – nach der Löschung darf keine Zeile ohne Konto bleiben.
(new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb))->grantManual($delUser, 'SKN', ['all'], null, 'Test T42');
$mails = [];
do_action('user_request_action_confirmed', $delReq); // wie wp-login.php?action=confirmaction
clean_user_cache($delUser);
$check(get_userdata($delUser) === false && get_post_status($delReq) === 'request-completed', 'nach der Bestätigung: Konto gelöscht, Antrag erledigt');
$anon = wc_get_order($delOrder->get_id());
$personal = [$anon->get_billing_first_name(), $anon->get_billing_last_name(), $anon->get_billing_address_1(), $anon->get_billing_city(), $anon->get_billing_phone(), $anon->get_billing_email(), $anon->get_customer_ip_address()];
$check($anon instanceof WC_Order && $anon->get_customer_id() === 0 && array_intersect($personal, ['Erika', 'Muster', 'Hafenstraße 1', 'Kiel', '0431 123456', 'acct_loeschen@example.test', '192.0.2.44']) === [] && $anon->get_meta('_anonymized') === 'yes', 'Bestellung anonymisiert (Name, Anschrift, Telefon, E-Mail, IP): '.wp_json_encode($personal, JSON_UNESCAPED_UNICODE));
$check($anon->get_total() === $total && $anon->get_order_number() === $number && $anon->get_transaction_id() === $txn && count($anon->get_items()) === 1 && $anon->get_date_paid() !== null, 'Bestellnummer, Betrag, Datum, Position und Transaktionsnummer bleiben');
$check($anon->get_billing_company() === 'Segelverein Kiel e. V.' && $anon->get_billing_country() === 'DE', 'für Steuer und Buchhaltung bleiben Firma und Land (B2B, OSS; T45)');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}progress WHERE user_id = %d", $delUser)) === 0, 'Lerndaten gelöscht');
$check($groups->freeSeats($groups->find($groupId)) === 1, 'Gruppenplatz wieder frei (QA-05)');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}entitlement WHERE user_id = %d AND (order_id IS NULL OR status <> 'revoked')", $delUser)) === 0, 'keine Zugänge ohne Konto: ohne Bestellung entfernt, zu Bestellungen beendet (T42)');
$check((bool) array_filter($mails, static fn ($m) => $m['to'] === 'acct_loeschen@example.test'), 'Bestätigung der Löschung an die Person gemailt');
$check(str_contains((string) apply_filters('user_request_action_confirmed_message', '', $delReq), 'Konto ist gelöscht'), 'Bestätigungsseite: „Dein Konto ist gelöscht“');
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'account_deleted' AND object_id = '".(int) $delReq."'") === 1, 'Protokoll: Konto gelöscht (ohne Klartext-Kennung)');

// T45: WooCommerce-Einstellung aus → Bestellungen bleiben unverändert, Konto wird trotzdem gelöscht.
update_option('woocommerce_erasure_request_removes_order_data', 'no');
$keepUser = $customer('acct_behalten_bestellung');
$keepOrder = $buy($keepUser);
$kr = SelfService::request($keepUser, 'delete');
do_action('user_request_action_confirmed', (int) $kr['request_id']);
clean_user_cache($keepUser);
$kept = wc_get_order($keepOrder->get_id());
$check(get_userdata($keepUser) === false && $kept->get_billing_last_name() === 'Muster' && $kept->get_meta('_anonymized') !== 'yes', 'WooCommerce-Einstellung „Daten aus Bestellungen entfernen“ aus: Konto gelöscht, Bestellung unverändert aufbewahrt');
$check(str_contains((string) apply_filters('user_request_action_confirmed_message', '', (int) $kr['request_id']), 'Bestellungen und Rechnungsangaben bewahren wir auf'), 'Bestätigung nennt die Aufbewahrung passend zur Einstellung');
update_option('woocommerce_erasure_request_removes_order_data', $erasureBefore);

// Verwaltungskonto: nie automatisch löschen.
$staffReq = (int) wp_create_user_request('acct_shopmanager@example.test', 'remove_personal_data', [], 'pending');
do_action('user_request_action_confirmed', $staffReq);
clean_user_cache($staff);
$check(get_userdata($staff) !== false && get_post_status($staffReq) === 'request-confirmed', 'Shop-Manager: bestätigter Antrag bleibt für die Verwaltung, Konto bleibt');
wp_delete_post($staffReq, true);

// Ausgeschaltet: WordPress-Standard (Betreiber bearbeitet), Konto bleibt.
update_option(Settings::OPTION, array_merge(Settings::all(), ['privacy_requests_auto' => false]));
$keepUser = $customer('acct_manuell');
$keepReq = (int) wp_create_user_request('acct_manuell@example.test', 'remove_personal_data', [], 'pending');
do_action('user_request_action_confirmed', $keepReq);
clean_user_cache($keepUser);
$check(get_userdata($keepUser) !== false && get_post_status($keepReq) === 'request-confirmed', 'Einstellung aus: bestätigter Antrag wartet auf die Verwaltung');
update_option(Settings::OPTION, array_merge(Settings::all(), ['privacy_requests_auto' => true]));
// Betreiber führt die Löschung in den Werkzeugen aus → danach wird auch das Konto gelöscht.
require_once ABSPATH.'wp-admin/includes/privacy-tools.php';
_wp_privacy_completed_request($keepReq);
do_action('wp_privacy_personal_data_erased', $keepReq);
clean_user_cache($keepUser);
$check(get_userdata($keepUser) === false, 'Löschung über die Werkzeuge: Konto wird ebenfalls gelöscht');
wp_delete_post($keepReq, true);
wp_delete_post($delReq, true);

// Aufräumen
foreach ([$mbUser, $selfUser, $staff, $owner] as $uid) {
    if (get_userdata($uid)) {
        wp_delete_user($uid);
    }
}
$wpdb->delete($p.'learning_group', ['id' => $groupId]);
foreach ([$order, $order2, $delOrder] as $o) {
    $wpdb->delete($p.'entitlement', ['order_id' => $o->get_id()]);
    wc_get_order($o->get_id())?->delete(true);
}
(new ProductAccessRepository($wpdb))->save($productId, 0, [], null, 'extend', 'revoke_on_full_refund');
MoneyBack::setPolicy($productId, null, null);
wp_delete_post($productId, true);
foreach ([$selfUser, $staff, $delUser] as $uid) {
    foreach (['export', 'delete'] as $type) {
        $wpdb->delete($p.'mobile_rate_limit', ['bucket_hash' => hash('sha256', 'web-privacy|'.$type.'|'.$uid.'|'.wp_salt('nonce'))]);
    }
}
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
