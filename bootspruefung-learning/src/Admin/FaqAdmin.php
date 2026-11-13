<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Faq\DefaultFaqs;
use Bootspruefung\Learning\Faq\Faq;
use Bootspruefung\Learning\Faq\FaqImporter;
use Bootspruefung\Learning\Security\Capabilities;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Adminbereich FAQ (Bootsprüfung › FAQ): Spalten Nr., Rubrik, Unterkategorie, „Im Theme“ (★) und
 * Hinweis, Filter nach Rubrik, Metabox (★, Redaktionshinweis), Term-Felder (Schlüssel, Reihenfolge)
 * sowie die Knöpfe „Fehlende Standard-FAQs importieren“ und „FAQ-Seite anlegen“.
 */
final class FaqAdmin extends TermFaqAdmin
{
    public const LIST_URL = 'edit.php?post_type='.Faq::POST_TYPE;
    public const TERMS_URL = 'edit-tags.php?taxonomy='.Faq::TAXONOMY.'&post_type='.Faq::POST_TYPE;
    private const NONCE = 'bp_faq_meta';

    public function register(): void
    {
        $this->registerShared(); // Menü offen halten, Term-Felder Schlüssel und Reihenfolge
        add_filter('manage_'.Faq::POST_TYPE.'_posts_columns', [$this, 'columns']);
        add_action('manage_'.Faq::POST_TYPE.'_posts_custom_column', [$this, 'column'], 10, 2);
        add_filter('manage_edit-'.Faq::POST_TYPE.'_sortable_columns', static fn (array $c): array => $c + ['bp_faq_ref' => 'bp_faq_ref']);
        add_action('restrict_manage_posts', [$this, 'rubricFilter']);
        add_action('pre_get_posts', [$this, 'listQuery']);
        add_action('add_meta_boxes_'.Faq::POST_TYPE, [$this, 'metaBox']);
        add_action('save_post_'.Faq::POST_TYPE, [$this, 'saveMeta'], 5, 2);
        add_action('admin_notices', [$this, 'listPanel']);
        add_action('admin_post_bp_faq_import', [$this, 'handleImport']);
        add_action('admin_post_bp_faq_page', [$this, 'handlePage']);
    }

