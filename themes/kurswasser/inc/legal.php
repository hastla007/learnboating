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
 * Seite (außer mit $force). Status (Entwurf/veröffentlicht) bleibt unverändert. Mit $draftsOnly werden
 * nur Entwürfe (draft/pending) geändert; unbearbeitete, nicht mehr aktuelle Seiten mit anderem Status
 * (veröffentlicht, geplant, privat) meldet das Ergebnis als „pending“, ohne sie anzufassen.
 *
 * @return array<string,string> Slug => filled|updated|unchanged|pending|edited|no-page|no-file|error
 */
function kurswasser_fill_legal_pages(bool $force = false, bool $draftsOnly = false): array
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
        // Betreiberangaben (Design › Betreiberangaben) in die Platzhalter einsetzen – inc/operator.php.
        $template = function_exists('kurswasser_operator_fill') ? kurswasser_operator_fill($template) : $template;
        $stored = (string) get_post_meta($page->ID, '_kurswasser_legal_source', true);
        if (! $force && $stored === md5($template) && trim($page->post_content) !== '' && ! str_contains($page->post_content, 'Platzhalter – hier den geprüften Text einfügen')) {
            $result[$slug] = 'unchanged';
            continue;
        }
        if ($draftsOnly && ! in_array($page->post_status, ['draft', 'pending', 'auto-draft'], true)) {
            $result[$slug] = 'pending';
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

// Nach dem Anlegen der Seiten (kurswasser_create_pages, Priorität 10) füllen und zuordnen. Auch hier nur
// Entwürfe automatisch – veröffentlichte, unbearbeitete Seiten bekommen den Hinweis mit „Vorlagen übernehmen“.
add_action('after_switch_theme', static function (): void {
    kurswasser_legal_sync(true);
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

    // Geänderte Betreiberangaben gelten wie eine geänderte Vorlage.
    $sums[] = 'operator:'.(function_exists('kurswasser_operator_hash') ? kurswasser_operator_hash() : '-');

    return md5(implode('|', $sums));
}

/**
 * Sperre gegen parallele Abgleiche (zwei Admin-Tabs, Admin + Cron). INSERT IGNORE ist atomar – add_option()
 * nutzt „ON DUPLICATE KEY UPDATE“ und würde beim gleichzeitigen Zugriff beiden Anfragen Erfolg melden.
 * Eine liegen gebliebene Sperre (abgebrochene Anfrage) verfällt nach fünf Minuten. $name: eigene Sperre
 * (z. B. kurswasser_content_lock für die Inhaltsseiten).
 */
function kurswasser_legal_lock(bool $acquire, string $name = 'kurswasser_legal_lock'): bool
{
    global $wpdb;
    if (! $acquire) {
        $wpdb->delete($wpdb->options, ['option_name' => $name]);
        wp_cache_delete($name, 'options');

        return true;
    }
    $insert = static fn (): bool => 1 === (int) $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
        $name,
        (string) time()
    ));
    if ($insert()) {
        return true;
    }
    $since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
    if ($since > 0 && time() - $since < 5 * MINUTE_IN_SECONDS) {
        return false;
    }
    // Abgelaufen: nur löschen, wenn es noch dieselbe Sperre ist, dann neu versuchen (atomar).
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $since));

    return $insert();
}

/**
 * Vorlagen mit den Pflichtseiten abgleichen. Günstige Vorprüfung ohne Dateizugriff: Der Zustand (Theme-
 * Version, Vorlagen-Prüfsumme, letzte Prüfung) steht in einer automatisch geladenen Option; die Vorlagen
 * werden nur gehasht, wenn sich KURSWASSER_VERSION geändert hat oder die letzte Prüfung über einen Tag her
 * ist (fängt Vorlagen-Änderungen ohne Versionssprung ab). Bei geänderten Vorlagen: Entwürfe automatisch
 * aktualisieren, veröffentlichte unbearbeitete Seiten nur melden (Hinweis mit Knopf „Vorlagen übernehmen“).
 * Prüfsumme und Version werden erst nach fehlerfreiem Füllen gespeichert.
 */
