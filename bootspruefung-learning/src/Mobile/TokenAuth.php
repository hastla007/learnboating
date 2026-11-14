<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

defined('ABSPATH') || exit;

/**
 * Gerätesitzungen der App (übernommen aus dem Konzept-Plugin, in die Plugin-Tabelle
 * bp_mobile_session verlegt):
 * - Access-Token 15 Minuten, Refresh-Token rotierend (30 Tage Inaktivität, 90 Tage absolut);
 * - gespeichert werden nur SHA-256-Hashes;
 * - ein bereits rotiertes Refresh-Token erneut vorgelegt → Sitzung wird widerrufen
 *   (Ausnahme seit 1.4.0: das unmittelbar vorherige Refresh-Token innerhalb von 60 Sekunden nach
 *   der Rotation – typischerweise ging die Antwort im Funkloch verloren. Dann wird erneut rotiert:
 *   neues gültiges Token-Paar für dieselbe Sitzung, das verlorene Paar wird ungültig);
 * - Bearer-Tokens gelten ausschließlich für /wp-json/boatingschool/v1/* (nicht für /wp/v2 usw.).
 */
final class TokenAuth
{
    public const ACCESS_TTL = 15 * MINUTE_IN_SECONDS;
    public const REFRESH_TTL = 30 * DAY_IN_SECONDS;
    public const SESSION_MAX_TTL = 90 * DAY_IN_SECONDS;
    /**
     * Kulanz nach einer Rotation (Sekunden): Das unmittelbar vorherige Refresh-Token liefert so lange
     * ein neues Paar (verlorene Antwort), danach widerruft seine Wiederverwendung die Sitzung.
     * Das Fenster zählt ab der ersten Rotation und verlängert sich durch Wiederholungen nicht.
     */
    public const ROTATION_GRACE = 60;
    /** last_used_at höchstens so oft schreiben. */
    private const TOUCH_INTERVAL = 60;

    private static ?object $session = null;
    private static bool $bearerPresented = false;

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_mobile_session';
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function token(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private static function sql(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public static function iso(?string $mysql): ?string
    {
        return $mysql === null || $mysql === '' ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($mysql.' UTC'));
    }

    /** Aktuelle Sitzung (nach erfolgreicher Bearer-Prüfung) oder null. */
    public static function current(): ?object
    {
        return self::$session;
    }

    /** Wurde ein (gültiges oder ungültiges) Bearer-Token mitgeschickt? */
    public static function bearerPresented(): bool
    {
        return self::$bearerPresented;
    }

    /** Für Tests: Zustand der vorherigen Anfrage verwerfen. */
    public static function reset(): void
    {
        self::$session = null;
        self::$bearerPresented = false;
    }

    /**
     * Authorization-Header lesen – auch wenn Apache/FastCGI ihn nicht als HTTP_AUTHORIZATION
     * weiterreicht (REDIRECT_HTTP_AUTHORIZATION bzw. getallheaders()).
     */
    public static function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (! empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return trim(wp_unslash($_SERVER[$key]));
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /** Bearer-Token aus dem Header oder ''. */
    public static function bearer(): string
    {
        $header = self::authorizationHeader();

        return preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,200})$/i', $header, $m) === 1 ? $m[1] : '';
    }

