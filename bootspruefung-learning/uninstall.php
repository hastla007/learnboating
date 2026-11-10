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

// Eigene Optionen, Zwischenspeicher und Tipps.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bp\\_learning\\_%' OR option_name LIKE '\\_transient\\_bp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_%'");
foreach (get_posts(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $tipId) {
    wp_delete_post((int) $tipId, true);
}
wp_clear_scheduled_hook('bp_learning_hourly');
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
