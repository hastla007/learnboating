<?php
/**
 * Rechtstext-Vorlagen (legal/*.html) in die Pflichtseiten übernehmen, WooCommerce-/Datenschutz-
 * Seiten zuordnen und den Betreiber auf offene Platzhalter hinweisen.
 *
 * Keine Rechtsberatung: Die Vorlagen enthalten [[Betreiber: …]]-Platzhalter und einen Hinweis-Absatz
 * (kw-placeholder), die der Betreiber vor der Veröffentlichung ersetzt bzw. löscht.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Slugs der Pflichtseiten (wie kurswasser_create_pages()) mit Bezeichnung für Hinweise. */
function kurswasser_legal_pages(): array
{
    return [
        'impressum' => __('Impressum', 'kurswasser'),
        'datenschutz' => __('Datenschutzerklärung', 'kurswasser'),
        'agb' => __('AGB', 'kurswasser'),
        'widerruf' => __('Widerrufsbelehrung', 'kurswasser'),
        'kontakt' => __('Kontakt', 'kurswasser'),
    ];
}

/** Inhalt der Vorlage legal/<slug>.html (Block-Markup) oder null, wenn sie (noch) fehlt. */
function kurswasser_legal_content(string $slug): ?string
{
    if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
        return null;
    }
    $file = dirname(__DIR__).'/legal/'.$slug.'.html';
    if (! is_file($file) || ! is_readable($file)) {
        return null;
    }
    $content = trim((string) file_get_contents($file));

    return $content === '' ? null : $content;
}

/** Pflichtseite per Slug (auch Entwurf), sonst null. */
function kurswasser_legal_page(string $slug): ?WP_Post
{
    $page = get_page_by_path($slug, OBJECT, 'page');

    return $page instanceof WP_Post && $page->post_status !== 'trash' ? $page : null;
}

/**
 * Noch unverändert? Leer, nur der ursprüngliche Platzhalter-Absatz von kurswasser_create_pages()
 * oder genau die zuletzt eingefügte Vorlage (Prüfsumme in _kurswasser_legal_hash).
 */
function kurswasser_legal_is_untouched(WP_Post $page): bool
{
    $content = trim($page->post_content);
    if ($content === '') {
        return true;
    }
    $hash = (string) get_post_meta($page->ID, '_kurswasser_legal_hash', true);
    if ($hash !== '' && hash_equals($hash, md5($content))) {
        return true;
    }
    // Ursprünglicher Platzhalter: nur ein kw-placeholder-Absatz mit dem Text „Platzhalter – hier den geprüften Text einfügen …“.
    $rest = preg_replace('/<!-- wp:paragraph \{"className":"kw-placeholder"\} -->.*?<!-- \/wp:paragraph -->/s', '', $content, 1, $count);

    return $count === 1 && trim((string) $rest) === '' && str_contains($content, 'Platzhalter – hier den geprüften Text einfügen');
}

/** Kurzlinks (#kw:…) der Vorlage beim Einfügen auflösen – kses würde sie beim Speichern verwerfen. */
function kurswasser_legal_resolve_links(string $content): string
{
    return (string) preg_replace_callback(
        '/href="#kw:([a-z0-9\/-]*)(#[a-z0-9-]+)?"/',
        static fn (array $m): string => 'href="'.esc_url(kurswasser_url($m[1]).($m[2] ?? '')).'"',
        $content
    );
}

/**
 * Pflichtseiten mit den Vorlagen aus legal/ füllen. Überschreibt nie eine vom Betreiber bearbeitete
 * Seite (außer mit $force). Status (Entwurf/veröffentlicht) bleibt unverändert.
 *
 * @return array<string,string> Slug => filled|updated|unchanged|edited|no-page|no-file
 */
