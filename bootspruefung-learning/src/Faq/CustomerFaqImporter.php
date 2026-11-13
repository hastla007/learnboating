<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Legt die Startinhalte der Kunden-FAQ an (DefaultCustomerFaqs): je Schein eine Kategorie (Schlüssel =
 * Kurscode) mit den Start-Unterkategorien und das Startpaket an FAQs – alle als Entwurf. Eine gemeinsame
 * FAQ wird einmal angelegt und allen ihren Kategorien zugeordnet (placements).
 *
 * Wie FaqImporter automatisch bei Aktivierung bzw. nach einem Update, sobald der Datenstand (VERSION) nicht
 * zur gespeicherten Option bp_learning_cfaq_version passt (eigener Datenstand, unabhängig von den öffentlichen
 * FAQs). Gibt es noch keine einzige Kunden-FAQ, wird alles angelegt; sonst läuft upgrade():
 *
 * 1. Datenstand 1 → 2: mergeCopies() führt die Kopien gemeinsamer Fragen zusammen (Regeln dort).
 * 2. Neue Start-FAQs eines späteren Datenstands (since > gespeicherter Stand) kommen als Entwurf dazu.
 * 3. updateDefaults(): Start-FAQs, deren Frage und Antwort noch der Prüfsumme _bp_cfaq_default_hash entsprechen
 *    (vom Betreiber nicht bearbeitet), bekommen den aktuellen Text und Redaktionshinweis. Status, Kategorien
 *    und Reihenfolge bleiben; bearbeitete FAQs und FAQs im Papierkorb bleiben unberührt.
 *
 * Das Ergebnis steht in der Option bp_learning_cfaq_report und erscheint als Hinweis über der Liste
 * (CustomerFaqAdmin), bis es ausgeblendet wird. Eine Nummer, die schon existiert (jeder Status, auch Papierkorb),
 * legt der Import nie neu an; eine gemeinsame FAQ auch dann nicht, wenn noch eine ihrer früheren Kopien existiert.
 * Kategorien werden über Schlüssel bzw. Slug wiedergefunden, ihre Merkmale nur ergänzt, nie überschrieben.
 */
final class CustomerFaqImporter
{
    /**
     * 3: Antwort von FAQ 1 (Smarttrainer: „sicher heute“, gefestigt an einem anderen Tag).
     * 4: FAQ 3 (Verlängern zur gekauften Stufe, Erinnerung abbestellen), Prüfungssimulation (abbrechen), FAQ 103
     *    (Punktestand der Navigationsaufgaben). Unbearbeitete FAQs werden jeweils nachgezogen.
     */
    public const VERSION = '4';
    public const OPTION_VERSION = 'bp_learning_cfaq_version';
    /** Ergebnis des letzten automatischen Updates (Admin-Hinweis). */
    public const OPTION_REPORT = 'bp_learning_cfaq_report';
    /** Prüfsumme des zuletzt eingespielten Standardstands (Frage + Antwort, FaqImporter::hash). */
    public const META_DEFAULT_HASH = '_bp_cfaq_default_hash';
    /** Nummern der Kopien aus Datenstand 1, aus denen eine gemeinsame FAQ zusammengeführt wurde (z. B. „101,201“). */
    public const META_MERGED_FROM = '_bp_cfaq_merged_from';
    private const OPTION_LOCK = 'bp_learning_cfaq_import_lock';
    private const LOCK_TTL = 300;
    /** Datenstand, mit dem die gemeinsamen FAQs die Kopien je Schein ersetzt haben. */
    private const SHARED_SINCE = '2';
    private const LIVE = ['publish', 'draft', 'pending', 'private', 'future'];

    public static function needsImport(): bool
    {
        return get_option(self::OPTION_VERSION) !== self::VERSION;
    }

