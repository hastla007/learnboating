<?php

declare(strict_types=1);

/*
 * Sperren und Limits des Kursgutschein-Connectors (T83, T88, T109, T115, T122) – läuft auch ohne Voucher Captain
 * (nur die Lernplattform muss aktiv sein): Sperre je Gutschein (GET_LOCK), Warteschlange nie ohne Sperre geschrieben
 * (belegt → per WP-Cron nachgeholt, NULL → ohne Sperre), Einlöse-Limit der Einlöseseite je IP (gemeinsamer Zähler
 * mit App und „Mein Lernen“) und je Konto (Zähler der Lernplattform, IPv6 je /64-Netz), keine Übersetzung beim Start.
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
$check(Connector::lockName('queue') === 'lbvc:'.substr(hash('sha256', DB_NAME.'|'.$wpdb->prefix.'|queue'), 0, 40), 'Sperrname enthält Datenbank und Präfix (zwei Installationen auf einem Server sperren sich nicht gegenseitig)');

echo "Warteschlange nie ohne Sperre schreiben (T115)\n";
$queueBefore = get_option(Connector::PENDING_OPTION, null);
$qKey = 'learnboating:grant:test-'.$id;
$qData = ['voucher_id' => $id, 'user_id' => 1, 'product_id' => 1, 'variation_id' => 0];
$queueHas = static function (string $key): bool {
    wp_cache_delete(Connector::PENDING_OPTION, 'options');

    return isset(((array) get_option(Connector::PENDING_OPTION, []))[$key]);
};
$queueLock = Connector::lockName('queue');
$check((int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $queueLock)) === 1, 'parallele Anfrage hält die Sperre der Warteschlange');
$noWait = static fn () => 0;
add_filter('lbvc_lock_timeout', $noWait);
$written = Connector::editQueue('add', $qKey, $qData);
$scheduled = wp_next_scheduled(Connector::QUEUE_HOOK, ['add', $qKey, $qData, 1]);
$check($written === false && ! $queueHas($qKey) && $scheduled !== false && $scheduled - time() >= 50 && $scheduled - time() <= 70, 'Sperre belegt: nichts geschrieben, Änderung in 60 Sekunden erneut eingeplant');
$last = Connector::editQueue('add', $qKey, $qData, Connector::QUEUE_ATTEMPTS - 1);
$check($last === false && ! $queueHas($qKey) && wp_next_scheduled(Connector::QUEUE_HOOK, ['add', $qKey, $qData, Connector::QUEUE_ATTEMPTS]) === false, 'letzter Versuch belegt: verworfen und protokolliert, nicht endlos neu eingeplant');
remove_filter('lbvc_lock_timeout', $noWait);
$other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $queueLock));
Connector::deferredQueueEdit('add', $qKey, $qData, 1);
$check($queueHas($qKey), 'nachgeholt (WP-Cron): Eintrag unter der Sperre geschrieben');
wp_clear_scheduled_hook(Connector::QUEUE_HOOK, ['add', $qKey, $qData, 1]);
$check((int) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $queueLock)) === 1, 'Sperre der Warteschlange danach wieder frei');
$other->query($other->prepare('SELECT RELEASE_LOCK(%s)', $queueLock));
// GET_LOCK nicht verfügbar (NULL): nur dann ohne Sperre schreiben; HyperDB/LudicrousDB: vorher an den Hauptserver.
$realDb = $GLOBALS['wpdb'];
$noLockDb = new class (DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
    public int $toMaster = 0;
    public function send_reads_to_masters(): void { $this->toMaster++; }
    public function get_var($query = null, $x = 0, $y = 0) { return is_string($query) && str_contains($query, 'GET_LOCK(') ? null : parent::get_var($query, $x, $y); }
};
$noLockDb->set_prefix($realDb->base_prefix);
$GLOBALS['wpdb'] = $noLockDb;
$nullWritten = Connector::editQueue('done', $qKey);
$nullVoucher = Connector::lockVoucher($id, 0);
$GLOBALS['wpdb'] = $realDb;
$check($nullWritten === true && ! $queueHas($qKey) && $nullVoucher, 'GET_LOCK nicht verfügbar (NULL): ohne Sperre weiter (Warteschlange geschrieben, Gutschein bearbeitbar)');
$check($noLockDb->toMaster >= 2, 'HyperDB/LudicrousDB: send_reads_to_masters() vor GET_LOCK');
$noLockDb->close();
$queueBefore === null ? delete_option(Connector::PENDING_OPTION) : update_option(Connector::PENDING_OPTION, $queueBefore, false);
$other->close();

echo "Einlöseseite: Limit je IP und je Konto (T88)\n";
$table = $wpdb->prefix.'bp_mobile_rate_limit';
$hash = static fn (string $bucket): string => hash('sha256', $bucket.'|'.wp_salt('nonce'));
$userId = 990000 + random_int(1, 9999);
$clear = static function () use ($wpdb, $table, $hash, $userId): void {
    foreach (['lbvc-redeem-ip|203.0.113.201', 'lbvc-redeem-ip|203.0.113.202', 'lbvc-redeem-ip|203.0.113.203', 'lbvc-redeem-ip|2001:db8:7:8::/64', 'redeem|'.$userId] as $bucket) {
        $wpdb->delete($table, ['bucket_hash' => $hash($bucket)]);
    }
};
$clear();
$perIp = static fn () => 3;
add_filter('bootspruefung_voucher_redeem_per_ip', $perIp);
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
// T109: ein Zähler für alle Wege – App/„Mein Lernen“ (Vouchers::hitIpLimit()) und Einlöseseite zählen gemeinsam.
$_SERVER['REMOTE_ADDR'] = '203.0.113.203';
$shared = [];
foreach (['app', 'page', 'app', 'page'] as $way) {
    if ($way === 'app') {
        try {
            \Bootspruefung\Learning\Extensions\Vouchers::hitIpLimit();
            $shared[] = false;
        } catch (\Bootspruefung\Learning\Mobile\ApiException) {
            $shared[] = true;
        }
    } else {
        $shared[] = Connector::redeemLimited(0);
    }
}
$check($shared === [false, false, false, true], 'gemeinsamer IP-Zähler: App/„Mein Lernen“ und Einlöseseite zusammen höchstens 3 (Filter bootspruefung_voucher_redeem_per_ip)');
remove_filter('bootspruefung_voucher_redeem_per_ip', $perIp);
$_SERVER['REMOTE_ADDR'] = '203.0.113.202';
$results = [];
for ($i = 0; $i < Connector::REDEEM_PER_USER + 1; $i++) {
    $results[] = Connector::redeemLimited($userId);
}
$check(! in_array(true, array_slice($results, 0, Connector::REDEEM_PER_USER), true) && end($results) === true, 'Konto: nach '.Connector::REDEEM_PER_USER.' Versuchen je Stunde gesperrt (gleicher Zähler wie App und „Mein Lernen“)');
$clear();
unset($_SERVER['REMOTE_ADDR']);

echo "Keine Übersetzung beim Start (T122)\n";
if (Connector::instance() === null) {
    $translated = 0;
    $count = static function ($t) use (&$translated) {
        $translated++;

        return $t;
    };
    add_filter('gettext_learnboating-voucher-connector', $count);
    Connector::boot(); // ohne Voucher Captain: kontrolliert inaktiv, Hinweistext erst in admin_notices
    remove_filter('gettext_learnboating-voucher-connector', $count);
    $check($translated === 0 && Connector::instance() === null, 'boot() (plugins_loaded, vor init) übersetzt nichts');
} else {
    echo "  –    übersprungen (Voucher Captain aktiv; boot() würde die Hooks doppelt registrieren)\n";
}

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
