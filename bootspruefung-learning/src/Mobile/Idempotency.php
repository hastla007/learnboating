<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

/**
 * Idempotency-Key je Nutzer und Vorgang: gleicher Schlüssel + gleicher Inhalt liefert die
 * gespeicherte Antwort erneut, gleicher Schlüssel + anderer Inhalt → IDEMPOTENCY_KEY_REUSED.
 * Gespeichert werden nur erfolgreiche Antworten; schlägt ein Vorgang fehl, ist der Schlüssel
 * wieder frei. Aufbewahrung 7 Tage.
 */
final class Idempotency
{
    public const RETENTION = 7 * DAY_IN_SECONDS;

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
            if ($existing) {
                return self::replay($existing, $requestHash);
            }
            throw new ApiException('INTERNAL_ERROR', __('Die Anfrage konnte nicht gespeichert werden. Bitte versuche es erneut.', 'bootspruefung-learning'), 500);
        }
        $id = (int) $wpdb->insert_id;
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
