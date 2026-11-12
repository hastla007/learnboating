<?php
/**
 * Plugin Name:       LearnBoating Partnerprogramm-Connector
 * Description:       Verbindet die Bootsprüfung-Lernplattform mit WP Affiliate Captain: Partnercode aus der App bis in den Shop, Ausschluss von Gruppenpaketen und Upgrades, Haltefrist aus Geld-zurück-Garantie und Widerrufsfrist.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  bootspruefung-learning, wp-affiliate-captain
 * Text Domain:       learnboating-affiliate-connector
 * License:           proprietary
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LBAC_VERSION', '1.0.0');
define('LBAC_FILE', __FILE__);

require_once __DIR__.'/src/Connector.php';

add_action('plugins_loaded', [\LearnBoating\AffiliateConnector\Connector::class, 'boot'], 20);
