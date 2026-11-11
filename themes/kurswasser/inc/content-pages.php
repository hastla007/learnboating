<?php
/**
 * Inhaltsseiten (Info- und Marketingseiten) aus den Vorlagen in content/ anlegen und aktualisieren.
 *
 * content/pages.php     – Manifest (erzeugt von tools/content_build.py aus den Markdown-Dateien)
 * content/pages/*.html  – Block-Markup je Seite; {{brand}} wird beim Anlegen zum Website-Namen
 *
 * Wie die Rechtstexte (inc/legal.php): Nichts wird ungefragt überschrieben. Eine Seite, die der Betreiber
 * geändert hat (Prüfsumme über Titel und Inhalt in _kw_content_hash), bleibt, wie sie ist; neue Vorlagen-
 * stände werden gemeldet. Unveränderte Seiten (und unveränderte SEO-Felder) übernehmen einen neuen
 * Vorlagenstand beim nächsten Abgleich. Angelegt und aktualisiert wird nur über den Knopf im Admin, die
 * Einrichtung (Design › Kurswasser einrichten, inc/setup.php) oder per WP-CLI (`wp kurswasser content-pages
 * sync`). Auch Geändertes überschreibt nur force: „Alles aus dem Theme übernehmen“ (mit Bestätigung) bzw. --force.
 * SEO-Daten: immer in die _kw_-Felder (inc/seo.php); mit Ziel „yoast“ zusätzlich in Yoast (inc/content-yoast.php).
 * Seiten mit [[Betreiber: …]] oder „Offen“-Kasten (kw-placeholder) entstehen immer als Entwurf. Einträge, die einen Rechtstext ersetzen (replaces = legal:kontakt), werden
 * übersprungen – diese Seite pflegt inc/legal.php.
 *
 * Typ A (Vorlage course): Der Kurs-Hero kommt aus dem Shortcode [kurswasser_page_hero] am Seitenanfang
 * (Eyebrow, Lead, CTAs, Zeile unter den Buttons als Attribute, H1 = Seitentitel, Brotkrumen aus _kw_breadcrumb bzw.
 * Hierarchie).
 *
 * Links auf Inhaltsseiten, die noch Entwurf sind, gibt das Theme nicht als Link aus (kurswasser_content_unlink_
 * unpublished); eine alte FAQ-Seite /haeufige-fragen/ leitet auf /faq-bootsfuehrerschein/ weiter.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Ordner mit pages.php und pages/ (Filter für Tests und eigene Vorlagen). */
function kurswasser_content_dir(): string
{
    return (string) apply_filters('kurswasser_content_dir', dirname(__DIR__).'/content');
}

/**
 * Manifest-Einträge (Eltern vor Kindern, wie vom Konverter sortiert).
 *
 * @return list<array<string,mixed>>
 */
function kurswasser_content_manifest(?string $dir = null): array
{
    $file = ($dir ?? kurswasser_content_dir()).'/pages.php';
    if (! is_file($file)) {
        return [];
    }
    $pages = include $file;

    return is_array($pages) ? array_values(array_filter($pages, static fn ($p): bool => is_array($p) && ! empty($p['path']) && ! empty($p['file']))) : [];
}

/** Block-Markup der Vorlage mit ersetzten Platzhaltern oder null, wenn die Datei fehlt. */
function kurswasser_content_template(array $entry, ?string $dir = null): ?string
{
    $name = basename((string) $entry['file']);
    if (! preg_match('/^[0-9]+-[a-z0-9-]+\.html$/', $name)) {
        return null;
    }
    $file = ($dir ?? kurswasser_content_dir()).'/pages/'.$name;
    if (! is_file($file) || ! is_readable($file)) {
        return null;
    }
    $content = trim((string) file_get_contents($file));

    return $content === '' ? null : kurswasser_content_tokens($content);
}

/** Vorhandene Seite zum Manifest-Pfad (auch Entwurf und Papierkorb) oder null. */
function kurswasser_content_page(array $entry): ?WP_Post
{
    $page = get_page_by_path((string) $entry['path'], OBJECT, 'page');

    return $page instanceof WP_Post ? $page : null;
}

/** Enthält der Text noch Angaben, die nur der Betreiber liefern kann (→ immer Entwurf)? Auch maskiert in Shortcode-Attributen. */
function kurswasser_content_has_placeholders(string $text): bool
{
    return str_contains($text, '[[Betreiber:') || str_contains($text, '&#91;&#91;Betreiber:') || str_contains($text, 'kw-placeholder');
}

/*
 * Links auf Inhaltsseiten, die (noch) nicht veröffentlicht sind: Navigation, Fuß und Seiteninhalt sollen nicht ins
 * Leere führen. Gilt für die Seiten des Manifests (auch die Kontaktseite, die inc/legal.php pflegt): fehlt die Seite
 * oder ist sie Entwurf, privat, geplant bzw. im Papierkorb, wird ein Link darauf im Seiteninhalt und in Mustern zu
 * normalem Text; Karten und Kacheln, deren Titel darauf verlinkt, und Buttons entfallen. Navigation und Fuß nutzen
 * dafür das Ziel #kw:page:<pfad> (functions.php); die Pflichtlinks unter „Rechtliches“ bleiben unverändert.
 */

/** Ist die Seite mit diesem Pfad (z. B. „ueber-uns/team“) veröffentlicht? */
function kurswasser_page_published(string $path): bool
{
    $path = trim($path, '/');
    if ($path === '') {
        return true;
    }
    $page = get_page_by_path($path, OBJECT, 'page');

    return $page instanceof WP_Post && $page->post_status === 'publish' && $page->post_password === '';
}

/**
 * Pfade der Inhaltsseiten aus dem Manifest, die nicht veröffentlicht sind (Pfad => true). Zwischengespeichert je
 * Stand der Beiträge (last_changed), damit Änderungen in derselben Anfrage (Tests, Synchronisation) sofort wirken.
 *
 * @return array<string,bool>
 */
function kurswasser_content_unpublished_paths(): array
{
    static $cache = [];
    $key = wp_cache_get_last_changed('posts');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $paths = [];
    foreach (kurswasser_content_manifest() as $entry) {
        $path = trim((string) $entry['path'], '/');
        if (! kurswasser_page_published($path)) {
            $paths[$path] = true;
        }
    }

    $cache = [$key => (array) apply_filters('kurswasser_content_unpublished_paths', $paths)];

    return $cache[$key];
}

/** Zeigt der Link (#kw:…, /pfad/ oder absolute URL dieser Website) auf eine nicht veröffentlichte Inhaltsseite? */
function kurswasser_link_unpublished(string $href): bool
{
    $paths = kurswasser_content_unpublished_paths();
    if ($paths === []) {
        return false;
    }
    $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/^#kw:page:([a-z0-9\/-]+)/', $href, $m)) {
        return isset($paths[trim($m[1], '/')]);
    }
    if (preg_match('/^#kw:([a-z0-9\/-]+)/', $href, $m) && function_exists('kurswasser_url')) {
        $href = kurswasser_url($m[1]);
    }
    $parts = wp_parse_url($href);
    if (! is_array($parts) || empty($parts['path']) || (empty($parts['host']) && ! str_starts_with($parts['path'], '/'))) {
        return false;
    }
    if (! empty($parts['host']) && strtolower($parts['host']) !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
        return false;
    }
    $base = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    $path = trim(rawurldecode($parts['path']), '/');
    if ($base !== '' && str_starts_with($path.'/', $base.'/')) {
        $path = trim(substr($path, strlen($base)), '/');
    }

    return isset($paths[$path]);
}

