<?php

declare(strict_types=1);

/*
 * Integrationstest Einrichtungs-Assistent und Yoast-Übernahme (Theme Kurswasser): Menü und einmalige Umleitung
 * nach dem Aktivieren, Plugin-Liste mit Status und Quellen, Installation aus einer lokalen ZIP (Dummy-Plugin,
 * ohne Netz), Fehlermeldungen (wordpress.org nicht erreichbar, keine Dateirechte), Auswahl der Inhaltsseiten
 * (Option A/B, „Vorerst nicht“), Yoast-Felder mit Überschreib-Regeln, „Alles aus dem Theme übernehmen“ mit
 * Bestätigung. Räumt danach auf.
 *
 * Yoast SEO selbst wird nicht geladen: Ein Stub simuliert YoastSEO() (Indexable_Post_Watcher) und
 * WPSEO_Sitemaps_Cache; „Yoast aktiv“ über den Filter kurswasser_yoast_active, am Ende über WPSEO_VERSION.
 *
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/setup-yoast-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 3).'/bootspruefung-learning/tests/integration/support/guard.php';
require_once ABSPATH.'wp-admin/includes/admin.php';

$theme = dirname(__DIR__);
if (! function_exists('kurswasser_setup_page')) {
    require_once $theme.'/functions.php';
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$html = static function (callable $render): string {
    ob_start();
    $render();

    return (string) ob_get_clean();
};

// Yoast-Stub (nur, wenn kein echtes Yoast geladen ist).
$stub = ! function_exists('YoastSEO');
if ($stub) {
    eval('namespace Yoast\WP\SEO\Integrations\Watchers; class Indexable_Post_Watcher { public static array $built = []; public function build_indexable($id) { self::$built[] = (int) $id; return true; } }');
    eval('class WPSEO_Sitemaps_Cache { public static array $invalidated = []; public static function invalidate_post($id) { self::$invalidated[] = (int) $id; } }');
    eval('function YoastSEO() { static $s = null; if ($s === null) { $s = new stdClass(); $s->classes = new class { public function get($c) { return new $c(); } }; } return $s; }');
}
$built = static fn (): array => $stub ? \Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::$built : [];
$yoastOn = static fn (): bool => true;
$yoastOff = static fn (): bool => false;

// Vorlagen aus den Fixtures bauen.
$tmp = sys_get_temp_dir().'/kw-setup-test-'.getmypid();
exec('python3 '.escapeshellarg($theme.'/tools/content_build.py').' '.escapeshellarg($theme.'/tools/fixtures').' --out '.escapeshellarg($tmp).' 2>&1', $out, $code);
if ($code !== 0) {
    echo implode("\n", $out)."\n";
    exit(1);
}
$dirFilter = static fn (): string => $tmp;
add_filter('kurswasser_content_dir', $dirFilter);
$manifestFile = $tmp.'/pages.php';
$editManifest = static function (string $from, string $to) use ($manifestFile): void {
    $php = (string) file_get_contents($manifestFile);
    file_put_contents($manifestFile, str_replace($from, $to, $php));
};

$paths = ['beispiel-ueber-uns/beispiel-team', 'beispiel-ratgeber', 'beispiel-angebot', 'beispiel-ueber-uns', 'beispiel-faq'];
$cleanup = static function () use ($paths): void {
    foreach ($paths as $path) {
        while (($p = get_page_by_path($path, OBJECT, 'page')) instanceof WP_Post) {
            wp_delete_post($p->ID, true);
        }
    }
};
$cleanup();
$blogname = get_option('blogname');
update_option('blogname', 'Testmarke');
$targetBefore = get_option('kurswasser_content_seo_target', null);
delete_option('kurswasser_content_seo_target');
wp_set_current_user(1);
$uid = get_current_user_id();
$flash = static function () use ($uid): array {
    $f = get_transient('kurswasser_setup_flash_'.$uid);
    delete_transient('kurswasser_setup_flash_'.$uid);

    return is_array($f) ? $f : [];
};

echo "Einrichtungsseite und Umleitung nach dem Aktivieren\n";
ob_start();
do_action('admin_menu');
ob_end_clean();
$check(in_array('kurswasser-setup', array_column($GLOBALS['submenu']['themes.php'] ?? [], 2), true), 'Menüpunkt Design › Kurswasser einrichten');
$check(has_action('after_switch_theme', 'kurswasser_setup_on_switch') !== false, 'after_switch_theme registriert');
delete_transient('kurswasser_setup_redirect');
kurswasser_setup_on_switch();
$check(get_transient('kurswasser_setup_redirect') === false, 'nicht im Frontend bzw. per Skript (kein Backend)');
set_current_screen('themes');
kurswasser_setup_on_switch();
$check((int) get_transient('kurswasser_setup_redirect') === $uid, 'Admin aktiviert im Backend: Merker gesetzt');
add_filter('wp_doing_ajax', '__return_true');
$check(kurswasser_setup_redirect_url() === null && get_transient('kurswasser_setup_redirect') !== false, 'AJAX: keine Umleitung, Merker bleibt');
remove_filter('wp_doing_ajax', '__return_true');
$_GET['activate-multi'] = 'true';
$check(kurswasser_setup_redirect_url() === null, 'Mehrfach-Aktivierung: keine Umleitung');
unset($_GET['activate-multi']);
$check(kurswasser_setup_redirect_url() === admin_url('themes.php?page=kurswasser-setup'), 'erste Admin-Seite: Umleitung zur Einrichtung');
$check(kurswasser_setup_redirect_url() === null, 'nur einmal');
wp_set_current_user(0);
kurswasser_setup_on_switch();
$check(get_transient('kurswasser_setup_redirect') === false, 'ohne Admin-Rechte kein Merker');
wp_set_current_user($uid);
$GLOBALS['current_screen'] = null;

echo "Plugins: Liste, Quellen, Fehler\n";
$rows = kurswasser_setup_plugin_rows();
$check(array_keys($rows) === ['woocommerce', 'wordpress-seo', 'bootspruefung-learning'], 'Reihenfolge: WooCommerce, Yoast, Lern-Plugin');
$check(in_array($rows['bootspruefung-learning']['status'], ['active', 'installed', 'missing'], true) && $rows['woocommerce']['package'] === 'wporg', 'Status je Plugin, wordpress.org als Quelle für WooCommerce ('.implode(', ', array_column($rows, 'status')).')');
$check(kurswasser_learning_plugin_source() === (defined('KURSWASSER_LEARNING_PLUGIN_ZIP') ? KURSWASSER_LEARNING_PLUGIN_ZIP : (is_file($theme.'/plugins/bootspruefung-learning.zip') ? $theme.'/plugins/bootspruefung-learning.zip' : '')), 'Quelle Lern-Plugin: Konstante, sonst ZIP im Theme, sonst leer');
$zipDir = sys_get_temp_dir().'/kw-setup-zip-'.getmypid();
wp_mkdir_p($zipDir);
$zip = $zipDir.'/kw-setup-dummy.zip';
$za = new ZipArchive();
$za->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$za->addFromString('kw-setup-dummy/kw-setup-dummy.php', "<?php\n/**\n * Plugin Name: Kurswasser Setup Dummy\n * Version: 1.0\n */\nupdate_option('kw_setup_dummy_loaded', 1);\n");
$za->close();
$srcFilter = static fn (): string => $zip;
add_filter('kurswasser_learning_plugin_zip', $srcFilter);
$check(kurswasser_learning_plugin_source() === $zip, 'Filter kurswasser_learning_plugin_zip: lokaler Pfad');
remove_filter('kurswasser_learning_plugin_zip', $srcFilter);
$bad = static fn (): string => '/gibt/es/nicht.zip';
add_filter('kurswasser_learning_plugin_zip', $bad);
$check(kurswasser_learning_plugin_source() === '', 'fehlende Datei: keine Quelle');
remove_filter('kurswasser_learning_plugin_zip', $bad);
$url = static fn (): string => 'https://example.org/bootspruefung-learning.zip';
add_filter('kurswasser_learning_plugin_zip', $url);
$check(kurswasser_learning_plugin_source() === 'https://example.org/bootspruefung-learning.zip', 'URL als Quelle');
remove_filter('kurswasser_learning_plugin_zip', $url);

