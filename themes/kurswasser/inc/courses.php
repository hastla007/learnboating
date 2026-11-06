<?php
/**
 * Kurs-Infoseiten (/kurse/<kurs>/) und Kurs-Kacheln der Startseite.
 *
 * Inhalte nur aus belegten Quellen: Kennzahlen aus dem Lern-Plugin (Fragen, Bögen, Aufgaben,
 * Videos), Prüfungsrahmen aus den Prüfungsanleitungen der Quelle (Umfang, Bestehensgrenze,
 * Dauer, Prüfungsteile), Preise aus WooCommerce. Keine Gebühren, Fristen oder Altersgrenzen.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Kursseiten: Kürzel => Definition. Reihenfolge = Reihenfolge der Kacheln. */
function kurswasser_courses(): array
{
    return [
        'sbf-see' => [
            'main' => 'BP-SBF-SEE', 'mark' => 'SEE', 'title' => 'SBF See', 'name' => 'Sportbootführerschein See', 'eyebrow' => 'Sportbootführerschein', 'code' => 'SBF-SEE', 'icon' => 'anchor', 'tone' => 'see',
            'teaser' => 'Für Küste und Seeschifffahrtsstraßen – mit Navigationsaufgaben in der Übungsseekarte.',
            'lead' => 'Bereite dich online auf die Theorie des SBF See vor: alle Prüfungsfragen im Smarttrainer, Navigationsaufgaben mit Musterlösungen, Praxiskarten für die Prüfungsfahrt und eine Prüfungssimulation mit Zeitlimit.',
            'offers' => ['BP-SBF-SEE', 'BP-SBF-SEE-BIN', 'BP-KAPITAEN'], 'featured' => 'BP-SBF-SEE-BIN', 'compare' => ['BP-SBF-SEE', 'BP-SBF-BIN'],
        ],
        'sbf-binnen' => [
            'main' => 'BP-SBF-BIN', 'mark' => 'BINNEN', 'title' => 'SBF Binnen', 'name' => 'Sportbootführerschein Binnen', 'eyebrow' => 'Sportbootführerschein', 'code' => 'SBF-BIN-MOTOR', 'icon' => 'anchor', 'tone' => 'binnen',
            'teaser' => 'Für Binnenschifffahrtsstraßen unter Motor – Schifffahrtszeichen, Lichter und Vorfahrt.',
            'lead' => 'Lerne online für den SBF Binnen unter Antriebsmaschine: alle Prüfungsfragen im Smarttrainer, Praxiskarten zu Zeichen, Lichtern und Manövern, ein Videokurs und die Prüfungssimulation mit Zeitlimit.',
            'offers' => ['BP-SBF-BIN', 'BP-SBF-SEE-BIN', 'BP-SBF-BIN-SEGEL'], 'featured' => 'BP-SBF-SEE-BIN', 'compare' => ['BP-SBF-SEE', 'BP-SBF-BIN'],
        ],
        'sbf-binnen-segel' => [
            'main' => 'BP-SBF-BIN-SEGEL', 'mark' => 'SEGEL', 'title' => 'SBF Binnen Segel', 'name' => 'Sportbootführerschein Binnen unter Segel', 'eyebrow' => 'Sportbootführerschein', 'code' => 'SBF-BIN-SEGEL', 'icon' => 'sail', 'tone' => 'segel',
            'teaser' => 'Binnen unter Segel – mit den zusätzlichen Segelfragen und Segelmanövern.',
            'lead' => 'Der Kurs für den SBF Binnen unter Segel: Binnen- und Segelfragen im Smarttrainer, Praxiskarten zu Manövern unter Segel, Wind und Trimm und die Prüfungssimulation.',
            'offers' => ['BP-SBF-BIN-SEGEL', 'BP-ADD-BIN-SEGEL'], 'featured' => 'BP-SBF-BIN-SEGEL', 'compare' => [],
        ],
        'src' => [
            'main' => 'BP-SRC', 'mark' => 'SRC', 'title' => 'SRC', 'name' => 'Short Range Certificate', 'eyebrow' => 'Funkzeugnis', 'code' => 'SRC', 'icon' => 'radio', 'tone' => 'funk',
            'teaser' => 'UKW-Seefunk mit DSC – Fragen, englische Funktexte und Gerätesimulation.',
            'lead' => 'Lerne für das SRC: alle Prüfungsfragen im Smarttrainer, englische Funktexte mit Auswertung, Vokabeln, Buchstabiertafel und eine Gerätesimulation für den praktischen Teil.',
            'offers' => ['BP-SRC', 'BP-SRC-UBI', 'BP-UBI'], 'featured' => 'BP-SRC-UBI', 'compare' => ['BP-SRC', 'BP-UBI'],
        ],
        'ubi' => [
            'main' => 'BP-UBI', 'mark' => 'UBI', 'title' => 'UBI', 'name' => 'UKW-Sprechfunkzeugnis Binnen', 'eyebrow' => 'Funkzeugnis', 'code' => 'UBI', 'icon' => 'radio', 'tone' => 'ubi',
            'teaser' => 'Binnenfunk – Fragen, Textaufnahme und Textabgabe, Praxis am Funkgerät.',
            'lead' => 'Lerne für das UBI: alle Prüfungsfragen im Smarttrainer, Funktexte für Textaufnahme und Textabgabe, Praxisszenarien und die Prüfungssimulation.',
            'offers' => ['BP-UBI', 'BP-SRC-UBI', 'BP-ADD-UBI-TO-SRC'], 'featured' => 'BP-SRC-UBI', 'compare' => ['BP-SRC', 'BP-UBI'],
        ],
        'skn' => [
            'main' => 'BP-SKN', 'mark' => 'SKN', 'title' => 'SKN', 'name' => 'Sachkundenachweis Seenotsignalmittel', 'eyebrow' => 'Seenotsignalmittel', 'code' => 'SKN', 'icon' => 'flare', 'tone' => 'signal',
            'teaser' => 'Sachkunde für Seenotsignalmittel – Fragen und Praxiskarten zur Handhabung.',
            'lead' => 'Bereite dich auf die Sachkundeprüfung für Seenotsignalmittel vor: alle Prüfungsfragen im Smarttrainer und Praxiskarten zu Erkennen, Handhabung und sicherem Auslösen.',
            'offers' => ['BP-SKN', 'BP-ADD-FKN-TO-SKN'], 'featured' => 'BP-SKN', 'compare' => [],
        ],
        'fkn' => [
            'main' => 'BP-FKN', 'mark' => 'FKN', 'title' => 'FKN', 'name' => 'Fachkundenachweis Seenotsignalmittel', 'eyebrow' => 'Seenotsignalmittel', 'code' => 'FKN', 'icon' => 'flare', 'tone' => 'signal',
            'teaser' => 'Fachkunde für Seenotsignalmittel – kompakt mit Fragen und Praxiskarten.',
            'lead' => 'Der kompakte Kurs für den Fachkundenachweis: alle Prüfungsfragen im Smarttrainer, Praxiskarten zum Erkennen und Handhaben von Signalmitteln und die Prüfungssimulation.',
            'offers' => ['BP-FKN', 'BP-SKN'], 'featured' => 'BP-FKN', 'compare' => [],
        ],
    ];
}

