<?php

declare(strict_types=1);

// Vorbereitung für membership-flow.mjs (nach setup.php):
//   WP_DIR=… php tests/e2e/membership-setup.php          → Teilumfang + Gruppe, gibt den Gruppenlink-Token aus
//   WP_DIR=… php tests/e2e/membership-setup.php reset    → Regeln, Gruppe und Einstellung zurücksetzen
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim((string) getenv('WP_DIR'), '/').'/wp-load.php';
require_once dirname(__DIR__).'/integration/support/FakeOrders.php';

use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSync;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Settings\Settings;

global $wpdb;
$p = $wpdb->prefix.'bp_';
wp_set_current_user(1);
$learner = get_user_by('login', 'lerner1');
$owner = get_user_by('login', 'schule1');
$ownerId = $owner ? (int) $owner->ID : (int) wp_insert_user(['user_login' => 'schule1', 'user_email' => 'schule1@example.invalid', 'user_pass' => 'Schul-Passwort-123', 'display_name' => 'Bootsschule Test', 'role' => 'subscriber']);
wp_set_password('Schul-Passwort-123', $ownerId);

$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code = 'SBF-SEE' AND access_type = 'free_limited'");
$wpdb->query("DELETE FROM {$p}learning_group WHERE order_id = 990777");
$wpdb->query($wpdb->prepare("DELETE FROM {$p}entitlement WHERE user_id = %d OR order_id = 990777", $learner->ID));
$wpdb->query("DELETE FROM {$p}product_access WHERE product_id = 990777");
$settings = Settings::all();
if (($argv[1] ?? '') === 'reset') {
    update_option(Settings::OPTION, array_merge($settings, ['default_access' => 'logged_in']));
    echo "Membership-Setup zurückgesetzt.\n";
    exit(0);
}
update_option(Settings::OPTION, array_merge($settings, ['default_access' => 'entitled_only']));
$now = current_time('mysql', true);
foreach ([['question_bank', 'questions_per_module', 3], ['exam_simulation', 'exam_papers', 2]] as [$feature, $type, $n]) {
    $wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => $feature, 'access_type' => 'free_limited', 'limit_type' => $type, 'limit_value' => $n, 'created_at' => $now, 'updated_at' => $now]);
}
$products = new ProductAccessRepository($wpdb);
$products->save(990777, 0, [['course_code' => 'SBF-BIN-MOTOR', 'feature_code' => 'all']], null, 'extend', 'revoke_on_full_refund', 5);
$orders = new \Bootspruefung\Learning\Tests\FakeOrders();
$orders->put(990777, $ownerId, 'completed', $now, [new OrderItemSnapshot(990777, 990777, 0, 1, 'SCHULE-5', 250.0)]);
$groups = new GroupService($wpdb);
(new OrderSync($orders, $products, new EntitlementService($wpdb), new EntitlementPlanner(), $groups))->sync(990777);
echo $groups->forOrder(990777)[0]->join_token, "\n";
