<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Security;

use Bootspruefung\Learning\Content\Media\MediaStorage;

/**
 * Härtungen für die Lernplattform. Jede Maßnahme ist über den Filter bootspruefung_hardening
 * einzeln abschaltbar (z. B. wenn ein eigenes Sicherheits-Plugin dasselbe übernimmt):
 *
 *   add_filter('bootspruefung_hardening', fn ($on, $feature) => $feature === 'xmlrpc_off' ? false : $on, 10, 2);
 *
 * Merkmale:
 *   login_limit      Fehlversuche bei wp-login, WooCommerce-Login, XML-RPC begrenzen (LoginGuard);
 *                    standardmäßig aus, wenn ein bekanntes Plugin mit eigener Login-Sperre aktiv ist
 *   login_errors     neutrale Meldung bei falschem Benutzer bzw. Passwort
 *   lost_password    „Passwort vergessen“: gleiche Bestätigung für unbekannte Konten, Mails begrenzt
 *   xmlrpc_off       XML-RPC aus (standardmäßig an, außer Jetpack ist aktiv – das braucht XML-RPC)
 *   user_sitemap     keine Benutzer-Sitemap (wp-sitemap-users-*.xml verrät Anmeldenamen)
 *   author_archives  ?author=N bzw. /author/<login>/ und Autorenangaben in oEmbed für Gäste weg
 *   rest_users       /wp/v2/users nur für Redakteure (Block-Editor braucht die Liste)
 *   app_passwords    Anwendungspasswörter nur für Konten mit Redaktionsrechten (nicht für Kunden)
 *   email_change     E-Mail-Adresse unter „Mein Konto“ nur mit aktuellem Passwort ändern
 *   feed_author      RSS nennt statt des Autorennamens die Website (kein Anmeldename im Feed)
 *   register_limit   Registrierungen je IP begrenzen (bremst das Abfragen vorhandener Adressen)
 *   headers          Sicherheits-Header im Frontend (nosniff, Referrer-Policy, Framing, Permissions-Policy)
 */
final class Hardening
{
    public static function register(): void
    {
        add_filter('rest_endpoints', static function (array $endpoints): array {
            // Der Block-Editor braucht die Liste (Autorenauswahl) – nur Gäste/Lernende ausschließen.
            if (self::enabled('rest_users') && ! current_user_can('edit_posts')) {
                unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);
            }

            return $endpoints;
        });
        // ?author=1 bzw. /author/<login>/ verraten den Anmeldenamen.
        add_action('template_redirect', static function (): void {
            if (is_author() && ! current_user_can('edit_posts') && self::enabled('author_archives')) {
                wp_safe_redirect(home_url('/'), 301);
                exit;
            }
        }, 1);
        add_filter('oembed_response_data', static function ($data) {
            if (is_array($data) && self::enabled('author_archives')) {
                unset($data['author_name'], $data['author_url']);
            }

            return $data;
        }, 20);
        add_filter('wp_sitemaps_add_provider', static fn ($provider, $name) => $name === 'users' && self::enabled('user_sitemap') ? false : $provider, 10, 2);
        // Yoast SEO hat eine eigene Autoren-Sitemap (author-sitemap.xml mit /author/<login>/) und Autoren-Archive:
        // beides aus, solange die Härtung aktiv ist (Autoren-Archive leiten ohnehin auf die Startseite um).
        add_filter('wpseo_sitemap_exclude_author', static fn ($users) => self::enabled('user_sitemap') ? [] : $users, 99);
        add_filter('option_wpseo_titles', static function ($titles) {
            if (is_array($titles) && self::enabled('user_sitemap') && self::enabled('author_archives')) {
                $titles['disable-author'] = true;
                $titles['noindex-author-wpseo'] = true;
            }

            return $titles;
        }, 99);

        // XML-RPC: Passwort-Rateangriffe über system.multicall, Pingbacks. Per Filter wieder einschaltbar.
        add_filter('xmlrpc_enabled', static fn ($on) => self::enabled('xmlrpc_off') ? false : $on, 99);
        add_filter('xmlrpc_methods', static fn ($methods) => self::enabled('xmlrpc_off') ? [] : $methods, 99);
        add_filter('wp_headers', static function ($headers) {
            if (is_array($headers) && self::enabled('xmlrpc_off')) {
                unset($headers['X-Pingback']);
            }

            return $headers;
        });

        LoginGuard::register();
        add_action('lostpassword_post', [LoginGuard::class, 'lostPassword'], 99, 2);

        // T49: Anwendungspasswörter nur für Redaktion/Verwaltung – ein Kunden-Anwendungspasswort wäre ein Dauerzugang
        // an der Login-Begrenzung vorbei.
        add_filter('wp_is_application_passwords_available_for_user', static fn ($available, $user) => self::enabled('app_passwords') && $user instanceof \WP_User && ! user_can($user, 'edit_posts') ? false : $available, 10, 2);
        // T49: E-Mail-Änderung unter „Mein Konto“ nur mit aktuellem Passwort (sonst reicht eine übernommene Sitzung,
        // z. B. aus der App-Webansicht, um das Konto per „Passwort vergessen“ zu übernehmen).
        add_action('woocommerce_save_account_details_errors', [self::class, 'requirePasswordForEmailChange'], 10, 2);
        // T49: Feeds nennen keinen Autorennamen (oft gleich dem Anmeldenamen).
        add_filter('the_author', static fn ($name) => is_feed() && self::enabled('feed_author') ? (string) get_bloginfo('name') : $name, 99);
        // T49: Registrierungen je IP begrenzen (WooCommerce „Mein Konto“, Kasse, wp-login).
        add_filter('woocommerce_registration_errors', [self::class, 'limitRegistration'], 5, 3);
        add_filter('registration_errors', [self::class, 'limitRegistration'], 5, 3);
        // T49: Sicherheits-Header im Frontend.
        add_action('send_headers', [self::class, 'securityHeaders']);

