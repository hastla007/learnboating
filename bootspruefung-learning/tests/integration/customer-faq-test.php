<?php

declare(strict_types=1);

/*
 * Integrationstest Kunden-FAQ: Startinhalte (7 Scheine, Unterkategorien, 42 Entwürfe, idempotent),
 * Zugriff (Gast, Testkurs, Kunde, Kombi, Ergänzung, Gruppe, „Zugang ohne Shop“), Zuordnung Kurs → Scheine,
 * verschachtelte und sortierte Unterkategorien, nur veröffentlichte FAQs, Platzhalter und kses, Abgrenzung zu
 * den öffentlichen FAQs (Shortcode, /bootspruefung/v1/faqs, /wp/v2, Suche, Sitemap) und die App-Schnittstelle
 * GET boatingschool/v1/courses/{id}/faqs mit answer_text und faq_count.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/customer-faq-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse importiert). Nur gegen eine Test-Datenbank. Setzt die Kunden-FAQ
 * zu Beginn zurück und hinterlässt den Startstand (alle Entwurf).
 */

ob_start();
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
ob_end_flush();
require_once __DIR__.'/support/FakeOrders.php';
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSync;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Faq\CustomerFaq;
use Bootspruefung\Learning\Faq\CustomerFaqImporter;
use Bootspruefung\Learning\Faq\CustomerFaqRepository;
use Bootspruefung\Learning\Faq\DefaultCustomerFaqs;
use Bootspruefung\Learning\Faq\Faq;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\Problem;
use Bootspruefung\Learning\Mobile\TokenAuth;
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
$p = $wpdb->prefix.'bp_';
wp_set_current_user(1);
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));

$allStatuses = array_values(array_diff(array_keys(get_post_stati()), ['auto-draft']));
$reset = static function () use ($allStatuses): void {
    foreach (get_posts(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        wp_delete_post((int) $id, true);
    }
    foreach (get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']) ?: [] as $termId) {
        wp_delete_term((int) $termId, CustomerFaq::TAXONOMY);
    }
    delete_option(CustomerFaqImporter::OPTION_VERSION);
    CustomerFaqRepository::flush();
};
$byRef = static function (int $ref) use ($allStatuses): ?WP_Post {
    $posts = get_posts(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => 1, 'meta_key' => CustomerFaq::META_REF, 'meta_value' => (string) $ref]);

    return $posts[0] ?? null;
};
$all = static fn (): array => get_posts(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => -1]);
$top = static function (string $key): ?WP_Term {
    $ids = get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => 0, 'fields' => 'ids', 'meta_query' => [['key' => CustomerFaq::TERM_KEY, 'value' => $key]]]);

    return is_array($ids) && $ids !== [] ? get_term((int) $ids[0], CustomerFaq::TAXONOMY) : null;
};
$child = static function (int $parent, string $name): ?WP_Term {
    foreach (get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => $parent]) ?: [] as $t) {
        if (html_entity_decode($t->name, ENT_QUOTES, 'UTF-8') === $name) {
            return $t;
        }
    }

    return null;
};

$reset();

