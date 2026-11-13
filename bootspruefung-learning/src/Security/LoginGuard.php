<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Security;

use Bootspruefung\Learning\Mobile\RateLimiter;
use WP_Error;
use WP_User;

/**
 * Begrenzung der Anmelde-Fehlversuche für alle Logins, die über wp_authenticate() laufen:
 * wp-login.php, das WooCommerce-Login unter „Mein Konto“, XML-RPC (falls wieder eingeschaltet)
 * und Anwendungspasswörter. Zähler und Grenzen sind dieselben wie beim App-Login
 * (POST boatingschool/v1/auth/login), Web und App teilen sich also die Fehlversuche:
 *   je IP 10, je Konto + IP 5, je Konto insgesamt 30 Fehlversuche in 15 Minuten.
 * Die Grenze je Konto ist absichtlich höher, sonst könnte jeder mit 5 falschen Passwörtern ein
 * fremdes Konto aussperren. Eine erfolgreiche Anmeldung setzt den Zähler Konto + IP zurück.
 *
 * Fehlermeldungen sind neutral (kein Hinweis, ob es den Benutzernamen bzw. die E-Mail gibt);
 * gesperrt wird mit einer ebenso neutralen Meldung – auch für nicht vorhandene Konten. Für unbekannte Konten
 * prüft eine Dummy-Passwortprüfung gleich lange wie für bekannte (keine Aufzählung über die Antwortzeit, T88).
 *
 * Zählen ohne Wettlauf (T83): Jeder Versuch wird VOR der Passwortprüfung atomar vorgemerkt (authenticate,
 * Priorität 1: INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1). Liegt ein Zähler danach über der Grenze, wird
 * der Versuch abgewiesen und die Vormerkung zurückgenommen. Erfolgreiche Anmeldungen nehmen ihre Vormerkung
 * zurück (nur Fehlversuche zählen). Parallele Anfragen können die Grenze so nicht mehr überholen.
 *
 * Abschaltbar über den Filter bootspruefung_hardening (Merkmal „login_limit“ bzw. „login_errors“);
 * die Begrenzung nimmt sich selbst zurück, wenn ein bekanntes Sicherheits-Plugin mit eigener
 * Login-Sperre aktiv ist (siehe Hardening::externalLoginLimiter()).
 */
final class LoginGuard
{
    public const FAILS_PER_IP = 10;
    public const FAILS_PER_LOGIN_IP = 5;
    public const FAILS_PER_LOGIN = 30;
    public const WINDOW = 15 * MINUTE_IN_SECONDS;

    /** Fehlercodes, die als falscher Benutzer bzw. falsches Passwort zählen. */
    private const FAIL_CODES = ['invalid_username', 'invalid_email', 'incorrect_password', 'authentication_failed', 'invalid_application_credentials', 'bp_login_failed'];

    /** Während des App-Logins zählt der AuthController selbst (sonst doppelt). */
    private static bool $paused = false;

    /**
     * Vormerkungen laufender Anmeldungen (Stapel, falls ein Plugin innerhalb von authenticate erneut anmeldet).
     * @var list<array{login:string,ip:string,buckets:array<string,int>,ok:bool,counts:array<string,int>,retry:int}>
     */
    private static array $reservations = [];

    /** Option mit dem Hash für die Dummy-Passwortprüfung. */
    private const DUMMY_HASH_OPTION = 'bp_learning_login_dummy_hash';

    public static function register(): void
    {
        add_filter('authenticate', [self::class, 'reserveAttempt'], 1, 3);
        add_filter('authenticate', [self::class, 'authenticate'], 100, 3);
        KnownDevice::register();
        // Reset-Mails sind begrenzt (3 je Konto und Stunde): auf der Bestätigungsseite sagen, was zu tun ist,
        // ohne zu verraten, ob es das Konto gibt (T41).
        add_filter('woocommerce_lost_password_confirmation_message', static fn ($message): string => trim((string) $message.' '.self::resetHint()));
        add_filter('wp_login_errors', [self::class, 'resetHintOnLogin'], 10, 1);
        add_filter('shake_error_codes', static fn ($codes): array => array_merge((array) $codes, ['bp_login_failed', 'bp_login_locked']));
    }

