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
            'teaser' => 'Für Küste und Seeschifffahrtsstraßen – mit Navigationsaufgaben zur Übungskarte D49 (gedruckte Karte nötig).',
            'lead' => 'Bereite dich online auf die Theorie des SBF See vor: alle Prüfungsfragen im Smarttrainer, Navigationsaufgaben mit Musterlösungen, Praxiskarten für die Prüfungsfahrt und eine Prüfungssimulation mit Zeitlimit.',
            'offers' => ['BP-SBF-SEE', 'BP-SBF-SEE-BIN', 'BP-KAPITAEN'], 'featured' => 'BP-SBF-SEE-BIN', 'compare' => ['BP-SBF-SEE-KOMPLETT', 'BP-SBF-BIN-KOMPLETT'],
        ],
        'sbf-binnen' => [
            'main' => 'BP-SBF-BIN', 'mark' => 'BINNEN', 'title' => 'SBF Binnen', 'name' => 'Sportbootführerschein Binnen', 'eyebrow' => 'Sportbootführerschein', 'code' => 'SBF-BIN-MOTOR', 'icon' => 'anchor', 'tone' => 'binnen',
            'teaser' => 'Für Binnenschifffahrtsstraßen unter Motor – Schifffahrtszeichen, Lichter und Vorfahrt.',
            'lead' => 'Lerne online für den SBF Binnen unter Antriebsmaschine: alle Prüfungsfragen im Smarttrainer, Praxiskarten zu Zeichen, Lichtern und Manövern und die Prüfungssimulation mit Zeitlimit – auf Wunsch mit Videokurs.',
            'offers' => ['BP-SBF-BIN', 'BP-SBF-SEE-BIN', 'BP-SBF-BIN-SEGEL'], 'featured' => 'BP-SBF-SEE-BIN', 'compare' => ['BP-SBF-SEE-KOMPLETT', 'BP-SBF-BIN-KOMPLETT'],
        ],
        'sbf-binnen-segel' => [
            'main' => 'BP-SBF-BIN-SEGEL', 'mark' => 'SEGEL', 'title' => 'SBF Binnen Segel', 'name' => 'Sportbootführerschein Binnen unter Segel', 'eyebrow' => 'Sportbootführerschein', 'code' => 'SBF-BIN-SEGEL', 'icon' => 'sail', 'tone' => 'segel',
            'teaser' => 'Binnen unter Segel – mit den zusätzlichen Segelfragen und Segelmanövern.',
            'lead' => 'Der Kurs für den SBF Binnen unter Segel: Binnen- und Segelfragen im Smarttrainer, Praxiskarten zu Manövern unter Segel, Wind und Trimm und die Prüfungssimulation.',
            'offers' => ['BP-SBF-BIN-SEGEL', 'BP-ADD-BIN-SEGEL'], 'featured' => 'BP-SBF-BIN-SEGEL', 'compare' => [],
        ],
        'src' => [
            'main' => 'BP-SRC', 'mark' => 'SRC', 'title' => 'SRC', 'name' => 'Short Range Certificate', 'eyebrow' => 'Funkzeugnis', 'code' => 'SRC', 'icon' => 'radio', 'tone' => 'funk',
            'teaser' => 'UKW-Seefunk mit DSC – Fragen, englische Funktexte und Funkgerät-Simulator zum Testen.',
            'lead' => 'Lerne für das SRC: alle Prüfungsfragen im Smarttrainer, englische Funktexte mit Auswertung, Vokabeln und Buchstabiertafel – dazu der Funkgerät-Simulator für den praktischen Teil: kostenlos testen, Vollversion als Upgrade.',
            'offers' => ['BP-SRC', 'BP-SRC-UBI', 'BP-UBI'], 'featured' => 'BP-SRC-UBI', 'compare' => ['BP-SRC-KOMPLETT', 'BP-UBI-KOMPLETT'],
        ],
        'ubi' => [
            'main' => 'BP-UBI', 'mark' => 'UBI', 'title' => 'UBI', 'name' => 'UKW-Sprechfunkzeugnis Binnen', 'eyebrow' => 'Funkzeugnis', 'code' => 'UBI', 'icon' => 'radio', 'tone' => 'ubi',
            'teaser' => 'Binnenfunk – Fragen, Textaufnahme und Textabgabe, Funkgerät-Simulator zum Testen.',
            'lead' => 'Lerne für das UBI: alle Prüfungsfragen im Smarttrainer, Funktexte für Textaufnahme und Textabgabe, Praxisszenarien und die Prüfungssimulation.',
            'offers' => ['BP-UBI', 'BP-SRC-UBI', 'BP-ADD-UBI-TO-SRC'], 'featured' => 'BP-SRC-UBI', 'compare' => ['BP-SRC-KOMPLETT', 'BP-UBI-KOMPLETT'],
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
        'sbf-kombi' => [
            'main' => 'BP-SBF-SEE-BIN', 'mark' => 'KOMBI', 'title' => 'SBF See & Binnen', 'name' => 'Kombi SBF See & Binnen', 'eyebrow' => 'Kombi', 'code' => 'SBF-SEE-BIN', 'icon' => 'anchor', 'tone' => 'see',
            'teaser' => 'Beide Sportbootführerscheine in einem Kurs – Küste und Binnen.',
            'lead' => 'Lerne SBF See und SBF Binnen in einem Kurs: alle Prüfungsfragen beider Scheine im Smarttrainer, Navigationsaufgaben, Praxiskarten, Videokurs und Prüfungssimulation – günstiger als zwei Einzelkurse.',
            'offers' => ['BP-SBF-SEE', 'BP-SBF-SEE-BIN', 'BP-KAPITAEN'], 'featured' => 'BP-SBF-SEE-BIN', 'compare' => ['BP-SBF-SEE-KOMPLETT', 'BP-SBF-BIN-KOMPLETT'],
        ],
        'kapitaen' => [
            'main' => 'BP-KAPITAEN', 'mark' => 'BUNDLE', 'title' => 'Kapitäns-Bundle', 'name' => 'Kapitäns-Bundle: SBF See, Binnen, SRC & UBI', 'eyebrow' => 'Komplettpaket', 'code' => 'SBF-SEE-BIN', 'codes' => ['SBF-SEE-BIN', 'SRC-UBI'], 'exam' => false, 'icon' => 'wheel', 'tone' => 'bundle', 'signal' => true,
            'teaser' => 'Komplett: SBF See und Binnen plus beide Funkscheine SRC und UBI.',
            'lead' => 'Alle wichtigen Scheine für Küste und Binnen in einem Paket: SBF See, SBF Binnen, SRC und UBI – mit allen Prüfungsfragen, Navigationsaufgaben, Praxiskarten, Funktraining und Prüfungssimulation.',
            'offers' => ['BP-SBF-SEE-BIN', 'BP-KAPITAEN', 'BP-SRC-UBI'], 'featured' => 'BP-KAPITAEN', 'compare' => ['BP-SBF-SEE-BIN', 'BP-SRC-UBI'],
        ],
        'funk-kombi' => [
            'main' => 'BP-SRC-UBI', 'mark' => 'KOMBI', 'title' => 'SRC & UBI', 'name' => 'Kombi SRC & UBI', 'eyebrow' => 'Kombi', 'code' => 'SRC-UBI', 'icon' => 'radio', 'tone' => 'funk',
            'teaser' => 'Seefunk und Binnenfunk zusammen – beide Funkzeugnisse in einem Kurs.',
            'lead' => 'Lerne SRC und UBI in einem Kurs: alle Prüfungsfragen beider Funkzeugnisse, englische und deutsche Funktexte, Vokabeln und Praxisszenarien – günstiger als zwei Einzelkurse. Den Funkgerät-Simulator testest du kostenlos und schaltest ihn als Upgrade frei.',
            'offers' => ['BP-SRC', 'BP-SRC-UBI', 'BP-KAPITAEN'], 'featured' => 'BP-SRC-UBI', 'compare' => ['BP-SRC-KOMPLETT', 'BP-UBI-KOMPLETT'],
        ],
    ];
}

