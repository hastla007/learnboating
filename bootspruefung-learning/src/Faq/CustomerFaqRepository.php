<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

use Bootspruefung\Learning\Gate\SectionFiles;

/**
 * Liest veröffentlichte Kunden-FAQs für einen Kurs, gruppiert nach Schein und Unterkategorie.
 *
 * Scheine eines Kurses: die Kategorie mit dem Kurscode selbst und – wie bei den Kursdateien
 * (SectionFiles::PARTS) – die Teilscheine eines Kombikurses bzw. der Zielschein einer Ergänzung,
 * z. B. SBF-SEE-BIN → SBF-SEE + SBF-BIN-MOTOR, ADD-BIN-TO-SEE → SBF-SEE.
 *
 * Form je Schein: {id, key, name, slug, faqs (direkt am Schein), subcategories}. subcategories ist
 * die Unterkategorien-Hierarchie in Ausgabereihenfolge (Tiefensuche, je Ebene nach bp_cfaq_order, dann
 * Name) als flache Liste mit parent (Term-ID; direkt unter dem Schein = dessen ID) und depth (1 = direkt
 * unter dem Schein). Unterkategorien ohne FAQs (auch in ihren Unterkategorien) und Scheine ohne FAQs
 * entfallen. FAQs: {id, question, answer_html}, sortiert nach menu_order und ID.
 *
 * Gemeinsame FAQs: Eine FAQ kann mehreren Kategorien zugeordnet sein (auch Unterkategorien verschiedener Scheine,
 * z. B. „Lernen im Kurs“ aller Scheine). Sie erscheint in jedem zugeordneten Schein – pro Kurs aber nur einmal:
 * im ersten Schein in der Reihenfolge von keysFor() (Kurs selbst, dann Teil- bzw. Zielscheine wie in
 * SectionFiles::PARTS) und dort an der ersten Stelle (Tiefensuche). Im Kombikurs SBF See & Binnen steht eine FAQ
 * für SBF See und SBF Binnen also nur unter SBF See; bleibt ein Schein dadurch leer, entfällt er. Web-REST, App
 * (FaqController) und Web-App (Kurs-Hub) nutzen diese Gruppen, die Anzahl (count) zählt jede FAQ einmal.
 *
 * Zwischenspeicher wie FaqRepository (Gruppe „bp_cfaq“, last_changed); Antworten werden mit wp_kses_post
 * gefiltert gespeichert, Platzhalter ({{agb}}, {{link:/pfad/|Text}}) erst beim Abruf ersetzt.
 */
final class CustomerFaqRepository
{
    private const MAX_DEPTH = 10;

    /** Schein-Schlüssel eines Kurses: der Kurs selbst, dann Teil- bzw. Zielscheine. @return list<string> */
    public static function keysFor(string $courseCode): array
    {
        $code = strtoupper($courseCode);

        return array_values(array_unique(array_merge([$code], SectionFiles::PARTS[$code] ?? [])));
    }

    /**
     * @return list<array{id:int,key:string,name:string,slug:string,faqs:list<array{id:int,question:string,answer_html:string}>,subcategories:list<array{id:int,name:string,slug:string,parent:int,depth:int,faqs:list<array{id:int,question:string,answer_html:string}>}>}>
     */
    public static function forCourse(string $courseCode): array
    {
        $tokens = FaqRepository::tokens();
        $render = static function (array $faqs) use ($tokens): array {
            return array_map(static fn (array $f): array => ['id' => $f['id'], 'question' => $f['question'], 'answer_html' => FaqRepository::links(strtr($f['answer_html'], $tokens))], $faqs);
        };
        $groups = self::groups($courseCode);
        foreach ($groups as &$group) {
            $group['faqs'] = $render($group['faqs']);
            foreach ($group['subcategories'] as &$sub) {
                $sub['faqs'] = $render($sub['faqs']);
            }
            unset($sub);
        }
        unset($group);

        return $groups;
    }

    /** Anzahl der FAQs, die ein Kunde des Kurses sieht (ohne Platzhalter zu ersetzen). */
    public static function count(string $courseCode): int
    {
        $n = 0;
        foreach (self::groups($courseCode) as $group) {
            $n += count($group['faqs']);
            foreach ($group['subcategories'] as $sub) {
                $n += count($sub['faqs']);
            }
        }

        return $n;
    }

    public static function flush(): void
    {
        wp_cache_set_last_changed(CustomerFaq::CACHE_GROUP);
    }