// Lern-Plugin „nicht installiert“, keine Quelle: Knopf „Plugin hochladen“.
$hide = static fn (array $p): array => array_filter($p, static fn ($f) => ! str_starts_with((string) $f, 'bootspruefung-learning/') && ! str_starts_with((string) $f, 'wordpress-seo/'), ARRAY_FILTER_USE_KEY);
add_filter('kurswasser_setup_installed_plugins', $hide);
$noSource = static fn (): string => '';
add_filter('kurswasser_learning_plugin_zip', $noSource);
$card = $html('kurswasser_setup_plugins_card');
$check(str_contains($card, 'plugin-install.php?tab=upload') && str_contains($card, 'KURSWASSER_LEARNING_PLUGIN_ZIP') && ! str_contains($card, 'value="bootspruefung-learning"'), 'ohne Quelle: Erklärung und „Plugin hochladen“ statt Häkchen');
$check(str_contains($card, 'value="wordpress-seo"') && ! str_contains($card, 'value="wordpress-seo" id="kw-plugin-wordpress-seo" checked'), 'Yoast: Häkchen „installieren und aktivieren“, nicht vorausgewählt');
$check(str_contains($card, 'name="_wpnonce"') && str_contains($card, 'kurswasser_setup_plugins'), 'Formular mit Nonce');
remove_filter('kurswasser_learning_plugin_zip', $noSource);
add_filter('kurswasser_learning_plugin_zip', $srcFilter);
$card = $html('kurswasser_setup_plugins_card');
$check(str_contains($card, 'value="bootspruefung-learning"') && str_contains($card, 'kw-setup-dummy.zip'), 'mit Quelle: Häkchen, Quelle genannt');
remove_filter('kurswasser_learning_plugin_zip', $srcFilter);
add_filter('file_mod_allowed', '__return_false');
$check(str_contains(kurswasser_setup_install_problem(), 'DISALLOW_FILE_MODS'), 'DISALLOW_FILE_MODS: Hinweis statt Installation');
$card = $html('kurswasser_setup_plugins_card');
$check(str_contains($card, 'notice-warning') && ! str_contains($card, 'value="wordpress-seo"') && str_contains($card, 'wordpress.org/plugins/wordpress-seo/'), 'Karte: Hinweis, kein Häkchen, Link zu wordpress.org');
$msg = kurswasser_setup_install_plugins(['wordpress-seo']);
$check($msg[0][0] === 'error' && str_contains($msg[0][1], 'DISALLOW_FILE_MODS'), 'Installation ohne Dateirechte: verständlicher Fehler');
remove_filter('file_mod_allowed', '__return_false');
$offline = static fn () => new WP_Error('http_request_failed', 'Verbindung abgelehnt');
add_filter('plugins_api', $offline);
$msg = kurswasser_setup_install_plugins(['wordpress-seo']);
$check($msg[0][0] === 'error' && str_contains($msg[0][1], 'wordpress.org ist nicht erreichbar') && str_contains($msg[0][1], 'Verbindung abgelehnt'), 'wordpress.org nicht erreichbar: Fehlermeldung ('.$msg[0][1].')');
remove_filter('plugins_api', $offline);
remove_filter('kurswasser_setup_installed_plugins', $hide);