/** Kachel-Reihen: Startseite (zwei Reihen) und Seite „Weitere Kurse“. */
function kurswasser_tile_sets(): array
{
    return [
        'main' => ['kapitaen', 'sbf-kombi', 'sbf-see', 'sbf-binnen', 'funk-kombi', 'src', 'ubi', 'weitere'],
        'more' => ['sbf-binnen-segel', 'skn', 'fkn', 'ergaenzung', 'paesse', 'gruppen'],
    ];
}

/** Zusatz-Kacheln ohne eigene Kursdefinition. */
function kurswasser_special_tiles(): array
{
    $min = static fn (array $skus): ?float => ($p = array_filter(array_map(static fn ($s) => kurswasser_offer($s)['price'] ?? null, $skus), static fn ($x) => $x !== null)) ? min($p) : null;

    return [
        'weitere' => ['title' => __('Weitere Kurse', 'kurswasser'), 'name' => __('Segeln, Seenotsignalmittel & mehr', 'kurswasser'), 'eyebrow' => __('Mehr Kurse', 'kurswasser'), 'icon' => 'plus',
            'teaser' => __('Alle Kurse im Überblick – auch Binnen unter Segel, SKN, FKN, Ergänzungen, Pässe und Gruppen.', 'kurswasser'),
            'facts' => [['sail', __('Binnen unter Segel', 'kurswasser')], ['flare', __('SKN und FKN', 'kurswasser')]],
            'url' => kurswasser_url('kurse'), 'price' => $min(['BP-SBF-BIN-SEGEL', 'BP-SKN', 'BP-FKN', 'BP-PASS-1M']), 'from' => true, 'cta' => __('Alle Kurse', 'kurswasser')],
        'ergaenzung' => ['title' => __('Ergänzungen', 'kurswasser'), 'name' => __('Ergänzungskurse', 'kurswasser'), 'eyebrow' => __('Schon einen Schein?', 'kurswasser'), 'icon' => 'plus',
            'teaser' => __('Nur die zusätzlichen Inhalte: See → Binnen, Binnen → See, Binnen → Segeln, SRC → UBI – und FKN → SKN mit dem ganzen SKN-Katalog.', 'kurswasser'),
            'facts' => [['clock', __('6 Monate Zugang', 'kurswasser')]],
            'url' => kurswasser_url('ergaenzung'), 'price' => $min(['BP-ADD-SEE-TO-BIN', 'BP-ADD-BIN-TO-SEE', 'BP-ADD-BIN-SEGEL', 'BP-ADD-UBI-TO-SRC', 'BP-ADD-FKN-TO-SKN']), 'from' => true, 'cta' => __('Ansehen', 'kurswasser')],
        'paesse' => ['title' => __('Pässe', 'kurswasser'), 'name' => __('1- und 3-Monats-Pass', 'kurswasser'), 'eyebrow' => __('Alle Inhalte', 'kurswasser'), 'icon' => 'clock',
            'teaser' => __('Voller Zugriff auf alle Kurse für 30 oder 90 Tage.', 'kurswasser'),
            'facts' => [['check', __('Alle Kurse und Trainer', 'kurswasser')]],
            'url' => kurswasser_course_url('pakete'), 'price' => $min(['BP-PASS-1M', 'BP-PASS-3M']), 'from' => true, 'cta' => __('Ansehen', 'kurswasser')],
        'gruppen' => ['title' => __('Gruppen', 'kurswasser'), 'name' => __('Gruppen & Vereine', 'kurswasser'), 'eyebrow' => __('2 bis 50 Personen', 'kurswasser'), 'icon' => 'crew',
            'teaser' => __('Ein Kauf, Plätze per Link oder E-Mail vergeben – jede Person lernt mit eigenem Konto.', 'kurswasser'),
            'facts' => [['crew', __('Paare, Familien, Crews, Vereine', 'kurswasser')]],
            'url' => kurswasser_url('gruppen'), 'price' => $min(['GRP-PAIR-02', 'GRP-PAIR-01']), 'from' => true, 'cta' => __('Ansehen', 'kurswasser')],
    ];
}

