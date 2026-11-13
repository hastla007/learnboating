<?php
/**
 * Plugin Name:       LearnBoating Partnerprogramm-Connector
 * Description:       Verbindet die Bootsprüfung-Lernplattform mit WP Affiliate Captain: Partnercode aus der App bis in den Shop, Ausschluss von Gruppenpaketen und Upgrades, Haltefrist aus Geld-zurück-Garantie und Widerrufsfrist.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  bootspruefung-learning, wp-affiliate-captain
 * Author:            LearnBoating
 * Update URI:        false
 * Text Domain:       learnboating-affiliate-connector
 * Domain Path:       /languages
 * License:           proprietary
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LBAC_VERSION', '1.1.0');
define('LBAC_FILE', __FILE__);

require_once __DIR__.'/src/Connector.php';

add_action('plugins_loaded', [\LearnBoating\AffiliateConnector\Connector::class, 'boot'], 20);

// Übersetzungen (languages/learnboating-affiliate-connector-<locale>.mo bzw. wp-content/languages/plugins/).
add_action('init', static function (): void {
    load_plugin_textdomain('learnboating-affiliate-connector', false, dirname(plugin_basename(__FILE__)).'/languages');
});