// Installation aus lokaler ZIP über Plugin_Upgrader (Dummy-Plugin) und Aktivierung.
$dummy = static function (array $plugins) use ($zip): array {
    return ['kw-setup-dummy' => ['name' => 'Kurswasser Setup Dummy', 'dir' => 'kw-setup-dummy', 'file' => 'kw-setup-dummy/kw-setup-dummy.php', 'source' => 'zip', 'package' => $zip, 'checked' => true, 'description' => 'Test']] + $plugins;
};
add_filter('kurswasser_setup_plugins', $dummy);
delete_option('kw_setup_dummy_loaded');
// Ohne Netz: WordPress fragt nach jeder Installation wordpress.org nach Updates (Warnung im Log) – hier abschalten.
foreach (['wp_version_check', 'wp_update_plugins', 'wp_update_themes'] as $hook) {
    remove_action('upgrader_process_complete', $hook);
}
if (kurswasser_setup_install_problem() === '') {
    $msg = kurswasser_setup_install_plugins(['kw-setup-dummy']);
    wp_clean_plugins_cache();
    $check(array_column($msg, 0) === ['success', 'success'] && str_contains($msg[0][1], 'installiert') && str_contains($msg[1][1], 'aktiviert'), 'ZIP installiert und aktiviert ('.implode(' | ', array_column($msg, 1)).')');
    $check(is_plugin_active('kw-setup-dummy/kw-setup-dummy.php') && (int) get_option('kw_setup_dummy_loaded') === 1 && is_file($zip), 'Plugin aktiv, lokale ZIP bleibt erhalten');
    $msg = kurswasser_setup_install_plugins(['kw-setup-dummy']);
    $check($msg[0][0] === 'info', 'schon aktiv: nichts zu tun');
    deactivate_plugins('kw-setup-dummy/kw-setup-dummy.php', true);
    $check(kurswasser_setup_plugin_rows()['kw-setup-dummy']['status'] === 'installed', 'deaktiviert: Status „installiert“');
    $msg = kurswasser_setup_install_plugins(['kw-setup-dummy']);
    $check(array_column($msg, 0) === ['success'] && str_contains($msg[0][1], 'aktiviert'), 'installiert, nicht aktiv: nur aktivieren');
    deactivate_plugins('kw-setup-dummy/kw-setup-dummy.php', true);
    ob_start();
    delete_plugins(['kw-setup-dummy/kw-setup-dummy.php']);
    ob_end_clean();
    wp_clean_plugins_cache();
    $check(! is_dir(WP_PLUGIN_DIR.'/kw-setup-dummy'), 'Dummy-Plugin entfernt');
} else {
    echo '  skip Installation (keine Dateirechte: '.kurswasser_setup_install_problem().")\n";
}
remove_filter('kurswasser_setup_plugins', $dummy);
delete_option('kw_setup_dummy_loaded');
@unlink($zip);
@rmdir($zipDir);