    /** Nutzer zum Access-Token (oder 0). Setzt die aktuelle Sitzung. */
    public static function resolve(string $token): int
    {
        self::$bearerPresented = $token !== '';
        self::$session = null;
        if ($token === '') {
            return 0;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE access_hash = %s AND revoked_at IS NULL LIMIT 1', self::hash($token)));
        $now = time();
        if (! $row || strtotime($row->access_expires_at.' UTC') <= $now || strtotime($row->absolute_expires_at.' UTC') <= $now) {
            return 0;
        }
        $user = get_userdata((int) $row->user_id);
        if (! $user || ! Controllers\AuthController::appAllowed($user)) {
            return 0; // Konto gelöscht bzw. inzwischen Verwaltungskonto (T131)
        }
        if ($now - (int) strtotime($row->last_used_at.' UTC') >= self::TOUCH_INTERVAL) {
            $wpdb->update(self::table(), ['last_used_at' => self::sql($now)], ['id' => (int) $row->id]);
            $row->last_used_at = self::sql($now);
        }
        self::$session = $row;

        return (int) $row->user_id;
    }

    /**
     * Neue Sitzung anlegen.
     * @return array<string,mixed> Token-Paar (Klartext nur in dieser Antwort)
     */
    public static function issue(int $userId, string $deviceName, ?string $platform, ?string $appVersion): array
    {
        global $wpdb;
        $access = self::token('bpa_');
        $refresh = self::token('bpr_');
        $now = time();
        $wpdb->insert(self::table(), [
            'user_id' => $userId,
            'access_hash' => self::hash($access),
            'refresh_hash' => self::hash($refresh),
            'device_name' => mb_substr($deviceName !== '' ? $deviceName : 'App', 0, 100),
            'device_platform' => in_array($platform, ['ios', 'android'], true) ? $platform : null,
            'app_version' => $appVersion !== null && $appVersion !== '' ? mb_substr($appVersion, 0, 40) : null,
            'created_at' => self::sql($now),
            'last_used_at' => self::sql($now),
            'access_expires_at' => self::sql($now + self::ACCESS_TTL),
            'refresh_expires_at' => self::sql($now + self::REFRESH_TTL),
            'absolute_expires_at' => self::sql($now + self::SESSION_MAX_TTL),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id === 0) {
            throw new ApiException('INTERNAL_ERROR', __('Die Anmeldung konnte nicht gespeichert werden. Bitte versuche es erneut.', 'bootspruefung-learning'), 500);
        }

        return self::pair($id, $access, $refresh, $now + self::ACCESS_TTL, $now + self::REFRESH_TTL);
    }

    /**
     * Refresh-Token rotieren.
     * - aktuelles Refresh-Token: bedingt rotieren (von parallelen Aufrufen gewinnt genau einer, der
     *   andere landet im Kulanzweg und erhält ebenfalls ein Paar – gültig ist das zuletzt ausgegebene);
     * - unmittelbar vorheriges Token innerhalb von ROTATION_GRACE: erneut rotieren (prev_refresh_hash
     *   und rotated_at bleiben, damit eine weitere verlorene Antwort ebenfalls aufgefangen wird, das
     *   Fenster aber nicht wächst); das bisher aktuelle Paar wird ungültig;
     * - vorheriges Token nach dem Fenster: Sitzung widerrufen (REFRESH_TOKEN_REUSED).
     * Alle Änderungen sind bedingte UPDATEs (Zeilensperre in InnoDB) – nie zwei gültige Paare.
     * @return array{0:int,1:array<string,mixed>} [user_id, Token-Paar]
     */
    public static function refresh(string $refreshToken): array
    {
        global $wpdb;
        $invalid = new ApiException('UNAUTHENTICATED', __('Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.', 'bootspruefung-learning'), 401);
        if (preg_match('/^[A-Za-z0-9_-]{20,200}$/', $refreshToken) !== 1) {
            throw $invalid;
        }
        $hash = self::hash($refreshToken);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $now = time();
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE refresh_hash = %s LIMIT 1', $hash));
            if ($row) {
                if (! self::usable($row, $now)) {
                    throw $invalid;
                }
                [$access, $refresh, $accessEnd, $refreshEnd] = self::newPair($row, $now);
                $updated = $wpdb->query($wpdb->prepare(
                    'UPDATE '.self::table().' SET access_hash = %s, refresh_hash = %s, prev_refresh_hash = %s, rotated_at = %s, last_used_at = %s, access_expires_at = %s, refresh_expires_at = %s
                     WHERE id = %d AND refresh_hash = %s AND revoked_at IS NULL',
                    self::hash($access), self::hash($refresh), $hash, self::sql($now), self::sql($now), self::sql($accessEnd), self::sql($refreshEnd), (int) $row->id, $hash
                ));
                if ((int) $updated === 1) {
                    return [(int) $row->user_id, self::pair((int) $row->id, $access, $refresh, $accessEnd, $refreshEnd)];
                }
                continue; // gleichzeitig rotiert: jetzt ist das Token das vorherige → Kulanzweg
            }
            // Bereits rotiertes Token erneut vorgelegt?
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE prev_refresh_hash = %s LIMIT 1', $hash));
            if (! $old || $old->revoked_at !== null) {
                throw $invalid;
            }
            $rotatedAt = $old->rotated_at !== null ? (int) strtotime($old->rotated_at.' UTC') : 0;
            if ($rotatedAt === 0 || $now - $rotatedAt > self::ROTATION_GRACE) {
                self::revoke((int) $old->id, 'refresh_reuse');
                throw new ApiException('REFRESH_TOKEN_REUSED', __('Aus Sicherheitsgründen wurde diese Sitzung beendet. Bitte melde dich erneut an.', 'bootspruefung-learning'), 401);
            }
            if (! self::usable($old, $now)) {
                throw $invalid;
            }
            // Antwort der letzten Rotation verloren: erneut rotieren, das verlorene Paar wird ungültig.
            [$access, $refresh, $accessEnd, $refreshEnd] = self::newPair($old, $now);
            $updated = $wpdb->query($wpdb->prepare(
                'UPDATE '.self::table().' SET access_hash = %s, refresh_hash = %s, last_used_at = %s, access_expires_at = %s, refresh_expires_at = %s
                 WHERE id = %d AND prev_refresh_hash = %s AND rotated_at = %s AND revoked_at IS NULL',
                self::hash($access), self::hash($refresh), self::sql($now), self::sql($accessEnd), self::sql($refreshEnd), (int) $old->id, $hash, (string) $old->rotated_at
            ));
            if ((int) $updated === 1) {
                /** Aktion: verlorene Refresh-Antwort nachgeliefert (Protokoll, Statistik). */
                do_action('bootspruefung_mobile_refresh_retry', (int) $old->user_id, (int) $old->id);

                return [(int) $old->user_id, self::pair((int) $old->id, $access, $refresh, $accessEnd, $refreshEnd)];
            }
            // Zeile hat sich zwischen Lesen und Schreiben geändert (paralleler Aufruf): neu lesen.
        }
        throw new ApiException('REFRESH_TOKEN_ROTATED', __('Die Sitzung wurde gerade erneuert. Bitte verwende die zuletzt erhaltenen Tokens.', 'bootspruefung-learning'), 409);
    }

    private static function usable(object $row, int $now): bool
    {
        if ($row->revoked_at !== null || strtotime($row->refresh_expires_at.' UTC') <= $now || strtotime($row->absolute_expires_at.' UTC') <= $now) {
            return false;
        }
        $user = get_userdata((int) $row->user_id);

        return $user instanceof \WP_User && Controllers\AuthController::appAllowed($user);
    }

    /** @return array{0:string,1:string,2:int,3:int} [Access, Refresh, Access-Ende, Refresh-Ende] */
    private static function newPair(object $row, int $now): array
    {
        $absolute = (int) strtotime($row->absolute_expires_at.' UTC');

        return [self::token('bpa_'), self::token('bpr_'), min($now + self::ACCESS_TTL, $absolute), min($now + self::REFRESH_TTL, $absolute)];
    }

    /** @return array<string,mixed> */
    private static function pair(int $sessionId, string $access, string $refresh, int $accessEnd, int $refreshEnd): array
    {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'access_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $accessEnd),
            'expires_in' => max(0, $accessEnd - time()),
            'refresh_token' => $refresh,
            'refresh_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $refreshEnd),
            'session_id' => (string) $sessionId,
        ];
    }