function kurswasser_fill_legal_pages(bool $force = false): array
{
    $result = [];
    foreach (array_keys(kurswasser_legal_pages()) as $slug) {
        $page = kurswasser_legal_page($slug);
        if (! $page) {
            $result[$slug] = 'no-page';
            continue;
        }
        $template = kurswasser_legal_content($slug);
        if ($template === null) {
            $result[$slug] = 'no-file';
            continue;
        }
        if (! $force && ! kurswasser_legal_is_untouched($page)) {
            $result[$slug] = 'edited';
            continue;
        }
        $template = kurswasser_legal_resolve_links($template);
        $stored = (string) get_post_meta($page->ID, '_kurswasser_legal_source', true);
        if (! $force && $stored === md5($template) && trim($page->post_content) !== '' && ! str_contains($page->post_content, 'Platzhalter – hier den geprüften Text einfügen')) {
            $result[$slug] = 'unchanged';
            continue;
        }
        $wasPlaceholder = str_contains($page->post_content, 'Platzhalter – hier den geprüften Text einfügen');
        $id = wp_update_post(['ID' => $page->ID, 'post_content' => wp_slash($template)], true);
        if (is_wp_error($id) || ! $id) {
            $result[$slug] = 'error';
            continue;
        }
        clean_post_cache((int) $id);
        $saved = get_post((int) $id);
        update_post_meta((int) $id, '_kurswasser_legal_hash', md5(trim((string) ($saved->post_content ?? ''))));
        update_post_meta((int) $id, '_kurswasser_legal_source', md5($template));
        $result[$slug] = $wasPlaceholder || $stored === '' ? 'filled' : 'updated';
    }

    return $result;
}

/**
 * WooCommerce-AGB-Seite und WordPress-Datenschutzseite zuordnen, wenn nicht gesetzt.
 * Als „nicht gesetzt“ gilt auch ein Verweis auf eine gelöschte Seite oder auf den unveränderten
 * Datenschutz-Entwurf, den WordPress bei der Installation anlegt (Slug privacy-policy, Entwurf).
 *
 * @return array<string,int> Option => gesetzte Seiten-ID
 */
function kurswasser_assign_legal_pages(): array
{
    $set = [];
    $usable = static function ($id): bool {
        $post = $id ? get_post((int) $id) : null;

        return $post instanceof WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash';
    };

    $agb = kurswasser_legal_page('agb');
    if ($agb && ! $usable(get_option('woocommerce_terms_page_id'))) {
        update_option('woocommerce_terms_page_id', $agb->ID);
        $set['woocommerce_terms_page_id'] = $agb->ID;
    }

    $privacy = kurswasser_legal_page('datenschutz');
    $current = (int) get_option('wp_page_for_privacy_policy');
    $currentPost = $current ? get_post($current) : null;
    $wpDefault = $currentPost instanceof WP_Post && $currentPost->post_name === 'privacy-policy' && $currentPost->post_status === 'draft';
    if ($privacy && $current !== $privacy->ID && (! $usable($current) || $wpDefault)) {
        update_option('wp_page_for_privacy_policy', $privacy->ID);
        $set['wp_page_for_privacy_policy'] = $privacy->ID;
    }

    return $set;
}

// AGB-Zustimmung in der Kasse erst, wenn die AGB-Seite veröffentlicht ist: Ein Entwurf wäre für
// Kunden nicht abrufbar, die Pflicht-Checkbox würde auf eine leere Seite verweisen.
add_filter('woocommerce_checkout_show_terms', static function ($show) {
    $id = (int) get_option('woocommerce_terms_page_id');

    return $show && $id > 0 && get_post_status($id) === 'publish';
});

// Nach dem Anlegen der Seiten (kurswasser_create_pages, Priorität 10) füllen und zuordnen.
add_action('after_switch_theme', static function (): void {
    kurswasser_fill_legal_pages();
    kurswasser_assign_legal_pages();
}, 20);

/** Prüfsumme über alle Vorlagen in legal/ (ändert sich mit jedem Theme-Update, das einen Rechtstext anpasst). */
function kurswasser_legal_templates_hash(): string
{
    $sums = [];
    foreach (array_keys(kurswasser_legal_pages()) as $slug) {
        $content = kurswasser_legal_content($slug);
        $sums[] = $slug.':'.($content === null ? '-' : md5($content));
    }

    return md5(implode('|', $sums));
}

/*
 * Bestehende Installationen: Nach einem Theme-Update mit geänderten Vorlagen die Pflichtseiten nachziehen
 * (ohne force – vom Betreiber bearbeitete Seiten bleiben unangetastet) und einmalig darauf hinweisen.
 */
