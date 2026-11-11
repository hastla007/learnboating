<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Shortcode [bootspruefung_faq rubric="" theme_only="0" subcategory="" ids="" limit="" title="" search="1" schema="1"].
 *
 * ids="12,31,40": genau diese FAQs in dieser Reihenfolge – Nummern aus den Standard-FAQs (ref); eine Zahl ohne
 * passende Nummer gilt als Beitrags-ID. Mit ids erscheint eine flache Liste ohne Rubrik- und Unterkategorie-
 * Überschriften (kuratierter Auszug); rubric, subcategory und theme_only filtern zusätzlich. limit="5" begrenzt
 * die Zahl der Fragen (auch ohne ids). Ohne beide Attribute bleibt alles wie bisher.
 *
 * Ohne Attribute: ganze FAQ-Sektion mit Rubrik-Navigation (Anker), je Rubrik eine Überschrift,
 * Unterkategorien als Zwischenüberschriften und die FAQs als native <details>. Optional eine
 * Suchzeile (kleines Inline-JS, ohne JS bleibt sie ausgeblendet und alles sichtbar) und JSON-LD
 * „FAQPage“ mit den ausgegebenen FAQs – schema="0" schaltet es ab (FAQ-Auszüge auf anderen Seiten; das
 * Markup gehört nur auf die FAQ-Seite). Mit dem Theme Kurswasser übernimmt es dessen FAQ-Optik.
 */
final class FaqShortcode
{
    private const STYLE = 'bootspruefung-faq';
    private static int $instance = 0;
    private static bool $scriptPrinted = false;

    /** @param array<string,string>|string $atts */
    public static function render($atts = []): string
    {
        $atts = shortcode_atts(['rubric' => '', 'theme_only' => '0', 'subcategory' => '', 'ids' => '', 'limit' => '', 'title' => '', 'search' => '1', 'schema' => '1'], (array) $atts, 'bootspruefung_faq');
        $faqs = FaqRepository::query(['rubric' => (string) $atts['rubric'], 'theme_only' => $atts['theme_only'], 'subcategory' => (string) $atts['subcategory']]);
        $ids = self::ids((string) $atts['ids']);
        if ($ids !== []) {
            $faqs = self::pick($faqs, $ids);
        }
        $limit = (int) $atts['limit'];
        if ($limit > 0) {
            $faqs = array_slice($faqs, 0, $limit);
        }
        if ($faqs === []) {
            return '';
        }
        self::enqueueStyle();
        $n = ++self::$instance;
        $prefix = 'bp-faq'.($n > 1 ? '-'.$n : '');
        $title = trim((string) $atts['title']);
        $search = filter_var($atts['search'], FILTER_VALIDATE_BOOLEAN);

        // Gruppieren: Rubrik → Unterkategorie (Reihenfolge kommt sortiert aus dem Repository).
        $rubrics = [];
        foreach ($faqs as $faq) {
            $r = $faq['rubric'] !== '' ? $faq['rubric'] : '_';
            $rubrics[$r] ??= ['label' => $faq['rubric_label'] !== '' ? $faq['rubric_label'] : __('Weitere Fragen', 'bootspruefung-learning'), 'groups' => []];
            $rubrics[$r]['groups'][$faq['subcategory']] ??= ['label' => $faq['subcategory_label'], 'faqs' => []];
            $rubrics[$r]['groups'][$faq['subcategory']]['faqs'][] = $faq;
        }
        // Überschriften-Hierarchie: Titel (h2) → Rubrik → Unterkategorie, jeweils nur wenn sinnvoll.
        $level = 2;
        $titleLevel = $title !== '' ? $level++ : 0;
        $flat = $ids !== [];
        if ($flat) {
            // Kuratierter Auszug: eine Liste in der gewünschten Reihenfolge, ohne Rubrik-Überschriften.
            $rubrics = ['_' => ['label' => '', 'groups' => ['' => ['label' => '', 'faqs' => $faqs]]]];
        }
        $showRubric = ! $flat && (count($rubrics) > 1 || $title === '');
        $rubricLevel = $showRubric ? $level++ : 0;
        $subLevel = min(6, $level);
        $showSub = ! $flat && trim((string) $atts['subcategory']) === '';

        $themed = self::themeActive();
        $classes = 'bp-faq'.($themed ? ' kw-section kw-faq' : '');
        $html = '<section class="'.esc_attr($classes).'" id="'.esc_attr($prefix).'"'.($title !== '' ? ' aria-labelledby="'.esc_attr($prefix).'-title"' : ' aria-label="'.esc_attr__('Häufige Fragen', 'bootspruefung-learning').'"').' data-bp-faq>';
        if ($title !== '') {
            $html .= sprintf('<h%1$d id="%2$s-title" class="bp-faq-title">%3$s</h%1$d>', $titleLevel, esc_attr($prefix), esc_html($title));
        }
        if ($search) {
            $html .= '<div class="bp-faq-search" role="search" hidden><label for="'.esc_attr($prefix).'-q">'.esc_html__('Fragen durchsuchen', 'bootspruefung-learning').'</label>'
                .'<input type="search" id="'.esc_attr($prefix).'-q" autocomplete="off" placeholder="'.esc_attr__('Stichwort, z. B. Prüfung', 'bootspruefung-learning').'">'
                .'<p class="bp-faq-status" role="status" aria-live="polite"></p></div>';
        }
        if (count($rubrics) > 1) {
            $html .= '<nav class="bp-faq-nav" aria-label="'.esc_attr__('FAQ-Rubriken', 'bootspruefung-learning').'"><ul>';
            foreach ($rubrics as $key => $rubric) {
                $html .= '<li><a href="#'.esc_attr(self::anchor($prefix, (string) $key)).'">'.esc_html($rubric['label']).'</a></li>';
            }
            $html .= '</ul></nav>';
        }
        foreach ($rubrics as $key => $rubric) {
            $id = self::anchor($prefix, (string) $key);
            $html .= '<section class="bp-faq-rubric" id="'.esc_attr($id).'"'.($showRubric ? ' aria-labelledby="'.esc_attr($id).'-h"' : '').' data-bp-faq-block>';
            if ($showRubric) {
                $html .= sprintf('<h%1$d id="%2$s-h" class="bp-faq-rubric-title">%3$s</h%1$d>', $rubricLevel, esc_attr($id), esc_html($rubric['label']));
            }
            foreach ($rubric['groups'] as $group) {
                $html .= '<div class="bp-faq-group" data-bp-faq-block>';
                if ($showSub && $group['label'] !== '') {
                    $html .= sprintf('<h%1$d class="bp-faq-group-title">%2$s</h%1$d>', $subLevel, esc_html($group['label']));
                }
                foreach ($group['faqs'] as $faq) {
                    // answer_html ist bereits per wp_kses_post gefiltert (FaqRepository).
                    $html .= '<details class="wp-block-details" data-bp-faq-item><summary>'.esc_html($faq['question']).'</summary>'.$faq['answer_html'].'</details>';
                }
                $html .= '</div>';
            }
            $html .= '</section>';
        }
        if ($search) {
            $html .= '<p class="bp-faq-empty" hidden>'.esc_html__('Keine passende Frage gefunden.', 'bootspruefung-learning').'</p>';
        }
        if (filter_var($atts['schema'], FILTER_VALIDATE_BOOLEAN)) {
            $html .= self::jsonLd($faqs);
        }
        $html .= '</section>';
        if ($search && ! self::$scriptPrinted) {
            self::$scriptPrinted = true;
            $html .= self::script();
        }

        return $html;
    }