function kurswasser_legal_sync(bool $recheck = false): void
{
    $state = get_option('kurswasser_legal_templates_state');
    $state = is_array($state) ? $state : [];
    if (! $state && is_string($legacy = get_option('kurswasser_legal_templates_hash'))) {
        // Bisheriger Stand (nur Prüfsumme): übernehmen, damit nach dem Update nicht alles neu gemeldet wird.
        $state = ['hash' => $legacy];
    }
    $sameVersion = ($state['version'] ?? '') === KURSWASSER_VERSION;
    if (! $recheck && $sameVersion && time() - (int) ($state['checked'] ?? 0) < DAY_IN_SECONDS) {
        return;
    }
    $hash = kurswasser_legal_templates_hash();
    $save = static function () use ($hash): void {
        update_option('kurswasser_legal_templates_state', ['version' => KURSWASSER_VERSION, 'hash' => $hash, 'checked' => time()], true);
        delete_option('kurswasser_legal_templates_hash');
    };
    if (! $recheck && ($state['hash'] ?? '') === $hash) {
        $save();

        return;
    }
    if (! kurswasser_legal_lock(true)) {
        return; // Eine andere Anfrage gleicht gerade ab.
    }
    try {
        $result = kurswasser_fill_legal_pages(false, true);
        if (in_array('error', $result, true)) {
            return; // Zustand nicht speichern – nächster Admin-Aufruf versucht es erneut.
        }
        $notice = kurswasser_legal_notice_from_result($result);
        if (array_filter($notice)) {
            $notice['id'] = $hash;
            update_option('kurswasser_legal_update_notice', $notice, false);
        }
        $save();
    } finally {
        kurswasser_legal_lock(false);
    }
}

/**
 * Hinweis-Inhalt (Seitentitel je Gruppe) aus dem Ergebnis von kurswasser_fill_legal_pages().
 *
 * @param array<string,string> $result
 * @return array{updated:list<string>,pending:list<string>,edited:list<string>}
 */
function kurswasser_legal_notice_from_result(array $result): array
{
    $pages = kurswasser_legal_pages();
    $pick = static fn (array $states): array => array_values(array_map(
        static fn (string $slug): string => $pages[$slug] ?? $slug,
        array_keys(array_filter($result, static fn (string $s): bool => in_array($s, $states, true)))
    ));

    return ['updated' => $pick(['updated', 'filled']), 'pending' => $pick(['pending']), 'edited' => $pick(['edited'])];
}

add_action('admin_init', static function (): void {
    if (! current_user_can('manage_options') || wp_doing_ajax()) {
        return;
    }
    kurswasser_legal_sync();
});

/*
 * Hinweis nach einem Vorlagen-Update: bleibt, bis die Person ihn wegklickt (je Admin in User-Meta, per AJAX
 * mit Nonce). Nicht im Block-/Website-Editor (dort würde er unsichtbar als Snackbar o. Ä. verbraucht).
 */
add_action('admin_notices', static function (): void {
    if (! current_user_can('manage_options')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->is_block_editor()) {
        return;
    }
    $notice = get_option('kurswasser_legal_update_notice');
    if (! is_array($notice) || empty($notice['id'])) {
        return;
    }
    $id = (string) $notice['id'];
    if (get_user_meta(get_current_user_id(), 'kurswasser_legal_notice_dismissed', true) === $id) {
        return;
    }
    echo '<div class="notice notice-info is-dismissible" data-kw-legal-notice="'.esc_attr($id).'">';
    if (! empty($notice['updated'])) {
        echo '<p><strong>'.esc_html__('Rechtstext-Vorlagen in Entwürfe übernommen:', 'kurswasser').'</strong> '
            .esc_html(implode(', ', (array) $notice['updated'])).'. '
            .esc_html__('Bitte die geänderten Seiten prüfen.', 'kurswasser').'</p>';
    }
    if (! empty($notice['pending'])) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=kurswasser_legal_fill'), 'kurswasser_legal_fill');
        echo '<p><strong>'.esc_html__('Neue Rechtstext-Vorlagen für veröffentlichte Seiten:', 'kurswasser').'</strong> '
            .esc_html(implode(', ', (array) $notice['pending'])).'. '
            .esc_html__('Die Seiten sind live und wurden deshalb nicht automatisch geändert. Bitte die Vorlagen prüfen und dann übernehmen (nur unbearbeitete Seiten werden ersetzt).', 'kurswasser').'</p>'
            .'<p><a class="button button-primary" href="'.esc_url($url).'">'.esc_html__('Vorlagen übernehmen', 'kurswasser').'</a></p>';
    }
    if (! empty($notice['edited'])) {
        echo '<p><strong>'.esc_html__('Neue Rechtstext-Vorlagen nicht übernommen', 'kurswasser').'</strong> '
            .esc_html__('(von dir bearbeitete Seiten bleiben unverändert – bitte mit den Vorlagen im Theme-Ordner legal/ abgleichen):', 'kurswasser').' '
            .esc_html(implode(', ', (array) $notice['edited'])).'</p>';
    }
    echo '</div>';
    $nonce = wp_create_nonce('kurswasser_legal_notice');
    // Wegklicken: common.js entfernt den Hinweis; hier nur die Entscheidung je Admin speichern.
    echo '<script>document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-kw-legal-notice] .notice-dismiss");if(!b){return;}'
        .'var d=new FormData();d.append("action","kurswasser_legal_notice_dismiss");d.append("id",b.closest("[data-kw-legal-notice]").getAttribute("data-kw-legal-notice"));d.append("_ajax_nonce",'.wp_json_encode($nonce).');'
        .'fetch('.wp_json_encode(admin_url('admin-ajax.php')).',{method:"POST",body:d,credentials:"same-origin"});});</script>';
});

