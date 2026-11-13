<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

use Bootspruefung\Learning\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Server;

/**
 * FAQ-Sektion: Beitragstyp bp_faq (Frage = Titel, Antwort = Inhalt) mit hierarchischer
 * Taxonomie bp_faq_category (oberste Ebene = Rubrik: „allgemein“ bzw. ein Schein/Produkt,
 * zweite Ebene = Unterkategorie). ★ (_bp_faq_theme) markiert FAQs, die auch das Theme zeigt.
 *
 * Ausgabe: bootspruefung_faqs() (Theme), Shortcode [bootspruefung_faq], REST GET /bootspruefung/v1/faqs.
 */
final class Faq
{
    public const POST_TYPE = 'bp_faq';
    public const TAXONOMY = 'bp_faq_category';
    public const META_REF = '_bp_faq_ref';
    public const META_THEME = '_bp_faq_theme';
    public const META_NOTE = '_bp_faq_note';
    public const TERM_KEY = 'bp_faq_key';
    public const TERM_ORDER = 'bp_faq_order';
    public const CACHE_GROUP = 'bp_faq';
    /** Seite „Häufige Fragen“, die der Knopf im Admin anlegt. */
    public const PAGE_OPTION = 'bp_learning_faq_page_id';

    /** Rubrik-Schlüssel (Term-Meta bp_faq_key): „allgemein“ und die Kurs- bzw. Produktcodes. */
    public const RUBRIC_KEYS = ['allgemein', 'SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-BIN-SEGEL', 'SRC', 'UBI', 'SKN', 'FKN', 'SBF-SEE-BIN', 'KAPITAEN', 'SRC-UBI'];

    public function register(): void
    {
        add_action('init', [self::class, 'registerTypes']);
        // Standard-FAQs nur ohne wartenden Besucher anlegen bzw. aktualisieren: Admin, Cron oder WP-CLI (T84).
        add_action('init', [self::class, 'maybeImportInBackground'], 20);
        add_action('rest_api_init', [self::class, 'routes']);
        add_shortcode('bootspruefung_faq', [FaqShortcode::class, 'render']);
        self::registerCacheInvalidation();
    }

    /** init: FaqImporter::maybeImport() nur im Admin, per Cron oder in der CLI (nie in Besucheranfragen). */
    public static function maybeImportInBackground(): void
    {
        if (\Bootspruefung\Learning\Plugin::isBackgroundRequest()) {
            FaqImporter::maybeImport();
        }
    }

    public static function registerTypes(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }
        $cap = Capabilities::MANAGE_CONTENT;
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('FAQ', 'bootspruefung-learning'),
                'singular_name' => __('FAQ', 'bootspruefung-learning'),
                'add_new' => __('Neue FAQ', 'bootspruefung-learning'),
                'add_new_item' => __('Neue FAQ anlegen', 'bootspruefung-learning'),
                'edit_item' => __('FAQ bearbeiten', 'bootspruefung-learning'),
                'new_item' => __('Neue FAQ', 'bootspruefung-learning'),
                'search_items' => __('FAQ durchsuchen', 'bootspruefung-learning'),
                'not_found' => __('Keine FAQ gefunden.', 'bootspruefung-learning'),
                'not_found_in_trash' => __('Keine FAQ im Papierkorb.', 'bootspruefung-learning'),
                'all_items' => __('Alle FAQ', 'bootspruefung-learning'),
                'menu_name' => __('FAQ', 'bootspruefung-learning'),
                'item_published' => __('FAQ veröffentlicht.', 'bootspruefung-learning'),
                'item_updated' => __('FAQ aktualisiert.', 'bootspruefung-learning'),
            ],
            'description' => __('Häufige Fragen mit Rubriken und Unterkategorien.', 'bootspruefung-learning'),
            // Keine Einzelseiten und Archive: ausgegeben wird über Shortcode, Theme-Funktion und REST.
            'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true, 'has_archive' => false, 'rewrite' => false, 'query_var' => false,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_nav_menus' => false, 'show_in_admin_bar' => false,
            'show_in_rest' => true, // Block-Editor
            'supports' => ['title', 'editor', 'page-attributes', 'revisions'],
            'map_meta_cap' => false,
            'capabilities' => array_fill_keys([
                'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts',
                'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts',
            ], $cap),
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => [
                'name' => __('FAQ-Kategorien', 'bootspruefung-learning'),
                'singular_name' => __('FAQ-Kategorie', 'bootspruefung-learning'),
                'menu_name' => __('FAQ-Kategorien', 'bootspruefung-learning'),
                'add_new_item' => __('Neue FAQ-Kategorie', 'bootspruefung-learning'),
                'edit_item' => __('FAQ-Kategorie bearbeiten', 'bootspruefung-learning'),
                'parent_item' => __('Rubrik', 'bootspruefung-learning'),
                'parent_item_colon' => __('Rubrik:', 'bootspruefung-learning'),
                'search_items' => __('FAQ-Kategorien durchsuchen', 'bootspruefung-learning'),
                'not_found' => __('Keine FAQ-Kategorien gefunden.', 'bootspruefung-learning'),
            ],
            'public' => false, 'publicly_queryable' => false, 'rewrite' => false,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_nav_menus' => false, 'show_tagcloud' => false,
            'show_in_rest' => true, 'hierarchical' => true, 'show_admin_column' => false,
            'capabilities' => array_fill_keys(['manage_terms', 'edit_terms', 'delete_terms', 'assign_terms'], $cap),
        ]);
        foreach ([self::META_REF => 'integer', self::META_THEME => 'boolean', self::META_NOTE => 'string'] as $key => $type) {
            register_post_meta(self::POST_TYPE, $key, ['type' => $type, 'single' => true, 'show_in_rest' => false]);
        }
        register_term_meta(self::TAXONOMY, self::TERM_KEY, ['type' => 'string', 'single' => true, 'show_in_rest' => false]);
        register_term_meta(self::TAXONOMY, self::TERM_ORDER, ['type' => 'integer', 'single' => true, 'show_in_rest' => false]);
    }

    public static function routes(): void
    {
        register_rest_route('bootspruefung/v1', '/faqs', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => static fn (WP_REST_Request $request) => rest_ensure_response(FaqRepository::query([
                'rubric' => (string) $request->get_param('rubric'),
                'theme_only' => (bool) $request->get_param('theme_only'),
                'subcategory' => (string) $request->get_param('subcategory'),
            ])),
            'permission_callback' => '__return_true', // nur veröffentlichte FAQs, ohne Redaktionshinweise
            'args' => [
                'rubric' => ['type' => 'string', 'default' => '', 'sanitize_callback' => static fn ($v): string => sanitize_text_field((string) $v)],
                'theme_only' => ['type' => 'boolean', 'default' => false],
                'subcategory' => ['type' => 'string', 'default' => '', 'sanitize_callback' => static fn ($v): string => sanitize_title((string) $v)],
            ],
        ]);
    }

    /** Zwischenspeicher leeren, sobald sich FAQs, ihre Kategorien oder Merkmale ändern. */
    private static function registerCacheInvalidation(): void
    {
        FaqTerms::flushOnChange(self::POST_TYPE, self::TAXONOMY, [self::META_REF, self::META_THEME], [self::TERM_KEY, self::TERM_ORDER], [FaqRepository::class, 'flush']);
    }
}
