<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Frontend;

/**
 * Rückkehr zur Ausgangsseite (Gruppeneinladung, Kasse, gesperrte Seite) nach Registrierung
 * oder Anmeldung. Links tragen ?bp_return=<URL>; das Ziel wird kurz im Cookie gemerkt, weil
 * die WordPress-Registrierung über die Mail zum Passwortsetzen läuft und erst die spätere
 * Anmeldung zurückführen kann. Nur Ziele auf dieser Website (wp_validate_redirect).
 */
final class ReturnAfterLogin
{
    public const PARAM = 'bp_return';
    public const COOKIE = 'bp_return';

    public static function register(): void
    {
        add_action('init', [self::class, 'remember'], 1);
        add_filter('login_redirect', [self::class, 'loginRedirect'], 20, 3);
        add_filter('registration_redirect', [self::class, 'registrationRedirect'], 20);
        add_filter('woocommerce_registration_redirect', [self::class, 'wooRedirect'], 20);
        add_filter('woocommerce_login_redirect', [self::class, 'wooRedirect'], 20);
    }

    /** Registrierungslink, der danach zu $return zurückführt. */
    public static function registrationUrl(string $return): string
    {
        $base = function_exists('wc_get_page_permalink') && get_option('woocommerce_enable_myaccount_registration') === 'yes'
            ? wc_get_page_permalink('myaccount')
            : wp_registration_url();

        return add_query_arg(self::PARAM, rawurlencode($return), $base);
    }

    /** Anmeldelink mit Rückkehr (wie wp_login_url, zusätzlich gemerkt). */
    public static function loginUrl(string $return): string
    {
        return add_query_arg(self::PARAM, rawurlencode($return), wp_login_url($return));
    }

    /**
     * Absolute Adresse der aktuellen Anfrage als Rücksprungziel (T105). REQUEST_URI enthält bei WordPress im
     * Unterordner diesen Ordner schon (/blog/bootsscheine/…); home_url(REQUEST_URI) hätte ihn verdoppelt
     * (/blog/blog/bootsscheine/…). Deshalb wird der Pfad relativ zum Pfad von home_url() gebildet.
     */
    public static function currentUrl(?string $requestUri = null): string
    {
        $uri = $requestUri ?? (isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- unten geprüft
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        $path = '/'.ltrim($path, '/');
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $url = home_url($path);

        return $query !== '' ? $url.'?'.$query : $url;
    }

    public static function target(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- nur Weiterleitungsziel, geprüft
        $raw = isset($_REQUEST[self::PARAM]) ? (string) wp_unslash($_REQUEST[self::PARAM]) : (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '') {
            return null;
        }
        $url = wp_validate_redirect(esc_url_raw(rawurldecode($raw)), '');

        return $url !== '' ? $url : null;
    }

    public static function remember(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (! isset($_GET[self::PARAM]) || headers_sent() || ($url = self::target()) === null) {
            return;
        }
        setcookie(self::COOKIE, rawurlencode($url), ['expires' => time() + DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = rawurlencode($url);
    }

    /** Nach der Anmeldung: gemerktes Ziel, wenn sonst Profil/Dashboard käme. */
    public static function loginRedirect($redirect, $requested = '', $user = null)
    {
        $target = self::target();
        if ($target === null || $user instanceof \WP_Error) {
            return $redirect;
        }
        $default = $requested === '' || str_starts_with((string) $redirect, admin_url()) || $requested === admin_url();
        if ($default || $requested === $target) {
            self::forget();

            return $target;
        }

        return $redirect;
    }

    public static function registrationRedirect($redirect)
    {
        return $redirect ?: (self::target() !== null ? add_query_arg('checkemail', 'registered', wp_login_url(self::target())) : $redirect);
    }

    public static function wooRedirect($redirect)
    {
        $target = self::target();
        if ($target === null) {
            return $redirect;
        }
        self::forget();

        return $target;
    }

    private static function forget(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (! headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - HOUR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '']);
        }
    }
}