echo "Inhaltsseiten ohne Yoast\n";
add_filter('kurswasser_yoast_active', $yoastOff);
$card = $html('kurswasser_setup_content_card');
$check(str_contains($card, 'value="theme" checked') && str_contains($card, 'Inhaltsseiten anlegen (SEO-Daten gibt das Theme aus)') && str_contains($card, 'value="later"') && ! str_contains($card, 'value="yoast"'), 'Auswahl: Theme (vorausgewählt) oder „Vorerst nicht“');
$msg = kurswasser_setup_apply_content_choice('later');
$check($msg[0][0] === 'info' && get_page_by_path('beispiel-ratgeber') === null && get_option('kurswasser_content_seo_target', '') === '', '„Vorerst nicht“: nichts angelegt, nichts gespeichert');
$msg = kurswasser_setup_apply_content_choice('yoast');
$check($msg[0][0] === 'error' && get_option('kurswasser_content_seo_target', '') === '', 'Yoast wählen ohne Yoast: Fehler');
remove_filter('kurswasser_yoast_active', $yoastOff);

echo "Yoast aktiv, noch keine Wahl\n";
add_filter('kurswasser_yoast_active', $yoastOn);
$card = $html('kurswasser_setup_content_card');
$check(str_contains($card, 'value="yoast" checked') && str_contains($card, 'SEO-Daten in Yoast (empfohlen)') && str_contains($card, 'SEO-Daten im Theme') && str_contains($card, 'value="later"'), 'Auswahl: Yoast (empfohlen, vorausgewählt), Theme, „Vorerst nicht“');
set_current_screen('dashboard');
$notice = $html(static fn () => do_action('admin_notices'));
$check(str_contains($notice, 'Inhaltsseiten einrichten') && str_contains($notice, 'page=kurswasser-setup') && ! str_contains($notice, 'action=kurswasser_content_sync'), 'Admin-Hinweis: erst wählen (Link zur Einrichtung statt Abgleich)');
$GLOBALS['current_screen'] = null;
$check(kurswasser_content_seo_target() === 'theme', 'ohne Wahl: SEO-Daten im Theme');
remove_filter('kurswasser_yoast_active', $yoastOn);

