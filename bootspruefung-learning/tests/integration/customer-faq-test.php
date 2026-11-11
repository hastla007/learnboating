<?php

declare(strict_types=1);

/*
 * Integrationstest Kunden-FAQ: Startinhalte (7 Scheine, Unterkategorien, 16 Entwürfe – 6 gemeinsame FAQs für
 * mehrere Scheine, je Schein 6 FAQs –, idempotent),
 * Zugriff (Gast, Testkurs, Kunde, Kombi, Ergänzung, Gruppe, „Zugang ohne Shop“), Zuordnung Kurs → Scheine,
 * verschachtelte und sortierte Unterkategorien, nur veröffentlichte FAQs, Platzhalter und kses, Abgrenzung zu
 * den öffentlichen FAQs (Shortcode, /bootspruefung/v1/faqs, /wp/v2, Suche, Sitemap) und die App-Schnittstelle
 * GET boatingschool/v1/courses/{id}/faqs mit answer_text und faq_count. Gemeinsame FAQs (eine FAQ in mehreren
 * Scheinen, im Kombikurs nur einmal), Admin-Spalten, Nachziehen unveränderter Start-FAQs bei neuem Datenstand
 * und die Migration von Datenstand 1 (Kopien je Schein zusammenführen, Papierkorb, Status, Bericht im Admin).
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

use Bootspruefung\Learning\Admin\CustomerFaqAdmin;
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
use Bootspruefung\Learning\Faq\FaqImporter;
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
$check(count($posts) === 16 && count($posts) === DefaultCustomerFaqs::total(), '16 Start-FAQs: '.count($posts));
$check(array_unique(array_map(static fn ($p) => $p->post_status, $posts)) === ['draft'], 'alle Start-FAQs als Entwurf');
$perSchein = [];
$shared = [];
$onlySubs = true;
foreach ($posts as $post) {
    $keysOfPost = [];
    foreach (wp_get_object_terms($post->ID, CustomerFaq::TAXONOMY) as $term) {
        $onlySubs = $onlySubs && $term->parent !== 0;
        $parent = get_term($term->parent, CustomerFaq::TAXONOMY);
        $keysOfPost[] = $parent instanceof WP_Term ? (string) get_term_meta($parent->term_id, CustomerFaq::TERM_KEY, true) : '-';
    }
    foreach (array_unique($keysOfPost) as $k) {
        $perSchein[$k][] = $post->ID;
    }
    if (count($keysOfPost) > 1) {
        $shared[(int) get_post_meta($post->ID, CustomerFaq::META_REF, true)] = count($keysOfPost);
    }
}
ksort($shared);
$check(count($perSchein) === 7 && min(array_map('count', $perSchein)) === 6 && max(array_map('count', $perSchein)) === 6 && $onlySubs, 'je Schein 6 FAQs, jede in Unterkategorien');
$check($shared === [1 => 7, 2 => 7, 3 => 7, 4 => 7, 5 => 2, 6 => 2], '6 gemeinsame FAQs (Nr. 1–4 in allen 7 Scheinen, 5 in SKN/FKN, 6 in beiden Binnen-Scheinen), 10 nur für einen Schein: '.wp_json_encode($shared));
$text = implode("\n", array_map(static fn ($p) => $p->post_title."\n".$p->post_content, $posts));
$check(! preg_match('/\d+(,\d+)?\s?(€|EUR|Euro)/u', $text), 'Startpaket ohne Preise');
$check(count(array_unique(array_map(static fn ($p) => $p->post_title, array_filter($posts, static fn ($p) => ! str_starts_with($p->post_title, 'Wie läuft die Prüfungssimulation') && $p->post_title !== 'Was übe ich im Funktraining?')))) === 7, 'keine wortgleiche Frage doppelt (außer Prüfungssimulation und Funktraining mit eigenen Antworten)');
$f101 = $byRef(1);
$check($f101 && $f101->post_title === 'Wann gilt eine Frage im Smarttrainer als gefestigt?' && str_contains($f101->post_content, '<strong>dreimal hintereinander richtig</strong>') && get_post_meta($f101->ID, CustomerFaq::META_NOTE, true) !== '', 'FAQ 1: Text als Block, **fett**, Redaktionshinweis');
$check(wp_get_object_terms($f101->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs', 'orderby' => 'term_id']) === ['sbf-see-lernen-im-kurs', 'sbf-binnen-lernen-im-kurs', 'sbf-binnen-segel-lernen-im-kurs', 'src-lernen-im-kurs', 'ubi-lernen-im-kurs', 'skn-lernen-im-kurs', 'fkn-lernen-im-kurs'], 'FAQ 1 (gemeinsam): „Lernen im Kurs“ aller 7 Scheine');
$f2 = $byRef(2);
$check($f2 && str_contains((string) get_post_meta($f2->ID, CustomerFaq::META_NOTE, true), 'nur veröffentlichen, wenn unter Bootsprüfung › Einstellungen die Prüfungsanmeldung eingeschaltet ist'), 'gemeinsame FAQ 2 behält den Redaktionshinweis (nur mit eingeschalteter Prüfungsanmeldung)');
$f6 = $byRef(6);
$check($f6 && wp_get_object_terms($f6->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs', 'orderby' => 'term_id']) === ['sbf-binnen-technik-und-zugang', 'sbf-binnen-segel-technik-und-zugang'] && str_contains((string) get_post_meta($f6->ID, CustomerFaq::META_NOTE, true), 'Dateien in der Mediathek'), 'FAQ 6 (Downloads): beide Binnen-Scheine, Hinweis zu den Dateien');
$check(array_column(array_filter(DefaultCustomerFaqs::faqs(), static fn ($f) => $f['replaces'] !== []), 'replaces', 'ref')[1] === [101, 201, 301, 401, 501, 601, 701], 'Nummern der früheren Kopien (Datenstand 1) hinterlegt');
$f102 = $byRef(102);
$check($f102 && str_contains($f102->post_content, 'mindestens 5 von 7 Basisfragen und 18 von 23 Fragen See') && str_contains($f102->post_content, '60 Minuten'), 'SBF See Prüfungssimulation: Grenzen je Teil wie im Regelwerk');
$nav = $byRef(103);
$check($nav && str_contains($nav->post_content, 'D49') && wp_get_object_terms($nav->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs']) === ['sbf-see-navigationsaufgaben'], 'Navigationsaufgaben (D49) in „Navigationsaufgaben“ unter SBF See');

echo "Idempotenz\n";
$termCount = count(get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false]));
wp_update_post(['ID' => $f101->ID, 'post_content' => '<!-- wp:paragraph --><p>Vom Betreiber geändert.</p><!-- /wp:paragraph -->']);
wp_delete_post($byRef(202)->ID, true);
wp_trash_post($byRef(103)->ID);
delete_option(CustomerFaqImporter::OPTION_VERSION);
delete_option(CustomerFaqImporter::OPTION_REPORT);
CustomerFaqImporter::maybeImport();
$check(count($all()) === 15 && get_option(CustomerFaqImporter::OPTION_REPORT) === false, 'Update-Lauf legt gelöschte Start-FAQs nicht neu an, überschreibt keine bearbeitete (kein Bericht)');
$check(CustomerFaqImporter::missingRefs() === [202], 'fehlend: nur die endgültig gelöschte FAQ 202 (Papierkorb zählt als vorhanden)');
$stats = CustomerFaqImporter::import();
$check($stats['created'] === 1 && $stats['terms'] === 0 && $stats['skipped'] === 15, 'Knopf „Fehlende Start-FAQs importieren“: nur die gelöschte FAQ 202 neu ('.wp_json_encode($stats).')');
$check(count(get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false])) === $termCount, 'keine Kategorie doppelt');
$check(str_contains((string) get_post_field('post_content', $f101->ID), 'Vom Betreiber geändert.') && get_post_status($byRef(103)->ID) === 'trash', 'Änderung bleibt, FAQ im Papierkorb kommt nicht zurück');
$check(CustomerFaqImporter::import()['created'] === 0, 'weiterer Lauf legt nichts an');
wp_untrash_post($byRef(103)->ID);

echo "Testdaten: veröffentlichen, verschachteln, sortieren\n";
$publish = static function (int $ref) use ($byRef): int {
    $post = $byRef($ref);
    wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);

    return (int) $post->ID;
};
$f102 = $publish(102);
$f103 = $publish(103);
$f202 = $publish(202);
$f502 = $publish(502);
$f101 = (int) $f101->ID; // gemeinsame FAQ 1 bleibt hier Entwurf (siehe „Gemeinsame FAQ“)
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
$secondId = $insert('Kunden-Testfrage danach', '<p>Reihenfolge 1.</p>', (int) $learn->term_id, 1);
$draftId = $insert('Kunden-Testfrage Entwurf', '<p>Nicht ausliefern.</p>', (int) $draftOnly['term_id'], 0, 'draft');

$groups = CustomerFaqRepository::forCourse('SBF-SEE');
$g = $groups[0] ?? ['subcategories' => [], 'faqs' => []];
$subs = array_map(static fn ($s) => [$s['name'], $s['depth']], $g['subcategories']);
$check(count($groups) === 1 && $g['key'] === 'SBF-SEE' && $g['name'] === 'SBF See', 'SBF See: eine Gruppe mit Scheinname');
$check($subs === [['Lernen im Kurs', 1], ['Prüfungssimulation', 1], ['Prüfungsbögen', 2], ['Einzelne Bögen', 3], ['Navigationsaufgaben', 1]], 'Unterkategorien verschachtelt (2 Ebenen unter dem Schein), sortiert, leere und reine Entwurfs-Kategorien fehlen: '.wp_json_encode($subs, JSON_UNESCAPED_UNICODE));
$bySub = array_column($g['subcategories'], null, 'name');
$check(($bySub['Einzelne Bögen']['parent'] ?? 0) === (int) $papers['term_id'] && ($bySub['Prüfungsbögen']['parent'] ?? 0) === (int) $exam->term_id && ($bySub['Prüfungssimulation']['parent'] ?? 0) === (int) $see->term_id, 'parent: Term-ID der übergeordneten Kategorie');
$check(array_column($bySub['Lernen im Kurs']['faqs'] ?? [], 'id') === [$firstId, $secondId], 'FAQs nach menu_order (0 vor 1)');
$flat = [];
foreach ($groups as $gr) {
    foreach ($gr['subcategories'] as $s) {
        $flat = array_merge($flat, array_column($s['faqs'], 'id'));
    }
}
$check(! in_array($draftId, $flat, true) && ! in_array($f101, $flat, true) && ! in_array($byRef(2)->ID, $flat, true) && count($flat) === 5, 'Entwürfe werden nicht geliefert (5 veröffentlichte SBF-See-FAQs)');
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
$check(! str_contains($shortcode, 'Kunden-Testfrage') && ! str_contains($shortcode, 'Wie läuft die Prüfungssimulation ab'), 'nicht im öffentlichen Shortcode (und nicht im JSON-LD)');
$public = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/faqs'))->get_data();
$customerIds = array_map(static fn ($p) => $p->ID, $all());
$check(is_array($public) && array_intersect(array_column($public, 'id'), $customerIds) === [], 'nicht in /bootspruefung/v1/faqs');
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE))->get_status() === 401 && rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f102))->get_status() === 401, '/wp/v2/bp_customer_faq als Gast: 401 (Liste und Einzel-FAQ)');
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::TAXONOMY))->get_status() === 401, '/wp/v2/bp_cfaq_category als Gast: 401');
wp_set_current_user($customer);
$check(rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE))->get_status() === 403, '/wp/v2 als Kunde: 403 (nur über den Kurs-Endpunkt)');
wp_set_current_user(1);
$editor = rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f102));
$check($editor->get_status() === 200 && rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::TAXONOMY))->get_status() === 200, '/wp/v2 für die Redaktion (Block-Editor): 200');
wp_set_current_user(0);
$search = new WP_Query(['s' => 'Kunden-Testfrage', 'post_type' => 'any', 'post_status' => 'publish']);
$single = new WP_Query(['p' => $f102, 'post_type' => 'any']);
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

echo "Gemeinsame FAQ in mehreren Scheinen\n";
// Web-Anfragen wieder ohne App-Token (wie beim Aufräumen).
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REQUEST_URI'] = '/';
TokenAuth::reset();
wp_set_current_user(1);
wp_update_post(['ID' => $f101, 'post_status' => 'publish']); // FAQ 1: „Lernen im Kurs“ aller 7 Scheine
$srcRadio = $child($top('SRC')->term_id, 'Funktraining');
$ubiRadio = $child($top('UBI')->term_id, 'Funktraining');
$radioShared = $insert('Kunden-Testfrage Funk gemeinsam', '<p>Für SRC und UBI.</p>', (int) $srcRadio->term_id);
wp_set_object_terms($radioShared, [(int) $srcRadio->term_id, (int) $ubiRadio->term_id], CustomerFaq::TAXONOMY); // wie zwei Häkchen im Editor
// Wo erscheint eine FAQ? Liste „Schein › Unterkategorie“ über alle Gruppen eines Kurses.
$where = static function (array $groups, int $id): array {
    $out = [];
    foreach ($groups as $g) {
        foreach (array_merge([['name' => '', 'faqs' => $g['faqs']]], $g['subcategories']) as $sub) {
            foreach ($sub['faqs'] as $f) {
                if ((int) $f['id'] === $id) {
                    $out[] = $g['key'].($sub['name'] !== '' ? ' › '.$sub['name'] : '');
                }
            }
        }
    }

    return $out;
};
$ids = static function (array $groups): array {
    $out = [];
    foreach (array_filter($groups, 'is_array') as $g) {
        $out = array_merge($out, array_column($g['faqs'], 'id'));
        foreach ($g['subcategories'] as $sub) {
            $out = array_merge($out, array_column($sub['faqs'], 'id'));
        }
    }

    return $out;
};
$everywhere = true;
foreach (CustomerFaq::SCHEIN_KEYS as $key) {
    $everywhere = $everywhere && $where(CustomerFaqRepository::forCourse($key), $f101) === [$key.' › Lernen im Kurs'];
}
$check($everywhere, 'FAQ 1 erscheint in jedem der 7 Scheine (je einmal unter „Lernen im Kurs“)');
$check($where(CustomerFaqRepository::forCourse('SRC'), $radioShared) === ['SRC › Funktraining'] && $where(CustomerFaqRepository::forCourse('UBI'), $radioShared) === ['UBI › Funktraining'], 'von Hand zwei Scheinen zugeordnete FAQ: in SRC und in UBI');
$check(CustomerFaqRepository::count('SBF-SEE') === 6 && CustomerFaqRepository::count('SRC') === 2 && CustomerFaqRepository::count('UBI') === 3, 'Anzahl je Einzelkurs zählt die gemeinsamen FAQs mit (SBF See 6, SRC 2, UBI 3)');
$comboGroups = CustomerFaqRepository::forCourse('SBF-SEE-BIN');
$binGroup = array_column($comboGroups, null, 'key')['SBF-BIN-MOTOR'] ?? ['subcategories' => []];
$check($where($comboGroups, $f101) === ['SBF-SEE › Lernen im Kurs'] && array_column($comboGroups, 'key') === ['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'] && ! in_array('Lernen im Kurs', array_column($binGroup['subcategories'], 'name'), true), 'Kombi SBF See & Binnen: FAQ 1 nur einmal, beim ersten Schein (SBF See); SBF Binnen ohne leere „Lernen im Kurs“');
$comboIds = $ids($comboGroups);
$check(count($comboIds) === count(array_unique($comboIds)) && CustomerFaqRepository::count('SBF-SEE-BIN') === count($comboIds) && count($comboIds) === 8, 'Kombi: keine FAQ doppelt, Anzahl ohne Dopplung (8)');
$srcUbi = CustomerFaqRepository::forCourse('SRC-UBI');
$check(array_column($srcUbi, 'key') === ['SRC', 'UBI'] && $where($srcUbi, $f101) === ['SRC › Lernen im Kurs'] && $where($srcUbi, $radioShared) === ['SRC › Funktraining'] && CustomerFaqRepository::count('SRC-UBI') === 3, 'Kombi SRC & UBI: gemeinsame FAQs nur unter SRC, UBI behält seine eigene (3 statt 5)');
$check($where(CustomerFaqRepository::forCourse('ADD-UBI-TO-SRC'), $f101) === ['UBI › Lernen im Kurs'], 'Ergänzung SRC → UBI: gemeinsame FAQ beim Zielschein UBI');
$r = $web($combi, 'sbf-see-bin');
$webIds = $ids((array) $r->get_data());
$check($r->get_status() === 200 && count(array_keys($webIds, $f101, true)) === 1 && count($webIds) === count(array_unique($webIds)), 'Web-REST Kombi: FAQ 1 genau einmal');
wp_set_current_user($combi);
$check((rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see-bin'))->get_data()['customer_faqs'] ?? null) === 8, 'Kursdaten Kombi: customer_faqs = 8 (Kurs-Hub der Web-App)');
wp_set_current_user(1);
$combiToken = $token($combi);
$r = $api('/courses/'.$comboId.'/faqs', $combiToken);
$appIds = array_map('intval', $ids((array) ($r['body']['data'] ?? [])));
$check($r['status'] === 200 && count(array_keys($appIds, $f101, true)) === 1 && count($appIds) === count(array_unique($appIds)) && ($r['body']['meta']['faqs'] ?? null) === 8, 'App Kombi: FAQ 1 genau einmal, meta.faqs = 8');
$check(($api('/courses/'.$comboId, $combiToken)['body']['data']['faq_count'] ?? null) === 8, 'App: faq_count im Kombikurs ohne Dopplung (8)');
$r = $api('/courses/'.(int) $content->courseByCode('SRC-UBI')->id.'/faqs', $combiToken);
$check($r['status'] === 403, 'App: Kombi-Kunde SBF hat keinen Zugriff auf SRC & UBI');

echo "Admin: Spalten und Editor\n";
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REQUEST_URI'] = '/';
TokenAuth::reset();
wp_set_current_user(1);
$column = static function (string $name, int $id): string {
    ob_start();
    (new CustomerFaqAdmin())->column($name, $id);

    return (string) ob_get_clean();
};
$schein1 = $column('bp_cfaq_schein', $f101);
$check(wp_strip_all_tags($schein1) === 'SBF See, SBF Binnen, SBF Binnen unter Segel, SRC, UBI, SKN, FKN' && substr_count($schein1, '<a ') === 7 && str_contains($schein1, 'bp_cfaq_category=kunden-src'), 'Spalte „Schein“: alle 7 Scheine der gemeinsamen FAQ, je mit Filter-Link');
$check($column('bp_cfaq_sub', $f101) === 'Lernen im Kurs', 'Spalte „Unterkategorie“: gleicher Pfad mehrerer Scheine nur einmal');
$check(wp_strip_all_tags($column('bp_cfaq_schein', $radioShared)) === 'SRC, UBI' && $column('bp_cfaq_sub', $radioShared) === 'Funktraining', 'von Hand zugeordnete FAQ: SRC, UBI');
$check(wp_strip_all_tags($column('bp_cfaq_schein', $f102)) === 'SBF See' && $column('bp_cfaq_sub', $deepId) === 'Prüfungssimulation › Prüfungsbögen › Einzelne Bögen', 'FAQ eines Scheins: ein Schein; tiefe Unterkategorie als Pfad');
$check(str_contains($column('bp_cfaq_schein', $comboFaq), 'SBF See &amp; Binnen') && $column('bp_cfaq_sub', $comboFaq) === '–', 'FAQ direkt am Schein: Schein, keine Unterkategorie');
$tax = get_taxonomy(CustomerFaq::TAXONOMY);
$rest = rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f101))->get_data();
$check($tax->show_ui && $tax->show_in_rest && $tax->hierarchical && count($rest[CustomerFaq::TAXONOMY] ?? []) === 7, 'Editor: Kategorie-Box mit Häkchen (hierarchisch, im Block-Editor über /wp/v2), FAQ 1 liefert alle 7 Kategorien');
$save = new WP_REST_Request('POST', '/wp/v2/'.CustomerFaq::POST_TYPE.'/'.$f102);
$save->set_body_params([CustomerFaq::TAXONOMY => [(int) $exam->term_id, (int) $child($top('SBF-BIN-MOTOR')->term_id, 'Prüfungssimulation')->term_id]]);
$saved = rest_do_request($save);
$check($saved->get_status() === 200 && wp_strip_all_tags($column('bp_cfaq_schein', $f102)) === 'SBF See, SBF Binnen', 'Editor (REST wie Block-Editor): zwei Kategorien anhaken speichert beide');
wp_set_object_terms($f102, [(int) $exam->term_id], CustomerFaq::TAXONOMY);

echo "Nachziehen unveränderter Start-FAQs (neuer Datenstand)\n";
$data = DefaultCustomerFaqs::faqs();
foreach ($data as &$faq) {
    if (in_array($faq['ref'], [1, 2, 102, 302, 402], true)) {
        $faq['answer'] .= "\n\nNeu im Datenstand 3.";
        $faq['note'] = 'Neuer Hinweis.';
    }
}
unset($faq);
$data[] = ['ref' => 107, 'since' => '3', 'question' => 'Kunden-Testfrage neu im Datenstand 3', 'answer' => 'Neu.', 'note' => null, 'order' => 5,
    'placements' => [['SBF-SEE', 'Lernen im Kurs'], ['SRC', 'Lernen im Kurs']], 'replaces' => []];
// 1: vom Betreiber bearbeitet (siehe Idempotenz), 402: jetzt bearbeitet, 302: im Papierkorb, 602: endgültig gelöscht;
// 2 (Entwurf) und 102 (veröffentlicht) unverändert.
wp_update_post(['ID' => $byRef(402)->ID, 'post_title' => 'Vom Betreiber umformuliert?']);
$f302 = $byRef(302);
wp_trash_post($f302->ID);
wp_delete_post($byRef(602)->ID, true);
$up = CustomerFaqImporter::upgrade('2', $data);
$check($up['updated'] === [2, 102] && $up['kept'] === [1, 402] && $up['added'] === [107] && $up['merged'] === [], 'aktualisiert 2 und 102, bearbeitete 1 und 402 bleiben, neue 107 angelegt ('.wp_json_encode(array_intersect_key($up, array_flip(['updated', 'kept', 'added']))).')');
$p102 = get_post($f102);
$p2 = $byRef(2);
$check(str_contains($p102->post_content, 'Neu im Datenstand 3.') && $p102->post_status === 'publish' && get_post_meta($f102, CustomerFaq::META_NOTE, true) === 'Neuer Hinweis.', 'veröffentlichte unveränderte FAQ 102: neuer Text und Hinweis, bleibt veröffentlicht');
$check(str_contains($p2->post_content, 'Neu im Datenstand 3.') && $p2->post_status === 'draft' && count(wp_get_object_terms($p2->ID, CustomerFaq::TAXONOMY)) === 7, 'gemeinsame FAQ 2: neuer Text, bleibt Entwurf und in allen 7 Scheinen');
$check(get_post_meta($p2->ID, CustomerFaqImporter::META_DEFAULT_HASH, true) === FaqImporter::hash($p2->post_title, $p2->post_content), 'Prüfsumme auf den neuen Stand gesetzt');
$check(! str_contains((string) get_post_field('post_content', $byRef(402)->ID), 'Neu im Datenstand 3.') && get_post_field('post_title', $byRef(402)->ID) === 'Vom Betreiber umformuliert?' && str_contains((string) get_post_field('post_content', $f101), 'Vom Betreiber geändert.'), 'bearbeitete FAQs 402 und 1 unverändert');
$check(get_post_status($f302->ID) === 'trash' && get_post_field('post_content', $f302->ID) === $f302->post_content && $byRef(602) === null, 'FAQ im Papierkorb unverändert, endgültig gelöschte 602 kommt nicht zurück');
$p107 = $byRef(107);
$check($p107 && $p107->post_status === 'draft' && wp_get_object_terms($p107->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs', 'orderby' => 'term_id']) === ['sbf-see-lernen-im-kurs', 'src-lernen-im-kurs'], 'neue Start-FAQ 107 als Entwurf in beiden Scheinen');
$again = CustomerFaqImporter::upgrade('3', $data);
$check($again['updated'] === [] && $again['added'] === [] && $again['kept'] === [1, 402], 'zweiter Lauf ändert nichts');

echo "Migration Datenstand 1 → 2 (Kopien zusammenführen)\n";
$reset();
CustomerFaqImporter::import();
// Datenstand 1 nachbauen: gemeinsame FAQs als Kopie je Schein (Text, Nummer, Prüfsumme und Hinweis wie damals).
$copy = [];
foreach (DefaultCustomerFaqs::faqs() as $faq) {
    if ($faq['replaces'] === []) {
        continue;
    }
    wp_delete_post($byRef($faq['ref'])->ID, true);
    foreach ($faq['replaces'] as $i => $oldRef) {
        [$key, $sub] = $faq['placements'][$i];
        $id = (int) wp_insert_post(wp_slash(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => 'draft', 'post_title' => $faq['question'], 'post_content' => FaqImporter::content($faq),
            'menu_order' => $faq['order'], 'meta_input' => [CustomerFaq::META_REF => $oldRef, CustomerFaq::META_NOTE => $faq['note']]]));
        wp_set_object_terms($id, [(int) $child($top($key)->term_id, $sub)->term_id], CustomerFaq::TAXONOMY);
        update_post_meta($id, CustomerFaqImporter::META_DEFAULT_HASH, FaqImporter::hash((string) get_post_field('post_title', $id), (string) get_post_field('post_content', $id)));
        $copy[$oldRef] = $id;
    }
}
$check(count($all()) === 42 && count($copy) === 32, 'Datenstand 1 nachgebaut: 42 FAQs, davon 32 Kopien gemeinsamer Fragen');
foreach ([101, 201, 301, 401, 501, 601, 701, 105, 204] as $r) {
    wp_update_post(['ID' => $copy[$r], 'post_status' => 'publish']); // Frage 1: alle veröffentlicht; Frage 3: nur SBF See und SBF Binnen
}
wp_update_post(['ID' => $copy[404], 'post_content' => '<!-- wp:paragraph --><p>Anmeldung bei uns im Büro.</p><!-- /wp:paragraph -->']); // Frage 2: SRC-Kopie bearbeitet
wp_trash_post($copy[106]); // Frage 4: SBF See im Papierkorb, SBF Binnen endgültig gelöscht
wp_delete_post($copy[205], true);
$fknLearn = $child($top('FKN')->term_id, 'Lernen im Kurs');
wp_set_object_terms($copy[703], [(int) $fknLearn->term_id], CustomerFaq::TAXONOMY); // Frage 5: FKN-Kopie vom Betreiber umgehängt
wp_trash_post($copy[206]); // Frage 6: beide Kopien im Papierkorb
wp_trash_post($copy[306]);
update_option(CustomerFaqImporter::OPTION_VERSION, '1');
delete_option(CustomerFaqImporter::OPTION_REPORT);
CustomerFaqImporter::maybeImport();
$report = get_option(CustomerFaqImporter::OPTION_REPORT);
$merged = is_array($report) ? array_column($report['merged'], null, 'ref') : [];
$check(get_option(CustomerFaqImporter::OPTION_VERSION) === CustomerFaqImporter::VERSION && is_array($report) && array_keys($merged) === [1, 3, 4, 5], 'Update auf Datenstand 2: Fragen 1, 3, 4, 5 zusammengeführt, Bericht gespeichert');
$m1 = $byRef(1);
$trashed1 = array_map(static fn (int $r): string => (string) get_post_status($copy[$r]).':'.get_post_meta($copy[$r], CustomerFaq::META_REF, true), [201, 301, 401, 501, 601, 701]);
$check($m1 && $m1->ID === $copy[101] && $m1->post_status === 'publish' && count(wp_get_object_terms($m1->ID, CustomerFaq::TAXONOMY)) === 7 && get_post_meta($m1->ID, CustomerFaqImporter::META_MERGED_FROM, true) === '101,201,301,401,501,601,701', 'Frage 1: Kopie SBF See wird gemeinsame FAQ Nr. 1, veröffentlicht (alle Kopien waren es), alle 7 Kategorien');
$check($trashed1 === ['trash:201', 'trash:301', 'trash:401', 'trash:501', 'trash:601', 'trash:701'] && $merged[1]['trashed'] === [201, 301, 401, 501, 601, 701], 'überzählige Kopien im Papierkorb (nicht gelöscht, Nummer bleibt)');
$check(get_post_meta($m1->ID, CustomerFaqImporter::META_DEFAULT_HASH, true) === FaqImporter::hash($m1->post_title, $m1->post_content) && get_post_meta($m1->ID, CustomerFaq::META_NOTE, true) !== '', 'gemeinsame FAQ: Prüfsumme und Redaktionshinweis gesetzt');
$copies2 = array_map(static fn (int $r): string => (string) get_post_status($copy[$r]), [104, 203, 303, 404, 504, 604, 704]);
$check($byRef(2) === null && array_unique($copies2) === ['draft'] && ($report['not_merged'] ?? []) === [['ref' => 2, 'question' => 'Wie melde ich mich zur Prüfung an?', 'edited' => [404]]], 'Frage 2: eine Kopie bearbeitet → nichts zusammengeführt, alle 7 Kopien bleiben');
$m3 = $byRef(3);
$check($m3 && $m3->ID === $copy[105] && $m3->post_status === 'draft' && $merged[3]['mixed'] === true && $merged[3]['published_in'] === ['SBF See', 'SBF Binnen'], 'Frage 3: gemischter Status → Entwurf, Bericht nennt SBF See und SBF Binnen');
$m4 = $byRef(4);
$check($m4 && $m4->ID === $copy[305] && wp_get_object_terms($m4->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs', 'orderby' => 'term_id']) === ['sbf-binnen-segel-technik-und-zugang', 'src-technik-und-zugang', 'ubi-technik-und-zugang', 'skn-technik-und-zugang', 'fkn-technik-und-zugang'] && $merged[4]['from'] === [305, 406, 506, 606, 706], 'Frage 4: ohne die gelöschten Kopien (SBF See, SBF Binnen) zusammengeführt, nur deren 5 Scheine');
$check(get_post_status($copy[106]) === 'trash' && (int) get_post_meta($copy[106], CustomerFaq::META_REF, true) === 106, 'Kopie im Papierkorb wird nicht wiederbelebt');
$m5 = $byRef(5);
$check($m5 && $m5->ID === $copy[603] && wp_get_object_terms($m5->ID, CustomerFaq::TAXONOMY, ['fields' => 'slugs', 'orderby' => 'term_id']) === ['skn-pruefungssimulation', 'fkn-lernen-im-kurs'] && $m5->post_status === 'draft', 'Frage 5: Kategorien der Kopien übernommen (auch vom Betreiber umgehängte), Entwurf bleibt');
$check($byRef(6) === null && get_post_status($copy[206]) === 'trash' && get_post_status($copy[306]) === 'trash', 'Frage 6: alle Kopien im Papierkorb → keine gemeinsame FAQ');
$check(CustomerFaqImporter::missingRefs() === [] && CustomerFaqImporter::import()['created'] === 0, 'Knopf „Fehlende Start-FAQs importieren“ legt keine gemeinsame FAQ neben vorhandenen Kopien an');
$live = array_filter($all(), static fn ($p) => $p->post_status !== 'trash');
$check(count($live) === 21 && count($all()) === 41, '21 FAQs außerhalb des Papierkorbs, nichts endgültig gelöscht (41 = 42 − die vom Betreiber gelöschte)');
$check(CustomerFaqRepository::count('SRC') === 1 && CustomerFaqRepository::count('SBF-SEE-BIN') === 1, 'veröffentlichte gemeinsame FAQ in SRC, im Kombikurs einmal');
ob_start();
CustomerFaqAdmin::renderReport($report);
$html = (string) ob_get_clean();
$check(str_contains($html, 'notice-warning') && str_contains($html, 'Nr. 1 aus Nr. 101, 201, 301, 401, 501, 601, 701') && str_contains($html, 'veröffentlicht in: SBF See, SBF Binnen') && str_contains($html, 'bearbeitete Kopie Nr. 404') && str_contains($html, 'bp_cfaq_report_dismiss') && ! str_contains($html, 'nicht aktualisiert (der Standardtext'), 'Bericht im Admin: zusammengeführt, Entwurf wegen gemischtem Status, nicht zusammengeführt, ausblendbar');
$check(wp_strip_all_tags($column('bp_cfaq_schein', $m4->ID)) === 'SBF Binnen unter Segel, SRC, UBI, SKN, FKN', 'Spalte „Schein“ der zusammengeführten FAQ');
update_option(CustomerFaqImporter::OPTION_VERSION, '1');
CustomerFaqImporter::maybeImport();
$check(count(array_filter($all(), static fn ($p) => $p->post_status !== 'trash')) === 21 && count($all()) === 41 && $byRef(1)->ID === $copy[101], 'erneuter Lauf: nichts mehr zusammenzuführen');

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
$check(count($all()) === 16 && Faq::POST_TYPE !== CustomerFaq::POST_TYPE, 'Startstand wiederhergestellt (16 Entwürfe)');

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
