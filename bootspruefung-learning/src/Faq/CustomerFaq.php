<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Rest\Controllers\LearnerController;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\NotFound;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Kunden-FAQ: scheinspezifische Fragen und Antworten nur für Kunden eines Kurses (Kurs-Hub im
 * Web-Lernbereich, App). Eigener Beitragstyp bp_customer_faq (Frage = Titel, Antwort = Inhalt,
 * Reihenfolge = menu_order) mit hierarchischer Taxonomie bp_cfaq_category: oberste Ebene = Schein
 * (Term-Meta bp_cfaq_key = Kurscode), darunter Unterkategorien beliebiger Tiefe (bp_cfaq_order). Eine FAQ kann
 * mehreren Kategorien zugeordnet sein (gemeinsame FAQ für mehrere Scheine); sie erscheint in jedem zugeordneten
 * Schein, in einem Kombikurs aber nur einmal (CustomerFaqRepository).
 *
 * Nie öffentlich: keine Einzelseiten, Archive, Suche, Sitemap, kein Shortcode, kein JSON-LD, nicht in
 * /bootspruefung/v1/faqs. Die Core-REST-API (/wp/v2, für den Block-Editor) lesen nur Redakteure
 * (CustomerFaqRestGuard). Ausgeliefert wird nur über GET bootspruefung/v1/courses/{code}/customer-faqs
 * (Web) und GET boatingschool/v1/courses/{courseId}/faqs (App) – an Kunden des Kurses.
 *
 * Kunde = voller Zugang zum Fragenkatalog des Kurses (Kauf, manuelle Freigabe, Gruppe oder „Zugang ohne
 * Shop = alle angemeldeten Nutzer“); der kostenlose Teilumfang (Testkurs) zählt nicht.
 */
final class CustomerFaq
{
    public const POST_TYPE = 'bp_customer_faq';
    public const TAXONOMY = 'bp_cfaq_category';
    public const META_REF = '_bp_cfaq_ref';
    public const META_NOTE = '_bp_cfaq_note';
    public const TERM_KEY = 'bp_cfaq_key';
    public const TERM_ORDER = 'bp_cfaq_order';
    public const CACHE_GROUP = 'bp_cfaq';

    /** Schein-Schlüssel der Startkategorien (Kombis und Ergänzungen zeigen die ihrer Teil- bzw. Zielscheine). */
    public const SCHEIN_KEYS = ['SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-BIN-SEGEL', 'SRC', 'UBI', 'SKN', 'FKN'];

    public function register(): void
    {
        add_action('init', [self::class, 'registerTypes']);
        add_action('init', [CustomerFaqImporter::class, 'maybeImport'], 20);
        add_action('rest_api_init', [self::class, 'routes']);
        // Zusätzlich zu public=false: nie in der Sitemap, auch wenn ein Filter Typen ergänzt.
        add_filter('wp_sitemaps_post_types', static function (array $types): array {
            unset($types[self::POST_TYPE]);

            return $types;
        });
        add_filter('wp_sitemaps_taxonomies', static function (array $taxonomies): array {
            unset($taxonomies[self::TAXONOMY]);

            return $taxonomies;
        });
        FaqTerms::flushOnChange(self::POST_TYPE, self::TAXONOMY, [], [self::TERM_KEY, self::TERM_ORDER], [CustomerFaqRepository::class, 'flush']);
    }