echo "Option A: Seiten anlegen, SEO im Theme\n";
add_filter('kurswasser_yoast_active', $yoastOff);
$msg = kurswasser_setup_apply_content_choice('theme');
$p1 = get_page_by_path('beispiel-ratgeber');
$p2 = get_page_by_path('beispiel-angebot');
$p3 = get_page_by_path('beispiel-ueber-uns');
$p4 = get_page_by_path('beispiel-ueber-uns/beispiel-team');
$p5 = get_page_by_path('beispiel-faq');
$check($msg[0][0] === 'success' && $p1 && $p2 && $p3 && $p4 && $p5 && get_option('kurswasser_content_seo_target') === 'theme', 'angelegt, Wahl „theme“ gespeichert');
$check(get_post_meta($p1->ID, '_kw_seo_title', true) !== '' && get_post_meta($p1->ID, '_yoast_wpseo_title', true) === '' && get_post_meta($p1->ID, '_kw_yoast_written', true) === '', 'nur Theme-Felder, keine Yoast-Felder');
$contentFlash = get_transient('kurswasser_content_flash_'.$uid);
$check(is_array($contentFlash) && count($contentFlash['created'] ?? []) === 5, 'Ergebnis als Hinweis (5 angelegt)');
remove_filter('kurswasser_yoast_active', $yoastOff);

echo "Option B: SEO-Daten in Yoast\n";
add_filter('kurswasser_yoast_active', $yoastOn);
delete_option('kurswasser_content_seo_target'); // Yoast erst nach dem Anlegen aktiviert, noch keine Wahl
set_current_screen('dashboard');
$notice = $html(static fn () => do_action('admin_notices'));
$check(str_contains($notice, 'SEO-Daten in Yoast übernehmen') && ! str_contains($notice, 'action=kurswasser_content_sync'), 'Admin-Hinweis: Yoast später aktiviert – Angebot zur Übernahme');
$GLOBALS['current_screen'] = null;
update_post_meta($p4->ID, '_yoast_wpseo_title', 'Eigener Team-Titel');      // vom Betreiber in Yoast gesetzt
update_post_meta($p3->ID, '_yoast_wpseo_focuskw', 'Eigenes Keyword');
$builtBefore = count($built());
$msg = kurswasser_setup_apply_content_choice('yoast');
$m = static fn (WP_Post $p, string $k): string => (string) get_post_meta($p->ID, $k, true);
$check($msg[0][0] === 'success' && get_option('kurswasser_content_seo_target') === 'yoast' && kurswasser_content_seo_target() === 'yoast', 'Wahl „yoast“ gespeichert');
$check($m($p1, '_yoast_wpseo_title') === 'Mindestalter Bootsführerschein (Beispiel) | %%sitename%%', 'Titel: {{brand}} → %%sitename%% ('.$m($p1, '_yoast_wpseo_title').')');
$check($m($p1, '_yoast_wpseo_metadesc') === 'Beispielseite für den Konverter: Ab 16 unter Motor, ab 14 unter Segel – mit Quellen und Ablauf. Bei %%sitename%% online lernen.', 'Beschreibung mit %%sitename%%');
$check($m($p1, '_yoast_wpseo_meta-robots-noindex') === '' && $m($p2, '_yoast_wpseo_meta-robots-noindex') === '1', 'noindex = 1 nur bei noindex');
$check($m($p2, '_yoast_wpseo_canonical') === get_permalink($p1) && $m($p1, '_yoast_wpseo_canonical') === '', 'Canonical als absolute URL (Slug → Permalink), sonst leer');
$check($m($p1, '_yoast_wpseo_schema_article_type') === 'Article' && $m($p2, '_yoast_wpseo_schema_article_type') === '', 'Artikeltyp Article nur bei Schema Article');
$check($m($p3, '_yoast_wpseo_schema_page_type') === 'AboutPage' && $m($p5, '_yoast_wpseo_schema_page_type') === '', 'Seitentyp AboutPage; FAQ-Seite ohne FAQPage (Shortcode liefert FAQ-JSON-LD)');
$check($m($p1, '_yoast_wpseo_focuskw') === 'Mindestalter Bootsführerschein' && $m($p2, '_yoast_wpseo_focuskw') === '', 'Fokus-Keyword nur aus focus_keyword, sonst keins');
$check($m($p4, '_yoast_wpseo_title') === 'Eigener Team-Titel' && $m($p4, '_yoast_wpseo_metadesc') === 'Beispiel-Unterseite (Typ B, Entwurf).', 'vom Betreiber gesetzter Yoast-Titel bleibt, leere Felder gefüllt');
$check($m($p3, '_yoast_wpseo_focuskw') === 'Eigenes Keyword', 'fremdes Fokus-Keyword bleibt');
$written = get_post_meta($p1->ID, '_kw_yoast_written', true);
$check(is_array($written) && ($written['_yoast_wpseo_title'] ?? null) === $m($p1, '_yoast_wpseo_title') && ! array_key_exists('_yoast_wpseo_title', (array) get_post_meta($p4->ID, '_kw_yoast_written', true)), 'Merker _kw_yoast_written: nur selbst geschriebene Werte');
$check($m($p1, '_kw_seo_title') === 'Mindestalter Bootsführerschein (Beispiel) | {{brand}}' && $m($p2, '_kw_noindex') === '1', '_kw_-Felder bleiben (Wechsel zurück möglich)');
if ($stub) {
    $check(count($built()) - $builtBefore === 5 && in_array($p1->ID, $built(), true) && in_array($p1->ID, WPSEO_Sitemaps_Cache::$invalidated, true), 'Yoast: Indexable je Seite neu gebaut, Sitemap-Cache geleert');
}
$cf = get_transient('kurswasser_content_flash_'.$uid);
$check(is_array($cf) && count($cf['yoast-written'] ?? []) === 5 && count($cf['yoast-kept'] ?? []) === 2, 'Hinweis: Yoast-Felder übernommen (5), behalten (2)');
$builtBefore = count($built());
$again = kurswasser_content_pages_sync();
$check(array_sum(array_map(static fn ($r) => count($r['yoast']['written'] ?? []), $again)) === 0 && count($built()) === $builtBefore, 'zweiter Abgleich: nichts zu schreiben, kein Neuaufbau');
$check(count($again['beispiel-ueber-uns/beispiel-team']['yoast']['kept']) === 1 && $again['beispiel-ueber-uns/beispiel-team']['yoast']['kept'][0] === '_yoast_wpseo_title', 'gemeldet: Titel von dir gesetzt');

