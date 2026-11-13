<?php

declare(strict_types=1);

/*
 * Hilfsprozess für smoke-test.php: führt die Deinstallation in frischem Kontext aus. Ist „Daten beim
 * Deinstallieren löschen“ gesetzt, prüft er zusätzlich, dass genau die Daten des Plugins und der Add-ons
 * verschwinden (T82): Optionen (auch bp_mail_test mit E-Mail-Adresse), Transients, Nutzer-Meta, Cron-Ereignisse,
 * Tipps auch im Papierkorb samt Kategorien – und dass gleich klingende Daten anderer Plugins bleiben.
 * Exit-Code 1 bei einem Fehler. Nur gegen eine Test-Datenbank (support/guard.php).
 */
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

use Bootspruefung\Learning\Infrastructure\Uninstaller;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Tips\Tips;

global $wpdb;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$plugin = 'bootspruefung-learning/bootspruefung-learning.php';
$deleting = (bool) Settings::get('delete_data_on_uninstall');

$tipIds = [];
$tipTerm = 0;
$userId = (int) (get_users(['fields' => 'ID', 'number' => 1, 'orderby' => 'ID'])[0] ?? 0);
// Gleich klingende Daten anderer Plugins: dürfen nicht gelöscht werden (kein Muster „bp_%“).
$foreignOptions = ['bp_fremdes_plugin', 'bpl_einstellung', 'bp_learningx_fremd'];
$foreignTransient = 'bp_fremd_cache';
if ($deleting) {
    echo "Deinstallation mit Datenlöschung (T82)\n";
    foreach (['bp_mail_test' => ['to' => 'betreiber@example.test', 'ok' => true], 'bp_cron_last_hourly' => time(), 'bp_privacy_endpoint_version' => '1',
        'bp_learning_uninstall_probe' => 1, 'bp_dispute_lock_42' => 'x|'.time(), 'bp_voucher_grant_'.md5('t82') => 'x|'.time()] as $name => $value) {
        update_option($name, $value, false);
    }
    foreach (['bp_learning_video_hosts', 'bp_paypal_token_'.md5('t82'), 'bp_oss_totals_2026', 'bp_webhook_rejected_paypal', 'bp_paypal_verify_calls', 'bp_learning_flash_1', 'bp_dispute_'.md5('t82'), 'bp_rl_1_1'] as $name) {
        set_transient($name, 'x', HOUR_IN_SECONDS);
    }
    foreach ($foreignOptions as $name) {
        update_option($name, 'bleibt', false);
    }
    set_transient($foreignTransient, 'bleibt', HOUR_IN_SECONDS);
    foreach (Uninstaller::userMetaKeys() as $key) {
        update_user_meta($userId, $key, ['t82' => true]);
    }
    update_user_meta($userId, 'bp_fremd_meta', 'bleibt');
    // Cron: alle eigenen Ereignisse, dazu die der Add-ons.
    foreach (Plugin::CRON_HOOKS as $hook) {
        wp_schedule_single_event(time() + 600, $hook, str_contains($hook, 'export') ? ['t82'] : []);
    }
    wp_schedule_event(time() + 600, 'hourly', 'lbvc_retry');
    update_option('lbac_settings', ['enabled' => true], false);
    update_option('lbvc_pending', [1 => ['t82']], false);
    set_transient('lbvc_broken_targets', [1], HOUR_IN_SECONDS);
    // Katalog-Merkwerte an einem Produkt (T98) und Ablageordner für den Import (T96).
    $catalogPost = (int) wp_insert_post(['post_type' => 'post', 'post_title' => 'T82 Katalog-Merkwert', 'post_status' => 'draft']);
    foreach (['_bp_catalog_price' => '49.00', '_bp_catalog_status' => 'draft', '_bp_manual_status' => 'draft'] as $metaKey => $metaValue) {
        update_post_meta($catalogPost, $metaKey, $metaValue);
    }
    update_option('t82_catalog_post', $catalogPost, false);
    // Ablageordner im Paketordner (T108) und – falls noch vorhanden – der frühere uploads/bootspruefung-import/.
    $newDropDir = \Bootspruefung\Learning\Content\Import\ImportSources::dropDir();
    file_put_contents($newDropDir.'/paket.zip', 'x');
    update_option('t82_new_drop_dir', $newDropDir, false);
    $dropDir = rtrim((string) wp_upload_dir(null, false)['basedir'], '/').'/bootspruefung-import';
    wp_mkdir_p($dropDir);
    file_put_contents($dropDir.'/paket.zip', 'x');
    // Tipps: veröffentlicht, Entwurf und im Papierkorb, mit Kategorie.
    Tips::registerTypes();
    $term = wp_insert_term('T82 Kategorie', Tips::TAXONOMY);
    $tipTerm = is_array($term) ? (int) $term['term_id'] : 0;
    foreach (['publish', 'draft', 'trash'] as $status) {
        $id = (int) wp_insert_post(['post_type' => Tips::POST_TYPE, 'post_status' => $status, 'post_title' => 'T82 '.$status, 'post_content' => 'x']);
        wp_set_object_terms($id, [$tipTerm], Tips::TAXONOMY);
        $tipIds[] = $id;
    }
}

