<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

/**
 * Synchronisierte App-Einstellungen je Konto (GET/PUT /me/settings): ein von der App definiertes
 * JSON-Objekt in der Nutzer-Meta bp_app_settings, mit Versionszähler für optimistisches Sperren.
 * Grenzen: nur Objekt, höchstens 8 KB (kompaktes UTF-8-JSON), Verschachtelung höchstens 4 Ebenen
 * (das Objekt selbst zählt als Ebene 1), Schlüssel ^[a-z0-9_.-]{1,64}$, Werte Skalar/Liste/Objekt.
 * Gespeichert als JSON-Text, damit leere Objekte ({}) als Objekte erhalten bleiben.
 */
final class AppSettings
{
    public const META = 'bp_app_settings';
    public const MAX_BYTES = 8192;
    public const MAX_DEPTH = 4;
    public const KEY_PATTERN = '/^[a-z0-9_.-]{1,64}$/';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private static function raw(int $userId): ?string
    {
        wp_cache_delete($userId, 'user_meta');
        $value = get_user_meta($userId, self::META, true);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{settings:object,version:int,updated_at:?string} */
    private static function decode(?string $raw): array
    {
        $stored = $raw !== null ? json_decode($raw, false) : null;
        if (! $stored instanceof \stdClass || ! isset($stored->settings) || ! $stored->settings instanceof \stdClass) {
            return ['settings' => new \stdClass(), 'version' => 0, 'updated_at' => null];
        }

        return ['settings' => $stored->settings, 'version' => (int) ($stored->version ?? 0), 'updated_at' => isset($stored->updated_at) ? (string) $stored->updated_at : null];
    }

    /** @return array{settings:object,version:int,updated_at:?string} */
    public static function get(int $userId): array
    {
        return self::decode(self::raw($userId));
    }

    /** @return array<string,list<string>> Feldfehler (leer = gültig) */
    public static function validate(mixed $settings): array
    {
        if (! $settings instanceof \stdClass) {
            return ['settings' => [__('Die Einstellungen müssen ein JSON-Objekt sein.', 'bootspruefung-learning')]];
        }
        $errors = [];
        self::walk($settings, 1, 'settings', $errors);
        if ($errors === [] && strlen((string) wp_json_encode($settings, self::JSON_FLAGS)) > self::MAX_BYTES) {
            $errors['settings'][] = sprintf(__('Die Einstellungen dürfen höchstens %d Bytes groß sein.', 'bootspruefung-learning'), self::MAX_BYTES);
        }

        return $errors;
    }

    /** @param array<string,list<string>> $errors */
    private static function walk(mixed $value, int $depth, string $path, array &$errors): void
    {
        if (count($errors) >= 20) {
            return;
        }
        if (! $value instanceof \stdClass && ! is_array($value)) {
            if (! is_scalar($value) && $value !== null) {
                $errors[$path][] = __('Nicht unterstützter Wert.', 'bootspruefung-learning');
            }

            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[$path][] = sprintf(__('Höchstens %d Ebenen verschachtelt.', 'bootspruefung-learning'), self::MAX_DEPTH);

            return;
        }
        foreach ((array) $value as $key => $child) {
            if ($value instanceof \stdClass && preg_match(self::KEY_PATTERN, (string) $key) !== 1) {
                $errors[$path][] = sprintf(__('Ungültiger Schlüssel „%s“ (erlaubt: a–z, 0–9, _ . -, höchstens 64 Zeichen).', 'bootspruefung-learning'), mb_substr((string) $key, 0, 64));
                continue;
            }
            self::walk($child, $depth + 1, $path.'.'.$key, $errors);
        }
    }

    /**
     * Einstellungen vollständig ersetzen. $expected = erwartete Version (If-Match/expected_version)
     * oder null. Bedingtes Schreiben (vorheriger Wert als Bedingung): von zwei gleichzeitigen
     * Schreibvorgängen mit derselben Version gewinnt genau einer.
     * @return array{settings:object,version:int,updated_at:?string}
     */
    public static function put(int $userId, \stdClass $settings, ?int $expected): array
    {
        for ($try = 0; $try < 3; $try++) {
            $raw = self::raw($userId);
            $current = self::decode($raw);
            if ($expected !== null && $expected !== $current['version']) {
                throw self::conflict($current);
            }
            $next = [
                'settings' => $settings,
                'version' => $current['version'] + 1,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $json = (string) wp_json_encode($next, self::JSON_FLAGS);
            // update_user_meta() entfernt Backslashes (wp_unslash) – deshalb vorher wp_slash().
            if ($raw === null && metadata_exists('user', $userId, self::META)) {
                // Unlesbarer Altwert (kein JSON-Text): ohne Bedingung überschreiben.
                $ok = (bool) update_user_meta($userId, self::META, wp_slash($json));
            } else {
                $ok = $raw === null
                    ? (bool) add_user_meta($userId, self::META, wp_slash($json), true)
                    : (bool) update_user_meta($userId, self::META, wp_slash($json), $raw);
            }
            if ($ok) {
                return self::decode($json);
            }
            if ($expected !== null) {
                throw self::conflict(self::get($userId));
            }
        }
        throw new ApiException('REQUEST_IN_PROGRESS', __('Die Einstellungen werden gerade von einem anderen Gerät gespeichert. Bitte versuche es gleich noch einmal.', 'bootspruefung-learning'), 409, ['retry_after' => 2]);
    }

    /** @param array{settings:object,version:int,updated_at:?string} $current */
    private static function conflict(array $current): ApiException
    {
        return new ApiException(
            'VERSION_CONFLICT',
            __('Die Einstellungen wurden inzwischen auf einem anderen Gerät geändert. Bitte übernimm den aktuellen Stand und speichere erneut.', 'bootspruefung-learning'),
            409,
            ['details' => ['current' => $current]]
        );
    }

    public static function delete(int $userId): bool
    {
        return delete_user_meta($userId, self::META);
    }
}
