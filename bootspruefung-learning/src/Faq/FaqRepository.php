<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Liest veröffentlichte FAQs sortiert nach Rubrik, Unterkategorie und menu_order.
 *
 * Zwischenspeicher: Objekt-Cache-Gruppe „bp_faq“ mit last_changed-Schlüssel; Faq leert ihn beim
 * Speichern, Löschen, Umsortieren usw. Gespeichert wird die gefilterte Antwort mit Platzhaltern
 * ({{agb}}, {{link:/pfad/|Text}}); ersetzt werden sie erst beim Abruf, damit z. B. ein neuer AGB-Link sofort wirkt.
 * Antworten mit dynamischen Blöcken (z. B. „Nur mit Lernzugang“) liegen als Rohinhalt im Speicher und werden erst
 * beim Ausgeben gerendert – je Person (Gast, Käufer) richtig, Shortcode/Theme wie REST/App (T116).
 */
final class FaqRepository
{
    /** @var list<string> Felder je FAQ (Theme-Funktion, Shortcode, REST) */
    public const FIELDS = ['id', 'ref', 'question', 'answer_html', 'rubric', 'rubric_label', 'subcategory', 'subcategory_label', 'theme'];

    /**
     * @param array{rubric?:string,theme_only?:bool|string|int,subcategory?:string} $args
     * @return list<array{id:int,ref:?int,question:string,answer_html:string,rubric:string,rubric_label:string,subcategory:string,subcategory_label:string,theme:bool}>
     */
    public static function query(array $args = []): array
    {
        $rubric = trim((string) ($args['rubric'] ?? ''));
        $themeOnly = filter_var($args['theme_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sub = sanitize_title((string) ($args['subcategory'] ?? ''));
        $tokens = self::tokens();
        $out = [];
        foreach (self::all() as $row) {
            if ($rubric !== '' && strcasecmp($row['rubric'], $rubric) !== 0 && $row['rubric_slug'] !== sanitize_title($rubric)) {
                continue;
            }
            if ($themeOnly && ! $row['theme']) {
                continue;
            }
            if ($sub !== '' && $row['subcategory'] !== $sub) {
                continue;
            }
            $row['answer_html'] = self::links(strtr(self::answerHtml($row), $tokens));
            $out[] = array_intersect_key($row, array_flip(self::FIELDS));
        }

        return $out;
    }

    /** Rubriken in Ausgabereihenfolge. @return list<array{key:string,slug:string,label:string}> */
    public static function rubrics(): array
    {
        $list = [];
        foreach (self::terms() as $term) {
            if ($term['parent'] === 0) {
                $list[] = ['key' => $term['key'], 'slug' => $term['slug'], 'label' => $term['name'], 'order' => $term['order']];
            }
        }
        usort($list, static fn ($a, $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);

        return array_map(static fn ($r) => ['key' => $r['key'], 'slug' => $r['slug'], 'label' => $r['label']], $list);
    }

    public static function flush(): void
    {
        wp_cache_set_last_changed(Faq::CACHE_GROUP);
    }

    /** Antwort-HTML wie beim Ausgeben: Blöcke rendern, Platzhalter ersetzen, wp_kses_post. */
    public static function renderAnswer(string $content): string
    {
        return self::links(strtr(self::filterAnswer($content), self::tokens()));
    }

    /**
     * {{link:/pfad/|Text}} → Link auf eine Seite dieser Website (home_url, auch bei Installation im Unterordner).
     * Der Text ist bereits maskiert (Import bzw. wp_kses_post). Nur Pfade mit „/“ am Anfang.
     */
    public static function links(string $html): string
    {
        if (! str_contains($html, '{{link:')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#\{\{link:(/[a-z0-9/._\-]*(?:\#[a-z0-9_\-]+)?)\|([^{}]+)\}\}#i',
            static fn (array $m): string => '<a href="'.esc_url(home_url($m[1])).'">'.$m[2].'</a>',
            $html
        );
    }

    /** Ersetzungen für Platzhalter in Antworten. {{agb}} = Link auf die WooCommerce-AGB-Seite, sonst nur „AGB“. @return array<string,string> */
    public static function tokens(): array
    {
        $label = esc_html__('AGB', 'bootspruefung-learning');
        $id = function_exists('wc_terms_and_conditions_page_id') ? (int) wc_terms_and_conditions_page_id() : (int) get_option('woocommerce_terms_page_id');
        $agb = $label;
        if ($id > 0 && get_post_status($id) === 'publish' && ($url = get_permalink($id))) {
            $agb = '<a href="'.esc_url($url).'">'.$label.'</a>';
        }

        /** @param array<string,string> $tokens Platzhalter => HTML (bereits escaped) */
        return (array) apply_filters('bootspruefung_faq_tokens', ['{{agb}}' => $agb]);
    }

    /** Antwort-HTML filtern (Blöcke rendern, wp_kses_post), Platzhalter bleiben stehen – auch für die Kunden-FAQ. */
    public static function filterAnswer(string $content): string
    {
        $html = has_blocks($content) ? do_blocks($content) : wpautop($content);

        return trim(wp_kses_post($html));
    }

    /**
     * Antwort für den Zwischenspeicher (T116): ohne dynamische Blöcke fertig gefiltert (answer_html), mit dynamischen
     * Blöcken nur der Rohinhalt (answer_raw) – gerendert wird dann erst beim Ausgeben (answerHtml()), denn das Ergebnis
     * hängt von der Person ab (Block „Nur mit Lernzugang“) oder ändert sich laufend.
     *
     * @return array{answer_html:string,answer_raw:?string}
     */
    public static function cacheableAnswer(string $content): array
    {
        return self::hasDynamicBlocks($content)
            ? ['answer_html' => '', 'answer_raw' => $content]
            : ['answer_html' => self::filterAnswer($content), 'answer_raw' => null];
    }

    /** Gefilterte Antwort eines gespeicherten Eintrags (dynamische Blöcke jetzt rendern). @param array<string,mixed> $row */
    public static function answerHtml(array $row): string
    {
        return isset($row['answer_raw']) && is_string($row['answer_raw']) ? self::filterAnswer($row['answer_raw']) : (string) ($row['answer_html'] ?? '');
    }

    /** Enthält der Inhalt dynamische (serverseitig gerenderte) oder unbekannte Blöcke? */
    public static function hasDynamicBlocks(string $content): bool
    {
        if (! has_blocks($content)) {
            return false;
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        $walk = static function (array $blocks) use (&$walk, $registry): bool {
            foreach ($blocks as $block) {
                $name = $block['blockName'] ?? null;
                if (is_string($name) && $name !== '') {
                    $type = $registry->get_registered($name);
                    // Unbekannt (noch nicht registriert) gilt vorsichtshalber als dynamisch.
                    if ($type === null || $type->is_dynamic()) {
                        return true;
                    }
                }
                if (! empty($block['innerBlocks']) && $walk($block['innerBlocks'])) {
                    return true;
                }
            }

            return false;
        };

        return $walk(parse_blocks($content));
    }

    /** @return list<array<string,mixed>> alle veröffentlichten FAQs (sortiert, Platzhalter noch nicht ersetzt) */
    private static function all(): array
    {
        // „v2“: Einträge mit answer_raw (T116); ältere Einträge im persistenten Objekt-Cache werden nicht mehr gelesen.
        $key = 'all-v2:'.wp_cache_get_last_changed(Faq::CACHE_GROUP);
        $cached = wp_cache_get($key, Faq::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }
        $terms = self::terms();
        $posts = get_posts([
            'post_type' => Faq::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'], 'suppress_filters' => true,
        ]);
        $rows = [];
        foreach ($posts as $post) {
            $assigned = get_the_terms($post, Faq::TAXONOMY); // Term-Cache ist durch get_posts() schon gefüllt
            [$rubric, $sub] = self::placement(is_array($assigned) ? wp_list_pluck($assigned, 'term_id') : [], $terms);
            $ref = get_post_meta($post->ID, Faq::META_REF, true);
            $answer = self::cacheableAnswer((string) $post->post_content);
            $rows[] = [
                'id' => (int) $post->ID,
                'ref' => $ref === '' ? null : (int) $ref,
                'question' => html_entity_decode(wp_strip_all_tags((string) $post->post_title), ENT_QUOTES, 'UTF-8'),
                'answer_html' => $answer['answer_html'],
                'answer_raw' => $answer['answer_raw'],
                'rubric' => $rubric['key'] ?? '',
                'rubric_slug' => $rubric['slug'] ?? '',
                'rubric_label' => $rubric['name'] ?? '',
                'subcategory' => $sub['slug'] ?? '',
                'subcategory_label' => $sub['name'] ?? '',
                'theme' => (bool) get_post_meta($post->ID, Faq::META_THEME, true),
                // FAQs ohne Rubrik ans Ende, ohne Unterkategorie an den Anfang ihrer Rubrik.
                'sort' => [$rubric === null ? PHP_INT_MAX : $rubric['order'], $rubric['name'] ?? '', $sub === null ? PHP_INT_MIN : $sub['order'], $sub['name'] ?? '', (int) $post->menu_order, (int) $post->ID],
            ];
        }
        usort($rows, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        foreach ($rows as &$row) {
            unset($row['sort']);
        }
        unset($row);
        wp_cache_set($key, $rows, Faq::CACHE_GROUP, DAY_IN_SECONDS);

        return $rows;
    }

    /**
     * Rubrik = oberste Ebene, Unterkategorie = darunter. Mehrere Kategorien: die erste nach Reihenfolge.
     *
     * @param list<int> $ids
     * @param array<int,array{id:int,parent:int,slug:string,name:string,key:string,order:int}> $terms
     * @return array{0:?array,1:?array}
     */
    private static function placement(array $ids, array $terms): array
    {
        $best = null;
        foreach ($ids as $id) {
            $term = $terms[(int) $id] ?? null;
            if ($term === null) {
                continue;
            }
            $top = $term;
            for ($i = 0; $top['parent'] !== 0 && isset($terms[$top['parent']]) && $i < 10; $i++) {
                $top = $terms[$top['parent']];
            }
            $candidate = [$top, $term['id'] === $top['id'] ? null : $term];
            $rank = [$top['order'], $candidate[1] === null ? 1 : 0, $candidate[1]['order'] ?? 0];
            if ($best === null || $rank < $best[0]) {
                $best = [$rank, $candidate];
            }
        }

        return $best[1] ?? [null, null];
    }

    /** @return array<int,array{id:int,parent:int,slug:string,name:string,key:string,order:int}> */
    private static function terms(): array
    {
        $key = 'terms:'.wp_cache_get_last_changed(Faq::CACHE_GROUP);
        $cached = wp_cache_get($key, Faq::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }
        $map = FaqTerms::map(Faq::TAXONOMY, Faq::TERM_KEY, Faq::TERM_ORDER);
        wp_cache_set($key, $map, Faq::CACHE_GROUP, DAY_IN_SECONDS);

        return $map;
    }
}