/** Link auf eine Seite, wenn sie veröffentlicht ist, sonst nur der Text (für Muster und feste Texte). */
function kurswasser_page_link(string $path, string $label, string $fragment = ''): string
{
    if (! kurswasser_page_published($path)) {
        return esc_html($label);
    }
    $page = get_page_by_path(trim($path, '/'), OBJECT, 'page');

    return '<a href="'.esc_url((string) get_permalink($page).$fragment).'">'.esc_html($label).'</a>';
}

/**
 * Links auf nicht veröffentlichte Inhaltsseiten im fertigen HTML entschärfen: Karten (kw-feature) und Kacheln
 * (kw-tile) mit solchem Titel-Link und Buttons entfallen, alle übrigen Links werden zu Text.
 */
function kurswasser_content_unlink_unpublished(string $html): string
{
    if (! str_contains($html, 'href=') || kurswasser_content_unpublished_paths() === []) {
        return $html;
    }
    $bad = static fn (string $chunk): bool => (bool) preg_match_all('/href="([^"]*)"/', $chunk, $m) && array_filter($m[1], 'kurswasser_link_unpublished') !== [];
    $html = (string) preg_replace_callback('#<div class="kw-feature">.*?</div>|<article class="kw-tile">.*?</article>#s', static fn (array $m): string => $bad($m[0]) ? '' : $m[0], $html);
    $html = (string) preg_replace_callback('#<div class="wp-block-button[^"]*">\s*<a\b[^>]*>.*?</a>\s*</div>|<a class="kw-btn\b[^"]*"[^>]*>.*?</a>#s', static fn (array $m): string => $bad($m[0]) ? '' : $m[0], $html);

    $html = (string) preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#s', static function (array $m): string {
        if (! preg_match('/href="([^"]*)"/', $m[1], $h) || ! kurswasser_link_unpublished($h[1])) {
            return $m[0];
        }
        $fallback = kurswasser_content_fallback_target($h[1]);

        return $fallback !== null ? '<a'.str_replace($h[0], 'href="'.esc_url($fallback).'"', $m[1]).'>'.$m[2].'</a>' : $m[2];
    }, $html);

    // Abschluss-Aufruf ohne Knopf (alle Ziele noch Entwurf): Ersatzknopf „Kostenlos testen“.
    return (string) preg_replace('#<div class="wp-block-buttons([^"]*)">\s*</div>#', '<div class="wp-block-buttons$1"><div class="wp-block-button is-style-signal"><a class="wp-block-button__link wp-element-button" href="'.esc_url(kurswasser_content_link('#kw:learn')).'">'.esc_html__('Kostenlos testen', 'kurswasser').'</a></div></div>', $html);
}

/**
 * Ausweichziel für Links auf eine noch nicht veröffentlichte Seite: Die Garantieseite verweist dann auf den
 * Abschnitt in den AGB (wie inc/offers.php), sofern die AGB veröffentlicht sind. Sonst null (Link wird zu Text).
 */
function kurswasser_content_fallback_target(string $href): ?string
{
    $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (! str_contains($href, 'geld-zurueck-garantie-bootsfuehrerschein') || ! kurswasser_page_published('agb')) {
        return null;
    }

    return kurswasser_content_link('#kw:agb#geld-zurueck-garantie');
}

add_filter('render_block', static function (string $html, array $block): string {
    return in_array($block['blockName'] ?? '', ['core/post-content', 'core/pattern'], true) ? kurswasser_content_unlink_unpublished($html) : $html;
}, 25, 2);

/**
 * Alte FAQ-Seite des Plugins (/haeufige-fragen/ mit [bootspruefung_faq], „FAQ-Seite anlegen“ vor dem 10.11.2026):
 * 301 auf die FAQ-Seite /faq-bootsfuehrerschein/, sobald diese veröffentlicht ist – auch wenn die alte Seite
 * inzwischen Entwurf ist (404).
 */
function kurswasser_faq_redirect_target(): ?string
{
    $path = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $base = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($base !== '' && str_starts_with($path.'/', $base.'/')) {
        $path = trim(substr($path, strlen($base)), '/');
    }
    if ($path !== 'haeufige-fragen') {
        return null;
    }
    $old = get_page_by_path('haeufige-fragen', OBJECT, 'page');
    $new = get_page_by_path('faq-bootsfuehrerschein', OBJECT, 'page');
    if (! $old instanceof WP_Post || ! has_shortcode($old->post_content, 'bootspruefung_faq') || ! $new instanceof WP_Post
        || $new->ID === $old->ID || $new->post_status !== 'publish') {
        return null;
    }

    return (string) get_permalink($new);
}

add_action('template_redirect', static function (): void {
    if ((is_page() || is_404()) && ($target = kurswasser_faq_redirect_target()) !== null) {
        wp_safe_redirect($target, 301, 'Kurswasser');
        exit;
    }
}, 5);

function kurswasser_content_hash(WP_Post $page): string
{
    return md5(trim((string) $page->post_title)."\n".trim((string) $page->post_content));
}

/** Leere Seite bzw. die schlichte FAQ-Seite des Plugins („FAQ-Seite anlegen“) darf übernommen werden. */
function kurswasser_content_is_stub(WP_Post $page): bool
{
    $content = trim((string) preg_replace('/\s+/', ' ', $page->post_content));

    return $content === '' || in_array($content, ['<!-- wp:shortcode --> [bootspruefung_faq] <!-- /wp:shortcode -->', '<!-- wp:shortcode -->[bootspruefung_faq]<!-- /wp:shortcode -->', '[bootspruefung_faq]'], true);
}

/** Werte, die die Synchronisation als Post-Meta setzt (SEO-Felder wie inc/seo.php). */
function kurswasser_content_seo_values(array $entry): array
{
    return [
        '_kw_seo_title' => (string) ($entry['seo_title'] ?? ''),
        '_kw_seo_description' => (string) ($entry['meta_description'] ?? ''),
        '_kw_noindex' => ($entry['index'] ?? 'index') === 'noindex' ? '1' : '',
        '_kw_canonical' => (string) ($entry['canonical'] ?? ''),
        '_kw_reviewed_date' => (string) ($entry['reviewed'] ?? ''),
        '_kw_schema' => in_array($entry['schema'] ?? '', ['', 'none'], true) ? '' : (string) $entry['schema'],
    ];
}

/** Prüfsumme des Vorlagenstands (Inhalt, Titel, Vorlage, Meta, Status) – ändert sich mit jedem neuen Stand. */
function kurswasser_content_source_hash(array $entry, string $content): string
{
    return md5((string) wp_json_encode([
        $content, kurswasser_content_tokens((string) $entry['title']), (string) ($entry['template'] ?? ''), (string) ($entry['status'] ?? ''),
        kurswasser_content_seo_values($entry), $entry['breadcrumb'] ?? [], $entry['crumb_label'] ?? '', (int) ($entry['nr'] ?? 0),
    ]));
}

