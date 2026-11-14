<?php

declare(strict_types=1);

/*
 * Aktiviert Voucher Captain und den Kursgutschein-Connector in der Testinstallation (WooCommerce und die
 * Lernplattform müssen schon aktiv sein). Eigener Prozess, damit beide Plugins beim nächsten Aufruf laden.
 *
 *   WP_DIR=/pfad/zu/wordpress php learnboating-voucher-connector/tests/setup.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 2).'/bootspruefung-learning/tests/integration/support/guard.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';

wp_set_current_user(1);
if (($argv[1] ?? '') === 'deactivate') {
    deactivate_plugins(['learnboating-voucher-connector/learnboating-voucher-connector.php', 'voucher-captain/voucher-captain.php']);
    echo "Voucher Captain und Connector deaktiviert.\n";
    exit(0);
}
foreach (['bootspruefung-learning/bootspruefung-learning.php', 'voucher-captain/voucher-captain.php', 'learnboating-voucher-connector/learnboating-voucher-connector.php'] as $plugin) {
    $result = is_plugin_active($plugin) ? null : activate_plugin($plugin);
    if (is_wp_error($result)) {
        fwrite(STDERR, $plugin.': '.$result->get_error_message()."\n");
        exit(1);
    }
    echo "aktiv: {$plugin}\n";
}
