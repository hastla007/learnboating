<?php
/**
 * Plugin Name:       LearnBoating Kursgutschein-Connector
 * Description:       Verbindet die Bootsprüfung-Lernplattform mit Voucher Captain: Kursgutscheine im Shop verkaufen und in „Mein Lernen“ (App und Einlöseseite) einlösen. Reserviert den Gutschein, schaltet den Kurs über die Produktzuordnung „Lernzugang“ frei und verbucht erst danach.
 * Version:           1.0.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  bootspruefung-learning, voucher-captain
 * Author:            LearnBoating
 * Update URI:        false
 * Text Domain:       learnboating-voucher-connector
 * Domain Path:       /languages
 * License:           proprietary
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LBVC_VERSION', '1.0.1');
define('LBVC_FILE', __FILE__);

require_once __DIR__.'/src/Connector.php';

add_action('plugins_loaded', [\LearnBoating\VoucherConnector\Connector::class, 'boot'], 30);
register_deactivation_hook(__FILE__, [\LearnBoating\VoucherConnector\Connector::class, 'deactivate']);

// Übersetzungen (languages/learnboating-voucher-connector-<locale>.mo bzw. wp-content/languages/plugins/).
add_action('init', static function (): void {
    load_plugin_textdomain('learnboating-voucher-connector', false, dirname(plugin_basename(__FILE__)).'/languages');
});