/**
 * Seiten aus dem Manifest anlegen bzw. aktualisieren.
 *
 * Optionen: dry_run (nur prüfen), force (auch bearbeitete/fremde Seiten und geänderte SEO-Felder überschreiben),
 * dir (Vorlagenordner), seo („theme“ bzw. „yoast“; Standard: kurswasser_content_seo_target()). Mit seo=yoast
 * stehen je Seite zusätzlich die Yoast-Felder in 'yoast' (written = gesetzt bzw. zu setzen, kept = vom Betreiber).
 * Zustände je Pfad: created|updated (bzw. im Probelauf new|outdated), unchanged, edited (neue Vorlage, Seite
 * vom Betreiber geändert), customized (geändert, Vorlage unverändert), foreign (Seite gab es schon, nicht aus
 * der Vorlage), blocked (live und neue Vorlage enthält Platzhalter), legal (ersetzt einen Rechtstext),
 * trashed, no-parent, no-file, error.
 *
 * @return array<string,array{state:string,id:int,title:string,nr:int}>
 */
function kurswasser_content_pages_sync(array $options = []): array
{
    $dry = ! empty($options['dry_run']);
    $force = ! empty($options['force']);
    $dir = isset($options['dir']) ? (string) $options['dir'] : null;
    $seo = in_array($options['seo'] ?? '', ['theme', 'yoast'], true) ? (string) $options['seo'] : kurswasser_content_seo_target();
    $result = [];
    $planned = [];
    $entries = [];
    // Ohne unfiltered_html (WP-CLI ohne --user, Cron) würde kses #kw:-Links und Inline-Stile entfernen.
    $kses = ! $dry && has_filter('content_save_pre', 'wp_filter_post_kses');
    if ($kses) {
        kses_remove_filters();
    }
    try {
        foreach (kurswasser_content_manifest($dir) as $entry) {
            $path = (string) $entry['path'];
            $entries[$path] = $entry;
            $title = kurswasser_content_tokens((string) $entry['title']);
            $set = static function (string $state, int $id = 0) use (&$result, $path, $title, $entry): void {
                $result[$path] = ['state' => $state, 'id' => $id, 'title' => $title, 'nr' => (int) ($entry['nr'] ?? 0)];
            };
            if (! empty($entry['replaces'])) {
                $set('legal', ($p = kurswasser_content_page($entry)) ? $p->ID : 0);
                continue;
            }
            $content = kurswasser_content_template($entry, $dir);
            if ($content === null) {
                $set('no-file');
                continue;
            }
            $parentId = 0;
            if (! empty($entry['parent'])) {
                $parent = get_page_by_path((string) $entry['parent'], OBJECT, 'page');
                if ($parent instanceof WP_Post && $parent->post_status !== 'trash') {
                    $parentId = (int) $parent->ID;
                } elseif (! ($dry && isset($planned[$entry['parent']]))) {
                    $set('no-parent');
                    continue;
                }
            }
            $source = kurswasser_content_source_hash($entry, $content);
            $page = kurswasser_content_page($entry);
            if ($page && $page->post_status === 'trash') {
                $set('trashed', $page->ID);
                continue;
            }
            $placeholder = kurswasser_content_has_placeholders($content.$title);
            $wanted = ($entry['status'] ?? 'draft') === 'publish' && ! $placeholder ? 'publish' : 'draft';

            if (! $page) {
                $planned[$path] = true;
                if ($dry) {
                    $set('new');
                    continue;
                }
                $id = wp_insert_post(wp_slash([
                    'post_type' => 'page', 'post_status' => $wanted, 'post_title' => $title, 'post_name' => (string) $entry['slug'],
                    'post_parent' => $parentId, 'post_content' => $content, 'menu_order' => (int) ($entry['nr'] ?? 0),
                    'comment_status' => 'closed', 'ping_status' => 'closed',
                ]), true);
                if (is_wp_error($id) || ! $id) {
                    $set('error');
                    continue;
                }
                kurswasser_content_store_meta((int) $id, $entry, $source, $wanted, true);
                $set('created', (int) $id);
                continue;
            }

            $ours = get_post_meta($page->ID, '_kw_content_hash', true) !== '';
            $untouched = $ours ? hash_equals((string) get_post_meta($page->ID, '_kw_content_hash', true), kurswasser_content_hash($page)) : kurswasser_content_is_stub($page);
            $same = $ours && get_post_meta($page->ID, '_kw_content_source', true) === $source;
            if ($same && $untouched && ! $force) {
                $set('unchanged', $page->ID);
                continue;
            }
            if (! $untouched && ! $force) {
                $set($ours ? ($same ? 'customized' : 'edited') : 'foreign', $page->ID);
                continue;
            }
            // Status: vom Betreiber geänderten Status behalten; sonst den neuen Vorlagenstatus übernehmen.
            $recorded = (string) get_post_meta($page->ID, '_kw_content_status', true);
            $status = $ours && $recorded !== '' && $recorded !== $page->post_status ? $page->post_status : $wanted;
            if ($placeholder && in_array($page->post_status, ['publish', 'future', 'private'], true)) {
                $set('blocked', $page->ID); // Live-Seite nicht mit Platzhaltern füllen und nicht still zurückziehen.
                continue;
            }
            if ($placeholder) {
                $status = 'draft';
            }
            if ($dry) {
                $set('outdated', $page->ID);
                continue;
            }
            $id = wp_update_post(wp_slash([
                'ID' => $page->ID, 'post_title' => $title, 'post_content' => $content, 'post_status' => $status,
                'post_parent' => $parentId ?: (int) $page->post_parent,
                'page_template' => '', // Vorlage setzt kurswasser_content_store_meta(); ohne Prüfung gegen das aktive Theme.
            ]), true);
            if (is_wp_error($id) || ! $id) {
                $set('error', $page->ID);
                continue;
            }
            kurswasser_content_store_meta((int) $id, $entry, $source, $status, ! $ours || $force);
            $set('updated', (int) $id);
        }
        if ($seo === 'yoast') {
            // Auch unveränderte und vom Betreiber bearbeitete Seiten: Die Yoast-Felder haben eigene Regeln je Feld.
            foreach ($result as $path => $row) {
                if (in_array($row['state'], ['created', 'updated', 'unchanged', 'customized', 'edited', 'new', 'outdated'], true)) {
                    $result[$path]['yoast'] = kurswasser_content_yoast_store($row['id'], $entries[$path], $force, $dry);
                }
            }
        }
    } finally {
        if ($kses) {
            kses_init_filters();
        }
    }

    return $result;
}

/**
 * Meta nach dem Speichern: Prüfsummen, Vorlage, Brotkrumen, offene Punkte und SEO-Felder. SEO-Felder, die der
 * Betreiber in der Metabox geändert hat, bleiben (Vergleich mit dem zuletzt gesetzten Wert).
 */
