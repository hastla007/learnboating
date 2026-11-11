<?php
/**
 * Einrichtungs-Assistent „Kurswasser einrichten“ (Design › Kurswasser einrichten).
 *
 * Nach dem Aktivieren des Themes leitet WordPress den Admin einmal hierher (Transient bei after_switch_theme,
 * Umleitung bei admin_init; nicht bei WP-CLI, AJAX, Cron, Netzwerk-Admin oder Mehrfach-Aktivierung).
 *
 * Schritt 1 – Plugins: WooCommerce und Yoast SEO von wordpress.org (plugins_api + Plugin_Upgrader), das
 * Lern-Plugin „Bootsprüfung Lernplattform“ aus einer ZIP-Datei (Konstante KURSWASSER_LEARNING_PLUGIN_ZIP bzw.
 * Filter kurswasser_learning_plugin_zip: URL oder lokaler Pfad; sonst plugins/bootspruefung-learning.zip im
 * Theme, falls vorhanden; sonst Knopf „Plugin hochladen“). Installieren und Aktivieren in fester Reihenfolge
 * (WooCommerce vor dem Lern-Plugin). Ohne Dateirechte (DISALLOW_FILE_MODS, fehlende FTP-Zugangsdaten) nur
 * Hinweis mit Link zur Plugin-Suche.
 *
 * Schritt 2 – Inhaltsseiten (inc/content-pages.php): anlegen mit SEO-Daten im Theme (Option A) bzw. – nur
 * mit aktivem Yoast – in Yoast (Option B, inc/content-yoast.php), oder „Vorerst nicht anlegen“. Die Wahl steht
 * in der Option kurswasser_content_seo_target und gilt für spätere Abgleiche (Admin-Hinweis, WP-CLI).
 *
 * Schritt 3 – Shop einrichten (inc/setup-shop.php, nur mit aktivem WooCommerce): Anschrift, Währung, Steuer,
 * Verkauf ohne Versand, Registrierung, Sprache, Produkte als Entwurf – mit Vorschau „aktuell → neu“ und Bestätigung.
 *
 * Außerdem: „Alles aus dem Theme übernehmen (überschreibt deine Änderungen)“ – nur mit Bestätigungsschritt
 * und Liste der betroffenen Seiten (force).
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const KURSWASSER_SETUP_PAGE = 'kurswasser-setup';

function kurswasser_setup_url(array $args = []): string
{
    return add_query_arg(array_merge(['page' => KURSWASSER_SETUP_PAGE], $args), admin_url('themes.php'));
}

/*
 * Einmalige Umleitung nach dem Aktivieren.
 */
add_action('after_switch_theme', 'kurswasser_setup_on_switch');

function kurswasser_setup_on_switch(): void
{
    // Nur wenn ein Admin das Theme im Backend aktiviert (Themes, Customizer) – nicht per WP-CLI oder Skript.
    if ((defined('WP_CLI') && WP_CLI) || ! is_admin() || ! current_user_can('switch_themes') || ! apply_filters('kurswasser_setup_redirect', true)) {
        return;
    }
    set_transient('kurswasser_setup_redirect', get_current_user_id(), 10 * MINUTE_IN_SECONDS);
}

/** Ziel der Umleitung nach dem Aktivieren oder null (verbraucht den Transient nur bei einer echten Seite). */
function kurswasser_setup_redirect_url(): ?string
{
    $uid = (int) get_transient('kurswasser_setup_redirect');
    if ($uid === 0 || $uid !== get_current_user_id()) {
        return null;
    }
    if (wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST) || is_network_admin()
        || isset($_GET['activate-multi']) || ! current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }
    delete_transient('kurswasser_setup_redirect');
    if (sanitize_key((string) ($_GET['page'] ?? '')) === KURSWASSER_SETUP_PAGE) {
        return null;
    }

    return kurswasser_setup_url();
}

add_action('admin_init', static function (): void {
    if (($url = kurswasser_setup_redirect_url()) !== null) {
        wp_safe_redirect($url);
        exit;
    }
});

add_action('admin_menu', static function (): void {
    add_theme_page(__('Kurswasser einrichten', 'kurswasser'), __('Kurswasser einrichten', 'kurswasser'), 'manage_options', KURSWASSER_SETUP_PAGE, 'kurswasser_setup_page');
});

/*
 * Plugins.
 */

/**
 * Plugins des Assistenten in Installationsreihenfolge (WooCommerce vor dem Lern-Plugin).
 *
 * @return array<string,array{name:string,dir:string,file:string,source:string,description:string,checked:bool}>
 */
