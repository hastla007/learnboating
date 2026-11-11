<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

use Bootspruefung\Learning\Security\Capabilities;
use WP_Post;
use WP_Term;

/**
 * Gemeinsame Admin-Bausteine der FAQ-Bereiche mit hierarchischer Kategorie (öffentliche FAQ, Kunden-FAQ):
 * Menü „Bootsprüfung“ offen halten, Platzierung (oberste Ebene und tiefste Kategorie) einer FAQ,
 * Redaktionshinweis in der Metabox, Term-Felder „Schlüssel“ (nur oberste Ebene) und „Reihenfolge“.
 * Die Feldnamen der Term-Formulare sind die Meta-Schlüssel (z. B. bp_faq_key, bp_faq_order).
 */
abstract class TermFaqAdmin
{
    abstract protected function postType(): string;

    abstract protected function taxonomy(): string;

    abstract protected function keyMeta(): string;

    abstract protected function orderMeta(): string;

    abstract protected function listUrl(): string;

    abstract protected function termsUrl(): string;

    /** Hilfetext zum Schlüssel. */
    abstract protected function keyHelp(): string;

    /** @return array{key_add:string,order_add:string} Beschriftungen beim Anlegen eines Begriffs */
    abstract protected function termLabels(): array;

    /** Menü- und Term-Hooks registrieren (aus register() der Unterklasse). */
    protected function registerShared(): void
    {
        $tax = $this->taxonomy();
        add_filter('parent_file', [$this, 'parentFile']);
        add_filter('submenu_file', [$this, 'submenuFile'], 10, 2);
        add_action($tax.'_add_form_fields', [$this, 'termAddFields']);
        add_action($tax.'_edit_form_fields', [$this, 'termEditFields']);
        add_action('created_'.$tax, [$this, 'saveTerm']);
        add_action('edited_'.$tax, [$this, 'saveTerm']);
        add_filter('manage_edit-'.$tax.'_columns', [$this, 'termColumns']);
        add_filter('manage_'.$tax.'_custom_column', [$this, 'termColumn'], 10, 3);
    }

