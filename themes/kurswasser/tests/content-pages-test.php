<?php

declare(strict_types=1);

/*
 * Integrationstest Inhaltsseiten und SEO (Theme Kurswasser): Konverter-Ausgabe aus tools/fixtures anlegen,
 * Status/Vorlage/Eltern/Meta, {{brand}}, #kw:-Links trotz kses, kein Überschreiben geänderter Seiten,
 * Kontaktseite übersprungen, Kurs-Hero, SEO-Ausgabe (Title, Description, Robots, Canonical, Sitemap,
 * BreadcrumbList, Article) und Abschalten bei einem SEO-Plugin (simuliert). Räumt danach auf.
 *
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/content-pages-test.php
 *
 * Braucht python3 (Konverter). Läuft mit aktivem Kurswasser oder lädt das Theme zusätzlich.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 3).'/bootspruefung-learning/tests/integration/support/guard.php';

$theme = dirname(__DIR__);
if (! function_exists('kurswasser_content_pages_sync')) {
    require_once $theme.'/functions.php'; // Theme nicht aktiv: Funktionen, Shortcodes und Filter trotzdem laden.
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};

// Konverter: Fixtures in einen temporären Ordner bauen.
$tmp = sys_get_temp_dir().'/kw-content-test-'.getmypid();
$cmd = 'python3 '.escapeshellarg($theme.'/tools/content_build.py').' '.escapeshellarg($theme.'/tools/fixtures').' --out '.escapeshellarg($tmp).' 2>&1';
exec($cmd, $out, $code);
echo "Konverter\n";
$check($code === 0 && is_file($tmp.'/pages.php') && is_file($tmp.'/pages/01-beispiel-ratgeber.html'), 'Fixtures konvertiert ('.$code.')');
if ($code !== 0) {
    echo implode("\n", $out)."\n";
    exit(1);
}
$manifest = kurswasser_content_manifest($tmp);
$check(array_column($manifest, 'path') === ['beispiel-ratgeber', 'beispiel-angebot', 'beispiel-ueber-uns', 'beispiel-ueber-uns/beispiel-team', 'beispiel-faq', 'kontakt'], 'Manifest: 6 Seiten, Eltern vor Kindern');

$paths = ['beispiel-ueber-uns/beispiel-team', 'beispiel-ratgeber', 'beispiel-angebot', 'beispiel-ueber-uns', 'beispiel-faq'];
$cleanup = static function () use ($paths): void {
    foreach ($paths as $path) {
        while (($p = get_page_by_path($path, OBJECT, 'page')) instanceof WP_Post) {
            wp_delete_post($p->ID, true);
        }
    }
};
$cleanup();
$kontaktBefore = get_page_by_path('kontakt', OBJECT, 'page');
$kontaktContent = $kontaktBefore ? $kontaktBefore->post_content : null;
$blogname = get_option('blogname');
update_option('blogname', 'Testmarke');

echo "Anlegen (ohne Benutzer, kses aktiv)\n";
wp_set_current_user(0);
$check(has_filter('content_save_pre', 'wp_filter_post_kses') !== false, 'ohne unfiltered_html ist kses aktiv');
$dry = kurswasser_content_pages_sync(['dry_run' => true, 'dir' => $tmp]);
$check(array_column($dry, 'state') === ['new', 'new', 'new', 'new', 'new', 'legal'], 'Probelauf: 5 neu, Kontakt übersprungen ('.implode(', ', array_column($dry, 'state')).')');
$check(get_page_by_path('beispiel-ratgeber') === null, 'Probelauf legt nichts an');
$res = kurswasser_content_pages_sync(['dir' => $tmp]);
$check(array_column($res, 'state') === ['created', 'created', 'created', 'created', 'created', 'legal'], 'angelegt: '.implode(', ', array_column($res, 'state')));
$check(has_filter('content_save_pre', 'wp_filter_post_kses') !== false, 'kses danach wieder aktiv');
$p1 = get_page_by_path('beispiel-ratgeber');
$p2 = get_page_by_path('beispiel-angebot');
$p3 = get_page_by_path('beispiel-ueber-uns');
$p4 = get_page_by_path('beispiel-ueber-uns/beispiel-team');
$p5 = get_page_by_path('beispiel-faq');
$check($p1 && $p2 && $p3 && $p4 && $p5, 'alle Seiten per Pfad auffindbar');
$check($p1->post_status === 'publish' && get_post_meta($p1->ID, '_wp_page_template', true) === 'course' && $p1->post_title === 'Beispiel-Ratgeber: Mindestalter für den Bootsführerschein', 'Typ A: veröffentlicht, Vorlage course');
$check($p2->post_status === 'draft', 'Seite mit [[Betreiber: …]] trotz status publish als Entwurf');
$check($p3->post_status === 'publish' && get_post_meta($p3->ID, '_wp_page_template', true) === '', 'Typ B: Standardvorlage page');
$check($p4->post_parent === $p3->ID && $p4->post_status === 'draft', 'Unterseite hängt an der Elternseite, Entwurf');
$check(get_post_meta($p2->ID, '_kw_noindex', true) === '1' && get_post_meta($p1->ID, '_kw_noindex', true) === '' && get_post_meta($p2->ID, '_kw_canonical', true) === 'beispiel-ratgeber', 'noindex und Canonical als Post-Meta');
$check(get_post_meta($p1->ID, '_kw_seo_title', true) === 'Mindestalter Bootsführerschein (Beispiel) | {{brand}}' && get_post_meta($p1->ID, '_kw_reviewed_date', true) === '2026-11-10' && get_post_meta($p1->ID, '_kw_schema', true) === 'Article', 'SEO-Titel (mit Token), Stand aus „Stand:“, Schema');
$check(str_contains($p1->post_content, 'Beim Testmarke-Kurs') && ! str_contains($p1->post_content, '{{brand}}'), '{{brand}} → Website-Name beim Anlegen');
$check(str_contains($p2->post_content, 'href="#kw:gruppen"') && str_contains($p2->post_content, '[kurswasser_page_hero') && str_contains($p2->post_content, 'primary="Gruppenpakete ansehen|#kw:gruppen"'), '#kw:-Links bleiben beim Anlegen ohne Benutzer erhalten');
$check(str_contains($p1->post_content, 'schema="0"') && str_contains($p5->post_content, '[bootspruefung_faq]'), 'FAQ-Auszug mit schema="0", FAQ-Seite ohne');
$check(get_post_meta($p4->ID, '_kw_content_open_items', true) === ['Namen und Rollen des Teams', 'autorisierte Fotos'], 'offene Punkte als Meta');
$k = get_page_by_path('kontakt', OBJECT, 'page');
$check(($k ? $k->post_content : null) === $kontaktContent, 'Kontaktseite (Rechtstext) unverändert');

echo "#kw:-Links beim Speichern (kses)\n";
$sample = '<p><a href="#kw:shop">Shop</a> <a href="javascript:alert(1)">x</a></p>';
$core = wp_kses($sample, 'post', array_diff(wp_allowed_protocols(), ['#kw']));
$check(str_contains($core, 'href="shop"'), 'ohne Theme-Filter macht kses aus #kw:shop „shop“ (Befund)');
if (in_array('#kw', wp_allowed_protocols(), true)) {
    $plain = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'kses-Test', 'post_content' => $sample]);
    $saved = (string) get_post_field('post_content', $plain);
    wp_delete_post($plain, true);
} else {
    // Theme erst nach wp_loaded geladen (nicht aktiv): Protokoll-Liste ist schon zwischengespeichert – Filter direkt prüfen.
    $saved = wp_kses($sample, 'post', apply_filters('kses_allowed_protocols', wp_allowed_protocols()));
}
$check(str_contains($saved, 'href="#kw:shop"') && ! str_contains($saved, 'javascript:'), 'ohne unfiltered_html: #kw: bleibt, javascript: wird entfernt');
wp_set_current_user(1);
$admin = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'kses-Test Admin', 'post_content' => '<!-- wp:paragraph --><p><a href="#kw:agb#geld-zurueck-garantie">AGB</a></p><!-- /wp:paragraph -->']);
$check(str_contains((string) get_post_field('post_content', $admin), 'href="#kw:agb#geld-zurueck-garantie"'), 'als Admin: #kw:-Link bleibt');
$check(str_contains(apply_filters('the_content', (string) get_post_field('post_content', $admin)), 'href="'.esc_url(kurswasser_url('agb')).'#geld-zurueck-garantie"'), 'beim Ausgeben aufgelöst');
wp_delete_post($admin, true);

echo "Nicht ungefragt überschreiben\n";
$again = kurswasser_content_pages_sync(['dir' => $tmp]);
$check(array_column($again, 'state') === ['unchanged', 'unchanged', 'unchanged', 'unchanged', 'unchanged', 'legal'], 'zweiter Lauf: alles aktuell');
// Betreiber ändert Seite 3 (Inhalt) und die SEO-Beschreibung von Seite 1; neue Vorlagenstände für 1 und 3.
wp_update_post(['ID' => $p3->ID, 'post_content' => $p3->post_content."\n<!-- wp:paragraph --><p>Ergänzung des Betreibers.</p><!-- /wp:paragraph -->"]);
update_post_meta($p1->ID, '_kw_seo_description', 'Eigene Beschreibung des Betreibers.');
foreach (['01-beispiel-ratgeber.html', '03-beispiel-ueber-uns.html'] as $file) {
    file_put_contents($tmp.'/pages/'.$file, str_replace('</p>', ' Neuer Vorlagenstand.</p>', (string) file_get_contents($tmp.'/pages/'.$file)));
}
$dry = kurswasser_content_pages_sync(['dry_run' => true, 'dir' => $tmp]);
$check($dry['beispiel-ratgeber']['state'] === 'outdated' && $dry['beispiel-ueber-uns']['state'] === 'edited' && $dry['beispiel-faq']['state'] === 'unchanged', 'Probelauf meldet: 1 neuer Stand, 3 von dir bearbeitet');
$res = kurswasser_content_pages_sync(['dir' => $tmp]);
$check($res['beispiel-ratgeber']['state'] === 'updated' && $res['beispiel-ueber-uns']['state'] === 'edited', 'Abgleich: 1 aktualisiert, 3 bleibt ('.implode(', ', array_column($res, 'state')).')');
$check(str_contains((string) get_post_field('post_content', $p1->ID), 'Neuer Vorlagenstand.') && str_contains((string) get_post_field('post_content', $p3->ID), 'Ergänzung des Betreibers.') && ! str_contains((string) get_post_field('post_content', $p3->ID), 'Neuer Vorlagenstand.'), 'Inhalte: neue Vorlage bzw. Betreibertext');
$check(get_post_meta($p1->ID, '_kw_seo_description', true) === 'Eigene Beschreibung des Betreibers.', 'vom Betreiber geändertes SEO-Feld bleibt beim Update');
$check(get_post_status($p1->ID) === 'publish', 'Status bleibt veröffentlicht');
wp_update_post(['ID' => $p4->ID, 'post_status' => 'publish']);
$check(kurswasser_content_pages_sync(['dry_run' => true, 'dir' => $tmp])['beispiel-ueber-uns/beispiel-team']['state'] === 'unchanged', 'Status ändern allein gilt nicht als Inhaltsänderung');
wp_update_post(['ID' => $p4->ID, 'post_status' => 'draft']);
$forced = kurswasser_content_pages_sync(['dir' => $tmp, 'force' => true]);
$check($forced['beispiel-ueber-uns']['state'] === 'updated' && ! str_contains((string) get_post_field('post_content', $p3->ID), 'Ergänzung des Betreibers.'), '--force überschreibt bearbeitete Seiten');

echo "Offene Punkte und Admin-Hinweis\n";
$open = kurswasser_content_open_items($tmp);
$byNr = array_column($open, null, 'nr');
$check(isset($byNr[2], $byNr[4], $byNr[6]) && in_array('enthält [[Betreiber: …]]-Platzhalter', $byNr[2]['items'], true) && in_array('enthält einen „Offen“-Kasten', $byNr[4]['items'], true) && str_contains(implode(' ', $byNr[6]['items']), 'legal/kontakt.html'), 'offene Punkte: Platzhalter, Offen-Kasten, Kontakt-Hinweis');
if (is_file(ABSPATH.'wp-admin/includes/admin.php')) {
    require_once ABSPATH.'wp-admin/includes/admin.php';
    set_current_screen('dashboard');
    $dirFilter = static fn (): string => $tmp;
    add_filter('kurswasser_content_dir', $dirFilter);
    ob_start();
    do_action('admin_notices');
    $notice = (string) ob_get_clean();
    $check(str_contains($notice, 'Inhaltsseiten aus dem Theme') && ! str_contains($notice, 'Inhaltsseiten anlegen bzw. aktualisieren') && str_contains($notice, 'Offene Punkte je Seite') && str_contains($notice, 'Namen und Rollen des Teams'), 'Hinweis ohne Knopf (alles aktuell), mit offenen Punkten');
    file_put_contents($tmp.'/pages/05-beispiel-faq.html', str_replace('</p>', ' Neu.</p>', (string) file_get_contents($tmp.'/pages/05-beispiel-faq.html')));
    ob_start();
    do_action('admin_notices');
    $notice = (string) ob_get_clean();
    $check(str_contains($notice, 'Inhaltsseiten anlegen bzw. aktualisieren') && str_contains($notice, 'neuer Vorlagenstand, Seite unverändert') && str_contains($notice, 'kurswasser_content_sync'), 'neuer Vorlagenstand: Hinweis mit Knopf');
    remove_filter('kurswasser_content_dir', $dirFilter);
    $GLOBALS['current_screen'] = null;
}

echo "Kurs-Hero und Ausgabe\n";
$view = static function (WP_Post $post): void {
    $GLOBALS['wp_query'] = new WP_Query(['page_id' => $post->ID, 'post_status' => 'any']);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = $post;
    setup_postdata($post);
};
$p1 = get_post($p1->ID);
$view($p1);
$html = apply_filters('the_content', $p1->post_content);
$check(str_contains($html, '<section class="kw-course-hero alignfull kw-page-hero"') && str_contains($html, '<h1>Beispiel-Ratgeber: Mindestalter für den Bootsführerschein</h1>') && str_contains($html, '<p class="kw-eyebrow">Voraussetzungen</p>') && str_contains($html, 'class="kw-course-lead"'), 'Hero: Eyebrow, H1 = Seitentitel, Lead');
$check(substr_count($html, '<h1') === 1, 'genau eine H1');
$check(str_contains($html, '<a class="kw-btn kw-btn-signal" href="'.esc_url(home_url('/kurse/')).'">SBF-Kurse ansehen</a>') && str_contains($html, '<a class="kw-btn kw-btn-ghost" href="#quellen">Offizielle Quellen</a>'), 'Hero: zwei CTAs (relativ → home_url, Anker)');
$check(preg_match('#<nav class="kw-crumbs" aria-label="Brotkrumen"><a href="[^"]+">Start</a><span aria-hidden="true">/</span><a href="'.preg_quote(esc_url(home_url('/beispiel-ratgeber/')), '#').'">Ratgeber</a><span aria-hidden="true">/</span><span aria-current="page">Mindestalter</span></nav>#', $html) === 1, 'Hero: Brotkrumen aus dem Manifest, letztes Glied aria-current');
$check(str_contains($html, '<ul class="kw-course-stats">') && str_contains($html, '<strong>16 Jahre</strong>') && str_contains($html, 'class="kw-overview-nav"') && ! str_contains($html, 'is-single'), 'Hero: Kennzahlen und Sprungnavigation');
$check(! str_contains($html, 'FAQPage'), 'FAQ-Auszug ohne FAQPage-JSON-LD');
$p2 = get_post($p2->ID);
$view($p2);
$html2 = apply_filters('the_content', $p2->post_content);
$check(str_contains($html2, 'href="'.esc_url(kurswasser_url('gruppen')).'"') && ! str_contains($html2, '#kw:'), 'Typ A: #kw:-Ziele im Hero und im CTA-Band aufgelöst');
$check(str_contains($html2, '--kw-course-photo') || get_template() !== 'kurswasser', 'Hero-Foto (B1-A+Foto), wenn Kurswasser aktiv');
$check(str_contains($html2, 'class="kw-steps alignwide"') && preg_match('#<section id="ablauf" class="wp-block-group alignfull kw-section kw-dark\b#', $html2) === 1, '[B4 dunkel]: Schritte-Band');

echo "SEO-Ausgabe\n";
$view($p1);
$check(wp_get_document_title() === 'Mindestalter Bootsführerschein (Beispiel) | Testmarke', '<title> aus dem SEO-Titel, {{brand}} ersetzt');
$head = kurswasser_seo_head_html();
$check(str_contains($head, '<meta name="description" content="Beispielseite für den Konverter: Ab 16 unter Motor, ab 14 unter Segel – mit Quellen und Ablauf. Bei Testmarke online lernen.">'), 'Meta-Description ({{brand}} ersetzt; nach --force wieder aus der Vorlage)');
preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $head, $ld);
$ld = array_map(static fn ($j) => json_decode($j, true), $ld[1]);
$crumbs = array_values(array_filter($ld, static fn ($d) => ($d['@type'] ?? '') === 'BreadcrumbList'))[0] ?? null;
$check($crumbs && array_column($crumbs['itemListElement'], 'name') === ['Start', 'Ratgeber', 'Mindestalter'] && $crumbs['itemListElement'][2]['item'] === get_permalink($p1) && $crumbs['itemListElement'][0]['position'] === 1, 'BreadcrumbList: Start / Ratgeber / Mindestalter mit URLs');
$article = array_values(array_filter($ld, static fn ($d) => ($d['@type'] ?? '') === 'Article'))[0] ?? null;
$check($article && $article['dateModified'] === '2026-11-10' && $article['author']['@type'] === 'Organization' && $article['headline'] === 'Beispiel-Ratgeber: Mindestalter für den Bootsführerschein', 'Article (schema=Article): Stand als dateModified, kein erfundener Autor');
$check(! str_contains($head, '"Organization","@id"') || ! str_contains($head, '"WebSite"'), 'keine Organization/WebSite auf Unterseiten');
ob_start();
wp_robots();
$check(! str_contains((string) ob_get_clean(), 'noindex') || get_option('blog_public') === '0', 'indexierbare Seite ohne noindex');
$view($p2);
ob_start();
wp_robots();
$robots = (string) ob_get_clean();
$check(str_contains($robots, 'noindex') && (str_contains($robots, 'follow') || get_option('blog_public') === '0'), 'noindex-Seite: noindex, follow ('.trim(strip_tags($robots)).')');
$check(wp_get_document_title() === 'Gruppenpakete (Beispiel) | Testmarke', 'Titel Seite 2');
$view($p3);
$crumbs3 = array_column(kurswasser_breadcrumbs($p3), 'label');
$view($p4);
$crumbs4 = array_column(kurswasser_breadcrumbs(get_post($p4->ID)), 'label');
$check($crumbs3 === ['Start', 'Über uns'] && $crumbs4 === ['Start', 'Über uns', 'Team'], 'Brotkrumen ohne Angabe aus der Seitenhierarchie: '.implode(' / ', $crumbs4));
update_post_meta($p3->ID, '_kw_canonical', 'beispiel-ratgeber');
$view(get_post($p3->ID));
ob_start();
rel_canonical();
$canon = (string) ob_get_clean();
$check(str_contains($canon, 'href="'.get_permalink($p1).'"'), 'Canonical auf die Hauptseite');
update_post_meta($p3->ID, '_kw_canonical', 'beispiel-angebot');
ob_start();
rel_canonical();
$check(str_contains((string) ob_get_clean(), 'href="'.get_permalink($p3).'"'), 'Canonical auf einen Entwurf: Selbstreferenz statt nicht erreichbarer Seite');
delete_post_meta($p3->ID, '_kw_canonical');
ob_start();
rel_canonical();
$check(str_contains((string) ob_get_clean(), 'href="'.get_permalink($p3).'"'), 'ohne Feld: Selbstreferenz');
update_post_meta($p3->ID, '_kw_noindex', '1');
$args = apply_filters('wp_sitemaps_posts_query_args', ['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids'], 'page');
$ids = (new WP_Query($args))->posts;
$check(! in_array($p3->ID, $ids, true) && in_array($p1->ID, $ids, true), 'Sitemap ohne noindex-Seiten');
delete_post_meta($p3->ID, '_kw_noindex');
$front = (int) get_option('page_on_front');
$GLOBALS['wp_query'] = new WP_Query(['page_id' => $p5->ID]);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$showOnFront = get_option('show_on_front');
update_option('show_on_front', 'page');
update_option('page_on_front', $p5->ID);
$GLOBALS['wp_query'] = new WP_Query(['page_id' => $p5->ID]);
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$frontHead = kurswasser_seo_head_html();
$check(str_contains($frontHead, '"@type":"Organization"') && str_contains($frontHead, '"@type":"WebSite"') && ! str_contains($frontHead, 'BreadcrumbList'), 'Startseite: Organization + WebSite, keine Brotkrumen');
update_option('show_on_front', $showOnFront);
update_option('page_on_front', $front);

echo "SEO-Plugin aktiv (simuliert): keine eigene Ausgabe\n";
$view($p1);
add_filter('kurswasser_seo_plugin_active', '__return_true');
$check(wp_get_document_title() !== 'Mindestalter Bootsführerschein (Beispiel) | Testmarke', 'Titel: WordPress bzw. Plugin');
$check(kurswasser_seo_head_html() === '', 'keine Description, kein JSON-LD');
$view($p2);
ob_start();
wp_robots();
$check(! str_contains((string) ob_get_clean(), 'noindex') || get_option('blog_public') === '0', 'kein eigenes noindex');
$check(apply_filters('wpseo_metadesc', '') === 'Beispielseite mit Shortcodes: Gruppenpakete und Kurskacheln live aus dem Shop.', 'Yoast-Feld leer: eigene Description als Rückfall');
update_post_meta($p2->ID, '_yoast_wpseo_metadesc', 'Yoast-Text');
$check(apply_filters('wpseo_metadesc', 'Yoast-Text') === 'Yoast-Text', 'Yoast-Feld gesetzt: Yoast gewinnt');
delete_post_meta($p2->ID, '_yoast_wpseo_metadesc');
$check(apply_filters('wpseo_robots_array', ['index' => 'index', 'follow' => 'follow'])['index'] === 'noindex', 'Yoast-Robots: eigenes noindex weitergereicht');
remove_filter('kurswasser_seo_plugin_active', '__return_true');

echo "Links auf Entwürfe, #kw:page:, Hero-Zeile, Weiterleitung\n";
$dirFilter = static fn (): string => $tmp;
add_filter('kurswasser_content_dir', $dirFilter);
$unpub = kurswasser_content_unpublished_paths();
$check(isset($unpub['beispiel-angebot'], $unpub['beispiel-ueber-uns/beispiel-team']) && ! isset($unpub['beispiel-ratgeber']) && isset($unpub['kontakt']) === ! kurswasser_page_published('kontakt'), 'nicht veröffentlicht: Entwürfe des Manifests (Kontakt je nach Status der Rechtstext-Seite): '.implode(', ', array_keys($unpub)));
$sample = '<p>Siehe <a href="/beispiel-angebot/">Gruppen</a> und <a href="'.esc_url(home_url('/beispiel-ratgeber/#motor')).'">Ratgeber</a> sowie <a href="'.esc_url(home_url('/beispiel-ueber-uns/beispiel-team/')).'">Team</a>.</p>'
    .'<div class="kw-feature-grid"><div class="kw-feature"><span class="kw-feature-icon"><span class="kw-icon kw-icon-anchor"></span></span><h3><a href="/beispiel-angebot/">Karte Entwurf</a></h3><p>x</p></div><div class="kw-feature"><span class="kw-feature-icon"><span class="kw-icon kw-icon-anchor"></span></span><h3><a href="/beispiel-ratgeber/">Karte live</a></h3></div></div>'
    .'<div class="wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#kw:beispiel-angebot">Button Entwurf</a></div></div>';
$out = kurswasser_content_unlink_unpublished($sample);
$check(str_contains($out, 'Siehe Gruppen und') && str_contains($out, '">Ratgeber</a>') && str_contains($out, 'sowie Team.'), 'Links auf Entwürfe werden Text, Links auf veröffentlichte Seiten bleiben');
$check(! str_contains($out, 'Karte Entwurf') && str_contains($out, 'Karte live') && ! str_contains($out, 'Button Entwurf'), 'Karte und Button mit Ziel Entwurf entfallen');
$view($p1);
$link = '<p><a href="/beispiel-angebot/">Angebot</a></p>';
$check(str_contains(render_block(['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $link, 'innerContent' => [$link]]), 'href="/beispiel-angebot/"')
    && ! str_contains(kurswasser_content_unlink_unpublished(render_block(['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $link, 'innerContent' => [$link]])), 'href='), 'einzelner Block unverändert, Seiteninhalt ohne Link auf den Entwurf');
$check(! str_contains(do_blocks('<!-- wp:pattern {"slug":"kurswasser/guarantee"} /-->'), 'beispiel-angebot'), 'Muster laufen durch denselben Filter (core/pattern)');
$footer = do_blocks('<!-- wp:html --><ul class="kw-footer-links"><li><a href="#kw:page:beispiel-ratgeber">Live</a></li><li><a href="#kw:page:beispiel-angebot">Entwurf</a></li><li><a href="#kw:page:beispiel-angebot|gruppen">Mit Ersatz</a></li><li><a href="#kw:page:gibt-es-nicht|#faq">FAQ</a></li></ul><!-- /wp:html -->');
$check(str_contains($footer, '<a href="'.esc_url(get_permalink($p1)).'">Live</a>') && ! str_contains($footer, '>Entwurf<') && str_contains($footer, '<a href="'.esc_url(kurswasser_url('gruppen')).'">Mit Ersatz</a>') && str_contains($footer, '<a href="'.esc_url(home_url('/').'#faq').'">FAQ</a>') && ! str_contains($footer, '#kw:'), '#kw:page: nur veröffentlichte Seiten, sonst Ersatzziel oder Eintrag entfällt');
$check(do_blocks('<!-- wp:column --><div class="wp-block-column"><!-- wp:html --><ul class="kw-footer-links"><li><a href="#kw:page:beispiel-angebot">Entwurf</a></li></ul><!-- /wp:html --></div><!-- /wp:column -->') === '', 'Fußspalte ohne veröffentlichte Ziele entfällt');
$nav = do_blocks('<!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"NavLive","url":"#kw:page:beispiel-ratgeber","kind":"custom"} /--><!-- wp:navigation-link {"label":"NavEntwurf","url":"#kw:page:beispiel-angebot","kind":"custom"} /--><!-- /wp:navigation -->');
$check(str_contains($nav, 'NavLive') && str_contains($nav, esc_url(get_permalink($p1))) && ! str_contains($nav, 'NavEntwurf'), 'Navigation: Eintrag auf Entwurf nicht ausgegeben');
$hero = do_shortcode('[kurswasser_page_hero lead="L" primary="Zum Entwurf|/beispiel-angebot/" secondary="Live|/beispiel-ratgeber/" note="Mit kostenlosem Kundenkonto."]');
$check(! str_contains($hero, 'Zum Entwurf') && str_contains($hero, '>Live</a>') && str_contains($hero, 'kw-page-hero-note" style="margin-top:.9rem;font-size:.85rem">Mit kostenlosem Kundenkonto.</p>'), 'Hero: Button auf Entwurf entfällt, Zeile unter den Buttons');
$check(kurswasser_content_has_placeholders('[kurswasser_page_hero note="&#91;&#91;Betreiber: Anschrift&#93;&#93;"]'), 'Platzhalter auch maskiert im Shortcode-Attribut erkannt');
$check(str_contains(kurswasser_page_link('beispiel-ratgeber', 'R'), 'href=') && kurswasser_page_link('beispiel-angebot', 'A') === 'A', 'kurswasser_page_link: Link nur auf veröffentlichte Seiten');
remove_filter('kurswasser_content_dir', $dirFilter);
$check(! str_contains(kurswasser_offer_trust(), 'geld-zurueck-garantie-bootsfuehrerschein') || kurswasser_page_published('geld-zurueck-garantie-bootsfuehrerschein'), 'Vertrauenszeile: Garantieseite nur, wenn veröffentlicht, sonst AGB-Abschnitt');
$oldFaq = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Häufige Fragen', 'post_name' => 'haeufige-fragen', 'post_content' => '<!-- wp:shortcode -->[bootspruefung_faq]<!-- /wp:shortcode -->']);
$newFaq = get_page_by_path('faq-bootsfuehrerschein', OBJECT, 'page');
$tmpFaq = $newFaq ? 0 : (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FAQ', 'post_name' => 'faq-bootsfuehrerschein', 'post_content' => '[bootspruefung_faq]']);
$newFaq = get_page_by_path('faq-bootsfuehrerschein', OBJECT, 'page');
$_SERVER['REQUEST_URI'] = (string) wp_parse_url(home_url('/haeufige-fragen/'), PHP_URL_PATH);
$target = $newFaq && $newFaq->post_status === 'publish' ? kurswasser_faq_redirect_target() : 'übersprungen';
$check($target === 'übersprungen' || $target === get_permalink($newFaq), '/haeufige-fragen/ mit Shortcode → 301 auf /faq-bootsfuehrerschein/');
$_SERVER['REQUEST_URI'] = '/andere-seite/';
$check(kurswasser_faq_redirect_target() === null, 'andere Pfade: keine Weiterleitung');
$_SERVER['REQUEST_URI'] = '/';
wp_delete_post($oldFaq, true);
if ($tmpFaq) {
    wp_delete_post($tmpFaq, true);
}

echo "Kursseiten\n";
$kurse = get_page_by_path('kurse');
$check(kurswasser_courses_overview_url() === ($kurse && $kurse->post_status === 'publish' ? get_permalink($kurse) : home_url('/#kurse')), 'Brotkrumen „Kurse“: '.kurswasser_courses_overview_url());

// Aufräumen
wp_reset_postdata();
$cleanup();
update_option('blogname', $blogname);
array_map('unlink', glob($tmp.'/pages/*') ?: []);
@rmdir($tmp.'/pages');
@unlink($tmp.'/pages.php');
@rmdir($tmp);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