function kurswasser_content_store_meta(int $id, array $entry, string $source, string $status, bool $fresh): void
{
    clean_post_cache($id);
    $saved = get_post($id);
    if (! $saved instanceof WP_Post) {
        return;
    }
    update_post_meta($id, '_kw_content_hash', kurswasser_content_hash($saved));
    update_post_meta($id, '_kw_content_source', $source);
    update_post_meta($id, '_kw_content_status', $status);
    update_post_meta($id, '_kw_content_nr', (int) ($entry['nr'] ?? 0));
    update_post_meta($id, '_kw_content_open_items', array_values(array_map('strval', (array) ($entry['open_items'] ?? []))));
    update_post_meta($id, '_kw_breadcrumb', ['items' => array_values((array) ($entry['breadcrumb'] ?? [])), 'label' => (string) ($entry['crumb_label'] ?? '')]);
    $template = (string) ($entry['template'] ?? '');
    $template === '' ? delete_post_meta($id, '_wp_page_template') : update_post_meta($id, '_wp_page_template', $template);
    $last = get_post_meta($id, '_kw_content_seo', true);
    $last = is_array($last) ? $last : [];
    foreach (kurswasser_content_seo_values($entry) as $key => $value) {
        $current = (string) get_post_meta($id, $key, true);
        if ($fresh || $current === (string) ($last[$key] ?? '') || ($current === '' && ! array_key_exists($key, $last))) {
            $value === '' ? delete_post_meta($id, $key) : update_post_meta($id, $key, $value);
        }
    }
    update_post_meta($id, '_kw_content_seo', kurswasser_content_seo_values($entry));
}

/**
 * Offene Punkte je Seite: open_items aus dem Manifest plus erkannte Punkte (Entwurf, Platzhalter).
 *
 * @return list<array{nr:int,title:string,id:int,status:string,items:list<string>}>
 */
function kurswasser_content_open_items(?string $dir = null): array
{
    $open = [];
    foreach (kurswasser_content_manifest($dir) as $entry) {
        $page = kurswasser_content_page($entry);
        $page = $page && $page->post_status !== 'trash' ? $page : null;
        $items = array_values(array_map('strval', (array) ($entry['open_items'] ?? [])));
        if (! empty($entry['replaces'])) {
            $items[] = sprintf(__('ersetzt den Rechtstext „%s“ – Vorlage legal/%s.html anpassen, keine eigene Seite', 'kurswasser'), substr((string) $entry['replaces'], 6), substr((string) $entry['replaces'], 6));
        } elseif (! $page) {
            $items[] = __('Seite noch nicht angelegt', 'kurswasser');
        } else {
            if (str_contains($page->post_content, '[[Betreiber:')) {
                $items[] = __('enthält [[Betreiber: …]]-Platzhalter', 'kurswasser');
            }
            if (str_contains($page->post_content, 'kw-placeholder')) {
                $items[] = __('enthält einen „Offen“-Kasten', 'kurswasser');
            }
        }
        if ($items) {
            $open[] = ['nr' => (int) ($entry['nr'] ?? 0), 'title' => kurswasser_content_tokens((string) $entry['title']), 'id' => $page ? (int) $page->ID : 0,
                'status' => $page ? (string) $page->post_status : '', 'items' => $items];
        }
    }

    return $open;
}

/** Zustände zusammenfassen: Zustand => Liste von Titeln (Yoast: yoast-pending/-written, yoast-kept). */
function kurswasser_content_group(array $result): array
{
    $groups = [];
    foreach ($result as $row) {
        $groups[$row['state']][] = $row['title'];
        if (! empty($row['yoast']['written'])) {
            $groups[! empty($row['yoast']['dry']) ? 'yoast-pending' : 'yoast-written'][] = $row['title'];
        }
        if (! empty($row['yoast']['kept'])) {
            $groups['yoast-kept'][] = $row['title'];
        }
    }

    return $groups;
}

/** SEO-Felder (_kw_), die der Betreiber nach dem letzten Abgleich geändert hat (Schlüssel). */
function kurswasser_content_seo_customized(int $id): array
{
    $last = get_post_meta($id, '_kw_content_seo', true);
    if (! is_array($last)) {
        return [];
    }
    $changed = [];
    foreach ($last as $key => $value) {
        if ((string) get_post_meta($id, (string) $key, true) !== (string) $value) {
            $changed[] = (string) $key;
        }
    }

    return $changed;
}

/**
 * Was „Alles aus dem Theme übernehmen“ (force) überschreiben würde: je Seite die Gründe (von dir bearbeiteter
 * Inhalt, fremde Seite, geänderte SEO- bzw. Yoast-Felder). Seiten ohne eigene Änderungen fehlen.
 *
 * @return list<array{nr:int,title:string,id:int,reasons:list<string>}>
 */
function kurswasser_content_force_preview(?string $dir = null, ?string $seo = null): array
{
    $options = ['dry_run' => true, 'dir' => $dir, 'seo' => $seo ?? kurswasser_content_seo_target()];
    if ($dir === null) {
        unset($options['dir']);
    }
    $yoastLabels = kurswasser_content_yoast_fields();
    $seoLabels = ['_kw_seo_title' => __('SEO-Titel', 'kurswasser'), '_kw_seo_description' => __('Meta-Description', 'kurswasser'), '_kw_noindex' => __('noindex', 'kurswasser'),
        '_kw_canonical' => __('Canonical', 'kurswasser'), '_kw_reviewed_date' => __('Stand', 'kurswasser'), '_kw_schema' => __('Schema', 'kurswasser')];
    $preview = [];
    foreach (kurswasser_content_pages_sync($options) as $row) {
        $reasons = [];
        if ($row['state'] === 'edited' || $row['state'] === 'customized') {
            $reasons[] = __('Inhalt bzw. Titel von dir geändert – wird durch die Vorlage ersetzt', 'kurswasser');
        } elseif ($row['state'] === 'foreign') {
            $reasons[] = __('Seite gab es schon (nicht aus dem Theme) – Inhalt wird durch die Vorlage ersetzt', 'kurswasser');
        }
        if ($row['id'] && in_array($row['state'], ['unchanged', 'outdated', 'edited', 'customized'], true) && ($seoKeys = kurswasser_content_seo_customized($row['id']))) {
            $reasons[] = sprintf(__('SEO-Felder (Kurswasser) von dir geändert: %s', 'kurswasser'), implode(', ', array_map(static fn ($k) => $seoLabels[$k] ?? $k, $seoKeys)));
        }
        if (! empty($row['yoast']['kept'])) {
            $reasons[] = sprintf(__('Yoast-Felder von dir gesetzt: %s', 'kurswasser'), implode(', ', array_map(static fn ($k) => $yoastLabels[$k] ?? $k, $row['yoast']['kept'])));
        }
        if ($reasons) {
            $preview[] = ['nr' => $row['nr'], 'title' => $row['title'], 'id' => $row['id'], 'reasons' => $reasons];
        }
    }

    return $preview;
}