function kurswasser_course_url(string $slug): string
{
    $page = get_page_by_path('kurse/'.$slug);

    return $page ? (string) get_permalink($page) : home_url('/kurse/'.$slug.'/');
}

/** Niedrigster Preis der Angebote einer Kursseite. */
function kurswasser_course_from_price(array $def): ?float
{
    $prices = array_filter(array_map(static fn ($sku) => kurswasser_offer($sku)['price'] ?? null, $def['offers']), static fn ($p) => $p !== null);

    return $prices ? min($prices) : null;
}

/** @return array{questions:int,nav:int,praxis:int,videos:int,papers:int} */
function kurswasser_course_stats(string $code): array
{
    return class_exists(\Bootspruefung\Learning\Commerce\ShopCatalog::class)
        ? \Bootspruefung\Learning\Commerce\ShopCatalog::courseStats([$code])
        : ['questions' => 0, 'nav' => 0, 'praxis' => 0, 'videos' => 0, 'papers' => 0];
}

/** Kennzahlen als kurze Fakten (Symbol, Text). @return list<array{0:string,1:string}> */
function kurswasser_course_facts(array $def, int $max = 3): array
{
    $s = kurswasser_course_stats($def['code']);
    $facts = [];
    if ($s['questions']) {
        $facts[] = ['smart', sprintf(__('%d Prüfungsfragen', 'kurswasser'), $s['questions'])];
    }
    if ($s['nav']) {
        $facts[] = ['compass', sprintf(__('%d Navigationsaufgaben', 'kurswasser'), $s['nav'])];
    }
    if ($s['praxis']) {
        $facts[] = ['cards', sprintf(__('%d Praxiskarten', 'kurswasser'), $s['praxis'])];
    }
    if ($s['videos']) {
        $facts[] = ['play', sprintf(__('%d Video-Lektionen', 'kurswasser'), $s['videos'])];
    }
    if ($s['papers']) {
        $facts[] = ['exam', sprintf(__('%d Prüfungsbögen', 'kurswasser'), $s['papers'])];
    }

    return array_slice($facts, 0, $max);
}