    /** JSON-LD „FAQPage“ mit genau den ausgegebenen FAQs. @param list<array<string,mixed>> $faqs */
    public static function jsonLd(array $faqs): string
    {
        $allowed = ['a' => ['href' => true], 'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'h2' => [], 'h3' => [], 'h4' => []];
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn ($faq) => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(wp_kses((string) $faq['answer_html'], $allowed))],
            ], $faqs),
        ];

        return '<script type="application/ld+json">'.wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP).'</script>';
    }

    /** Zahlen aus ids="12, 31,40" (Reihenfolge bleibt, doppelte entfallen). @return list<int> */
    private static function ids(string $value): array
    {
        $ids = array_map('intval', preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * FAQs zu den Nummern bzw. IDs in genau dieser Reihenfolge; unbekannte Nummern entfallen.
     *
     * @param list<array<string,mixed>> $faqs
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private static function pick(array $faqs, array $ids): array
    {
        $byRef = [];
        $byId = [];
        foreach ($faqs as $faq) {
            if ($faq['ref'] !== null) {
                $byRef[(int) $faq['ref']] ??= $faq;
            }
            $byId[(int) $faq['id']] = $faq;
        }
        $out = [];
        foreach ($ids as $id) {
            $faq = $byRef[$id] ?? $byId[$id] ?? null;
            if ($faq !== null && ! isset($out[(int) $faq['id']])) {
                $out[(int) $faq['id']] = $faq;
            }
        }

        return array_values($out);
    }

    private static function anchor(string $prefix, string $key): string
    {
        return $prefix.'-'.($key === '_' ? 'weitere' : sanitize_title($key));
    }

    private static function themeActive(): bool
    {
        return get_template() === 'kurswasser' || function_exists('kurswasser_course_faq');
    }

    /** Eigene, zurückhaltende Gestaltung – nur auf Seiten mit dem Shortcode. */
    private static function enqueueStyle(): void
    {
        if (! wp_style_is(self::STYLE, 'registered')) {
            wp_register_style(self::STYLE, false, [], \Bootspruefung\Learning\Plugin::VERSION);
            wp_add_inline_style(self::STYLE, self::css());
        }
        wp_enqueue_style(self::STYLE);
        if (self::themeActive()) {
            // Rahmen und Abstände der <details> stammen aus theme.json (Stil des Blocks core/details).
            wp_enqueue_style('wp-block-details');
        }
    }

    private static function css(): string
    {
        return '.bp-faq{--bp-faq-line:var(--kw-line,#d6dde4);--bp-faq-accent:var(--kw-blue-dark,#0b5c8a);--bp-faq-muted:var(--kw-muted,#4b5661)}'
            .'.bp-faq [hidden]{display:none!important}'
            // Mit Theme: Seitenabstand oben liefert schon die Seite; unten bleibt der Abschnittsabstand.
            .'.bp-faq.kw-section{padding-top:var(--wp--preset--spacing--30,1rem)}'
            .'.bp-faq-nav ul{display:flex;flex-wrap:wrap;gap:.5rem;list-style:none;margin:0 0 1.5rem;padding:0}'
            .'.bp-faq-nav a{display:inline-block;padding:.35rem .85rem;border:1px solid var(--bp-faq-line);border-radius:999px;text-decoration:none;line-height:1.4}'
            .'.bp-faq-nav a:hover{border-color:var(--bp-faq-accent)}'
            .'.bp-faq a:focus-visible,.bp-faq summary:focus-visible,.bp-faq input:focus-visible{outline:2px solid var(--bp-faq-accent);outline-offset:3px}'
            .'.bp-faq-rubric{scroll-margin-top:6rem;margin-top:2.5rem}.bp-faq-rubric:first-of-type{margin-top:1rem}'
            .'.bp-faq-group-title{margin:1.75rem 0 .25rem;font-size:1.05rem}'
            .'.bp-faq-search{margin:0 0 1.5rem}.bp-faq-search label{display:block;font-weight:600;margin-bottom:.35rem}'
            .'.bp-faq-search input{box-sizing:border-box;width:100%;max-width:30rem;padding:.6rem .8rem;font:inherit;border:1px solid var(--bp-faq-line);border-radius:.5rem}'
            .'.bp-faq-status{margin:.35rem 0 0;font-size:.9rem;color:var(--bp-faq-muted);min-height:1.2em}'
            // Ohne Theme Kurswasser: schlichte Akkordeons.
            .'.bp-faq:not(.kw-faq) details{border-bottom:1px solid var(--bp-faq-line);padding:.85rem 0}'
            .'.bp-faq:not(.kw-faq) summary{cursor:pointer;font-weight:600}'
            .'.bp-faq:not(.kw-faq) details>:not(summary){color:var(--bp-faq-muted);margin:.5rem 0 0}';
    }

    /** Filtert die FAQs im Browser; ohne JavaScript bleibt die Suche ausgeblendet und alles sichtbar. */
    private static function script(): string
    {
        $one = esc_js(__('1 Frage gefunden', 'bootspruefung-learning'));
        $many = esc_js(__('%d Fragen gefunden', 'bootspruefung-learning'));

        return "<script>(function(){document.querySelectorAll('[data-bp-faq]').forEach(function(root){var box=root.querySelector('.bp-faq-search');if(!box){return;}var input=box.querySelector('input'),status=box.querySelector('.bp-faq-status'),empty=root.querySelector('.bp-faq-empty'),items=root.querySelectorAll('[data-bp-faq-item]');box.hidden=false;"
            ."var norm=function(s){return s.toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g,'');};"
            ."input.addEventListener('input',function(){var words=norm(input.value.trim()).split(/\\s+/).filter(Boolean),count=0;items.forEach(function(d){var t=norm(d.textContent),hit=words.every(function(w){return t.indexOf(w)>-1;});d.hidden=!hit;if(hit){count++;}});"
            ."root.querySelectorAll('[data-bp-faq-block]').forEach(function(b){b.hidden=!b.querySelector('[data-bp-faq-item]:not([hidden])');});"
            ."empty.hidden=count>0;status.textContent=words.length?(count===1?'{$one}':'{$many}'.replace('%d',count)):'';});});})();</script>";
    }
}