    /** @return list<array<string,mixed>> Gruppen mit gefilterten Antworten (Platzhalter noch nicht ersetzt) */
    private static function groups(string $courseCode): array
    {
        ['terms' => $terms, 'children' => $children, 'faqs' => $byTerm] = self::data();
        $out = [];
        $usedTops = [];
        $seen = []; // FAQ-IDs, die der Kurs schon zeigt (über alle Scheine hinweg)
        foreach (self::keysFor($courseCode) as $key) {
            $top = null;
            foreach ($children[0] ?? [] as $id) {
                if (strcasecmp($terms[$id]['key'], $key) === 0) {
                    $top = $terms[$id];
                    break;
                }
            }
            if ($top === null || isset($usedTops[$top['id']])) {
                continue;
            }
            $usedTops[$top['id']] = true;
            $take = static function (int $termId) use ($byTerm, &$seen): array {
                $list = [];
                foreach ($byTerm[$termId] ?? [] as $faq) {
                    if (! isset($seen[$faq['id']])) {
                        $seen[$faq['id']] = true;
                        $list[] = $faq;
                    }
                }

                return $list;
            };
            $direct = $take($top['id']);
            $subs = [];
            $walk = static function (int $parent, int $depth) use (&$walk, &$subs, $children, $terms, $take): bool {
                $any = false;
                foreach ($children[$parent] ?? [] as $id) {
                    if ($depth > self::MAX_DEPTH) {
                        break;
                    }
                    $at = count($subs);
                    $subs[] = ['id' => $id, 'name' => $terms[$id]['name'], 'slug' => $terms[$id]['slug'], 'parent' => $parent, 'depth' => $depth, 'faqs' => $take($id)];
                    $below = $walk($id, $depth + 1);
                    if ($subs[$at]['faqs'] === [] && ! $below) {
                        array_splice($subs, $at, 1); // leer, auch darunter
                        continue;
                    }
                    $any = true;
                }

                return $any;
            };
            $walk($top['id'], 1);
            if ($direct === [] && $subs === []) {
                continue;
            }
            $out[] = ['id' => $top['id'], 'key' => $top['key'], 'name' => $top['name'], 'slug' => $top['slug'], 'faqs' => $direct, 'subcategories' => $subs];
        }

        return $out;
    }

    /**
     * Begriffe, Kinder je Begriff (sortiert) und veröffentlichte FAQs je zugewiesenem Begriff (sortiert).
     *
     * @return array{terms:array<int,array<string,mixed>>,children:array<int,list<int>>,faqs:array<int,list<array{id:int,question:string,answer_html:string}>>}
     */
    private static function data(): array
    {
        $key = 'data:'.wp_cache_get_last_changed(CustomerFaq::CACHE_GROUP);
        $cached = wp_cache_get($key, CustomerFaq::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }
        $terms = FaqTerms::map(CustomerFaq::TAXONOMY, CustomerFaq::TERM_KEY, CustomerFaq::TERM_ORDER);
        $children = [];
        foreach ($terms as $id => $term) {
            // Verwaiste Begriffe (Eltern gelöscht) hängen wie WordPress an der obersten Ebene.
            $parent = isset($terms[$term['parent']]) ? $term['parent'] : 0;
            $children[$parent][] = $id;
        }
        foreach ($children as &$ids) {
            usort($ids, static fn ($a, $b) => [$terms[$a]['order'], $terms[$a]['name'], $a] <=> [$terms[$b]['order'], $terms[$b]['name'], $b]);
        }
        unset($ids);
        $posts = get_posts([
            'post_type' => CustomerFaq::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'no_found_rows' => true,
            'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'], 'suppress_filters' => true,
        ]);
        $faqs = [];
        foreach ($posts as $post) {
            $assigned = get_the_terms($post, CustomerFaq::TAXONOMY);
            $row = [
                'id' => (int) $post->ID,
                'question' => html_entity_decode(wp_strip_all_tags((string) $post->post_title), ENT_QUOTES, 'UTF-8'),
                'answer_html' => FaqRepository::filterAnswer((string) $post->post_content),
            ];
            foreach (is_array($assigned) ? $assigned : [] as $term) {
                if (isset($terms[(int) $term->term_id])) {
                    $faqs[(int) $term->term_id][] = $row;
                }
            }
        }
        $data = ['terms' => $terms, 'children' => $children, 'faqs' => $faqs];
        wp_cache_set($key, $data, CustomerFaq::CACHE_GROUP, DAY_IN_SECONDS);

        return $data;
    }
}
