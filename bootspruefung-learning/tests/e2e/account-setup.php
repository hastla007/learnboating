<?php

declare(strict_types=1);

// Vorbereitung für account-flow.mjs (To-do T60; WooCommerce aktiv, nach setup.php):
//   php tests/e2e/account-setup.php            → Konten konto1/konto2, Mail-Abfang, Einstellungen; gibt JSON aus
//   php tests/e2e/account-setup.php resetkey   → Link zum Passwort-Zurücksetzen für konto1 (JSON)
//   php tests/e2e/account-setup.php state      → Stand für Prüfungen: abbestellt?, konto2 vorhanden?, Mails (JSON)
//   php tests/e2e/account-setup.php reset      → Anmeldezähler, Einstellungen, Mail-Abfang und Konten zurück
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$wpDir = rtrim((string) getenv('WP_DIR'), '/');
require $wpDir.'/wp-load.php';
require dirname(__DIR__).'/integration/support/guard.php';
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\ReminderOptOut;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Security\LoginGuard;
use Bootspruefung\Learning\Settings\Settings;

$mode = $argv[1] ?? 'prepare';
$mu = WPMU_PLUGIN_DIR.'/bp-e2e-mail.php';
$users = ['konto1' => 'Konto-Passwort-123', 'konto2' => 'Konto-Passwort-456'];
$user = static fn (string $login): ?WP_User => get_user_by('login', $login) ?: null;

// Anmeldezähler der Test-Konten und der lokalen IP leeren (alle Browser-Tests kommen von 127.0.0.1).
$clearCounters = static function () use ($users): void {
    foreach (array_keys($users) as $login) {
        foreach (['127.0.0.1', '::1'] as $ip) {
            foreach (array_keys(LoginGuard::buckets($login, $ip)) as $bucket) {
                RateLimiter::clear($bucket);
            }
            foreach (array_keys(LoginGuard::buckets($login.'@example.invalid', $ip)) as $bucket) {
                RateLimiter::clear($bucket);
            }
        }
        RateLimiter::clear('forgot-email|'.$login.'@example.invalid');
    }
    RateLimiter::clear('forgot-ip|'.RateLimiter::ipKey('127.0.0.1'));
};

if ($mode === 'reset') {
    $clearCounters();
    $saved = get_option('bp_e2e_account_settings', null);
    if (is_array($saved)) {
        update_option(Settings::OPTION, $saved);
    }
    delete_option('bp_e2e_account_settings');
    delete_option('bp_e2e_mails');
    @unlink($mu);
    foreach (array_keys($users) as $login) {
        if (($u = $user($login)) !== null) {
            wp_delete_user($u->ID);
        }
    }
    echo "Konto-Setup zurückgesetzt.\n";
    exit(0);
}

if ($mode === 'resetkey') {
    $u = $user('konto1');
    $key = get_password_reset_key($u);
    echo wp_json_encode(['url' => add_query_arg(['action' => 'rp', 'key' => $key, 'login' => rawurlencode($u->user_login)], wp_login_url())])."\n";
    exit(0);
}

if ($mode === 'state') {
    $u = $user('konto1');
    echo wp_json_encode([
        'optedOut' => $u !== null && ReminderOptOut::optedOut((int) $u->ID),
        'konto2' => $user('konto2') !== null,
        'mails' => array_values((array) get_option('bp_e2e_mails', [])),
    ])."\n";
    exit(0);
}

// Vorbereitung
if (! is_dir(WPMU_PLUGIN_DIR)) {
    wp_mkdir_p(WPMU_PLUGIN_DIR);
}
// Mails nicht verschicken, sondern merken (Bestätigungslinks für den Test). Nur in der Test-Installation.
file_put_contents($mu, <<<'PHP'
<?php
// E2E (account-flow.mjs): Mails abfangen und in bp_e2e_mails merken. Wird von account-setup.php reset entfernt.
add_filter('pre_wp_mail', static function ($short, array $atts) {
    $mails = (array) get_option('bp_e2e_mails', []);
    $mails[] = ['to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message']];
    update_option('bp_e2e_mails', array_slice($mails, -20), false);

    return true;
}, 10, 2);
PHP);
delete_option('bp_e2e_mails');
if (get_option('bp_e2e_account_settings', null) === null) {
    update_option('bp_e2e_account_settings', Settings::all(), false);
}
// Lernen ohne Kauf (Prüfung starten), Kontolöschung nach Bestätigung automatisch, Ablauf-Erinnerung an.
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'logged_in', 'privacy_requests_auto' => true, 'expiry_reminder_enabled' => true]));
update_option('woocommerce_coming_soon', 'no');
$ids = [];
foreach ($users as $login => $pass) {
    $u = $user($login);
    $id = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => $pass, 'display_name' => ucfirst($login), 'role' => 'customer']);
    wp_set_password($pass, $id);
    delete_user_meta($id, ReminderOptOut::META);
    $ids[$login] = $id;
}
$clearCounters();
// Offene Datenschutz-Anträge der Test-Konten entfernen (sonst „erneut senden“ statt neuem Antrag).
foreach (get_posts(['post_type' => 'user_request', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $rid) {
    $r = wp_get_user_request((int) $rid);
    if ($r && in_array($r->email, ['konto1@example.invalid', 'konto2@example.invalid'], true)) {
        wp_delete_post((int) $rid, true);
    }
}
echo wp_json_encode([
    'optout' => ReminderOptOut::url($ids['konto1']),
    'optoutBad' => add_query_arg([ReminderOptOut::ARG_USER => $ids['konto1'], ReminderOptOut::ARG_SIG => 'falsch'], \Bootspruefung\Learning\Frontend\Router::url(ReminderOptOut::PATH)),
    'myData' => \Bootspruefung\Learning\Privacy\SelfService::url(),
])."\n";