/** Bezeichnungen der Zustände (Admin und WP-CLI). */
function kurswasser_content_state_labels(): array
{
    return [
        'new' => __('neu (noch nicht angelegt)', 'kurswasser'),
        'outdated' => __('neuer Vorlagenstand, Seite unverändert', 'kurswasser'),
        'created' => __('angelegt', 'kurswasser'),
        'updated' => __('aktualisiert', 'kurswasser'),
        'unchanged' => __('aktuell', 'kurswasser'),
        'customized' => __('von dir bearbeitet (Vorlage unverändert)', 'kurswasser'),
        'edited' => __('neuer Vorlagenstand nicht übernommen – von dir bearbeitet', 'kurswasser'),
        'foreign' => __('Seite mit diesem Slug gab es schon – nicht angetastet', 'kurswasser'),
        'blocked' => __('nicht aktualisiert – Seite ist live und die neue Vorlage enthält Platzhalter', 'kurswasser'),
        'legal' => __('übersprungen – ersetzt einen Rechtstext (inc/legal.php)', 'kurswasser'),
        'trashed' => __('im Papierkorb – nicht neu angelegt', 'kurswasser'),
        'no-parent' => __('Elternseite fehlt', 'kurswasser'),
        'no-file' => __('Vorlagendatei fehlt', 'kurswasser'),
        'error' => __('Fehler beim Speichern', 'kurswasser'),
        'yoast-pending' => __('Yoast-Felder zu übernehmen', 'kurswasser'),
        'yoast-written' => __('Yoast-Felder übernommen', 'kurswasser'),
        'yoast-kept' => __('Yoast-Felder von dir gesetzt – nicht überschrieben', 'kurswasser'),
    ];
}

/*
 * #kw:-Links (href="#kw:shop" usw.) überstehen kses: Wer kein unfiltered_html hat (z. B. Redakteur, Multisite-
 * Admin), speichert sonst nur „shop“. „#kw“ gilt dafür als erlaubtes Schema; der Wert bleibt ein Anker
 * (Fragment) und wird beim Ausgeben durch functions.php ersetzt.
 */
add_filter('kses_allowed_protocols', static function (array $protocols): array {
    $protocols[] = '#kw';

    return $protocols;
});

/** Kurs-Hero für Infoseiten (Typ A, Vorlage course) – Markup wie die Kursseiten (inc/courses.php). */
add_shortcode('kurswasser_page_hero', static function ($atts): string {
    $a = shortcode_atts(['eyebrow' => '', 'lead' => '', 'primary' => '', 'secondary' => '', 'photo' => '', 'flip' => '', 'stats' => '', 'nav' => '', 'title' => '', 'note' => '', 'stand' => ''], (array) $atts, 'kurswasser_page_hero');
    $a = array_map(static fn ($v): string => kurswasser_content_tokens(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $a);
    $post = get_post();
    $title = $a['title'] !== '' ? esc_html($a['title']) : ($post ? esc_html(get_the_title($post)) : '');
    $style = '';
    $slot = sanitize_key($a['photo']);
    if ($slot !== '' && function_exists('kurswasser_course_image') && ($photo = kurswasser_course_image($slot))) {
        $style = ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) kurswasser_course_image($slot, true)).'\')"';
    }
    $stats = array_filter(array_map(static fn ($s) => array_map('trim', explode('|', $s)), explode('||', $a['stats'])), static fn ($s) => count($s) === 3);
    $html = '<section class="kw-course-hero alignfull kw-page-hero'.($style !== '' && $a['flip'] === '1' ? ' is-flip' : '').'"'.$style.'><div class="kw-course-hero-inner">';
    if ($post) {
        $html .= kurswasser_breadcrumbs_html(kurswasser_breadcrumbs($post));
    }
    $html .= '<div class="kw-course-hero-grid'.($stats ? '' : ' is-single').'"><div>';
    if ($a['eyebrow'] !== '') {
        $html .= '<p class="kw-eyebrow">'.esc_html($a['eyebrow']).'</p>';
    }
    $html .= '<h1>'.$title.'</h1>';
    if ($a['lead'] !== '') {
        $html .= '<p class="kw-course-lead">'.esc_html($a['lead']).'</p>';
    }
    $buttons = '';
    foreach (['primary' => 'kw-btn-signal', 'secondary' => 'kw-btn-ghost'] as $key => $class) {
        $parts = array_map('trim', explode('|', $a[$key]));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' && ! kurswasser_link_unpublished($parts[1])) {
            $buttons .= '<a class="kw-btn '.$class.'" href="'.esc_url(kurswasser_content_link($parts[1])).'">'.esc_html($parts[0]).'</a>';
        }
    }
    if ($buttons !== '') {
        $html .= '<div class="kw-course-cta">'.$buttons.'</div>';
    }
    if ($a['note'] !== '') {
        // Kurze Zeile unter den Buttons (z. B. „Mit kostenlosem Kundenkonto.“) – Farbe und Schrift wie der Lead, kleiner.
        $html .= '<p class="kw-course-lead kw-page-hero-note" style="margin-top:.9rem;font-size:.85rem">'.esc_html($a['note']).'</p>';
    }
    $html .= kurswasser_content_stand_html($a['stand']);
    $nav = array_filter(array_map(static fn ($s) => array_map('trim', explode('|', $s)), explode('||', $a['nav'])), static fn ($s) => count($s) === 2 && str_starts_with($s[0], '#'));
    if ($nav) {
        $html .= '<nav class="kw-overview-nav" aria-label="'.esc_attr__('Auf dieser Seite', 'kurswasser').'">';
        foreach ($nav as [$anchor, $label]) {
            $html .= '<a href="'.esc_attr('#'.sanitize_title(substr($anchor, 1))).'">'.esc_html($label).'</a>';
        }
        $html .= '</nav>';
    }
    $html .= '</div>';
    if ($stats) {
        $html .= '<ul class="kw-course-stats">';
        foreach ($stats as [$icon, $value, $label]) {
            $html .= '<li><span class="kw-icon kw-icon-'.esc_attr(sanitize_key($icon)).'" aria-hidden="true"></span><strong>'.esc_html($value).'</strong><span>'.esc_html($label).'</span></li>';
        }
        $html .= '</ul>';
    }

    return $html.'</div></div></section>';
});

/** Zeile „Stand: Monat Jahr“ (Ratgeber- und prüfungsrechtliche Seiten); $date = JJJJ-MM-TT. Keine Quellen, nur das Datum. */
function kurswasser_content_stand_html(string $date, string $class = 'kw-hero-meta'): string
{
    if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $m)) {
        return '';
    }
    $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $label = ($months[(int) $m[2] - 1] ?? '').' '.$m[1];
    $how = kurswasser_page_published('ueber-uns') ? ' <span aria-hidden="true">·</span> '.kurswasser_page_link('ueber-uns', __('So entstehen unsere Inhalte', 'kurswasser'), '#inhalte') : '';

    return '<p class="'.esc_attr($class).'"><span class="kw-icon kw-icon-clock" aria-hidden="true"></span>'.esc_html__('Stand:', 'kurswasser').' <time datetime="'.esc_attr($m[0]).'">'.esc_html($label).'</time>'.$how.'</p>';
}