    /** @param array<string,string> $columns @return array<string,string> */
    public function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $out['bp_faq_ref'] = __('Nr.', 'bootspruefung-learning');
                $out[$key] = __('Frage', 'bootspruefung-learning');
                $out['bp_faq_rubric'] = __('Rubrik', 'bootspruefung-learning');
                $out['bp_faq_sub'] = __('Unterkategorie', 'bootspruefung-learning');
                $out['bp_faq_theme'] = __('Im Theme', 'bootspruefung-learning');
                $out['bp_faq_note'] = __('Hinweis', 'bootspruefung-learning');
                continue;
            }
            $out[$key] = $label;
        }

        return $out;
    }

    public function column(string $column, int $postId): void
    {
        switch ($column) {
            case 'bp_faq_ref':
                $ref = get_post_meta($postId, Faq::META_REF, true);
                echo $ref === '' ? '–' : esc_html((string) (int) $ref);
                break;
            case 'bp_faq_rubric':
            case 'bp_faq_sub':
                [$rubric, $sub] = self::placementIn($postId, Faq::TAXONOMY);
                $term = $column === 'bp_faq_rubric' ? $rubric : $sub;
                if (! $term) {
                    echo $column === 'bp_faq_rubric' ? '<span title="'.esc_attr__('Ohne Rubrik erscheint die FAQ unter „Weitere Fragen“.', 'bootspruefung-learning').'">–</span>' : '–';
                    break;
                }
                $url = add_query_arg([Faq::TAXONOMY => $term->slug], admin_url(self::LIST_URL));
                echo '<a href="'.esc_url($url).'">'.esc_html($term->name).'</a>';
                break;
            case 'bp_faq_theme':
                echo get_post_meta($postId, Faq::META_THEME, true)
                    ? '<span aria-hidden="true">★</span><span class="screen-reader-text">'.esc_html__('ja', 'bootspruefung-learning').'</span>'
                    : '<span aria-hidden="true">–</span><span class="screen-reader-text">'.esc_html__('nein', 'bootspruefung-learning').'</span>';
                break;
            case 'bp_faq_note':
                $note = (string) get_post_meta($postId, Faq::META_NOTE, true);
                echo $note !== '' ? '<span class="dashicons dashicons-edit-page" title="'.esc_attr($note).'" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html($note).'</span>' : '–';
                break;
        }
    }

    public function rubricFilter(string $postType): void
    {
        if ($postType !== Faq::POST_TYPE) {
            return;
        }
        $current = sanitize_title((string) ($_GET[Faq::TAXONOMY] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        echo '<label for="bp-faq-rubric" class="screen-reader-text">'.esc_html__('Nach Rubrik filtern', 'bootspruefung-learning').'</label>';
        echo '<select name="'.esc_attr(Faq::TAXONOMY).'" id="bp-faq-rubric"><option value="">'.esc_html__('Alle Rubriken', 'bootspruefung-learning').'</option>';
        foreach (\Bootspruefung\Learning\Faq\FaqRepository::rubrics() as $rubric) {
            printf('<option value="%s" %s>%s</option>', esc_attr($rubric['slug']), selected($current, $rubric['slug'], false), esc_html($rubric['label']));
        }
        echo '</select>';
    }

    /** Liste standardmäßig nach Nummer sortieren; Spalte „Nr.“ sortierbar. */
    public function listQuery(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== Faq::POST_TYPE) {
            return;
        }
        $orderby = $query->get('orderby');
        if ($orderby === 'bp_faq_ref' || $orderby === '' || $orderby === null) {
            $query->set('meta_query', [
                'relation' => 'OR',
                'bp_ref' => ['key' => Faq::META_REF, 'type' => 'NUMERIC'],
                ['key' => Faq::META_REF, 'compare' => 'NOT EXISTS'],
            ]);
            $query->set('orderby', ['bp_ref' => $query->get('order') === 'desc' ? 'DESC' : 'ASC', 'ID' => 'ASC']);
        }
    }

    public function metaBox(): void
    {
        add_meta_box('bp-faq-settings', __('FAQ-Einstellungen', 'bootspruefung-learning'), [$this, 'renderMetaBox'], Faq::POST_TYPE, 'side', 'high');
    }

    public function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE, '_bp_faq_nonce');
        $ref = get_post_meta($post->ID, Faq::META_REF, true);
        if ($ref !== '') {
            echo '<p>'.esc_html(sprintf(__('Standard-FAQ Nr. %d', 'bootspruefung-learning'), (int) $ref)).'</p>';
        }
        printf(
            '<p><label><input type="checkbox" name="bp_faq_theme" value="1" %s> %s</label></p>',
            checked((bool) get_post_meta($post->ID, Faq::META_THEME, true), true, false),
            esc_html__('Im Theme anzeigen (★)', 'bootspruefung-learning')
        );
        echo '<p class="description">'.esc_html__('★-FAQs erscheinen im Theme: „Allgemeine Fragen“ auf Startseite und Shopseiten, die Rubrik eines Scheins auf dessen Kursseite.', 'bootspruefung-learning').'</p>';
        self::renderNoteField($post, Faq::META_NOTE, 'bp_faq_note');
        echo '<p class="description">'.esc_html__('Rubrik und Unterkategorie unter „FAQ-Kategorien“ wählen (die Unterkategorie genügt). Reihenfolge innerhalb der Unterkategorie: „Seiten-Attribute › Reihenfolge“. Platzhalter {{agb}} wird zum Link auf die AGB.', 'bootspruefung-learning').'</p>';
    }

    public function saveMeta(int $postId, WP_Post $post): void
    {
        if (! isset($_POST['_bp_faq_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_bp_faq_nonce'])), self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || ! current_user_can(Capabilities::MANAGE_CONTENT)) {
            return;
        }
        update_post_meta($postId, Faq::META_THEME, ! empty($_POST['bp_faq_theme']) ? '1' : '0');
        self::saveNote($postId, Faq::META_NOTE, 'bp_faq_note');
    }

    /** Knöpfe und Kurzinfo über der FAQ-Liste. */
    public function listPanel(): void
    {
        $screen = get_current_screen();
        if (! $screen || $screen->id !== 'edit-'.Faq::POST_TYPE || ! current_user_can(Capabilities::MANAGE_CONTENT)) {
            return;
        }
        Ui::notice();
        $total = 0;
        foreach (DefaultFaqs::RUBRICS as $rubric) {
            foreach ($rubric['subcategories'] as $sub) {
                $total += count($sub['faqs']);
            }
        }
        $missing = $total - count(array_unique(FaqImporter::existingRefs()));
        $page = self::faqPage();
        echo '<style>.post-type-bp_faq .column-bp_faq_ref{width:4em}.post-type-bp_faq .column-bp_faq_theme,.post-type-bp_faq .column-bp_faq_note{width:5.5em}.post-type-bp_faq .column-bp_faq_rubric,.post-type-bp_faq .column-bp_faq_sub{width:14%}</style>';
        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Ausgabe: Shortcode [bootspruefung_faq] (ganze Sektion; Attribute rubric, theme_only, subcategory, title, search), im Theme über bootspruefung_faqs() und per REST GET /wp-json/bootspruefung/v1/faqs. Es erscheinen nur veröffentlichte FAQs.', 'bootspruefung-learning');
        echo '</p><div style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin:0 0 10px">';
        $form = static function (string $action, string $label, bool $primary = false): void {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0">';
            wp_nonce_field($action);
            echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><button type="submit" class="button'.($primary ? ' button-primary' : '').'">'.esc_html($label).'</button></form>';
        };
        $form('bp_faq_import', $missing > 0
            ? sprintf(__('Fehlende Standard-FAQs importieren (%d)', 'bootspruefung-learning'), $missing)
            : __('Fehlende Standard-FAQs importieren', 'bootspruefung-learning'));
        if ($page) {
            printf(
                '<span>%s <a href="%s">%s</a> · <a href="%s">%s</a></span>',
                esc_html__('FAQ-Seite:', 'bootspruefung-learning'),
                esc_url((string) get_permalink($page)),
                esc_html(get_the_title($page)),
                esc_url((string) get_edit_post_link($page->ID)),
                esc_html__('bearbeiten', 'bootspruefung-learning')
            );
        } else {
            $form('bp_faq_page', __('FAQ-Seite anlegen', 'bootspruefung-learning'));
        }
        echo '</div></div>';
    }

    public function handleImport(): void
    {
        check_admin_referer('bp_faq_import');
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $stats = FaqImporter::import();
        update_option(FaqImporter::OPTION_VERSION, FaqImporter::VERSION, true);
        Ui::flash($stats['created'] > 0
            ? sprintf(__('%1$d Standard-FAQs importiert (davon %2$d als Entwurf), %3$d Kategorien angelegt. Vorhandene FAQs blieben unverändert.', 'bootspruefung-learning'), $stats['created'], count($stats['drafts']), $stats['terms'])
            : __('Alle Standard-FAQs sind schon vorhanden. Nichts geändert.', 'bootspruefung-learning'));
        wp_safe_redirect(admin_url(self::LIST_URL));
        exit;
    }

    public function handlePage(): void
    {
        check_admin_referer('bp_faq_page');
        if (! current_user_can(Capabilities::MANAGE_CONTENT) || ! current_user_can('publish_pages')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $result = self::createPage();
        Ui::flash($result['created']
            ? __('Seite „Häufige Fragen zum Bootsführerschein“ angelegt und veröffentlicht.', 'bootspruefung-learning')
            : __('Es gibt schon eine FAQ-Seite.', 'bootspruefung-learning'), $result['created'] ? 'success' : 'info');
        wp_safe_redirect(admin_url(self::LIST_URL));
        exit;
    }

    /** Slug der FAQ-Seite (wie die Inhaltsseite 34 des Themes Kurswasser). */
    public const PAGE_SLUG = 'faq-bootsfuehrerschein';

    /** Legt die Seite „Häufige Fragen zum Bootsführerschein“ mit dem Shortcode an, falls es noch keine gibt. @return array{id:int,created:bool} */
    public static function createPage(): array
    {
        $page = self::faqPage();
        if ($page) {
            return ['id' => (int) $page->ID, 'created' => false];
        }
        $id = wp_insert_post(wp_slash([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Häufige Fragen zum Bootsführerschein', 'bootspruefung-learning'),
            'post_name' => self::PAGE_SLUG,
            'post_content' => "<!-- wp:shortcode -->\n[bootspruefung_faq]\n<!-- /wp:shortcode -->",
            'comment_status' => 'closed',
        ]), true);
        if (is_wp_error($id)) {
            return ['id' => 0, 'created' => false];
        }
        update_option(Faq::PAGE_OPTION, (int) $id, false);

        return ['id' => (int) $id, 'created' => true];
    }

    /**
     * Vorhandene FAQ-Seite (nicht im Papierkorb): gemerkte Seite, sonst die Seite /faq-bootsfuehrerschein/, sonst
     * irgendeine Seite mit dem Shortcode – aber keine mit einem FAQ-Auszug (schema="0", z. B. auf Ratgeberseiten).
     */
    public static function faqPage(): ?WP_Post
    {
        $id = (int) get_option(Faq::PAGE_OPTION);
        $page = $id > 0 ? get_post($id) : null;
        if ($page instanceof WP_Post && $page->post_type === 'page' && $page->post_status !== 'trash') {
            return $page;
        }
        $page = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        if ($page instanceof WP_Post && $page->post_status !== 'trash' && str_contains($page->post_content, '[bootspruefung_faq')) {
            return $page;
        }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('trash','auto-draft','inherit') AND post_content LIKE %s ORDER BY ID LIMIT 50",
            '%'.$wpdb->esc_like('[bootspruefung_faq').'%'
        ));
        foreach ($ids as $found) {
            $post = get_post((int) $found);
            if ($post instanceof WP_Post && self::isFaqPageContent($post->post_content)) {
                return $post;
            }
        }

        return null;
    }

    /** Enthält der Inhalt den FAQ-Shortcode als ganze FAQ-Sektion (nicht als Auszug mit schema="0")? */
    public static function isFaqPageContent(string $content): bool
    {
        if (! preg_match_all('/\[bootspruefung_faq\b([^\]]*)\]/', $content, $m)) {
            return false;
        }
        foreach ($m[1] as $attrs) {
            $atts = shortcode_parse_atts($attrs);
            $schema = is_array($atts) ? ($atts['schema'] ?? '1') : '1';
            if (filter_var($schema, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    protected function postType(): string
    {
        return Faq::POST_TYPE;
    }

    protected function taxonomy(): string
    {
        return Faq::TAXONOMY;
    }

    protected function keyMeta(): string
    {
        return Faq::TERM_KEY;
    }

    protected function orderMeta(): string
    {
        return Faq::TERM_ORDER;
    }

    protected function listUrl(): string
    {
        return self::LIST_URL;
    }

    protected function termsUrl(): string
    {
        return self::TERMS_URL;
    }

    protected function termLabels(): array
    {
        return [
            'key_add' => __('Schlüssel (nur Rubrik)', 'bootspruefung-learning'),
            'order_add' => __('Kleinere Zahl = weiter oben (Rubriken untereinander, Unterkategorien innerhalb ihrer Rubrik).', 'bootspruefung-learning'),
        ];
    }

    protected function keyHelp(): string
    {
        return sprintf(__('Für Rubriken (oberste Ebene): %s. Das Theme sucht die ★-FAQs eines Kurses über diesen Schlüssel.', 'bootspruefung-learning'), implode(', ', Faq::RUBRIC_KEYS));
    }
}
