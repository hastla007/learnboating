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
// Geschützte Mediathek-Dateien vorher an ihren Ort zurücklegen (die Dateien gehören dem Betreiber).
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => \Bootspruefung\Learning\Gate\ProtectedFiles::PATH_META]) as $attachmentId) {
    \Bootspruefung\Learning\Gate\ProtectedFiles::unprotect((int) $attachmentId);
}
(new Migrator($wpdb))->dropAll();
Capabilities::removeFromAllRoles();
delete_option(Settings::OPTION);

// Eigene Optionen, Zwischenspeicher und Tipps.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bp\\_learning\\_%' OR option_name LIKE '\\_transient\\_bp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bp\\_%'");
foreach (get_posts(['post_type' => \Bootspruefung\Learning\Tips\Tips::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $tipId) {
    wp_delete_post((int) $tipId, true);
}
wp_clear_scheduled_hook('bp_learning_hourly');
// Importierte Medien und Pakete (uploads/bootspruefung/).
$dir = \Bootspruefung\Learning\Content\Media\MediaStorage::baseDir();
if (is_dir($dir)) {
    \Bootspruefung\Learning\Content\Import\ImportService::removeDir($dir);
}