echo "Startinhalte\n";
$check(CustomerFaqImporter::needsImport(), 'ohne Datenstand: Import fällig');
CustomerFaqImporter::maybeImport();
$check(! CustomerFaqImporter::needsImport() && get_option(CustomerFaqImporter::OPTION_VERSION) === CustomerFaqImporter::VERSION, 'eigener Datenstand gespeichert (bp_learning_cfaq_version)');
$tops = get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => 0]);
$keys = array_map(static fn ($t) => (string) get_term_meta($t->term_id, CustomerFaq::TERM_KEY, true), $tops);
sort($keys);
$expectedKeys = CustomerFaq::SCHEIN_KEYS;
sort($expectedKeys);
$check(count($tops) === 7 && $keys === $expectedKeys, '7 Scheine mit Schlüssel = Kurscode: '.implode(', ', $keys));
$see = $top('SBF-SEE');
$subNames = static fn (?WP_Term $t): array => $t ? array_map(static fn ($c) => html_entity_decode($c->name, ENT_QUOTES, 'UTF-8'), get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => $t->term_id, 'meta_key' => CustomerFaq::TERM_ORDER, 'orderby' => 'meta_value_num', 'order' => 'ASC'])) : [];
$check($subNames($see) === ['Lernen im Kurs', 'Prüfungssimulation', 'Navigationsaufgaben', 'Prüfung anmelden und ablegen', 'Technik und Zugang'], 'SBF See: Start-Unterkategorien mit „Navigationsaufgaben“, Reihenfolge per bp_cfaq_order');
$check($subNames($top('SRC')) === ['Lernen im Kurs', 'Prüfungssimulation', 'Funktraining', 'Prüfung anmelden und ablegen', 'Technik und Zugang'] && in_array('Funktraining', $subNames($top('UBI')), true), 'SRC und UBI: zusätzlich „Funktraining“');
$check($subNames($top('SKN')) === ['Lernen im Kurs', 'Prüfungssimulation', 'Prüfung anmelden und ablegen', 'Technik und Zugang'], 'SKN: die vier Standard-Unterkategorien');
$check(count(get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false])) === 38, '38 Kategorien (7 Scheine, 31 Unterkategorien)');
$posts = $all();
$check(count($posts) === 42 && count($posts) === DefaultCustomerFaqs::total(), '42 Start-FAQs (6 je Schein): '.count($posts));
$check(array_unique(array_map(static fn ($p) => $p->post_status, $posts)) === ['draft'], 'alle Start-FAQs als Entwurf');
$perSchein = [];
foreach ($posts as $post) {
    $terms = wp_get_object_terms($post->ID, CustomerFaq::TAXONOMY);
    $parent = $terms ? get_term($terms[0]->parent, CustomerFaq::TAXONOMY) : null;
    $perSchein[$parent ? (string) get_term_meta($parent->term_id, CustomerFaq::TERM_KEY, true) : '-'][] = $post->ID;
}
$check(count($perSchein) === 7 && min(array_map('count', $perSchein)) >= 3 && max(array_map('count', $perSchein)) <= 6, '3–6 FAQs je Schein, jede in einer Unterkategorie');
$text = implode("\n", array_map(static fn ($p) => $p->post_title."\n".$p->post_content, $posts));
$check(! preg_match('/\d+(,\d+)?\s?(€|EUR|Euro)/u', $text), 'Startpaket ohne Preise');
$f101 = $byRef(101);
$check($f101 && $f101->post_title === 'Wann gilt eine Frage im Smarttrainer als gefestigt?' && str_contains($f101->post_content, '<strong>dreimal hintereinander richtig</strong>') && get_post_meta($f101->ID, CustomerFaq::META_NOTE, true) !== '', 'FAQ 101: Text als Block, **fett**, Redaktionshinweis');
$f102 = $byRef(102);
$check($f102 && str_contains($f102->post_content, 'mindestens 5 von 7 Basisfragen und 18 von 23 Fragen See') && str_contains($f102->post_content, '60 Minuten'), 'SBF See Prüfungssimulation: Grenzen je Teil wie im Regelwerk');
$nav = $byRef(103);
$check($nav && str_contains($nav->post_content, 'D49') && wp_get_object_terms($nav->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs']) === ['sbf-see-navigationsaufgaben'], 'Navigationsaufgaben (D49) in „Navigationsaufgaben“ unter SBF See');

echo "Idempotenz\n";
$termCount = count(get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false]));
wp_update_post(['ID' => $f101->ID, 'post_content' => '<!-- wp:paragraph --><p>Vom Betreiber geändert.</p><!-- /wp:paragraph -->']);
wp_delete_post($byRef(202)->ID, true);
wp_trash_post($byRef(203)->ID);
delete_option(CustomerFaqImporter::OPTION_VERSION);
CustomerFaqImporter::maybeImport();
$check(count($all()) === 41, 'Update-Lauf importiert nicht, wenn es schon Kunden-FAQs gibt');
$stats = CustomerFaqImporter::import();
$check($stats['created'] === 1 && $stats['terms'] === 0 && $stats['skipped'] === 41, 'Knopf „Fehlende Start-FAQs importieren“: nur die gelöschte FAQ 202 neu ('.wp_json_encode($stats).')');
$check(count(get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false])) === $termCount, 'keine Kategorie doppelt');
$check(str_contains((string) get_post_field('post_content', $f101->ID), 'Vom Betreiber geändert.') && get_post_status($byRef(203)->ID) === 'trash', 'Änderung bleibt, FAQ im Papierkorb kommt nicht zurück');
$check(CustomerFaqImporter::import()['created'] === 0, 'weiterer Lauf legt nichts an');
wp_untrash_post($byRef(203)->ID);