echo "Neuer Vorlagenstand: nur Unverändertes wird überschrieben\n";
update_post_meta($p1->ID, '_yoast_wpseo_metadesc', 'Beschreibung des Betreibers.');
delete_post_meta($p1->ID, '_yoast_wpseo_focuskw'); // bewusst geleert
$editManifest("'seo_title' => 'Mindestalter Bootsführerschein (Beispiel) | {{brand}}'", "'seo_title' => 'Mindestalter (neu) | {{brand}}'");
$editManifest('Bei {{brand}} online lernen.', 'Neu bei {{brand}}.');
$editManifest("'canonical' => 'beispiel-ratgeber',\n        'seo_title' => 'Gruppenpakete", "'canonical' => '',\n        'seo_title' => 'Gruppenpakete");
$php = (string) file_get_contents($manifestFile);
$pos = strpos($php, "'path' => 'beispiel-angebot'");
file_put_contents($manifestFile, substr($php, 0, $pos).preg_replace("/'index' => 'noindex'/", "'index' => 'index'", substr($php, $pos), 1));
$dry = kurswasser_content_pages_sync(['dry_run' => true]);
$check($dry['beispiel-ratgeber']['state'] === 'outdated' && $dry['beispiel-ratgeber']['yoast']['written'] === ['_yoast_wpseo_title'] && $dry['beispiel-ratgeber']['yoast']['dry'], 'Probelauf: Yoast-Titel zu setzen, nichts geschrieben');
$check($m($p1, '_yoast_wpseo_title') === 'Mindestalter Bootsführerschein (Beispiel) | %%sitename%%', 'Probelauf ändert nichts');
set_current_screen('dashboard');
$notice = $html(static fn () => do_action('admin_notices'));
$check(str_contains($notice, 'Yoast-Felder zu übernehmen') && str_contains($notice, 'Yoast-Felder von dir gesetzt') && str_contains($notice, 'action=kurswasser_content_sync') && str_contains($notice, 'SEO-Daten: Yoast'), 'Admin-Hinweis: Yoast-Stand und Knopf');
$GLOBALS['current_screen'] = null;
$res = kurswasser_content_pages_sync();
$check($res['beispiel-ratgeber']['state'] === 'updated' && $m($p1, '_yoast_wpseo_title') === 'Mindestalter (neu) | %%sitename%%' && $m($p1, '_kw_seo_title') === 'Mindestalter (neu) | {{brand}}', 'unveränderter Yoast-Titel → neuer Stand (auch _kw_)');
$check($m($p1, '_yoast_wpseo_metadesc') === 'Beschreibung des Betreibers.' && in_array('_yoast_wpseo_metadesc', $res['beispiel-ratgeber']['yoast']['kept'], true), 'geänderte Yoast-Beschreibung bleibt');
$check($m($p1, '_yoast_wpseo_focuskw') === '' && in_array('_yoast_wpseo_focuskw', $res['beispiel-ratgeber']['yoast']['kept'], true), 'bewusst geleertes Feld bleibt leer');
$check(get_post_meta($p2->ID, '_yoast_wpseo_meta-robots-noindex', true) === '' && ! metadata_exists('post', $p2->ID, '_yoast_wpseo_meta-robots-noindex') && get_post_meta($p2->ID, '_yoast_wpseo_canonical', true) === '', 'noindex und Canonical entfallen, wenn die Vorlage sie streicht');

