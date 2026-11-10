<?php
/**
 * Inhaltsseiten (Info- und Marketingseiten) aus den Vorlagen in content/ anlegen und aktualisieren.
 *
 * content/pages.php     – Manifest (erzeugt von tools/content_build.py aus den Markdown-Dateien)
 * content/pages/*.html  – Block-Markup je Seite; {{brand}} wird beim Anlegen zum Website-Namen
 *
 * Wie die Rechtstexte (inc/legal.php): Nichts wird ungefragt überschrieben. Eine Seite, die der Betreiber
 * geändert hat (Prüfsumme über Titel und Inhalt in _kw_content_hash), bleibt, wie sie ist; neue Vorlagen-
 * stände werden gemeldet. Angelegt und aktualisiert wird nur über den Knopf im Admin oder per WP-CLI
 * (`wp kurswasser content-pages sync`). Seiten mit [[Betreiber: …]] oder „Offen“-Kasten (kw-placeholder)
 * entstehen immer als Entwurf. Einträge, die einen Rechtstext ersetzen (replaces = legal:kontakt), werden
 * übersprungen – diese Seite pflegt inc/legal.php.
 *
 * Typ A (Vorlage course): Der Kurs-Hero kommt aus dem Shortcode [kurswasser_page_hero] am Seitenanfang
 * (Eyebrow, Lead, CTAs als Attribute, H1 = Seitentitel, Brotkrumen aus _kw_breadcrumb bzw. Hierarchie).
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

/** Enthält der Text noch Angaben, die nur der Betreiber liefern kann (→ immer Entwurf)? */
function kurswasser_content_has_placeholders(string $text): bool
{
    return str_contains($text, '[[Betreiber:') || str_contains($text, 'kw-placeholder');
}

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
 * Optionen: dry_run (nur prüfen), force (auch bearbeitete/fremde Seiten überschreiben), dir (Vorlagenordner).
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
    $result = [];
    $planned = [];
    // Ohne unfiltered_html (WP-CLI ohne --user, Cron) würde kses #kw:-Links und Inline-Stile entfernen.
    $kses = ! $dry && has_filter('content_save_pre', 'wp_filter_post_kses');
    if ($kses) {
        kses_remove_filters();
    }
    try {
        foreach (kurswasser_content_manifest($dir) as $entry) {
            $path = (string) $entry['path'];
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

/** Zustände zusammenfassen: Zustand => Liste von Titeln. */
function kurswasser_content_group(array $result): array
{
    $groups = [];
    foreach ($result as $row) {
        $groups[$row['state']][] = $row['title'];
    }

    return $groups;
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
    $a = shortcode_atts(['eyebrow' => '', 'lead' => '', 'primary' => '', 'secondary' => '', 'photo' => '', 'stats' => '', 'nav' => '', 'title' => ''], (array) $atts, 'kurswasser_page_hero');
    $a = array_map(static fn ($v): string => kurswasser_content_tokens(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $a);
    $post = get_post();
    $title = $a['title'] !== '' ? esc_html($a['title']) : ($post ? esc_html(get_the_title($post)) : '');
    $style = '';
    $slot = sanitize_key($a['photo']);
    if ($slot !== '' && function_exists('kurswasser_course_image') && ($photo = kurswasser_course_image($slot))) {
        $style = ' style="--kw-course-photo:url(\''.esc_url($photo).'\');--kw-course-photo-sm:url(\''.esc_url((string) kurswasser_course_image($slot, true)).'\')"';
    }
    $stats = array_filter(array_map(static fn ($s) => array_map('trim', explode('|', $s)), explode('||', $a['stats'])), static fn ($s) => count($s) === 3);
    $html = '<section class="kw-course-hero alignfull kw-page-hero"'.$style.'><div class="kw-course-hero-inner">';
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
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $buttons .= '<a class="kw-btn '.$class.'" href="'.esc_url(kurswasser_content_link($parts[1])).'">'.esc_html($parts[0]).'</a>';
        }
    }
    if ($buttons !== '') {
        $html .= '<div class="kw-course-cta">'.$buttons.'</div>';
    }
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

/*
 * Admin: Hinweis mit Knopf „Inhaltsseiten anlegen bzw. aktualisieren“ (nur unveränderte Seiten) und die
 * offenen Punkte je Seite. Auf Dashboard, Seitenliste und Themes.
 */
add_action('admin_notices', static function (): void {
    if (! current_user_can('edit_pages')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && ! in_array($screen->id, ['dashboard', 'edit-page', 'themes'], true)) {
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
    if (kurswasser_content_manifest() === []) {
        return;
    }
    $groups = kurswasser_content_group(kurswasser_content_pages_sync(['dry_run' => true]));
    $labels = kurswasser_content_state_labels();
    $todo = count($groups['new'] ?? []) + count($groups['outdated'] ?? []);
    $report = array_intersect_key($groups, array_flip(['new', 'outdated', 'edited', 'foreign', 'blocked', 'no-parent', 'no-file']));
    $open = $screen && $screen->id === 'themes' ? [] : kurswasser_content_open_items();
    if (! $report && ! $open) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>'.esc_html__('Inhaltsseiten aus dem Theme', 'kurswasser').'</strong> – '
        .esc_html__('Info- und Marketingseiten aus content/. Von dir bearbeitete Seiten werden nie überschrieben.', 'kurswasser').'</p>';
    if ($report) {
        echo '<ul style="list-style:disc;margin-left:1.5em">';
        foreach ($report as $state => $titles) {
            echo '<li><strong>'.esc_html($labels[$state] ?? $state).'</strong> ('.count($titles).'): '.esc_html(implode(', ', $titles)).'</li>';
        }
        echo '</ul>';
    }
    if ($todo > 0 && current_user_can('manage_options')) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=kurswasser_content_sync'), 'kurswasser_content_sync');
        echo '<p><a class="button button-primary" href="'.esc_url($url).'">'.esc_html__('Inhaltsseiten anlegen bzw. aktualisieren', 'kurswasser').'</a> '
            .esc_html(sprintf(__('(%d Seiten; nur unveränderte – Seiten mit Platzhaltern als Entwurf)', 'kurswasser'), $todo)).'</p>';
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
    if (kurswasser_legal_lock(true, 'kurswasser_content_lock')) {
        try {
            $groups = kurswasser_content_group(kurswasser_content_pages_sync());
            set_transient('kurswasser_content_flash_'.get_current_user_id(), array_intersect_key($groups, array_flip(['created', 'updated', 'edited', 'foreign', 'blocked', 'no-parent', 'no-file', 'error'])), 5 * MINUTE_IN_SECONDS);
        } finally {
            kurswasser_legal_lock(false, 'kurswasser_content_lock');
        }
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=page'));
    exit;
});

if (defined('WP_CLI') && WP_CLI) {
    $kurswasser_content_cli = static function (bool $dry): callable {
        return static function (array $args, array $assoc) use ($dry): void {
            $labels = kurswasser_content_state_labels();
            $options = ['dry_run' => $dry || ! empty($assoc['dry-run']), 'force' => ! empty($assoc['force'])];
            if (! empty($assoc['dir'])) {
                $options['dir'] = rtrim((string) $assoc['dir'], '/');
            }
            if (kurswasser_content_manifest($options['dir'] ?? null) === []) {
                WP_CLI::warning('Kein Manifest bzw. keine Seiten in '.($options['dir'] ?? kurswasser_content_dir()).'/pages.php');

                return;
            }
            $result = kurswasser_content_pages_sync($options);
            foreach ($result as $path => $row) {
                WP_CLI::log(sprintf('%02d %-44s %s%s', $row['nr'], '/'.$path.'/', $labels[$row['state']] ?? $row['state'], $row['id'] ? ' (#'.$row['id'].')' : ''));
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
     * : Auch vom Betreiber bearbeitete und schon vorhandene fremde Seiten überschreiben.
     *
     * [--dir=<ordner>]
     * : Anderer Vorlagenordner (mit pages.php und pages/).
     *
     * ## EXAMPLES
     *
     *     wp kurswasser content-pages sync --dry-run
     *     wp kurswasser content-pages sync --user=admin
     */
    WP_CLI::add_command('kurswasser content-pages sync', $kurswasser_content_cli(false));
    /**
     * Stand der Inhaltsseiten und offene Punkte anzeigen (ändert nichts).
     *
     * [--dir=<ordner>]
     * : Anderer Vorlagenordner.
     */
    WP_CLI::add_command('kurswasser content-pages status', $kurswasser_content_cli(true));
    unset($kurswasser_content_cli);
}