function kurswasser_setup_plugins(): array
{
    return (array) apply_filters('kurswasser_setup_plugins', [
        'woocommerce' => [
            'name' => 'WooCommerce', 'dir' => 'woocommerce', 'file' => 'woocommerce/woocommerce.php', 'source' => 'wporg', 'checked' => true,
            'description' => __('Shop, Warenkorb und Kasse. Das Lern-Plugin schaltet Kurse nach dem Kauf frei.', 'kurswasser'),
        ],
        'wordpress-seo' => [
            'name' => 'Yoast SEO', 'dir' => 'wordpress-seo', 'file' => 'wordpress-seo/wp-seo.php', 'source' => 'wporg', 'checked' => false,
            'description' => __('Optional. Mit Yoast kannst du in Schritt 2 wählen, dass die SEO-Daten der Inhaltsseiten in Yoast stehen.', 'kurswasser'),
        ],
        'bootspruefung-learning' => [
            'name' => 'Bootsprüfung Lernplattform', 'dir' => 'bootspruefung-learning', 'file' => 'bootspruefung-learning/bootspruefung-learning.php', 'source' => 'zip', 'checked' => true,
            'description' => __('Unser Lern-Plugin: Fragen, Prüfungssimulation, Trainer, FAQ und Freischaltung. Nicht auf wordpress.org.', 'kurswasser'),
        ],
    ]);
}

/**
 * Installationsquelle des Lern-Plugins: Konstante bzw. Filter (URL oder Pfad), sonst die ZIP im Theme, sonst ''.
 */
function kurswasser_learning_plugin_source(): string
{
    $source = defined('KURSWASSER_LEARNING_PLUGIN_ZIP') ? (string) KURSWASSER_LEARNING_PLUGIN_ZIP : '';
    if ($source === '') {
        $bundled = get_template_directory().'/plugins/bootspruefung-learning.zip';
        $source = is_file($bundled) ? $bundled : '';
    }
    $source = trim((string) apply_filters('kurswasser_learning_plugin_zip', $source));
    if ($source === '' || preg_match('#^https?://#i', $source)) {
        return $source;
    }

    return is_file($source) && is_readable($source) && str_ends_with(strtolower($source), '.zip') ? $source : '';
}

/** Installierte Plugins (Datei => Kopfdaten). */
function kurswasser_setup_installed_plugins(): array
{
    require_once ABSPATH.'wp-admin/includes/plugin.php';

    return (array) apply_filters('kurswasser_setup_installed_plugins', get_plugins());
}

/** Plugin-Datei eines Eintrags, wenn installiert (erwarteter Pfad, sonst gleicher Ordner bzw. Name), sonst ''. */
function kurswasser_setup_plugin_file(array $def): string
{
    $installed = kurswasser_setup_installed_plugins();
    if (isset($installed[$def['file']])) {
        return $def['file'];
    }
    foreach ($installed as $file => $data) {
        if (dirname((string) $file) === $def['dir'] || ($data['Name'] ?? '') === $def['name']) {
            return (string) $file;
        }
    }

    return '';
}

/**
 * Warum hier nicht installiert werden kann ('' = es geht). Aktivieren bereits installierter Plugins geht trotzdem.
 */
function kurswasser_setup_install_problem(): string
{
    if (! wp_is_file_mod_allowed('kurswasser_setup')) {
        return __('Auf dieser Website dürfen keine Plugins installiert werden (DISALLOW_FILE_MODS). Bitte die Plugins über dein Hosting bzw. dein Deployment einspielen.', 'kurswasser');
    }
    if (! current_user_can('install_plugins')) {
        return __('Dein Benutzerkonto darf keine Plugins installieren.', 'kurswasser');
    }
    require_once ABSPATH.'wp-admin/includes/file.php';
    if (get_filesystem_method() !== 'direct') {
        ob_start();
        $creds = request_filesystem_credentials(kurswasser_setup_url(), '', false, false, null);
        ob_end_clean();
        if ($creds === false || ! WP_Filesystem($creds)) {
            return __('WordPress kann nicht direkt in den Plugin-Ordner schreiben, und es sind keine FTP-Zugangsdaten hinterlegt. Installiere die Plugins über Plugins › Installieren (dort fragt WordPress nach den Zugangsdaten).', 'kurswasser');
        }
    }

    return '';
}

/**
 * Stand je Plugin für Anzeige und Installation.
 *
 * @return array<string,array<string,mixed>> mit status missing|installed|active, installed_file, package ('' = keine Quelle)
 */
