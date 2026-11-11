<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Gemeinsame Bausteine der FAQ-Bereiche (öffentliche FAQ, Kunden-FAQ): Begriffe einer hierarchischen
 * FAQ-Taxonomie mit Schlüssel und Reihenfolge laden und den Zwischenspeicher bei Änderungen leeren.
 */
final class FaqTerms
{
    /**
     * Alle Begriffe der Taxonomie (auch leere) mit Schlüssel (Term-Meta, sonst Slug) und Reihenfolge
     * (Term-Meta, sonst ganz hinten).
     *
     * @return array<int,array{id:int,parent:int,slug:string,name:string,key:string,order:int}>
     */
    public static function map(string $taxonomy, string $keyMeta, string $orderMeta): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        $map = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $order = get_term_meta($term->term_id, $orderMeta, true);
            $map[(int) $term->term_id] = [
                'id' => (int) $term->term_id,
                'parent' => (int) $term->parent,
                'slug' => (string) $term->slug,
                'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
                'key' => (string) (get_term_meta($term->term_id, $keyMeta, true) ?: $term->slug),
                'order' => $order === '' ? PHP_INT_MAX : (int) $order,
            ];
        }

        return $map;
    }

    /**
     * Zwischenspeicher leeren, sobald sich Beiträge des Typs, ihre Kategorien oder die genannten Merkmale ändern.
     *
     * @param list<string> $postMeta Beitrags-Meta, die die Ausgabe beeinflusst
     * @param list<string> $termMeta Term-Meta, die die Ausgabe beeinflusst
     */
    public static function flushOnChange(string $postType, string $taxonomy, array $postMeta, array $termMeta, callable $flush): void
    {
        $ifType = static function ($postId) use ($postType, $flush): void {
            if (get_post_type((int) $postId) === $postType) {
                $flush();
            }
        };
        add_action('save_post_'.$postType, $flush);
        add_action('transition_post_status', static function ($new, $old, $post) use ($postType, $flush): void {
            if ($post instanceof \WP_Post && $post->post_type === $postType && $new !== $old) {
                $flush();
            }
        }, 10, 3);
        foreach (['before_delete_post', 'deleted_post', 'trashed_post', 'untrashed_post'] as $hook) {
            add_action($hook, $ifType);
        }
        add_action('set_object_terms', static function ($objectId, $terms, $ttIds, $tax) use ($taxonomy, $flush): void {
            if ($tax === $taxonomy) {
                $flush();
            }
        }, 10, 4);
        foreach (['created_', 'edited_', 'delete_'] as $prefix) {
            add_action($prefix.$taxonomy, $flush);
        }
        $metaPost = static function ($metaId, $objectId, $key) use ($postMeta, $ifType): void {
            if (in_array($key, $postMeta, true)) {
                $ifType($objectId);
            }
        };
        $metaTerm = static function ($metaId, $objectId, $key) use ($termMeta, $flush): void {
            if (in_array($key, $termMeta, true)) {
                $flush();
            }
        };
        foreach (['added', 'updated', 'deleted'] as $action) {
            if ($postMeta !== []) {
                add_action($action.'_post_meta', $metaPost, 10, 3);
            }
            if ($termMeta !== []) {
                add_action($action.'_term_meta', $metaTerm, 10, 3);
            }
        }
    }
}