/** Eine Kachel (Kursdefinition oder Zusatz-Kachel). */
function kurswasser_tile(string $slug): string
{
    $def = kurswasser_courses()[$slug] ?? null;
    if ($def !== null) {
        $main = kurswasser_offer($def['main']);
        $facts = kurswasser_course_facts($def, 2);
        $saving = 0.0;
        if ($main && $def['compare'] !== [] && $def['featured'] === $def['main']) {
            $saving = round(array_sum(array_map(static fn ($s) => kurswasser_offer($s)['price'] ?? 0.0, $def['compare'])) - $main['price'], 2);
        }
        $t = ['title' => $def['title'], 'name' => $def['name'], 'eyebrow' => $def['eyebrow'], 'icon' => $def['icon'], 'teaser' => $def['teaser'], 'facts' => $facts,
            'url' => kurswasser_course_url($slug), 'price' => $main['price'] ?? null, 'from' => kurswasser_learning_compatible() && isset(\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_TIERS[$def['main']]), 'cta' => __('Kurs ansehen', 'kurswasser'), 'saving' => $saving, 'signal' => ! empty($def['signal'])];
    } else {
        $t = kurswasser_special_tiles()[$slug] ?? null;
        if ($t === null) {
            return '';
        }
        $t += ['saving' => 0.0, 'signal' => false];
    }
    $img = kurswasser_course_image($slug, true);
    $html = '<article class="kw-tile'.($t['signal'] ? ' kw-tile-bundle' : '').'">';
    $html .= '<div class="kw-tile-photo"'.($img ? ' style="background-image:url(\''.esc_url($img).'\')"' : '').'><span class="kw-tile-pill'.($t['signal'] ? ' is-signal' : '').'"><span class="kw-icon kw-icon-'.esc_attr($t['icon']).'" aria-hidden="true"></span>'.esc_html($t['eyebrow']).'</span><span class="kw-tile-code" aria-hidden="true">'.esc_html($t['title']).'</span></div>';
    $html .= '<div class="kw-tile-body"><h3><a href="'.esc_url($t['url']).'">'.esc_html($t['name']).'</a></h3><p class="kw-tile-text">'.esc_html($t['teaser']).'</p><ul class="kw-tile-facts">';
    if ($t['saving'] >= 1) {
        $html .= '<li class="is-save"><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html(sprintf(__('Du sparst %s gegenüber den Einzelkursen', 'kurswasser'), kurswasser_money($t['saving']))).'</li>';
    }
    foreach (array_slice($t['facts'], 0, $t['saving'] >= 1 ? 1 : 2) as [$icon, $text]) {
        $html .= '<li><span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span>'.esc_html($text).'</li>';
    }
    $html .= '</ul></div><footer class="kw-tile-foot">';
    $html .= $t['price'] !== null ? '<p class="kw-tile-price">'.($t['from'] ? '<span>'.esc_html__('ab', 'kurswasser').'</span> ' : '').esc_html(kurswasser_money((float) $t['price'])).(kurswasser_price_note() !== '' ? ' <small class="kw-price-note">'.esc_html(kurswasser_price_note()).'</small>' : '').'</p>' : '<p></p>';

    return $html.'<span class="kw-tile-go">'.esc_html($t['cta']).'<span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></span></footer></article>';
}

/** Kursfoto (assets/images/courses/<kurs>.webp, klein: -sm). */
function kurswasser_course_image(string $slug, bool $small = false): ?string
{
    $file = 'assets/images/courses/'.$slug.($small ? '-sm' : '').'.webp';

    return is_file(get_template_directory().'/'.$file) ? get_template_directory_uri().'/'.$file : null;
}

/**
 * Ziel des Brotkrumen-Glieds „Kurse“: die Kursübersicht /kurse/ (legt kurswasser_create_course_pages() an).
 * Ohne veröffentlichte Übersichtsseite wie bisher der Anker der Startseite.
 */