echo "Testdaten: veröffentlichen, verschachteln, sortieren\n";
$publish = static function (int $ref) use ($byRef): int {
    $post = $byRef($ref);
    wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);

    return (int) $post->ID;
};
$f101 = $publish(101);
$f102 = $publish(102);
$f103 = $publish(103);
$f201 = $publish(201);
$f501 = $publish(501);
$exam = $child($see->term_id, 'Prüfungssimulation');
$learn = $child($see->term_id, 'Lernen im Kurs');
$papers = wp_insert_term('Prüfungsbögen', CustomerFaq::TAXONOMY, ['parent' => $exam->term_id, 'slug' => 'test-cfaq-boegen']);
$deep = wp_insert_term('Einzelne Bögen', CustomerFaq::TAXONOMY, ['parent' => $papers['term_id'], 'slug' => 'test-cfaq-einzeln']);
$empty = wp_insert_term('Leer', CustomerFaq::TAXONOMY, ['parent' => $exam->term_id, 'slug' => 'test-cfaq-leer']);
$draftOnly = wp_insert_term('Nur Entwurf', CustomerFaq::TAXONOMY, ['parent' => $exam->term_id, 'slug' => 'test-cfaq-entwurf']);
update_term_meta($papers['term_id'], CustomerFaq::TERM_ORDER, 1);
update_term_meta($empty['term_id'], CustomerFaq::TERM_ORDER, 0);
$insert = static function (string $title, string $content, int $term, int $order = 0, string $status = 'publish'): int {
    $id = (int) wp_insert_post(wp_slash(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => $status, 'post_title' => $title, 'post_content' => $content, 'menu_order' => $order]));
    wp_set_object_terms($id, [$term], CustomerFaq::TAXONOMY);

    return $id;
};
$deepId = $insert('Kunden-Testfrage zwei Ebenen tiefer', '<p>Siehe {{agb}} und {{link:/kontakt/|Kontakt}}.</p><script>alert(1)</script>', (int) $deep['term_id']);
$firstId = $insert('Kunden-Testfrage zuerst', '<p>Reihenfolge 0.</p>', (int) $learn->term_id, 0);
$draftId = $insert('Kunden-Testfrage Entwurf', '<p>Nicht ausliefern.</p>', (int) $draftOnly['term_id'], 0, 'draft');