    public static function maybeImport(): void
    {
        if (! self::needsImport() || ! post_type_exists(CustomerFaq::POST_TYPE) || ! self::acquireLock()) {
            return;
        }
        try {
            if (! self::hasAny()) {
                self::import();
            } else {
                $report = self::upgrade((string) get_option(self::OPTION_VERSION));
                if ($report['merged'] !== [] || $report['not_merged'] !== [] || $report['added'] !== [] || $report['updated'] !== [] || $report['kept'] !== []) {
                    update_option(self::OPTION_REPORT, $report, false);
                }
            }
            update_option(self::OPTION_VERSION, self::VERSION, true);
        } finally {
            \Bootspruefung\Learning\Infrastructure\Lock::release(self::OPTION_LOCK);
        }
    }

    /**
     * Update einer bestehenden Installation vom Datenstand $from auf den aktuellen.
     *
     * @param list<array<string,mixed>>|null $faqs Standarddaten (Tests); null = DefaultCustomerFaqs::faqs()
     * @return array{version:string,from:string,time:int,merged:list<array<string,mixed>>,not_merged:list<array<string,mixed>>,added:list<int>,updated:list<int>,kept:list<int>}
     */
    public static function upgrade(string $from, ?array $faqs = null): array
    {
        $faqs ??= DefaultCustomerFaqs::faqs();
        $from = $from === '' ? '1' : $from; // ohne Datenstand, aber mit FAQs: wie Datenstand 1 behandeln
        $merge = version_compare($from, self::SHARED_SINCE, '<') ? self::mergeCopies($faqs) : ['merged' => [], 'not_merged' => []];
        $added = array_values(array_map(static fn (array $f): int => (int) $f['ref'], array_filter($faqs, static fn (array $f): bool => version_compare((string) $f['since'], $from, '>'))));
        $created = $added !== [] ? self::import($added, $faqs)['refs'] : [];
        $update = self::updateDefaults($faqs);

        return ['version' => self::VERSION, 'from' => $from, 'time' => time(), 'merged' => $merge['merged'], 'not_merged' => $merge['not_merged'],
            'added' => $created, 'updated' => $update['updated'], 'kept' => $update['kept']];
    }

    /** Gibt es schon irgendeine Kunden-FAQ (jeder Status, auch Papierkorb)? */
    public static function hasAny(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' LIMIT 1", CustomerFaq::POST_TYPE));
    }

