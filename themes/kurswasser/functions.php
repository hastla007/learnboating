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
require_once __DIR__.'/inc/consent.php';
require_once __DIR__.'/inc/legal.php';
require_once __DIR__.'/inc/seo.php';
require_once __DIR__.'/inc/content-pages.php';

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
        'vertrag-widerrufen' => class_exists(\Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::class) ? \Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::url() : home_url('/vertrag-widerrufen/'),
        'tips' => home_url('/bootsscheine/tipps/'),
        'sbf', 'funk', 'seenot', 'ergaenzung', 'pakete', 'gruppen' => $cat($target),
        '' => home_url('/'),
        default => home_url('/'.$target.'/'),
    };
}

/**
 * Ziel #kw:page:<pfad>[#anker][|<ersatz>]: Link auf eine Seite, nur wenn sie veröffentlicht ist. Sonst das
 * Ersatzziel (wie #kw:<ersatz>, z. B. „gruppen“ oder „#faq“) oder null – dann entfällt der Eintrag (Navigation, Fuß).
 */
const KURSWASSER_PAGE_TARGET = '#kw:page:[a-z0-9\/-]+(?:#[a-z0-9-]+)?(?:\|[a-z0-9\/-]*(?:#[a-z0-9-]+)?)?';

function kurswasser_page_target(string $spec): ?string
{
    if (! preg_match('/^#kw:page:([a-z0-9\/-]+)(#[a-z0-9-]+)?(\|([a-z0-9\/-]*)(#[a-z0-9-]+)?)?$/', $spec, $m)) {
        return null;
    }
    $page = get_page_by_path(trim($m[1], '/'), OBJECT, 'page');
    if ($page instanceof WP_Post && $page->post_status === 'publish' && $page->post_password === '') {
        return (string) get_permalink($page).($m[2] ?? '');
    }

    return ! empty($m[3]) ? kurswasser_url($m[4] ?? '').($m[5] ?? '') : null;
}

/**
 * Platzhalter-Links in Vorlagen/Mustern: href="#kw:shop" usw. werden beim Ausgeben ersetzt,
 * damit Vorlagen ohne feste IDs/Slugs auskommen.
 */
// Navigationslinks maskiert WordPress vor render_block (esc_url verwirft „#kw:…“) – daher schon die Blockdaten ersetzen.
add_filter('render_block_data', static function (array $block): array {
    $url = $block['attrs']['url'] ?? null;
    if (! in_array($block['blockName'] ?? '', ['core/navigation-link', 'core/navigation-submenu'], true) || ! is_string($url)) {
        return $block;
    }
    if (preg_match('/^'.KURSWASSER_PAGE_TARGET.'$/', $url)) {
        $target = kurswasser_page_target($url);
        $target === null ? $block['attrs']['kwHidden'] = true : $block['attrs']['url'] = $target;
    } elseif (preg_match('/^#kw:([a-z0-9\/-]*)(#[a-z0-9-]+)?$/', $url, $m)) {
        $block['attrs']['url'] = kurswasser_url($m[1]).($m[2] ?? '');
    }

    return $block;
});

add_filter('render_block', static function (string $html, array $block = []): string {
    $url = $block['attrs']['url'] ?? null;
    if (! empty($block['attrs']['kwHidden'])) {
        return ''; // Navigationseintrag auf eine nicht veröffentlichte Seite (ohne Ersatzziel).
    }
    if (in_array($block['blockName'] ?? '', ['core/navigation-link', 'core/navigation-submenu'], true) && is_string($url) && preg_match('/^'.KURSWASSER_PAGE_TARGET.'$/', $url)) {
        // Navigation rendert ihre Einträge ohne render_block_data – hier über die ursprüngliche URL entscheiden.
        $target = kurswasser_page_target($url);

        return $target === null ? '' : (string) preg_replace('/href="#kw:page:[^"]*"/', 'href="'.esc_url($target).'"', $html);
    }
    if (! str_contains($html, '#kw:')) {
        return $html;
    }
    if (str_contains($html, '#kw:page:')) {
        // Listeneintrag (Fuß) mit Link auf eine nicht veröffentlichte Seite ganz entfernen, sonst Ziel einsetzen.
        $html = (string) preg_replace_callback('~<li>\s*<a href="('.KURSWASSER_PAGE_TARGET.')">.*?</a>\s*</li>~s', static function (array $m): string {
            $target = kurswasser_page_target($m[1]);

            return $target === null ? '' : str_replace('href="'.$m[1].'"', 'href="'.esc_url($target).'"', $m[0]);
        }, $html);
        $html = (string) preg_replace_callback('~<a href="('.KURSWASSER_PAGE_TARGET.')"([^>]*)>(.*?)</a>~s', static function (array $m): string {
            $target = kurswasser_page_target($m[1]);

            return $target === null ? $m[3] : '<a href="'.esc_url($target).'"'.$m[2].'>'.$m[3].'</a>';
        }, $html);
    }

    return (string) preg_replace_callback('/href="#kw:([a-z0-9\/-]*)(#[a-z0-9-]+)?"/', static fn ($m) => 'href="'.esc_url(kurswasser_url($m[1]).($m[2] ?? '')).'"', $html);
}, 20, 2);

// Fußspalte, deren Linkliste leer ist (alle Ziele noch Entwurf), nicht ausgeben.
add_filter('render_block_core/column', static function (string $html): string {
    return preg_match('#<ul class="kw-footer-links">\s*</ul>#', $html) ? '' : $html;
});

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

