<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

use Bootspruefung\Learning\Frontend\Router;

/**
 * Sichere Sitzungsübergabe App → Web (eingebettete Webansicht, z. B. Funkgerät-Simulator am Tablet):
 * - POST /auth/web-session liefert eine Einmal-URL ins Lernbereich-Ziel mit ?bp_app_login=<Code>;
 * - Code: 32 Zufallsbytes, gespeichert nur als SHA-256-Hash (bp_mobile_web_code), 60 Sekunden gültig,
 *   einmal verwendbar (bedingtes UPDATE), gebunden an Konto und App-Sitzung (Widerruf der Sitzung
 *   oder Passwortänderung macht offene Codes unbrauchbar);
 * - Ziel nur Pfade unter dem Lernbereich dieser Website (/bootsscheine/…), kein offener Redirect;
 * - beim Aufruf: WordPress-Anmeldecookie ohne „angemeldet bleiben“, Referrer-Policy no-referrer,
 *   Cache-Control no-store, Weiterleitung aufs Ziel ohne Code; ungültig/abgelaufen/verbraucht →
 *   Anmeldeseite mit redirect_to (ohne Hinweis auf den Grund);
 * - Konten mit Verwaltungsrechten (manage_options, edit_others_posts) sind ausgeschlossen.
 */
final class WebSession
{
    public const PARAM = 'bp_app_login';
    public const TTL = 60;
    /** Codes je Konto im Zeitfenster. */
    public const RATE_LIMIT = 20;
    public const RATE_WINDOW = 15 * MINUTE_IN_SECONDS;
    private const CODE_PATTERN = '/^bpw_[A-Za-z0-9_-]{43}$/';