add_action('admin_init', static function (): void {
    if (! current_user_can('manage_options') || wp_doing_ajax()) {
        return;
    }
    $hash = kurswasser_legal_templates_hash();
    if (get_option('kurswasser_legal_templates_hash') === $hash) {
        return;
    }
    update_option('kurswasser_legal_templates_hash', $hash, false);
    $result = kurswasser_fill_legal_pages();
    $pages = kurswasser_legal_pages();
    $pick = static fn (array $states): array => array_values(array_map(
        static fn (string $slug): string => $pages[$slug] ?? $slug,
        array_keys(array_filter($result, static fn (string $s): bool => in_array($s, $states, true)))
    ));
    $notice = ['updated' => $pick(['updated', 'filled']), 'edited' => $pick(['edited'])];
    if ($notice['updated'] || $notice['edited']) {
        update_option('kurswasser_legal_update_notice', $notice, false);
    }
});

add_action('admin_notices', static function (): void {
    if (! current_user_can('manage_options')) {
        return;
    }
    $notice = get_option('kurswasser_legal_update_notice');
    if (! is_array($notice)) {
        return;
    }
    delete_option('kurswasser_legal_update_notice');
    echo '<div class="notice notice-info is-dismissible">';
    if (! empty($notice['updated'])) {
        echo '<p><strong>'.esc_html__('Rechtstext-Vorlagen aktualisiert:', 'kurswasser').'</strong> '
            .esc_html(implode(', ', (array) $notice['updated'])).'. '
            .esc_html__('Bitte die geänderten Seiten prüfen.', 'kurswasser').'</p>';
    }
    if (! empty($notice['edited'])) {
        echo '<p><strong>'.esc_html__('Neue Rechtstext-Vorlagen nicht übernommen', 'kurswasser').'</strong> '
            .esc_html__('(von dir bearbeitete Seiten bleiben unverändert – bitte mit den Vorlagen im Theme-Ordner legal/ abgleichen):', 'kurswasser').' '
            .esc_html(implode(', ', (array) $notice['edited'])).'</p>';
    }
    echo '</div>';
});

/**
 * Offene Punkte je Pflichtseite.
 *
 * @return array<string,array{title:string,id:int,issues:string[]}>
 */
function kurswasser_legal_open_issues(): array
{
    $open = [];
    foreach (kurswasser_legal_pages() as $slug => $title) {
        $page = kurswasser_legal_page($slug);
        $issues = [];
        if (! $page) {
            $issues[] = __('Seite fehlt', 'kurswasser');
        } else {
            if ($page->post_status !== 'publish') {
                $issues[] = __('noch nicht veröffentlicht', 'kurswasser');
            }
            if (str_contains($page->post_content, '[[Betreiber:')) {
                $issues[] = __('enthält [[Betreiber: …]]-Platzhalter', 'kurswasser');
            }
            if (str_contains($page->post_content, 'kw-placeholder')) {
                $issues[] = __('enthält den Vorlagen-Hinweis', 'kurswasser');
            }
        }
        if ($issues) {
            $open[$slug] = ['title' => $title, 'id' => $page ? $page->ID : 0, 'issues' => $issues];
        }
    }

    return $open;
}

add_action('admin_notices', static function (): void {
    if (! current_user_can('edit_pages')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && ! in_array($screen->id, ['dashboard', 'edit-page', 'themes', 'woocommerce_page_wc-settings', 'options-privacy', 'privacy'], true)) {
        return;
    }
    $open = kurswasser_legal_open_issues();
    if (! $open) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Rechtstexte noch nicht fertig', 'kurswasser').'</strong> – '
        .esc_html__('Bitte prüfen lassen, alle Platzhalter ersetzen, den Vorlagen-Hinweis löschen und die Seiten veröffentlichen:', 'kurswasser').'</p><ul style="list-style:disc;margin-left:1.5em">';
    foreach ($open as $item) {
        $link = $item['id'] ? get_edit_post_link($item['id']) : '';
        $label = $link ? '<a href="'.esc_url($link).'">'.esc_html($item['title']).'</a>' : esc_html($item['title']);
        echo '<li>'.$label.': '.esc_html(implode(', ', $item['issues'])).'</li>';
    }
    echo '</ul>';
    if (current_user_can('manage_options') && array_filter(array_keys($open), static fn (string $slug): bool => ($p = kurswasser_legal_page($slug)) && kurswasser_legal_content($slug) !== null && str_contains($p->post_content, 'Platzhalter – hier den geprüften Text einfügen'))) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=kurswasser_legal_fill'), 'kurswasser_legal_fill');
        echo '<p><a class="button" href="'.esc_url($url).'">'.esc_html__('Vorlagen in leere Seiten einfügen', 'kurswasser').'</a> '
            .esc_html__('(bearbeitete Seiten bleiben unverändert)', 'kurswasser').'</p>';
    }
    echo '</div>';
});

