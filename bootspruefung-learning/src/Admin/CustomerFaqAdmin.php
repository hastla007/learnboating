<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Faq\CustomerFaq;
use Bootspruefung\Learning\Faq\CustomerFaqImporter;
use Bootspruefung\Learning\Faq\DefaultCustomerFaqs;
use Bootspruefung\Learning\Faq\FaqTerms;
use Bootspruefung\Learning\Security\Capabilities;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Adminbereich Kunden-FAQ (Bootsprüfung › Kunden-FAQs): Spalten Nr., Schein, Unterkategorie (Pfad) und
 * Hinweis, Filter nach Schein, Metabox (Redaktionshinweis), Term-Felder (Schlüssel = Kurscode, Reihenfolge)
 * sowie der Knopf „Fehlende Start-FAQs importieren“. Gemeinsame Teile mit den öffentlichen FAQ: TermFaqAdmin.
 */
final class CustomerFaqAdmin extends TermFaqAdmin
{
    public const LIST_URL = 'edit.php?post_type='.CustomerFaq::POST_TYPE;
    public const TERMS_URL = 'edit-tags.php?taxonomy='.CustomerFaq::TAXONOMY.'&post_type='.CustomerFaq::POST_TYPE;
    private const NONCE = 'bp_cfaq_meta';

    public function register(): void
    {
        $this->registerShared();
        add_filter('manage_'.CustomerFaq::POST_TYPE.'_posts_columns', [$this, 'columns']);
        add_action('manage_'.CustomerFaq::POST_TYPE.'_posts_custom_column', [$this, 'column'], 10, 2);
        add_filter('manage_edit-'.CustomerFaq::POST_TYPE.'_sortable_columns', static fn (array $c): array => $c + ['bp_cfaq_ref' => 'bp_cfaq_ref']);
        add_action('restrict_manage_posts', [$this, 'scheinFilter']);
        add_action('pre_get_posts', [$this, 'listQuery']);
        add_action('add_meta_boxes_'.CustomerFaq::POST_TYPE, [$this, 'metaBox']);
        add_action('save_post_'.CustomerFaq::POST_TYPE, [$this, 'saveMeta'], 5, 2);
        add_action('admin_notices', [$this, 'listPanel']);
        add_action('admin_post_bp_cfaq_import', [$this, 'handleImport']);
    }