function kurswasser_courses_overview_url(): string
{
    $page = get_page_by_path('kurse');

    return $page instanceof WP_Post && $page->post_status === 'publish' ? (string) get_permalink($page) : home_url('/#kurse');
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
function kurswasser_course_stats(array $def): array
{
    return kurswasser_learning_compatible()
        ? \Bootspruefung\Learning\Commerce\ShopCatalog::courseStats($def['codes'] ?? [$def['code']])
        : ['questions' => 0, 'nav' => 0, 'praxis' => 0, 'videos' => 0, 'papers' => 0];
}

/** Kennzahlen als kurze Fakten (Symbol, Text). @return list<array{0:string,1:string}> */
function kurswasser_course_facts(array $def, int $max = 3): array
{
    $s = kurswasser_course_stats($def);
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


/** Kursseite → Rubrik-Schlüssel der FAQ im Lern-Plugin (Kapitäns-Bundle ist ein Produkt, kein Kurs). */
function kurswasser_faq_rubrics(): array
{
    return [
        'sbf-see' => 'SBF-SEE', 'sbf-binnen' => 'SBF-BIN-MOTOR', 'sbf-binnen-segel' => 'SBF-BIN-SEGEL', 'src' => 'SRC', 'ubi' => 'UBI',
        'skn' => 'SKN', 'fkn' => 'FKN', 'sbf-kombi' => 'SBF-SEE-BIN', 'kapitaen' => 'KAPITAEN', 'funk-kombi' => 'SRC-UBI',
    ];
}

/**
 * ★-FAQs einer Rubrik aus dem Lern-Plugin (Bootsprüfung › FAQ, „Im Theme anzeigen“).
 * Leer, wenn das Plugin fehlt oder keine ★-FAQ veröffentlicht ist – dann gelten die festen Texte.
 *
 * @return list<array{question:string,answer_html:string}> answer_html ist vom Plugin bereits gefiltert
 */
function kurswasser_plugin_faqs(string $rubric): array
{
    if (! function_exists('bootspruefung_faqs')) {
        return [];
    }

    return array_map(static fn (array $faq): array => ['question' => (string) $faq['question'], 'answer_html' => (string) $faq['answer_html']], bootspruefung_faqs(['rubric' => $rubric, 'theme_only' => true]));
}

/** Allgemeine FAQ (Muster „kurswasser/faq“): ★-FAQs der Rubrik „allgemein“, leer = statisches Muster. */
function kurswasser_general_faqs(): array
{
    return kurswasser_plugin_faqs('allgemein');
}

/**
 * Kursbezogene FAQ-Kategorien: Titel je Kursseite, Fragen aus dem Lern-Plugin (★ der passenden Rubrik).
 * Die fest eingetragenen Fragen sind der Rückfall, wenn das Plugin fehlt oder nichts liefert.
 */
function kurswasser_course_faqs(): array
{
    $faqs = kurswasser_course_faqs_fallback();
    foreach (kurswasser_faq_rubrics() as $slug => $rubric) {
        if (isset($faqs[$slug]) && ($items = kurswasser_plugin_faqs($rubric)) !== []) {
            $faqs[$slug]['items'] = $items;
        }
    }

    return $faqs;
}

/** Rückfall: fest eingetragene Fragen und Antworten je Kursseite (Text, ohne HTML). */
function kurswasser_course_faqs_fallback(): array
{
    return [
        'sbf-see' => [
            'title' => 'Fragen zum SBF See',
            'items' => [
                ['question' => 'Was lerne ich im SBF-See-Kurs?', 'answer' => 'Du übst alle Prüfungsfragen des SBF See im Smarttrainer und simulierst die Prüfung mit Zeitlimit. Bei den Navigationsaufgaben werden deine Werte automatisch geprüft. Praxiskarten bereiten dich auf die praktische Prüfung vor. In der Stufe „Komplett“ kommt der Videokurs dazu.'],
                ['question' => 'Brauche ich für die Navigationsaufgaben eine Seekarte?', 'answer' => 'Ja. Für die Navigationsaufgaben brauchst du die gedruckte Übungsseekarte D49 sowie Kursdreieck und Zirkel. Die Karte besorgst du dir selbst, wir verkaufen sie nicht. Im Kurs werden deine Werte automatisch geprüft, du siehst die Musterlösung und kannst dich selbst einschätzen.'],
                ['question' => 'Ist die praktische Ausbildung auf dem Boot im Onlinekurs enthalten?', 'answer' => 'Der Kurs bereitet dich online auf die Theorie und die Prüfungsinhalte vor. Praxiskarten unterstützen dich beim Lernen; tatsächliche Fahrstunden auf dem Wasser sind nicht Bestandteil des Onlinekurses.'],
                ['question' => 'Kann ich die Prüfung mit dem Kurs simulieren?', 'answer' => 'Ja. Die Prüfungssimulation übt den Ablauf mit Zeitlimit und anschließender Auswertung.'],
            ],
        ],
        'sbf-binnen' => [
            'title' => 'Fragen zum SBF Binnen',
            'items' => [
                ['question' => 'Welche Inhalte deckt der Binnenkurs ab?', 'answer' => 'Der Kurs behandelt den SBF Binnen unter Antriebsmaschine. Du übst die Fragen im Smarttrainer und vertiefst Zeichen, Lichter und Manöver mit Praxiskarten.'],
                ['question' => 'Ist der Segelteil im Binnenkurs enthalten?', 'answer' => 'Der hier beschriebene Kurs bereitet auf den SBF Binnen unter Antriebsmaschine vor. Für die zusätzlichen Segelfragen und Segelmanöver gibt es einen eigenen Kurs für den SBF Binnen unter Segel.'],
                ['question' => 'Gibt es eine Prüfungssimulation?', 'answer' => 'Ja. Du kannst mit Zeitlimit üben und erhältst nach dem Durchgang eine Auswertung.'],
                ['question' => 'Enthält der Onlinekurs Fahrstunden?', 'answer' => 'Nein. Der Kurs ist für die Onlinevorbereitung gedacht. Praxiskarten helfen dir beim Wiederholen der praktischen Inhalte; Fahrstunden auf dem Wasser sind separat zu organisieren.'],
            ],
        ],
        'sbf-binnen-segel' => [
            'title' => 'Fragen zum SBF Binnen unter Segel',
            'items' => [
                ['question' => 'Was kommt im Segelkurs zusätzlich dazu?', 'answer' => 'Der Kurs verbindet die Binnenfragen mit den zusätzlichen Segelfragen. Außerdem übst du Segelmanöver sowie Themen wie Wind und Trimm.'],
                ['question' => 'Kann ich die Segelfragen gezielt wiederholen?', 'answer' => 'Ja. Die Fragen lassen sich im Smarttrainer üben und gezielt wiederholen.'],
                ['question' => 'Wie bereite ich mich auf den praktischen Segelteil vor?', 'answer' => 'Praxiskarten helfen dir, Manöver und wichtige Abläufe zu wiederholen. Die praktische Ausbildung auf dem Wasser ist nicht Teil des Onlinekurses.'],
            ],
        ],
        'src' => [
            'title' => 'Fragen zum SRC',
            'items' => [
                ['question' => 'Was lerne ich im SRC-Kurs?', 'answer' => 'Der Kurs behandelt die Prüfungsfragen zum Seefunkzeugnis SRC. Zusätzlich übst du englische Funktexte, Vokabeln und das Buchstabieralphabet.'],
                ['question' => 'Kann ich Funktexte für die Prüfung üben?', 'answer' => 'Ja. Die englischen Funktexte werden mit einer Auswertung geübt, damit du deine Eingaben nachvollziehen kannst.'],
                ['question' => 'Gibt es im SRC-Kurs einen Funkgerät-Simulator?', 'answer_html' => '<p>'.esc_html('Im SRC-Kurs ist die kostenlose Testversion enthalten: Gerät einschalten, Kanäle wählen und einen Routineanruf üben. Die Vollversion mit Notalarm, Prüfungsmodus und freiem Üben bekommst du auf zwei Wegen:').'</p><ul class="wp-block-list"><li>'.esc_html('beim Kauf eines Funkkurses als Upgrade in der Kasse;').'</li><li>'.esc_html('als eigenen Simulator-Zugang für 14 Tage, 3 oder 12 Monate.').'</li></ul><p>'.esc_html('Im Kapitäns-Bundle und in den Pässen ist die Vollversion schon enthalten.').'</p>'],
                ['question' => 'Was ist der Unterschied zwischen SRC und UBI?', 'answer' => 'Das SRC ist das Funkzeugnis für den Seefunk. Das UBI behandelt den Binnenfunk. Für beide Zeugnisse gibt es eigene Lerninhalte und Prüfungsvorbereitungen.'],
            ],
        ],
        'ubi' => [
            'title' => 'Fragen zum UBI',
            'items' => [
                ['question' => 'Was lerne ich im UBI-Kurs?', 'answer' => 'Der Kurs bereitet auf das UKW-Sprechfunkzeugnis für den Binnenfunk vor. Er umfasst Prüfungsfragen, Funktexte und Praxisszenarien.'],
                ['question' => 'Kann ich Textaufnahme und Textabgabe üben?', 'answer' => 'Ja. Der Kurs enthält Übungen zu Funktexten für die Textaufnahme und Textabgabe.'],
                ['question' => 'Ist das UBI dasselbe wie das SRC?', 'answer' => 'Nein. Das UBI bezieht sich auf den Binnenfunk, das SRC auf den Seefunk. Die Prüfungsinhalte unterscheiden sich entsprechend.'],
                ['question' => 'Gibt es eine Prüfungssimulation?', 'answer' => 'Ja. Mit der Simulation kannst du die Vorbereitung unter Zeitvorgabe üben und deine Antworten anschließend auswerten.'],
            ],
        ],
        'skn' => [
            'title' => 'Fragen zum SKN',
            'items' => [
                ['question' => 'Worum geht es im SKN-Kurs?', 'answer' => 'Der Kurs bereitet auf den Sachkundenachweis für Seenotsignalmittel vor. Du übst Fragen und wiederholst den Umgang mit Signalmitteln anhand von Praxiskarten.'],
                ['question' => 'Was trainieren die Praxiskarten?', 'answer' => 'Die Praxiskarten unterstützen dich beim Erkennen und bei der sicheren Handhabung der behandelten Seenotsignalmittel.'],
                ['question' => 'Kann ich meine Prüfungsvorbereitung überprüfen?', 'answer' => 'Ja. Der Smarttrainer hilft dir, Fragen zu wiederholen und unsichere Themen erneut zu üben.'],
            ],
        ],
        'fkn' => [
            'title' => 'Fragen zum FKN',
            'items' => [
                ['question' => 'Was behandelt der FKN-Kurs?', 'answer' => 'Der Kurs bereitet auf den Fachkundenachweis für Seenotsignalmittel vor. Er umfasst Prüfungsfragen, Praxiskarten und eine Prüfungssimulation.'],
                ['question' => 'Wie kann ich die Handhabung wiederholen?', 'answer' => 'Mit den Praxiskarten kannst du das Erkennen und die Handhabung der behandelten Signalmittel wiederholen.'],
                ['question' => 'Kann ich einzelne Fragen mehrfach üben?', 'answer' => 'Ja. Im Smarttrainer kannst du Fragen wiederholen und dich erneut mit Themen beschäftigen, bei denen du noch unsicher bist.'],
            ],
        ],
        'sbf-kombi' => [
            'title' => 'Fragen zum Kombikurs SBF See & Binnen',
            'items' => [
                ['question' => 'Welche Scheine umfasst der Kombikurs?', 'answer' => 'Der Kombikurs verbindet die Vorbereitung auf den SBF See und den SBF Binnen. Die Lerninhalte beider Scheine sind im Kurs zusammengeführt.'],
                ['question' => 'Sind die Fragen für beide Scheine enthalten?', 'answer' => 'Ja. Du kannst die im Kurs enthaltenen Fragen für See und Binnen üben. Zusätzlich umfasst die Vorbereitung Navigationsaufgaben, Praxiskarten und Prüfungssimulationen.'],
                ['question' => 'Muss ich beide Prüfungen gleichzeitig ablegen?', 'answer' => 'Nein. Du kannst SBF See und SBF Binnen zusammen oder nacheinander ablegen. Bei einer gemeinsamen Prüfung beantwortest du die Basisfragen nur einmal. Den Termin stimmst du mit deinem Prüfungsausschuss ab.'],
            ],
        ],
        'kapitaen' => [
            'title' => 'Fragen zum Kapitäns-Bundle',
            'items' => [
                ['question' => 'Welche Kurse gehören zum Kapitäns-Bundle?', 'answer' => 'Das Bundle bündelt die Vorbereitung auf SBF See, SBF Binnen, SRC und UBI. Die jeweiligen Fragen und Lernbereiche sind im Paket enthalten. Die Vollversion des Funkgerät-Simulators ist enthalten.'],
                ['question' => 'Kann ich die Scheine in meinem eigenen Tempo lernen?', 'answer' => 'Ja. Du lernst online und kannst zwischen den Themen und Kursbereichen wechseln. Dein Lernfortschritt wird in deinem Konto gespeichert.'],
                ['question' => 'Sind amtliche Prüfungen im Kapitäns-Bundle enthalten?', 'answer' => 'Nein. Das Bundle umfasst Lerninhalte und Prüfungsvorbereitung für SBF See, SBF Binnen, SRC und UBI. Die amtlichen Prüfungen legst du bei einem Prüfungsausschuss deiner Wahl ab; Anmeldung, Termin und Gebühr laufen direkt über den Ausschuss.'],
            ],
        ],
        'funk-kombi' => [
            'title' => 'Fragen zum Kombikurs SRC & UBI',
            'items' => [
                ['question' => 'Welche Funkzeugnisse umfasst der Kombikurs?', 'answer' => 'Der Kurs verbindet die Vorbereitung auf SRC für den Seefunk und UBI für den Binnenfunk.'],
                ['question' => 'Welche Funkübungen sind enthalten?', 'answer' => 'Je nach Funkzeugnis übst du englische oder deutsche Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien.'],
                ['question' => 'Ist der Funkgerät-Simulator im Kombikurs enthalten?', 'answer' => 'Im Kombikurs ist die Testversion enthalten. Die Vollversion buchst du als Upgrade dazu – im Kapitäns-Bundle und in den Pässen ist sie schon enthalten.'],
            ],
        ],
    ];
}

/** Gibt die FAQ-Kategorie des jeweiligen Kurses als native Akkordeons aus. */
function kurswasser_course_faq(string $slug): string
{
    $faq = kurswasser_course_faqs()[$slug] ?? null;
    if ($faq === null || empty($faq['items'])) {
        return '';
    }

    $html = '<section class="kw-section kw-faq kw-course-faq" aria-labelledby="kw-course-faq-title"><div class="kw-section-head"><p class="kw-eyebrow">'.esc_html__('Fragen & Antworten', 'kurswasser').'</p><h2 id="kw-course-faq-title">'.esc_html($faq['title']).'</h2></div>';
    foreach ($faq['items'] as $item) {
        // Plugin: answer_html (bereits per wp_kses_post gefiltert); Rückfall: reiner Text.
        $answer = isset($item['answer_html']) ? $item['answer_html'] : '<p>'.esc_html($item['answer']).'</p>';
        $html .= '<details class="wp-block-details"><summary>'.esc_html($item['question']).'</summary>'.$answer.'</details>';
    }

    return $html.'</section>';
}

/* ------------------------------------------------------------------ Kacheln (Startseite) */

add_shortcode('kurswasser_course_tiles', static function ($atts): string {
    $a = shortcode_atts(['set' => 'main', 'kurse' => ''], (array) $atts);
    $set = sanitize_key((string) $a['set']);
    $slugs = kurswasser_tile_sets()[$set] ?? kurswasser_tile_sets()['main'];
    // Auswahl für Ratgeberseiten: kurse="sbf-see,sbf-binnen" – nur bekannte Kurse bzw. Zusatz-Kacheln.
    $pick = array_values(array_filter(array_map('sanitize_key', explode(',', (string) $a['kurse'])), static fn (string $s): bool => $s !== '' && (isset(kurswasser_courses()[$s]) || isset(kurswasser_special_tiles()[$s]))));
    if ($pick !== []) {
        return '<div class="kw-tiles kw-tiles-pick kw-tiles-n'.count($pick).'">'.implode('', array_map('kurswasser_tile', $pick)).'</div>';
    }

    return '<div class="kw-tiles'.(count($slugs) % 4 !== 0 && count($slugs) % 3 === 0 ? ' kw-tiles-3' : '').'">'.implode('', array_map('kurswasser_tile', $slugs)).'</div>';
});

/* ------------------------------------------------------------------ Kurs-Infoseite */

add_shortcode('kurswasser_course', static function ($atts): string {
    $slug = sanitize_key((string) (shortcode_atts(['id' => ''], (array) $atts)['id']));
    if ($slug === 'pakete') {
        return kurswasser_packages_page();
    }
    if ($slug === 'weitere') {
        return kurswasser_more_page();
    }
    $def = kurswasser_courses()[$slug] ?? null;
    if ($def === null) {
        return '';
    }
    $stats = kurswasser_course_stats($def);
    $main = kurswasser_offer($def['main'] ?? '');
    $from = $main['price'] ?? kurswasser_course_from_price($def);
    $trial = home_url('/bootsscheine/'.(kurswasser_learning_compatible() ? \Bootspruefung\Learning\Content\Repositories\ContentRepository::slug($def['code']) : '').'/');

    // Hero
    $photo = kurswasser_course_image($slug);
    $photoSmall = kurswasser_course_image($slug, true);
    $html = '<section class="kw-course-hero alignfull"'.($photo ? ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) $photoSmall).'\')"' : '').'><div class="kw-course-hero-inner">';
    $html .= '<nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><a href="'.esc_url(kurswasser_courses_overview_url()).'">'.esc_html__('Kurse', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html($def['title']).'</span></nav>';
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
        $features[] = ['compass', __('Navigationsaufgaben', 'kurswasser'), sprintf(__('%d Kartenaufgaben zur Übungskarte D49 (gedruckte Karte nötig) mit Musterlösungen und Selbsteinschätzung.', 'kurswasser'), $stats['nav'])];
    }
    if ($stats['praxis']) {
        $features[] = ['cards', __('Praxiskarten', 'kurswasser'), sprintf(__('%d Karten zum Einprägen für den praktischen Teil.', 'kurswasser'), $stats['praxis'])];
    }
    if ($stats['videos']) {
        $tiered = kurswasser_learning_compatible() && isset(\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_TIERS[$def['main'] ?? '']);
        $features[] = ['play', __('Videokurs', 'kurswasser'), sprintf($tiered
            ? __('%d kurze Lektionen mit Schritt-für-Schritt-Ansichten und Fragen – in der Komplett-Stufe oder als Upgrade; erste Lektion kostenlos.', 'kurswasser')
            : __('%d kurze Lektionen mit Schritt-für-Schritt-Ansichten und Fragen.', 'kurswasser'), $stats['videos'])];
    }
    if (array_intersect($def['codes'] ?? [$def['code']], ['SRC', 'UBI', 'SRC-UBI']) !== []) {
        $features[] = ['radio', __('Funktraining', 'kurswasser'), kurswasser_course_has_sim($def)
            ? __('Funktexte, Vokabeln, Buchstabiertafel, Praxisszenarien und der Funkgerät-Simulator mit Prüfungsmodus.', 'kurswasser')
            : __('Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien. Funkgerät-Simulator kostenlos testen, Vollversion als Upgrade.', 'kurswasser')];
    }
    $features[] = ['phone', __('Überall lernen', 'kurswasser'), __('Im Browser auf Rechner, Tablet und Smartphone – dein Fortschritt bleibt gespeichert.', 'kurswasser')];
    foreach ($features as [$icon, $title, $text]) {
        $html .= '<div class="kw-feature"><span class="kw-feature-icon"><span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span></span><h3>'.esc_html($title).'</h3><p>'.esc_html($text).'</p></div>';
    }
    $html .= '</div></section>';

    // Prüfung
    $html .= kurswasser_course_exam($def);
    $html .= kurswasser_course_guides();

    // Angebote
    $html .= '<section id="preise" class="kw-section kw-course-offers"><div class="kw-center-head"><p class="kw-eyebrow">'.esc_html__('Preise', 'kurswasser').'</p><h2>'.esc_html(sprintf(__('Starte mit %s', 'kurswasser'), $def['title'])).'</h2><p class="kw-lead">'.esc_html__('Einmal zahlen, sofort lernen. Mit der Kombi sparst du gegenüber dem Einzelkauf.', 'kurswasser').'</p></div>';
    $html .= kurswasser_offer_cards($def['offers'], $def['featured'], $def['compare'], $def['compare'] !== [] ? __('Unsere Empfehlung', 'kurswasser') : __('Dein Kurs', 'kurswasser'));
    $html .= kurswasser_offer_trust().'</section>';
    $html .= kurswasser_sim_section($def);

    $html .= kurswasser_course_faq($slug);

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/guarantee"} /--><!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
});