$groups = CustomerFaqRepository::forCourse('SBF-SEE');
$g = $groups[0] ?? ['subcategories' => [], 'faqs' => []];
$subs = array_map(static fn ($s) => [$s['name'], $s['depth']], $g['subcategories']);
$check(count($groups) === 1 && $g['key'] === 'SBF-SEE' && $g['name'] === 'SBF See', 'SBF See: eine Gruppe mit Scheinname');
$check($subs === [['Lernen im Kurs', 1], ['Prüfungssimulation', 1], ['Prüfungsbögen', 2], ['Einzelne Bögen', 3], ['Navigationsaufgaben', 1]], 'Unterkategorien verschachtelt (2 Ebenen unter dem Schein), sortiert, leere und reine Entwurfs-Kategorien fehlen: '.wp_json_encode($subs, JSON_UNESCAPED_UNICODE));
$bySub = array_column($g['subcategories'], null, 'name');
$check(($bySub['Einzelne Bögen']['parent'] ?? 0) === (int) $papers['term_id'] && ($bySub['Prüfungsbögen']['parent'] ?? 0) === (int) $exam->term_id && ($bySub['Prüfungssimulation']['parent'] ?? 0) === (int) $see->term_id, 'parent: Term-ID der übergeordneten Kategorie');
$check(array_column($bySub['Lernen im Kurs']['faqs'] ?? [], 'id') === [$firstId, $f101], 'FAQs nach menu_order (0 vor 1)');
$flat = [];
foreach ($groups as $gr) {
    foreach ($gr['subcategories'] as $s) {
        $flat = array_merge($flat, array_column($s['faqs'], 'id'));
    }
}
$check(! in_array($draftId, $flat, true) && ! in_array($byRef(104)->ID, $flat, true) && count($flat) === 5, 'Entwürfe werden nicht geliefert (5 veröffentlichte SBF-See-FAQs)');
$deepFaq = $bySub['Einzelne Bögen']['faqs'][0] ?? ['answer_html' => '', 'question' => ''];
$check(array_keys($deepFaq) === ['id', 'question', 'answer_html'], 'FAQ-Felder id, question, answer_html');
$check(str_contains($deepFaq['answer_html'], '<a href="'.esc_url(home_url('/kontakt/')).'">Kontakt</a>') && ! str_contains($deepFaq['answer_html'], '{{agb}}') && ! str_contains($deepFaq['answer_html'], '<script'), 'Platzhalter ersetzt ({{agb}}, {{link:…}}), Antwort per wp_kses_post gefiltert');
$check(CustomerFaqRepository::count('SBF-SEE') === 5 && CustomerFaqRepository::count('SRC') === 0, 'Anzahl je Kurs (SBF See 5, SRC 0)');

echo "Zuordnung Kurs → Scheine\n";
$keysOf = static fn (string $code): array => array_column(CustomerFaqRepository::forCourse($code), 'key');
$check(CustomerFaqRepository::keysFor('SBF-SEE-BIN') === ['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'] && CustomerFaqRepository::keysFor('ADD-UBI-TO-SRC') === ['ADD-UBI-TO-SRC', 'UBI'], 'Schlüssel: Kurs selbst, dann Teil- bzw. Zielscheine (SectionFiles::PARTS)');
$check($keysOf('SBF-SEE-BIN') === ['SBF-SEE', 'SBF-BIN-MOTOR'] && array_column(CustomerFaqRepository::forCourse('SBF-SEE-BIN'), 'name') === ['SBF See', 'SBF Binnen'], 'Kombi SBF See & Binnen: zwei Gruppen mit Scheinnamen');
$check($keysOf('ADD-BIN-TO-SEE') === ['SBF-SEE'] && $keysOf('SRC-UBI') === ['UBI'] && $keysOf('ADD-UBI-TO-SRC') === ['UBI'], 'Ergänzung Binnen → See: SBF See; SRC & UBI bzw. Ergänzung SRC → UBI: UBI (SRC ohne veröffentlichte FAQ)');
$check($keysOf('SBF-SEE') === ['SBF-SEE'], 'SBF See zeigt keine UBI-FAQs');
$combo = wp_insert_term('SBF See & Binnen', CustomerFaq::TAXONOMY, ['parent' => 0, 'slug' => 'test-cfaq-kombi']);
update_term_meta($combo['term_id'], CustomerFaq::TERM_KEY, 'SBF-SEE-BIN');
$comboFaq = $insert('Kunden-Testfrage Kombi', '<p>Nur im Kombikurs.</p>', (int) $combo['term_id']);
$comboGroups = CustomerFaqRepository::forCourse('SBF-SEE-BIN');
$check(array_column($comboGroups, 'key') === ['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'] && array_column($comboGroups[0]['faqs'], 'id') === [$comboFaq] && $comboGroups[0]['subcategories'] === [], 'eigene Kategorie mit Kurscode SBF-SEE-BIN kommt dazu (FAQ direkt am Schein)');
$check($keysOf('SBF-SEE') === ['SBF-SEE'], 'Kombi-Kategorie erscheint nicht im Einzelkurs');