function kurswasser_setup_plugin_rows(): array
{
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    $rows = [];
    foreach (kurswasser_setup_plugins() as $key => $def) {
        $file = kurswasser_setup_plugin_file($def);
        $status = $file === '' ? 'missing' : (is_plugin_active($file) ? 'active' : 'installed');
        $package = $def['source'] === 'zip' ? ($key === 'bootspruefung-learning' ? kurswasser_learning_plugin_source() : (string) ($def['package'] ?? '')) : 'wporg';
        $rows[$key] = $def + ['key' => $key, 'installed_file' => $file, 'status' => $status, 'package' => $package];
    }

    return $rows;
}

/** Download-Link eines Plugins von wordpress.org oder WP_Error. */
function kurswasser_setup_wporg_package(string $slug)
{
    require_once ABSPATH.'wp-admin/includes/plugin-install.php';
    $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false, 'short_description' => false, 'icons' => false, 'banners' => false, 'reviews' => false]]);
    if (is_wp_error($api)) {
        return new WP_Error('kw_wporg', sprintf(__('wordpress.org ist nicht erreichbar oder kennt das Plugin nicht (%s).', 'kurswasser'), $api->get_error_message()));
    }
    $link = is_object($api) ? (string) ($api->download_link ?? '') : '';

    return $link !== '' ? $link : new WP_Error('kw_wporg', __('wordpress.org lieferte keinen Download-Link.', 'kurswasser'));
}

/** Plugin aus einem Paket (URL oder lokale ZIP) installieren; gibt die Plugin-Datei oder WP_Error zurück. */
function kurswasser_setup_install_package(string $package)
{
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/misc.php';
    require_once ABSPATH.'wp-admin/includes/plugin.php';
    require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
    if (! class_exists('WP_Ajax_Upgrader_Skin')) {
        require_once ABSPATH.'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
    }
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->install($package);
    if (is_wp_error($result)) {
        return $result;
    }
    if (is_wp_error($skin->result)) {
        return $skin->result;
    }
    if ($skin->get_errors()->has_errors()) {
        return new WP_Error('kw_install', implode(' ', (array) $skin->get_error_messages()));
    }
    if ($result !== true) {
        return new WP_Error('kw_fs', __('Keine Schreibrechte bzw. Zugangsdaten für den Plugin-Ordner.', 'kurswasser'));
    }
    $file = (string) $upgrader->plugin_info();

    return $file !== '' ? $file : new WP_Error('kw_noplugin', __('In der ZIP-Datei wurde kein Plugin gefunden.', 'kurswasser'));
}

/**
 * Ausgewählte Plugins installieren (falls nötig) und aktivieren – immer in der Reihenfolge von
 * kurswasser_setup_plugins(). Gibt Meldungen zurück: list<array{0:type,1:text}>.
 */
function kurswasser_setup_install_plugins(array $keys): array
{
    $messages = [];
    $keys = array_map('sanitize_key', $keys);
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- auf manchen Hostings gesperrt
    }
    $problem = null;
    foreach (kurswasser_setup_plugin_rows() as $key => $row) {
        if (! in_array($key, $keys, true) || $row['status'] === 'active') {
            continue;
        }
        $name = (string) $row['name'];
        $file = (string) $row['installed_file'];
        if ($file === '') {
            $problem ??= kurswasser_setup_install_problem();
            if ($problem !== '') {
                $messages[] = ['error', sprintf(__('%1$s nicht installiert: %2$s', 'kurswasser'), $name, $problem)];
                continue;
            }
            $package = $row['package'] === 'wporg' ? kurswasser_setup_wporg_package((string) $row['dir']) : (string) $row['package'];
            if ($package === '') {
                $messages[] = ['error', sprintf(__('%s nicht installiert: Es ist keine Installationsquelle hinterlegt. Lade die ZIP-Datei über „Plugin hochladen“ hoch.', 'kurswasser'), $name)];
                continue;
            }
            $file = is_wp_error($package) ? $package : kurswasser_setup_install_package($package);
            if (is_wp_error($file)) {
                $messages[] = ['error', sprintf(__('%1$s konnte nicht installiert werden: %2$s', 'kurswasser'), $name, $file->get_error_message())];
                continue;
            }
            if (dirname($file) !== $row['dir']) {
                $messages[] = ['warning', sprintf(__('%1$s wurde im Ordner „%2$s“ installiert (erwartet: „%3$s“).', 'kurswasser'), $name, dirname($file), $row['dir'])];
            }
            $messages[] = ['success', sprintf(__('%s installiert.', 'kurswasser'), $name)];
        }
        if (! current_user_can('activate_plugins')) {
            $messages[] = ['error', sprintf(__('%s nicht aktiviert: Dein Benutzerkonto darf keine Plugins aktivieren.', 'kurswasser'), $name)];
            continue;
        }
        $activated = activate_plugin($file);
        if (is_wp_error($activated) && $activated->get_error_code() !== 'unexpected_output') {
            $messages[] = ['error', sprintf(__('%1$s konnte nicht aktiviert werden: %2$s', 'kurswasser'), $name, $activated->get_error_message())];
            continue;
        }
        kurswasser_setup_after_activation($key);
        $messages[] = ['success', sprintf(__('%s aktiviert.', 'kurswasser'), $name)];
    }
    if ($messages === []) {
        $messages[] = ['info', __('Keine Plugins ausgewählt bzw. alle ausgewählten sind schon aktiv.', 'kurswasser')];
    }

    return $messages;
}