/**
 * Zeile „Vor der Prüfung wissen“ auf den Kursseiten: Links auf die passenden Ratgeber (nur veröffentlichte Seiten).
 */
function kurswasser_course_guides(): string
{
    $links = [];
    foreach ([['aerztliches-attest-bootsfuehrerschein', __('Ärztliches Attest', 'kurswasser')], ['mindestalter-bootsfuehrerschein', __('Mindestalter', 'kurswasser')],
        ['bootsfuehrerschein-kosten', __('Kosten', 'kurswasser')], ['bootsfuehrerschein-standorte', __('Praxis und Prüfung vor Ort', 'kurswasser')]] as [$path, $label]) {
        if (function_exists('kurswasser_page_published') && kurswasser_page_published($path)) {
            $links[] = kurswasser_page_link($path, $label);
        }
    }
    if ($links === []) {
        return '';
    }

    return '<section class="kw-section kw-course-guides"><p class="kw-link-chips"><span class="kw-link-chips-label">'.esc_html__('Vor der Prüfung wissen:', 'kurswasser').'</span>'.implode('', $links).'</p></section>';
}

/** Prüfungsrahmen aus der Anleitung der Quelle (erste Prüfungsform des Kurses). */
function kurswasser_course_exam(array $def): string
{
    if (($def['exam'] ?? true) === false || ! kurswasser_learning_compatible()) {
        return '';
    }
    $tabs = array_values(array_filter((new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->payload($def['code'])['tabs'] ?? [], static fn ($t) => ! empty($t['variants'])));
    if ($tabs === []) {
        return '';
    }
    $multi = count($tabs) > 1;
    $html = '<section class="kw-section kw-course-exam"><div class="kw-exam-grid"><div><p class="kw-eyebrow">'.esc_html__('Die Prüfung', 'kurswasser').'</p><h2>'.esc_html($multi ? __('So laufen die Prüfungen ab.', 'kurswasser') : __('So läuft die Prüfung ab.', 'kurswasser')).'</h2>';
    $html .= '<p class="kw-lead">'.esc_html__('Die amtliche Prüfung legst du bei einem Prüfungsausschuss ab. Hier siehst du, wie sie abläuft und was dich erwartet.', 'kurswasser').'</p>';
    foreach ($tabs as $tab) {
        $variant = $tab['variants'][0];
        $html .= ($multi ? '<h3 class="kw-exam-tab">'.esc_html((string) $tab['tab']).' <span>· '.esc_html((string) $variant['label']).'</span></h3>' : '').'<dl class="kw-exam-facts"><div><dt>'.esc_html__('Theorie', 'kurswasser').'</dt><dd>'.esc_html((string) $variant['scope']).'</dd></div>';
        if (! empty($variant['pass_text'])) {
            $html .= '<div><dt>'.esc_html__('Bestanden', 'kurswasser').'</dt><dd>'.esc_html((string) $variant['pass_text']).'</dd></div>';
        }
        if (! empty($variant['minutes'])) {
            $html .= '<div><dt>'.esc_html__('Zeit', 'kurswasser').'</dt><dd>'.esc_html(sprintf(__('%d Minuten', 'kurswasser'), (int) $variant['minutes'])).'</dd></div>';
        }
        $html .= '</dl>';
    }
    $html .= '<p class="kw-exam-note">'.esc_html__('Angaben laut amtlichem Prüfungsrahmen; maßgeblich ist dein Prüfungsausschuss.', 'kurswasser').'</p></div><ol class="kw-exam-parts">';
    $seen = [];
    foreach ($tabs as $tab) {
        foreach ($tab['exam_parts'] as $part) {
            $title = ($multi ? $tab['tab'].': ' : '').$part['title'];
            if (isset($seen[$title])) {
                continue;
            }
            $seen[$title] = true;
            $html .= '<li><h3>'.esc_html((string) $title).'</h3><p>'.esc_html((string) $part['text']).'</p></li>';
        }
    }

    return $html.'</ol></div></section>';
}

/** Seite „Pakete & Pässe“: Reiter-Element mit allen Angeboten. */
function kurswasser_packages_page(): string
{
    $photo = kurswasser_course_image('pakete');
    $html = '<section class="kw-course-hero alignfull"'.($photo ? ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) kurswasser_course_image('pakete', true)).'\')"' : '').'><div class="kw-course-hero-inner"><nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><a href="'.esc_url(kurswasser_courses_overview_url()).'">'.esc_html__('Kurse', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html__('Pakete & Pässe', 'kurswasser').'</span></nav>';
    $html .= '<div class="kw-course-hero-grid is-single"><div><p class="kw-eyebrow">'.esc_html__('Sparen mit Kombis und Paketen', 'kurswasser').'</p><h1>'.esc_html__('Pakete & Pässe', 'kurswasser').'</h1><p class="kw-course-lead">'.esc_html__('Mehrere Scheine auf einmal? Mit Kombi, Kapitäns-Bundle oder einem Pass für alle Inhalte lernst du alles an einem Ort – und sparst gegenüber dem Einzelkauf.', 'kurswasser').'</p></div></div></div></section>';
    $html .= '<section id="preise" class="kw-section">'.do_shortcode('[kurswasser_offers]').'</section>';

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/groups"} /--><!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
}