echo "Zugriff Web (GET bootspruefung/v1/courses/{code}/customer-faqs)\n";
$userOf = static function (string $login): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'subscriber']);
};
$trial = $userOf('cfaq_testkurs');
$customer = $userOf('cfaq_kunde_see');
$combi = $userOf('cfaq_kunde_kombi');
$addon = $userOf('cfaq_kunde_ergaenzung');
$member = $userOf('cfaq_gruppe');
$owner = $userOf('cfaq_gruppe_besitzer');
$nobody = $userOf('cfaq_ohne_zugang');
$users = [$trial, $customer, $combi, $addon, $member, $owner, $nobody];
$entitlements = new EntitlementService($wpdb);
$entitlements->grantManual($customer, 'SBF-SEE', ['question_bank'], null, 'Kunden-FAQ-Test');
$entitlements->grantManual($combi, 'SBF-SEE-BIN', ['all'], null, 'Kunden-FAQ-Test');
$entitlements->grantManual($addon, 'ADD-BIN-TO-SEE', ['question_bank'], null, 'Kunden-FAQ-Test');
$now = current_time('mysql', true);
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank', 'module_code' => null, 'access_type' => 'free_limited', 'limit_type' => 'questions_per_module', 'limit_value' => 3, 'created_at' => $now, 'updated_at' => $now]);
$trialRule = (int) $wpdb->insert_id;
$web = static function (int $user, string $code): WP_REST_Response {
    wp_set_current_user($user);

    return rest_ensure_response(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/'.$code.'/customer-faqs')));
};
$r = $web(0, 'sbf-see');
$check($r->get_status() === 401, 'Gast: 401');
$r = $web($trial, 'sbf-see');
$check($r->get_status() === 403 && ($r->get_data()['message'] ?? '') === 'Die Fragen und Antworten stehen Kunden dieses Kurses zur Verfügung.', 'Testkurs (kostenloser Teilumfang): 403 mit Meldung');
$check($web($nobody, 'ubi')->get_status() === 403, 'ohne Zugang (UBI, ohne Testumfang): 403');
$r = $web($customer, 'sbf-see');
$check($r->get_status() === 200 && array_column($r->get_data(), 'key') === ['SBF-SEE'] && ($r->get_headers()['Cache-Control'] ?? '') === 'no-store, private', 'Kunde SBF See: 200, Gruppe SBF See, nicht zwischenspeichern');
$check($web($customer, 'ubi')->get_status() === 403, 'Kunde SBF See: UBI-FAQs gesperrt (403)');
$r = $web($combi, 'sbf-see-bin');
$check($r->get_status() === 200 && array_column($r->get_data(), 'key') === ['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'], 'Kunde Kombi: Kombi-Kategorie, SBF See und SBF Binnen');
$r = $web($addon, 'add-bin-to-see');
$check($r->get_status() === 200 && array_column($r->get_data(), 'key') === ['SBF-SEE'], 'Kunde Ergänzung Binnen → See: SBF See');
$check($web($customer, 'gibt-es-nicht')->get_status() === 404, 'unbekannter Kurs: 404');
wp_set_current_user($customer);
$course = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data();
wp_set_current_user($trial);
$trialCourse = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data();
$check(($course['customer_faqs'] ?? null) === 5 && ($trialCourse['customer_faqs'] ?? null) === 0, 'Kursdaten: customer_faqs = 5 für Kunden, 0 im Testkurs (Kurs-Hub fragt dann nicht an)');

// Gruppe: Gruppenpaket SBF Binnen (Bestellung bezahlt), Mitglied tritt über den Beitrittslink bei.
$products = new ProductAccessRepository($wpdb);
$groupsService = new GroupService($wpdb);
$orders = new FakeOrders();
$sync = new OrderSync($orders, $products, $entitlements, new EntitlementPlanner(), $groupsService);
$wpdb->query("DELETE FROM {$p}learning_group_member WHERE group_id IN (SELECT id FROM {$p}learning_group WHERE order_id = 930001)");
$wpdb->query("DELETE FROM {$p}learning_group WHERE order_id = 930001");
$products->save(993001, 0, [['course_code' => 'SBF-BIN-MOTOR', 'feature_code' => 'question_bank']], 365, 'extend', 'revoke_on_full_refund', 3);
$orders->put(930001, $owner, 'processing', gmdate('Y-m-d H:i:s'), [new OrderItemSnapshot(93100, 993001, 0, 1, 'CFAQ-GRUPPE', 300.0, 0, 0.0)], 'bacs');
$sync->sync(930001);
$group = $groupsService->forOrder(930001)[0] ?? null;
if ($group) {
    $groupsService->join((string) $group->join_token, $member);
}
$r = $web($member, 'sbf-bin-motor');
$check($group !== null && $r->get_status() === 200 && array_column($r->get_data(), 'key') === ['SBF-BIN-MOTOR'] && $web($owner, 'sbf-bin-motor')->get_status() === 403, 'Gruppenmitglied: Zugriff auf SBF Binnen; Käufer ohne eigenen Platz nicht');
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'logged_in']));
$r = $web($nobody, 'ubi');
$check($r->get_status() === 200 && array_column($r->get_data(), 'key') === ['UBI'], '„Zugang ohne Shop = alle angemeldeten Nutzer“: Kunde jedes Kurses');
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));