deactivate_plugins($plugin);
uninstall_plugin($plugin);
if ($deleting) {
    // Die Add-ons werden wie von WordPress mit gesetztem WP_UNINSTALL_PLUGIN geladen.
    foreach (['learnboating-affiliate-connector', 'learnboating-voucher-connector'] as $addon) {
        include dirname(__DIR__, 3).'/'.$addon.'/uninstall.php';
    }
}

if (! $deleting) {
    exit(0);
}
wp_cache_flush();
$optionExists = static fn (string $name): bool => (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name));
$check(! $optionExists('bp_mail_test'), 'Testmail-Ergebnis mit E-Mail-Adresse (bp_mail_test) gelöscht');
$check(! $optionExists('bp_cron_last_hourly') && ! $optionExists('bp_privacy_endpoint_version'), 'Optionen außerhalb von bp_learning_* gelöscht');
$check(! $optionExists('bp_dispute_lock_42') && ! $optionExists('bp_voucher_grant_'.md5('t82')), 'liegen gebliebene Sperren gelöscht');
$leftOptions = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bp\\_learning\\_%'");
$check($leftOptions === [], 'keine Option bp_learning_* übrig'.($leftOptions ? ': '.implode(', ', $leftOptions) : ''));
$leftTransients = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE '\\_transient\\_bp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_%') AND option_name NOT LIKE %s",
    '%'.$wpdb->esc_like($foreignTransient)
));
$check($leftTransients === [], 'eigene Transients gelöscht'.($leftTransients ? ' – übrig: '.implode(', ', $leftTransients) : ''));
$check(array_filter($foreignOptions, static fn ($n) => ! $optionExists($n)) === [] && $optionExists('_transient_'.$foreignTransient), 'gleich klingende Optionen und Transients anderer Plugins bleiben');
$metaLeft = $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE (meta_key LIKE 'bp\\_%' OR meta_key LIKE '\\_bp\\_%') AND meta_key <> 'bp_fremd_meta'");
$check($metaLeft === [], 'Nutzer-Meta des Plugins gelöscht'.($metaLeft ? ' – übrig: '.implode(', ', $metaLeft) : ''));
$check(get_user_meta($userId, 'bp_fremd_meta', true) === 'bleibt', 'fremde Nutzer-Meta bleibt');
$cronLeft = [];
foreach ((array) _get_cron_array() as $events) {
    foreach (array_keys((array) $events) as $hook) {
        if (in_array($hook, array_merge(Plugin::CRON_HOOKS, ['lbvc_retry']), true)) {
            $cronLeft[] = $hook;
        }
    }
}
$check($cronLeft === [], 'alle Cron-Ereignisse von Plugin und Add-ons ausgetragen'.($cronLeft ? ' – übrig: '.implode(', ', array_unique($cronLeft)) : ''));
$tipsLeft = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Tips::POST_TYPE));
$check($tipsLeft === 0, 'alle Tipps gelöscht, auch Entwurf und Papierkorb');
$termsLeft = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (%s, %s, %s)", Tips::TAXONOMY, \Bootspruefung\Learning\Faq\Faq::TAXONOMY, \Bootspruefung\Learning\Faq\CustomerFaq::TAXONOMY));
$check($termsLeft === 0, 'Tipp-, FAQ- und Kunden-FAQ-Kategorien gelöscht');
$check(! $optionExists('lbac_settings') && ! $optionExists('lbvc_pending') && ! $optionExists('_transient_lbvc_broken_targets'), 'Add-ons: Optionen und Transients gelöscht');
$catalogPost = (int) get_option('t82_catalog_post');
$catalogMetaLeft = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_bp_catalog_price', '_bp_catalog_status', '_bp_manual_status')");
$check($catalogMetaLeft === 0 && get_post($catalogPost) !== null, 'Katalog-Merkwerte an Produkten gelöscht, Produkte bleiben (T98)');
$check(! is_dir(rtrim((string) wp_upload_dir(null, false)['basedir'], '/').'/bootspruefung-import'), 'früherer Ablageordner für den Import gelöscht (T96)');
$newDropDir = (string) get_option('t82_new_drop_dir');
$check($newDropDir !== '' && ! is_dir($newDropDir), 'Ablageordner im Paketordner gelöscht (T108)');
delete_option('t82_new_drop_dir');
wp_delete_post($catalogPost, true);
delete_option('t82_catalog_post');
// Multisite: beim Löschen einer Website gehen auch die eigenen Tabellen (abhängige zuerst).
$dropped = Plugin::dropTablesOnSiteDelete([$wpdb->prefix.'posts'], 1);
$check(in_array($wpdb->prefix.'bp_course', $dropped, true) && array_search($wpdb->prefix.'bp_attempt_answer', $dropped, true) < array_search($wpdb->prefix.'bp_question', $dropped, true), 'Multisite: wpmu_drop_tables ergänzt die eigenen Tabellen in sicherer Reihenfolge');
$check(has_filter('wpmu_drop_tables', [Plugin::class, 'dropTablesOnSiteDelete']) !== false, 'Filter wpmu_drop_tables verbunden');

// Aufräumen der Fremddaten.
foreach ($foreignOptions as $name) {
    delete_option($name);
}
delete_transient($foreignTransient);
delete_user_meta($userId, 'bp_fremd_meta');
echo $failures === 0 ? "Deinstallation: alle Prüfungen bestanden.\n" : "Deinstallation: {$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
