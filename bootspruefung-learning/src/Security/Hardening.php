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

        // Ablageordner (Pakete, geschützte Dateien) nach Updates an den geschützten Ort bringen.
        add_action('admin_init', [MediaStorage::class, 'relocateAll']);
        add_action('bp_learning_hourly', \Bootspruefung\Learning\Infrastructure\Cron::guard('media_relocate', [MediaStorage::class, 'relocateAll']));
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