    public static function registerTypes(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }
        $cap = Capabilities::MANAGE_CONTENT;
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Kunden-FAQs', 'bootspruefung-learning'),
                'singular_name' => __('Kunden-FAQ', 'bootspruefung-learning'),
                'add_new' => __('Neue Kunden-FAQ', 'bootspruefung-learning'),
                'add_new_item' => __('Neue Kunden-FAQ anlegen', 'bootspruefung-learning'),
                'edit_item' => __('Kunden-FAQ bearbeiten', 'bootspruefung-learning'),
                'new_item' => __('Neue Kunden-FAQ', 'bootspruefung-learning'),
                'search_items' => __('Kunden-FAQs durchsuchen', 'bootspruefung-learning'),
                'not_found' => __('Keine Kunden-FAQs gefunden.', 'bootspruefung-learning'),
                'not_found_in_trash' => __('Keine Kunden-FAQs im Papierkorb.', 'bootspruefung-learning'),
                'all_items' => __('Alle Kunden-FAQs', 'bootspruefung-learning'),
                'menu_name' => __('Kunden-FAQs', 'bootspruefung-learning'),
                'item_published' => __('Kunden-FAQ veröffentlicht.', 'bootspruefung-learning'),
                'item_updated' => __('Kunden-FAQ aktualisiert.', 'bootspruefung-learning'),
            ],
            'description' => __('Scheinspezifische Fragen und Antworten, nur für Kunden des Kurses sichtbar.', 'bootspruefung-learning'),
            'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true, 'has_archive' => false, 'rewrite' => false, 'query_var' => false,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_nav_menus' => false, 'show_in_admin_bar' => false,
            // Block-Editor braucht die Core-REST-API; lesen dürfen dort nur Redakteure.
            'show_in_rest' => true,
            'rest_controller_class' => CustomerFaqPostsController::class,
            'supports' => ['title', 'editor', 'page-attributes', 'revisions'],
            'map_meta_cap' => false,
            'capabilities' => array_fill_keys([
                'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts',
                'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts',
            ], $cap),
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => [
                'name' => __('Kunden-FAQ-Kategorien', 'bootspruefung-learning'),
                'singular_name' => __('Kunden-FAQ-Kategorie', 'bootspruefung-learning'),
                'menu_name' => __('Kunden-FAQ-Kategorien', 'bootspruefung-learning'),
                'add_new_item' => __('Neue Kunden-FAQ-Kategorie', 'bootspruefung-learning'),
                'edit_item' => __('Kunden-FAQ-Kategorie bearbeiten', 'bootspruefung-learning'),
                'parent_item' => __('Übergeordnet (Schein oder Unterkategorie)', 'bootspruefung-learning'),
                'parent_item_colon' => __('Übergeordnet:', 'bootspruefung-learning'),
                'search_items' => __('Kunden-FAQ-Kategorien durchsuchen', 'bootspruefung-learning'),
                'not_found' => __('Keine Kunden-FAQ-Kategorien gefunden.', 'bootspruefung-learning'),
            ],
            'public' => false, 'publicly_queryable' => false, 'rewrite' => false,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_nav_menus' => false, 'show_tagcloud' => false,
            'show_in_rest' => true, 'rest_controller_class' => CustomerFaqTermsController::class,
            'hierarchical' => true, 'show_admin_column' => false,
            'capabilities' => array_fill_keys(['manage_terms', 'edit_terms', 'delete_terms', 'assign_terms'], $cap),
        ]);
        foreach ([self::META_REF => 'integer', self::META_NOTE => 'string'] as $key => $type) {
            register_post_meta(self::POST_TYPE, $key, ['type' => $type, 'single' => true, 'show_in_rest' => false]);
        }
        register_term_meta(self::TAXONOMY, self::TERM_KEY, ['type' => 'string', 'single' => true, 'show_in_rest' => false]);
        register_term_meta(self::TAXONOMY, self::TERM_ORDER, ['type' => 'integer', 'single' => true, 'show_in_rest' => false]);
    }

    /** Kunde des Kurses = voller Zugang zum Fragenkatalog (kein kostenloser Teilumfang). */
    public static function isCustomer(int $userId, string $courseCode, ?AccessService $access = null): bool
    {
        return $userId > 0 && ($access ?? new AccessService())->full($userId, $courseCode, AccessService::QUESTION_BANK);
    }

    /** Meldung bei fehlendem Kundenzugang (Web und App). */
    public static function deniedMessage(): string
    {
        return __('Die Fragen und Antworten stehen Kunden dieses Kurses zur Verfügung.', 'bootspruefung-learning');
    }

    public static function routes(): void
    {
        register_rest_route(RestRegistrar::NAMESPACE, '/courses/(?P<code>[A-Za-z0-9-]+)/customer-faqs', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => LearnerController::wrap(static function (WP_REST_Request $request): array {
                $course = (new ContentRepository())->courseByCode(strtoupper((string) $request['code']));
                if ($course === null) {
                    throw new NotFound(__('Kurs nicht gefunden.', 'bootspruefung-learning'));
                }
                if (! self::isCustomer(get_current_user_id(), (string) $course->code)) {
                    throw new AccessDenied(self::deniedMessage());
                }

                return CustomerFaqRepository::forCourse((string) $course->code);
            }),
            'permission_callback' => static fn (): bool|WP_Error => is_user_logged_in() ? true : new WP_Error('bp_login_required', __('Bitte anmelden.', 'bootspruefung-learning'), ['status' => 401]),
            'args' => ['code' => ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9-]+$']],
        ]);
    }
}