/** Eigene Weiterleitungen der Plugins nach dem Aktivieren abschalten, damit der Assistent weiterläuft. */
function kurswasser_setup_after_activation(string $key): void
{
    if ($key === 'woocommerce') {
        delete_transient('_wc_activation_redirect');
    }
    if ($key === 'wordpress-seo' && class_exists('WPSEO_Options') && method_exists('WPSEO_Options', 'set')) {
        try {
            WPSEO_Options::set('should_redirect_after_install_free', false);
        } catch (\Throwable $e) {
            // Andere Yoast-Version: dann zeigt Yoast einmal seine Willkommensseite.
        }
    }
}

/*
 * Inhaltsseiten-Wahl und „Alles übernehmen“.
 */

/** Wahl aus Schritt 2 speichern und ausführen; gibt Meldungen zurück. */
function kurswasser_setup_apply_content_choice(string $choice): array
{
    if ($choice === 'later') {
        return [['info', __('Es wurden keine Inhaltsseiten angelegt. Du kannst das hier oder über den Hinweis im Dashboard jederzeit nachholen.', 'kurswasser')]];
    }
    if (! in_array($choice, ['theme', 'yoast'], true)) {
        return [['error', __('Bitte eine Möglichkeit auswählen.', 'kurswasser')]];
    }
    if ($choice === 'yoast' && ! kurswasser_yoast_active()) {
        return [['error', __('Yoast SEO ist nicht aktiv – die SEO-Daten können nicht in Yoast geschrieben werden.', 'kurswasser')]];
    }
    update_option('kurswasser_content_seo_target', $choice, false);
    $groups = kurswasser_content_run_sync(['seo' => $choice]);
    if ($groups === null) {
        return [['warning', __('Gerade läuft schon ein Abgleich der Inhaltsseiten. Bitte in einer Minute erneut versuchen.', 'kurswasser')]];
    }

    return [['success', $choice === 'yoast'
        ? __('Inhaltsseiten abgeglichen. SEO-Daten stehen in Yoast (und weiter in den Theme-Feldern für einen späteren Wechsel).', 'kurswasser')
        : __('Inhaltsseiten abgeglichen. Die SEO-Daten gibt das Theme aus.', 'kurswasser')]];
}

/** „Alles aus dem Theme übernehmen“ ausführen (nur mit Bestätigung); gibt Meldungen zurück. */
function kurswasser_setup_force_sync(bool $confirmed): array
{
    if (! $confirmed) {
        return [['error', __('Nichts geändert: Bitte bestätige, dass deine Änderungen überschrieben werden dürfen.', 'kurswasser')]];
    }
    $groups = kurswasser_content_run_sync(['force' => true]);
    if ($groups === null) {
        return [['warning', __('Gerade läuft schon ein Abgleich der Inhaltsseiten. Bitte in einer Minute erneut versuchen.', 'kurswasser')]];
    }

    return [['success', __('Alle Inhaltsseiten wurden aus dem Theme übernommen.', 'kurswasser')]];
}

function kurswasser_setup_flash(array $messages): void
{
    set_transient('kurswasser_setup_flash_'.get_current_user_id(), $messages, 5 * MINUTE_IN_SECONDS);
}

add_action('admin_post_kurswasser_setup_plugins', static function (): void {
    if (! current_user_can('install_plugins') && ! current_user_can('activate_plugins')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_setup_plugins');
    $keys = isset($_POST['kw_plugins']) && is_array($_POST['kw_plugins']) ? array_map('sanitize_key', wp_unslash($_POST['kw_plugins'])) : [];
    kurswasser_setup_flash(kurswasser_setup_install_plugins($keys));
    wp_safe_redirect(kurswasser_setup_url());
    exit;
});

add_action('admin_post_kurswasser_setup_content', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_setup_content');
    kurswasser_setup_flash(kurswasser_setup_apply_content_choice(sanitize_key(wp_unslash((string) ($_POST['kw_content'] ?? '')))));
    wp_safe_redirect(kurswasser_setup_url().'#kw-setup-content');
    exit;
});