echo "Nie öffentlich\n";
wp_set_current_user(0);
$type = get_post_type_object(CustomerFaq::POST_TYPE);
$check(! $type->public && ! $type->publicly_queryable && $type->exclude_from_search && ! $type->has_archive && ! is_post_type_viewable($type) && $type->cap->read_post === 'manage_bp_content', 'Beitragstyp: nicht öffentlich, keine Einzelseiten, nicht in der Suche');
$check(! is_taxonomy_viewable(CustomerFaq::TAXONOMY) && get_taxonomy(CustomerFaq::TAXONOMY)->hierarchical, 'Taxonomie hierarchisch, nicht öffentlich');
$shortcode = do_shortcode('[bootspruefung_faq]');
$check(! str_contains($shortcode, 'Kunden-Testfrage') && ! str_contains($shortcode, 'Wann gilt eine Frage im Smarttrainer als gefestigt?'), 'nicht im öffentlichen Shortcode (und nicht im JSON-LD)');
$public = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/faqs'))->get_data();
$customerIds = array_map(static fn ($p) => $p->ID, $all());
$check(is_array($public) && array_intersect(array_column($public, 'id'), $customerIds) === [], 'nicht in /bootspruefung/v1/faqs');
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE))->get_status() === 401 && rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f101))->get_status() === 401, '/wp/v2/bp_customer_faq als Gast: 401 (Liste und Einzel-FAQ)');
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::TAXONOMY))->get_status() === 401, '/wp/v2/bp_cfaq_category als Gast: 401');
wp_set_current_user($customer);
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE))->get_status() === 403, '/wp/v2 als Kunde: 403 (nur über den Kurs-Endpunkt)');
wp_set_current_user(1);
$editor = rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f101));
$check($editor->get_status() === 200 && rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::TAXONOMY))->get_status() === 200, '/wp/v2 für die Redaktion (Block-Editor): 200');
wp_set_current_user(0);
$search = new WP_Query(['s' => 'Kunden-Testfrage', 'post_type' => 'any', 'post_status' => 'publish']);
$single = new WP_Query(['p' => $f101, 'post_type' => 'any']);
$check($search->posts === [] && $single->posts === [], 'nicht in der WordPress-Suche, kein Abruf über ?p=');
// Sitemap-Anbieter von WordPress nehmen nur öffentliche Typen; der Filter entfernt die Kunden-FAQ auch, wenn ihn jemand ergänzt.
$postTypes = array_keys(apply_filters('wp_sitemaps_post_types', [CustomerFaq::POST_TYPE => $type] + get_post_types(['public' => true], 'objects')));
$taxonomies = array_keys(apply_filters('wp_sitemaps_taxonomies', [CustomerFaq::TAXONOMY => get_taxonomy(CustomerFaq::TAXONOMY)] + get_taxonomies(['public' => true], 'objects')));
$check(! in_array(CustomerFaq::POST_TYPE, $postTypes, true) && ! in_array(CustomerFaq::TAXONOMY, $taxonomies, true), 'nicht in der Sitemap');