    public static function revoke(int $sessionId, string $reason): bool
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET revoked_at = %s, revoke_reason = %s WHERE id = %d AND revoked_at IS NULL',
            self::sql(time()), mb_substr($reason, 0, 32), $sessionId
        )) > 0;
    }

    /** Eigene Sitzung widerrufen (nur wenn sie dem Nutzer gehört). */
    public static function revokeOwn(int $userId, int $sessionId, string $reason = 'user'): bool
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET revoked_at = %s, revoke_reason = %s WHERE id = %d AND user_id = %d AND revoked_at IS NULL',
            self::sql(time()), $reason, $sessionId, $userId
        )) > 0;
    }

    /** Alle Sitzungen eines Nutzers widerrufen (Passwortänderung, Admin, Kontolöschung). */
    public static function revokeAll(int $userId, string $reason, ?int $exceptSessionId = null): int
    {
        global $wpdb;
        if ($userId <= 0) {
            return 0;
        }

        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET revoked_at = %s, revoke_reason = %s WHERE user_id = %d AND revoked_at IS NULL AND id <> %d',
            self::sql(time()), mb_substr($reason, 0, 32), $userId, $exceptSessionId ?? 0
        ));
    }

    /** @return list<object> aktive Sitzungen eines Nutzers */
    public static function active(int $userId): array
    {
        global $wpdb;
        $now = self::sql(time());

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, device_name, device_platform, app_version, created_at, last_used_at FROM '.self::table().'
             WHERE user_id = %d AND revoked_at IS NULL AND refresh_expires_at > %s AND absolute_expires_at > %s ORDER BY last_used_at DESC, id DESC',
            $userId, $now, $now
        ));
    }

    public static function activeCount(): int
    {
        global $wpdb;
        $now = self::sql(time());

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE revoked_at IS NULL AND refresh_expires_at > %s AND absolute_expires_at > %s', $now, $now));
    }

    /** Abgelaufene und widerrufene Sitzungen nach 7 Tagen löschen (stündlicher Cron). */
    public static function cleanup(): int
    {
        global $wpdb;
        $cut = self::sql(time() - 7 * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at < %s) OR absolute_expires_at < %s OR refresh_expires_at < %s LIMIT 5000',
            $cut, $cut, $cut
        ));
    }
}
