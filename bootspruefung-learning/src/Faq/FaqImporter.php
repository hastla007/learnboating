<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Legt die Standard-FAQs (DefaultFaqs) mit Rubriken und Unterkategorien an.
 *
 * Idempotent über _bp_faq_ref: Eine FAQ, deren Nummer schon existiert (auch als Entwurf oder im
 * Papierkorb), wird nie angefasst – Änderungen des Betreibers bleiben erhalten. Kategorien werden
 * über den Rubrik-Schlüssel bzw. den Slug wiedergefunden, ihre Merkmale nur ergänzt, nie überschrieben.
 *
 * Automatisch wie die Datenbank-Migration (Migrator): bei Aktivierung und nach einem Update, sobald
 * der Datenstand (VERSION) nicht zur gespeicherten Option passt. Gibt es noch keine einzige FAQ, werden
 * alle angelegt; sonst werden nur unveränderte Standard-FAQs auf den neuen Text gebracht (updateDefaults).
 * Fehlende einzelne FAQs ergänzt der Knopf im Admin.
 *
 * „Unverändert“: Prüfsumme über Frage und Antwort (Leerraum und Entities normalisiert) entspricht dem
 * zuletzt eingespielten Standardstand – gemerkt in _bp_faq_default_hash, für FAQs aus Datenstand 1 ohne
 * dieses Feld über DefaultFaqHistory. Vom Betreiber geänderte FAQs werden nie überschrieben.
 * Ändert sich ein Text in DefaultFaqs: VERSION erhöhen.
 */
final class FaqImporter
{
    public const VERSION = '2';
    /** Prüfsumme des zuletzt eingespielten Standardstands (Frage + Antwort, wie gespeichert). */
    public const META_DEFAULT_HASH = '_bp_faq_default_hash';
    /** Status, den der Import bzw. die Aktualisierung zuletzt gesetzt hat. */
    public const META_DEFAULT_STATUS = '_bp_faq_default_status';
    public const OPTION_VERSION = 'bp_learning_faq_version';
    private const OPTION_LOCK = 'bp_learning_faq_import_lock';
    private const LOCK_TTL = 300;
    /** Platzhalter in eckigen Klammern, z. B. „[Link zum Shop]“. */
    public const PLACEHOLDER = '/\[[^\[\]]+\]/u';

    public static function needsImport(): bool
    {
        return get_option(self::OPTION_VERSION) !== self::VERSION;
    }

    /** Erstimport nach Aktivierung oder Update (nur wenn noch keine FAQ existiert). */
    public static function maybeImport(): void
    {
        if (! self::needsImport() || ! post_type_exists(Faq::POST_TYPE) || ! self::acquireLock()) {
            return;
        }
        try {
            if (! self::hasAny()) {
                self::import();
            } else {
                self::updateDefaults();
            }
            update_option(self::OPTION_VERSION, self::VERSION, true);
        } finally {
            delete_option(self::OPTION_LOCK);
        }
    }

    /** Gibt es schon irgendeine FAQ (jeder Status, auch Papierkorb)? */
    public static function hasAny(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' LIMIT 1", Faq::POST_TYPE));
    }

    /** Status beim Import: Entwurf bei Platzhaltern oder Redaktionshinweis ohne ★. */
    public static function statusFor(array $faq): string
    {
        $placeholder = preg_match(self::PLACEHOLDER, (string) $faq['answer']) === 1;
        $note = ($faq['note'] ?? null) !== null && $faq['note'] !== '';

        return ! $placeholder && (! $note || ! empty($faq['theme'])) ? 'publish' : 'draft';
    }

