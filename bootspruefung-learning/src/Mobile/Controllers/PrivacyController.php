<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\AppLinks;
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
 *   Seit 1.4.0 asynchron: POST legt den Export als „pending“ an (202) und plant die Erzeugung
 *   (Action Scheduler, falls vorhanden – sonst WP-Cron-Einzelereignis); GET /profile/export/{id}
 *   meldet pending|ready|failed und erzeugt ihn notfalls selbst, wenn er nach 30 Sekunden noch
 *   wartet (Cron aus/träge). Eine Sperre (started_at, bedingtes UPDATE) verhindert Doppelläufe.
 * - Kontolöschung beantragen: WordPress-Datenschutzanfrage „Löschung“ mit Bestätigungsmail
 *   (App-Store-Vorgabe von Apple: Weg zur Kontolöschung in der App). Seit 1.4.0 zeigt der Link der
 *   Mail bei eingetragenen App-Kennungen auf /app/konto-loeschen/ (öffnet die App; ohne App
 *   Weiterleitung zur WordPress-Bestätigung); die App bestätigt mit POST /me/deletion-request/confirm.
 */
final class PrivacyController extends BaseController
{
    public const EXPORT_TTL = DAY_IN_SECONDS;
    /** Hintergrundaufgabe, die einen wartenden Export erzeugt (Argument: export_id). */
    public const GENERATE_HOOK = 'bp_mobile_export_generate';
    /** Wartet ein Export so lange, erzeugt ihn die Statusabfrage selbst. */
    public const POLL_FALLBACK_AFTER = 30;
    /** Sperre eines Erzeugungslaufs; danach darf ein anderer Lauf übernehmen (abgebrochener Prozess). */
    private const LOCK_TTL = 300;
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
            self::schedule((string) $row->public_id);

