<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

defined('ABSPATH') || exit;

/**
 * Idempotency-Key je Nutzer und Vorgang: gleicher Schlüssel + gleicher Inhalt liefert die
 * gespeicherte Antwort erneut, gleicher Schlüssel + anderer Inhalt → IDEMPOTENCY_KEY_REUSED.
 * Gespeichert werden nur erfolgreiche Antworten; schlägt ein Vorgang fehl, ist der Schlüssel
 * wieder frei. Aufbewahrung 7 Tage.
 *
 * Seit 1.4.0: Bricht PHP hart ab (Fatal Error, Timeout, OOM), bliebe die Zeile „in Arbeit“
 * (status_code 0) stehen und jede Wiederholung erhielte 7 Tage lang REQUEST_IN_PROGRESS. Eine
 * solche Zeile gilt nach ORPHAN_AFTER Sekunden als verwaist; die nächste Wiederholung (gleicher
 * Inhalt) übernimmt sie atomar (bedingtes UPDATE auf created_at) und führt den Vorgang aus.
 */
final class Idempotency
{
    public const RETENTION = 7 * DAY_IN_SECONDS;
    /** „In Arbeit“ älter als so viele Sekunden = verwaist (Prozess abgebrochen), darf übernommen werden. */
    public const ORPHAN_AFTER = 60;

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_mobile_idempotency';
    }

    /**
     * @param callable():array{0:int,1:mixed,2:?string} $operation liefert [Status, Body, Ressource]
     * @return array{0:int,1:mixed,2:bool} [Status, Body, wiederholt]
     */
    public static function run(int $userId, string $scope, ?string $key, array $payload, callable $operation): array
    {
        if ($key === null) {
            [$status, $body] = $operation();

            return [$status, $body, false];
        }
        global $wpdb;
        $requestHash = hash('sha256', $scope.'|'.(string) wp_json_encode(self::normalize($payload)));
        $now = time();
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id = %d AND scope = %s AND idem_key = %s', $userId, $scope, $key));
        if ($existing && strtotime($existing->expires_at.' UTC') <= $now) {
            $wpdb->delete(self::table(), ['id' => (int) $existing->id]);
            $existing = null;
        }
        if ($existing && ($id = self::takeOver($existing, $requestHash, $now)) !== null) {
            return self::execute($id, $operation);
        }
        if ($existing) {
            return self::replay($existing, $requestHash);
        }
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(self::table(), [
            'user_id' => $userId, 'scope' => $scope, 'idem_key' => $key, 'request_hash' => $requestHash, 'status_code' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + self::RETENTION),
        ]);
        $wpdb->suppress_errors($suppress);
        if ($inserted === false) {
            $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE user_id = %d AND scope = %s AND idem_key = %s', $userId, $scope, $key));
            if ($existing && ($id = self::takeOver($existing, $requestHash, $now)) !== null) {
                return self::execute($id, $operation);
            }
            if ($existing) {
                return self::replay($existing, $requestHash);
            }
            throw new ApiException('INTERNAL_ERROR', __('Die Anfrage konnte nicht gespeichert werden. Bitte versuche es erneut.', 'bootspruefung-learning'), 500);
        }

        return self::execute((int) $wpdb->insert_id, $operation);
    }

    /**
     * Verwaiste Zeile „in Arbeit“ übernehmen: nur bei gleichem Inhalt, nur wenn älter als
     * ORPHAN_AFTER; von parallelen Wiederholungen gewinnt genau eine (bedingtes UPDATE).
     * @return ?int ID der übernommenen Zeile
     */
    private static function takeOver(object $row, string $requestHash, int $now): ?int
    {
        if ((int) $row->status_code !== 0 || ! hash_equals((string) $row->request_hash, $requestHash)
            || strtotime($row->created_at.' UTC') > $now - self::ORPHAN_AFTER) {
            return null;
        }
        global $wpdb;
        $taken = $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET created_at = %s WHERE id = %d AND status_code = 0 AND created_at = %s',
            gmdate('Y-m-d H:i:s', $now), (int) $row->id, (string) $row->created_at
        ));

        return (int) $taken === 1 ? (int) $row->id : null;
    }

    /**
     * Vorgang zur reservierten Zeile ausführen und Antwort speichern (bei Fehler Zeile freigeben).
     * @param callable():array{0:int,1:mixed,2:?string} $operation
     * @return array{0:int,1:mixed,2:bool}
     */
    private static function execute(int $id, callable $operation): array
    {
        global $wpdb;
        try {
            [$status, $body, $resource] = array_pad($operation(), 3, null);
        } catch (\Throwable $e) {
            $wpdb->delete(self::table(), ['id' => $id]);
            throw $e;
        }
        $wpdb->update(self::table(), ['status_code' => (int) $status, 'response' => wp_json_encode($body), 'resource' => $resource], ['id' => $id]);

        return [(int) $status, $body, false];
    }

    /** Gespeicherte Antwort zu einer Ressource (z. B. attempt:123) des Nutzers. */
    public static function byResource(int $userId, string $resource): mixed
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            'SELECT response FROM '.self::table().' WHERE user_id = %d AND resource = %s AND status_code > 0 AND expires_at > %s ORDER BY id DESC LIMIT 1',
            $userId, $resource, gmdate('Y-m-d H:i:s')
        ));

        return $json === null ? null : json_decode((string) $json, true);
    }

    /** @return array{0:int,1:mixed,2:bool} */
    private static function replay(object $row, string $requestHash): array
    {
        if (! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new ApiException('IDEMPOTENCY_KEY_REUSED', __('Dieser Idempotency-Key wurde bereits für eine andere Anfrage verwendet.', 'bootspruefung-learning'), 409);
        }
        if ((int) $row->status_code === 0) {
            throw new ApiException('REQUEST_IN_PROGRESS', __('Diese Anfrage wird gerade bearbeitet. Bitte versuche es gleich noch einmal.', 'bootspruefung-learning'), 409, ['retry_after' => 2]);
        }

        return [(int) $row->status_code, json_decode((string) $row->response, true), true];
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map([self::class, 'normalize'], $value);
        }

        return $value;
    }

    public static function cleanup(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at < %s LIMIT 5000', gmdate('Y-m-d H:i:s')));
    }
}
