<?php
/**
 * Deinstallation: löscht Daten nur, wenn das in den Einstellungen
 * ausdrücklich aktiviert ist (Konzept Kap. 8). Standard ist Erhalt.
 * Ablauf und Liste der Daten: Infrastructure\Uninstaller (je Website, auch Multisite).
 *
 * @package Bootspruefung\Learning
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__.'/src/Infrastructure/Autoloader.php';
\Bootspruefung\Learning\Infrastructure\Autoloader::register(__DIR__.'/src');

\Bootspruefung\Learning\Infrastructure\Uninstaller::run();
