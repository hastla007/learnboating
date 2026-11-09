<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

/**
 * Einfache Zähler mit festem Zeitfenster (Transients, mit Objekt-Cache im Speicher) und
 * Ermittlung der Client-IP hinter Reverse-Proxys: X-Forwarded-For gilt nur, wenn die
 * direkte Gegenstelle ein vertrauenswürdiger Proxy ist (Loopback, private Netze oder in
 * den App-Einstellungen eingetragen). Ausgewertet wird von rechts – der erste nicht
 * vertrauenswürdige Eintrag ist der Client (gefälschte linke Einträge zählen nicht).
 */
final class RateLimiter
{
    private const PREFIX = 'bp_mobile_rl_';

    /** Anfrage zählen; wirft RATE_LIMITED, wenn das Limit im Fenster erreicht ist. */
    public static function hit(string $bucket, int $limit, int $window, string $message = ''): void
    {
        $state = self::state($bucket);
        if ($state['count'] >= $limit) {
            throw ApiException::rateLimited($state['reset'] - time(), $message);
        }
        self::store($bucket, $state['count'] + 1, $state['count'] === 0 ? time() + $window : $state['reset']);
    }

    /** Limit erreicht? (ohne zu zählen) */
    public static function check(string $bucket, int $limit, string $message = ''): void
    {
        $state = self::state($bucket);
        if ($state['count'] >= $limit) {
            throw ApiException::rateLimited($state['reset'] - time(), $message);
        }
    }

    /** Fehlversuch zählen (Fenster beginnt mit dem ersten Fehlversuch). */
    public static function increment(string $bucket, int $window): void
    {
        $state = self::state($bucket);
        self::store($bucket, $state['count'] + 1, $state['count'] === 0 ? time() + $window : $state['reset']);
    }

    public static function clear(string $bucket): void
    {
        delete_transient(self::key($bucket));
    }

    /** @return array{count:int,reset:int} */
    private static function state(string $bucket): array
    {
        $value = get_transient(self::key($bucket));
        if (! is_array($value) || (int) ($value['reset'] ?? 0) <= time()) {
            return ['count' => 0, 'reset' => time()];
        }

        return ['count' => (int) $value['count'], 'reset' => (int) $value['reset']];
    }

    private static function store(string $bucket, int $count, int $reset): void
    {
        set_transient(self::key($bucket), ['count' => $count, 'reset' => $reset], max(1, $reset - time()));
    }

    private static function key(string $bucket): string
    {
        // Transient-Namen ≤ 172 Zeichen; E-Mail/IP nicht im Klartext in wp_options.
        return self::PREFIX.substr(hash('sha256', $bucket.'|'.wp_salt('nonce')), 0, 40);
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
        // Loopback und private Netze (Docker, Reverse-Proxy im selben Netz).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        foreach (MobileSettings::trustedProxies() as $cidr) {
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