            return [202, ['data' => $this->exportPayload($row)], 'export:'.$row->public_id];
        });

        return new WP_REST_Response($body, $status);
    }

    /** Status pending|ready|failed|expired; wartet der Export zu lange, wird er hier erzeugt. */
    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $row = $this->ownExport((string) $request['exportId']);
        if ($row->status === 'pending' && strtotime($row->created_at.' UTC') <= time() - self::POLL_FALLBACK_AFTER) {
            self::generate((string) $row->public_id);
            $row = $this->ownExport((string) $request['exportId']);
        }

        return $this->ok($this->exportPayload($row));
    }

    /**
     * JSON-Datei (nur mit Bearer-Token des Eigentümers). Kein Range-Support (Accept-Ranges: none):
     * Der Export ist eine kleine JSON-Datei, die als Ganzes geladen wird.
     */
    public function download(WP_REST_Request $request): WP_REST_Response
    {
        $row = $this->ownExport((string) $request['exportId']);
        if ($row->status === 'pending' && strtotime($row->expires_at.' UTC') > time()) {
            throw new ApiException('EXPORT_NOT_READY', __('Dein Export wird noch erstellt. Bitte versuche es gleich noch einmal.', 'bootspruefung-learning'), 409, ['retry_after' => 5]);
        }
        if ($row->status === 'failed') {
            throw new ApiException('EXPORT_FAILED', __('Dein Export konnte nicht erstellt werden. Bitte fordere einen neuen an.', 'bootspruefung-learning'), 410);
        }
        if ($row->status !== 'ready' || strtotime($row->expires_at.' UTC') <= time()) {
            throw new ApiException('EXPORT_EXPIRED', __('Dieser Export ist abgelaufen. Bitte fordere einen neuen an.', 'bootspruefung-learning'), 410);
        }
        $response = new WP_REST_Response(json_decode((string) $row->payload, true), 200);
        $response->header('Content-Disposition', FileController::contentDisposition('attachment', 'datenexport-'.gmdate('Y-m-d', (int) strtotime($row->created_at.' UTC')).'.json'));
        $response->header('Accept-Ranges', 'none');

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
        // Mit eingetragenen App-Kennungen zeigt der Bestätigungslink auf /app/konto-loeschen/ (öffnet die App).
        $sent = AppLinks::withAppDeletionLink(static fn () => wp_send_user_request((int) $id));
        if (is_wp_error($sent)) {
            error_log('[bootspruefung-learning mobile] Bestätigungsmail Löschantrag fehlgeschlagen: '.$sent->get_error_message());
        }

        return new WP_REST_Response(['data' => $this->deletionPayload((int) $id, false)], 202);
    }

    /**
     * Löschantrag aus dem Mail-Link in der App bestätigen (ohne Anmeldung, wie wp-login.php?action=confirmaction):
     * wp_validate_user_request_key() und danach dieselbe Aktion wie WordPress (user_request_action_confirmed →
     * Status „bestätigt“ und Benachrichtigung an die Verwaltung). Ungültig, abgelaufen, schon bestätigt
     * oder kein Löschantrag → einheitlich 422 (kein Hinweis, ob es den Antrag gibt).
     */
    public function deletionConfirm(WP_REST_Request $request): WP_REST_Response
    {
        RateLimiter::hit('deletion-confirm-ip|'.RateLimiter::clientIp(), 20, HOUR_IN_SECONDS);
        $raw = $request['request_id'];
        $id = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
        $key = sanitize_text_field((string) $request['confirm_key']);
        $invalid = new ApiException('DELETION_CONFIRMATION_INVALID', __('Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte beantrage die Löschung in der App erneut.', 'bootspruefung-learning'), 422);
        if ($id <= 0 || $key === '') {
            throw $invalid;
        }
        RateLimiter::hit('deletion-confirm|'.$id, 10, HOUR_IN_SECONDS);
        $userRequest = wp_get_user_request($id);
        if (! $userRequest || $userRequest->action_name !== 'remove_personal_data' || is_wp_error(wp_validate_user_request_key($id, $key))) {
            throw $invalid;
        }
        /** Core-Aktion wie in wp-login.php (action=confirmaction): Antrag bestätigt, Verwaltung benachrichtigt. */
        do_action('user_request_action_confirmed', $id);

        return $this->ok($this->deletionPayload($id, false));
    }

    // ------------------------------------------------------------------

    /** Export als „pending“ anlegen; nur der neueste Export eines Kontos bleibt. */
    private function createExport(int $userId): object
    {
        if (! get_userdata($userId)) {
            throw new ApiException('UNAUTHENTICATED', __('Bitte melde dich an.', 'bootspruefung-learning'), 401);
        }
        global $wpdb;
        $now = time();
        $wpdb->delete(self::table(), ['user_id' => $userId]);
        $publicId = bin2hex(random_bytes(16));
        $wpdb->insert(self::table(), [
            'user_id' => $userId, 'public_id' => $publicId, 'status' => 'pending', 'payload' => null, 'size_bytes' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now), 'expires_at' => gmdate('Y-m-d H:i:s', $now + self::EXPORT_TTL),
        ]);

        return $wpdb->get_row($wpdb->prepare('SELECT id, user_id, public_id, status, size_bytes, created_at, expires_at FROM '.self::table().' WHERE public_id = %s', $publicId));
    }

    /** Erzeugung im Hintergrund planen: Action Scheduler (WooCommerce), sonst WP-Cron-Einzelereignis. */
    public static function schedule(string $publicId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::GENERATE_HOOK, [$publicId], 'bootspruefung-learning');

            return;
        }
        wp_schedule_single_event(time(), self::GENERATE_HOOK, [$publicId]);
    }

    /**
     * Hintergrundaufgabe (und Rückfall der Statusabfrage): wartenden Export erzeugen. Genau ein Lauf
     * gleichzeitig (bedingtes UPDATE auf started_at; nach LOCK_TTL darf ein neuer Lauf übernehmen).
     */
    public static function generate($publicId): void
    {
        global $wpdb;
        if (! is_string($publicId) || preg_match('/^[0-9a-f]{32}$/', $publicId) !== 1) {
            return;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, user_id, status FROM '.self::table().' WHERE public_id = %s', $publicId));
        if (! $row || $row->status !== 'pending') {
            return;
        }
        $now = time();
        $locked = $wpdb->query($wpdb->prepare(
            "UPDATE ".self::table()." SET started_at = %s WHERE id = %d AND status = 'pending' AND (started_at IS NULL OR started_at < %s)",
            gmdate('Y-m-d H:i:s', $now), (int) $row->id, gmdate('Y-m-d H:i:s', $now - self::LOCK_TTL)
        ));
        if ((int) $locked !== 1) {
            return;
        }
        try {
            $payload = self::buildPayload((int) $row->user_id);
            $done = time();
            $wpdb->query($wpdb->prepare(
                "UPDATE ".self::table()." SET status = 'ready', payload = %s, size_bytes = %d, expires_at = %s WHERE id = %d AND status = 'pending'",
                $payload, strlen($payload), gmdate('Y-m-d H:i:s', $done + self::EXPORT_TTL), (int) $row->id
            ));
        } catch (\Throwable $e) {
            error_log('[bootspruefung-learning mobile] Datenexport fehlgeschlagen: '.get_class($e).': '.$e->getMessage());
            $wpdb->query($wpdb->prepare("UPDATE ".self::table()." SET status = 'failed' WHERE id = %d AND status = 'pending'", (int) $row->id));
        }
    }

    /** Alle registrierten WordPress-Datenexporte des Kontos als JSON. */
    private static function buildPayload(int $userId): string
    {
        $user = get_userdata($userId);
        if (! $user) {
            throw new \RuntimeException('Konto nicht gefunden.');
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

        return (string) wp_json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'site' => home_url('/'),
            'user' => ['id' => (string) $userId, 'email' => $email],
            'groups' => $groups,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $status = $expired && $row->status !== 'failed' ? 'expired' : (string) $row->status;

        return [
            'export_id' => (string) $row->public_id,
            // pending (wird erstellt) → ready (Download bereit) | failed (neu anfordern); expired nach 24 h
            'status' => $status,
            'download_url' => $status === 'ready' ? rest_url('boatingschool/v1/profile/export/'.$row->public_id.'/download') : null,
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
