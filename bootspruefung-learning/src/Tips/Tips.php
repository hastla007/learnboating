<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tips;

use WP_REST_Server;

/**
 * „Tipps & Tricks“ (Struktur wie die Quelle: Kategorie, Titel, Text, Reihenfolge, aktiv).
 * Redaktionell im Admin gepflegt als eigener Beitragstyp; nur veröffentlichte Tipps
 * erscheinen unter /bootsscheine/tipps/. Starttexte siehe StarterTips (neu geschrieben).
 */
final class Tips
{
    public const POST_TYPE = 'bp_tip';
    public const TAXONOMY = 'bp_tip_category';
    public const SEEDED_OPTION = 'bp_learning_tips_seeded';

    public function register(): void
    {
        add_action('init', [self::class, 'registerTypes']);
        add_action('init', [self::class, 'maybeSeed'], 20);
        add_action('rest_api_init', [$this, 'routes']);
    }

    public static function registerTypes(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Tipps', 'bootspruefung-learning'), 'singular_name' => __('Tipp', 'bootspruefung-learning'),
                'add_new_item' => __('Neuen Tipp anlegen', 'bootspruefung-learning'), 'edit_item' => __('Tipp bearbeiten', 'bootspruefung-learning'),
                'menu_name' => __('Tipps', 'bootspruefung-learning'),
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'bootspruefung', 'show_in_rest' => true,
            'supports' => ['title', 'editor', 'page-attributes', 'revisions'],
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => ['name' => __('Tipp-Kategorien', 'bootspruefung-learning'), 'singular_name' => __('Tipp-Kategorie', 'bootspruefung-learning')],
            'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'hierarchical' => true, 'show_admin_column' => true,
        ]);
    }

    /** Starttexte einmalig anlegen (später gelöschte Tipps kommen nicht zurück). */
    public static function maybeSeed(): void
    {
        if (get_option(self::SEEDED_OPTION)) {
            return;
        }
        update_option(self::SEEDED_OPTION, gmdate('Y-m-d H:i:s'), false);
        $order = 0;
        foreach (StarterTips::all() as $category => $tips) {
            $term = term_exists($category, self::TAXONOMY);
            $termId = $term ? (int) (is_array($term) ? $term['term_id'] : $term) : (int) (wp_insert_term($category, self::TAXONOMY)['term_id'] ?? 0);
            foreach ($tips as [$title, $body]) {
                $id = wp_insert_post(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $body, 'menu_order' => $order++], true);
                if (! is_wp_error($id) && $termId > 0) {
                    wp_set_object_terms((int) $id, [$termId], self::TAXONOMY);
                }
            }
        }
    }

    public function routes(): void
    {
        register_rest_route('bootspruefung/v1', '/tips', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'payload'],
            'permission_callback' => static fn () => is_user_logged_in(),
        ]);
    }

    /** @return list<array{name:string,tips:list<array{id:int,title:string,html:string}>}> */
    public static function payload(): array
    {
        return array_map(static fn ($g) => ['name' => $g['name'], 'tips' => $g['tips']], self::groups());
    }

    /**
     * Veröffentlichte Tipps nach Kategorie (Reihenfolge wie im Web), zusätzlich mit der Kategorie
     * (null = „Allgemein“, ohne Kategorie) – für die App-Schnittstelle.
     *
     * @return list<array{term:?\WP_Term,name:string,order:int,tips:list<array{id:int,title:string,html:string}>}>
     */
    public static function groups(): array
    {
        $posts = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC']]);
        $groups = [];
        foreach ($posts as $post) {
            $terms = get_the_terms($post, self::TAXONOMY);
            $term = is_array($terms) && $terms !== [] ? $terms[0] : null;
            $key = $term ? $term->name : __('Allgemein', 'bootspruefung-learning');
            $groups[$key] ??= ['term' => $term, 'name' => $key, 'order' => (int) $post->menu_order, 'tips' => []];
            $groups[$key]['tips'][] = ['id' => (int) $post->ID, 'title' => get_the_title($post), 'html' => wp_kses_post(wpautop(do_blocks((string) $post->post_content)))];
        }
        usort($groups, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_values($groups);
    }
}