    /** Menü „Bootsprüfung“ bleibt geöffnet, auch beim Bearbeiten einer FAQ oder Kategorie. */
    public function parentFile(?string $parent): ?string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen && ($screen->post_type === $this->postType() || $screen->taxonomy === $this->taxonomy()) ? Menu::SLUG : $parent;
    }

    public function submenuFile(?string $submenu, ?string $parent = ''): ?string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->taxonomy === $this->taxonomy()) {
            return $this->termsUrl();
        }

        return $screen && $screen->post_type === $this->postType() ? $this->listUrl() : $submenu;
    }

    /** @return array{0:?WP_Term,1:?WP_Term} oberste Ebene und (tiefste gewählte) Unterkategorie */
    protected static function placementIn(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (! is_array($terms) || $terms === []) {
            return [null, null];
        }
        $term = $terms[0];
        foreach ($terms as $t) {
            if ($t->parent !== 0) {
                $term = $t;
                break;
            }
        }
        if ($term->parent === 0) {
            return [$term, null];
        }
        $top = $term;
        for ($i = 0; $top->parent !== 0 && $i < 10; $i++) {
            $parent = get_term($top->parent, $taxonomy);
            if (! $parent instanceof WP_Term) {
                break;
            }
            $top = $parent;
        }

        return [$top, $term];
    }

    /**
     * Alle Zuordnungen einer FAQ (mehrere Kategorien möglich): je gewählter Kategorie die oberste Ebene und die
     * Kategorie selbst (null, wenn direkt die oberste Ebene gewählt ist). Eine oberste Ebene, unter der zusätzlich
     * eine Unterkategorie gewählt ist, steht nur einmal (mit der Unterkategorie).
     *
     * @return list<array{0:WP_Term,1:?WP_Term}>
     */
    protected static function placementsIn(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);
        if (! is_array($terms) || $terms === []) {
            return [];
        }
        $out = [];
        $withSub = [];
        foreach ($terms as $term) {
            if ($term->parent === 0) {
                continue;
            }
            $top = $term;
            for ($i = 0; $top->parent !== 0 && $i < 10; $i++) {
                $parent = get_term($top->parent, $taxonomy);
                if (! $parent instanceof WP_Term) {
                    break;
                }
                $top = $parent;
            }
            $out[] = [$top, $term];
            $withSub[$top->term_id] = true;
        }
        foreach ($terms as $term) {
            if ($term->parent === 0 && ! isset($withSub[$term->term_id])) {
                $out[] = [$term, null];
            }
        }

        return $out;
    }

    /** Feld „Redaktionshinweis“ (nur im Admin sichtbar). */
    protected static function renderNoteField(WP_Post $post, string $metaKey, string $field): void
    {
        $id = str_replace('_', '-', $field);
        printf(
            '<p><label for="%4$s"><strong>%1$s</strong></label><textarea id="%4$s" name="%5$s" rows="4" class="widefat">%2$s</textarea><span class="description">%3$s</span></p>',
            esc_html__('Redaktionshinweis', 'bootspruefung-learning'),
            esc_textarea((string) get_post_meta($post->ID, $metaKey, true)),
            esc_html__('Nur im Admin sichtbar.', 'bootspruefung-learning'),
            esc_attr($id),
            esc_attr($field)
        );
    }

    /** Redaktionshinweis aus dem Formular speichern (Nonce und Rechte prüft der Aufrufer). */
    protected static function saveNote(int $postId, string $metaKey, string $field): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- geprüft im Aufrufer
        $note = sanitize_textarea_field(wp_unslash((string) ($_POST[$field] ?? '')));
        $note === '' ? delete_post_meta($postId, $metaKey) : update_post_meta($postId, $metaKey, $note);
    }

    public function termAddFields(): void
    {
        $labels = $this->termLabels();
        $key = $this->keyMeta();
        $order = $this->orderMeta();
        echo '<div class="form-field"><label for="'.esc_attr(str_replace('_', '-', $key)).'">'.esc_html($labels['key_add']).'</label><input type="text" id="'.esc_attr(str_replace('_', '-', $key)).'" name="'.esc_attr($key).'" value="">'
            .'<p>'.esc_html($this->keyHelp()).'</p></div>';
        echo '<div class="form-field"><label for="'.esc_attr(str_replace('_', '-', $order)).'">'.esc_html__('Reihenfolge', 'bootspruefung-learning').'</label><input type="number" id="'.esc_attr(str_replace('_', '-', $order)).'" name="'.esc_attr($order).'" value="" min="0" step="1">'
            .'<p>'.esc_html($labels['order_add']).'</p></div>';
    }

    public function termEditFields(WP_Term $term): void
    {
        $keyField = $this->keyMeta();
        $orderField = $this->orderMeta();
        $key = (string) get_term_meta($term->term_id, $keyField, true);
        $order = get_term_meta($term->term_id, $orderField, true);
        if ($term->parent === 0) {
            printf(
                '<tr class="form-field"><th scope="row"><label for="%4$s">%1$s</label></th><td><input type="text" id="%4$s" name="%5$s" value="%2$s"><p class="description">%3$s</p></td></tr>',
                esc_html__('Schlüssel', 'bootspruefung-learning'),
                esc_attr($key),
                esc_html($this->keyHelp()),
                esc_attr(str_replace('_', '-', $keyField)),
                esc_attr($keyField)
            );
        }
        printf(
            '<tr class="form-field"><th scope="row"><label for="%4$s">%1$s</label></th><td><input type="number" id="%4$s" name="%5$s" value="%2$s" min="0" step="1"><p class="description">%3$s</p></td></tr>',
            esc_html__('Reihenfolge', 'bootspruefung-learning'),
            esc_attr((string) $order),
            esc_html__('Kleinere Zahl = weiter oben.', 'bootspruefung-learning'),
            esc_attr(str_replace('_', '-', $orderField)),
            esc_attr($orderField)
        );
    }

    /** Speichert Schlüssel und Reihenfolge (WordPress hat das Formular-Nonce bereits geprüft). */
    public function saveTerm(int $termId): void
    {
        $keyField = $this->keyMeta();
        $orderField = $this->orderMeta();
        // phpcs:disable WordPress.Security.NonceVerification -- Term-Formulare prüft WordPress selbst.
        if (! current_user_can(Capabilities::MANAGE_CONTENT) || (! isset($_POST[$keyField]) && ! isset($_POST[$orderField]))) {
            return;
        }
        $term = get_term($termId, $this->taxonomy());
        if (isset($_POST[$keyField]) && $term instanceof WP_Term && $term->parent === 0) {
            $key = preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field(wp_unslash((string) $_POST[$keyField])));
            $key === '' ? delete_term_meta($termId, $keyField) : update_term_meta($termId, $keyField, $key);
        }
        if (isset($_POST[$orderField])) {
            $order = trim(sanitize_text_field(wp_unslash((string) $_POST[$orderField])));
            $order === '' ? delete_term_meta($termId, $orderField) : update_term_meta($termId, $orderField, max(0, (int) $order));
        }
        // phpcs:enable
    }

    /** @param array<string,string> $columns @return array<string,string> */
    public function termColumns(array $columns): array
    {
        unset($columns['description']);

        return $columns + [$this->keyMeta() => __('Schlüssel', 'bootspruefung-learning'), $this->orderMeta() => __('Reihenfolge', 'bootspruefung-learning')];
    }

    public function termColumn(string $content, string $column, int $termId): string
    {
        return match ($column) {
            $this->keyMeta() => esc_html((string) get_term_meta($termId, $this->keyMeta(), true)),
            $this->orderMeta() => esc_html((string) get_term_meta($termId, $this->orderMeta(), true)),
            default => $content,
        };
    }
}
