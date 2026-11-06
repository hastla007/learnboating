<?php

declare(strict_types=1);

/*
 * Demo-Stand für Theme-Screenshots (CI): Produktkatalog veröffentlichen, Schnupper-Paket.
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/demo-setup.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';

wp_set_current_user(1);
switch_theme('kurswasser');
do_action('after_switch_theme', 'kurswasser');
if (class_exists(\Bootspruefung\Learning\Commerce\ShopCatalog::class) && function_exists('wc_get_product_id_by_sku')) {
    $rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts(false, true);
    \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier();
    echo count($rows)." Produkte veröffentlicht.\n";
}
update_option('blogname', 'XXXX');
echo "Demo-Stand für Kurswasser bereit.\n";