add_action('wp_ajax_kurswasser_legal_notice_dismiss', static function (): void {
    check_ajax_referer('kurswasser_legal_notice');
    if (! current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }
    $id = sanitize_key(wp_unslash((string) ($_POST['id'] ?? '')));
    if ($id === '') {
        wp_send_json_error(null, 400);
    }
    update_user_meta(get_current_user_id(), 'kurswasser_legal_notice_dismissed', $id);
    wp_send_json_success();
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
    if (kurswasser_legal_lock(true)) {
        try {
            $result = kurswasser_fill_legal_pages();
            // Erledigt: Der Hinweis auf ausstehende Vorlagen verschwindet (bei einem Fehler bleibt er stehen).
            if (! in_array('error', $result, true)) {
                delete_option('kurswasser_legal_update_notice');
            }
        } finally {
            kurswasser_legal_lock(false);
        }
    }
    kurswasser_assign_legal_pages();
    wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=page'));
    exit;
});

/**
 * Platzhalter für Redakteure im Frontend hervorheben (nur Textknoten, keine Attribute). Inhalte von
 * <script>, <style>, <textarea> und <option> bleiben samt Tags unverändert (ein <mark> dort wäre Code bzw.
 * Rohtext). Als Tag gilt nur „<“ gefolgt von Buchstabe, „/“ oder „!“ – ein loses „<“ im Text ist Text.
 * Bei einem Regex-Fehler (z. B. Backtrack-Limit) bleibt das Markup unverändert.
 */
function kurswasser_mark_legal_placeholders(string $html): string
{
    if (! str_contains($html, '[[Betreiber:')) {
        return $html;
    }
    // Gruppe 1: Tags bzw. ganze script/style/textarea/option-Elemente (unverändert), Gruppe 3: Text.
    $marked = preg_replace_callback(
        '/(<!--.*?-->|<(script|style|textarea|option)\b[^>]*>.*?<\/\2\s*>|<[a-zA-Z\/!][^>]*>)|([^<]+|<)/is',
        static function (array $m): string {
            if (! isset($m[3]) || $m[3] === '' || ! str_contains($m[3], '[[Betreiber:')) {
                return $m[0];
            }
            $text = preg_replace('/\[\[Betreiber:[^\[\]]*\]\]/u', '<mark class="kw-legal-placeholder">$0</mark>', $m[3]);

            return is_string($text) ? $text : $m[0];
        },
        $html
    );

    return is_string($marked) ? $marked : $html;
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
    // Listenpunkt des Knopfs mit verstecken (consent.js blendet ihn samt Knopf ein) – ohne JavaScript kein
    // leerer Punkt. Die Template-Part-Ausgabe hat den Shortcode meist schon ersetzt, daher beide Formen.
    $html = (string) preg_replace('/<li>(\s*(?:\[kurswasser_consent_settings\b|<button\b[^>]*\bdata-kw-consent-open\b))/', '<li hidden data-kw-consent-wrap>$1', $html);
    if (str_contains($html, '[kurswasser_consent_settings')) {
        $html = do_shortcode($html);
    }

    return (string) preg_replace('/<li\b[^>]*>\s*<\/li>/', '', $html);
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
