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
    /** Textstand der Starttexte; weicht er ab, werden unbearbeitete Starttexte nachgezogen (updateDefaults). */
    public const VERSION = '2';
    public const VERSION_OPTION = 'bp_learning_tips_version';
    /** Sperre gegen doppelte Anlage der Starttexte (Infrastructure\Lock). */
    public const SEED_LOCK = 'bp_learning_tips_seed_lock';

    public function register(): void
    {
        add_action('init', [self::class, 'registerTypes']);
        // Starttexte nur ohne wartenden Besucher anlegen: Admin, Cron oder WP-CLI (T84).
        add_action('init', [self::class, 'maybeSeedInBackground'], 20);
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
            // REST für den Block-Editor; lesen nur mit Bearbeitungsrecht (T88, TipsRest).
            'public' => false, 'show_ui' => true, 'show_in_menu' => 'bootspruefung', 'show_in_rest' => true, 'rest_controller_class' => TipsPostsController::class,
            'supports' => ['title', 'editor', 'page-attributes', 'revisions'],
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => ['name' => __('Tipp-Kategorien', 'bootspruefung-learning'), 'singular_name' => __('Tipp-Kategorie', 'bootspruefung-learning')],
            'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'rest_controller_class' => TipsTermsController::class, 'hierarchical' => true, 'show_admin_column' => true,
        ]);
    }

    /** init: maybeSeed() nur im Admin, per Cron oder in der CLI (nie in Besucheranfragen). */
    public static function maybeSeedInBackground(): void
    {
        if (\Bootspruefung\Learning\Plugin::isBackgroundRequest()) {
            self::maybeSeed();
        }
    }

    /**
     * Starttexte einmalig anlegen (später gelöschte Tipps kommen nicht zurück). Auf bestehenden Installationen
     * werden bei neuem Textstand unbearbeitete Starttexte nachgezogen (updateDefaults).
     *
     * Gegen Doppelung (T84): Eine Sperre lässt nur einen Lauf gleichzeitig zu; danach wird der Stand frisch aus der
     * Datenbank geprüft, und ein Starttext mit gleichem Titel (jeder Status, auch Papierkorb) wird nicht noch einmal
     * angelegt.
     */
    public static function maybeSeed(): void
    {
        $seeded = get_option(self::SEEDED_OPTION);
        if ($seeded && get_option(self::VERSION_OPTION) === self::VERSION) {
            return;
        }
        if (! \Bootspruefung\Learning\Infrastructure\Lock::acquire(self::SEED_LOCK, 300)) {
            return; // ein anderer Lauf legt gerade an
        }
        try {
            // Stand nach dem Warten auf die Sperre frisch lesen (ein paralleler Lauf kann fertig sein).
            wp_cache_delete(self::SEEDED_OPTION, 'options');
            wp_cache_delete(self::VERSION_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            if (get_option(self::SEEDED_OPTION)) {
                if (get_option(self::VERSION_OPTION) !== self::VERSION) {
                    update_option(self::VERSION_OPTION, self::VERSION, false);
                    self::updateDefaults();
                }

                return;
            }
            update_option(self::SEEDED_OPTION, gmdate('Y-m-d H:i:s'), false);
            update_option(self::VERSION_OPTION, self::VERSION, false);
            $order = 0;
            foreach (StarterTips::all() as $category => $tips) {
                $term = term_exists($category, self::TAXONOMY);
                $termId = $term ? (int) (is_array($term) ? $term['term_id'] : $term) : (int) (wp_insert_term($category, self::TAXONOMY)['term_id'] ?? 0);
                foreach ($tips as [$title, $body]) {
                    $position = $order++;
                    if (self::exists((string) $title)) {
                        continue; // schon vorhanden (auch im Papierkorb): nicht doppelt anlegen
                    }
                    $id = wp_insert_post(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $body, 'menu_order' => $position], true);
                    if (! is_wp_error($id) && $termId > 0) {
                        wp_set_object_terms((int) $id, [$termId], self::TAXONOMY);
                    }
                }
            }
        } finally {
            \Bootspruefung\Learning\Infrastructure\Lock::release(self::SEED_LOCK);
        }
    }

    /** Gibt es schon einen Tipp mit diesem Titel (jeder Status außer automatischem Entwurf)? */
    private static function exists(string $title): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status <> 'auto-draft' LIMIT 1",
            self::POST_TYPE,
            $title
        ));
    }

    /**
     * Unbearbeitete Starttexte auf den aktuellen Text bringen: nur Tipps (nicht im Papierkorb) mit dem Titel eines
     * Starttexts, deren Text genau einer früheren Fassung entspricht. Vom Betreiber geänderte Tipps bleiben.
     *
     * @return list<int> aktualisierte Tipp-IDs
     */
    public static function updateDefaults(): array
    {
        $current = [];
        foreach (StarterTips::all() as $tips) {
            foreach ($tips as [$title, $body]) {
                $current[$title] = $body;
            }
        }
        $updated = [];
        foreach (StarterTips::previous() as $title => $oldBodies) {
            if (! isset($current[$title])) {
                continue;
            }
            $posts = get_posts(['post_type' => self::POST_TYPE, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'title' => $title, 'numberposts' => 20]);
            foreach ($posts as $post) {
                $content = trim((string) $post->post_content);
                if ($content !== $current[$title] && in_array($content, $oldBodies, true)) {
                    wp_update_post(['ID' => (int) $post->ID, 'post_content' => $current[$title]]);
                    $updated[] = (int) $post->ID;
                }
            }
        }

        return $updated;
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