/** Kompassrose mit Kurslinie und gelbem Wegpunkt (Dekor des Kopfs „Seekarte“, Farbe = currentColor). */
function kurswasser_chart_rose_svg(): string
{
    $cx = 300;
    $cy = 170;
    $o = '<svg class="kw-chart-deco" viewBox="0 0 470 340" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><g stroke-width="1.2" opacity=".28">';
    foreach ([0, 22, 44, 66] as $off) {
        $o .= sprintf('<path d="M%.1f %d C %d %d, %.1f %.1f, %.1f %.1f S 205 %.1f, %.1f -10"/>', -10 + $off * .3, 300 - $off, 60 + $off, 250 - $off, 90 + $off * .5, 150 - $off * .6, 150 + $off * .4, 120 - $off * .8, 30 - $off * .4, 250 + $off * .3);
    }
    $o .= '</g><path d="M18 318 L120 236 L196 214" stroke-width="2" stroke-dasharray="6 7" opacity=".55"/><circle cx="120" cy="236" r="5" fill="#F5B900" stroke="none"/><circle cx="18" cy="318" r="4" fill="currentColor" stroke="none" opacity=".55"/>';
    $o .= '<g opacity=".38" stroke-width="1.2"><circle cx="300" cy="170" r="150"/><circle cx="300" cy="170" r="136" stroke-dasharray="1.5 5.5"/><circle cx="300" cy="170" r="100"/><circle cx="300" cy="170" r="14"/>';
    for ($d = 0; $d < 360; $d += 10) {
        $a = deg2rad($d);
        $r1 = $d % 90 ? 141 : 128;
        $o .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $cx + $r1 * sin($a), $cy - $r1 * cos($a), $cx + 150 * sin($a), $cy - 150 * cos($a));
    }
    $o .= '</g><g opacity=".5" stroke-linejoin="round">';
    foreach ([[92, 16, 45, '.25'], [126, 22, 0, '.55']] as [$r, $w, $ang0, $fill]) {
        for ($i = 0; $i < 4; $i++) {
            $a = deg2rad($ang0 + $i * 90);
            $tip = [$cx + $r * sin($a), $cy - $r * cos($a)];
            $l = [$cx + $w * sin($a - M_PI / 4), $cy - $w * cos($a - M_PI / 4)];
            $rr = [$cx + $w * sin($a + M_PI / 4), $cy - $w * cos($a + M_PI / 4)];
            $o .= sprintf('<path d="M%d %d L%.1f %.1f L%.1f %.1f Z" fill="currentColor" fill-opacity="%s" stroke-width="1.2"/>', $cx, $cy, $l[0], $l[1], $tip[0], $tip[1], $fill);
            $o .= sprintf('<path d="M%d %d L%.1f %.1f L%.1f %.1f Z" fill="none" stroke-width="1.2"/>', $cx, $cy, $rr[0], $rr[1], $tip[0], $tip[1]);
        }
    }

    return $o.'</g><text x="300" y="14" text-anchor="middle" font-weight="800" font-size="16" fill="currentColor" stroke="none" opacity=".6">N</text></svg>';
}

/**
 * Kopf „Seekarte“ für Seiten vom Typ B (Vorlage page-info): Raster, Kompassrose, Brotkrumen, Eyebrow, H1 (Seitentitel),
 * Einleitung, Sprung-Chips (#anker bzw. /pfad/ mit Symbol; Ziele, die noch Entwurf sind, entfallen) und Stand.
 */