/* ------------------------------------------------------------------ Kacheln (Startseite) */

add_shortcode('kurswasser_course_tiles', static function (): string {
    $html = '<div class="kw-tiles">';
    foreach (kurswasser_courses() as $slug => $def) {
        $main = kurswasser_offer($def['main']);
        $html .= '<article class="kw-tile kw-tone-'.esc_attr($def['tone']).'">';
        $html .= '<div class="kw-tile-visual" aria-hidden="true"><span class="kw-tile-badge"><span class="kw-icon kw-icon-'.esc_attr($def['icon']).'"></span></span><span class="kw-tile-mark">'.esc_html($def['mark']).'</span></div>';
        $html .= '<div class="kw-tile-body"><p class="kw-tile-eyebrow">'.esc_html($def['eyebrow']).'</p>';
        $html .= '<h3><a href="'.esc_url(kurswasser_course_url($slug)).'">'.esc_html($def['title']).'</a></h3>';
        $html .= '<p class="kw-tile-text">'.esc_html($def['teaser']).'</p><ul class="kw-tile-facts">';
        foreach (kurswasser_course_facts($def, 2) as [$icon, $text]) {
            $html .= '<li><span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span>'.esc_html($text).'</li>';
        }
        $html .= '</ul></div><footer class="kw-tile-foot">';
        $html .= $main !== null ? '<p class="kw-tile-price">'.esc_html(kurswasser_money($main['price'])).'</p>' : '<p></p>';
        $html .= '<span class="kw-tile-go">'.esc_html__('Kurs ansehen', 'kurswasser').'<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></span></footer></article>';
    }
    // Pakete & Pässe als eigene Kachel
    $bundle = kurswasser_offer('BP-KAPITAEN');
    $html .= '<article class="kw-tile kw-tile-bundle kw-tone-bundle"><div class="kw-tile-visual" aria-hidden="true"><span class="kw-tile-badge"><span class="kw-icon kw-icon-wheel"></span></span><span class="kw-tile-mark">'.esc_html__('PAKETE', 'kurswasser').'</span></div>';
    $html .= '<div class="kw-tile-body"><p class="kw-tile-eyebrow">'.esc_html__('Sparen', 'kurswasser').'</p><h3><a href="'.esc_url(kurswasser_course_url('pakete')).'">'.esc_html__('Pakete & Pässe', 'kurswasser').'</a></h3>';
    $html .= '<p class="kw-tile-text">'.esc_html__('Kapitäns-Bundle mit SBF See, Binnen und Funk – oder ein Pass für alle Inhalte.', 'kurswasser').'</p><ul class="kw-tile-facts"><li><span class="kw-icon kw-icon-wheel" aria-hidden="true"></span>'.esc_html__('Kapitäns-Bundle', 'kurswasser').'</li><li><span class="kw-icon kw-icon-clock" aria-hidden="true"></span>'.esc_html__('Pässe für 1 oder 3 Monate', 'kurswasser').'</li></ul></div>';
    $html .= '<footer class="kw-tile-foot">'.($bundle ? '<p class="kw-tile-price"><span>'.esc_html__('ab', 'kurswasser').'</span> '.esc_html(kurswasser_money(min(array_map(static fn ($s) => kurswasser_offer($s)['price'] ?? PHP_FLOAT_MAX, ['BP-KAPITAEN', 'BP-PASS-1M', 'BP-PASS-3M'])))).'</p>' : '<p></p>').'<span class="kw-tile-go">'.esc_html__('Ansehen', 'kurswasser').'<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></span></footer></article>';

    return $html.'</div>';
});

