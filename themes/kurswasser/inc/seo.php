<?php
/**
 * SEO-Grundausstattung ohne Plugin (Review 4, Abschnitt „Technische Empfehlung“):
 * SEO-Titel, Meta-Description, noindex, Canonical und Prüfdatum als Post-Meta (Metabox im Editor),
 * Ausgabe von <title>, Description, Robots und Canonical (noindex-Seiten: Canonical auf sich selbst), Open-Graph-
 * und Twitter-Tags (Bild = Hero-Foto der Seite), noindex-Seiten nicht in der Core-Sitemap,
 * JSON-LD BreadcrumbList (aus der Seitenhierarchie bzw. den Brotkrumen der Inhaltsseiten), Article
 * (Seiten mit Schema „Article“) und Organization/WebSite (nur Startseite).
 *
 * Sobald Yoast SEO, Rank Math (oder AIOSEO/SEOPress) aktiv ist, gibt dieses Modul nichts mehr selbst
 * aus; es reicht dann nur eigene Werte an das Plugin weiter, wo dessen Feld leer ist.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Meta-Schlüssel der SEO-Felder. */
function kurswasser_seo_fields(): array
{
    return [
        '_kw_seo_title' => 'string',
        '_kw_seo_description' => 'string',
        '_kw_noindex' => 'boolean',
        '_kw_canonical' => 'string',
        '_kw_reviewed_date' => 'string',
        '_kw_schema' => 'string',
    ];
}

/** Ist ein SEO-Plugin aktiv? Dann keine eigene Ausgabe (Filter für Tests und Sonderfälle). */
function kurswasser_seo_plugin_active(): bool
{
    $active = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');

    return (bool) apply_filters('kurswasser_seo_plugin_active', $active);
}

/** Platzhalter {{brand}} (Website-Name) und {{theme}} (Theme-URL) ersetzen. */
function kurswasser_content_tokens(string $text): string
{
    if (! str_contains($text, '{{')) {
        return $text;
    }

    return strtr($text, ['{{brand}}' => (string) get_bloginfo('name'), '{{theme}}' => get_template_directory_uri()]);
}

add_action('init', static function (): void {
    foreach (['page', 'post'] as $type) {
        foreach (kurswasser_seo_fields() as $key => $kind) {
            register_post_meta($type, $key, [
                'type' => $kind,
                'single' => true,
                'show_in_rest' => true,
                'default' => $kind === 'boolean' ? false : '',
                'sanitize_callback' => $kind === 'boolean' ? 'rest_sanitize_boolean' : 'sanitize_text_field',
                'auth_callback' => static fn ($allowed, $metaKey, $postId): bool => current_user_can('edit_post', (int) $postId),
            ]);
        }
    }
});

/** Gespeicherter Wert (Text) eines SEO-Felds, Platzhalter ersetzt. */
function kurswasser_seo_meta(int $postId, string $key): string
{
    return trim(kurswasser_content_tokens((string) get_post_meta($postId, $key, true)));
}

/** Auf Wortgrenze kürzen (für Description). */
function kurswasser_seo_trim(string $text, int $max = 155): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');

    return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:–-").'…';
}

/** SEO-Titel einer Seite (mit Marke, sofern sie noch passt) oder null = WordPress-Standard. */
function kurswasser_seo_title(WP_Post $post): ?string
{
    $title = kurswasser_seo_meta($post->ID, '_kw_seo_title');
    if ($title === '') {
        return null;
    }
    $brand = (string) get_bloginfo('name');
    $with = $title.' | '.$brand;
    if ($brand !== '' && ! str_contains($title, $brand) && mb_strlen($with) <= 60) {
        return $with;
    }

    return $title;
}

/** Meta-Description: Feld, sonst Kurs-Lead (Kursseiten), Auszug; leer = keine Ausgabe. */
function kurswasser_seo_description(WP_Post $post): string
{
    $desc = kurswasser_seo_meta($post->ID, '_kw_seo_description');
    if ($desc === '' && function_exists('kurswasser_courses') && preg_match('/\[kurswasser_course id="([a-z0-9-]+)"/', $post->post_content, $m)) {
        $desc = (string) (kurswasser_courses()[$m[1]]['lead'] ?? '');
    }
    if ($desc === '' && has_excerpt($post)) {
        $desc = (string) $post->post_excerpt;
    }

    return $desc === '' ? '' : kurswasser_seo_trim($desc);
}

