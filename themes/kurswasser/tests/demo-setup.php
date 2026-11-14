<?php

declare(strict_types=1);

/*
 * Demo-Stand für Theme-Screenshots (CI): Produktkatalog veröffentlichen, Schnupper-Paket.
 * Schaltet das Theme um, legt Seiten an und ändert den Website-Titel – deshalb nur gegen eine Test-Datenbank
 * (Schutz wie bei den Integrationstests: DB_NAME muss „test“ enthalten).
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/demo-setup.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';
require dirname(__DIR__, 3).'/bootspruefung-learning/tests/integration/support/guard.php';

wp_set_current_user(1);
switch_theme('kurswasser');
kurswasser_create_pages();
kurswasser_create_course_pages();
if (class_exists(\Bootspruefung\Learning\Commerce\ShopCatalog::class) && function_exists('wc_get_product_id_by_sku')) {
    $rows = \Bootspruefung\Learning\Commerce\ShopCatalog::applyProducts(false, true);
    \Bootspruefung\Learning\Commerce\ShopCatalog::applyFreeTier();
    echo count($rows)." Produkte veröffentlicht.\n";
}
update_option('blogname', 'XXXX');
echo "Demo-Stand für Kurswasser bereit.\n";