    /** @param array<string,string> $columns @return array<string,string> */
    public function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $out['bp_cfaq_ref'] = __('Nr.', 'bootspruefung-learning');
                $out[$key] = __('Frage', 'bootspruefung-learning');
                $out['bp_cfaq_schein'] = __('Schein', 'bootspruefung-learning');
                $out['bp_cfaq_sub'] = __('Unterkategorie', 'bootspruefung-learning');
                $out['bp_cfaq_note'] = __('Hinweis', 'bootspruefung-learning');
                continue;
            }
            $out[$key] = $label;
        }

        return $out;
    }

    public function column(string $column, int $postId): void
    {
        switch ($column) {
            case 'bp_cfaq_ref':
                $ref = get_post_meta($postId, CustomerFaq::META_REF, true);
                echo $ref === '' ? '–' : esc_html((string) (int) $ref);
                break;
            case 'bp_cfaq_schein':
                [$top] = self::placementIn($postId, CustomerFaq::TAXONOMY);
                if (! $top) {
                    echo '<span title="'.esc_attr__('Ohne Schein erscheint die FAQ in keinem Kurs.', 'bootspruefung-learning').'">–</span>';
                    break;
                }
                $url = add_query_arg([CustomerFaq::TAXONOMY => $top->slug], admin_url(self::LIST_URL));
                echo '<a href="'.esc_url($url).'">'.esc_html($top->name).'</a>';
                break;
            case 'bp_cfaq_sub':
                [, $sub] = self::placementIn($postId, CustomerFaq::TAXONOMY);
                echo $sub ? esc_html(self::path($sub)) : '–';
                break;
            case 'bp_cfaq_note':
                $note = (string) get_post_meta($postId, CustomerFaq::META_NOTE, true);
                echo $note !== '' ? '<span class="dashicons dashicons-edit-page" title="'.esc_attr($note).'" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html($note).'</span>' : '–';
                break;
        }
    }

    /** Pfad einer Unterkategorie unterhalb des Scheins, z. B. „Prüfungssimulation › Offene Fragen“. */
    private static function path(WP_Term $term): string
    {
        $names = [html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')];
        $current = $term;
        for ($i = 0; $current->parent !== 0 && $i < 10; $i++) {
            $parent = get_term($current->parent, CustomerFaq::TAXONOMY);
            if (! $parent instanceof WP_Term || $parent->parent === 0) {
                break;
            }
            array_unshift($names, html_entity_decode($parent->name, ENT_QUOTES, 'UTF-8'));
            $current = $parent;
        }

        return implode(' › ', $names);
    }

    public function scheinFilter(string $postType): void
    {
        if ($postType !== CustomerFaq::POST_TYPE) {
            return;
        }
        $current = sanitize_title((string) ($_GET[CustomerFaq::TAXONOMY] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        $tops = array_filter(FaqTerms::map(CustomerFaq::TAXONOMY, CustomerFaq::TERM_KEY, CustomerFaq::TERM_ORDER), static fn ($t) => $t['parent'] === 0);
        usort($tops, static fn ($a, $b) => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);
        echo '<label for="bp-cfaq-schein" class="screen-reader-text">'.esc_html__('Nach Schein filtern', 'bootspruefung-learning').'</label>';
        echo '<select name="'.esc_attr(CustomerFaq::TAXONOMY).'" id="bp-cfaq-schein"><option value="">'.esc_html__('Alle Scheine', 'bootspruefung-learning').'</option>';
        foreach ($tops as $term) {
            printf('<option value="%s" %s>%s</option>', esc_attr($term['slug']), selected($current, $term['slug'], false), esc_html($term['name']));
        }
        echo '</select>';
    }

    /** Liste standardmäßig nach Nummer sortieren; Spalte „Nr.“ sortierbar. */
    public function listQuery(WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== CustomerFaq::POST_TYPE) {
            return;
        }
        $orderby = $query->get('orderby');
        if ($orderby === 'bp_cfaq_ref' || $orderby === '' || $orderby === null) {
            $query->set('meta_query', [
                'relation' => 'OR',
                'bp_ref' => ['key' => CustomerFaq::META_REF, 'type' => 'NUMERIC'],
                ['key' => CustomerFaq::META_REF, 'compare' => 'NOT EXISTS'],
            ]);
            $query->set('orderby', ['bp_ref' => $query->get('order') === 'desc' ? 'DESC' : 'ASC', 'ID' => 'ASC']);
        }
    }

    public function metaBox(): void
    {
        add_meta_box('bp-cfaq-settings', __('Kunden-FAQ', 'bootspruefung-learning'), [$this, 'renderMetaBox'], CustomerFaq::POST_TYPE, 'side', 'high');
    }

    public function renderMetaBox(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE, '_bp_cfaq_nonce');
        $ref = get_post_meta($post->ID, CustomerFaq::META_REF, true);
        if ($ref !== '') {
            echo '<p>'.esc_html(sprintf(__('Start-FAQ Nr. %d', 'bootspruefung-learning'), (int) $ref)).'</p>';
        }
        echo '<p class="description">'.esc_html__('Nur für Kunden sichtbar: im Kurs-Hub („Fragen & Antworten zu deinem Kurs“) und in der App, wenn die FAQ veröffentlicht ist. Kombi- und Ergänzungskurse zeigen die FAQs ihrer Teil- bzw. Zielscheine.', 'bootspruefung-learning').'</p>';
        self::renderNoteField($post, CustomerFaq::META_NOTE, 'bp_cfaq_note');
        echo '<p class="description">'.esc_html__('Schein und Unterkategorie unter „Kunden-FAQ-Kategorien“ wählen (die Unterkategorie genügt). Reihenfolge: „Seiten-Attribute › Reihenfolge“. Platzhalter {{agb}} wird zum Link auf die AGB.', 'bootspruefung-learning').'</p>';
    }

    public function saveMeta(int $postId, WP_Post $post): void
    {
        if (! isset($_POST['_bp_cfaq_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_bp_cfaq_nonce'])), self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || ! current_user_can(Capabilities::MANAGE_CONTENT)) {
            return;
        }
        self::saveNote($postId, CustomerFaq::META_NOTE, 'bp_cfaq_note');
    }

    /** Kurzinfo und Import-Knopf über der Liste. */
    public function listPanel(): void
    {
        $screen = get_current_screen();
        if (! $screen || $screen->id !== 'edit-'.CustomerFaq::POST_TYPE || ! current_user_can(Capabilities::MANAGE_CONTENT)) {
            return;
        }
        Ui::notice();
        $missing = DefaultCustomerFaqs::total() - count(array_unique(CustomerFaqImporter::existingRefs()));
        echo '<style>.post-type-bp_customer_faq .column-bp_cfaq_ref{width:4em}.post-type-bp_customer_faq .column-bp_cfaq_note{width:5.5em}.post-type-bp_customer_faq .column-bp_cfaq_schein{width:12%}.post-type-bp_customer_faq .column-bp_cfaq_sub{width:18%}</style>';
        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Kunden-FAQs sind nur für Kunden eines Kurses sichtbar (voller Zugang zum Fragenkatalog, kein kostenloser Testkurs): im Kurs-Hub des Web-Lernbereichs und in der App. Nie öffentlich, nicht im FAQ-Shortcode. Es erscheinen nur veröffentlichte FAQs; das Startpaket liegt als Entwurf vor.', 'bootspruefung-learning');
        echo '</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0 0 10px">';
        wp_nonce_field('bp_cfaq_import');
        echo '<input type="hidden" name="action" value="bp_cfaq_import"><button type="submit" class="button">'.esc_html($missing > 0
            ? sprintf(__('Fehlende Start-FAQs importieren (%d)', 'bootspruefung-learning'), $missing)
            : __('Fehlende Start-FAQs importieren', 'bootspruefung-learning')).'</button></form></div>';
    }

    public function handleImport(): void
    {
        check_admin_referer('bp_cfaq_import');
        if (! current_user_can(Capabilities::MANAGE_CONTENT)) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $stats = CustomerFaqImporter::import();
        update_option(CustomerFaqImporter::OPTION_VERSION, CustomerFaqImporter::VERSION, true);
        Ui::flash($stats['created'] > 0
            ? sprintf(__('%1$d Start-FAQs als Entwurf importiert, %2$d Kategorien angelegt. Vorhandene FAQs blieben unverändert.', 'bootspruefung-learning'), $stats['created'], $stats['terms'])
            : __('Alle Start-FAQs sind schon vorhanden. Nichts geändert.', 'bootspruefung-learning'));
        wp_safe_redirect(admin_url(self::LIST_URL));
        exit;
    }

    protected function postType(): string
    {
        return CustomerFaq::POST_TYPE;
    }

    protected function taxonomy(): string
    {
        return CustomerFaq::TAXONOMY;
    }

    protected function keyMeta(): string
    {
        return CustomerFaq::TERM_KEY;
    }

    protected function orderMeta(): string
    {
        return CustomerFaq::TERM_ORDER;
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
            'key_add' => __('Schlüssel (nur Schein)', 'bootspruefung-learning'),
            'order_add' => __('Kleinere Zahl = weiter oben (Scheine untereinander, Unterkategorien innerhalb ihrer Ebene).', 'bootspruefung-learning'),
        ];
    }

    protected function keyHelp(): string
    {
        return sprintf(
            __('Für Scheine (oberste Ebene) der Kurscode: %s. Kombi- und Ergänzungskurse zeigen die FAQs ihrer Teil- bzw. Zielscheine; für kombi-spezifische Fragen eine eigene Kategorie mit dem Kurscode anlegen (z. B. SBF-SEE-BIN, SRC-UBI).', 'bootspruefung-learning'),
            implode(', ', CustomerFaq::SCHEIN_KEYS)
        );
    }
}
