<?php

declare(strict_types=1);

/*
 * Sperren und Limits des Kursgutschein-Connectors (T83, T88) – läuft auch ohne Voucher Captain (nur die
 * Lernplattform muss aktiv sein): Sperre je Gutschein (GET_LOCK) und Einlöse-Limit der Einlöseseite je IP und
 * je Konto (Zähler der Lernplattform, IPv6 je /64-Netz).
 *
 *   WP_DIR=/pfad/zu/wordpress php learnboating-voucher-connector/tests/locks-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 2).'/bootspruefung-learning/tests/integration/support/guard.php';
if (! class_exists(\LearnBoating\VoucherConnector\Connector::class)) {
    require_once dirname(__DIR__).'/src/Connector.php';
}

use LearnBoating\VoucherConnector\Connector;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;

echo "Sperre je Gutschein (T83)\n";
$id = 900000 + random_int(1, 99999);
$name = Connector::lockName('voucher:'.$id);
$check(strlen($name) <= 64 && $name !== Connector::lockName('voucher:'.($id + 1)), 'Sperrname kurz genug und je Gutschein verschieden');
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); // parallele Anfrage
$check((int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)) === 1, 'parallele Anfrage hält die Sperre');
$t = microtime(true);
$check(! Connector::lockVoucher($id, 0) && microtime(true) - $t < 2, 'zweiter Versuch: Sperre belegt, sofort abgewiesen');
$wait = static fn () => 1;
add_filter('lbvc_lock_timeout', $wait);
$t = microtime(true);
$check(! Connector::lockVoucher($id, 10) && microtime(true) - $t >= 0.9, 'Wartezeit über den Filter lbvc_lock_timeout');
remove_filter('lbvc_lock_timeout', $wait);
$other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
$check(Connector::lockVoucher($id, 0), 'nach Freigabe: Sperre erhalten');
$check((int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)) === 0, 'gehaltene Sperre blockiert andere Verbindungen');
Connector::unlockVoucher($id);
$check((int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)) === 1, 'unlockVoucher gibt sie frei');
$other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
$other->close();

echo "Einlöseseite: Limit je IP und je Konto (T88)\n";
$table = $wpdb->prefix.'bp_mobile_rate_limit';
$hash = static fn (string $bucket): string => hash('sha256', $bucket.'|'.wp_salt('nonce'));
$userId = 990000 + random_int(1, 9999);
$clear = static function () use ($wpdb, $table, $hash, $userId): void {
    foreach (['lbvc-redeem-ip|203.0.113.201', 'lbvc-redeem-ip|203.0.113.202', 'lbvc-redeem-ip|2001:db8:7:8::/64', 'redeem|'.$userId] as $bucket) {
        $wpdb->delete($table, ['bucket_hash' => $hash($bucket)]);
    }
};
$clear();
$perIp = static fn () => 3;
add_filter('lbvc_redeem_per_ip', $perIp);
$_SERVER['REMOTE_ADDR'] = '203.0.113.201';
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = Connector::redeemLimited(0);
}
$check($results === [false, false, false, true], 'Gast: nach 3 Versuchen je IP gesperrt');
$_SERVER['REMOTE_ADDR'] = '2001:db8:7:8::1';
Connector::redeemLimited(0);
Connector::redeemLimited(0);
Connector::redeemLimited(0);
$_SERVER['REMOTE_ADDR'] = '2001:db8:7:8:ffff::2';
$check(Connector::redeemLimited(0), 'IPv6: andere Adresse im selben /64-Netz zählt mit');
remove_filter('lbvc_redeem_per_ip', $perIp);
$_SERVER['REMOTE_ADDR'] = '203.0.113.202';
$results = [];
for ($i = 0; $i < Connector::REDEEM_PER_USER + 1; $i++) {
    $results[] = Connector::redeemLimited($userId);
}
$check(! in_array(true, array_slice($results, 0, Connector::REDEEM_PER_USER), true) && end($results) === true, 'Konto: nach '.Connector::REDEEM_PER_USER.' Versuchen je Stunde gesperrt (gleicher Zähler wie App und „Mein Lernen“)');
$clear();
unset($_SERVER['REMOTE_ADDR']);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
