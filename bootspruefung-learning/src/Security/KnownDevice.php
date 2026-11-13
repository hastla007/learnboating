<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Security;

use WP_User;

/**
 * „Bekanntes Gerät“ für die Login-Sperre (To-do T41, Muster „Device Cookies“ der OWASP).
 *
 * Nach einer erfolgreichen Anmeldung bzw. einem Passwort-Reset bekommt der Browser ein signiertes Cookie (die App
 * einen gleichwertigen device_token). Meldet sich dieselbe Person später von diesem Gerät an, zählen für sie nur
 * die eigenen Fehlversuche (Konto + IP) – die Sperre je IP (Fahrschul-WLAN, Mobilfunk, Proxy) und die Sperre je
 * Konto (Angreifer mit vielen IPs) treffen sie nicht. Fremde ohne Nachweis bleiben voll begrenzt.
 *
 * Der Nachweis enthält Benutzer-ID und Ablauf, signiert mit dem Auth-Salt und einem Teil des Passwort-Hashs:
 * Eine Passwortänderung macht alle Nachweise ungültig. Er erlaubt keine Anmeldung, nur das Umgehen der
 * Sperre für die eigene Anmeldung (Passwort bleibt nötig).
 */
final class KnownDevice
{
    public const COOKIE = 'bp_known_device';
    public const LIFETIME = 180 * DAY_IN_SECONDS;

    public static function register(): void
    {
        add_action('wp_login', [self::class, 'onLogin'], 10, 2);
        add_action('after_password_reset', [self::class, 'onPasswordReset'], 10, 1);
        add_action('woocommerce_customer_reset_password', [self::class, 'onPasswordReset'], 10, 1);
    }

    /** Nachweis für ein Konto: v1.<id>.<ablauf>.<signatur> */
    public static function issue(int $userId, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::LIFETIME;
        $sig = self::sign($userId, $expires);

        return $sig === null ? '' : 'v1.'.$userId.'.'.$expires.'.'.$sig;
    }

    /** Gilt der Nachweis für dieses Konto? */
    public static function valid(string $token, int $userId, ?int $now = null): bool
    {
        if (preg_match('/^v1\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m) !== 1 || (int) $m[1] !== $userId || (int) $m[2] < ($now ?? time())) {
            return false;
        }
        $expected = self::sign($userId, (int) $m[2]);

        return $expected !== null && hash_equals($expected, $m[3]);
    }

    /**
     * Hat diese Anmeldung (Benutzername oder E-Mail) einen gültigen Nachweis? Ohne $token zählt das Cookie.
     */
    public static function trustedFor(string $login, ?string $token = null): bool
    {
        $token ??= isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? wp_unslash($_COOKIE[self::COOKIE]) : '';
        if ($token === '') {
            return false;
        }
        $login = trim($login);
        $user = str_contains($login, '@') ? get_user_by('email', $login) : false;
        $user = $user ?: get_user_by('login', $login);

        return $user instanceof WP_User && self::valid($token, (int) $user->ID);
    }

    /** Cookie setzen (nur Web; HttpOnly, SameSite=Lax, bei HTTPS Secure). */
    public static function setCookie(int $userId): void
    {
        $token = self::issue($userId);
        if ($token === '') {
            return;
        }
        $_COOKIE[self::COOKIE] = $token;
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::LIFETIME,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @param mixed $login @param mixed $user */
    public static function onLogin($login, $user): void
    {
        if ($user instanceof WP_User) {
            self::setCookie((int) $user->ID);
        }
    }

    /** Passwort-Reset beweist den Zugriff aufs Postfach: Kontozähler zurücksetzen, Gerät als bekannt merken. @param mixed $user */
    public static function onPasswordReset($user): void
    {
        if (! $user instanceof WP_User) {
            return;
        }
        LoginGuard::resetAccount($user);
        self::setCookie((int) $user->ID);
    }

    private static function sign(int $userId, int $expires): ?string
    {
        clean_user_cache($userId);
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return null;
        }
        $fragment = substr((string) $user->user_pass, 8, 4);

        return hash_hmac('sha256', 'known-device|'.$userId.'|'.$expires.'|'.$fragment, wp_salt('auth'));
    }
}