/** Bereiche der Kursübersicht: Anker => [Titel, Text, Kacheln]. */
function kurswasser_overview_sections(): array
{
    return [
        'pakete' => [__('Pakete & Pässe', 'kurswasser'), __('Mehrere Scheine auf einmal oder alle Inhalte für eine feste Zeit – günstiger als einzeln.', 'kurswasser'), ['kapitaen', 'paesse']],
        'sbf' => [__('Sportbootführerscheine', 'kurswasser'), __('Für Küste und Binnengewässer, unter Motor oder Segel – einzeln oder als Kombi.', 'kurswasser'), ['sbf-kombi', 'sbf-see', 'sbf-binnen', 'sbf-binnen-segel']],
        'funk' => [__('Funkzeugnisse', 'kurswasser'), __('Seefunk (SRC) und Binnenfunk (UBI) mit Funktexten, Vokabeln und Praxisszenarien – dazu der Funkgerät-Simulator.', 'kurswasser'), ['funk-kombi', 'src', 'ubi']],
        'seenot' => [__('Seenotsignalmittel', 'kurswasser'), __('Sach- und Fachkundenachweis für den Umgang mit Seenotsignalmitteln.', 'kurswasser'), ['skn', 'fkn']],
        'mehr' => [__('Ergänzungen & Gruppen', 'kurswasser'), __('Schon einen Schein? Nur die zusätzlichen Inhalte lernen. Oder gemeinsam als Paar, Familie, Crew oder Verein.', 'kurswasser'), ['ergaenzung', 'gruppen']],
    ];
}