add_shortcode('kurswasser_page_head', static function ($atts): string {
    $a = shortcode_atts(['eyebrow' => '', 'lead' => '', 'nav' => '', 'stand' => '', 'title' => ''], (array) $atts, 'kurswasser_page_head');
    $a = array_map(static fn ($v): string => kurswasser_content_tokens(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $a);
    $post = get_post();
    $title = $a['title'] !== '' ? $a['title'] : ($post ? get_the_title($post) : '');
    $html = '<div class="kw-page-head is-chart alignfull">'.kurswasser_chart_rose_svg().'<div class="kw-page-head-inner">';
    if ($post) {
        $html .= kurswasser_breadcrumbs_html(kurswasser_breadcrumbs($post));
    }
    $html .= ($a['eyebrow'] !== '' ? '<p class="kw-eyebrow">'.esc_html($a['eyebrow']).'</p>' : '').'<h1>'.esc_html(wp_strip_all_tags($title)).'</h1>';
    if ($a['lead'] !== '') {
        $html .= '<p class="kw-page-lead">'.esc_html($a['lead']).'</p>';
    }
    $chips = '';
    foreach (array_filter(explode('||', $a['nav'])) as $item) {
        $parts = array_map('trim', explode('|', $item));
        if (count($parts) < 2 || $parts[1] === '' || ! preg_match('#^(\#|/)#', $parts[0]) || kurswasser_link_unpublished($parts[0])) {
            continue;
        }
        $icon = sanitize_key($parts[2] ?? '');
        $href = str_starts_with($parts[0], '#') ? '#'.sanitize_title(substr($parts[0], 1)) : kurswasser_content_link($parts[0]);
        $chips .= '<a href="'.esc_url($href).'">'.($icon !== '' ? '<span class="kw-icon kw-icon-'.esc_attr($icon).'" aria-hidden="true"></span>' : '').esc_html($parts[1]).'</a>';
    }
    if ($chips !== '') {
        $html .= '<nav class="kw-page-chips" aria-label="'.esc_attr__('Auf dieser Seite', 'kurswasser').'">'.$chips.'</nav>';
    }
    $html .= kurswasser_content_stand_html($a['stand'], 'kw-page-meta');

    return $html.'</div></div>';
});

/** Kursfoto als Klasse (.kw-photo-<kurs>): Abschluss-Aufruf und Blöcke ohne Inline-Stil (Block-Validierung). */
function kurswasser_photo_css(): string
{
    $css = '';
    foreach (glob(get_template_directory().'/assets/images/courses/*-sm.webp') ?: [] as $file) {
        $slot = basename($file, '-sm.webp');
        $big = kurswasser_course_image($slot);
        $small = kurswasser_course_image($slot, true);
        if ($big && $small && preg_match('/^[a-z0-9-]+$/', $slot)) {
            $css .= '.kw-photo-'.$slot.'{--kw-course-photo:url("'.esc_url($big).'");--kw-course-photo-sm:url("'.esc_url($small).'")}';
        }
    }

    return $css;
}

add_action('wp_enqueue_scripts', static function (): void {
    if (wp_style_is('kurswasser', 'enqueued')) {
        wp_add_inline_style('kurswasser', kurswasser_photo_css());
    }
    $post = is_singular('page') ? get_queried_object() : null;
    if ($post instanceof WP_Post && (str_contains($post->post_content, 'kw-toc') || get_post_meta($post->ID, '_kw_content_nr', true) !== '')) {
        $file = get_template_directory().'/assets/js/content-pages.js';
        wp_enqueue_script('kurswasser-content-pages', get_template_directory_uri().'/assets/js/content-pages.js', [], is_file($file) ? (string) filemtime($file) : KURSWASSER_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    }
}, 20);

/** Hero-Foto der Seite (Slot aus [kurswasser_page_hero photo] bzw. [kurswasser_course id]) oder '' = Standardfoto. */
function kurswasser_content_photo_slot(?WP_Post $post): string
{
    if (! $post instanceof WP_Post) {
        return '';
    }
    if (preg_match('/\[kurswasser_page_hero\b[^\]]*\sphoto="([a-z0-9-]+)"/', $post->post_content, $m) || preg_match('/\[kurswasser_course id="([a-z0-9-]+)"/', $post->post_content, $m)) {
        return kurswasser_course_image($m[1]) ? $m[1] : '';
    }

    return '';
}

/** Hero-Foto vorladen (LCP): klein bis 781 px, groß ab 782 px – wie die Hintergrundbilder in theme.css. */
add_action('wp_head', static function (): void {
    $post = is_singular('page') ? get_queried_object() : null;
    if (! $post instanceof WP_Post || (! str_contains($post->post_content, '[kurswasser_page_hero') && ! str_contains($post->post_content, '[kurswasser_course id='))) {
        return;
    }
    $slot = kurswasser_content_photo_slot($post);
    $small = $slot !== '' ? (string) kurswasser_course_image($slot, true) : get_template_directory_uri().'/assets/images/hero-boat-960.webp';
    $big = $slot !== '' ? (string) kurswasser_course_image($slot) : get_template_directory_uri().'/assets/images/hero-boat.webp';
    printf('<link rel="preload" as="image" href="%s" type="image/webp" media="(max-width: 781px)" fetchpriority="high">'."\n", esc_url($small));
    printf('<link rel="preload" as="image" href="%s" type="image/webp" media="(min-width: 782px)" fetchpriority="high">'."\n", esc_url($big));
}, 2);

/*
 * Muster mit eigener Überschrift: <!-- wp:pattern {"slug":"kurswasser/courses","kwHeading":"…","kwEyebrow":"…","kwLead":"…"} /-->
 * ersetzt die erste H2, die erste Eyebrow-Zeile und den ersten Lead des Musters (Konverter, Abschnittsüberschrift).
 */
add_filter('render_block', static function (string $html, array $block): string {
    if (($block['blockName'] ?? '') !== 'core/pattern' || $html === '') {
        return $html;
    }
    $attrs = $block['attrs'] ?? [];
    $set = static function (string $html, string $pattern, string $text): string {
        return (string) preg_replace_callback($pattern, static fn (array $m): string => $m[1].esc_html($text).$m[3], $html, 1);
    };
    if (! empty($attrs['kwHeading'])) {
        $html = $set($html, '#(<h2\b[^>]*>)(.*?)(</h2>)#s', kurswasser_content_tokens((string) $attrs['kwHeading']));
    }
    if (! empty($attrs['kwEyebrow'])) {
        $html = $set($html, '#(<p\b[^>]*class="[^"]*kw-eyebrow[^"]*"[^>]*>)(.*?)(</p>)#s', kurswasser_content_tokens((string) $attrs['kwEyebrow']));
    }
    if (! empty($attrs['kwLead'])) {
        $html = $set($html, '#(<p\b[^>]*class="[^"]*kw-lead[^"]*"[^>]*>)(.*?)(</p>)#s', kurswasser_content_tokens((string) $attrs['kwLead']));
    }

    return $html;
}, 15, 2);

/*
 * Fußzeile: Einträge, deren Link auf eine Inhaltsseite zeigt, die noch Entwurf ist (z. B. Kontakt), entfallen – auch
 * in einer im Website-Editor angepassten Fußzeile (dort steht womöglich noch #kw:kontakt statt #kw:page:kontakt).
 */
add_filter('render_block', static function (string $html, array $block): string {
    if (($block['blockName'] ?? '') !== 'core/template-part' || ($block['attrs']['slug'] ?? '') !== 'footer' || ! str_contains($html, 'kw-footer-links')) {
        return $html;
    }

    return (string) preg_replace_callback('#<li>\s*<a href="([^"]*)"[^>]*>[^<]*</a>\s*</li>#', static fn (array $m): string => kurswasser_link_unpublished($m[1]) ? '' : $m[0], $html);
}, 26, 2);

/*
 * Admin: Hinweis mit Knopf „Inhaltsseiten anlegen bzw. aktualisieren“ (nur unveränderte Seiten) und die
 * offenen Punkte je Seite. Auf Dashboard, Seitenliste und Themes.
 */
add_action('admin_notices', static function (): void {
    if (! current_user_can('edit_pages')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $setup = $screen && $screen->id === 'appearance_page_kurswasser-setup';
    if ($screen && ! $setup && ! in_array($screen->id, ['dashboard', 'edit-page', 'themes'], true)) {
        return;
    }
    $uid = get_current_user_id();
    $flash = get_transient('kurswasser_content_flash_'.$uid);
    if (is_array($flash)) {
        delete_transient('kurswasser_content_flash_'.$uid);
        $labels = kurswasser_content_state_labels();
        echo '<div class="notice notice-success is-dismissible"><p><strong>'.esc_html__('Inhaltsseiten abgeglichen:', 'kurswasser').'</strong></p><ul style="list-style:disc;margin-left:1.5em">';
        foreach ($flash as $state => $titles) {
            echo '<li>'.esc_html(($labels[$state] ?? $state).': '.implode(', ', $titles)).'</li>';
        }
        echo '</ul></div>';
    }
    if ($setup || kurswasser_content_manifest() === []) {
        return; // Die Einrichtungsseite zeigt den Stand selbst.
    }
    $groups = kurswasser_content_group(kurswasser_content_pages_sync(['dry_run' => true]));
    $labels = kurswasser_content_state_labels();
    $todo = count($groups['new'] ?? []) + count($groups['outdated'] ?? []) + count($groups['yoast-pending'] ?? []);
    $report = array_intersect_key($groups, array_flip(['new', 'outdated', 'edited', 'foreign', 'blocked', 'no-parent', 'no-file', 'yoast-pending', 'yoast-kept']));
    $open = $screen && $screen->id === 'themes' ? [] : kurswasser_content_open_items();
    // Yoast später aktiviert, Seiten schon da: einmal fragen, ob die SEO-Daten in Yoast übernommen werden sollen.
    $askYoast = $todo === 0 && kurswasser_yoast_active() && kurswasser_content_seo_choice() === '' && current_user_can('manage_options')
        && array_intersect_key($groups, array_flip(['unchanged', 'customized', 'edited'])) !== [];
    if (! $report && ! $open && ! $askYoast) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>'.esc_html__('Inhaltsseiten aus dem Theme', 'kurswasser').'</strong> – '
        .esc_html__('Info- und Marketingseiten aus content/. Unveränderte Seiten übernehmen neue Vorlagenstände; von dir bearbeitete Seiten und SEO-Felder bleiben, außer du wählst ausdrücklich „Alles aus dem Theme übernehmen“.', 'kurswasser').'</p>';
    if ($report) {
        echo '<ul style="list-style:disc;margin-left:1.5em">';
        foreach ($report as $state => $titles) {
            echo '<li><strong>'.esc_html($labels[$state] ?? $state).'</strong> ('.count($titles).'): '.esc_html(implode(', ', $titles)).'</li>';
        }
        echo '</ul>';
    }
    if ($todo > 0 && current_user_can('manage_options')) {
        $setupUrl = admin_url('themes.php?page=kurswasser-setup#kw-setup-content');
        if (kurswasser_yoast_active() && kurswasser_content_seo_choice() === '') {
            // Yoast aktiv, aber noch keine Wahl getroffen: erst fragen, wohin die SEO-Daten sollen.
            echo '<p><a class="button button-primary" href="'.esc_url($setupUrl).'">'.esc_html__('Inhaltsseiten einrichten', 'kurswasser').'</a> '
                .esc_html__('(Yoast SEO ist aktiv – wähle, ob die SEO-Daten in Yoast oder im Theme stehen sollen)', 'kurswasser').'</p>';
        } else {
            $url = wp_nonce_url(admin_url('admin-post.php?action=kurswasser_content_sync'), 'kurswasser_content_sync');
            echo '<p><a class="button button-primary" href="'.esc_url($url).'">'.esc_html__('Inhaltsseiten anlegen bzw. aktualisieren', 'kurswasser').'</a> '
                .esc_html(sprintf(__('(%d Seiten; nur unveränderte – Seiten mit Platzhaltern als Entwurf)', 'kurswasser'), $todo))
                .' <a href="'.esc_url($setupUrl).'">'.esc_html(kurswasser_content_seo_target() === 'yoast' ? __('SEO-Daten: Yoast – ändern', 'kurswasser') : __('SEO-Daten: Theme – ändern', 'kurswasser')).'</a></p>';
        }
    }
    if ($askYoast) {
        echo '<p><a class="button" href="'.esc_url(admin_url('themes.php?page=kurswasser-setup#kw-setup-content')).'">'.esc_html__('SEO-Daten in Yoast übernehmen …', 'kurswasser').'</a> '
            .esc_html__('Yoast SEO ist aktiv. Du kannst Titel, Beschreibung, noindex und Canonical der Inhaltsseiten in die Yoast-Felder schreiben lassen.', 'kurswasser').'</p>';
    }
    if ($open) {
        echo '<details><summary><strong>'.esc_html(sprintf(__('Offene Punkte je Seite (%d Seiten)', 'kurswasser'), count($open))).'</strong></summary><ul style="list-style:disc;margin-left:1.5em">';
        foreach ($open as $row) {
            $link = $row['id'] ? get_edit_post_link($row['id']) : '';
            $label = $link ? '<a href="'.esc_url($link).'">'.esc_html($row['title']).'</a>' : esc_html($row['title']);
            $status = $row['status'] !== '' && $row['status'] !== 'publish' ? ' ('.esc_html(get_post_status_object($row['status'])?->label ?? $row['status']).')' : '';
            echo '<li>'.esc_html(sprintf('%02d', $row['nr'])).' '.$label.$status.': '.esc_html(implode('; ', $row['items'])).'</li>';
        }
        echo '</ul></details>';
    }
    echo '</div>';
});

add_action('admin_post_kurswasser_content_sync', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_content_sync');
    kurswasser_content_run_sync();
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=page'));
    exit;
});

/**
 * Abgleich mit Sperre (gegen Doppelklick) und Ergebnis als Hinweis für den aktuellen Benutzer.
 * Gibt die Gruppen zurück oder null, wenn gerade ein anderer Abgleich läuft.
 */
function kurswasser_content_run_sync(array $options = []): ?array
{
    if (! kurswasser_legal_lock(true, 'kurswasser_content_lock')) {
        return null;
    }
    try {
        $groups = kurswasser_content_group(kurswasser_content_pages_sync($options));
        set_transient('kurswasser_content_flash_'.get_current_user_id(), array_intersect_key($groups, array_flip(['created', 'updated', 'edited', 'foreign', 'blocked', 'no-parent', 'no-file', 'error', 'yoast-written', 'yoast-kept'])), 5 * MINUTE_IN_SECONDS);

        return $groups;
    } finally {
        kurswasser_legal_lock(false, 'kurswasser_content_lock');
    }
}

if (defined('WP_CLI') && WP_CLI) {
    $kurswasser_content_cli = static function (bool $dry): callable {
        return static function (array $args, array $assoc) use ($dry): void {
            $labels = kurswasser_content_state_labels();
            $options = ['dry_run' => $dry || ! empty($assoc['dry-run']), 'force' => ! $dry && ! empty($assoc['force'])];
            if (! empty($assoc['dir'])) {
                $options['dir'] = rtrim((string) $assoc['dir'], '/');
            }
            if (isset($assoc['seo'])) {
                if (! in_array($assoc['seo'], ['theme', 'yoast'], true)) {
                    WP_CLI::error('--seo=theme oder --seo=yoast');
                }
                $options['seo'] = (string) $assoc['seo'];
                if ($options['seo'] === 'yoast' && ! kurswasser_yoast_active()) {
                    WP_CLI::warning('Yoast SEO ist nicht aktiv – die Yoast-Felder werden trotzdem geschrieben und gelten, sobald Yoast aktiv ist.');
                }
            }
            $options['seo'] ??= kurswasser_content_seo_target();
            WP_CLI::log('SEO-Daten: '.($options['seo'] === 'yoast' ? 'Theme-Felder und Yoast' : 'Theme (inc/seo.php)'));
            if (kurswasser_content_manifest($options['dir'] ?? null) === []) {
                WP_CLI::warning('Kein Manifest bzw. keine Seiten in '.($options['dir'] ?? kurswasser_content_dir()).'/pages.php');

                return;
            }
            $result = kurswasser_content_pages_sync($options);
            foreach ($result as $path => $row) {
                $yoast = '';
                if (! empty($row['yoast']['written'])) {
                    $yoast .= ' · Yoast '.($options['dry_run'] ? 'zu setzen' : 'gesetzt').': '.implode(', ', array_map(static fn ($k) => substr($k, 13), $row['yoast']['written']));
                }
                if (! empty($row['yoast']['kept'])) {
                    $yoast .= ' · Yoast von dir (bleibt): '.implode(', ', array_map(static fn ($k) => substr($k, 13), $row['yoast']['kept']));
                }
                WP_CLI::log(sprintf('%02d %-44s %s%s%s', $row['nr'], '/'.$path.'/', $labels[$row['state']] ?? $row['state'], $row['id'] ? ' (#'.$row['id'].')' : '', $yoast));
            }
            foreach (kurswasser_content_open_items($options['dir'] ?? null) as $row) {
                WP_CLI::log(sprintf('  offen %02d %s: %s', $row['nr'], $row['title'], implode('; ', $row['items'])));
            }
            in_array('error', array_column($result, 'state'), true) ? WP_CLI::error('Fehler beim Speichern.') : WP_CLI::success($options['dry_run'] ? 'Probelauf – nichts geändert.' : 'Inhaltsseiten abgeglichen.');
        };
    };
    /**
     * Inhaltsseiten aus content/ anlegen bzw. aktualisieren (nur unveränderte Seiten).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Nur anzeigen, was passieren würde.
     *
     * [--force]
     * : Auch vom Betreiber bearbeitete und schon vorhandene fremde Seiten sowie geänderte SEO- und Yoast-Felder überschreiben.
     *
     * [--seo=<ziel>]
     * : Wohin die SEO-Daten gehen: theme (nur Theme-Felder) oder yoast (zusätzlich Yoast-Felder). Standard: Wahl aus der Einrichtung.
     *
     * [--dir=<ordner>]
     * : Anderer Vorlagenordner (mit pages.php und pages/).
     *
     * ## EXAMPLES
     *
     *     wp kurswasser content-pages sync --dry-run
     *     wp kurswasser content-pages sync --user=admin
     *     wp kurswasser content-pages sync --seo=yoast --user=admin
     */
    WP_CLI::add_command('kurswasser content-pages sync', $kurswasser_content_cli(false));
    /**
     * Stand der Inhaltsseiten und offene Punkte anzeigen (ändert nichts).
     *
     * [--seo=<ziel>]
     * : theme oder yoast (zeigt auch, welche Yoast-Felder gesetzt würden).
     *
     * [--dir=<ordner>]
     * : Anderer Vorlagenordner.
     */
    WP_CLI::add_command('kurswasser content-pages status', $kurswasser_content_cli(true));
    unset($kurswasser_content_cli);
}