add_action('admin_post_kurswasser_setup_force', static function (): void {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_setup_force');
    $confirmed = ! empty($_POST['kw_confirm']);
    kurswasser_setup_flash(kurswasser_setup_force_sync($confirmed));
    wp_safe_redirect($confirmed ? kurswasser_setup_url() : kurswasser_setup_url(['kw_view' => 'force']));
    exit;
});

/*
 * Seite.
 */

function kurswasser_setup_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    echo '<div class="wrap kw-setup"><h1>'.esc_html__('Kurswasser einrichten', 'kurswasser').'</h1>';
    echo '<style>.kw-setup .card{max-width:900px;padding:.6em 1.6em 1.2em}.kw-setup .kw-status{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:#f0f0f1}'
        .'.kw-setup .kw-status.is-active{background:#edfaef;color:#00630f}.kw-setup .kw-status.is-installed{background:#fcf9e8;color:#6e4b00}'
        .'.kw-setup .kw-choice{display:block;margin:.8em 0;padding:.6em .8em;border:1px solid #dcdcde;border-radius:4px}.kw-setup .kw-choice p{margin:.3em 0 0 1.8em;color:#50575e}'
        .'.kw-setup .kw-danger{border-left:4px solid #d63638}.kw-setup .button.kw-button-danger{color:#b32d2e;border-color:#b32d2e}</style>';
    $uid = get_current_user_id();
    $flash = get_transient('kurswasser_setup_flash_'.$uid);
    if (is_array($flash)) {
        delete_transient('kurswasser_setup_flash_'.$uid);
        foreach ($flash as [$type, $text]) {
            echo '<div class="notice notice-'.esc_attr(in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info').' is-dismissible"><p>'.esc_html((string) $text).'</p></div>';
        }
    }
    $view = sanitize_key((string) ($_GET['kw_view'] ?? ''));
    if ($view === 'force') {
        kurswasser_setup_force_view();
        echo '</div>';

        return;
    }
    if (in_array($view, ['shop', 'shop-result'], true) && function_exists('kurswasser_setup_shop_view')) {
        kurswasser_setup_shop_view($view);
        echo '</div>';

        return;
    }
    $shop = function_exists('kurswasser_shop_available') && kurswasser_shop_available();
    echo '<p>'.esc_html($shop
        ? __('Drei Schritte: die passenden Plugins installieren, die Info- und Marketingseiten des Themes anlegen und den Shop einrichten. Du kannst diese Seite jederzeit wieder unter Design › Kurswasser einrichten öffnen.', 'kurswasser')
        : __('Zwei Schritte: die passenden Plugins installieren und die Info- und Marketingseiten des Themes anlegen. Du kannst diese Seite jederzeit wieder unter Design › Kurswasser einrichten öffnen. Schritt 3 „Shop einrichten“ erscheint, sobald WooCommerce aktiv ist.', 'kurswasser')).'</p>';
    kurswasser_setup_plugins_card();
    kurswasser_setup_content_card();
    if ($shop) {
        kurswasser_setup_shop_card();
    }
    echo '</div>';
}

function kurswasser_setup_status_label(string $status): string
{
    return match ($status) {
        'active' => '<span class="kw-status is-active">'.esc_html__('aktiv', 'kurswasser').'</span>',
        'installed' => '<span class="kw-status is-installed">'.esc_html__('installiert, nicht aktiv', 'kurswasser').'</span>',
        default => '<span class="kw-status">'.esc_html__('nicht installiert', 'kurswasser').'</span>',
    };
}