echo "Alles aus dem Theme übernehmen (force)\n";
wp_update_post(['ID' => $p3->ID, 'post_content' => get_post_field('post_content', $p3->ID)."\n<!-- wp:paragraph --><p>Text des Betreibers.</p><!-- /wp:paragraph -->"]);
update_post_meta($p5->ID, '_kw_seo_description', 'Eigene Theme-Beschreibung.');
$preview = array_column(kurswasser_content_force_preview(), null, 'title');
$t1 = kurswasser_content_tokens('Beispiel-Ratgeber: Mindestalter für den Bootsführerschein');
$check(isset($preview[$t1], $preview[$p3->post_title], $preview[$p4->post_title], $preview[$p5->post_title]) && ! isset($preview[$p2->post_title]), 'Vorschau: betroffene Seiten ('.implode(', ', array_keys($preview)).')');
$check(str_contains(implode(' ', $preview[$t1]['reasons']), 'Meta-Beschreibung') && str_contains(implode(' ', $preview[$t1]['reasons']), 'Fokus-Keyword') && str_contains(implode(' ', $preview[$p3->post_title]['reasons']), 'Inhalt') && str_contains(implode(' ', $preview[$p5->post_title]['reasons']), 'Meta-Description'), 'Gründe: Yoast-Felder, Inhalt, Theme-SEO-Feld');
$_GET['kw_view'] = 'force';
$page = $html('kurswasser_setup_page');
unset($_GET['kw_view']);
$check(str_contains($page, 'Alles aus dem Theme übernehmen (überschreibt deine Änderungen)') && str_contains($page, 'name="kw_confirm" value="1" required') && str_contains($page, 'kurswasser_setup_force') && str_contains($page, esc_html($p3->post_title)) && str_contains($page, 'notice-error'), 'Bestätigungsschritt: Warnung, Liste, Pflicht-Häkchen');
$main = $html('kurswasser_setup_page');
$check(str_contains($main, 'kw_view=force') && ! str_contains($main, 'name="kw_confirm"') && str_contains($main, '1. Plugins') && str_contains($main, '2. Inhaltsseiten'), 'Hauptseite: nur Link zur Bestätigung, nie direkt');
$msg = kurswasser_setup_force_sync(false);
$check($msg[0][0] === 'error' && $m($p1, '_yoast_wpseo_metadesc') === 'Beschreibung des Betreibers.', 'ohne Bestätigung: nichts geändert');
$msg = kurswasser_setup_force_sync(true);
$check($msg[0][0] === 'success' && str_ends_with($m($p1, '_yoast_wpseo_metadesc'), 'Ablauf. Neu bei %%sitename%%.') && $m($p1, '_yoast_wpseo_focuskw') === 'Mindestalter Bootsführerschein', 'force: Yoast-Felder des Betreibers überschrieben');
$check($m($p4, '_yoast_wpseo_title') === 'Team (Beispiel) | %%sitename%%' && $m($p3, '_yoast_wpseo_focuskw') === '' && ! str_contains((string) get_post_field('post_content', $p3->ID), 'Text des Betreibers.') && $m($p5, '_kw_seo_description') === 'Beispielseite mit dem FAQ-Shortcode und FAQPage-Markup.', 'force: Inhalt, fremde Yoast-Werte und Theme-SEO-Feld ersetzt');
$check(kurswasser_content_force_preview() === [], 'danach: nichts mehr abweichend');
$check(has_action('admin_post_kurswasser_setup_force') && has_action('admin_post_kurswasser_setup_content') && has_action('admin_post_kurswasser_setup_plugins'), 'admin-post-Aktionen registriert');