/*
 * Datenschutz: keine Gravatar-Abrufe (E-Mail-Hash an einen US-Dienst) und kein von WooCommerce
 * automatisch eingefügter zweiter Konto-Block im Kopf (lädt den Avatar, Festlegung 07.11.2026).
 */
add_filter('hooked_block_types', static function (array $hooked): array {
    return array_values(array_diff($hooked, ['woocommerce/customer-account']));
}, 20);
add_filter('pre_option_show_avatars', static fn () => '0');
add_filter('pre_get_avatar_data', static function (array $args): array {
    $args['url'] = '';
    $args['found_avatar'] = false;

    return $args;
});

/**
 * Enthält das Markup schon einen Link auf die Widerrufsfunktion? Erkennt absolute, protokoll-relative und
 * relative Adressen (/vertrag-widerrufen/) sowie den WooCommerce-Endpunkt des Plugins (Pfadvergleich).
 */
function kurswasser_has_withdrawal_link(string $html): bool
{
    if (! preg_match_all('#\bhref\s*=\s*(["\'])(.*?)\1#is', $html, $m)) {
        return false;
    }
    $norm = static fn (string $path): string => '/'.trim(rawurldecode($path), '/');
    $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $targets = [kurswasser_url('vertrag-widerrufen'), home_url('/vertrag-widerrufen/')];
    $class = \Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::class;
    if (class_exists($class) && method_exists($class, 'endpointUrl')) {
        $targets[] = (string) $class::endpointUrl();
    }
    $paths = [];
    foreach ($targets as $target) {
        $path = wp_parse_url($target, PHP_URL_PATH);
        if (is_string($path) && trim($path, '/') !== '') {
            $paths[$norm($path)] = true;
        }
    }
    foreach ($m[2] as $href) {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = wp_parse_url($href);
        if (! is_array($parts) || empty($parts['path'])) {
            continue;
        }
        if (! empty($parts['host']) && strtolower($parts['host']) !== $homeHost) {
            continue;
        }
        if (empty($parts['host']) && ! str_starts_with($parts['path'], '/')) {
            continue; // Relativ zur aktuellen Seite (z. B. „vertrag-widerrufen/“) – nicht eindeutig.
        }
        if (isset($paths[$norm($parts['path'])])) {
            return true;
        }
    }

    return false;
}

/*
 * Pflichtlink „Vertrag hier widerrufen“ (§ 356a BGB): Wurde die Fußzeile im Website-Editor angepasst
 * (kurswasser//footer in der Datenbank), fehlt der später ergänzte Link. Dann vor dem schließenden
 * Element einen kleinen Absatz mit dem Link anhängen.
 */
add_filter('render_block', static function (string $html, array $block): string {
    if (($block['blockName'] ?? '') !== 'core/template-part' || ($block['attrs']['slug'] ?? '') !== 'footer' || $html === '') {
        return $html;
    }
    $url = kurswasser_url('vertrag-widerrufen');
    if (kurswasser_has_withdrawal_link($html)) {
        return $html;
    }
    $link = '<p class="kw-footer-withdrawal alignwide has-small-font-size"><a href="'.esc_url($url).'">'.esc_html__('Vertrag hier widerrufen', 'kurswasser').'</a></p>';
    // Möglichst in die äußere Gruppe der Fußzeile (Hintergrund/Abstände), sonst vor das letzte schließende Tag.
    if (preg_match('#</div>\s*</footer>\s*$#', $html, $m, PREG_OFFSET_CAPTURE)) {
        return substr_replace($html, $link, (int) $m[0][1], 0);
    }
    $pos = strrpos($html, '</');

    return $pos === false ? $html.$link : substr_replace($html, $link, $pos, 0);
}, 30, 2);

/*
 * Datenschutz („keine externen Dienste“): kein WordPress-Emoji-Skript (lädt Grafiken von s.w.org)
 * und kein dns-prefetch auf s.w.org im Frontend.
 */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
// Auch in oEmbed-Ansichten (/embed/) nicht.
remove_action('embed_head', 'print_emoji_detection_script');
remove_action('enqueue_embed_scripts', 'wp_enqueue_emoji_styles');
remove_filter('the_content_feed', 'wp_staticize_emoji');
remove_filter('comment_text_rss', 'wp_staticize_emoji');
remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
add_filter('emoji_svg_url', '__return_false');
add_filter('wp_resource_hints', static function (array $urls, string $relation): array {
    if ($relation !== 'dns-prefetch') {
        return $urls;
    }

    return array_values(array_filter($urls, static function ($url): bool {
        $href = is_array($url) ? (string) ($url['href'] ?? '') : (string) $url;

        return ! str_contains($href, 's.w.org');
    }));
}, 10, 2);

/**
 * Mini-Warenkorb auf Warenkorb/Kasse/Bestellbestätigung: WooCommerce gibt dort nur eine ausgeblendete
 * Attrappe ohne Einstellungen aus, WordPress reiht das Block-Skript (viewScriptModule) trotzdem ein.
 * Ohne Einstellungen fragt es „…/undefinedwc/store/v1/cart“ ab (404). Dort also nicht laden.
 * (Zusammenspiel WooCommerce 11.2 / WordPress 7.1; entfernen, sobald WooCommerce das selbst behebt.)
 */
add_filter('render_block_woocommerce/mini-cart', static function ($html) {
    if (function_exists('is_checkout') && (is_checkout() || is_cart())) {
        wp_dequeue_script_module('woocommerce/mini-cart');
    }

    return $html;
});