/** Canonical-Ziel aus dem Feld (Slug, /pfad/ oder URL) oder null. */
function kurswasser_seo_canonical(WP_Post $post): ?string
{
    $value = kurswasser_seo_meta($post->ID, '_kw_canonical');
    // noindex-Seiten verweisen mit dem Canonical auf sich selbst – noindex und ein fremdes Canonical widersprechen sich.
    if ($value === '' || kurswasser_seo_noindex($post)) {
        return null;
    }
    if (preg_match('#^https?://#i', $value)) {
        return esc_url_raw($value);
    }
    $path = trim($value, '/');
    $page = $path !== '' ? get_page_by_path($path) : null;
    if ($page instanceof WP_Post && $page->post_status === 'publish') {
        return (string) get_permalink($page);
    }
    if ($page instanceof WP_Post) {
        return null; // Ziel ist (noch) Entwurf o. ä. – kein Canonical auf eine nicht erreichbare Seite, sondern Selbstreferenz.
    }

    return home_url('/'.($path !== '' ? $path.'/' : ''));
}

function kurswasser_seo_noindex(WP_Post $post): bool
{
    return (bool) get_post_meta($post->ID, '_kw_noindex', true);
}

/** Aktuell angezeigte Seite bzw. Beitrag (Einzelansicht) oder null. */
function kurswasser_seo_current(): ?WP_Post
{
    if (! is_singular()) {
        return null;
    }
    $post = get_queried_object();

    return $post instanceof WP_Post ? $post : null;
}

/** Link-Ziel aus Vorlagen: /pfad/ → home_url, #kw:ziel → kurswasser_url, sonst unverändert. */
function kurswasser_content_link(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('/^#kw:([a-z0-9\/-]*)(#[a-z0-9-]+)?$/', $url, $m) && function_exists('kurswasser_url')) {
        return kurswasser_url($m[1]).($m[2] ?? '');
    }
    if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
        return home_url($url);
    }

    return $url;
}

/**
 * Brotkrumen einer Seite: Start, Zwischenglieder, aktuelle Seite (Label + URL).
 * Inhaltsseiten mit _kw_breadcrumb (aus dem Manifest: logische Gruppe, z. B. „Ratgeber“) nutzen diese,
 * sonst die Seitenhierarchie (Elternseiten). Glieder ohne URL bleiben ohne Link.
 *
 * @return list<array{label:string,url:string}>
 */
function kurswasser_breadcrumbs(?WP_Post $post = null): array
{
    $post ??= get_post();
    if (! $post instanceof WP_Post) {
        return [];
    }
    $items = [['label' => __('Start', 'kurswasser'), 'url' => home_url('/')]];
    $crumb = get_post_meta($post->ID, '_kw_breadcrumb', true);
    if (is_array($crumb) && isset($crumb['items'])) {
        foreach ((array) $crumb['items'] as $item) {
            $label = kurswasser_content_tokens((string) ($item[0] ?? ''));
            if ($label !== '') {
                $items[] = ['label' => $label, 'url' => kurswasser_content_link((string) ($item[1] ?? ''))];
            }
        }
        $label = kurswasser_content_tokens((string) ($crumb['label'] ?? ''));
    } else {
        foreach (array_reverse(get_post_ancestors($post)) as $ancestor) {
            $items[] = ['label' => get_the_title($ancestor), 'url' => (string) get_permalink($ancestor)];
        }
        $label = '';
    }
    $items[] = ['label' => $label !== '' ? $label : get_the_title($post), 'url' => (string) get_permalink($post)];

    return $items;
}