    /** @return list<int> vorhandene Nummern (jeder Status) */
    public static function existingRefs(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND p.post_status <> 'auto-draft'",
            CustomerFaq::POST_TYPE,
            CustomerFaq::META_REF
        )));
    }

    /**
     * Nummern der Start-FAQs, die der Knopf „Fehlende Start-FAQs importieren“ anlegen würde: weder die Nummer noch
     * (bei einer gemeinsamen FAQ) eine ihrer früheren Kopien existiert.
     *
     * @param list<array<string,mixed>>|null $faqs
     * @return list<int>
     */
    public static function missingRefs(?array $faqs = null): array
    {
        $existing = array_flip(self::existingRefs());
        $missing = [];
        foreach ($faqs ?? DefaultCustomerFaqs::faqs() as $faq) {
            if (! self::covered($faq, $existing)) {
                $missing[] = (int) $faq['ref'];
            }
        }

        return $missing;
    }

    /**
     * Fehlende Scheine, Unterkategorien und Start-FAQs anlegen (FAQs als Entwurf); mit $only nur diese Nummern
     * (neue FAQs beim Update) und nur die dafür nötigen Kategorien.
     *
     * @param list<int>|null $only
     * @param list<array<string,mixed>>|null $faqs Standarddaten (Tests); null = DefaultCustomerFaqs::faqs()
     * @return array{created:int,skipped:int,terms:int,refs:list<int>}
     */
    public static function import(?array $only = null, ?array $faqs = null): array
    {
        CustomerFaq::registerTypes();
        $existing = array_flip(self::existingRefs());
        $stats = ['created' => 0, 'skipped' => 0, 'terms' => 0, 'refs' => []];
        $scheine = [];
        foreach (DefaultCustomerFaqs::scheine() as $i => $schein) {
            $scheine[$schein['key']] = ['order' => $i + 1, 'schein' => $schein];
        }
        $tops = [];
        $subs = [];
        $termFor = static function (string $key, string $subName) use (&$tops, &$subs, &$stats, $scheine): int {
            if (! isset($scheine[$key])) {
                return 0;
            }
            $schein = $scheine[$key]['schein'];
            $tops[$key] ??= self::ensureTop($schein, $scheine[$key]['order'], $stats);
            if ($tops[$key] === 0) {
                return 0;
            }
            if (! isset($subs[$key][$subName])) {
                $subs[$key][$subName] = 0;
                foreach ($schein['subcategories'] as $s => $sub) {
                    if ($sub['name'] === $subName) {
                        $subs[$key][$subName] = self::ensureSub($sub, $tops[$key], $s + 1, $stats);
                    }
                }
            }

            return $subs[$key][$subName] ?: $tops[$key];
        };
        if ($only === null) {
            // Vollständiger Import: alle Scheine und Unterkategorien in ihrer Reihenfolge, auch ohne FAQ.
            foreach ($scheine as $key => $entry) {
                foreach ($entry['schein']['subcategories'] as $sub) {
                    $termFor($key, $sub['name']);
                }
            }
        }
        foreach ($faqs ?? DefaultCustomerFaqs::faqs() as $faq) {
            if ($only !== null && ! in_array((int) $faq['ref'], $only, true)) {
                continue;
            }
            if (self::covered($faq, $existing)) {
                $stats['skipped']++;
                continue;
            }
            $terms = array_values(array_unique(array_filter(array_map(static fn (array $p): int => $termFor((string) $p[0], (string) $p[1]), $faq['placements']))));
            $meta = [CustomerFaq::META_REF => (int) $faq['ref']];
            if (! empty($faq['note'])) {
                $meta[CustomerFaq::META_NOTE] = (string) $faq['note'];
            }
            $id = wp_insert_post(wp_slash([
                'post_type' => CustomerFaq::POST_TYPE,
                'post_status' => 'draft',
                'post_title' => $faq['question'],
                'post_content' => FaqImporter::content($faq),
                'menu_order' => (int) ($faq['order'] ?? 0),
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'meta_input' => $meta,
            ]), true);
            if (is_wp_error($id)) {
                continue;
            }
            wp_set_object_terms((int) $id, $terms, CustomerFaq::TAXONOMY);
            self::rememberDefault((int) $id);
            $existing[(int) $faq['ref']] = true;
            $stats['created']++;
            $stats['refs'][] = (int) $faq['ref'];
        }
        CustomerFaqRepository::flush();

        return $stats;
    }

    /**
     * Unveränderte Start-FAQs auf den aktuellen Stand bringen: Frage, Antwort und Redaktionshinweis. „Unverändert“ =
     * Prüfsumme über Frage und Antwort entspricht dem zuletzt eingespielten Stand. Status, Kategorien und Reihenfolge
     * bleiben Sache des Betreibers. Bearbeitete FAQs („kept“, wenn sich ihr Standardtext geändert hat), FAQs im
     * Papierkorb und eigene FAQs ohne Nummer bleiben unberührt; Nummern, die nicht mehr in den Standarddaten stehen
     * (z. B. nicht zusammengeführte Kopien), auch.
     *
     * @param list<array<string,mixed>>|null $faqs Standarddaten (Tests); null = DefaultCustomerFaqs::faqs()
     * @return array{updated:list<int>,kept:list<int>,current:int}
     */
    public static function updateDefaults(?array $faqs = null): array
    {
        CustomerFaq::registerTypes();
        $defaults = [];
        foreach ($faqs ?? DefaultCustomerFaqs::faqs() as $faq) {
            $defaults[(int) $faq['ref']] = $faq;
        }
        $stats = ['updated' => [], 'kept' => [], 'current' => 0];
        foreach (self::postsWithRefs(array_keys($defaults), self::LIVE) as $post) {
            $ref = (int) get_post_meta($post->ID, CustomerFaq::META_REF, true);
            $faq = $defaults[$ref] ?? null;
            if ($faq === null) {
                continue;
            }
            $current = FaqImporter::hash($post->post_title, $post->post_content);
            $stored = (string) get_post_meta($post->ID, self::META_DEFAULT_HASH, true);
            $wanted = FaqImporter::hash((string) $faq['question'], FaqImporter::content($faq));
            if ($current === $wanted) {
                $stats['current']++;
                if ($stored !== $current) {
                    update_post_meta($post->ID, self::META_DEFAULT_HASH, $current);
                }
                continue;
            }
            if ($stored === '' || $current !== $stored) {
                // Bearbeitet: bleibt. Gemeldet nur, wenn sich der Standardtext seitdem geändert hat.
                if ($stored === '' || $wanted !== $stored) {
                    $stats['kept'][] = $ref;
                }
                continue;
            }
            if (self::applyDefault($post, $faq, $post->post_status)) {
                $stats['updated'][] = $ref;
            }
        }
        sort($stats['updated']);
        sort($stats['kept']);
        CustomerFaqRepository::flush();

        return $stats;
    }

    /**
     * Datenstand 1 → 2: Kopien einer gemeinsamen Frage (replaces) zu einer FAQ zusammenführen.
     *
     * - Betrachtet werden die vorhandenen Kopien außerhalb des Papierkorbs; Kopien im Papierkorb oder endgültig
     *   gelöschte bleiben, wie sie sind (ihr Schein bekommt die gemeinsame FAQ nicht).
     * - Ist eine dieser Kopien bearbeitet (Prüfsumme über Frage und Antwort weicht ab), wird für diese Frage nichts
     *   zusammengeführt: Alle Kopien bleiben eigenständig (Bericht „not_merged“).
     * - Sonst bleibt die Kopie mit dem ersten Schein (Reihenfolge der Scheine) als gemeinsame FAQ erhalten: Sie
     *   bekommt die Nummer der gemeinsamen FAQ, den aktuellen Text und Redaktionshinweis und alle Kategorien der
     *   zusammengeführten Kopien (auch vom Betreiber umgehängte). Die übrigen Kopien gehen in den Papierkorb –
     *   nie endgültig gelöscht.
     * - Status: Hatten alle zusammengeführten Kopien denselben Status (z. B. alle veröffentlicht), bekommt die
     *   gemeinsame FAQ diesen Status. Bei gemischtem Status (z. B. in SBF See veröffentlicht, in SRC Entwurf) wird
     *   sie Entwurf, damit sie nicht in Scheinen erscheint, in denen der Betreiber sie bewusst zurückgehalten hat;
     *   der Bericht nennt die Scheine, in denen sie veröffentlicht war („mixed“, Hinweis im Admin).
     *
     * @param list<array<string,mixed>>|null $faqs Standarddaten (Tests); null = DefaultCustomerFaqs::faqs()
     * @return array{merged:list<array{ref:int,question:string,post_id:int,from:list<int>,trashed:list<int>,status:string,mixed:bool,published_in:list<string>}>,not_merged:list<array{ref:int,question:string,edited:list<int>}>}
     */
    public static function mergeCopies(?array $faqs = null): array
    {
        CustomerFaq::registerTypes();
        $out = ['merged' => [], 'not_merged' => []];
        $existing = array_flip(self::existingRefs());
        foreach ($faqs ?? DefaultCustomerFaqs::faqs() as $faq) {
            $replaces = array_map('intval', (array) ($faq['replaces'] ?? []));
            if ($replaces === [] || isset($existing[(int) $faq['ref']])) {
                continue;
            }
            // Vorhandene Kopien in der Reihenfolge der Scheine (replaces); je Nummer die älteste.
            $copies = [];
            foreach (self::postsWithRefs($replaces, self::LIVE) as $post) {
                $ref = (int) get_post_meta($post->ID, CustomerFaq::META_REF, true);
                $copies[$ref] ??= $post;
            }
            $copies = array_values(array_filter(array_map(static fn (int $ref) => $copies[$ref] ?? null, $replaces)));
            if ($copies === []) {
                continue;
            }
            $edited = [];
            foreach ($copies as $post) {
                $stored = (string) get_post_meta($post->ID, self::META_DEFAULT_HASH, true);
                if ($stored === '' || FaqImporter::hash($post->post_title, $post->post_content) !== $stored) {
                    $edited[] = (int) get_post_meta($post->ID, CustomerFaq::META_REF, true);
                }
            }
            if ($edited !== []) {
                $out['not_merged'][] = ['ref' => (int) $faq['ref'], 'question' => (string) $faq['question'], 'edited' => $edited];
                continue;
            }
            $statuses = array_values(array_unique(array_map(static fn (\WP_Post $p): string => $p->post_status, $copies)));
            $mixed = count($statuses) > 1;
            $status = $mixed ? 'draft' : $statuses[0];
            $terms = [];
            $publishedIn = [];
            foreach ($copies as $post) {
                $assigned = wp_get_object_terms($post->ID, CustomerFaq::TAXONOMY, ['fields' => 'ids']);
                $ids = is_array($assigned) ? array_map('intval', $assigned) : [];
                array_push($terms, ...$ids);
                if ($mixed && $post->post_status === 'publish') {
                    array_push($publishedIn, ...self::scheinNames($ids));
                }
            }
            $survivor = $copies[0];
            $from = array_map(static fn (\WP_Post $p): int => (int) get_post_meta($p->ID, CustomerFaq::META_REF, true), $copies);
            if (! self::applyDefault($survivor, $faq, $status)) {
                continue;
            }
            update_post_meta($survivor->ID, CustomerFaq::META_REF, (int) $faq['ref']);
            update_post_meta($survivor->ID, self::META_MERGED_FROM, implode(',', $from));
            wp_set_object_terms($survivor->ID, array_values(array_unique($terms)), CustomerFaq::TAXONOMY);
            $trashed = [];
            foreach (array_slice($copies, 1) as $post) {
                if (self::trash($post->ID)) {
                    $trashed[] = (int) get_post_meta($post->ID, CustomerFaq::META_REF, true);
                }
            }
            $existing[(int) $faq['ref']] = true;
            $out['merged'][] = ['ref' => (int) $faq['ref'], 'question' => (string) $faq['question'], 'post_id' => (int) $survivor->ID, 'from' => $from,
                'trashed' => $trashed, 'status' => $status, 'mixed' => $mixed, 'published_in' => array_values(array_unique($publishedIn))];
        }
        CustomerFaqRepository::flush();

        return $out;
    }

    /**
     * Ist eine Start-FAQ schon „da“? Ihre Nummer existiert (jeder Status) oder – bei einer gemeinsamen FAQ – eine
     * ihrer früheren Kopien (dann ist sie zusammengeführt, bewusst nicht zusammengeführt oder vom Betreiber gelöscht).
     *
     * @param array<string,mixed> $faq
     * @param array<int,mixed> $existing Nummer => beliebig
     */
    private static function covered(array $faq, array $existing): bool
    {
        if (isset($existing[(int) $faq['ref']])) {
            return true;
        }
        foreach ((array) ($faq['replaces'] ?? []) as $ref) {
            if (isset($existing[(int) $ref])) {
                return true;
            }
        }

        return false;
    }

    /** Text, Redaktionshinweis und Status aus den Standarddaten setzen, Prüfsumme merken. @param array<string,mixed> $faq */
    private static function applyDefault(\WP_Post $post, array $faq, string $status): bool
    {
        $content = FaqImporter::content($faq);
        if ($post->post_title !== (string) $faq['question'] || $post->post_content !== $content || $post->post_status !== $status) {
            $id = wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => (string) $faq['question'], 'post_content' => $content, 'post_status' => $status]), true);
            if (is_wp_error($id) || ! $id) {
                return false;
            }
        }
        empty($faq['note']) ? delete_post_meta($post->ID, CustomerFaq::META_NOTE) : update_post_meta($post->ID, CustomerFaq::META_NOTE, (string) $faq['note']);
        self::rememberDefault($post->ID);

        return true;
    }

    /** Prüfsumme des gespeicherten Stands als „unveränderter Standard“ merken. */
    private static function rememberDefault(int $id): void
    {
        clean_post_cache($id);
        $post = get_post($id);
        if ($post instanceof \WP_Post) {
            update_post_meta($id, self::META_DEFAULT_HASH, FaqImporter::hash($post->post_title, $post->post_content));
        }
    }

    /** In den Papierkorb – auch wenn der Papierkorb abgeschaltet ist (EMPTY_TRASH_DAYS = 0), nie endgültig löschen. */
    private static function trash(int $id): bool
    {
        if (EMPTY_TRASH_DAYS) {
            return (bool) wp_trash_post($id);
        }
        $status = (string) get_post_status($id);
        add_post_meta($id, '_wp_trash_meta_status', $status);
        add_post_meta($id, '_wp_trash_meta_time', time());

        return ! is_wp_error(wp_update_post(['ID' => $id, 'post_status' => 'trash'], true));
    }

    /** @param list<int> $termIds @return list<string> Namen der Scheine (oberste Ebene) dieser Kategorien */
    private static function scheinNames(array $termIds): array
    {
        $names = [];
        foreach ($termIds as $termId) {
            $term = get_term($termId, CustomerFaq::TAXONOMY);
            for ($i = 0; $term instanceof \WP_Term && $term->parent !== 0 && $i < 10; $i++) {
                $term = get_term($term->parent, CustomerFaq::TAXONOMY);
            }
            if ($term instanceof \WP_Term) {
                $names[] = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
            }
        }

        return $names;
    }

    /**
     * @param list<int> $refs
     * @param list<string> $statuses
     * @return list<\WP_Post> nach ID aufsteigend
     */
    private static function postsWithRefs(array $refs, array $statuses): array
    {
        if ($refs === []) {
            return [];
        }

        return get_posts(['post_type' => CustomerFaq::POST_TYPE, 'post_status' => $statuses, 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC',
            'suppress_filters' => true, 'meta_query' => [['key' => CustomerFaq::META_REF, 'value' => array_map('strval', $refs), 'compare' => 'IN']]]);
    }

    /** @param array<string,mixed> $stats */
    private static function ensureTop(array $schein, int $order, array &$stats): int
    {
        $found = get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => 0, 'number' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => CustomerFaq::TERM_KEY, 'value' => $schein['key']]]]);
        $id = is_array($found) && $found !== [] ? (int) $found[0] : 0;
        if ($id === 0 && ($term = get_term_by('slug', $schein['slug'], CustomerFaq::TAXONOMY)) && (int) $term->parent === 0) {
            $id = (int) $term->term_id;
        }
        if ($id === 0) {
            $id = self::insertTerm($schein['name'], $schein['slug'], 0, $stats);
        }
        if ($id > 0) {
            add_term_meta($id, CustomerFaq::TERM_KEY, $schein['key'], true);
            add_term_meta($id, CustomerFaq::TERM_ORDER, $order, true);
        }

        return $id;
    }

    /** @param array<string,mixed> $stats */
    private static function ensureSub(array $sub, int $parentId, int $order, array &$stats): int
    {
        $term = get_term_by('slug', $sub['slug'], CustomerFaq::TAXONOMY);
        $id = $term && (int) $term->parent === $parentId ? (int) $term->term_id : 0;
        if ($id === 0) {
            foreach (get_terms(['taxonomy' => CustomerFaq::TAXONOMY, 'hide_empty' => false, 'parent' => $parentId]) ?: [] as $child) {
                if ($child instanceof \WP_Term && html_entity_decode($child->name, ENT_QUOTES, 'UTF-8') === $sub['name']) {
                    $id = (int) $child->term_id;
                    break;
                }
            }
        }
        if ($id === 0) {
            $id = self::insertTerm($sub['name'], $sub['slug'], $parentId, $stats);
        }
        if ($id > 0) {
            add_term_meta($id, CustomerFaq::TERM_ORDER, $order, true);
        }

        return $id;
    }

    /** @param array<string,mixed> $stats */
    private static function insertTerm(string $name, string $slug, int $parent, array &$stats): int
    {
        $result = wp_insert_term($name, CustomerFaq::TAXONOMY, ['slug' => $slug, 'parent' => $parent]);
        if (is_wp_error($result)) {
            $existing = $result->get_error_data('term_exists');

            return is_numeric($existing) ? (int) $existing : 0;
        }
        $stats['terms']++;

        return (int) $result['term_id'];
    }

    private static function acquireLock(): bool
    {
        return \Bootspruefung\Learning\Infrastructure\Lock::acquire(self::OPTION_LOCK, self::LOCK_TTL);
    }
}