add_action('admin_post_kurswasser_legal_fill', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_legal_fill');
    kurswasser_fill_legal_pages();
    kurswasser_assign_legal_pages();
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=page'));
    exit;
});

/**
 * Platzhalter für Redakteure im Frontend hervorheben (nur Textknoten, keine Attribute). Inhalte von
 * <script>, <style> und <textarea> bleiben samt Tags unverändert (ein <mark> dort wäre Code bzw. Rohtext).
 */
function kurswasser_mark_legal_placeholders(string $html): string
{
    if (! str_contains($html, '[[Betreiber:')) {
        return $html;
    }
    // Gruppe 1: Tags bzw. ganze script/style/textarea-Elemente (unverändert), Gruppe 3: Text.
    return (string) preg_replace_callback(
        '/(<!--.*?-->|<(script|style|textarea)\b[^>]*>.*?<\/\2\s*>|<[^>]*>)|([^<]+|<)/is',
        static fn (array $m): string => isset($m[3]) && $m[3] !== '' && str_contains($m[3], '[[Betreiber:')
            ? (string) preg_replace('/\[\[Betreiber:[^\[\]]*\]\]/u', '<mark class="kw-legal-placeholder">$0</mark>', $m[3])
            : $m[0],
        $html
    );
}

add_filter('the_content', static function ($content) {
    if (! is_string($content) || is_admin() || ! is_user_logged_in() || ! current_user_can('edit_pages')) {
        return $content;
    }

    return kurswasser_mark_legal_placeholders($content);
}, 20);

add_action('wp_enqueue_scripts', static function (): void {
    if (is_user_logged_in() && current_user_can('edit_pages')) {
        wp_add_inline_style('kurswasser', '.kw-legal-placeholder{background:#ffe066;color:#071B2E;padding:0 .2em;border-radius:3px;outline:1px dashed #b38600}');
    }
}, 20);

/*
 * Fußzeile: „Cookie-Einstellungen“ ruft [kurswasser_consent_settings] auf (inc/consent.php).
 * Fehlt der Shortcode, erscheint weder Rohtext noch ein leerer Listenpunkt.
 */
add_action('init', static function (): void {
    if (! shortcode_exists('kurswasser_consent_settings')) {
        add_shortcode('kurswasser_consent_settings', static fn (): string => '');
    }
}, 99);

add_filter('render_block', static function (string $html, array $block): string {
    if (($block['blockName'] ?? '') !== 'core/html' || ! str_contains($html, 'kw-footer-links')) {
        return $html;
    }
    if (str_contains($html, '[kurswasser_consent_settings')) {
        $html = do_shortcode($html);
    }

    return (string) preg_replace('/<li>\s*<\/li>/', '', $html);
}, 10, 2);

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Pflichtseiten mit den Rechtstext-Vorlagen (legal/*.html) füllen und AGB-/Datenschutzseite zuordnen.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Auch vom Betreiber bearbeitete Seiten überschreiben.
     *
     * ## EXAMPLES
     *
     *     wp kurswasser legal
     *     wp kurswasser legal --force
     */
    WP_CLI::add_command('kurswasser legal', static function (array $args, array $assoc): void {
        $labels = [
            'filled' => 'Vorlage eingefügt',
            'updated' => 'Vorlage aktualisiert',
            'unchanged' => 'unverändert (Vorlage schon aktuell)',
            'edited' => 'übersprungen – vom Betreiber bearbeitet (--force überschreibt)',
            'no-page' => 'Seite fehlt (Theme neu aktivieren legt sie an)',
            'no-file' => 'keine Vorlage in legal/',
            'error' => 'Fehler beim Speichern',
        ];
        foreach (kurswasser_fill_legal_pages(! empty($assoc['force'])) as $slug => $status) {
            WP_CLI::log(sprintf('%-12s %s', $slug, $labels[$status] ?? $status));
        }
        foreach (kurswasser_assign_legal_pages() as $option => $id) {
            WP_CLI::log(sprintf('%s = %d', $option, $id));
        }
        foreach (kurswasser_legal_open_issues() as $slug => $item) {
            WP_CLI::warning($slug.': '.implode(', ', $item['issues']));
        }
        WP_CLI::success('Rechtstexte geprüft.');
    });
}
