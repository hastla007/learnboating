<?php
/**
 * Kurswasser – Block-Theme für die Lernplattform.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const KURSWASSER_VERSION = '2.0.0';

require_once __DIR__.'/inc/offers.php';
require_once __DIR__.'/inc/courses.php';

add_action('after_setup_theme', static function (): void {
    load_theme_textdomain('kurswasser', get_template_directory().'/languages');
    add_theme_support('wp-block-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('editor-styles');
    add_editor_style('assets/css/theme.css');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', ['height' => 64, 'width' => 220, 'flex-height' => true, 'flex-width' => true]);
    add_theme_support('woocommerce', ['product_grid' => ['default_columns' => 3]]);
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
});

add_action('wp_enqueue_scripts', static function (): void {
    $file = get_template_directory().'/assets/css/theme.css';
    wp_enqueue_style('kurswasser', get_template_directory_uri().'/assets/css/theme.css', [], is_file($file) ? (string) filemtime($file) : KURSWASSER_VERSION);
    $img = get_template_directory_uri().'/assets/images/';
    $icons = get_template_directory_uri().'/assets/icons/';
    wp_add_inline_style('kurswasser', sprintf(
        ':root{--kw-hero:image-set(url("%1$s") type("image/webp"),url("%2$s") type("image/jpeg"));--kw-hero-small:url("%3$s");--kw-icons:"%4$s"}',
        esc_url($img.'hero-boat.webp'),
        esc_url($img.'hero-boat.jpg'),
        esc_url($img.'hero-boat-960.webp'),
        esc_url($icons)
    ).kurswasser_icon_css());
});

/** CSS-Masken für die Symbole (.kw-icon-anchor usw.), damit sie per currentColor einfärbbar sind. */
function kurswasser_icon_css(): string
{
    $css = '';
    foreach (glob(get_template_directory().'/assets/icons/*.svg') ?: [] as $path) {
        $name = basename($path, '.svg');
        $url = esc_url(get_template_directory_uri().'/assets/icons/'.$name.'.svg');
        $css .= ".kw-icon-{$name}{-webkit-mask-image:url(\"{$url}\");mask-image:url(\"{$url}\")}";
    }

    return $css;
}

add_action('init', static function (): void {
    register_block_pattern_category('kurswasser', ['label' => __('Kurswasser', 'kurswasser')]);
    register_block_style('core/button', ['name' => 'signal', 'label' => __('Signalgelb', 'kurswasser')]);
    register_block_style('core/button', ['name' => 'ghost-light', 'label' => __('Umriss hell (auf dunkel)', 'kurswasser')]);
    register_block_style('core/group', ['name' => 'card', 'label' => __('Karte', 'kurswasser')]);
    register_block_style('core/group', ['name' => 'card-dark', 'label' => __('Karte dunkel', 'kurswasser')]);
    register_block_style('core/list', ['name' => 'checks', 'label' => __('Häkchenliste', 'kurswasser')]);
});

/**
 * Pflichtseiten beim Aktivieren als Entwurf anlegen (Impressum, Datenschutz, AGB, Widerruf, Kontakt).
 * Keine Rechtstexte – nur Gerüst mit deutlichem Platzhalter; Inhalte liefert der Betreiber.
 */
add_action('after_switch_theme', 'kurswasser_create_pages');

function kurswasser_create_pages(): void
{
    $pages = [
        'impressum' => __('Impressum', 'kurswasser'),
        'datenschutz' => __('Datenschutzerklärung', 'kurswasser'),
        'agb' => __('Allgemeine Geschäftsbedingungen', 'kurswasser'),
        'widerruf' => __('Widerrufsbelehrung', 'kurswasser'),
        'kontakt' => __('Kontakt', 'kurswasser'),
    ];
    foreach ($pages as $slug => $title) {
        if (get_page_by_path($slug)) {
            continue;
        }
        $id = wp_insert_post([
            'post_type' => 'page', 'post_status' => 'draft', 'post_name' => $slug, 'post_title' => $title,
            'post_content' => '<!-- wp:paragraph {"className":"kw-placeholder"} --><p class="kw-placeholder">'.esc_html__('Platzhalter – hier den geprüften Text einfügen. Diese Seite ist ein Entwurf und erst nach dem Veröffentlichen sichtbar.', 'kurswasser').'</p><!-- /wp:paragraph -->',
        ]);
        if ($id && ! is_wp_error($id) && $slug !== 'kontakt') {
            update_post_meta((int) $id, '_wp_page_template', 'page-legal');
        }
    }
}