    /**
     * Zähler für eine Anmeldung: Name ist bei bekannten Konten die E-Mail-Adresse (Benutzername und
     * E-Mail zählen also gemeinsam), sonst die Eingabe in Kleinbuchstaben.
     * @return array<string,int> Zählername => Grenze
     */
    public static function buckets(string $login, string $ip): array
    {
        $id = self::identity($login);
        $ip = RateLimiter::ipKey($ip);
        $limits = (array) apply_filters('bootspruefung_login_limits', ['ip' => self::FAILS_PER_IP, 'login_ip' => self::FAILS_PER_LOGIN_IP, 'login' => self::FAILS_PER_LOGIN]);

        return [
            'login-ip|'.$ip => max(1, (int) ($limits['ip'] ?? self::FAILS_PER_IP)),
            'login-email-ip|'.$id.'|'.$ip => max(1, (int) ($limits['login_ip'] ?? self::FAILS_PER_LOGIN_IP)),
            'login-email|'.$id => max(1, (int) ($limits['login'] ?? self::FAILS_PER_LOGIN)),
        ];
    }

    public static function identity(string $login): string
    {
        $login = trim($login);
        $user = str_contains($login, '@') ? get_user_by('email', $login) : false;
        $user = $user ?: get_user_by('login', $login);

        return strtolower($user instanceof WP_User ? (string) $user->user_email : $login);
    }