echo "App (GET boatingschool/v1/courses/{id}/faqs)\n";
$mobileBefore = get_option(MobileSettings::OPTION);
delete_option(MobileSettings::OPTION);
$tenant = MobileSettings::tenantSlug();
$api = static function (string $path, ?string $token) use ($tenant): array {
    global $current_user;
    $current_user = null;
    TokenAuth::reset();
    Problem::reset();
    $_SERVER['REQUEST_URI'] = '/wp-json/boatingschool/v1'.$path;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.40';
    if ($token !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    } else {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    $request = new WP_REST_Request('GET', '/boatingschool/v1'.$path);
    $request->set_header('X-Tenant-Slug', $tenant);
    $response = rest_do_request($request);
    $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), rest_get_server(), $request);

    return ['status' => $response->get_status(), 'body' => json_decode((string) wp_json_encode($response->get_data()), true)];
};
$token = static fn (int $user): string => (string) TokenAuth::issue($user, 'Kunden-FAQ-Test', 'ios', '1.0.0')['access_token'];
$content = new ContentRepository();
$seeId = (int) $content->courseByCode('SBF-SEE')->id;
$comboId = (int) $content->courseByCode('SBF-SEE-BIN')->id;
$r = $api('/courses/'.$seeId.'/faqs', null);
$check($r['status'] === 401 && ($r['body']['code'] ?? '') === 'UNAUTHENTICATED', 'Gast: 401 UNAUTHENTICATED');
$r = $api('/courses/'.$seeId.'/faqs', $token($trial));
$check($r['status'] === 403 && ($r['body']['code'] ?? '') === 'CONTENT_LOCKED' && ($r['body']['details']['reason'] ?? '') === 'partial_access' && ($r['body']['detail'] ?? '') === 'Die Fragen und Antworten stehen Kunden dieses Kurses zur Verfügung.', 'Testkurs: 403 CONTENT_LOCKED (partial_access) mit Meldung');
$r = $api('/courses/'.(int) $content->courseByCode('UBI')->id.'/faqs', $token($nobody));
$check($r['status'] === 403 && ($r['body']['code'] ?? '') === 'ENTITLEMENT_REQUIRED' && ($r['body']['details']['capability'] ?? '') === 'smarttrainer', 'ohne Zugang (UBI): 403 ENTITLEMENT_REQUIRED');
$customerToken = $token($customer);
$r = $api('/courses/'.$seeId.'/faqs', $customerToken);
$data = $r['body']['data'] ?? [];
$check($r['status'] === 200 && count($data) === 1 && $data[0]['key'] === 'SBF-SEE' && $data[0]['id'] === (string) $see->term_id && ($r['body']['meta'] ?? null) === ['course_id' => (string) $seeId, 'groups' => 1, 'faqs' => 5], 'Kunde: 200, Gruppe SBF See, IDs als Strings, meta groups/faqs');
$mSubs = array_column($data[0]['subcategories'] ?? [], null, 'name');
$mDeep = $mSubs['Einzelne Bögen']['faqs'][0] ?? [];
$check(($mSubs['Einzelne Bögen']['depth'] ?? 0) === 3 && ($mSubs['Einzelne Bögen']['parent_id'] ?? '') === (string) $papers['term_id'], 'Unterkategorien mit depth und parent_id');
$check(array_keys($mDeep) === ['id', 'question', 'answer_html', 'answer_text', 'links'] && str_contains($mDeep['answer_text'] ?? '', 'Kontakt') && ! str_contains($mDeep['answer_text'] ?? '', '<'), 'FAQ mit answer_html, answer_text (Klartext) und links');
$check(in_array(['url' => esc_url_raw(home_url('/kontakt/')), 'text' => 'Kontakt'], $mDeep['links'] ?? [], true) && str_starts_with(home_url('/kontakt/'), 'http'), 'Links als absolute URLs');
$check(($api('/courses/'.$seeId.'/faqs', $customerToken)['body']['data'] ?? null) === $data && wp_json_encode(array_column($data[0]['subcategories'], 'name')) === wp_json_encode(array_column(CustomerFaqRepository::forCourse('SBF-SEE')[0]['subcategories'], 'name')), 'gleiche Daten wie im Web');
$r = $api('/courses/'.$comboId.'/faqs', $token($combi));
$check($r['status'] === 200 && array_column($r['body']['data'], 'name') === ['SBF See & Binnen', 'SBF See', 'SBF Binnen'], 'Kombi: getrennte Gruppen mit Scheinnamen');
$r = $api('/courses/SBF-SEE/faqs', $customerToken);
$check($r['status'] === 200, 'Kurscode statt ID wird akzeptiert');
$summary = $api('/courses/'.$seeId, $customerToken)['body']['data'] ?? [];
$trialSummary = $api('/courses/'.$seeId, $token($trial))['body']['data'] ?? [];
$check(($summary['faq_count'] ?? null) === 5 && ($trialSummary['faq_count'] ?? null) === 0, 'faq_count in der Kurs-Zusammenfassung (Kunde 5, Testkurs 0)');

