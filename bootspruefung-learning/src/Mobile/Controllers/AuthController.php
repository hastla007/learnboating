<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Anmeldung mit dem bestehenden Shop-/Lernkonto (WordPress/WooCommerce), Token-Rotation,
 * Abmeldung und Passwort-Zurücksetzen über den WordPress-Mechanismus.
 * wp_authenticate() prüft nur die Zugangsdaten (inkl. Filtern von Sicherheits-Plugins)
 * und setzt – anders als wp_signon() – keine Cookies.
 */
final class AuthController extends BaseController
{
    /** Fehlversuche je IP bzw. je E-Mail-Adresse in 15 Minuten. */
    public const LOGIN_FAILS_PER_IP = 10;
    public const LOGIN_FAILS_PER_EMAIL = 5;
    public const LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        $email = trim((string) $request['email']);
        $password = (string) $request['password'];
        $device = trim(sanitize_text_field((string) $request['device_name']));
        $errors = [];
        if ($email === '') {
            $errors['email'][] = __('Bitte gib deine E-Mail-Adresse ein.', 'bootspruefung-learning');
        }
        if ($password === '') {
            $errors['password'][] = __('Bitte gib dein Passwort ein.', 'bootspruefung-learning');
        }
        if ($device === '') {
            $errors['device_name'][] = __('Bitte sende einen Gerätenamen mit.', 'bootspruefung-learning');
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $ip = RateLimiter::clientIp();
        $limitMessage = __('Zu viele Anmeldeversuche. Bitte warte einige Minuten und versuche es dann erneut.', 'bootspruefung-learning');
        RateLimiter::check('login-ip|'.$ip, self::LOGIN_FAILS_PER_IP, $limitMessage);
        RateLimiter::check('login-email|'.strtolower($email), self::LOGIN_FAILS_PER_EMAIL, $limitMessage);

        // E-Mail oder Benutzername (wie das Web-Login von WordPress/WooCommerce).
        $user = wp_authenticate($email, $password);
        if (is_wp_error($user) || ! $user instanceof \WP_User || ! apply_filters('bootspruefung_mobile_user_allowed', true, $user)) {
            RateLimiter::increment('login-ip|'.$ip, self::LOGIN_WINDOW);
            RateLimiter::increment('login-email|'.strtolower($email), self::LOGIN_WINDOW);
            throw new ApiException('UNAUTHENTICATED', __('E-Mail-Adresse oder Passwort ist nicht korrekt.', 'bootspruefung-learning'), 401);
        }
        RateLimiter::clear('login-email|'.strtolower($email));
        $platform = in_array($request['device_platform'], ['ios', 'android'], true) ? (string) $request['device_platform'] : null;
        $tokens = TokenAuth::issue((int) $user->ID, $device, $platform, $request['app_version'] !== null ? sanitize_text_field((string) $request['app_version']) : null);
        /** Aktion: erfolgreiche App-Anmeldung (Protokoll, Statistik). */
        do_action('bootspruefung_mobile_login', (int) $user->ID, (int) $tokens['session_id']);

        return $this->ok($tokens + AccountController::profile((int) $user->ID));
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        RateLimiter::hit('refresh-ip|'.RateLimiter::clientIp(), 120, 15 * MINUTE_IN_SECONDS);
        [, $tokens] = TokenAuth::refresh(trim((string) $request['refresh_token']));

        return $this->ok($tokens);
    }

    public function logout(): WP_REST_Response
    {
        $session = TokenAuth::current();
        if ($session !== null) {
            TokenAuth::revoke((int) $session->id, 'logout');
        }

        return $this->noContent();
    }

    /** Neutral (202), unabhängig davon, ob es das Konto gibt – keine Aufzählung von Adressen. */
    public function forgotPassword(WP_REST_Request $request): WP_REST_Response
    {
        $email = sanitize_email((string) $request['email']);
        if (! is_email($email)) {
            throw ApiException::validation(['email' => [__('Bitte gib eine gültige E-Mail-Adresse ein.', 'bootspruefung-learning')]]);
        }
        RateLimiter::hit('forgot-ip|'.RateLimiter::clientIp(), 10, HOUR_IN_SECONDS);
        RateLimiter::hit('forgot-email|'.strtolower($email), 3, HOUR_IN_SECONDS);
        $user = get_user_by('email', $email);
        if ($user instanceof \WP_User) {
            // WordPress-/WooCommerce-Mechanismus: E-Mail mit Link zum Setzen eines neuen Passworts.
            $result = retrieve_password($user->user_login);
            if (is_wp_error($result)) {
                error_log('[bootspruefung-learning mobile] Passwort-Mail fehlgeschlagen: '.$result->get_error_message());
            }
        }

        return new WP_REST_Response(null, 202);
    }

    /** Neues Passwort mit dem Schlüssel aus der Reset-E-Mail; widerruft alle App-Sitzungen. */
    public function resetPassword(WP_REST_Request $request): WP_REST_Response
    {
        RateLimiter::hit('reset-ip|'.RateLimiter::clientIp(), 20, HOUR_IN_SECONDS);
        $email = sanitize_email((string) $request['email']);
        $key = trim((string) $request['token']);
        $password = (string) $request['password'];
        $errors = [];
        if (mb_strlen($password) < 12) {
            $errors['password'][] = __('Das Passwort muss mindestens 12 Zeichen lang sein.', 'bootspruefung-learning');
        }
        if ($password !== (string) $request['password_confirmation']) {
            $errors['password_confirmation'][] = __('Die Passwörter stimmen nicht überein.', 'bootspruefung-learning');
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $user = is_email($email) ? get_user_by('email', $email) : false;
        $checked = $user instanceof \WP_User ? check_password_reset_key($key, $user->user_login) : null;
        if (! $checked instanceof \WP_User) {
            throw new ApiException('PASSWORD_RESET_INVALID_OR_EXPIRED', __('Der Link zum Zurücksetzen ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 'bootspruefung-learning'), 422);
        }
        reset_password($checked, $password);
        TokenAuth::revokeAll((int) $checked->ID, 'password_reset');

        return $this->noContent();
    }

    /**
     * Unsere Konten sind WooCommerce-Kundenkonten ohne eigene E-Mail-Bestätigung; sie gelten als
     * bestätigt. Der Endpunkt bleibt für den Vertrag erhalten und versendet nichts.
     */
    public function verification(): WP_REST_Response
    {
        return new WP_REST_Response(null, 202);
    }
}