echo "Zurück zu Option A\n";
$msg = kurswasser_setup_apply_content_choice('theme');
$check(get_option('kurswasser_content_seo_target') === 'theme' && kurswasser_content_seo_target() === 'theme' && $m($p1, '_yoast_wpseo_title') === 'Mindestalter (neu) | %%sitename%%', 'Wahl „theme“: Yoast-Felder bleiben unangetastet');
$check(array_filter(kurswasser_content_pages_sync(['dry_run' => true]), static fn ($r) => isset($r['yoast'])) === [], 'Abgleich ohne Yoast-Teil');
update_option('kurswasser_content_seo_target', 'yoast');
remove_filter('kurswasser_yoast_active', $yoastOn);
add_filter('kurswasser_yoast_active', $yoastOff);
$check(kurswasser_content_seo_target() === 'theme', 'Wahl „yoast“, aber Yoast inaktiv: Theme gibt aus');
$check(str_contains($html('kurswasser_setup_content_card'), 'Yoast SEO ist aber nicht aktiv'), 'Einrichtung weist darauf hin');
remove_filter('kurswasser_yoast_active', $yoastOff);

echo "Yoast-Werte im Einzelnen\n";
$base = ['seo_title' => 'Kurz', 'meta_description' => '', 'index' => 'index', 'canonical' => '', 'schema' => 'ContactPage'];
$v = kurswasser_content_yoast_values($base);
$check($v['_yoast_wpseo_title'] === 'Kurz %%sep%% %%sitename%%' && $v['_yoast_wpseo_schema_page_type'] === 'ContactPage' && $v['_yoast_wpseo_metadesc'] === '', 'Titel ohne Marke: „%%sep%% %%sitename%%“ angehängt (wie das Theme); ContactPage');
$v = kurswasser_content_yoast_values(['seo_title' => str_repeat('x', 55)] + $base);
$check($v['_yoast_wpseo_title'] === str_repeat('x', 55), 'langer Titel: keine Marke angehängt');
$check(kurswasser_content_yoast_values(['canonical' => '/'] + $base)['_yoast_wpseo_canonical'] === home_url('/') && kurswasser_content_yoast_values(['canonical' => 'https://example.org/x/'] + $base)['_yoast_wpseo_canonical'] === 'https://example.org/x/', 'Canonical „/“ und URL');
$check(kurswasser_content_yoast_values(['canonical' => 'beispiel-angebot'] + $base)['_yoast_wpseo_canonical'] === '' && kurswasser_content_yoast_values(['canonical' => 'gibt/es-nicht'] + $base)['_yoast_wpseo_canonical'] === home_url('/gibt/es-nicht/'), 'Canonical auf Entwurf: leer (Rückfall im Theme); unbekannter Pfad: URL');
if (! defined('WPSEO_VERSION')) {
    define('WPSEO_VERSION', '0.0-kurswasser-test');
}
$check(kurswasser_yoast_active() && kurswasser_seo_plugin_active(), 'WPSEO_VERSION: Yoast gilt als aktiv');

// Aufräumen
remove_filter('kurswasser_content_dir', $dirFilter);
$cleanup();
update_option('blogname', $blogname);
$targetBefore === null ? delete_option('kurswasser_content_seo_target') : update_option('kurswasser_content_seo_target', $targetBefore);
delete_transient('kurswasser_content_flash_'.$uid);
delete_transient('kurswasser_setup_flash_'.$uid);
array_map('unlink', glob($tmp.'/pages/*') ?: []);
@rmdir($tmp.'/pages');
@unlink($tmp.'/pages.php');
@rmdir($tmp);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
