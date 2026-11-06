<?php

declare(strict_types=1);

// Vorbereitung für checkout-flow.mjs (WooCommerce aktiv, nach setup.php):
//   php tests/e2e/checkout-setup.php            → Produkt, Käufer, Block-Checkout; gibt JSON aus
//   php tests/e2e/checkout-setup.php classic    → klassischer Checkout (Shortcode), Zugänge des Käufers zurücksetzen
//   php tests/e2e/checkout-setup.php pay        → letzte Bestellung des Käufers als bezahlt markieren (Überweisung eingegangen)
//   php tests/e2e/checkout-setup.php reset      → Einstellung „Zugang ohne Shop“ zurück
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';

use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Settings\Settings;

global $wpdb;
wp_set_current_user(1);
$mode = $argv[1] ?? 'block';
$buyer = get_user_by('login', 'kaeufer1');
$buyerId = $buyer ? (int) $buyer->ID : (int) wp_insert_user(['user_login' => 'kaeufer1', 'user_email' => 'kaeufer1@example.invalid', 'user_pass' => 'Kauf-Passwort-123', 'display_name' => 'Karl Käufer', 'role' => 'customer']);
wp_set_password('Kauf-Passwort-123', $buyerId);

if ($mode === 'reset') {
    update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'logged_in']));
    echo "Checkout-Setup zurückgesetzt.\n";
    exit(0);
}
if ($mode === 'pay') {
    $orders = wc_get_orders(['customer_id' => $buyerId, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC']);
    $orders[0]->update_status('processing', 'Überweisung eingegangen (E2E)');
    echo 'Bestellung '.$orders[0]->get_id().' bezahlt: '.$orders[0]->get_status()."\n";
    exit(0);
}

update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
update_option('woocommerce_default_country', 'DE');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Überweisung']);
foreach (['first_name' => 'Karl', 'last_name' => 'Käufer', 'address_1' => 'Hafenstraße 1', 'city' => 'Hamburg', 'postcode' => '20457', 'country' => 'DE', 'email' => 'kaeufer1@example.invalid', 'phone' => '040123456'] as $k => $v) {
    update_user_meta($buyerId, 'billing_'.$k, $v);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d", $buyerId));
WC()->session = null;
delete_user_meta($buyerId, '_woocommerce_persistent_cart_'.get_current_blog_id());

$productId = (int) get_option('bp_e2e_product');
if (! $productId || ! wc_get_product($productId)) {
    $product = new WC_Product_Simple();
    $product->set_name('Lernpaket SBF Binnen Segel');
    $product->set_regular_price('39');
    $product->set_virtual(true);
    $product->set_status('publish');
    $productId = (int) $product->save();
    update_option('bp_e2e_product', $productId);
}
(new ProductAccessRepository($wpdb))->save($productId, 0, [['course_code' => 'SBF-BIN-SEGEL', 'feature_code' => 'question_bank'], ['course_code' => 'SBF-BIN-SEGEL', 'feature_code' => 'exam_simulation']], null, 'extend', 'revoke_on_full_refund');

// Checkout-Seite: Block (Standard neuer Installationen) oder klassischer Shortcode.
$content = $mode === 'classic' ? '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' : '<!-- wp:woocommerce/checkout /-->';
$slug = $mode === 'classic' ? 'kasse-klassisch' : 'kasse-block';
$page = get_page_by_path($slug);
$pageId = $page ? (int) $page->ID : (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $mode === 'classic' ? 'Kasse klassisch' : 'Kasse', 'post_name' => $slug, 'post_content' => $content]);
update_option('woocommerce_checkout_page_id', $pageId);
foreach (['cart' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->', 'myaccount' => '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->'] as $key => $body) {
    $id = (int) get_option('woocommerce_'.$key.'_page_id');
    if (! $id || get_post_status($id) !== 'publish') {
        $id = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $key === 'cart' ? 'Warenkorb' : 'Mein Konto', 'post_name' => $key === 'cart' ? 'warenkorb' : 'mein-konto', 'post_content' => $body]);
        update_option('woocommerce_'.$key.'_page_id', $id);
    }
}
delete_option('bp_learning_rewrite_version');
flush_rewrite_rules(false);
echo wp_json_encode(['product' => $productId, 'checkout' => get_permalink($pageId), 'account' => wc_get_account_endpoint_url('mein-lernen')]), "\n";