// Aufräumen: Testdaten, Startstand der Kunden-FAQ (Entwürfe), Einstellungen wie vorher.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_AUTHORIZATION']);
TokenAuth::reset();
wp_set_current_user(1);
$wpdb->delete($p.'access_rule', ['id' => $trialRule]);
$wpdb->query("DELETE FROM {$p}learning_group_member WHERE group_id IN (SELECT id FROM {$p}learning_group WHERE order_id = 930001)");
$wpdb->query("DELETE FROM {$p}learning_group WHERE order_id = 930001");
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id = 993001");
$in = implode(',', array_map('intval', $users));
$wpdb->query("DELETE FROM {$p}entitlement WHERE user_id IN ({$in}) OR order_id = 930001");
$wpdb->query("DELETE FROM {$p}mobile_session WHERE user_id IN ({$in})");
foreach ($users as $id) {
    wp_delete_user($id);
}
$mobileBefore === false ? delete_option(MobileSettings::OPTION) : update_option(MobileSettings::OPTION, $mobileBefore);
update_option(Settings::OPTION, $settingsBefore);
$reset();
CustomerFaqImporter::maybeImport();
$check(count($all()) === 42 && Faq::POST_TYPE !== CustomerFaq::POST_TYPE, 'Startstand wiederhergestellt (42 Entwürfe)');

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