    /**
     * Legt fehlende Rubriken, Unterkategorien und FAQs an.
     *
     * @return array{created:int,skipped:int,terms:int,drafts:list<int>}
     */
    public static function import(): array
    {
        Faq::registerTypes();
        $existing = array_flip(self::existingRefs());
        $stats = ['created' => 0, 'skipped' => 0, 'terms' => 0, 'drafts' => []];
        foreach (DefaultFaqs::RUBRICS as $r => $rubric) {
            $rubricId = self::ensureRubric($rubric, $r + 1, $stats);
            if ($rubricId === 0) {
                continue;
            }
            foreach ($rubric['subcategories'] as $s => $sub) {
                $subId = self::ensureSubcategory($sub, $rubricId, $s + 1, $stats);
                foreach ($sub['faqs'] as $f => $faq) {
                    if (isset($existing[$faq['ref']])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $status = self::statusFor($faq);
                    $meta = [Faq::META_REF => (int) $faq['ref'], Faq::META_THEME => $faq['theme'] ? '1' : '0', self::META_DEFAULT_STATUS => $status];
                    if (! empty($faq['note'])) {
                        $meta[Faq::META_NOTE] = $faq['note'];
                    }
                    $id = wp_insert_post(wp_slash([
                        'post_type' => Faq::POST_TYPE,
                        'post_status' => $status,
                        'post_title' => $faq['question'],
                        'post_content' => self::content($faq),
                        'menu_order' => $f + 1,
                        'comment_status' => 'closed',
                        'ping_status' => 'closed',
                        'meta_input' => $meta,
                    ]), true); // wp_insert_post() erwartet maskierte Daten
                    if (is_wp_error($id)) {
                        continue;
                    }
                    wp_set_object_terms((int) $id, [$subId ?: $rubricId], Faq::TAXONOMY);
                    self::rememberDefault((int) $id);
                    $existing[$faq['ref']] = true;
                    $stats['created']++;
                    if ($status === 'draft') {
                        $stats['drafts'][] = (int) $faq['ref'];
                    }
                }
            }
        }
        FaqRepository::flush();

        return $stats;
    }

    /** Gespeicherter Inhalt einer Standard-FAQ (ein Absatz-Block). @param array{answer:string} $faq */
    public static function content(array $faq): string
    {
        return "<!-- wp:paragraph -->\n<p>".htmlspecialchars((string) $faq['answer'], ENT_NOQUOTES, 'UTF-8')."</p>\n<!-- /wp:paragraph -->";
    }

    /** Prüfsumme über Frage und Antwort; Leerraum und Entities normalisiert (kses, Editor). */
    public static function hash(string $question, string $content): string
    {
        $norm = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return md5($norm($question)."\n".$norm($content));
    }

    /** Prüfsumme des gespeicherten Stands als „unveränderter Standard“ merken. */
    private static function rememberDefault(int $id): void
    {
        clean_post_cache($id);
        $post = get_post($id);
        if ($post instanceof \WP_Post) {
            update_post_meta($id, self::META_DEFAULT_HASH, self::hash($post->post_title, $post->post_content));
        }
    }

    /**
     * Unveränderte Standard-FAQs auf den aktuellen Text aus DefaultFaqs bringen (Frage, Antwort, Redaktions-
     * hinweis; Status nur, wenn der Betreiber ihn nicht geändert hat). Vom Betreiber geänderte FAQs, FAQs im
     * Papierkorb und eigene FAQs ohne Nummer bleiben unberührt. ★ (Im Theme anzeigen) bleibt Sache des Betreibers.
     *
     * @param list<array<string,mixed>>|null $rubrics Standarddaten (Tests); null = DefaultFaqs::RUBRICS
     * @return array{updated:list<int>,kept:list<int>,current:int}
     */
    public static function updateDefaults(?array $rubrics = null): array
    {
        Faq::registerTypes();
        $defaults = [];
        foreach ($rubrics ?? DefaultFaqs::RUBRICS as $rubric) {
            foreach ($rubric['subcategories'] as $sub) {
                foreach ($sub['faqs'] as $faq) {
                    $defaults[(int) $faq['ref']] = $faq;
                }
            }
        }
        $stats = ['updated' => [], 'kept' => [], 'current' => 0];
        $posts = get_posts(['post_type' => Faq::POST_TYPE, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1,
            'meta_key' => Faq::META_REF, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]);
        foreach ($posts as $post) {
            $ref = (int) get_post_meta($post->ID, Faq::META_REF, true);
            $faq = $defaults[$ref] ?? null;
            if ($faq === null) {
                continue;
            }
            $current = self::hash($post->post_title, $post->post_content);
            $wanted = self::hash((string) $faq['question'], self::content($faq));
            $stored = (string) get_post_meta($post->ID, self::META_DEFAULT_HASH, true);
            $recordedStatus = (string) get_post_meta($post->ID, self::META_DEFAULT_STATUS, true);
            if ($stored === '') {
                // Datenstand 1 (ohne Prüfsumme): bekannte frühere Standardstände je Nummer.
                $history = DefaultFaqHistory::HASHES[$ref] ?? [];
                $known = isset($history[$current]) ? [$current] : [];
                if ($recordedStatus === '' && isset($history[$current])) {
                    $recordedStatus = $history[$current];
                }
            } else {
                $known = [$stored];
            }
            if ($current === $wanted) {
                $stats['current']++;
                if ($stored !== $current) {
                    // Entspricht dem aktuellen Standard: Prüfsumme (und den Status des Imports) nachtragen.
                    update_post_meta($post->ID, self::META_DEFAULT_HASH, $current);
                    if ((string) get_post_meta($post->ID, self::META_DEFAULT_STATUS, true) === '') {
                        update_post_meta($post->ID, self::META_DEFAULT_STATUS, $recordedStatus !== '' ? $recordedStatus : self::statusFor($faq));
                    }
                }
                continue;
            }
            if (! in_array($current, $known, true)) {
                $stats['kept'][] = $ref;
                continue;
            }
            $status = $recordedStatus !== '' && $recordedStatus !== $post->post_status ? $post->post_status : self::statusFor($faq);
            $id = wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => (string) $faq['question'], 'post_content' => self::content($faq), 'post_status' => $status]), true);
            if (is_wp_error($id) || ! $id) {
                continue;
            }
            empty($faq['note']) ? delete_post_meta($post->ID, Faq::META_NOTE) : update_post_meta($post->ID, Faq::META_NOTE, (string) $faq['note']);
            update_post_meta($post->ID, self::META_DEFAULT_STATUS, $status);
            self::rememberDefault($post->ID);
            $stats['updated'][] = $ref;
        }
        FaqRepository::flush();

        return $stats;
    }

    /** @return list<int> vorhandene FAQ-Nummern (jeder Status) */
    public static function existingRefs(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s",
            Faq::POST_TYPE,
            Faq::META_REF
        )));
    }

    /** @param array<string,mixed> $stats */
    private static function ensureRubric(array $rubric, int $order, array &$stats): int
    {
        $found = get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false, 'parent' => 0, 'number' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => Faq::TERM_KEY, 'value' => $rubric['key']]]]);
        $id = is_array($found) && $found !== [] ? (int) $found[0] : 0;
        if ($id === 0 && ($term = get_term_by('slug', $rubric['slug'], Faq::TAXONOMY)) && (int) $term->parent === 0) {
            $id = (int) $term->term_id;
        }
        if ($id === 0) {
            $id = self::insertTerm($rubric['name'], $rubric['slug'], 0, $stats);
        }
        if ($id > 0) {
            add_term_meta($id, Faq::TERM_KEY, $rubric['key'], true);
            add_term_meta($id, Faq::TERM_ORDER, $order, true);
        }

        return $id;
    }

    /** @param array<string,mixed> $stats */
    private static function ensureSubcategory(array $sub, int $rubricId, int $order, array &$stats): int
    {
        $term = get_term_by('slug', $sub['slug'], Faq::TAXONOMY);
        $id = $term && (int) $term->parent === $rubricId ? (int) $term->term_id : 0;
        if ($id === 0) {
            // Vom Betreiber angelegt oder umbenannt: gleicher Name unter derselben Rubrik.
            foreach (get_terms(['taxonomy' => Faq::TAXONOMY, 'hide_empty' => false, 'parent' => $rubricId]) ?: [] as $child) {
                if ($child instanceof \WP_Term && html_entity_decode($child->name, ENT_QUOTES, 'UTF-8') === $sub['name']) {
                    $id = (int) $child->term_id;
                    break;
                }
            }
        }
        if ($id === 0) {
            $id = self::insertTerm($sub['name'], $sub['slug'], $rubricId, $stats);
        }
        if ($id > 0) {
            add_term_meta($id, Faq::TERM_ORDER, $order, true);
        }

        return $id;
    }

    /** @param array<string,mixed> $stats */
    private static function insertTerm(string $name, string $slug, int $parent, array &$stats): int
    {
        $result = wp_insert_term($name, Faq::TAXONOMY, ['slug' => $slug, 'parent' => $parent]);
        if (is_wp_error($result)) {
            $existing = $result->get_error_data('term_exists');

            return is_numeric($existing) ? (int) $existing : 0;
        }
        $stats['terms']++;

        return (int) $result['term_id'];
    }

    private static function acquireLock(): bool
    {
        // add_option ist atomar (UNIQUE auf option_name) – wie die Sperre des Migrators.
        if (add_option(self::OPTION_LOCK, (string) time(), '', false)) {
            return true;
        }
        $since = (int) get_option(self::OPTION_LOCK);
        if ($since > 0 && time() - $since > self::LOCK_TTL) {
            delete_option(self::OPTION_LOCK);

            return add_option(self::OPTION_LOCK, (string) time(), '', false);
        }

        return false;
    }
}
