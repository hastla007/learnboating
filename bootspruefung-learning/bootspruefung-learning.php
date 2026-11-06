<?php
/**
 * Plugin Name:       Bootsprüfung Lernplattform
 * Description:       Lernplattform für Bootsführerscheine (SBF, SRC, UBI, SKN, FKN) mit Prüfungssimulation, Spezialtrainern und WooCommerce-Freischaltung.
 * Version:           0.10.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            hastla007
 * Text Domain:       bootspruefung-learning
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:     11.3
 *
 * @package Bootspruefung\Learning
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('BP_LEARNING_FILE', __FILE__);
define('BP_LEARNING_DIR', __DIR__);

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'.esc_html__('Bootsprüfung Lernplattform benötigt PHP 8.1 oder neuer.', 'bootspruefung-learning').'</p></div>';
    });

    return;
}

if (is_file(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
} else {
    require_once __DIR__.'/src/Infrastructure/Autoloader.php';
    \Bootspruefung\Learning\Infrastructure\Autoloader::register(__DIR__.'/src');
}

register_activation_hook(__FILE__, [\Bootspruefung\Learning\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Bootspruefung\Learning\Plugin::class, 'deactivate']);

\Bootspruefung\Learning\Plugin::boot(__FILE__);
