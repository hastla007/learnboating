<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\AppLinks;
use Bootspruefung\Learning\Mobile\MobileSettings;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Mobile\TokenAuth;
use Bootspruefung\Learning\Mobile\WebSession;
use Bootspruefung\Learning\Security\LoginGuard;
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
    /**
     * Fehlversuche in 15 Minuten: je IP, je Kombination E-Mail + IP (niedrig) und je E-Mail
     * insgesamt (deutlich höher – sonst könnte jeder mit 5 falschen Passwörtern ein fremdes Konto
     * sperren). Eine erfolgreiche Anmeldung setzt den Zähler E-Mail + IP zurück.
     */
    public const LOGIN_FAILS_PER_IP = LoginGuard::FAILS_PER_IP;
    public const LOGIN_FAILS_PER_EMAIL_IP = LoginGuard::FAILS_PER_LOGIN_IP;
    public const LOGIN_FAILS_PER_EMAIL = LoginGuard::FAILS_PER_LOGIN;
    public const LOGIN_WINDOW = LoginGuard::WINDOW;

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
        // Dieselben Zähler wie wp-login/WooCommerce-Login (LoginGuard): Web und App teilen die Fehlversuche.
        // Bekanntes Gerät (device_token aus einer früheren Anmeldung): Sperren je IP und je Konto gelten nicht (T41).
        $buckets = LoginGuard::scope(LoginGuard::buckets($email, $ip), \Bootspruefung\Learning\Security\KnownDevice::trustedFor($email, trim((string) $request['device_token'])));
        // Versuch vor der Passwortprüfung atomar vormerken (T83): parallele Anfragen können die Grenze nicht überholen.
        $reservation = LoginGuard::reserve($buckets);
        if (! $reservation['ok']) {
            throw ApiException::rateLimited($reservation['retry'], $limitMessage);
        }

        // E-Mail oder Benutzername (wie das Web-Login von WordPress/WooCommerce).
        $user = LoginGuard::uncounted(static fn () => wp_authenticate($email, $password));
        if (is_wp_error($user) || ! $user instanceof \WP_User || ! apply_filters('bootspruefung_mobile_user_allowed', true, $user)) {
            LoginGuard::settle($buckets, $reservation['counts'], false, $email, $ip); // Fehlversuch bleibt gezählt
            throw new ApiException('UNAUTHENTICATED', __('E-Mail-Adresse oder Passwort ist nicht korrekt.', 'bootspruefung-learning'), 401);
        }
        LoginGuard::settle($buckets, $reservation['counts'], true, $email, $ip);
        $platform = in_array($request['device_platform'], ['ios', 'android'], true) ? (string) $request['device_platform'] : null;
        $tokens = TokenAuth::issue((int) $user->ID, $device, $platform, $request['app_version'] !== null ? sanitize_text_field((string) $request['app_version']) : null);
        /** Aktion: erfolgreiche App-Anmeldung (Protokoll, Statistik). */
        do_action('bootspruefung_mobile_login', (int) $user->ID, (int) $tokens['session_id']);

        return $this->ok($tokens + AccountController::profile((int) $user->ID) + ['device_token' => \Bootspruefung\Learning\Security\KnownDevice::issue((int) $user->ID)]);
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        RateLimiter::hit('refresh-ip|'.RateLimiter::clientKey(), 120, 15 * MINUTE_IN_SECONDS);
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
        RateLimiter::hit('forgot-ip|'.RateLimiter::clientKey(), 10, HOUR_IN_SECONDS);
        RateLimiter::hit('forgot-email|'.strtolower($email), 3, HOUR_IN_SECONDS);
        $user = get_user_by('email', $email);
        if ($user instanceof \WP_User) {
            // WordPress-/WooCommerce-Mechanismus: E-Mail mit Link zum Setzen eines neuen Passworts.
            // Mit eingetragenen App-Kennungen zeigt der Link auf den App-Link-Pfad (öffnet die App,
            // ohne App leitet der Server auf die Web-Seite weiter); Web-Ablauf sonst unverändert.
            // Erst nach dem Senden der Antwort (T88): Schlüssel und Mailversand dauern messbar – sonst verriete die
            // Antwortzeit, ob es das Konto gibt. Die Antwort ist für bekannte und unbekannte Adressen gleich.
            self::afterResponse(static function () use ($user): void {
                $result = AppLinks::withAppResetLink(static fn () => retrieve_password($user->user_login));
                if (is_wp_error($result)) {
                    error_log('[bootspruefung-learning mobile] Passwort-Mail fehlgeschlagen: '.$result->get_error_message());
                }
            });
        }

        return new WP_REST_Response(null, 202);
    }

    /** @var list<callable():void> nach dem Senden der Antwort auszuführen */
    private static array $deferred = [];

    /**
     * Arbeit nach dem Senden der Antwort erledigen (PHP-FPM: fastcgi_finish_request, LiteSpeed:
     * litespeed_finish_request). Ohne diese Möglichkeit (z. B. mod_php, Kommandozeile) sofort.
     * Filter bootspruefung_defer_after_response erzwingt bzw. verhindert das Verschieben.
     */
    public static function afterResponse(callable $fn): void
    {
        $finish = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
        if (! (bool) apply_filters('bootspruefung_defer_after_response', $finish && PHP_SAPI !== 'cli')) {
            $fn();

            return;
        }
        if (self::$deferred === []) {
            add_action('shutdown', static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                self::runDeferred();
            }, 0);
        }
        self::$deferred[] = $fn;
    }

    /** Verschobene Arbeit jetzt ausführen (Ende der Anfrage; Tests). */
    public static function runDeferred(): void
    {
        while (($fn = array_shift(self::$deferred)) !== null) {
            $fn();
        }
    }

    /**
     * Einmal-URL für die eingebettete Webansicht (z. B. Funkgerät-Simulator am Tablet): meldet das
     * Konto der App-Sitzung im Web-Lernbereich an. Nur Ziele unter /bootsscheine/…, 60 Sekunden,
     * einmal verwendbar; nicht für Konten mit Verwaltungsrechten. Seit 1.4.0 mit Prüfwert (verifier),
     * den die WebView als Header X-Boatingschool-Web-Verifier mitsendet (Schutz vor Login-CSRF).
     */
    public function webSession(WP_REST_Request $request): WP_REST_Response
    {
        if (! MobileSettings::webSessionEnabled()) {
            throw new ApiException('WEB_SESSION_DISABLED', __('Die Anmeldung in der Webansicht ist bei dieser Bootsschule abgeschaltet. Bitte öffne die Seite im Browser.', 'bootspruefung-learning'), 403);
        }
        $userId = $this->user();
        if (WebSession::privileged(get_userdata($userId))) {
            throw new ApiException('WEB_SESSION_NOT_ALLOWED', __('Für Konten mit Verwaltungsrechten ist die Anmeldung in der Webansicht über die App nicht möglich. Bitte melde dich im Browser an.', 'bootspruefung-learning'), 403);
        }
        RateLimiter::hit('web-session|'.$userId, WebSession::RATE_LIMIT, WebSession::RATE_WINDOW);
        $path = WebSession::normalizeTarget((string) $request['redirect']);
        if ($path === null) {
            throw ApiException::validation(['redirect' => [__('Erlaubt sind nur Seiten im Lernbereich dieser Website, z. B. /bootsscheine/src/funk/geraet/.', 'bootspruefung-learning')]]);
        }
        $session = TokenAuth::current();

        return $this->ok(WebSession::create($userId, $session !== null ? (int) $session->id : 0, $path), 201);
    }

    /** Neues Passwort mit dem Schlüssel aus der Reset-E-Mail; widerruft alle App-Sitzungen. */
    public function resetPassword(WP_REST_Request $request): WP_REST_Response
    {
        RateLimiter::hit('reset-ip|'.RateLimiter::clientKey(), 20, HOUR_IN_SECONDS);
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
        // Konto über die E-Mail-Adresse oder (seit 1.3.0, aus dem App-Link /app/passwort/) den Benutzernamen.
        $login = trim((string) $request['login']);
        $user = $login !== '' ? get_user_by('login', $login) : (is_email($email) ? get_user_by('email', $email) : false);
        if ($user instanceof \WP_User && $login !== '' && $email !== '' && strcasecmp((string) $user->user_email, $email) !== 0) {
            $user = false;
        }
        $checked = $user instanceof \WP_User ? check_password_reset_key($key, $user->user_login) : null;
        if (! $checked instanceof \WP_User) {
            throw new ApiException('PASSWORD_RESET_INVALID_OR_EXPIRED', __('Der Link zum Zurücksetzen ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 'bootspruefung-learning'), 422);
        }
        reset_password($checked, $password);
        TokenAuth::revokeAll((int) $checked->ID, 'password_reset');

        return $this->noContent();
    }
}