function kurswasser_setup_plugins_card(): void
{
    $rows = kurswasser_setup_plugin_rows();
    $problem = array_filter($rows, static fn ($r) => $r['status'] === 'missing') ? kurswasser_setup_install_problem() : '';
    $canActivate = current_user_can('activate_plugins');
    echo '<div class="card" id="kw-setup-plugins"><h2>'.esc_html__('1. Plugins', 'kurswasser').'</h2>';
    if ($problem !== '') {
        echo '<div class="notice notice-warning inline"><p>'.esc_html($problem).'</p></div>';
    }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kurswasser_setup_plugins">';
    wp_nonce_field('kurswasser_setup_plugins');
    echo '<table class="widefat striped"><thead><tr><th style="width:2.2em"></th><th>'.esc_html__('Plugin', 'kurswasser').'</th><th>'.esc_html__('Status', 'kurswasser').'</th></tr></thead><tbody>';
    $any = false;
    foreach ($rows as $key => $row) {
        $box = '';
        $extra = '';
        if ($row['status'] === 'installed' && $canActivate) {
            $box = '<input type="checkbox" name="kw_plugins[]" value="'.esc_attr($key).'" id="kw-plugin-'.esc_attr($key).'"'.checked((bool) $row['checked'], true, false).'>';
            $extra = esc_html__('Häkchen = aktivieren', 'kurswasser');
        } elseif ($row['status'] === 'missing' && $problem === '' && $row['package'] !== '' && $canActivate) {
            $box = '<input type="checkbox" name="kw_plugins[]" value="'.esc_attr($key).'" id="kw-plugin-'.esc_attr($key).'"'.checked((bool) $row['checked'], true, false).'>';
            $extra = $row['package'] === 'wporg'
                ? esc_html__('Häkchen = von wordpress.org installieren und aktivieren', 'kurswasser')
                : esc_html(sprintf(__('Häkchen = installieren und aktivieren aus %s', 'kurswasser'), preg_match('#^https?://#i', (string) $row['package']) ? (string) wp_parse_url((string) $row['package'], PHP_URL_HOST) : basename((string) $row['package'])));
        } elseif ($row['status'] === 'missing' && $row['source'] === 'wporg') {
            $extra = wp_is_file_mod_allowed('kurswasser_setup')
                ? '<a href="'.esc_url(admin_url('plugin-install.php?tab=search&type=term&s='.rawurlencode((string) $row['name']))).'">'.esc_html__('In der Plugin-Suche öffnen', 'kurswasser').'</a>'
                : '<a href="'.esc_url('https://wordpress.org/plugins/'.$row['dir'].'/').'" target="_blank" rel="noopener">'.esc_html__('Auf wordpress.org ansehen', 'kurswasser').'</a>';
        } elseif ($row['status'] === 'missing') {
            $extra = esc_html__('Dieses Plugin liegt nicht auf wordpress.org. Lade die ZIP-Datei (bootspruefung-learning.zip) über „Plugin hochladen“ hoch – oder hinterlege die Quelle in wp-config.php: define( \'KURSWASSER_LEARNING_PLUGIN_ZIP\', \'https://…/bootspruefung-learning.zip\' ); (URL oder Pfad).', 'kurswasser');
            if (wp_is_file_mod_allowed('kurswasser_setup') && current_user_can('upload_plugins')) {
                $extra .= ' <a class="button" href="'.esc_url(admin_url('plugin-install.php?tab=upload')).'">'.esc_html__('Plugin hochladen', 'kurswasser').'</a>';
            }
        }
        $any = $any || $box !== '';
        echo '<tr><td>'.$box.'</td><td><label for="kw-plugin-'.esc_attr($key).'"><strong>'.esc_html((string) $row['name']).'</strong></label><br><span class="description">'.esc_html((string) $row['description']).'</span>'
            .($extra !== '' ? '<br><small>'.$extra.'</small>' : '').'</td><td>'.kurswasser_setup_status_label((string) $row['status']).'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
    }
    echo '</tbody></table>';
    if ($any) {
        echo '<p><button type="submit" class="button button-primary">'.esc_html__('Ausgewählte installieren und aktivieren', 'kurswasser').'</button> <span class="description">'
            .esc_html__('Reihenfolge: WooCommerce, Yoast SEO, Lern-Plugin. Das kann eine Minute dauern.', 'kurswasser').'</span></p>';
    } elseif (! array_filter($rows, static fn ($r) => $r['status'] !== 'active')) {
        echo '<p>'.esc_html__('Alle Plugins sind aktiv.', 'kurswasser').'</p>';
    }
    echo '</form></div>';
}

