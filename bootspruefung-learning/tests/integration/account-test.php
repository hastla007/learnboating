<?php

declare(strict_types=1);

/*
 * Integrationstest Konto und Garantie mit echtem WooCommerce: Geld-zurück-Anfrage nur einmal (QA-09),
 * Nutzungsstand bleibt nach Datenlöschung (QA-06), Selbstbedienung „Meine Daten“ (QA-08),
 * Kontolöschung nach bestätigtem Antrag mit anonymisierten Bestellungen (LAW-04), Gruppenplatz (QA-05),
 * vollständiger Datenexport mit Kindtabellen und Geld-zurück-Angaben (T111), Multisite-Zweige der Kontolöschung (T112).
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
// T111: Bestell-Export enthält die Geld-zurück-Anfrage und den festgeschriebenen Lernstand.
$mbExport = (string) wp_json_encode(apply_filters('woocommerce_privacy_export_order_personal_data', [], wc_get_order($order->get_id())), JSON_UNESCAPED_UNICODE);
$check(str_contains($mbExport, 'Geld-zurück-Anfrage') && str_contains($mbExport, 'Lernstand bei der Anfrage') && str_contains($mbExport, 'Frist bis'), 'Bestell-Export: Geld-zurück-Anfrage mit Zeitpunkt, Lernstand und Frist (T111)');
$mbExport2 = (string) wp_json_encode(apply_filters('woocommerce_privacy_export_order_personal_data', [], wc_get_order($order2->get_id())), JSON_UNESCAPED_UNICODE);
$check(str_contains($mbExport2, 'Festgeschriebener Lernstand') && str_contains($mbExport2, 'festgeschrieben vor dem Löschen der Lerndaten am'), 'Bestell-Export: festgeschriebener Lernstand nach der Datenlöschung (T111)');

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

echo "Datenexport vollständig: Kindtabellen, Erinnerungen, alle Tabellen geprüft (T111)\n";
$exportAll = static function (string $email): array {
    $groups = [];
    $pages = 0;
    for ($page = 1; $page <= 200; $page++) {
        $r = PersonalData::export($email, $page);
        $pages++;
        foreach ($r['data'] as $item) {
            $groups[$item['group_id']]['label'] = $item['group_label'];
            $groups[$item['group_id']]['items'][$item['item_id']] = array_column($item['data'], 'value', 'name');
        }
        if ($r['done']) {
            break;
        }
    }

    return ['groups' => $groups, 'pages' => $pages];
};
$exUser = $customer('acct_export');
$exOther = $customer('acct_export_andere');
$exNow = gmdate('Y-m-d H:i:s');
$exRev = $wpdb->get_row("SELECT id, question_id FROM {$p}question_revision ORDER BY id LIMIT 1");
$exCourse = (int) $wpdb->get_var("SELECT id FROM {$p}course WHERE code = 'SKN'");
$exAttempt = static function (int $uid) use ($wpdb, $p, $exRev, $exNow): int {
    $wpdb->insert($p.'attempt', ['user_id' => $uid, 'question_id' => (int) $exRev->question_id, 'revision_id' => (int) $exRev->id, 'context' => 'smart', 'is_correct' => 0, 'idempotency_key' => wp_generate_uuid4(), 'created_at' => $exNow]);

    return (int) $wpdb->insert_id;
};
$exA = $exAttempt($exUser);
$wpdb->insert($p.'attempt_answer', ['attempt_id' => $exA, 'answer_key' => 'b', 'free_text' => 'Freitext T111 Backbord', 'position' => 0]);
// Mehr als eine Exportseite (2000 Zeilen je Seite) in der Kindtabelle.
$values = implode(',', array_map(static fn (int $i): string => '('.$exA.", 'k{$i}', NULL, {$i})", range(1, 2001)));
$wpdb->query("INSERT INTO {$p}attempt_answer (attempt_id, answer_key, free_text, position) VALUES {$values}");
$exB = $exAttempt($exOther);
$wpdb->insert($p.'attempt_answer', ['attempt_id' => $exB, 'answer_key' => 'c', 'free_text' => 'Fremder Freitext T111', 'position' => 0]);
$wpdb->insert($p.'exam_session', ['user_id' => $exUser, 'course_id' => $exCourse, 'mode' => 'exam', 'rule_snapshot' => '{}', 'status' => 'submitted', 'start_token' => wp_generate_uuid4(), 'started_at' => $exNow, 'submitted_at' => $exNow]);
$exSession = (int) $wpdb->insert_id;
$wpdb->insert($p.'exam_session_question', ['session_id' => $exSession, 'position' => 1, 'question_id' => (int) $exRev->question_id, 'revision_id' => (int) $exRev->id, 'given_answer' => '["c"]', 'flagged' => 1, 'answered_at' => $exNow]);
$wpdb->insert($p.'favorite_collection', ['user_id' => $exUser, 'course_id' => $exCourse, 'name' => 'Meine Knoten T111', 'created_at' => $exNow]);
$exCollection = (int) $wpdb->insert_id;
$wpdb->insert($p.'favorite_collection_item', ['collection_id' => $exCollection, 'question_id' => (int) $exRev->question_id, 'created_at' => $exNow]);
$wpdb->insert($p.'mobile_idempotency', ['user_id' => $exUser, 'scope' => 'group-join-code:1', 'idem_key' => wp_generate_uuid4(), 'request_hash' => str_repeat('a', 64), 'status_code' => 200, 'response' => '{"join_code":"GEHEIM-T111"}', 'resource' => 'x', 'created_at' => $exNow, 'expires_at' => $exNow]);
update_user_meta($exUser, \Bootspruefung\Learning\Commerce\ExpiryReminder::META, ['SKN|2027-01-31 23:59:59' => '2027-01-24 08:00:00']);
\Bootspruefung\Learning\Commerce\ReminderOptOut::set($exUser, true, 'account');
$ex = $exportAll('acct_export@example.test');
$g = $ex['groups'];
$answers = $g['bootspruefung-learning-attempt_answer']['items'] ?? [];
$check(count($answers) === 2002 && in_array('Freitext T111 Backbord', array_column($answers, 'free_text'), true) && in_array('b', array_column($answers, 'answer_key'), true), 'Export: gewählte Antworten und Freitexte (attempt_answer), alle 2002 Zeilen über mehrere Seiten ('.count($answers).')');
$check(! str_contains((string) wp_json_encode($g, JSON_UNESCAPED_UNICODE), 'Fremder Freitext T111'), 'Export: keine Antworten anderer Personen');
$esq = array_values($g['bootspruefung-learning-exam_session_question']['items'] ?? []);
$check(count($esq) === 1 && $esq[0]['given_answer'] === '["c"]' && $esq[0]['flagged'] === '1' && $esq[0]['answered_at'] === $exNow, 'Export: Fragen der Prüfungssimulation mit eigener Antwort, Markierung und Zeitpunkt');
$fci = array_values($g['bootspruefung-learning-favorite_collection_item']['items'] ?? []);
$check(count($fci) === 1 && (int) $fci[0]['collection_id'] === $exCollection && (int) $fci[0]['question_id'] === (int) $exRev->question_id, 'Export: Fragen der Sammlungen (favorite_collection_item)');
$check(str_contains((string) ($g['bootspruefung-learning-exam_session_question']['label'] ?? ''), 'Prüfungssimulationen – Fragen') && str_contains((string) ($g['bootspruefung-learning-attempt_answer']['label'] ?? ''), 'gewählte Antworten') && str_contains((string) ($g['bootspruefung-learning-favorite_collection_item']['label'] ?? ''), 'Sammlungen –'), 'Export: lesbare Bezeichnungen der neuen Gruppen');
$idem = array_values($g['bootspruefung-learning-mobile_idempotency']['items'] ?? []);
$check(count($idem) === 1 && ! isset($idem[0]['response']) && ! isset($idem[0]['request_hash']) && ! str_contains((string) wp_json_encode($g), 'GEHEIM-T111'), 'Export: technische App-Daten ohne gespeicherte Antworten (keine Beitrittscodes) und ohne Hashes');
$rem = array_values($g['bootspruefung-learning-expiry_reminders']['items'] ?? []);
$check(count($rem) === 2 && in_array('SKN', array_column($rem, 'Kurs'), true) && (string) array_column($rem, 'Weg')[0] === 'account', 'Export: gesendete Ablauf-Erinnerungen und Widerspruch (Nutzer-Meta)');
$tables = \Bootspruefung\Learning\Infrastructure\Database\Schema::TABLES;
$exportTables = PersonalData::exportTables();
$unclassified = array_values(array_diff($tables, $exportTables, PersonalData::NO_PERSONAL_DATA_TABLES));
$check($unclassified === [] && array_diff(\Bootspruefung\Learning\Infrastructure\Database\Schema::USER_DATA_TABLES, $exportTables) === [] && array_diff(array_keys(PersonalData::CHILD_TABLES), $exportTables) === [], 'jede eigene Tabelle ist im Export oder ausdrücklich ohne Personenbezug eingestuft'.($unclassified !== [] ? ': '.implode(', ', $unclassified) : ''));
$check(array_intersect($exportTables, PersonalData::NO_PERSONAL_DATA_TABLES) === [] && array_diff(array_merge($exportTables, PersonalData::NO_PERSONAL_DATA_TABLES), $tables) === [], 'Einstufung ohne Widerspruch und ohne unbekannte Tabellen');
// Löschung: Kindzeilen ausdrücklich, Protokoll mit UTC-Zeit.
$erasedEx = PersonalData::eraseUser($exUser, 'acct_export@example.test');
$left = (int) $wpdb->get_var($wpdb->prepare("SELECT (SELECT COUNT(*) FROM {$p}attempt_answer WHERE attempt_id = %d) + (SELECT COUNT(*) FROM {$p}exam_session_question WHERE session_id = %d) + (SELECT COUNT(*) FROM {$p}favorite_collection_item WHERE collection_id = %d) + (SELECT COUNT(*) FROM {$p}mobile_idempotency WHERE user_id = %d)", $exA, $exSession, $exCollection, $exUser));
$check($erasedEx['items_removed'] && $left === 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}attempt_answer WHERE attempt_id = %d", $exB)) === 1, 'Löschung: Antworten, Prüfungsfragen und Sammlungsinhalte weg, fremde Antworten bleiben');
$check(in_array('Dein Widerspruch gegen die Erinnerungs-E-Mails bleibt gespeichert, damit wir ihn weiter beachten.', $erasedEx['messages'], true) && \Bootspruefung\Learning\Commerce\ReminderOptOut::optedOut($exUser), 'Löschung: Widerspruch gegen Erinnerungen bleibt (mit Hinweis)');
$auditAt = (string) $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$p}audit_log WHERE action = 'personal_data_erased' AND object_id = %s ORDER BY id DESC LIMIT 1", hash('sha256', $exUser.wp_salt())));
$check($auditAt !== '' && abs(strtotime($auditAt.' UTC') - time()) < 120, "Protokoll „personal_data_erased“ mit UTC-Zeitpunkt ({$auditAt})");
$wpdb->delete($p.'attempt', ['id' => $exB]);
foreach ([$exUser, $exOther] as $uid) {
    wp_delete_user($uid);
}

echo "Multisite: Konto nur von dieser Website bzw. netzweit löschen (T112, mit Test-Double)\n";
// Ohne Multisite-Installation: NetworkAccounts als Double. Ungetestet bleiben die echten Aufrufe von
// wpmu_delete_user, get_blogs_of_user, get_sites und switch_to_blog (siehe docs/datenschutz-organisation.md, 1.8).
$fakeNet = new class () extends \Bootspruefung\Learning\Privacy\NetworkAccounts {
    /** @var list<int> */
    public array $memberOf = [1];
    /** @var list<string> */
    public array $calls = [];
    public function isMultisite(): bool
    {
        return true;
    }
    public function currentSite(): int
    {
        return 1;
    }
    public function sitesOf(int $userId): array
    {
        return $this->memberOf;
    }
    public function deleteFromNetwork(int $userId): bool
    {
        $this->calls[] = 'network';
        require_once ABSPATH.'wp-admin/includes/user.php';

        return (bool) wp_delete_user($userId); // Einzelwebsite: entfernt die Zeile in wp_users wie wpmu_delete_user
    }
    public function deleteFromSite(int $userId): bool
    {
        // Wie wp_delete_user auf Multisite: nur die Rolle dieser Website entfernen, dann deleted_user.
        global $wpdb;
        $this->calls[] = 'site';
        $user = get_userdata($userId);
        delete_user_meta($userId, $wpdb->get_blog_prefix().'capabilities');
        delete_user_meta($userId, $wpdb->get_blog_prefix().'user_level');
        do_action('deleted_user', $userId, null, $user);
        clean_user_cache($userId);

        return true;
    }
    public function sitesWithTables(): array
    {
        return [1, 2];
    }
    public function inSite(int $siteId, callable $fn): mixed
    {
        $this->calls[] = 'in-site:'.$siteId;

        return $fn();
    }
};
// a) Auch Mitglied einer anderen Website: Konto bleibt im Netzwerk, Daten dieser Website weg.
$msUser = $customer('acct_multisite');
$learn($msUser, 3);
$fakeNet->memberOf = [1, 3];
AccountDeletion::useNetwork($fakeNet);
$check(AccountDeletion::scopeFor(get_userdata($msUser)) === 'site', 'Mitglied mehrerer Websites: Wirkung „nur diese Website“');
wp_set_current_user($msUser);
ob_start();
SelfService::render();
$msPage = (string) ob_get_clean();
wp_set_current_user(1);
$check(str_contains($msPage, 'Für die anderen Websites dieses Netzwerks bleibt es mit Anmeldung bestehen'), '„Meine Daten“ sagt: Konto bleibt für die anderen Websites');
$mails = [];
$msReq = (int) SelfService::request($msUser, 'delete')['request_id'];
$check(str_contains($mails[0]['message'] ?? '', 'Von dieser Website löschen') && str_contains($mails[0]['message'] ?? '', 'anderen Websites dieses Netzwerks'), 'Bestätigungsmail: Knopf „Von dieser Website löschen“, Konto bleibt im Netzwerk');
preg_match('/confirm_key=([A-Za-z0-9]+)/', $mails[0]['message'] ?? '', $msKey);
$msForm = AccountDeletion::confirmForm($msReq, (string) ($msKey[1] ?? ''));
$check(str_contains($msForm, 'Von dieser Website löschen') && str_contains($msForm, 'anderen Websites dieses Netzwerks') && ! str_contains($msForm, 'Konto jetzt löschen'), 'Bestätigungsseite: Daten dieser Website löschen, Konto bleibt');
$mails = [];
do_action('user_request_action_confirmed', $msReq);
clean_user_cache($msUser);
$check(get_userdata($msUser) !== false && $fakeNet->calls === ['site', 'in-site:1'] && get_post_status($msReq) === 'request-completed', 'nach der Bestätigung: Konto besteht weiter (nur von dieser Website entfernt), Antrag erledigt');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}progress WHERE user_id = %d", $msUser)) === 0 && (int) get_post_meta($msReq, AccountDeletion::SITE_REMOVED_META, true) > 0 && ! get_post_meta($msReq, AccountDeletion::DELETED_META, true), 'Daten dieser Website gelöscht; Antrag vermerkt „von der Website entfernt“, nicht „Konto gelöscht“');
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'account_removed_from_site' AND object_id = %s", (string) $msReq)) === 1 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'account_deleted' AND object_id = %s", (string) $msReq)) === 0, 'Protokoll: account_removed_from_site statt account_deleted');
$msDone = array_values(array_filter($mails, static fn ($m) => $m['to'] === 'acct_multisite@example.test'));
$check($msDone !== [] && str_contains($msDone[0]['message'], 'Gelöscht wurden deine Daten auf dieser Website'), 'Erledigt-Mail: Daten dieser Website gelöscht, Konto bleibt');
$msMsg = (string) apply_filters('user_request_action_confirmed_message', '', $msReq);
$check(str_contains($msMsg, 'Deine Daten auf dieser Website sind gelöscht') && str_contains($msMsg, 'bleibt im Netzwerk bestehen') && ! str_contains($msMsg, 'Konto ist gelöscht'), 'Bestätigungsseite ehrlich: Konto bleibt im Netzwerk');
$check(str_contains(AccountDeletion::completedText($msReq), 'bleibt im Netzwerk bestehen'), 'App-Meldung (completedText): Konto bleibt im Netzwerk');
// b) Nur Mitglied dieser Website: netzweit löschen, Lerndaten aller Websites mit Plugin-Tabellen.
$fakeNet->calls = [];
$fakeNet->memberOf = [1];
$netUser = $customer('acct_netzwerk');
$learn($netUser, 3);
$check(AccountDeletion::scopeFor(get_userdata($netUser)) === 'network', 'nur Mitglied dieser Website: Wirkung „netzweit“');
$netReq = (int) SelfService::request($netUser, 'delete')['request_id'];
do_action('user_request_action_confirmed', $netReq);
clean_user_cache($netUser);
$check(get_userdata($netUser) === false && $fakeNet->calls === ['network', 'in-site:1', 'in-site:2'], 'netzweit gelöscht (wpmu_delete_user), danach Lerndaten auf allen Websites mit Plugin-Tabellen: '.implode(', ', $fakeNet->calls));
$check((int) get_post_meta($netReq, AccountDeletion::DELETED_META, true) > 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'account_deleted' AND object_id = %s AND context LIKE %s", (string) $netReq, '%"network":true%')) === 1, 'Antrag „Konto gelöscht“, Protokoll mit Netzwerk-Vermerk');
// c) Admin entfernt ein Konto nur von dieser Website (Konto bleibt): nur diese Website wird bereinigt.
$fakeNet->calls = [];
$siteOnly = $customer('acct_nur_entfernt');
$fakeNet->deleteFromSite($siteOnly);
$check($fakeNet->calls === ['site', 'in-site:1'], 'deleted_user mit weiter bestehendem Konto: nur diese Website');
AccountDeletion::useNetwork(null);
$check(AccountDeletion::scopeFor(get_userdata($msUser)) === 'account' && get_class(AccountDeletion::network()) === \Bootspruefung\Learning\Privacy\NetworkAccounts::class, 'ohne Multisite: Wirkung „Konto löschen“ wie bisher');
foreach ([$msUser, $siteOnly] as $uid) {
    wp_delete_user($uid);
}
foreach ([$msReq, $netReq] as $rid) {
    wp_delete_post($rid, true);
}

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