        // Ablageordner (Pakete, geschützte Dateien) nach Updates an den geschützten Ort bringen.
        add_action('admin_init', [MediaStorage::class, 'relocateAll']);
        add_action('bp_learning_hourly', \Bootspruefung\Learning\Infrastructure\Cron::guard('media_relocate', [MediaStorage::class, 'relocateAll']));
    }

    /**
     * „Mein Konto › Kontodetails“: neue E-Mail-Adresse nur mit richtigem aktuellem Passwort.
     * @param mixed $errors @param mixed $user
     */
    public static function requirePasswordForEmailChange($errors, $user): void
    {
        if (! $errors instanceof \WP_Error || ! is_object($user) || empty($user->ID) || ! self::enabled('email_change')) {
            return;
        }
        $current = get_userdata((int) $user->ID);
        $new = isset($user->user_email) ? strtolower(trim((string) $user->user_email)) : '';
        if (! $current instanceof \WP_User || $new === '' || $new === strtolower((string) $current->user_email)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce prüft die Nonce vor diesem Hook
        $password = isset($_POST['password_current']) ? (string) wp_unslash($_POST['password_current']) : '';
        if ($password === '' || ! wp_check_password($password, (string) $current->user_pass, (int) $current->ID)) {
            $errors->add('bp_email_change_password', __('Bitte gib dein aktuelles Passwort ein, um deine E-Mail-Adresse zu ändern.', 'bootspruefung-learning'));
        }
    }

    /**
     * Registrierung: höchstens 10 Versuche je IP und Stunde (vorhandene Adressen lassen sich sonst massenhaft
     * abfragen). @param mixed $errors @return mixed
     */
    public static function limitRegistration($errors, $a = null, $b = null)
    {
        if (! $errors instanceof \WP_Error || ! self::enabled('register_limit')) {
            return $errors;
        }
        $hits = \Bootspruefung\Learning\Mobile\RateLimiter::increment('register-ip|'.\Bootspruefung\Learning\Mobile\RateLimiter::ipKey(\Bootspruefung\Learning\Mobile\RateLimiter::clientIp()), HOUR_IN_SECONDS);
        if ($hits > 10) {
            $errors->add('bp_register_limit', __('Zu viele Registrierungen von deinem Anschluss. Bitte versuche es in einer Stunde erneut.', 'bootspruefung-learning'));
        }

        return $errors;
    }

    /** Sicherheits-Header im Frontend, nur wenn noch nicht gesetzt (Server oder anderes Plugin haben Vorrang). */
    public static function securityHeaders(): void
    {
        if (is_admin() || headers_sent() || ! self::enabled('headers')) {
            return;
        }
        $sent = array_map(static fn ($h) => strtolower(trim(explode(':', (string) $h, 2)[0])), headers_list());
        foreach (self::securityHeaderList() as $name => $value) {
            if (! in_array(strtolower($name), $sent, true)) {
                header($name.': '.$value);
            }
        }
    }

    /**
     * Sicherheits-Header: kein MIME-Sniffing, Referrer nur als Herkunft bei fremden Seiten, Einbetten nur auf der
     * eigenen Website, Mikrofon nur für die eigene Seite (Funktraining), keine Kamera und kein Standort.
     * @return array<string,string>
     */
    public static function securityHeaderList(): array
    {
        return (array) apply_filters('bootspruefung_security_headers', [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(self)',
        ]);
    }

    /** Ist die Härtung $feature aktiv? (Filter bootspruefung_hardening) */
    public static function enabled(string $feature): bool
    {
        $default = match ($feature) {
            'login_limit' => self::externalLoginLimiter() === null,
            'xmlrpc_off' => ! class_exists('Jetpack', false),
            default => true,
        };

        /** Filter: einzelne Härtungen ab- bzw. einschalten. */
        return (bool) apply_filters('bootspruefung_hardening', $default, $feature);
    }

    /**
     * Name eines aktiven Sicherheits-Plugins mit eigener Begrenzung der Login-Fehlversuche, sonst null.
     * Dann zählt das Plugin selbst nicht zusätzlich (sonst doppelte Sperren mit verschiedenen Fristen).
     */
    public static function externalLoginLimiter(): ?string
    {
        $known = [
            'Wordfence' => defined('WORDFENCE_VERSION'),
            'Limit Login Attempts Reloaded' => defined('LLA_PLUGIN_DIR') || defined('LLA_PLUGIN_URL'),
            'Limit Login Attempts' => function_exists('limit_login_setup'),
            'Solid Security (iThemes)' => class_exists('ITSEC_Core', false),
            'All In One WP Security' => class_exists('AIO_WP_Security', false),
            'Loginizer' => defined('LOGINIZER_VERSION'),
        ];
        foreach ($known as $name => $active) {
            if ($active) {
                return $name;
            }
        }

        return null;
    }
}