function kurswasser_setup_content_card(): void
{
    echo '<div class="card" id="kw-setup-content"><h2>'.esc_html__('2. Inhaltsseiten', 'kurswasser').'</h2>';
    $manifest = kurswasser_content_manifest();
    if ($manifest === []) {
        echo '<p>'.esc_html__('Im Theme liegen keine Inhaltsseiten (content/pages.php fehlt).', 'kurswasser').'</p></div>';

        return;
    }
    $yoast = kurswasser_yoast_active();
    $choice = kurswasser_content_seo_choice();
    $groups = kurswasser_content_group(kurswasser_content_pages_sync(['dry_run' => true]));
    $labels = kurswasser_content_state_labels();
    echo '<p>'.esc_html(sprintf(__('Das Theme bringt %d Info- und Marketingseiten mit (Ratgeber, Angebote, Über uns, FAQ …). Seiten mit offenen Angaben des Betreibers entstehen als Entwurf.', 'kurswasser'), count($manifest))).'</p>';
    $summary = array_intersect_key($groups, array_flip(['new', 'outdated', 'unchanged', 'customized', 'edited', 'foreign', 'blocked', 'trashed', 'yoast-pending', 'yoast-kept']));
    if ($summary) {
        echo '<ul style="list-style:disc;margin-left:1.5em">';
        foreach ($summary as $state => $titles) {
            echo '<li>'.esc_html(($labels[$state] ?? $state).': '.count($titles)).'</li>';
        }
        echo '</ul>';
    }
    if ($choice === 'yoast' && ! $yoast) {
        echo '<div class="notice notice-warning inline"><p>'.esc_html__('Du hattest „SEO-Daten in Yoast“ gewählt, Yoast SEO ist aber nicht aktiv. Bis dahin gibt das Theme die SEO-Daten aus.', 'kurswasser').'</p></div>';
    }
    $selected = $choice !== '' && ($choice !== 'yoast' || $yoast) ? $choice : ($yoast ? 'yoast' : 'theme');
    $options = [];
    if ($yoast) {
        $options['yoast'] = [__('Inhaltsseiten anlegen, SEO-Daten in Yoast (empfohlen)', 'kurswasser'),
            __('Die Seiten werden angelegt. Titel, Beschreibung, noindex und Canonical stehen direkt in den Yoast-Feldern jeder Seite – im Yoast-Kasten, in der Snippet-Vorschau und in der Yoast-Sitemap. Bei Artikeln wird der Yoast-Artikeltyp „Article“ gesetzt. Ein Fokus-Keyword nur, wenn die Vorlage eines vorgibt. Yoast-Felder, die du schon ausgefüllt hast, werden nicht überschrieben.', 'kurswasser')];
        $options['theme'] = [__('Inhaltsseiten anlegen, SEO-Daten im Theme', 'kurswasser'),
            __('Wie ohne Yoast: Die SEO-Daten stehen in der Box „SEO (Kurswasser)“. Yoast nutzt sie nur, wo sein eigenes Feld leer ist; im Yoast-Kasten erscheinen sie nicht.', 'kurswasser')];
    } else {
        $options['theme'] = [__('Inhaltsseiten anlegen (SEO-Daten gibt das Theme aus)', 'kurswasser'),
            __('Titel, Beschreibung, noindex, Canonical und strukturierte Daten gibt das Theme selbst aus (Box „SEO (Kurswasser)“ an jeder Seite).', 'kurswasser')];
    }
    $options['later'] = [__('Vorerst nicht anlegen', 'kurswasser'),
        __('Es passiert nichts. Der Hinweis im Dashboard und diese Seite bleiben – du kannst später wählen.', 'kurswasser')];
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kurswasser_setup_content">';
    wp_nonce_field('kurswasser_setup_content');
    foreach ($options as $value => [$label, $help]) {
        echo '<label class="kw-choice"><input type="radio" name="kw_content" value="'.esc_attr($value).'"'.checked($selected, $value, false).'> <strong>'.esc_html($label).'</strong><p>'.esc_html($help).'</p></label>';
    }
    echo '<p class="description">'.esc_html__('Später gilt: Ein neuer Vorlagenstand aus dem Theme ersetzt nur, was du in WordPress nicht verändert hast – Seiteninhalt und SEO-Felder. Von dir geänderte Seiten und Felder bleiben.', 'kurswasser').'</p>';
    echo '<p><button type="submit" class="button button-primary">'.esc_html__('Auswahl übernehmen', 'kurswasser').'</button></p></form>';
    if (array_intersect_key($groups, array_flip(['unchanged', 'customized', 'edited', 'foreign', 'outdated']))) {
        echo '<div class="kw-danger" style="padding:.2em 1em;margin-top:1.2em;background:#fcf0f1"><h3>'.esc_html__('Alles aus dem Theme übernehmen (überschreibt deine Änderungen)', 'kurswasser').'</h3><p>'
            .esc_html__('Setzt alle Inhaltsseiten und ihre SEO-Felder (auch die Yoast-Felder, wenn du Yoast gewählt hast) auf den Stand der Vorlagen zurück – auch Seiten und Felder, die du geändert hast. Vorher siehst du, welche Seiten betroffen sind.', 'kurswasser')
            .'</p><p><a class="button kw-button-danger" href="'.esc_url(kurswasser_setup_url(['kw_view' => 'force'])).'">'.esc_html__('Betroffene Seiten anzeigen …', 'kurswasser').'</a></p></div>';
    }
    echo '</div>';
}

