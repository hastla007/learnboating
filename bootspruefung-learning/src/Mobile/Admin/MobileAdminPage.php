<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Admin;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Mobile\AppLinks;
use Bootspruefung\Learning\Mobile\MobileApi;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\TokenAuth;

/**
 * Adminseite „Bootsprüfung → Mobile App“: App ein/aus, Tenant-Slug, Marke, vertrauenswürdige
 * Proxys, Sitzungsübergabe an die Webansicht, Universal Links/App Links (Passwort-Reset),
 * Server-Prüfung (Authorization-Header) und Sitzungen eines Kunden widerrufen.
 * Nur mit manage_options und Nonce.
 */
final class MobileAdminPage
{
    public const SLUG = 'bootspruefung-mobile';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_bp_mobile_save', [$this, 'save']);
        add_action('admin_post_bp_mobile_revoke', [$this, 'revoke']);
        add_action('admin_post_bp_mobile_check', [$this, 'check']);
    }

    public function menu(): void
    {
        add_submenu_page('bootspruefung', __('Mobile App', 'bootspruefung-learning'), __('Mobile App', 'bootspruefung-learning'), 'manage_options', self::SLUG, [$this, 'render']);
    }

    private function guard(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    private function back(array $args): void
    {
        wp_safe_redirect(add_query_arg($args + ['page' => self::SLUG], admin_url('admin.php')));
        exit;
    }

    public function save(): void
    {
        $this->guard('bp_mobile_save');
        $input = isset($_POST['bp_mobile']) && is_array($_POST['bp_mobile']) ? wp_unslash($_POST['bp_mobile']) : [];
        MobileSettings::save($input);
        AuditLog::record('mobile_settings_saved', 'settings', MobileSettings::OPTION);
        $this->back(['bp_notice' => 'saved']);
    }

    public function revoke(): void
    {
        $this->guard('bp_mobile_revoke');
        $needle = trim(sanitize_text_field(wp_unslash((string) ($_POST['customer'] ?? ''))));
        $user = is_email($needle) ? get_user_by('email', $needle) : get_user_by('login', $needle);
        if (! $user) {
            $this->back(['bp_notice' => 'unknown']);
        }
        $count = TokenAuth::revokeAll((int) $user->ID, 'admin');
        AuditLog::record('mobile_sessions_revoked', 'user', (string) $user->ID, null, ['count' => $count]);
        $this->back(['bp_notice' => 'revoked', 'bp_count' => $count]);
    }

    /** Loopback: kommt der Authorization-Header bei PHP an? */
    public function check(): void
    {
        $this->guard('bp_mobile_check');
        $response = wp_remote_get(rest_url(MobileApi::NAMESPACE.'/health'), ['timeout' => 10, 'headers' => ['Authorization' => 'Bearer bp-header-check-0000000000000000']]);
        if (is_wp_error($response)) {
            $this->back(['bp_notice' => 'check_failed']);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $ok = ! empty($body['data']['authorization_header_received']);
        $this->back(['bp_notice' => $ok ? 'check_ok' : 'check_header']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'));
        }
        $s = MobileSettings::all();
        $notices = [
            'saved' => ['success', __('Einstellungen gespeichert.', 'bootspruefung-learning')],
            'unknown' => ['error', __('Kein Kundenkonto mit dieser E-Mail-Adresse bzw. diesem Benutzernamen gefunden.', 'bootspruefung-learning')],
            'revoked' => ['success', sprintf(__('%d App-Sitzung(en) widerrufen.', 'bootspruefung-learning'), (int) ($_GET['bp_count'] ?? 0))], // phpcs:ignore WordPress.Security.NonceVerification
            'check_ok' => ['success', __('Server-Prüfung erfolgreich: Der Authorization-Header kommt bei PHP an.', 'bootspruefung-learning')],
            'check_header' => ['error', __('Der Authorization-Header kommt nicht bei PHP an. Bitte den Webserver anpassen: Apache „CGIPassAuth On“ bzw. die Umschreibregel für HTTP_AUTHORIZATION in der .htaccess, nginx mit PHP-FPM „fastcgi_param HTTP_AUTHORIZATION $http_authorization;“.', 'bootspruefung-learning')],
            'check_failed' => ['warning', __('Die Server-Prüfung konnte die eigene Website nicht erreichen (Loopback). Bitte mit curl von außen prüfen.', 'bootspruefung-learning')],
        ];
        $notice = sanitize_key((string) ($_GET['bp_notice'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="wrap"><h1>'.esc_html__('Bootsprüfung – Mobile App', 'bootspruefung-learning').'</h1>';
        if (isset($notices[$notice])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$notice][0]), esc_html($notices[$notice][1]));
        }
        printf(
            '<p>%s <code>%s</code></p><p>%s <strong>%d</strong></p>',
            esc_html__('Basisadresse der App-Schnittstelle:', 'bootspruefung-learning'),
            esc_html(rest_url(MobileApi::NAMESPACE)),
            esc_html__('Aktive App-Sitzungen:', 'bootspruefung-learning'),
            TokenAuth::activeCount()
        );

        echo '<h2>'.esc_html__('Einstellungen', 'bootspruefung-learning').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_mobile_save');
        echo '<input type="hidden" name="action" value="bp_mobile_save"><table class="form-table"><tbody>';
        printf('<tr><th scope="row">%s</th><td><label><input type="checkbox" name="bp_mobile[enabled]" value="1" %s> %s</label></td></tr>', esc_html__('App-Schnittstelle', 'bootspruefung-learning'), checked(! empty($s['enabled']), true, false), esc_html__('Aktiv (aus: die App zeigt einen Wartungshinweis, Web-Lernbereich bleibt unverändert)', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-tenant">%s</label></th><td><input type="text" id="bp-mobile-tenant" class="regular-text" name="bp_mobile[tenant_slug]" value="%s" placeholder="%s" pattern="[a-z0-9][a-z0-9-]{1,62}"><p class="description">%s</p></td></tr>', esc_html__('App-Kennung', 'bootspruefung-learning'), esc_attr((string) $s['tenant_slug']), esc_attr(MobileSettings::tenantSlug()), esc_html__('Kennung dieser Bootsschule für die App (Header X-Tenant-Slug). Leer = aus dem Markennamen. Nach dem Start der App nicht mehr ändern.', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-color">%s</label></th><td><input type="text" id="bp-mobile-color" name="bp_mobile[primary_color]" value="%s" placeholder="#005FD7" pattern="#[0-9A-Fa-f]{6}"><p class="description">%s</p></td></tr>', esc_html__('Markenfarbe', 'bootspruefung-learning'), esc_attr((string) $s['primary_color']), esc_html__('Hex-Farbe für die App (leer = App-Standard). Logo: Website-Icon bzw. Theme-Logo.', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-support">%s</label></th><td><input type="email" id="bp-mobile-support" class="regular-text" name="bp_mobile[support_email]" value="%s" placeholder="%s"></td></tr>', esc_html__('Support-E-Mail', 'bootspruefung-learning'), esc_attr((string) $s['support_email']), esc_attr((string) MobileSettings::supportEmail()));
        if (($proxyWarning = \Bootspruefung\Learning\Mobile\RateLimiter::proxyWarning()) !== null) {
            printf('<tr><td colspan="2"><div class="notice notice-warning inline"><p>%s</p></div></td></tr>', esc_html($proxyWarning));
        }
        printf('<tr><th scope="row"><label for="bp-mobile-proxies">%s</label></th><td><textarea id="bp-mobile-proxies" class="large-text code" rows="3" name="bp_mobile[trusted_proxies]">%s</textarea><p class="description">%s</p></td></tr>', esc_html__('Vertrauenswürdige Proxys', 'bootspruefung-learning'), esc_textarea((string) $s['trusted_proxies']), esc_html__('IP-Adressen oder Netze (CIDR) der Reverse-Proxys/Load-Balancer/CDNs direkt vor WordPress, deren X-Forwarded-For für Anmelde-Limits gilt. Leer (Standard) = keinem Proxy vertrauen, es zählt nur die direkte Gegenstelle (REMOTE_ADDR) – auch Loopback und private Netze gelten nicht automatisch. Hinter einem Proxy (z. B. Docker mit Caddy/nginx davor) dessen Adresse bzw. Netz eintragen, sonst teilen sich alle Kunden die Limits der Proxy-IP.', 'bootspruefung-learning'));
        printf('<tr><th scope="row">%s</th><td><input type="hidden" name="bp_mobile[web_session_enabled]" value="0"><label><input type="checkbox" name="bp_mobile[web_session_enabled]" value="1" %s> %s</label><p class="description">%s</p></td></tr>', esc_html__('Anmeldung in der Webansicht', 'bootspruefung-learning'), checked(! empty($s['web_session_enabled']), true, false), esc_html__('Erlaubt (Standard)', 'bootspruefung-learning'), esc_html__('Die App darf Kundinnen und Kunden mit einem 60 Sekunden gültigen Einmal-Link im Web-Lernbereich anmelden (z. B. Funkgerät-Simulator in der Tablet-App). Nur Seiten unter dem Lernbereich; nie für Konten mit Verwaltungsrechten.', 'bootspruefung-learning'));
        echo '</tbody></table>';

        echo '<h3>'.esc_html__('Reset-Link öffnet die App (Universal Links / App Links)', 'bootspruefung-learning').'</h3>';
        printf('<p class="description">%s</p>', esc_html(sprintf(
            /* translators: 1: App-Link-Pfad, 2: Adresse apple-app-site-association, 3: Adresse assetlinks.json */
            __('Leer = aus. Mit Einträgen zeigt der Link der Passwort-Mail, die aus der App angefordert wird, auf %1$s (öffnet die App; ohne App leitet die Website auf die normale Seite zum Passwortsetzen weiter). Die Website liefert dann %2$s und %3$s aus. Die Werte stellt das App-Entwicklerteam bereit.', 'bootspruefung-learning'),
            AppLinks::resetUrl(),
            home_url('/.well-known/apple-app-site-association'),
            home_url('/.well-known/assetlinks.json')
        )));
        echo '<table class="form-table"><tbody>';
        printf('<tr><th scope="row"><label for="bp-mobile-ios">%s</label></th><td><textarea id="bp-mobile-ios" class="large-text code" rows="2" name="bp_mobile[ios_app_ids]" placeholder="ABCDE12345.at.example.bootsschule">%s</textarea><p class="description">%s</p></td></tr>', esc_html__('iOS-App-IDs', 'bootspruefung-learning'), esc_textarea((string) $s['ios_app_ids']), esc_html__('Je Zeile Team-ID und Bundle-ID (TEAMID.bundle.id) aus dem Apple-Developer-Konto.', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-android">%s</label></th><td><input type="text" id="bp-mobile-android" class="regular-text code" name="bp_mobile[android_package]" value="%s" placeholder="at.example.bootsschule"></td></tr>', esc_html__('Android-Paketname', 'bootspruefung-learning'), esc_attr((string) $s['android_package']));
        foreach (['ios' => __('Mindestversion App (iOS)', 'bootspruefung-learning'), 'android' => __('Mindestversion App (Android)', 'bootspruefung-learning')] as $platform => $label) {
            printf('<tr><th scope="row"><label for="bp-mobile-min-%1$s">%2$s</label></th><td><input type="text" id="bp-mobile-min-%1$s" class="small-text code" name="bp_mobile[min_app_version_%1$s]" value="%3$s" placeholder="1.0.0"><p class="description">%4$s</p></td></tr>', esc_attr($platform), esc_html($label), esc_attr((string) $s['min_app_version_'.$platform]), esc_html__('Ältere App-Versionen werden zum Update aufgefordert (Fehler 426 APP_UPDATE_REQUIRED). Leer = keine Vorgabe.', 'bootspruefung-learning'));
        }
        printf('<tr><th scope="row"><label for="bp-mobile-appstore">%s</label></th><td><input type="url" id="bp-mobile-appstore" class="large-text code" name="bp_mobile[app_store_url]" value="%s" placeholder="https://apps.apple.com/de/app/…/id123456789"><p class="description">%s</p></td></tr>', esc_html__('App Store (Link)', 'bootspruefung-learning'), esc_attr((string) $s['app_store_url']), esc_html__('Adresse des App-Eintrags mit der App-ID (…/id123456789). Die App verlinkt beim Pflicht-Update darauf; Safari zeigt auf der Website das App-Banner. Leer = App noch nicht im Store.', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-playstore">%s</label></th><td><input type="url" id="bp-mobile-playstore" class="large-text code" name="bp_mobile[play_store_url]" value="%s" placeholder="https://play.google.com/store/apps/details?id=at.example.bootsschule"><p class="description">%s</p></td></tr>', esc_html__('Google Play (Link)', 'bootspruefung-learning'), esc_attr((string) $s['play_store_url']), esc_html__('Adresse des App-Eintrags mit dem Paketnamen (?id=…). Die App verlinkt beim Pflicht-Update darauf. Leer = App noch nicht im Store.', 'bootspruefung-learning'));
        printf('<tr><th scope="row"><label for="bp-mobile-fp">%s</label></th><td><textarea id="bp-mobile-fp" class="large-text code" rows="2" name="bp_mobile[android_cert_fingerprints]">%s</textarea><p class="description">%s</p></td></tr>', esc_html__('Android-Zertifikat (SHA-256)', 'bootspruefung-learning'), esc_textarea((string) $s['android_cert_fingerprints']), esc_html__('Je Zeile ein SHA-256-Fingerabdruck des Signaturzertifikats (AA:BB:…, 32 Bytes) – bei Google Play der Schlüssel aus „App-Signatur“, ggf. zusätzlich der Upload-/Debug-Schlüssel.', 'bootspruefung-learning'));
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        echo '<h2>'.esc_html__('Server prüfen', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_mobile_check');
        echo '<input type="hidden" name="action" value="bp_mobile_check"><p>'.esc_html__('Prüft per Aufruf der eigenen Website, ob der Authorization-Header (Bearer-Token) bei PHP ankommt.', 'bootspruefung-learning').'</p>';
        submit_button(__('Server prüfen', 'bootspruefung-learning'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<h2>'.esc_html__('App-Sitzungen eines Kunden widerrufen', 'bootspruefung-learning').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_mobile_revoke');
        echo '<input type="hidden" name="action" value="bp_mobile_revoke"><p><label>'.esc_html__('E-Mail-Adresse oder Benutzername', 'bootspruefung-learning').' <input type="text" name="customer" class="regular-text" required></label></p>';
        submit_button(__('Alle App-Sitzungen beenden', 'bootspruefung-learning'), 'delete', 'submit', false, ['data-bp-confirm' => __('Alle App-Sitzungen dieses Kunden beenden?', 'bootspruefung-learning')]);
        echo '</form></div>';
    }
}