/** Kursübersicht „Alle Kurse“ (/kurse/ und /kurse/weitere/). */
function kurswasser_more_page(): string
{
    $sections = kurswasser_overview_sections();
    $photo = kurswasser_course_image('weitere');
    $html = '<section class="kw-course-hero alignfull"'.($photo ? ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) kurswasser_course_image('weitere', true)).'\')"' : '').'><div class="kw-course-hero-inner"><nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html__('Alle Kurse', 'kurswasser').'</span></nav>';
    $html .= '<div class="kw-course-hero-grid is-single"><div><p class="kw-eyebrow">'.esc_html__('Kursübersicht', 'kurswasser').'</p><h1>'.esc_html__('Alle Kurse', 'kurswasser').'</h1><p class="kw-course-lead">'.esc_html__('Sportbootführerscheine, Funkzeugnisse, Seenotsignalmittel, Ergänzungskurse, Pakete und Gruppen – jeder Kurs mit allen Prüfungsfragen, Prüfungssimulation und den passenden Trainern.', 'kurswasser').'</p>';
    $html .= '<nav class="kw-overview-nav" aria-label="'.esc_attr__('Bereiche', 'kurswasser').'">';
    foreach ($sections as $anchor => [$title]) {
        $html .= '<a href="#'.esc_attr($anchor).'">'.esc_html($title).'</a>';
    }
    $html .= '</nav></div></div></div></section>';
    foreach ($sections as $anchor => [$title, $text, $tiles]) {
        $html .= '<section id="'.esc_attr($anchor).'" class="kw-section kw-overview-section"><div class="kw-overview-head"><h2>'.esc_html($title).'</h2><p class="kw-lead">'.esc_html($text).'</p></div>';
        $html .= '<div class="kw-tiles">'.implode('', array_map('kurswasser_tile', $tiles)).'</div></section>';
    }
    $html .= '<section class="kw-section kw-overview-trust">'.kurswasser_offer_trust().'</section>';

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
}