    /** Gesperrt? (zählt nicht) @param array<string,int> $buckets */
    public static function locked(array $buckets): bool
    {
        foreach ($buckets as $bucket => $limit) {
            try {
                RateLimiter::check($bucket, $limit, self::WINDOW);
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versuch atomar vormerken (T83): jeden Zähler um 1 erhöhen. Liegt einer danach über seiner Grenze, ist der
     * Versuch abgewiesen und alle Vormerkungen werden zurückgenommen (gesperrte Versuche zählen nicht weiter).
     * Ein angenommener Versuch zählt als Fehlversuch, bis settle() ihn nach Erfolg zurücknimmt.
     *
     * @param array<string,int> $buckets
     * @return array{ok:bool,counts:array<string,int>,retry:int}
     */
    public static function reserve(array $buckets): array
    {
        $counts = [];
        $ok = true;
        $retry = 1;
        foreach ($buckets as $bucket => $limit) {
            [$count, $reset] = RateLimiter::take($bucket, self::WINDOW);
            $counts[$bucket] = $count;
            if ($count > $limit) {
                $ok = false;
                $retry = max($retry, $reset - time());
            }
        }
        if (! $ok) {
            self::release($buckets);
        }

        return ['ok' => $ok, 'counts' => $counts, 'retry' => $retry];
    }

    /** Vormerkungen zurücknehmen. @param array<string,int> $buckets */
    public static function release(array $buckets): void
    {
        foreach (array_keys($buckets) as $bucket) {
            RateLimiter::release($bucket);
        }
    }

    /**
     * Ergebnis eines vorgemerkten Versuchs: Erfolg nimmt die Vormerkungen zurück und setzt Konto + IP zurück;
     * ein Fehlversuch bleibt gezählt. @param array<string,int> $buckets @param array<string,int> $counts
     * @return bool true, wenn nach diesem Fehlversuch gesperrt
     */
    public static function settle(array $buckets, array $counts, bool $success, string $login, string $ip): bool
    {
        if ($success) {
            self::release($buckets);
            self::succeeded($login, $ip);

            return false;
        }
        foreach ($buckets as $bucket => $limit) {
            if (($counts[$bucket] ?? 0) >= $limit) {
                return true;
            }
        }

        return false;
    }

    /** Fehlversuch zählen. @param array<string,int> $buckets @return bool true, wenn danach gesperrt */
    public static function fail(array $buckets): bool
    {
        $locked = false;
        foreach ($buckets as $bucket => $limit) {
            if (RateLimiter::increment($bucket, self::WINDOW) >= $limit) {
                $locked = true;
            }
        }

        return $locked;
    }

    /**
     * Bekanntes Gerät (KnownDevice): nur der eigene Zähler Konto + IP gilt, nicht die Sperre je IP und je Konto.
     * @param array<string,int> $buckets @return array<string,int>
     */
    public static function scope(array $buckets, bool $knownDevice): array
    {
        return $knownDevice ? array_filter($buckets, static fn (string $b): bool => str_starts_with($b, 'login-email-ip|'), ARRAY_FILTER_USE_KEY) : $buckets;
    }

    /** Nach erfolgreicher Anmeldung: Zähler Konto + IP zurücksetzen. */
    public static function succeeded(string $login, string $ip): void
    {
        RateLimiter::clear('login-email-ip|'.self::identity($login).'|'.RateLimiter::ipKey($ip));
    }

    /** Nach einem Passwort-Reset: Zähler des Kontos (insgesamt und von dieser IP) zurücksetzen. */
    public static function resetAccount(WP_User $user): void
    {
        $id = strtolower((string) $user->user_email);
        RateLimiter::clear('login-email|'.$id);
        RateLimiter::clear('login-email-ip|'.$id.'|'.RateLimiter::clientKey());
    }

    public static function resetHint(): string
    {
        return __('Kommt keine E-Mail, schau bitte auch im Spam-Ordner nach. Aus Sicherheitsgründen schicken wir je Konto höchstens drei Links pro Stunde – fordere den Link sonst nach einer Stunde erneut an.', 'bootspruefung-learning');
    }

    /** wp-login.php?checkemail=confirm: Hinweis zur Begrenzung unter der Bestätigung. @param mixed $errors @return mixed */
    public static function resetHintOnLogin($errors)
    {
        if ($errors instanceof WP_Error && ($_GET['checkemail'] ?? '') === 'confirm' && Hardening::enabled('lost_password')) { // phpcs:ignore WordPress.Security.NonceVerification -- Anzeige
            $errors->add('bp_reset_hint', esc_html(self::resetHint()), 'message');
        }

        return $errors;
    }

    /**
     * Führt $fn aus, ohne dass der authenticate-Filter zählt oder sperrt (der Aufrufer prüft und zählt
     * dieselben Zähler selbst, z. B. das App-Login).
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function uncounted(callable $fn): mixed
    {
        $before = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $before;
        }
    }

    /**
     * Filter authenticate (Priorität 1, vor der Passwortprüfung von WordPress bei 20): Versuch atomar vormerken.
     * Über der Grenze → Sperrmeldung; das Ergebnis setzt authenticate() (Priorität 100) in jedem Fall durch.
     * @param WP_User|WP_Error|null $user
     * @param mixed $username
     * @param mixed $password
     * @return WP_User|WP_Error|null
     */
    public static function reserveAttempt($user, $username, $password)
    {
        $login = is_string($username) ? trim($username) : '';
        if ($login === '' || ! is_string($password) || $password === '' || self::$paused || $user instanceof WP_User || ! Hardening::enabled('login_limit')) {
            return $user;
        }
        $ip = RateLimiter::clientIp();
        $buckets = self::scope(self::buckets($login, $ip), KnownDevice::trustedFor($login));
        $reservation = self::reserve($buckets);
        self::$reservations[] = ['login' => $login, 'ip' => $ip, 'buckets' => $buckets] + $reservation;

        return $reservation['ok'] ? $user : self::lockedError();
    }

    /**
     * Filter authenticate (Priorität 100, nach der Passwortprüfung von WordPress bei 20):
     * gesperrt → Sperrmeldung (auch bei richtigem Passwort), Fehlversuch → gezählt lassen und neutrale Meldung,
     * Erfolg → Vormerkung zurücknehmen.
     * @param WP_User|WP_Error|null $user
     * @param mixed $username
     * @param mixed $password
     * @return WP_User|WP_Error|null
     */
    public static function authenticate($user, $username, $password)
    {
        $login = is_string($username) ? trim($username) : '';
        if ($login === '' || ! is_string($password) || $password === '') {
            return $user; // leeres Formular bzw. Cookie-Anmeldung: nichts zu zählen
        }
        if ($user instanceof WP_Error && in_array($user->get_error_code(), ['invalid_username', 'invalid_email'], true)) {
            self::equalizeTiming($password); // unbekanntes Konto: gleich teure Arbeit wie eine echte Prüfung
        }
        $failed = $user === null || ($user instanceof WP_Error && in_array($user->get_error_code(), self::FAIL_CODES, true));
        $reservation = self::$reservations !== [] && end(self::$reservations)['login'] === $login ? array_pop(self::$reservations) : null;
        if ($reservation !== null) {
            if (! $reservation['ok']) {
                /** Aktion: Anmeldung wegen zu vieler Fehlversuche abgewiesen. */
                do_action('bootspruefung_login_locked', $login, $reservation['ip']);

                return self::lockedError();
            }
            if ($user instanceof WP_User) {
                self::settle($reservation['buckets'], $reservation['counts'], true, $login, $reservation['ip']);

                return $user;
            }
            if (! $failed) {
                self::release($reservation['buckets']); // anderer Fehler (z. B. Konto gesperrt): kein Fehlversuch
            } elseif (self::settle($reservation['buckets'], $reservation['counts'], false, $login, $reservation['ip'])) {
                do_action('bootspruefung_login_locked', $login, $reservation['ip']);

                return self::lockedError();
            }
        } elseif (! self::$paused && Hardening::enabled('login_limit')) {
            // Ohne Vormerkung (Filter bei Priorität 1 entfernt): wie früher prüfen und zählen.
            $ip = RateLimiter::clientIp();
            $buckets = self::scope(self::buckets($login, $ip), KnownDevice::trustedFor($login));
            if (self::locked($buckets)) {
                /** Aktion: Anmeldung wegen zu vieler Fehlversuche abgewiesen. */
                do_action('bootspruefung_login_locked', $login, $ip);

                return self::lockedError();
            }
            if ($user instanceof WP_User) {
                self::succeeded($login, $ip);

                return $user;
            }
            if ($failed && self::fail($buckets)) {
                do_action('bootspruefung_login_locked', $login, $ip);

                return self::lockedError();
            }
        }
        if ($failed && Hardening::enabled('login_errors')) {
            return self::neutralError();
        }

        return $user;
    }

    /**
     * Dummy-Passwortprüfung für unbekannte Konten (T88): Gleich teuer wie wp_check_password() eines echten Kontos,
     * damit die Antwortzeit nicht verrät, ob es einen Benutzernamen bzw. eine E-Mail-Adresse gibt. Der Hash wird
     * einmal mit dem aktuellen Verfahren von WordPress erzeugt und gespeichert (neu, wenn sich das Verfahren ändert).
     */
    public static function equalizeTiming(string $password): void
    {
        $hash = (string) get_option(self::DUMMY_HASH_OPTION, '');
        if ($hash === '' || (function_exists('wp_password_needs_rehash') && wp_password_needs_rehash($hash))) {
            $hash = wp_hash_password(wp_generate_password(32, true, true));
            update_option(self::DUMMY_HASH_OPTION, $hash, false);
        }
        wp_check_password($password, $hash);
    }

    public static function neutralError(): WP_Error
    {
        return new WP_Error('bp_login_failed', '<strong>'.__('Fehler:', 'bootspruefung-learning').'</strong> '.__('Benutzername, E-Mail-Adresse oder Passwort ist nicht korrekt.', 'bootspruefung-learning'));
    }

    public static function lockedError(): WP_Error
    {
        return new WP_Error('bp_login_locked', '<strong>'.__('Fehler:', 'bootspruefung-learning').'</strong> '.__('Zu viele Anmeldeversuche. Bitte warte einige Minuten und versuche es dann erneut.', 'bootspruefung-learning'));
    }

    /**
     * Passwort vergessen (wp-login.php und WooCommerce „Mein Konto“): unbekannte Benutzernamen bzw.
     * E-Mail-Adressen bekommen dieselbe Bestätigung wie bekannte; Reset-Mails je IP (10/h) und je
     * Konto (3/h) begrenzt – wie in der App. Aktion lostpassword_post (wird von beiden aufgerufen).
     * @param mixed $errors
     * @param mixed $userData
     */
    public static function lostPassword($errors, $userData = false): void
    {
        if (! $errors instanceof WP_Error || ! Hardening::enabled('lost_password')) {
            return;
        }
        // „Kein Konto mit dieser E-Mail“ (WordPress prüft E-Mail-Adressen schon vorher) zählt als unbekannt.
        $unknownOnly = ! $userData instanceof WP_User && array_diff($errors->get_error_codes(), ['invalid_email', 'invalidcombo']) === [];
        if ($errors->has_errors() && ! $unknownOnly) {
            return; // z. B. leeres Feld: Meldung bleibt
        }
        $target = self::lostPasswordConfirmUrl();
        if ($target === null) {
            return; // anderer Aufrufer (Admin „Link senden“, App): nicht eingreifen
        }
        if ($userData instanceof WP_User) {
            $perIp = RateLimiter::increment('forgot-ip|'.RateLimiter::clientKey(), HOUR_IN_SECONDS);
            $perUser = RateLimiter::increment('forgot-email|'.strtolower((string) $userData->user_email), HOUR_IN_SECONDS);
            if ($perIp <= 10 && $perUser <= 3) {
                return; // Mail wird wie gewohnt verschickt
            }
        }
        wp_safe_redirect($target);
        exit;
    }

    /** Ziel der Erfolgsmeldung im aktuellen Formular, sonst null. */
    public static function lostPasswordConfirmUrl(): ?string
    {
        if (isset($_POST['wc_reset_password']) && function_exists('wc_get_account_endpoint_url')) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Nonce vorher
            return add_query_arg('reset-link-sent', 'true', wc_get_account_endpoint_url('lost-password'));
        }
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') {
            $redirect = isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

            return $redirect !== '' ? wp_validate_redirect($redirect, 'wp-login.php?checkemail=confirm') : 'wp-login.php?checkemail=confirm';
        }

        return null;
    }
}
