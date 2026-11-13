<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

/**
 * Zähler mit festem Zeitfenster (beginnt mit dem ersten Treffer) in der Tabelle bp_mobile_rate_limit
 * (seit 1.4.0; vorher Transients mit Lesen-Prüfen-Schreiben, nicht atomar): Jeder Treffer ist ein
 * einziges INSERT … ON DUPLICATE KEY UPDATE hits = hits + 1 (nach Fensterende: 1); der neue Stand wird
 * über LAST_INSERT_ID(…) in derselben Anweisung gesetzt und auf derselben Verbindung gelesen –
 * parallele Anfragen können das Limit nicht überholen. Zählername (E-Mail, IP …) nur als gesalzener SHA-256-Hash; abgelaufene
 * Fenster löscht der stündliche Cron.
 *
 * Client-IP: standardmäßig nur REMOTE_ADDR. X-Forwarded-For gilt erst, wenn die direkte Gegenstelle
 * als vertrauenswürdiger Proxy eingetragen ist (Bootsprüfung → Mobile App bzw. Filter
 * bootspruefung_mobile_trusted_proxies); ausgewertet wird von rechts – der erste nicht
 * vertrauenswürdige Eintrag ist der Client (gefälschte linke Einträge zählen nicht).
 */
final class RateLimiter
{
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_mobile_rate_limit';
    }

    /** Anfrage zählen; wirft RATE_LIMITED, sobald mehr als $limit Treffer im Fenster liegen. */
    public static function hit(string $bucket, int $limit, int $window, string $message = ''): void
    {
        [$count, $reset] = self::add($bucket, $window);
        if ($count > $limit) {
            throw ApiException::rateLimited($reset - time(), $message);
        }
    }

    /** Limit erreicht? (ohne zu zählen; $window nur zur Lesbarkeit der Aufrufer, das Fenster legt der erste Treffer fest) */
    public static function check(string $bucket, int $limit, int $window, string $message = ''): void
    {
        [$count, $reset] = self::count($bucket);
        if ($count >= $limit) {
            throw ApiException::rateLimited($reset - time(), $message);
        }
    }

    /** Fehlversuch zählen. @return int Stand im aktuellen Fenster */
    public static function increment(string $bucket, int $window): int
    {
        return self::add($bucket, $window)[0];
    }

    /** Zähler zurücksetzen (alle Fenster dieses Zählers). */
    public static function clear(string $bucket): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['bucket_hash' => self::key($bucket)]);
    }

    /** Abgelaufene Fenster löschen (stündlicher Cron). */
    public static function cleanup(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at < %s LIMIT 10000', gmdate('Y-m-d H:i:s')));
    }

    /** @return array{0:int,1:int} [Treffer, Fensterende] */
    private static function add(string $bucket, int $window): array
    {
        global $wpdb;
        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $endSql = gmdate('Y-m-d H:i:s', $now + max(1, $window));
        $key = self::key($bucket);
        // Atomar in einer Anweisung: einfügen, hochzählen oder – nach Fensterende – neu beginnen (Fenster
        // beginnt mit dem ersten Treffer). LAST_INSERT_ID(expr) merkt sich den neuen Stand für diese Verbindung;
        // expires_at muss als Letztes gesetzt werden (MySQL wertet die Zuweisungen von links nach rechts aus).
        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO '.self::table().' (bucket_hash, window_start, hits, expires_at) VALUES (%s, %d, LAST_INSERT_ID(1), %s)
             ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(expires_at <= %s, 1, hits + 1)), window_start = IF(expires_at <= %s, %d, window_start),
             expires_at = IF(expires_at <= %s, %s, expires_at)',
            $key, $now, $endSql, $nowSql, $nowSql, $now, $nowSql, $endSql
        ));
        if ($ok === false) {
            // Zähler nicht verfügbar (z. B. Migration noch nicht gelaufen): nicht sperren, aber protokollieren.
            error_log('[bootspruefung-learning mobile] Rate-Limit-Zähler nicht verfügbar: '.$wpdb->last_error);

            return [0, $now + $window];
        }
        $hits = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        $end = $wpdb->get_var($wpdb->prepare('SELECT expires_at FROM '.self::table().' WHERE bucket_hash = %s', $key));

        return [$hits, $end !== null ? (int) strtotime($end.' UTC') : $now + $window];
    }

    /** @return array{0:int,1:int} [Treffer, Fensterende] */
    private static function count(string $bucket): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT hits, expires_at FROM '.self::table().' WHERE bucket_hash = %s AND expires_at > %s', self::key($bucket), gmdate('Y-m-d H:i:s')));

        return $row ? [(int) $row->hits, (int) strtotime($row->expires_at.' UTC')] : [0, time()];
    }

    private static function key(string $bucket): string
    {
        // E-Mail/IP nicht im Klartext speichern.
        return hash('sha256', $bucket.'|'.wp_salt('nonce'));
    }

    // ------------------------------------------------------------------ Client-IP

    public static function clientIp(): string
    {
        $remote = self::cleanIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $ip = $remote ?? '0.0.0.0';
        if ($remote !== null && self::trusted($remote)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $chain = array_values(array_filter(array_map(static fn ($p) => self::cleanIp(trim($p)), explode(',', $forwarded))));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $ip = $chain[$i];
                if (! self::trusted($chain[$i])) {
                    break;
                }
            }
            if ($chain === [] && ($real = self::cleanIp((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''))) !== null) {
                $ip = $real;
            }
        }

        /** Filter: Client-IP für Rate-Limits anpassen (z. B. eigener CDN-Header). */
        return (string) apply_filters('bootspruefung_mobile_client_ip', $ip);
    }

    /**
     * Schlüssel einer IP für Zähler: IPv4 unverändert, IPv6 als /64-Netz (ein Anschluss bekommt meist ein ganzes
     * /64 – sonst könnte ein Angreifer je Versuch eine neue Adresse nehmen; To-do T41). IPv4 in IPv6 (::ffff:a.b.c.d)
     * zählt als IPv4.
     */
    public static function ipKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /**
     * Steht ein Proxy vor WordPress, ohne dass er eingetragen ist? Dann teilen sich alle Besucher die IP des Proxys
     * und damit die Anmelde-Limits (To-do T41). Hinweis für Systemstatus und Einstellungen, sonst null.
     */
    public static function proxyWarning(): ?string
    {
        $remote = self::cleanIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === null || self::trusted($remote)) {
            return null;
        }
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? ''));
        $private = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if ($forwarded === '' && ! $private) {
            return null;
        }

        return sprintf(
            /* translators: %s: IP-Adresse der direkten Gegenstelle */
            __('Die Anfrage kommt über %s (Proxy bzw. internes Netz), dieser ist aber nicht als vertrauenswürdiger Proxy eingetragen. Dann zählen alle Besucher mit dieser Adresse, und die Anmelde-Limits treffen alle gemeinsam. Proxy unter Bootsprüfung → Mobile App → „Vertrauenswürdige Proxys“ eintragen.', 'bootspruefung-learning'),
            $remote
        );
    }

    /**
     * Vertrauenswürdige Proxys (IP oder CIDR): Einstellung „Vertrauenswürdige Proxys“ plus Filter.
     * Standard: keine – auch Loopback und private Netze nicht (sonst könnte jeder Dienst im selben
     * Netz bzw. eine Fehlkonfiguration die Client-IP vorgeben).
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        /** Filter: vertrauenswürdige Proxys (Liste von IPs/CIDRs), z. B. Docker-Netz des Reverse-Proxys. */
        $list = apply_filters('bootspruefung_mobile_trusted_proxies', MobileSettings::trustedProxies());

        return array_values(array_filter(array_map('strval', is_array($list) ? $list : []), [self::class, 'validCidr']));
    }

    private static function cleanIp(string $value): ?string
    {
        $value = trim($value, " \t\"[]");
        // IPv4 mit Port (1.2.3.4:5678)
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m) === 1) {
            $value = $m[1];
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private static function trusted(string $ip): bool
    {
        foreach (self::trustedProxies() as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public static function validCidr(string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if (filter_var($net, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = str_contains((string) $net, ':') ? 128 : 32;

        return $bits === null || (ctype_digit((string) $bits) && (int) $bits <= $max);
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        if (! self::validCidr($cidr)) {
            return false;
        }
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = inet_pton($ip);
        $b = inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }
}