/** Alte Fassung der Seite „Weitere Kurse“ (nur fehlende Kurse) – nicht mehr verwendet. */
function kurswasser_more_page_legacy(): string
{
    $photo = kurswasser_course_image('weitere');
    $html = '<section class="kw-course-hero alignfull"'.($photo ? ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) kurswasser_course_image('weitere', true)).'\')"' : '').'><div class="kw-course-hero-inner"><nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'"><a href="'.esc_url(home_url('/')).'">'.esc_html__('Start', 'kurswasser').'</a><span aria-hidden="true">/</span><a href="'.esc_url(kurswasser_courses_overview_url()).'">'.esc_html__('Kurse', 'kurswasser').'</a><span aria-hidden="true">/</span><span>'.esc_html__('Weitere Kurse', 'kurswasser').'</span></nav>';
    $html .= '<div class="kw-course-hero-grid is-single"><div><p class="kw-eyebrow">'.esc_html__('Mehr Kurse', 'kurswasser').'</p><h1>'.esc_html__('Weitere Kurse', 'kurswasser').'</h1><p class="kw-course-lead">'.esc_html__('Segeln auf Binnengewässern, Seenotsignalmittel, Ergänzungskurse für Scheininhaber, Pässe für alle Inhalte und Pakete für Gruppen.', 'kurswasser').'</p></div></div></div></section>';
    $html .= '<section class="kw-section">'.do_shortcode('[kurswasser_course_tiles set="more"]').kurswasser_offer_trust().'</section>';

    return $html.'<section class="kw-course-tail">'.do_blocks('<!-- wp:pattern {"slug":"kurswasser/faq"} /-->').'</section>';
}

/** Kursseiten anlegen (Elternseite „Kurse“ + je Kurs), wiederholbar. */
function kurswasser_create_course_pages(): void
{
    $overview = '<!-- wp:shortcode -->[kurswasser_course id="weitere"]<!-- /wp:shortcode -->';
    $parent = get_page_by_path('kurse');
    $parentId = $parent ? (int) $parent->ID : (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'kurse', 'post_title' => __('Alle Kurse', 'kurswasser'), 'post_content' => $overview]);
    if ($parent && trim((string) $parent->post_content) === '<!-- wp:pattern {"slug":"kurswasser/courses"} /-->') {
        wp_update_post(['ID' => $parentId, 'post_content' => $overview, 'post_title' => __('Alle Kurse', 'kurswasser')]);
    }
    if ($parentId) {
        update_post_meta($parentId, '_wp_page_template', 'course');
    }
    $pages = array_map(static fn ($d) => $d['title'], kurswasser_courses()) + ['pakete' => __('Pakete & Pässe', 'kurswasser'), 'weitere' => __('Weitere Kurse', 'kurswasser')];
    foreach ($pages as $slug => $title) {
        if (get_page_by_path('kurse/'.$slug)) {
            continue;
        }
        $name = kurswasser_courses()[$slug]['name'] ?? $title;
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parentId, 'post_name' => $slug, 'post_title' => $name,
            'post_content' => '<!-- wp:shortcode -->[kurswasser_course id="'.$slug.'"]<!-- /wp:shortcode -->']);
        if ($id && ! is_wp_error($id)) {
            update_post_meta((int) $id, '_wp_page_template', 'course');
        }
    }
}
add_action('after_switch_theme', 'kurswasser_create_course_pages');

/**
 * Kursfotos auch im Lernbereich (/bootsscheine/): Kurs-Slugs des Plugins → Bildplätze des Themes.
 */
add_filter('bootspruefung_course_images', static function (array $images): array {
    $map = [
        'sbf-see' => 'sbf-see', 'sbf-bin-motor' => 'sbf-binnen', 'sbf-bin-segel' => 'sbf-binnen-segel', 'sbf-see-bin' => 'sbf-kombi',
        'add-see-to-bin' => 'sbf-binnen', 'add-bin-to-see' => 'sbf-see', 'add-bin-segel' => 'sbf-binnen-segel',
        'src' => 'src', 'ubi' => 'ubi', 'src-ubi' => 'funk-kombi', 'add-ubi-to-src' => 'ubi',
        'skn' => 'skn', 'fkn' => 'fkn', 'add-fkn-to-skn' => 'skn',
    ];
    foreach ($map as $course => $slot) {
        $card = kurswasser_course_image($slot, true);
        $hero = kurswasser_course_image($slot, false);
        if ($card || $hero) {
            $images[$course] = ['card' => $card ?: $hero, 'hero' => $hero ?: $card];
        }
    }

    return $images;
});

/** Enthält das Hauptangebot der Kursseite den Funkgerät-Simulator (Bundle/Pässe) oder nur die Testversion? */
function kurswasser_course_has_sim(array $def): bool
{
    return in_array($def['featured'] ?? '', ['BP-KAPITAEN', 'BP-PASS-1M', 'BP-PASS-3M'], true);
}

/** Funk-Kursseiten: Simulator-Zugänge (Testversion kostenlos, Vollversion als eigener Zugang). */
function kurswasser_sim_section(array $def): string
{
    $codes = $def['codes'] ?? [$def['code'] ?? ''];
    if (array_intersect($codes, ['SRC', 'UBI', 'SRC-UBI']) === [] || kurswasser_course_has_sim($def) || ! kurswasser_learning_compatible()) {
        return '';
    }
    $skus = \Bootspruefung\Learning\Commerce\ShopCatalog::SIM_PASSES;
    if (kurswasser_offer($skus[0]) === null) {
        return '';
    }
    $html = '<section id="simulator" class="kw-section kw-course-offers kw-sim"><div class="kw-center-head"><p class="kw-eyebrow">'.esc_html__('Praxis am Funkgerät', 'kurswasser').'</p>';
    $html .= '<h2>'.esc_html__('Funkgerät-Simulator für die praktische Prüfung', 'kurswasser').'</h2>';
    $html .= '<p class="kw-lead">'.esc_html__('Kostenlos testen: Gerät bedienen, Kanäle wählen und einen Routineanruf üben. Die Vollversion schaltet Notalarm (DISTRESS), Dringlichkeits- und Sicherheitsverkehr, alle Lektionen und den Prüfungsmodus frei.', 'kurswasser').'</p></div>';
    $html .= kurswasser_offer_cards($skus, 'SIM-PASS-3M', [], __('Empfehlung', 'kurswasser'));
    $html .= '<ul class="kw-offers-trust"><li><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html__('Läuft im Browser – ohne Installation', 'kurswasser').'</li><li><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html__('Ein Login für PC, Tablet und Smartphone', 'kurswasser').'</li><li><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html__('Einmalzahlung, kein Abo', 'kurswasser').'</li></ul>';
    $html .= '<p class="kw-sim-note">'.esc_html__('Beim Kauf eines Funkkurses gibt es den Simulator im Checkout als günstiges Upgrade. Für Simulator-Zugänge gilt keine Geld-zurück-Garantie.', 'kurswasser').'</p>';

    return $html.'</section>';
}