function kurswasser_setup_force_view(): void
{
    echo '<p><a href="'.esc_url(kurswasser_setup_url()).'">&larr; '.esc_html__('Zurück zur Einrichtung', 'kurswasser').'</a></p>';
    echo '<div class="card kw-danger"><h2>'.esc_html__('Alles aus dem Theme übernehmen (überschreibt deine Änderungen)', 'kurswasser').'</h2>';
    echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('Achtung:', 'kurswasser').'</strong> '
        .esc_html__('Alle Inhaltsseiten aus dem Theme werden auf den Vorlagenstand gesetzt. Was du an den unten aufgeführten Seiten geändert hast – Text, Titel, SEO-Felder und Yoast-Felder –, geht verloren. Ältere Fassungen des Seitentexts findest du danach in den Revisionen der Seite; SEO- und Yoast-Felder haben keine Revisionen.', 'kurswasser').'</p></div>';
    $seo = kurswasser_content_seo_target();
    $preview = kurswasser_content_force_preview(null, $seo);
    echo '<p>'.esc_html($seo === 'yoast' ? __('SEO-Daten: Theme-Felder und Yoast.', 'kurswasser') : __('SEO-Daten: Theme-Felder (Yoast-Felder bleiben unberührt).', 'kurswasser')).'</p>';
    if ($preview === []) {
        echo '<p>'.esc_html__('Keine von dir geänderten Seiten oder Felder gefunden – es würden nur unveränderte Seiten auf den neuesten Vorlagenstand gebracht.', 'kurswasser').'</p>';
    } else {
        echo '<h3>'.esc_html(sprintf(_n('Betroffen: %d Seite', 'Betroffen: %d Seiten', count($preview), 'kurswasser'), count($preview))).'</h3><ul style="list-style:disc;margin-left:1.5em">';
        foreach ($preview as $row) {
            $link = $row['id'] ? get_edit_post_link($row['id']) : '';
            $title = $link ? '<a href="'.esc_url($link).'">'.esc_html($row['title']).'</a>' : esc_html($row['title']);
            echo '<li>'.esc_html(sprintf('%02d', $row['nr'])).' '.$title.': '.esc_html(implode('; ', $row['reasons'])).'</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
        }
        echo '</ul>';
    }
    echo '<p class="description">'.esc_html__('Nicht betroffen: Rechtstexte (Impressum, Kontakt usw.), Seiten im Papierkorb und veröffentlichte Seiten, deren neue Vorlage noch Platzhalter enthält.', 'kurswasser').'</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kurswasser_setup_force">';
    wp_nonce_field('kurswasser_setup_force');
    echo '<p><label><input type="checkbox" name="kw_confirm" value="1" required> '.esc_html__('Ich habe verstanden: Meine Änderungen an diesen Seiten werden überschrieben.', 'kurswasser').'</label></p>';
    echo '<p><button type="submit" class="button kw-button-danger">'.esc_html__('Alles aus dem Theme übernehmen', 'kurswasser').'</button> <a class="button" href="'.esc_url(kurswasser_setup_url()).'">'.esc_html__('Abbrechen', 'kurswasser').'</a></p></form></div>';
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Plugins des Einrichtungs-Assistenten anzeigen bzw. installieren und aktivieren.
     *
     * ## OPTIONS
     *
     * [--install=<liste>]
     * : Kommagetrennt: woocommerce, wordpress-seo, bootspruefung-learning (oder „all“). Ohne: nur Stand anzeigen.
     *
     * ## EXAMPLES
     *
     *     wp kurswasser setup plugins
     *     wp kurswasser setup plugins --install=woocommerce,bootspruefung-learning --user=admin
     */
    WP_CLI::add_command('kurswasser setup plugins', static function (array $args, array $assoc): void {
        $rows = kurswasser_setup_plugin_rows();
        foreach ($rows as $key => $row) {
            $source = $row['package'] === 'wporg' ? 'wordpress.org' : ($row['package'] !== '' ? (string) $row['package'] : 'keine Quelle (KURSWASSER_LEARNING_PLUGIN_ZIP setzen)');
            WP_CLI::log(sprintf('%-24s %-10s %s', $key, $row['status'], $source));
        }
        if (empty($assoc['install'])) {
            return;
        }
        $keys = $assoc['install'] === 'all' ? array_keys($rows) : array_map('trim', explode(',', (string) $assoc['install']));
        $failed = false;
        foreach (kurswasser_setup_install_plugins($keys) as [$type, $text]) {
            $type === 'error' ? WP_CLI::warning($text) : WP_CLI::log($text);
            $failed = $failed || $type === 'error';
        }
        $failed ? WP_CLI::error('Nicht alle Plugins wurden eingerichtet.') : WP_CLI::success('Plugins eingerichtet.');
    });
}