/* ------------------------------------------------------------------ Kurs-Infoseite */

add_shortcode('kurswasser_course', static function ($atts): string {
    $slug = sanitize_key((string) (shortcode_atts(['id' => ''], (array) $atts)['id']));
    if ($slug === 'pakete') {
        return kurswasser_packages_page();
    }
    $def = kurswasser_courses()[$slug] ?? null;
    if ($def === null) {
        return '';
    }
    $stats = kurswasser_course_stats($def['code']);
    $main = kurswasser_offer($def['main'] ?? '');
    $from = $main['price'] ?? kurswasser_course_from_price($def);
    $trial = home_url('/bootsscheine/'.(class_exists(\Bootspruefung\Learning\Content\Repositories\ContentRepository::class) ? \Bootspruefung\Learning\Content\Repositories\ContentRepository::slug($def['code']) : '').'/');

    // Hero
    $html = '<section class="kw-course-hero kw-tone-'.esc_attr($def['tone']).' alignfull"><div class="kw-course-hero-inner">';
    $html .= '<nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><a href="'.esc_url(home_url('/#kurse')).'">'.esc_html__('Kurse', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html($def['title']).'</span></nav>';
    $html .= '<div class="kw-course-hero-grid"><div><p class="kw-eyebrow">'.esc_html($def['name']).'</p>';
    $html .= '<h1>'.esc_html(sprintf(__('%s online lernen', 'kurswasser'), $def['title'])).'</h1><p class="kw-course-lead">'.esc_html($def['lead']).'</p>';
    $html .= '<div class="kw-course-cta"><a class="kw-btn kw-btn-signal" href="#preise">'.esc_html($from !== null ? sprintf(__('Ab %s – Angebote ansehen', 'kurswasser'), kurswasser_money($from)) : __('Angebote ansehen', 'kurswasser')).'</a><a class="kw-btn kw-btn-ghost" href="'.esc_url($trial).'">'.esc_html__('Kostenlos reinschnuppern', 'kurswasser').'</a></div></div>';
    $html .= '<ul class="kw-course-stats">';
    foreach ([['smart', $stats['questions'], __('Prüfungsfragen', 'kurswasser')], ['exam', $stats['papers'], __('Prüfungsbögen', 'kurswasser')], ['compass', $stats['nav'], __('Navigationsaufgaben', 'kurswasser')], ['cards', $stats['praxis'], __('Praxiskarten', 'kurswasser')], ['play', $stats['videos'], __('Video-Lektionen', 'kurswasser')]] as [$icon, $n, $label]) {
        if ($n > 0) {
            $html .= '<li><span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span><strong>'.esc_html((string) $n).'</strong><span>'.esc_html($label).'</span></li>';
        }
    }
    $html .= '</ul></div></div></section>';

    // Das lernst du
    $html .= '<section class="kw-section kw-course-learn"><div class="kw-section-head"><p class="kw-eyebrow">'.esc_html__('Im Kurs enthalten', 'kurswasser').'</p><h2>'.esc_html__('Alles, was du für die Prüfung brauchst.', 'kurswasser').'</h2></div><div class="kw-feature-grid">';
    $features = [['smart', __('Smarttrainer', 'kurswasser'), sprintf(__('Alle %d Prüfungsfragen mit Erklärungen. Zuerst neue, dann falsch beantwortete und unsichere Fragen – bis alles sitzt.', 'kurswasser'), $stats['questions'])],
        ['exam', __('Prüfungssimulation', 'kurswasser'), $stats['papers'] ? sprintf(__('Prüfung mit Zeitlimit und Auswertung – als Zufallsprüfung oder mit %d festen Bögen.', 'kurswasser'), $stats['papers']) : __('Prüfung mit Zeitlimit und Auswertung, inklusive Rückblick auf jede Frage.', 'kurswasser')]];
    if ($stats['nav']) {
        $features[] = ['compass', __('Navigationsaufgaben', 'kurswasser'), sprintf(__('%d Kartenaufgaben mit Musterlösungen und Selbsteinschätzung.', 'kurswasser'), $stats['nav'])];
    }
    if ($stats['praxis']) {
        $features[] = ['cards', __('Praxiskarten', 'kurswasser'), sprintf(__('%d Karten zum Einprägen für den praktischen Teil.', 'kurswasser'), $stats['praxis'])];
    }
    if ($stats['videos']) {
        $features[] = ['play', __('Videokurs', 'kurswasser'), sprintf(__('%d kurze Lektionen mit Schritt-für-Schritt-Ansichten und Fragen.', 'kurswasser'), $stats['videos'])];
    }
    if (in_array($def['code'], ['SRC', 'UBI'], true)) {
        $features[] = ['radio', __('Funktraining', 'kurswasser'), __('Funktexte, Vokabeln, Buchstabiertafel, Praxisszenarien und Gerätesimulation.', 'kurswasser')];
    }
    $features[] = ['phone', __('Überall lernen', 'kurswasser'), __('Im Browser auf Rechner, Tablet und Smartphone – dein Fortschritt bleibt gespeichert.', 'kurswasser')];
    foreach ($features as [$icon, $title, $text]) {
        $html .= '<div class="kw-feature"><span class="kw-feature-icon"><span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span></span><h3>'.esc_html($title).'</h3><p>'.esc_html($text).'</p></div>';
    }
    $html .= '</div></section>';

    // Prüfung
    $html .= kurswasser_course_exam($def);

    // Angebote
    $html .= '<section id="preise" class="kw-section kw-course-offers"><div class="kw-center-head"><p class="kw-eyebrow">'.esc_html__('Preise', 'kurswasser').'</p><h2>'.esc_html(sprintf(__('Starte mit %s', 'kurswasser'), $def['title'])).'</h2><p class="kw-lead">'.esc_html__('Einmal zahlen, sofort lernen. Mit der Kombi sparst du gegenüber dem Einzelkauf.', 'kurswasser').'</p></div>';
    $html .= kurswasser_offer_cards($def['offers'], $def['featured'], $def['compare'], $def['compare'] !== [] ? __('Unsere Empfehlung', 'kurswasser') : __('Dein Kurs', 'kurswasser'));
    $html .= kurswasser_offer_trust().'</section>';

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/guarantee"} /--><!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
});

/** Prüfungsrahmen aus der Anleitung der Quelle (erste Prüfungsform des Kurses). */
function kurswasser_course_exam(array $def): string
{
    if (! class_exists(\Bootspruefung\Learning\ExamGuide\ExamGuideService::class)) {
        return '';
    }
    $tabs = (new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->payload($def['code'])['tabs'] ?? [];
    $tab = $tabs[0] ?? null;
    $variant = $tab['variants'][0] ?? null;
    if ($tab === null || $variant === null) {
        return '';
    }
    $html = '<section class="kw-section kw-course-exam"><div class="kw-exam-grid"><div><p class="kw-eyebrow">'.esc_html__('Die Prüfung', 'kurswasser').'</p><h2>'.esc_html__('So läuft die Prüfung ab.', 'kurswasser').'</h2>';
    $html .= '<p class="kw-lead">'.esc_html__('Die amtliche Prüfung legst du bei einem Prüfungsausschuss ab. Wir bereiten dich auf jeden Teil vor.', 'kurswasser').'</p>';
    $html .= '<dl class="kw-exam-facts"><div><dt>'.esc_html__('Theorie', 'kurswasser').'</dt><dd>'.esc_html((string) $variant['scope']).'</dd></div>';
    if (! empty($variant['pass_text'])) {
        $html .= '<div><dt>'.esc_html__('Bestanden', 'kurswasser').'</dt><dd>'.esc_html((string) $variant['pass_text']).'</dd></div>';
    }
    if (! empty($variant['minutes'])) {
        $html .= '<div><dt>'.esc_html__('Zeit', 'kurswasser').'</dt><dd>'.esc_html(sprintf(__('%d Minuten', 'kurswasser'), (int) $variant['minutes'])).'</dd></div>';
    }
    $html .= '</dl><p class="kw-exam-note">'.esc_html__('Angaben für die Vollprüfung laut amtlichem Prüfungsrahmen; maßgeblich ist dein Prüfungsausschuss.', 'kurswasser').'</p></div><ol class="kw-exam-parts">';
    foreach ($tab['exam_parts'] as $part) {
        $html .= '<li><h3>'.esc_html((string) $part['title']).'</h3><p>'.esc_html((string) $part['text']).'</p></li>';
    }

    return $html.'</ol></div></section>';
}

/** Seite „Pakete & Pässe“: Reiter-Element mit allen Angeboten. */
function kurswasser_packages_page(): string
{
    $html = '<section class="kw-course-hero kw-tone-bundle alignfull"><div class="kw-course-hero-inner"><nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><a href="'.esc_url(home_url('/#kurse')).'">'.esc_html__('Kurse', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html__('Pakete & Pässe', 'kurswasser').'</span></nav>';
    $html .= '<div class="kw-course-hero-grid is-single"><div><p class="kw-eyebrow">'.esc_html__('Sparen mit Kombis und Paketen', 'kurswasser').'</p><h1>'.esc_html__('Pakete & Pässe', 'kurswasser').'</h1><p class="kw-course-lead">'.esc_html__('Mehrere Scheine auf einmal? Mit Kombi, Kapitäns-Bundle oder einem Pass für alle Inhalte lernst du alles an einem Ort – und sparst gegenüber dem Einzelkauf.', 'kurswasser').'</p></div></div></div></section>';
    $html .= '<section id="preise" class="kw-section">'.do_shortcode('[kurswasser_offers]').'</section>';

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/groups"} /--><!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
}

/** Kursseiten anlegen (Elternseite „Kurse“ + je Kurs), wiederholbar. */
function kurswasser_create_course_pages(): void
{
    $parent = get_page_by_path('kurse');
    $parentId = $parent ? (int) $parent->ID : (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'kurse', 'post_title' => __('Kurse', 'kurswasser'),
        'post_content' => '<!-- wp:pattern {"slug":"kurswasser/courses"} /-->']);
    if ($parentId && ! $parent) {
        update_post_meta($parentId, '_wp_page_template', 'landing');
    }
    $pages = array_map(static fn ($d) => $d['title'], kurswasser_courses()) + ['pakete' => __('Pakete & Pässe', 'kurswasser')];
    foreach ($pages as $slug => $title) {
        if (get_page_by_path('kurse/'.$slug)) {
            continue;
        }
        $name = $slug === 'pakete' ? $title : (kurswasser_courses()[$slug]['name'] ?? $title);
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parentId, 'post_name' => $slug, 'post_title' => $name,
            'post_content' => '<!-- wp:shortcode -->[kurswasser_course id="'.$slug.'"]<!-- /wp:shortcode -->']);
        if ($id && ! is_wp_error($id)) {
            update_post_meta((int) $id, '_wp_page_template', 'course');
        }
    }
}
add_action('after_switch_theme', 'kurswasser_create_course_pages');
