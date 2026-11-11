<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Legt die Startinhalte der Kunden-FAQ an (DefaultCustomerFaqs): je Schein eine Kategorie (Schlüssel =
 * Kurscode) mit den Start-Unterkategorien und das Startpaket an FAQs – alle als Entwurf.
 *
 * Wie FaqImporter: automatisch bei Aktivierung bzw. nach einem Update, sobald der Datenstand (VERSION)
 * nicht zur gespeicherten Option passt – aber nur, solange es noch keine einzige Kunden-FAQ gibt (eigener
 * Datenstand, unabhängig von den öffentlichen FAQs). Idempotent über _bp_cfaq_ref: Eine Nummer, die schon
 * existiert (jeder Status, auch Papierkorb), wird nie angefasst. Kategorien werden über Schlüssel bzw. Slug
 * wiedergefunden, ihre Merkmale nur ergänzt, nie überschrieben. Fehlende einzelne FAQs ergänzt der Knopf
 * im Admin (CustomerFaqAdmin).
 */
final class CustomerFaqImporter
{
    public const VERSION = '1';
    public const OPTION_VERSION = 'bp_learning_cfaq_version';
    /** Prüfsumme des eingespielten Standardstands (für spätere Datenstände, wie bei den öffentlichen FAQs). */
    public const META_DEFAULT_HASH = '_bp_cfaq_default_hash';
    private const OPTION_LOCK = 'bp_learning_cfaq_import_lock';
    private const LOCK_TTL = 300;

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
            }
            update_option(self::OPTION_VERSION, self::VERSION, true);
        } finally {
            delete_option(self::OPTION_LOCK);
        }
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
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s",
            CustomerFaq::POST_TYPE,
            CustomerFaq::META_REF
        )));
    }

    /**
     * Fehlende Scheine, Unterkategorien und Start-FAQs anlegen (FAQs als Entwurf).
     *
     * @return array{created:int,skipped:int,terms:int}
     */
    public static function import(): array
    {
        CustomerFaq::registerTypes();
        $existing = array_flip(self::existingRefs());
        $stats = ['created' => 0, 'skipped' => 0, 'terms' => 0];
        foreach (DefaultCustomerFaqs::scheine() as $i => $schein) {
            $topId = self::ensureTop($schein, $i + 1, $stats);
            if ($topId === 0) {
                continue;
            }
            foreach ($schein['subcategories'] as $s => $sub) {
                $subId = self::ensureSub($sub, $topId, $s + 1, $stats);
                foreach ($sub['faqs'] as $f => $faq) {
                    if (isset($existing[$faq['ref']])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $meta = [CustomerFaq::META_REF => (int) $faq['ref']];
                    if (! empty($faq['note'])) {
                        $meta[CustomerFaq::META_NOTE] = (string) $faq['note'];
                    }
                    $id = wp_insert_post(wp_slash([
                        'post_type' => CustomerFaq::POST_TYPE,
                        'post_status' => 'draft',
                        'post_title' => $faq['question'],
                        'post_content' => FaqImporter::content($faq),
                        'menu_order' => $f + 1,
                        'comment_status' => 'closed',
                        'ping_status' => 'closed',
                        'meta_input' => $meta,
                    ]), true);
                    if (is_wp_error($id)) {
                        continue;
                    }
                    wp_set_object_terms((int) $id, [$subId ?: $topId], CustomerFaq::TAXONOMY);
                    clean_post_cache((int) $id);
                    $post = get_post((int) $id);
                    if ($post instanceof \WP_Post) {
                        update_post_meta((int) $id, self::META_DEFAULT_HASH, FaqImporter::hash($post->post_title, $post->post_content));
                    }
                    $existing[$faq['ref']] = true;
                    $stats['created']++;
                }
            }
        }
        CustomerFaqRepository::flush();

        return $stats;
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
