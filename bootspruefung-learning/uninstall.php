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
(new Migrator($wpdb))->dropAll();
Capabilities::removeFromAllRoles();
delete_option(Settings::OPTION);