/** Kurzlink-Ziele für Navigation und Muster (robust gegenüber Sprache/Permalinks). */
function kurswasser_url(string $target): string
{
    $cat = static fn (string $slug): string => (function_exists('get_term_link') && ($term = get_term_by('slug', $slug, 'product_cat')) && ! is_wp_error($link = get_term_link($term))) ? $link : add_query_arg('product_cat', $slug, home_url('/'));

    return match ($target) {
        'shop' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/?post_type=product'),
        'account' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(),
        'learn' => home_url('/bootsscheine/'),
        'tips' => home_url('/bootsscheine/tipps/'),
        'sbf', 'funk', 'seenot', 'ergaenzung', 'pakete', 'gruppen' => $cat($target),
        '' => home_url('/'),
        default => home_url('/'.$target.'/'),
    };
}

/**
 * Platzhalter-Links in Vorlagen/Mustern: href="#kw:shop" usw. werden beim Ausgeben ersetzt,
 * damit Vorlagen ohne feste IDs/Slugs auskommen.
 */
add_filter('render_block', static function (string $html): string {
    if (! str_contains($html, '#kw:')) {
        return $html;
    }

    return (string) preg_replace_callback('/href="#kw:([a-z0-9\/-]*)(#[a-z0-9-]+)?"/', static fn ($m) => 'href="'.esc_url(kurswasser_url($m[1]).($m[2] ?? '')).'"', $html);
}, 20);

/** Kontobereich im Kopf: Anmelden bzw. Lernbereich (Shortcode für den Kopfbereich). */
add_shortcode('kurswasser_account', static function (): string {
    if (is_user_logged_in()) {
        return '<a class="kw-head-link" href="'.esc_url(kurswasser_url('learn')).'"><span class="kw-icon kw-icon-user" aria-hidden="true"></span><span class="kw-head-label">'.esc_html__('Lernbereich', 'kurswasser').'</span></a>';
    }

    return '<a class="kw-head-link" href="'.esc_url(kurswasser_url('account')).'"><span class="kw-icon kw-icon-user" aria-hidden="true"></span><span class="kw-head-label">'.esc_html__('Anmelden', 'kurswasser').'</span></a>';
});

/** Markenname: Einstellung des Lern-Plugins, sonst Website-Titel. */
function kurswasser_brand(): string
{
    if (class_exists(\Bootspruefung\Learning\Settings\Settings::class)) {
        return \Bootspruefung\Learning\Settings\Settings::brand();
    }

    return (string) get_bloginfo('name');
}

add_shortcode('kurswasser_brand', static fn (): string => esc_html(kurswasser_brand()));
add_shortcode('kurswasser_year', static fn (): string => esc_html(wp_date('Y')));

/** Anmeldeseite im Markenstil. */
add_action('login_enqueue_scripts', static function (): void {
    echo '<style>body.login{background:#071B2E}.login h1 a{background:none!important;text-indent:0!important;width:auto!important;height:auto!important;font:800 1.8rem/1.2 system-ui,sans-serif;color:#fff!important;letter-spacing:-.03em}.login form{border-radius:14px;border:0;box-shadow:0 18px 50px rgba(0,0,0,.3)}.login #backtoblog a,.login #nav a{color:#cfe6f2!important}.wp-core-ui .button-primary{background:#29B6E6!important;border-color:#29B6E6!important;color:#071B2E!important;font-weight:700;border-radius:999px}</style>';
});
add_filter('login_headertext', static fn (): string => kurswasser_brand());
add_filter('login_headerurl', static fn (): string => home_url('/'));

/** WooCommerce: ruhigere Standardtexte für digitale Kurse. */
add_filter('woocommerce_product_add_to_cart_text', static fn ($text, $product = null) => $product && $product->is_purchasable() && $product->is_in_stock() ? __('In den Warenkorb', 'kurswasser') : $text, 10, 2);
add_filter('woocommerce_product_single_add_to_cart_text', static fn () => __('Jetzt Kurs kaufen', 'kurswasser'));

/** Shortcode-Blöcke in Mustern ausführen (WordPress tut das dort sonst nicht). */
add_filter('pre_render_block', static function ($pre, array $block) {
    return ($block['blockName'] ?? '') === 'core/shortcode' ? do_shortcode(trim((string) ($block['innerHTML'] ?? ''))) : $pre;
}, 10, 2);

/** Abschnitt „Beliebte Kurse“ nur mit WooCommerce. */
add_filter('render_block', static function (string $html, array $block): string {
    if (! class_exists('WooCommerce') && str_contains((string) ($block['attrs']['className'] ?? ''), 'kw-popular')) {
        return '';
    }

    return $html;
}, 10, 2);

/** Ohne WooCommerce: Produkt-Shortcode nicht als Rohtext zeigen. */
add_action('init', static function (): void {
    if (! shortcode_exists('products')) {
        add_shortcode('products', static fn (): string => '');
    }
}, 99);

/** Lernende sehen keine WordPress-Werkzeugleiste (nur wer Beiträge bearbeiten darf). */
add_filter('show_admin_bar', static fn (bool $show): bool => $show && current_user_can('edit_posts'));
