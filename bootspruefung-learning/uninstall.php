<?php
/**
 * Deinstallation: löscht Daten nur, wenn das in den Einstellungen
 * ausdrücklich aktiviert ist (Konzept Kap. 8). Standard ist Erhalt.
 *
 * @package Bootspruefung\Learning
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__.'/src/Infrastructure/Autoloader.php';
\Bootspruefung\Learning\Infrastructure\Autoloader::register(__DIR__.'/src');

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

if (! Settings::get('delete_data_on_uninstall')) {
    return;
}

global $wpdb;
// Lizenzierte Seekarten (BSH) ganz löschen – sie dürfen nach der Deinstallation nicht öffentlich werden.
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_chart']) as $chartId) {
    wp_delete_attachment((int) $chartId, true);
}
(new Migrator($wpdb))->dropAll();
Capabilities::removeFromAllRoles();
delete_option(Settings::OPTION);
// Synchronisierte App-Einstellungen (Nutzer-Meta, GET/PUT /me/settings).
delete_metadata('user', 0, 'bp_app_settings', '', true);
// Merker der Ablauf-Erinnerungen (je Kurs und Ablaufdatum).
delete_metadata('user', 0, \Bootspruefung\Learning\Commerce\ExpiryReminder::META, '', true);

// Eigene Optionen, Zwischenspeicher und Tipps.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bp\\_learning\\_%' OR option_name LIKE '\\_transient\\_bp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_%'");
foreach (get_posts(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $tipId) {
    wp_delete_post((int) $tipId, true);
}
// FAQ: Beiträge (samt Meta und Revisionen), Rubriken/Unterkategorien (samt Term-Meta) und Zwischenspeicher.
// Die Seite „Häufige Fragen“ bleibt als normale Seite des Betreibers erhalten.
\Bootspruefung\Learning\Faq\Faq::registerTypes();
$faqStatuses = array_values(array_diff(array_keys(get_post_stati()), ['auto-draft']));
foreach (get_posts(['post_type' => \Bootspruefung\Learning\Faq\Faq::POST_TYPE, 'post_status' => $faqStatuses, 'numberposts' => -1, 'fields' => 'ids']) as $faqId) {
    wp_delete_post((int) $faqId, true);
}
$faqTerms = get_terms(['taxonomy' => \Bootspruefung\Learning\Faq\Faq::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']);
foreach (is_array($faqTerms) ? $faqTerms : [] as $faqTermId) {
    wp_delete_term((int) $faqTermId, \Bootspruefung\Learning\Faq\Faq::TAXONOMY);
}
// Kunden-FAQ ebenso (Beiträge, Scheine/Unterkategorien, Zwischenspeicher); der Datenstand steckt in bp_learning_cfaq_version.
\Bootspruefung\Learning\Faq\CustomerFaq::registerTypes();
foreach (get_posts(['post_type' => \Bootspruefung\Learning\Faq\CustomerFaq::POST_TYPE, 'post_status' => $faqStatuses, 'numberposts' => -1, 'fields' => 'ids']) as $customerFaqId) {
    wp_delete_post((int) $customerFaqId, true);
}
$customerFaqTerms = get_terms(['taxonomy' => \Bootspruefung\Learning\Faq\CustomerFaq::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']);
foreach (is_array($customerFaqTerms) ? $customerFaqTerms : [] as $customerFaqTermId) {
    wp_delete_term((int) $customerFaqTermId, \Bootspruefung\Learning\Faq\CustomerFaq::TAXONOMY);
}
if (function_exists('wp_cache_flush_group') && wp_cache_supports('flush_group')) {
    wp_cache_flush_group(\Bootspruefung\Learning\Faq\Faq::CACHE_GROUP);
    wp_cache_flush_group(\Bootspruefung\Learning\Faq\CustomerFaq::CACHE_GROUP);
}
wp_clear_scheduled_hook('bp_learning_hourly');
wp_clear_scheduled_hook(\Bootspruefung\Learning\Commerce\ExpiryReminder::HOOK);
// Geplante Erzeugung von App-Datenexporten (WP-Cron mit Argumenten bzw. Action Scheduler).
wp_unschedule_hook('bp_mobile_export_generate');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('bp_mobile_export_generate');
}
// Importierte Medien und Pakete löschen. Geschützte Kurs-PDFs bleiben im gesperrten Ordner
// uploads/bootspruefung/protected-*/ (nicht öffentlich abrufbar), damit nichts ungewollt frei wird.
$dir = \Bootspruefung\Learning\Content\Media\MediaStorage::baseDir();
foreach (['media', 'packages'] as $sub) {
    if (is_dir($dir.'/'.$sub)) {
        \Bootspruefung\Learning\Content\Import\ImportService::removeDir($dir.'/'.$sub);
    }
}
