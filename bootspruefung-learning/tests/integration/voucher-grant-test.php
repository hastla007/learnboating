<?php

declare(strict_types=1);

/*
 * Integrationstest Grant-Service für Kursgutscheine (VoucherGrants, Vertrag bootspruefung_voucher_grants_v1,
 * docs/kursgutscheine.md) gegen eine echte WordPress-Datenbank mit WooCommerce:
 * Zielprüfung über die Produktzuordnung „Lernzugang“, exakte Freischaltung, Idempotenz (auch parallel aus zwei
 * Prozessen), Laufzeit ab Einlösung mit Verlängerungsregel, vorhandener Zugang, Widerruf nach Erstattungsregel,
 * Audit und Supportsuche.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/voucher-grant-test.php
 * (intern: … voucher-grant-test.php race <key> <user> <product> – ein Teilnehmer des Parallel-Tests)
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\VoucherGrants;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Settings\Settings;

global $wpdb;

if (($argv[1] ?? '') === 'race') {
    // Teilnehmer: wartet auf den gemeinsamen Startzeitpunkt, dann gleichzeitig freischalten.
    $start = (float) ($argv[5] ?? 0);
    while (microtime(true) < $start) {
        usleep(1000);
    }
    $service = apply_filters('bootspruefung_voucher_grants_v1', null);
    $result = $service->grant(['redemption_key' => $argv[2], 'user_id' => (int) $argv[3], 'product_id' => (int) $argv[4], 'voucher_id' => 1, 'source' => 'test']);
    echo wp_json_encode(is_wp_error($result) ? ['error' => $result->get_error_code()] : ['created' => $result['created'], 'ids' => array_column($result['entitlements'], 'id')]);
    exit(0);
}

$failures = 0;
$check = static function (bool $ok, string $label, $detail = null) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label.($ok || $detail === null ? '' : ' – '.wp_json_encode($detail))."\n";
    if (! $ok) {
        $failures++;
    }
};
if (! function_exists('wc_get_product')) {
    fwrite(STDERR, "WooCommerce muss aktiv sein (tests/integration/woocommerce-setup.php).\n");
    exit(2);
}

wp_set_current_user(1);
$p = $wpdb->prefix.'bp_';
$course = (new \Bootspruefung\Learning\Content\Repositories\ContentRepository())->courses()[0]->code ?? null;
if ($course === null) {
    fwrite(STDERR, "Kein veröffentlichter Kurs – zuerst das Content-Paket importieren.\n");
    exit(2);
}
$user = static function (string $login): int {
    $u = get_user_by('login', $login);

    return $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer']);
};
$alice = $user('bp_voucher_alice');
$bob = $user('bp_voucher_bob');
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id IN (%d, %d)", $alice, $bob));
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));

$products = new ProductAccessRepository($wpdb);
$entitlements = new EntitlementService($wpdb);
$access = new AccessService();
$service = apply_filters(VoucherGrants::FILTER, null);
$check($service instanceof VoucherGrants && str_starts_with(VoucherGrants::CONTRACT_VERSION, '1.'), 'Vertrag bootspruefung_voucher_grants_v1 (1.x) registriert');

$product = static function (string $name, string $sku = '', string $status = 'publish'): int {
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_regular_price('49');
    $p->set_virtual(true);
    $p->set_status($status);
    if ($sku !== '') {
        $p->set_sku($sku.'-'.wp_generate_password(4, false, false));
    }

    return (int) $p->save();
};
$scopes = static fn (array $features) => array_map(static fn ($f) => ['course_code' => $course, 'feature_code' => $f], $features);
$yearly = $product('Kurs Jahreszugang');
$products->save($yearly, 0, $scopes(['question_bank', 'exam_simulation']), 365, 'extend', 'revoke_on_full_refund');
$parallel = $product('Kurs 30 Tage parallel');
$products->save($parallel, 0, $scopes(['navigation_trainer']), 30, 'parallel', 'revoke_on_full_refund');
$forever = $product('Kurs dauerhaft, Zugang bleibt');
$products->save($forever, 0, $scopes(['downloads']), null, 'extend', 'keep');
$group = $product('Gruppenpaket');
$products->save($group, 0, $scopes(['question_bank']), 365, 'extend', 'revoke_on_full_refund', 5);
$upgrade = $product('Video-Upgrade', 'BP-TEST'.\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_SUFFIX);
$wc = wc_get_product($upgrade);
$wc->set_sku('BP-T'.wp_generate_password(4, false, false).\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_SUFFIX);
$wc->save();
$products->save($upgrade, 0, $scopes(['video_course']), 365, 'extend', 'revoke_on_full_refund');
$draft = $product('Kurs im Entwurf', '', 'draft');
$products->save($draft, 0, $scopes(['question_bank']), 365, 'extend', 'revoke_on_full_refund');
$unmapped = $product('Kurs ohne Lernzugang');
$wrongCourse = $product('Kurs nicht angeboten');
$products->save($wrongCourse, 0, [['course_code' => 'GIBT-ES-NICHT', 'feature_code' => 'question_bank']], 365, 'extend', 'revoke_on_full_refund');

echo "Zielprüfung über „Lernzugang“ (keine eigene Konfiguration im Gutschein-Plugin)\n";
$target = $service->target($yearly);
$check(is_array($target) && array_column($target['scopes'], 'feature') === ['exam_simulation', 'question_bank'] && $target['scopes'][0]['duration_days'] === 365, 'Kursprodukt mit Lernzugang: Bereiche und Laufzeit aus der Zuordnung');
$errorOf = static fn ($r) => is_wp_error($r) ? $r->get_error_code() : 'ok';
$check($errorOf($service->target(999999999)) === 'lb_target_unavailable', 'gelöschtes/unbekanntes Produkt → lb_target_unavailable');
$check($errorOf($service->target($draft)) === 'lb_target_unavailable', 'nicht veröffentlichtes (deaktiviertes) Produkt → lb_target_unavailable');
$check($errorOf($service->target($unmapped)) === 'lb_target_unmapped', 'Produkt ohne Lernzugang → lb_target_unmapped');
$check($errorOf($service->target($group)) === 'lb_target_group', 'Gruppenlizenz → lb_target_group');
$check($errorOf($service->target($upgrade)) === 'lb_target_upgrade', 'Video-Upgrade → lb_target_upgrade');
$check($errorOf($service->target($wrongCourse)) === 'lb_course_unavailable', 'Kurs nicht veröffentlicht → lb_course_unavailable');
$wpdb->update($p.'product_access', ['active' => 0], ['product_id' => $parallel]);
$check($errorOf($service->target($parallel)) === 'lb_target_unmapped', 'Zuordnung deaktiviert → lb_target_unmapped');
$wpdb->update($p.'product_access', ['active' => 1], ['product_id' => $parallel]);

echo "Freischaltung: exakt Kurs und Bereiche, Laufzeit ab Einlösung\n";
$check(! $access->can($alice, $course, 'question_bank'), 'vorher kein Zugriff');
$before = time();
$first = $service->grant(['redemption_key' => 'learnboating:grant:9001', 'user_id' => $alice, 'product_id' => $yearly, 'voucher_id' => 9001, 'source' => 'voucher-captain', 'source_reference' => 'vcap:voucher:9001']);
$check(is_array($first) && $first['created'] === true && count($first['entitlements']) === 2, 'Grant angelegt (zwei Bereiche)', $first);
$rows = $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9001'));
$check(array_map(static fn ($r) => $r->feature_code, $rows) === ['exam_simulation', 'question_bank'] && array_unique(array_map(static fn ($r) => $r->source, $rows)) === ['voucher'], 'genau die Bereiche der Zuordnung, Quelle „voucher“');
$check($access->can($alice, $course, 'question_bank') && $access->can($alice, $course, 'exam_simulation') && ! $access->can($alice, $course, 'video_course'), 'Zugriff genau auf die freigeschalteten Bereiche');
$from = strtotime($rows[0]->valid_from.' UTC');
$until = strtotime($rows[0]->valid_until.' UTC');
$check(abs($from - $before) < 120 && $until - $from === 365 * DAY_IN_SECONDS, 'Laufzeit beginnt mit der Einlösung, Dauer 365 Tage aus der Zuordnung', [$rows[0]->valid_from, $rows[0]->valid_until]);
$meta = json_decode((string) $rows[0]->meta, true);
$check(($meta['voucher_id'] ?? null) === 9001 && ($meta['redemption_key'] ?? '') === 'learnboating:grant:9001' && ($meta['source_reference'] ?? '') === 'vcap:voucher:9001' && ! empty($meta['redeemed_at']), 'Zugang trägt Gutschein-ID, Einlöse-ID, Herkunft und Zeitpunkt');

echo "Idempotenz\n";
$again = $service->grant(['redemption_key' => 'learnboating:grant:9001', 'user_id' => $alice, 'product_id' => $yearly, 'voucher_id' => 9001]);
$check(is_array($again) && $again['created'] === false && array_column($again['entitlements'], 'id') === array_column($first['entitlements'], 'id'), 'gleicher Schlüssel → derselbe Grant, nichts Neues');
$check(count($entitlements->forUser($alice)) === 2, 'kein zweiter Zugang');
$check($errorOf($service->grant(['redemption_key' => 'learnboating:grant:9001', 'user_id' => $bob, 'product_id' => $yearly])) === 'lb_idempotency_conflict', 'gleicher Schlüssel für anderen Nutzer → lb_idempotency_conflict');
$check($errorOf($service->grant(['redemption_key' => 'kurz', 'user_id' => $bob, 'product_id' => $yearly])) === 'lb_invalid_request', 'ungültiger Schlüssel → lb_invalid_request');
// Parallel: zwei Prozesse schalten im selben Augenblick mit demselben Schlüssel frei.
$startAt = microtime(true) + 2.0;
$procs = [];
foreach ([1, 2] as $i) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, 'race', 'learnboating:race:0001', (string) $bob, (string) $yearly, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, ['WP_DIR' => $wpDir] + getenv());
}
$out = [];
foreach ([1, 2] as $i) {
    $out[$i] = json_decode((string) stream_get_contents($pipes[$i][1]), true);
    $err = stream_get_contents($pipes[$i][2]);
    proc_close($procs[$i]);
    if ($err !== '') {
        echo '  (Prozess '.$i.': '.trim($err).")\n";
    }
}
$created = array_sum(array_map(static fn ($o) => ! empty($o['created']) ? 1 : 0, $out));
$retryable = array_sum(array_map(static fn ($o) => ($o['error'] ?? '') === 'lb_temporary_failure' ? 1 : 0, $out));
$bobRows = $entitlements->forReference(VoucherGrants::reference('learnboating:race:0001'));
$check(count($bobRows) === 2 && $created === 1 && $created + $retryable + array_sum(array_map(static fn ($o) => isset($o['created']) && ! $o['created'] ? 1 : 0, $out)) === 2, 'parallel: genau ein Grant angelegt, der andere Aufruf erhält ihn oder „später erneut“', $out);
$replay = $service->grant(['redemption_key' => 'learnboating:race:0001', 'user_id' => $bob, 'product_id' => $yearly]);
$check(is_array($replay) && $replay['created'] === false && count($entitlements->forUser($bob)) === 2, 'Wiederholung nach dem Wettlauf: derselbe Grant');

echo "Vorhandener Zugang und Verlängerung\n";
$preview = $service->check($alice, $yearly);
$check(is_array($preview) && $preview['scopes'][0]['existing'] === 'until' && $preview['scopes'][0]['starts_at'] === $rows[0]->valid_until, 'Vorschau: zweiter Jahresgutschein beginnt am Ende des vorhandenen Zugangs');
$second = $service->grant(['redemption_key' => 'learnboating:grant:9002', 'user_id' => $alice, 'product_id' => $yearly, 'voucher_id' => 9002]);
$second = $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9002'));
$check($second[0]->valid_from === $rows[0]->valid_until && strtotime($second[0]->valid_until.' UTC') - strtotime($second[0]->valid_from.' UTC') === 365 * DAY_IN_SECONDS, 'Verlängerungsregel „verlängern“: nahtlos anschließend, eigene Laufzeit');
$par = $service->grant(['redemption_key' => 'learnboating:grant:9003', 'user_id' => $alice, 'product_id' => $parallel]);
$parRows = $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9003'));
$check(abs(strtotime($parRows[0]->valid_from.' UTC') - time()) < 120 && strtotime($parRows[0]->valid_until.' UTC') - strtotime($parRows[0]->valid_from.' UTC') === 30 * DAY_IN_SECONDS, 'Verlängerungsregel „parallel“: beginnt sofort, 30 Tage');
$perm = $service->grant(['redemption_key' => 'learnboating:grant:9004', 'user_id' => $alice, 'product_id' => $forever]);
$permRows = $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9004'));
$check($permRows[0]->valid_until === null, 'Zuordnung ohne Dauer: dauerhafter Zugang');
$check($errorOf($service->check($alice, $forever)) === 'lb_access_exists' && $errorOf($service->grant(['redemption_key' => 'learnboating:grant:9005', 'user_id' => $alice, 'product_id' => $forever])) === 'lb_access_exists', 'schon dauerhaft freigeschaltet → lb_access_exists (Gutschein bleibt ungenutzt)');
$check($entitlements->forReference(VoucherGrants::reference('learnboating:grant:9005')) === [], 'abgelehnte Freischaltung legt nichts an');
$check($errorOf($service->check(0, $yearly)) === 'lb_user_required', 'ohne Nutzer → lb_user_required');

echo "Widerruf nach Erstattungsregel\n";
$done = $service->revoke('learnboating:grant:9001', 'Kauf erstattet');
$check(is_array($done) && $done['revoked'] === 2 && $done['kept'] === 0, 'Gutschein ungültig: Zugang widerrufen', $done);
$check(array_unique(array_map(static fn ($r) => $r->status, $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9001')))) === ['revoked'], 'Status „widerrufen“');
$moved = $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9002'));
$check(abs(strtotime($moved[0]->valid_from.' UTC') - time()) < 120 && $access->can($alice, $course, 'question_bank'), 'Verlängerung rückt nach vorn (keine bezahlte Zeit geht verloren)', [$moved[0]->valid_from]);
$kept = $service->revoke('learnboating:grant:9004', 'Kauf erstattet');
$check(is_array($kept) && $kept['kept'] === 1 && $entitlements->forReference(VoucherGrants::reference('learnboating:grant:9004'))[0]->status === 'active', 'Erstattungsregel „behalten“: Zugang bleibt');
$check($errorOf($service->revoke('learnboating:grant:0000', '')) === 'lb_grant_not_found', 'unbekannter Schlüssel → lb_grant_not_found');

echo "Support: Verlauf und Suche\n";
$audit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}audit_log WHERE action = 'entitlement_voucher_grant' AND object_id = %s AND context LIKE %s ORDER BY id DESC LIMIT 1", (string) $alice, '%'.$wpdb->esc_like('learnboating:grant:9001').'%'));
$context = $audit ? json_decode((string) $audit->context, true) : [];
$check(($context['redemption_key'] ?? '') !== '' && isset($context['voucher_id'], $context['product_id'], $context['redeemed_at']), 'Audit-Log: Nutzer, Gutschein-ID, Kursprodukt, Einlöse-ID, Zeitpunkt', $context);
$check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'entitlement_voucher_revoke' AND object_type = 'user' AND context LIKE %s", '%'.$wpdb->esc_like('learnboating:grant:9001').'%')) > 0, 'Audit-Log: Widerruf', $wpdb->get_results("SELECT action, object_type, object_id FROM {$p}audit_log ORDER BY id DESC LIMIT 6"));
$found = $entitlements->search(['search' => 'learnboating:grant:9002', 'source' => 'voucher']);
$check($found['total'] === 2, 'Adminsuche nach Einlöse-ID und Quelle „Kursgutschein“');
$info = $service->get('learnboating:grant:9002');
$check(is_array($info) && $info['voucher_id'] === 9002 && $info['user_id'] === $alice && $info['product_id'] === $yearly && $info['granted_at'] !== '', 'get(): Grant für Support und Wiederholung');

// Aufräumen.
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id IN (%d, %d)", $alice, $bob));
foreach ([$yearly, $parallel, $forever, $group, $upgrade, $draft, $unmapped, $wrongCourse] as $id) {
    $wpdb->delete($p.'product_access', ['product_id' => $id]);
    wp_delete_post($id, true);
}
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
