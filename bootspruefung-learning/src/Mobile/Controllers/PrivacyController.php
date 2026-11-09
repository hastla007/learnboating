<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Datenschutz in der App:
 * - Export (Art. 15/20 DSGVO) über alle registrierten WordPress-Datenexporte – unsere
 *   PersonalData-Quellen (Lernstand, Prüfungen, Zugänge, App-Sitzungen), das WordPress-Konto
 *   und WooCommerce (Kundendaten, Bestellungen) – als JSON. Abruf nur mit Anmeldung über
 *   die API (kein öffentlicher Upload-Ordner); automatische Löschung nach 24 Stunden.
 * - Kontolöschung beantragen: WordPress-Datenschutzanfrage „Löschung“ mit Bestätigungsmail
 *   (App-Store-Vorgabe von Apple: Weg zur Kontolöschung in der App).
 */
final class PrivacyController extends BaseController
{
    public const EXPORT_TTL = DAY_IN_SECONDS;
    private const MAX_PAGES = 500;

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'bp_mobile_export';
    }

    public function requestExport(WP_REST_Request $request): WP_REST_Response
    {
        $userId = $this->user();
        [$status, $body] = Idempotency::run($userId, 'export', $this->idempotencyKey($request, false), [], function () use ($userId): array {
            RateLimiter::hit('export|'.$userId, 5, DAY_IN_SECONDS, __('Du hast heute schon mehrere Exporte angefordert. Bitte versuche es morgen erneut.', 'bootspruefung-learning'));
            $row = $this->createExport($userId);

            return [202, ['data' => $this->exportPayload($row)], 'export:'.$row->public_id];
        });

        return new WP_REST_Response($body, $status);
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        return $this->ok($this->exportPayload($this->ownExport((string) $request['exportId'])));
    }

    /** JSON-Datei (nur mit Bearer-Token des Eigentümers). */
    public function download(WP_REST_Request $request): WP_REST_Response
    {
        $row = $this->ownExport((string) $request['exportId']);
        if ($row->status !== 'ready' || strtotime($row->expires_at.' UTC') <= time()) {
            throw new ApiException('EXPORT_EXPIRED', __('Dieser Export ist abgelaufen. Bitte fordere einen neuen an.', 'bootspruefung-learning'), 410);
        }
        $response = new WP_REST_Response(json_decode((string) $row->payload, true), 200);
        $response->header('Content-Disposition', 'attachment; filename="datenexport-'.gmdate('Y-m-d', (int) strtotime($row->created_at.' UTC')).'.json"');

        return $response;
    }

    public function deletionRequest(): WP_REST_Response
    {
        $userId = $this->user();
        $user = get_userdata($userId);
        if (! $user) {
            throw new ApiException('UNAUTHENTICATED', __('Bitte melde dich an.', 'bootspruefung-learning'), 401);
        }
        RateLimiter::hit('deletion|'.$userId, 5, DAY_IN_SECONDS);
        $existing = $this->pendingDeletion((string) $user->user_email);
        if ($existing !== null) {
            return new WP_REST_Response(['data' => $this->deletionPayload($existing, true)], 202);
        }
        $id = wp_create_user_request((string) $user->user_email, 'remove_personal_data', [], 'pending');
        if (is_wp_error($id)) {
            throw new ApiException('VALIDATION_FAILED', $id->get_error_message(), 422);
        }
        $sent = wp_send_user_request((int) $id);
        if (is_wp_error($sent)) {
            error_log('[bootspruefung-learning mobile] Bestätigungsmail Löschantrag fehlgeschlagen: '.$sent->get_error_message());
        }

        return new WP_REST_Response(['data' => $this->deletionPayload((int) $id, false)], 202);
    }

    // ------------------------------------------------------------------

    private function createExport(int $userId): object
    {
        $user = get_userdata($userId);
        if (! $user) {
            throw new ApiException('UNAUTHENTICATED', __('Bitte melde dich an.', 'bootspruefung-learning'), 401);
        }
        $email = (string) $user->user_email;
        $groups = [];
        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        foreach ((array) $exporters as $key => $exporter) {
            if (! is_array($exporter) || ! isset($exporter['callback']) || ! is_callable($exporter['callback'])) {
                continue;
            }
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $result = call_user_func($exporter['callback'], $email, $page);
                if (! is_array($result) || ! isset($result['data']) || ! is_array($result['data'])) {
                    break;
                }
                foreach ($result['data'] as $item) {
                    $gid = (string) ($item['group_id'] ?? $key);
                    $groups[$gid] ??= ['label' => (string) ($item['group_label'] ?? $exporter['exporter_friendly_name'] ?? $gid), 'items' => []];
                    $fields = [];
                    foreach ((array) ($item['data'] ?? []) as $field) {
                        $fields[(string) ($field['name'] ?? '')] = $field['value'] ?? null;
                    }
                    $groups[$gid]['items'][] = ['id' => (string) ($item['item_id'] ?? ''), 'data' => $fields];
                }
                if (! empty($result['done'])) {
                    break;
                }
            }
        }
        $payload = (string) wp_json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => home_url('/'),
            'user' => ['id' => (string) $userId, 'email' => $email],
            'groups' => $groups,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        global $wpdb;
        $now = time();
        // Nur der neueste Export bleibt; ältere des Nutzers werden ersetzt.
        $wpdb->delete(self::table(), ['user_id' => $userId]);
        $publicId = bin2hex(random_bytes(16));
        $wpdb->insert(self::table(), [
            'user_id' => $userId, 'public_id' => $publicId, 'status' => 'ready', 'payload' => $payload, 'size_bytes' => strlen($payload),
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + self::EXPORT_TTL),
        ]);

        return $wpdb->get_row($wpdb->prepare('SELECT id, user_id, public_id, status, size_bytes, created_at, expires_at FROM '.self::table().' WHERE public_id = %s', $publicId));
    }

    private function ownExport(string $publicId): object
    {
        global $wpdb;
        $row = preg_match('/^[0-9a-f]{32}$/', $publicId) === 1
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE public_id = %s AND user_id = %d', $publicId, $this->user()))
            : null;
        if (! $row) {
            throw ApiException::notFound(__('Export nicht gefunden.', 'bootspruefung-learning'));
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function exportPayload(object $row): array
    {
        $expired = strtotime($row->expires_at.' UTC') <= time();

        return [
            'export_id' => (string) $row->public_id,
            'status' => $expired ? 'expired' : (string) $row->status,
            'download_url' => $expired ? null : rest_url('boatingschool/v1/profile/export/'.$row->public_id.'/download'),
            'download_requires_authorization' => true,
            'size_bytes' => (int) $row->size_bytes,
            'created_at' => TokenAuth::iso($row->created_at),
            'expires_at' => TokenAuth::iso($row->expires_at),
        ];
    }

    private function pendingDeletion(string $email): ?int
    {
        $ids = get_posts([
            'post_type' => 'user_request', 'post_name__in' => ['remove_personal_data'], 'title' => $email,
            'post_status' => ['request-pending', 'request-confirmed'], 'fields' => 'ids', 'numberposts' => 1, 'suppress_filters' => true,
        ]);

        return $ids !== [] ? (int) $ids[0] : null;
    }

    /** @return array<string,mixed> */
    private function deletionPayload(int $requestId, bool $existing): array
    {
        $status = (string) get_post_status($requestId);

        return [
            'request_id' => (string) $requestId,
            'status' => $status === 'request-confirmed' ? 'confirmed' : 'pending_confirmation',
            'already_requested' => $existing,
            'message' => $status === 'request-confirmed'
                ? __('Du hast die Löschung bereits bestätigt. Wir bearbeiten deinen Antrag und melden uns per E-Mail.', 'bootspruefung-learning')
                : __('Wir haben dir eine E-Mail geschickt. Bitte bestätige darin die Löschung deines Kontos.', 'bootspruefung-learning'),
        ];
    }

    public static function cleanup(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE expires_at < %s LIMIT 1000', gmdate('Y-m-d H:i:s')));
    }
}