/** Sichtbare Brotkrumen (Markup der Kursseiten, hell auf dunkel – nur im Kurs-Hero verwenden). */
function kurswasser_breadcrumbs_html(array $items): string
{
    $out = [];
    $last = count($items) - 1;
    foreach ($items as $i => $item) {
        $label = esc_html(wp_strip_all_tags(html_entity_decode($item['label'], ENT_QUOTES, 'UTF-8')));
        if ($i === $last) {
            $out[] = '<span aria-current="page">'.$label.'</span>';
        } elseif ($item['url'] !== '') {
            $out[] = '<a href="'.esc_url($item['url']).'">'.$label.'</a>';
        } else {
            $out[] = '<span>'.$label.'</span>';
        }
    }

    return '<nav class="kw-crumbs" aria-label="'.esc_attr__('Brotkrumen', 'kurswasser').'">'.implode('<span aria-hidden="true">/</span>', $out).'</nav>';
}

/** JSON-LD-Daten (Liste von Objekten) für die aktuelle Ansicht. */
function kurswasser_seo_json_ld(): array
{
    $brand = (string) get_bloginfo('name');
    $home = home_url('/');
    $org = ['@type' => 'Organization', '@id' => $home.'#organization', 'name' => $brand, 'url' => $home];
    $data = [];
    if (is_front_page()) {
        $logo = (int) get_theme_mod('custom_logo');
        if ($logo && ($src = wp_get_attachment_image_url($logo, 'full'))) {
            $org['logo'] = $src;
        }
        $data[] = ['@context' => 'https://schema.org', '@graph' => [
            $org,
            ['@type' => 'WebSite', '@id' => $home.'#website', 'url' => $home, 'name' => $brand, 'inLanguage' => 'de-DE', 'publisher' => ['@id' => $home.'#organization']],
        ]];

        return $data;
    }
    $post = kurswasser_seo_current();
    if (! $post || $post->post_type !== 'page') {
        return $data;
    }
    $list = [];
    foreach (kurswasser_breadcrumbs($post) as $item) {
        if ($item['url'] === '') {
            continue; // Glied ohne Seite: nur sichtbar, nicht ausgezeichnet.
        }
        $list[] = ['@type' => 'ListItem', 'position' => count($list) + 1, 'name' => wp_strip_all_tags(html_entity_decode($item['label'], ENT_QUOTES, 'UTF-8')), 'item' => $item['url']];
    }
    if (count($list) > 1) {
        $data[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }
    if (get_post_meta($post->ID, '_kw_schema', true) === 'Article') {
        $reviewed = (string) get_post_meta($post->ID, '_kw_reviewed_date', true);
        $article = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => mb_substr(wp_strip_all_tags(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8')), 0, 110),
            'url' => (string) get_permalink($post),
            'mainEntityOfPage' => (string) get_permalink($post),
            'datePublished' => (string) get_post_time('c', true, $post),
            'dateModified' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewed) ? $reviewed : (string) get_post_modified_time('c', true, $post),
            'inLanguage' => 'de-DE',
            // Keine Person erfinden: Autor und Herausgeber ist der Betreiber (Organization).
            'author' => ['@type' => 'Organization', 'name' => $brand, 'url' => $home],
            'publisher' => ['@type' => 'Organization', '@id' => $home.'#organization', 'name' => $brand],
        ];
        $desc = kurswasser_seo_description($post);
        if ($desc !== '') {
            $article['description'] = $desc;
        }
        $article['image'] = kurswasser_seo_image($post);
        $data[] = $article;
    }

    return $data;
}

/** Startseite (Vorlage front-page bzw. statische Seite ohne eigene SEO-Felder): SEO-Titel und Beschreibung (Filter kurswasser_front_seo). */
function kurswasser_seo_front(): array
{
    $brand = (string) get_bloginfo('name');
    $tagline = trim((string) get_bloginfo('description'));
    $defaults = [
        'title' => 'Bootsführerschein online lernen: SBF, SRC & UBI'.($brand !== '' ? ' | '.$brand : ''),
        'description' => $tagline !== '' && $tagline !== 'Just another WordPress site' ? $tagline
            : 'Lerne die Theorie für SBF See und Binnen, SRC, UBI, SKN und FKN online: alle Prüfungsfragen, Prüfungssimulation und dein Lernstand auf jedem Gerät.',
    ];

    return array_merge($defaults, (array) apply_filters('kurswasser_front_seo', []));
}

/** Bild für Vorschauen (og:image, Article): Hero-Foto der Seite bzw. Kursfoto, sonst das Segelboot der Startseite. */
function kurswasser_seo_image(?WP_Post $post): string
{
    $slot = $post && function_exists('kurswasser_content_photo_slot') ? kurswasser_content_photo_slot($post) : '';
    if ($slot !== '' && function_exists('kurswasser_course_image') && ($img = kurswasser_course_image($slot))) {
        return $img;
    }

    return get_template_directory_uri().'/assets/images/hero-boat.jpg';
}

/** Open-Graph- und Twitter-Tags (Titel, Beschreibung, URL, Bild = Hero-Foto der Seite). */
function kurswasser_seo_social_html(?WP_Post $post, string $desc): string
{
    $front = is_front_page() && ! $post;
    if (! $post && ! $front) {
        return '';
    }
    $own = $post ? kurswasser_seo_title($post) : null;
    $title = $own ?? (is_front_page() ? kurswasser_seo_front()['title'] : wp_strip_all_tags(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8')));
    $url = $post ? (string) (wp_get_canonical_url($post) ?: get_permalink($post)) : home_url('/');
    $tags = [
        ['property', 'og:locale', 'de_DE'],
        ['property', 'og:type', $post && get_post_meta($post->ID, '_kw_schema', true) === 'Article' ? 'article' : 'website'],
        ['property', 'og:site_name', (string) get_bloginfo('name')],
        ['property', 'og:title', $title],
        ['property', 'og:description', $desc],
        ['property', 'og:url', $url],
        ['property', 'og:image', kurswasser_seo_image($post)],
        ['name', 'twitter:card', 'summary_large_image'],
        ['name', 'twitter:title', $title],
        ['name', 'twitter:description', $desc],
    ];
    $html = '';
    foreach ($tags as [$attr, $key, $value]) {
        if ($value !== '') {
            $html .= '<meta '.$attr.'="'.esc_attr($key).'" content="'.esc_attr($key === 'og:url' || $key === 'og:image' ? esc_url($value) : $value).'">'."\n";
        }
    }

    return $html;
}

/** Kopf-Ausgabe: Meta-Description, Open Graph und JSON-LD (leer, wenn ein SEO-Plugin aktiv ist). */
function kurswasser_seo_head_html(): string
{
    if (kurswasser_seo_plugin_active()) {
        return '';
    }
    $html = '';
    $post = kurswasser_seo_current();
    $desc = $post ? kurswasser_seo_description($post) : '';
    if ($desc === '' && is_front_page()) {
        $desc = kurswasser_seo_trim(kurswasser_seo_front()['description']); // Startseite: eigene Beschreibung, auch als statische Seite
    }
    if ($desc !== '') {
        $html .= '<meta name="description" content="'.esc_attr($desc).'">'."\n";
    }
    $html .= kurswasser_seo_social_html($post, $desc);
    foreach (kurswasser_seo_json_ld() as $item) {
        $html .= '<script type="application/ld+json">'.wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP).'</script>'."\n";
    }

    return $html;
}

add_action('wp_head', static function (): void {
    echo kurswasser_seo_head_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
}, 1);

add_filter('pre_get_document_title', static function ($title) {
    if (! kurswasser_seo_plugin_active() && is_front_page() && (! ($front = kurswasser_seo_current()) || kurswasser_seo_title($front) === null)) {
        return esc_html(kurswasser_seo_front()['title']);
    }
    if (kurswasser_seo_plugin_active() || ! ($post = kurswasser_seo_current())) {
        return $title;
    }
    $own = kurswasser_seo_title($post);

    return $own !== null ? esc_html($own) : $title;
}, 20);

add_filter('wp_robots', static function (array $robots): array {
    if (kurswasser_seo_plugin_active() || ! ($post = kurswasser_seo_current()) || ! kurswasser_seo_noindex($post)) {
        return $robots;
    }
    $robots['noindex'] = true;
    unset($robots['index']);
    if (empty($robots['nofollow'])) {
        $robots['follow'] = true;
    }

    return $robots;
});

add_filter('get_canonical_url', static function ($url, $post) {
    if (kurswasser_seo_plugin_active() || ! $post instanceof WP_Post) {
        return $url;
    }

    return kurswasser_seo_canonical($post) ?? $url;
}, 10, 2);

// Core-Sitemap: keine noindex-Seiten (und Beiträge).
add_filter('wp_sitemaps_posts_query_args', static function (array $args): array {
    if (kurswasser_seo_plugin_active()) {
        return $args;
    }
    $args['meta_query'] = array_merge((array) ($args['meta_query'] ?? []), [[
        'relation' => 'OR',
        ['key' => '_kw_noindex', 'compare' => 'NOT EXISTS'],
        ['key' => '_kw_noindex', 'value' => '1', 'compare' => '!='],
    ]]);

    return $args;
});

// {{brand}} auch in Seitentiteln und Inhalten, die der Betreiber selbst damit geschrieben hat.
add_filter('the_title', static fn ($title) => is_string($title) ? kurswasser_content_tokens($title) : $title);
add_filter('the_content', static fn ($content) => is_string($content) ? kurswasser_content_tokens($content) : $content, 8);

/*
 * Mit Yoast bzw. Rank Math: nur Rückfall auf die eigenen Werte, wenn das Feld des Plugins leer ist.
 */
$kurswasser_seo_fallback = static function (string $pluginKey, callable $own): callable {
    return static function ($value) use ($pluginKey, $own) {
        $post = kurswasser_seo_current();
        if (! $post || trim((string) get_post_meta($post->ID, $pluginKey, true)) !== '') {
            return $value;
        }
        $mine = $own($post);

        return is_string($mine) && $mine !== '' ? $mine : $value;
    };
};
add_filter('wpseo_title', $kurswasser_seo_fallback('_yoast_wpseo_title', static fn (WP_Post $p) => kurswasser_seo_title($p)), 20);
add_filter('wpseo_metadesc', $kurswasser_seo_fallback('_yoast_wpseo_metadesc', 'kurswasser_seo_description'), 20);
add_filter('wpseo_canonical', $kurswasser_seo_fallback('_yoast_wpseo_canonical', static fn (WP_Post $p) => kurswasser_seo_canonical($p)), 20);
add_filter('rank_math/frontend/title', $kurswasser_seo_fallback('rank_math_title', static fn (WP_Post $p) => kurswasser_seo_title($p)), 20);
add_filter('rank_math/frontend/description', $kurswasser_seo_fallback('rank_math_description', 'kurswasser_seo_description'), 20);
add_filter('rank_math/frontend/canonical', $kurswasser_seo_fallback('rank_math_canonical_url', static fn (WP_Post $p) => kurswasser_seo_canonical($p)), 20);
unset($kurswasser_seo_fallback);
add_filter('wpseo_robots_array', static function ($robots) {
    $post = kurswasser_seo_current();
    if (is_array($robots) && $post && kurswasser_seo_noindex($post) && get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true) === '') {
        $robots['index'] = 'noindex';
    }

    return $robots;
}, 20);
add_filter('rank_math/frontend/robots', static function ($robots) {
    $post = kurswasser_seo_current();
    if (is_array($robots) && $post && kurswasser_seo_noindex($post) && empty(get_post_meta($post->ID, 'rank_math_robots', true))) {
        $robots['index'] = 'noindex';
    }

    return $robots;
}, 20);

/*
 * Metabox „SEO“ (funktioniert auch im Block-Editor) für Seiten und Beiträge.
 */
add_action('add_meta_boxes', static function (string $postType): void {
    if (! in_array($postType, ['page', 'post'], true)) {
        return;
    }
    add_meta_box('kurswasser-seo', __('SEO (Kurswasser)', 'kurswasser'), 'kurswasser_seo_metabox', $postType, 'normal', 'low');
});

function kurswasser_seo_metabox(WP_Post $post): void
{
    wp_nonce_field('kurswasser_seo_save', 'kurswasser_seo_nonce');
    if (kurswasser_seo_plugin_active()) {
        echo '<p><em>'.esc_html__('Ein SEO-Plugin ist aktiv: Es gibt Titel, Beschreibung und Robots aus. Diese Felder gelten nur, wo das Feld des Plugins leer ist.', 'kurswasser').'</em></p>';
    }
    $v = static fn (string $key): string => (string) get_post_meta($post->ID, $key, true);
    $row = static function (string $key, string $label, string $help, int $max = 0) use ($v): void {
        echo '<p><label for="'.esc_attr($key).'"><strong>'.esc_html($label).'</strong></label><br>';
        if ($key === '_kw_seo_description') {
            echo '<textarea class="widefat" rows="2" id="'.esc_attr($key).'" name="'.esc_attr($key).'" data-kw-count="'.esc_attr((string) $max).'">'.esc_textarea($v($key)).'</textarea>';
        } else {
            echo '<input class="widefat" type="'.($key === '_kw_reviewed_date' ? 'date' : 'text').'" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($v($key)).'"'.($max ? ' data-kw-count="'.esc_attr((string) $max).'"' : '').'>';
        }
        echo '<span class="description">'.esc_html($help).($max ? ' <span data-kw-counter></span>' : '').'</span></p>';
    };
    $row('_kw_seo_title', __('SEO-Titel', 'kurswasser'), __('Leer = Seitentitel. {{brand}} = Website-Name; die Marke wird angehängt, wenn sie fehlt und noch passt.', 'kurswasser'), 60);
    $row('_kw_seo_description', __('Meta-Description', 'kurswasser'), __('Kurzbeschreibung für Suchergebnisse.', 'kurswasser'), 155);
    $row('_kw_canonical', __('Canonical', 'kurswasser'), __('Leer = diese Seite. Sonst Slug, /pfad/ oder URL der Hauptseite.', 'kurswasser'));
    $row('_kw_reviewed_date', __('Stand (zuletzt fachlich geprüft)', 'kurswasser'), __('Für „Stand“ und dateModified – nicht bei jedem Tippfehler ändern.', 'kurswasser'));
    echo '<p><label><input type="checkbox" name="_kw_noindex" value="1"'.checked($v('_kw_noindex') === '1', true, false).'> '.esc_html__('Nicht in Suchmaschinen (noindex, follow; nicht in der Sitemap)', 'kurswasser').'</label></p>';
    $schema = $v('_kw_schema');
    echo '<p><label for="_kw_schema"><strong>'.esc_html__('Strukturierte Daten', 'kurswasser').'</strong></label> <select id="_kw_schema" name="_kw_schema">';
    foreach (['' => __('keine (nur Brotkrumen)', 'kurswasser'), 'Article' => 'Article'] as $value => $label) {
        echo '<option value="'.esc_attr($value).'"'.selected($schema === $value || ($value === '' && $schema !== 'Article'), true, false).'>'.esc_html($label).'</option>';
    }
    echo '</select></p>';
    echo '<script>(function(){document.querySelectorAll("#kurswasser-seo [data-kw-count]").forEach(function(f){var c=f.parentNode.querySelector("[data-kw-counter]"),max=+f.getAttribute("data-kw-count");if(!c){return;}var u=function(){var n=f.value.replace(/\{\{brand\}\}/g,"xxxxxxxx").length;c.textContent="("+n+"/"+max+")";c.style.color=n>max?"#b32d2e":"";};f.addEventListener("input",u);u();});})();</script>';
}

add_action('save_post', static function (int $postId, WP_Post $post): void {
    if (! isset($_POST['kurswasser_seo_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['kurswasser_seo_nonce'])), 'kurswasser_seo_save')) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || ! current_user_can('edit_post', $postId) || ! in_array($post->post_type, ['page', 'post'], true)) {
        return;
    }
    foreach (['_kw_seo_title', '_kw_seo_description', '_kw_canonical', '_kw_reviewed_date', '_kw_schema'] as $key) {
        $value = sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? '')));
        if ($key === '_kw_reviewed_date' && $value !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value = '';
        }
        if ($key === '_kw_schema' && ! in_array($value, ['', 'Article'], true)) {
            $value = (string) get_post_meta($postId, $key, true);
        }
        $value === '' ? delete_post_meta($postId, $key) : update_post_meta($postId, $key, $value);
    }
    empty($_POST['_kw_noindex']) ? delete_post_meta($postId, '_kw_noindex') : update_post_meta($postId, '_kw_noindex', '1');
}, 10, 2);
