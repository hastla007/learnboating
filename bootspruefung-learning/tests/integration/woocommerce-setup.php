<?php

declare(strict_types=1);

/*
 * Aktiviert WooCommerce in der Testinstallation (mit WOO_DEACTIVATE=1: deaktiviert).
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/woocommerce-setup.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

wp_set_current_user(1);
if (getenv('WOO_DEACTIVATE')) {
    deactivate_plugins('woocommerce/woocommerce.php');
    echo "WooCommerce deaktiviert.\n";
    exit(0);
}
$result = activate_plugin('woocommerce/woocommerce.php');
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message()."\n");
    exit(1);
}
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_enable_guest_checkout', 'yes'); // Plugin muss trotzdem ein Konto verlangen
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Überweisung']);
echo "WooCommerce aktiviert.\n";
