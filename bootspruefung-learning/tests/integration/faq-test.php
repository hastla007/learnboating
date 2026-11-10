<?php

declare(strict_types=1);

/*
 * Integrationstest FAQ-Sektion: Import der Standard-FAQs (Rubriken, Unterkategorien, ★, Entwürfe),
 * Idempotenz, bootspruefung_faqs(), Platzhalter {{agb}}, Shortcode, REST und – falls ladbar – die
 * Kurs-FAQ des Themes Kurswasser. Setzt die FAQs zu Beginn zurück und hinterlässt den Standardstand.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/faq-test.php
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

use Bootspruefung\Learning\Admin\FaqAdmin;
use Bootspruefung\Learning\Faq\DefaultFaqs;
use Bootspruefung\Learning\Faq\Faq;
use Bootspruefung\Learning\Faq\FaqImporter;
use Bootspruefung\Learning\Faq\FaqRepository;
use Bootspruefung\Learning\Security\Capabilities;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
wp_set_current_user(1);

$allStatuses = array_values(array_diff(array_keys(get_post_stati()), ['auto-draft']));
$reset = static function () use ($allStatuses): void {
    foreach (get_posts(['post_type' => Faq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        wp_delete_post((int) $id, true);
    }
    foreach (get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']) ?: [] as $termId) {
        wp_delete_term((int) $termId, Faq::TAXONOMY);
    }
    delete_option(FaqImporter::OPTION_VERSION);
    FaqRepository::flush();
};
$byRef = static function (int $ref) use ($allStatuses): ?WP_Post {
    $posts = get_posts(['post_type' => Faq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => 1, 'meta_key' => Faq::META_REF, 'meta_value' => (string) $ref]);

    return $posts[0] ?? null;
};
$refs = static fn (array $faqs): array => array_map(static fn ($f) => (int) $f['ref'], $faqs);
$termsPageBefore = get_option('woocommerce_terms_page_id');
$pageOptionBefore = get_option(Faq::PAGE_OPTION);
$createdPages = [];

$reset();

echo "Import der Standard-FAQs\n";
$check(FaqImporter::needsImport(), 'ohne Datenstand: Import fällig');
FaqImporter::maybeImport();
$check(! FaqImporter::needsImport(), 'Datenstand gespeichert (wie Migrationen)');
$rubrics = get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false, 'parent' => 0]);
$subs = array_filter(get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false]), static fn ($t) => $t->parent !== 0);
$check(count($rubrics) === 11, '11 Rubriken: '.count($rubrics));
$check(count($subs) === 48, '48 Unterkategorien: '.count($subs));
$rubricIds = wp_list_pluck($rubrics, 'term_id');
$check(array_filter($subs, static fn ($t) => ! in_array($t->parent, $rubricIds, true)) === [], 'jede Unterkategorie hängt direkt an einer Rubrik');
$check(array_filter($rubrics, static fn ($t) => get_term_children($t->term_id, Faq::TAXONOMY) === []) === [], 'jede Rubrik hat Unterkategorien');
$keys = array_column(FaqRepository::rubrics(), 'key');
$check($keys === Faq::RUBRIC_KEYS, 'Rubrik-Schlüssel und Reihenfolge wie in der Datei: '.implode(', ', $keys));
$lernen = get_term_by('slug', 'sbf-see-lernen', Faq::TAXONOMY);
$check($lernen instanceof WP_Term && $lernen->name === 'Lernen' && get_term_meta($lernen->parent, Faq::TERM_KEY, true) === 'SBF-SEE' && (int) get_term_meta($lernen->term_id, Faq::TERM_ORDER, true) === 2, 'gleichnamige Unterkategorie eindeutig: sbf-see-lernen unter SBF-SEE, Reihenfolge 2');
$check(count(get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false, 'name' => 'Lernen'])) === 11, '„Lernen“ unter allen 11 Rubriken mit eigenen Slugs');

$all = get_posts(['post_type' => Faq::POST_TYPE, 'post_status' => ['publish', 'draft'], 'numberposts' => -1]);
$check(count($all) === 92, '92 FAQs wie in der Datei: '.count($all));
$stars = array_filter($all, static fn ($p) => (bool) get_post_meta($p->ID, Faq::META_THEME, true));
$check(count($stars) === 41, '41 FAQs mit ★: '.count($stars));
$drafts = array_map(static fn ($p) => (int) get_post_meta($p->ID, Faq::META_REF, true), array_filter($all, static fn ($p) => $p->post_status === 'draft'));
sort($drafts);
$check($drafts === [9, 11, 12, 13, 14, 19, 20, 21, 23], 'Entwürfe genau bei Platzhaltern bzw. Redaktionshinweis ohne ★: '.implode(', ', $drafts));
$f22 = $byRef(22);
$check($f22 && $f22->post_status === 'publish' && str_contains($f22->post_content, 'Alle Bedingungen stehen in den {{agb}}.') && ! str_contains($f22->post_content, '['), 'FAQ 22 (★): Platzhalter → {{agb}}, veröffentlicht');
$f51 = $byRef(51);
$f85 = $byRef(85);
$check($f51 && $f85 && $f51->post_status === 'publish' && $f85->post_status === 'publish'
    && get_post_meta($f51->ID, Faq::META_NOTE, true) === 'tatsächliche Angebotsstufen bestätigen.' && ! str_contains($f51->post_content, 'Redaktion'), 'FAQ 51/85 (★ mit Redaktionshinweis): veröffentlicht, Hinweis nur in _bp_faq_note');
$f9 = $byRef(9);
$check($f9 && get_post_meta($f9->ID, Faq::META_NOTE, true) === 'enthaltene Leistungen und Ausschlüsse ergänzen.' && ! str_contains($f9->post_content, 'Redaktion'), 'Redaktionszeile nicht in der Antwort');
$f10 = $byRef(10);
$check($f10 && $f10->post_title === 'Wie lange habe ich Zugriff auf die Lernplattform?' && ! str_contains($f10->post_title.$f10->post_content, '★') && str_contains($f10->post_content, '<p>Je nach Kurs gilt der Zugang 12 oder 6 Monate ab Zahlungseingang.'), 'Texte wörtlich, ohne ★');
$f28 = $byRef(28);
$check($f28 && (int) $f28->menu_order === 2 && wp_get_object_terms($f28->ID, Faq::TAXONOMY, ['fields' => 'slugs']) === ['sbf-see-lernen'], 'menu_order nach Reihenfolge in der Unterkategorie (FAQ 28 = 2)');

echo "Idempotenz\n";
$termCount = count(get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false]));
$f1 = $byRef(1);
wp_update_post(['ID' => $f1->ID, 'post_content' => '<!-- wp:paragraph --><p>Vom Betreiber geändert.</p><!-- /wp:paragraph -->']);
wp_delete_post($byRef(2)->ID, true);
wp_trash_post($byRef(3)->ID);
delete_option(FaqImporter::OPTION_VERSION);
FaqImporter::maybeImport();
$check(count(get_posts(['post_type' => Faq::POST_TYPE, 'post_status' => $allStatuses, 'numberposts' => -1, 'fields' => 'ids'])) === 91, 'Update-Lauf importiert nicht, wenn es schon FAQs gibt');
$stats = FaqImporter::import();
$check($stats['created'] === 1 && $stats['terms'] === 0 && $stats['skipped'] === 91, 'zweiter Lauf: nur die gelöschte FAQ 2 neu, nichts doppelt ('.wp_json_encode($stats).')');
$check(count(get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false])) === $termCount, 'keine Kategorie doppelt');
$check(str_contains((string) get_post_field('post_content', $f1->ID), 'Vom Betreiber geändert.'), 'geänderte Antwort bleibt');
$check(get_post_status($byRef(3)->ID) === 'trash', 'FAQ im Papierkorb kommt nicht zurück');
$again = FaqImporter::import();
$check($again['created'] === 0 && count(FaqImporter::existingRefs()) === 92 && count(array_unique(FaqImporter::existingRefs())) === 92, 'dritter Lauf legt nichts an');
wp_untrash_post($byRef(3)->ID);
wp_publish_post($byRef(3)->ID);

echo "Unveränderte Standard-FAQs aktualisieren (neuer Datenstand)\n";
$check(FaqImporter::VERSION !== '1', 'Datenstand erhöht: '.FaqImporter::VERSION);
$f4 = $byRef(4);
$f5 = $byRef(5);
$f12 = $byRef(12);
$check(get_post_meta($f4->ID, FaqImporter::META_DEFAULT_HASH, true) === FaqImporter::hash($f4->post_title, $f4->post_content) && get_post_meta($f12->ID, FaqImporter::META_DEFAULT_STATUS, true) === 'draft', 'Import merkt Prüfsumme und Status des Standardstands');
// Neuer Standardstand (simuliert): Texte von 1 (vom Betreiber geändert), 4, 5 (ohne Prüfsumme wie Datenstand 1) und 12 (Entwurf mit Platzhalter).
delete_post_meta($f5->ID, FaqImporter::META_DEFAULT_HASH);
delete_post_meta($f5->ID, FaqImporter::META_DEFAULT_STATUS);
$newDefaults = DefaultFaqs::RUBRICS;
foreach ($newDefaults as &$rubric) {
    foreach ($rubric['subcategories'] as &$sub) {
        foreach ($sub['faqs'] as &$faq) {
            if (in_array($faq['ref'], [1, 4, 5, 12], true)) {
                $faq['answer'] = 'Neuer Standardtext für FAQ '.$faq['ref'].' & mehr.';
                $faq['note'] = $faq['ref'] === 12 ? null : $faq['note'];
            }
            if ($faq['ref'] === 4) {
                $faq['question'] = 'Neue Frage 4?';
            }
        }
        unset($faq);
    }
    unset($sub);
}
unset($rubric);
$upd = FaqImporter::updateDefaults($newDefaults);
sort($upd['updated']);
$check($upd['updated'] === [4, 5, 12] && $upd['kept'] === [1], 'aktualisiert 4, 5, 12; vom Betreiber geänderte FAQ 1 bleibt ('.wp_json_encode($upd).')');
$f4 = get_post($f4->ID);
$check($f4->post_title === 'Neue Frage 4?' && str_contains($f4->post_content, '<p>Neuer Standardtext für FAQ 4 &amp; mehr.</p>') && $f4->post_status === 'publish', 'FAQ 4: Frage und Antwort neu, Status bleibt veröffentlicht');
$check(str_contains((string) get_post_field('post_content', $f5->ID), 'Neuer Standardtext für FAQ 5'), 'FAQ 5 ohne gemerkte Prüfsumme: über DefaultFaqHistory als unverändert erkannt');
$check(str_contains((string) get_post_field('post_content', $f1->ID), 'Vom Betreiber geändert.'), 'FAQ 1 (geändert) unverändert');
$check(get_post_status($f12->ID) === 'publish' && get_post_meta($f12->ID, Faq::META_NOTE, true) === '', 'FAQ 12: ohne Platzhalter jetzt veröffentlicht, Redaktionshinweis entfernt');
$again = FaqImporter::updateDefaults($newDefaults);
$check($again['updated'] === [] && $again['kept'] === [1], 'zweiter Lauf ändert nichts');
// Betreiber ändert FAQ 4 nach dem Update → bleibt beim nächsten Stand; FAQ 12 bekommt vom Betreiber den Status Entwurf.
wp_update_post(['ID' => $f4->ID, 'post_content' => '<!-- wp:paragraph --><p>Betreibertext 4.</p><!-- /wp:paragraph -->']);
wp_update_post(['ID' => $f12->ID, 'post_status' => 'draft']);
// Zurück auf den echten Datenstand (wie nach einem Plugin-Update): maybeImport() mit neuer VERSION.
update_option(FaqImporter::OPTION_VERSION, '1');
FaqImporter::maybeImport();
$check(get_option(FaqImporter::OPTION_VERSION) === FaqImporter::VERSION, 'maybeImport: Datenstand gespeichert');
$check(str_contains((string) get_post_field('post_content', $f4->ID), 'Betreibertext 4.'), 'FAQ 4 nach Betreiber-Änderung nicht überschrieben');
$check(str_contains((string) get_post_field('post_content', $f5->ID), 'Das Mindestalter hängt von Schein und Antriebsart ab.') && str_contains((string) get_post_field('post_content', $f12->ID), '[Link zu den Standorten ergänzen.]'), 'FAQ 5 und 12 wieder auf dem Standardtext');
$check(get_post_status($f12->ID) === 'draft', 'FAQ 12: Status bleibt Entwurf (Standard: Entwurf wegen Platzhalter)');
wp_update_post(['ID' => $f4->ID, 'post_title' => DefaultFaqs::RUBRICS[0]['subcategories'][1]['faqs'][0]['question'], 'post_content' => FaqImporter::content(DefaultFaqs::RUBRICS[0]['subcategories'][1]['faqs'][0])]);

echo "bootspruefung_faqs()\n";
$fields = ['id', 'ref', 'question', 'answer_html', 'rubric', 'rubric_label', 'subcategory', 'subcategory_label', 'theme'];
$faqs = bootspruefung_faqs();
$expected = [];
foreach (DefaultFaqs::RUBRICS as $rubric) {
    foreach ($rubric['subcategories'] as $sub) {
        foreach ($sub['faqs'] as $faq) {
            if (FaqImporter::statusFor($faq) === 'publish') {
                $expected[] = $faq['ref'];
            }
        }
    }
}
$check(count($faqs) === 83 && $refs($faqs) === $expected, '83 veröffentlichte FAQs, sortiert nach Rubrik, Unterkategorie, menu_order');
$check(array_keys($faqs[0]) === $fields, 'Felder: '.implode(', ', array_keys($faqs[0])));
$see = bootspruefung_faqs(['rubric' => 'SBF-SEE', 'theme_only' => true]);
$check($refs($see) === [24, 30, 25, 26], 'SBF-SEE ★: 24, 30 (Kursinhalt), 25 (Lernmaterial), 26 (Prüfung) – '.implode(', ', $refs($see)));
$check($see[0]['rubric'] === 'SBF-SEE' && $see[0]['rubric_label'] === 'SBF See' && $see[0]['subcategory'] === 'sbf-see-kursinhalt' && $see[0]['subcategory_label'] === 'Kursinhalt' && $see[0]['theme'] === true, 'Rubrik und Unterkategorie je FAQ');
$check($refs(bootspruefung_faqs(['rubric' => 'allgemein', 'theme_only' => true])) === [10, 88, 89, 90, 92, 22, 91], 'allgemein ★: 10, 88, 89, 90, 92, 22, 91');
$check($refs(bootspruefung_faqs(['rubric' => 'SBF-SEE', 'subcategory' => 'sbf-see-lernen'])) === [27, 28, 29], 'Unterkategorie sbf-see-lernen: 27, 28, 29');
$check(count(bootspruefung_faqs(['rubric' => 'KAPITAEN'])) === 5 && count(bootspruefung_faqs(['rubric' => 'gibt-es-nicht'])) === 0, 'Kapitäns-Bundle 5 FAQs, unbekannte Rubrik leer');
$check(! in_array(9, $refs($faqs), true) && ! str_contains(wp_json_encode($faqs, JSON_UNESCAPED_UNICODE), 'Redaktion'), 'keine Entwürfe, keine Redaktionshinweise');
$check(preg_match('#^<p( class="wp-block-paragraph")?>Du übst#', $see[0]['answer_html']) === 1 && ! str_contains($see[0]['answer_html'], 'wp:paragraph'), 'answer_html: gerendertes HTML ohne Blockkommentare');

// Platzhalter {{agb}}
update_option('woocommerce_terms_page_id', 0);
$g22 = bootspruefung_faqs(['rubric' => 'allgemein', 'theme_only' => true])[5];
$check($g22['ref'] === 22 && str_ends_with($g22['answer_html'], 'Alle Bedingungen stehen in den AGB.</p>') && ! str_contains($g22['answer_html'], '{{'), 'ohne AGB-Seite: nur „AGB“');
$createdPages[] = $agbId = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AGB FAQ-Test', 'post_name' => 'agb-faq-test']);
update_option('woocommerce_terms_page_id', $agbId);
$g22 = bootspruefung_faqs(['rubric' => 'allgemein', 'theme_only' => true])[5];
$check(str_contains($g22['answer_html'], 'Alle Bedingungen stehen in den <a href="'.esc_url(get_permalink($agbId)).'">AGB</a>.'), '{{agb}} → Link auf die AGB-Seite (wc_terms_and_conditions_page_id)');

// Zwischenspeicher wird beim Speichern geleert
$f24 = $byRef(24);
wp_update_post(['ID' => $f24->ID, 'post_title' => 'Geänderte Frage zum SBF See?']);
$check(bootspruefung_faqs(['rubric' => 'SBF-SEE', 'theme_only' => true])[0]['question'] === 'Geänderte Frage zum SBF See?', 'Cache: geänderte Frage sofort sichtbar');
wp_update_post(['ID' => $f24->ID, 'post_title' => 'Was lerne ich im SBF-See-Kurs?']);
update_post_meta($byRef(27)->ID, Faq::META_THEME, '1');
$check(in_array(27, $refs(bootspruefung_faqs(['rubric' => 'SBF-SEE', 'theme_only' => true])), true), 'Cache: ★ setzen wirkt sofort');
update_post_meta($byRef(27)->ID, Faq::META_THEME, '0');
wp_update_post(['ID' => $byRef(26)->ID, 'post_status' => 'draft']);
$check($refs(bootspruefung_faqs(['rubric' => 'SBF-SEE', 'theme_only' => true])) === [24, 30, 25], 'Cache: Entwurf verschwindet sofort');
wp_publish_post($byRef(26)->ID);

echo "Shortcode [bootspruefung_faq]\n";
$html = do_shortcode('[bootspruefung_faq]');
preg_match_all('#<nav class="bp-faq-nav"[^>]*>.*?</nav>#s', $html, $nav);
$check(isset($nav[0][0]) && substr_count($nav[0][0], '<a href="#bp-faq-') === 11 && str_contains($nav[0][0], 'href="#bp-faq-sbf-see-bin"'), 'Navigation über 11 Rubriken (Anker)');
$check(substr_count($html, '<details class="wp-block-details" data-bp-faq-item><summary>') === 83, 'alle 83 FAQs als <details><summary>');
$check(substr_count($html, '<h2 ') === 11 && substr_count($html, '<h3 class="bp-faq-group-title">') >= 40 && ! str_contains($html, '<h4'), 'Überschriften: Rubrik h2, Unterkategorie h3');
$check(str_contains($html, 'id="bp-faq-allgemein"') && str_contains($html, 'class="bp-faq-search" role="search" hidden') && str_contains($html, "querySelectorAll('[data-bp-faq]')"), 'Rubrik-Anker, Suche (ohne JS ausgeblendet) und Inline-Skript');
$check(! str_contains($html, 'Was ist im Kurspreis enthalten?'), 'Entwürfe nicht im Shortcode');
$ld = null;
if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
    $ld = json_decode($m[1], true);
}
$check(is_array($ld) && $ld['@type'] === 'FAQPage' && count($ld['mainEntity']) === 83 && $ld['mainEntity'][0]['@type'] === 'Question' && $ld['mainEntity'][0]['name'] === 'Welchen Bootsführerschein brauche ich?' && isset($ld['mainEntity'][0]['acceptedAnswer']['text']), 'JSON-LD FAQPage mit den ausgegebenen FAQs');
$small = do_shortcode('[bootspruefung_faq rubric="SRC" theme_only="1" title="Fragen zum SRC" search="0"]');
$check(str_contains($small, '>Fragen zum SRC</h2>') && ! str_contains($small, 'bp-faq-nav') && ! str_contains($small, 'bp-faq-search') && substr_count($small, '<summary>') === 4 && str_contains($small, '<h3 class="bp-faq-group-title">'), 'mit Attributen: Titel h2, keine Navigation/Suche, 4 ★-FAQs SRC');
$check(str_contains($small, '"@type":"FAQPage"') && substr_count($small, '"@type":"Question"') === 4, 'JSON-LD nur mit den ausgegebenen FAQs');
$check(do_shortcode('[bootspruefung_faq rubric="gibt-es-nicht"]') === '', 'ohne Treffer: keine Ausgabe');
$noLd = do_shortcode('[bootspruefung_faq rubric="SRC" theme_only="1" search="0" schema="0"]');
$check(substr_count($noLd, '<summary>') === 4 && ! str_contains($noLd, 'application/ld+json') && ! str_contains($noLd, 'FAQPage'), 'schema="0": FAQs ohne JSON-LD FAQPage');
$check(str_contains(do_shortcode('[bootspruefung_faq rubric="SRC" theme_only="1" search="0" schema="1"]'), '"@type":"FAQPage"'), 'schema="1" (Standard): mit JSON-LD');

echo "REST GET /bootspruefung/v1/faqs\n";
wp_set_current_user(0);
$res = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/faqs'));
$data = $res->get_data();
$check($res->get_status() === 200 && count($data) === 83, 'öffentlich lesbar, 83 FAQs');
$check(array_filter($data, static fn ($f) => get_post_status($f['id']) !== 'publish') === [] && array_keys($data[0]) === $fields, 'nur veröffentlichte FAQs, gleiche Felder');
$req = new WP_REST_Request('GET', '/bootspruefung/v1/faqs');
$req->set_query_params(['rubric' => 'SBF-SEE', 'theme_only' => 'true']);
$check($refs(rest_do_request($req)->get_data()) === [24, 30, 25, 26], 'rubric=SBF-SEE&theme_only=true');
$check(rest_do_request(new WP_REST_Request('POST', '/bootspruefung/v1/faqs'))->get_status() === 404, 'nur lesend');
$drafts = rest_do_request(new WP_REST_Request('GET', '/wp/v2/'.Faq::POST_TYPE))->get_data();
$check(is_array($drafts) && array_filter($drafts, static fn ($p) => ($p['status'] ?? 'publish') !== 'publish') === [], 'Editor-REST zeigt Gästen keine Entwürfe');
wp_set_current_user(1);

echo "Admin\n";
$type = get_post_type_object(Faq::POST_TYPE);
$check($type->cap->edit_posts === Capabilities::MANAGE_CONTENT && $type->cap->edit_post === Capabilities::MANAGE_CONTENT && ! $type->public && ! $type->publicly_queryable && $type->show_in_rest && ! $type->has_archive, 'Beitragstyp: nicht öffentlich, Block-Editor, Recht manage_bp_content');
$check(get_taxonomy(Faq::TAXONOMY)->hierarchical && get_taxonomy(Faq::TAXONOMY)->cap->assign_terms === Capabilities::MANAGE_CONTENT, 'Taxonomie hierarchisch, Recht manage_bp_content');
delete_option(Faq::PAGE_OPTION);
$existingPage = FaqAdmin::faqPage();
$page = FaqAdmin::createPage();
if (! $existingPage) {
    $createdPages[] = $page['id'];
    $check($page['created'] && get_post_field('post_title', $page['id']) === 'Häufige Fragen zum Bootsführerschein' && get_post_field('post_name', $page['id']) === 'faq-bootsfuehrerschein' && str_contains((string) get_post_field('post_content', $page['id']), '[bootspruefung_faq]') && get_post_status($page['id']) === 'publish', 'FAQ-Seite „Häufige Fragen zum Bootsführerschein“ (/faq-bootsfuehrerschein/) mit Shortcode angelegt');
}
$check(FaqAdmin::createPage() === ['id' => $page['id'], 'created' => false], 'zweiter Klick legt keine weitere Seite an');
// Vorhandene Seite mit dem Shortcode wird genutzt – eine Seite mit FAQ-Auszug (schema="0") aber nicht.
delete_option(Faq::PAGE_OPTION);
if (! $existingPage) {
    wp_delete_post($page['id'], true);
    $createdPages[] = $excerptId = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Ratgeber FAQ-Test', 'post_name' => 'ratgeber-faq-test',
        'post_content' => '<!-- wp:shortcode -->[bootspruefung_faq rubric="allgemein" schema="0" search="0"]<!-- /wp:shortcode -->']);
    $check(FaqAdmin::faqPage() === null, 'Seite mit FAQ-Auszug (schema="0") gilt nicht als FAQ-Seite');
    $createdPages[] = $ownId = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Eigene FAQ', 'post_name' => 'eigene-faq-test',
        'post_content' => '<!-- wp:shortcode -->[bootspruefung_faq]<!-- /wp:shortcode -->']);
    $again = FaqAdmin::createPage();
    $check($again === ['id' => $ownId, 'created' => false], 'vorhandene Seite mit dem Shortcode wird genutzt, keine neue angelegt');
}

echo "Theme Kurswasser\n";
if (! function_exists('kurswasser_course_faq') && is_file(get_theme_root().'/kurswasser/inc/courses.php')) {
    require_once get_theme_root().'/kurswasser/inc/courses.php';
}
if (function_exists('kurswasser_course_faq')) {
    $course = kurswasser_course_faq('sbf-see');
    preg_match_all('#<summary>(.*?)</summary>#', $course, $q);
    $check($q[1] === array_map(static fn ($f) => esc_html($f['question']), $see) && str_contains($course, 'Fragen zum SBF See') && preg_match('#<details class="wp-block-details"><summary>Was lerne ich im SBF-See-Kurs\?</summary><p[^>]*>Du übst#', $course) === 1, 'kurswasser_course_faq(\'sbf-see\'): ★-Fragen der Rubrik SBF-SEE aus dem Plugin, Titel wie bisher');
    $check(str_contains(kurswasser_course_faq('kapitaen'), 'Sind amtliche Prüfungen im Kapitäns-Bundle enthalten?') && str_contains(kurswasser_course_faq('funk-kombi'), 'Ist der Funkgerät-Simulator im Kombikurs enthalten?'), 'Kapitäns-Bundle (KAPITAEN) und Funk-Kombi (SRC-UBI)');
    $sknStars = array_map(static fn ($f) => $f['id'], bootspruefung_faqs(['rubric' => 'SKN', 'theme_only' => true]));
    foreach ($sknStars as $id) {
        update_post_meta($id, Faq::META_THEME, '0');
    }
    $check(str_contains(kurswasser_course_faq('skn'), 'Was trainieren die Praxiskarten?'), 'ohne ★-FAQ: Rückfall auf die festen Fragen');
    foreach ($sknStars as $id) {
        update_post_meta($id, Faq::META_THEME, '1');
    }
    $general = kurswasser_general_faqs();
    $check(count($general) === 7 && $general[0]['question'] === 'Wie lange habe ich Zugriff auf die Lernplattform?', 'Startseiten-FAQ: 7 ★-FAQs „allgemein“');
    $patternFile = get_theme_root().'/kurswasser/patterns/faq.php';
    ob_start();
    include $patternFile;
    $pattern = do_blocks((string) ob_get_clean());
    $check(substr_count($pattern, '<details class="wp-block-details') === 7 && preg_match('#<summary>Wie lange habe ich Zugriff auf die Lernplattform\?</summary><p[^>]*>Je nach Kurs#', $pattern) === 1 && ! str_contains($pattern, 'Wie lange habe ich Zugang?') && str_contains($pattern, 'id="faq"'), 'Muster kurswasser/faq: Plugin-FAQs statt festem Text, Anker #faq bleibt');
} else {
    echo "  –    Theme Kurswasser nicht ladbar – nur die Plugin-Seite geprüft\n";
}

// Aufräumen: Standardstand der FAQs, Optionen und Seiten wie vorher.
foreach ($createdPages as $id) {
    wp_delete_post((int) $id, true);
}
$termsPageBefore === false ? delete_option('woocommerce_terms_page_id') : update_option('woocommerce_terms_page_id', $termsPageBefore);
$pageOptionBefore === false ? delete_option(Faq::PAGE_OPTION) : update_option(Faq::PAGE_OPTION, $pageOptionBefore, false);
$reset();
FaqImporter::maybeImport();

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