    public static function register(): void
    {
        add_action('init', [self::class, 'handleRequest'], 1);
    }

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_mobile_web_code';
    }

    /** Verwaltungskonten bekommen keine Web-Sitzung über ein App-Token. */
    public static function privileged(\WP_User|false|null $user): bool
    {
        return $user instanceof \WP_User && (user_can($user, 'manage_options') || user_can($user, 'edit_others_posts'));
    }

    /** scheme://host[:port] der Website. */
    private static function origin(): string
    {
        $home = wp_parse_url(home_url('/'));

        return ($home['scheme'] ?? 'https').'://'.($home['host'] ?? '').(isset($home['port']) ? ':'.$home['port'] : '');
    }

    /**
     * Weiterleitungsziel prüfen und normalisieren: nur Pfade im Lernbereich dieser Website
     * (Pfad relativ zur Domain oder absolute URL mit gleichem Schema/Host/Port), ohne „..“,
     * Prozentkodierung, Backslashes, doppelte Schrägstriche oder Fragment.
     * @return ?string Pfad (+ Query) ab Domain, z. B. /bootsscheine/src/funk/geraet/
     */
    public static function normalizeTarget(string $redirect): ?string
    {
        $redirect = trim($redirect);
        if ($redirect === '' || strlen($redirect) > 400 || preg_match('/[\x00-\x20\x7f\\\\#]/', $redirect) === 1) {
            return null;
        }
        $home = wp_parse_url(home_url('/'));
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $redirect) === 1) {
            $parts = wp_parse_url($redirect);
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                || strtolower((string) ($parts['scheme'] ?? '')) !== strtolower((string) ($home['scheme'] ?? ''))
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
                || ($parts['port'] ?? null) !== ($home['port'] ?? null)) {
                return null;
            }
            $redirect = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }
        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return null;
        }
        [$path, $query] = array_pad(explode('?', $redirect, 2), 2, null);
        if (preg_match('#^/[A-Za-z0-9/_.~-]*$#', $path) !== 1 || str_contains($path, '//') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }
        $homePath = rtrim((string) ($home['path'] ?? ''), '/');
        $area = $homePath.'/'.Router::base().'/';
        if ($homePath !== '' && ! str_starts_with($path.'/', $area) && str_starts_with($path.'/', '/'.Router::base().'/')) {
            $path = $homePath.$path; // Pfad relativ zur WordPress-Adresse (Unterverzeichnis-Installation)
        }
        if (Router::base() === '' || ! str_starts_with($path.'/', $area)) {
            return null;
        }
        if ($query !== null && $query !== '') {
            if (preg_match('/^[A-Za-z0-9_.~%=&+-]*$/', $query) !== 1) {
                return null;
            }
            parse_str($query, $params);
            unset($params[self::PARAM]);
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $path.($query !== null && $query !== '' ? '?'.$query : '');
    }

    /**
     * Einmal-Code anlegen.
     * @return array{url:string,expires_at:string,expires_in:int,redirect:string}
     */
    public static function create(int $userId, int $sessionId, string $path): array
    {
        global $wpdb;
        $code = 'bpw_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $ok = $wpdb->insert(self::table(), [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'code_hash' => hash('sha256', $code),
            'redirect_path' => $path,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::TTL),
        ]);
        if ($ok === false) {
            throw new ApiException('INTERNAL_ERROR', __('Die Anmeldung für die Webansicht konnte nicht vorbereitet werden. Bitte versuche es erneut.', 'bootspruefung-learning'), 500);
        }

        return [
            'url' => self::origin().$path.(str_contains($path, '?') ? '&' : '?').self::PARAM.'='.$code,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + self::TTL),
            'expires_in' => self::TTL,
            'redirect' => self::origin().$path,
        ];
    }

    /**
     * Code einlösen (atomar, genau einmal). Gültig nur, solange die App-Sitzung aktiv ist und das
     * Konto keine Verwaltungsrechte hat. @return ?object Zeile mit user_id und redirect_path
     */
    public static function consume(string $code): ?object
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }
        global $wpdb;
        $hash = hash('sha256', $code);
        $now = gmdate('Y-m-d H:i:s');
        $used = (int) $wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET used_at = %s WHERE code_hash = %s AND used_at IS NULL AND expires_at > %s', $now, $hash, $now));
        if ($used !== 1) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT c.user_id, c.redirect_path FROM '.self::table().' c JOIN '.$wpdb->prefix.'bp_mobile_session s ON s.id = c.session_id AND s.user_id = c.user_id
             WHERE c.code_hash = %s AND s.revoked_at IS NULL AND s.refresh_expires_at > %s AND s.absolute_expires_at > %s',
            $hash, $now, $now
        ));
        if (! $row) {
            return null;
        }
        $user = get_userdata((int) $row->user_id);
        if (! $user || self::privileged($user) || ! apply_filters('bootspruefung_mobile_user_allowed', true, $user)) {
            return null;
        }

        return $row;
    }

    /**
     * Aufruf der Einmal-URL auswerten (ohne Header/Exit, testbar): bei Erfolg Anmeldecookie setzen.
     * @return array{location:string,user_id:int}
     */
    public static function resolveRequest(string $code, string $requestUri): array
    {
        $row = MobileSettings::webSessionEnabled() ? self::consume($code) : null;
        if ($row !== null) {
            $userId = (int) $row->user_id;
            wp_set_auth_cookie($userId, false);
            wp_set_current_user($userId);
            /** Aktion: Web-Sitzung aus der App übernommen (Protokoll, Statistik). */
            do_action('bootspruefung_mobile_web_session', $userId);

            return ['location' => self::origin().$row->redirect_path, 'user_id' => $userId];
        }
        // Kein Hinweis auf den Grund: Anmeldeseite mit dem (geprüften) Ziel der aufgerufenen Adresse.
        $target = self::normalizeTarget((string) remove_query_arg(self::PARAM, (string) wp_parse_url($requestUri, PHP_URL_PATH).(($q = wp_parse_url($requestUri, PHP_URL_QUERY)) ? '?'.$q : '')));

        return ['location' => wp_login_url($target !== null ? self::origin().$target : Router::url()), 'user_id' => 0];
    }

    /** init: ?bp_app_login=… einlösen und weiterleiten (Code verschwindet aus der Adresse). */
    public static function handleRequest(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- Einmal-Code ist selbst der Nachweis
        if (! isset($_GET[self::PARAM]) || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        $code = is_string($_GET[self::PARAM]) ? (string) wp_unslash($_GET[self::PARAM]) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $result = self::resolveRequest($code, (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        wp_safe_redirect($result['location'], 302, 'bootspruefung-learning');
        exit;
    }

    /** Abgelaufene/benutzte Codes nach einem Tag löschen (stündlicher Cron). */
    public static function cleanup(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at < %s LIMIT 5000', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)));
    }
}
