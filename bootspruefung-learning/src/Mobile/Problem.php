<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile;

use WP_Error;
use WP_REST_Response;

/**
 * Einheitliches Fehlerobjekt der App-API (RFC-9457-Stil wie im Konzept-Vertrag):
 * type, title, status, detail, code, request_id – zusätzlich message (= detail) und
 * je nach Fall errors (Feldfehler), details (Zusatzinfos) und retry_after (Sekunden).
 * Content-Type application/problem+json.
 */
final class Problem
{
    private static ?string $requestId = null;

    /** Kennung der laufenden Anfrage (Support/Diagnose), auch als Header X-Request-Id. */
    public static function requestId(): string
    {
        return self::$requestId ??= 'req_'.str_replace('-', '', wp_generate_uuid4());
    }

    /** Neue Anfrage (Tests rufen mehrere Anfragen in einem Prozess auf). */
    public static function reset(): void
    {
        self::$requestId = null;
    }

    private const TITLES = [
        'UNAUTHENTICATED' => 'Anmeldung erforderlich',
        'REFRESH_TOKEN_REUSED' => 'Sitzung beendet',
        'REFRESH_TOKEN_ROTATED' => 'Sitzung wurde gerade erneuert',
        'PASSWORD_RESET_INVALID_OR_EXPIRED' => 'Link ungültig oder abgelaufen',
        'TENANT_MEMBERSHIP_REQUIRED' => 'Bootsschule nicht verfügbar',
        'ENTITLEMENT_REQUIRED' => 'Kein Zugang',
        'ENTITLEMENT_EXPIRED' => 'Kurszugang abgelaufen',
        'ENTITLEMENT_SUSPENDED' => 'Kurszugang ausgesetzt',
        'CONTENT_LOCKED' => 'Noch gesperrt',
        'RESOURCE_NOT_FOUND' => 'Nicht gefunden',
        'VALIDATION_FAILED' => 'Eingabe prüfen',
        'IDEMPOTENCY_KEY_REUSED' => 'Idempotency-Key bereits verwendet',
        'REQUEST_IN_PROGRESS' => 'Anfrage läuft noch',
        'QUESTION_TOKEN_EXPIRED' => 'Frage abgelaufen',
        'EXAM_NOT_STARTABLE' => 'Prüfung nicht startbar',
        'EXAM_ALREADY_SUBMITTED' => 'Prüfung bereits abgegeben',
        'EXAM_NOT_SUBMITTED' => 'Prüfung läuft noch',
        'EXAM_EXPIRED' => 'Prüfungszeit abgelaufen',
        'EXAM_ANSWER_LOCKED' => 'Antwort gesperrt',
        'COUPON_INVALID' => 'Code ungültig',
        'COUPON_NOT_SUPPORTED' => 'Kein Zugangscode',
        'GROUP_NOT_ACTIVE' => 'Gruppe nicht aktiv',
        'RATE_LIMITED' => 'Zu viele Anfragen',
        'EXPORT_EXPIRED' => 'Export abgelaufen',
        'EXPORT_NOT_READY' => 'Export wird erstellt',
        'EXPORT_FAILED' => 'Export fehlgeschlagen',
        'RANGE_NOT_SATISFIABLE' => 'Bereich ungültig',
        'DELETION_CONFIRMATION_INVALID' => 'Bestätigungslink ungültig oder abgelaufen',
        'VERSION_CONFLICT' => 'Inzwischen geändert',
        'WEB_SESSION_DISABLED' => 'Webansicht-Anmeldung abgeschaltet',
        'WEB_SESSION_NOT_ALLOWED' => 'Webansicht-Anmeldung nicht erlaubt',
        'MOBILE_API_DISABLED' => 'App vorübergehend nicht verfügbar',
        'HTTPS_REQUIRED' => 'HTTPS erforderlich',
        'METHOD_NOT_ALLOWED' => 'Methode nicht erlaubt',
        'INTERNAL_ERROR' => 'Serverfehler',
    ];

    /** @param array<string,mixed> $extra */
    public static function response(string $code, string $message, int $status, array $extra = []): WP_REST_Response
    {
        $body = [
            'type' => 'urn:bootspruefung:problem:'.strtolower(str_replace('_', '-', $code)),
            'title' => self::TITLES[$code] ?? $message,
            'status' => $status,
            'detail' => $message,
            'code' => $code,
            'message' => $message,
            'request_id' => self::requestId(),
        ];
        foreach (['errors', 'details', 'retry_after'] as $key) {
            if (array_key_exists($key, $extra) && $extra[$key] !== null && $extra[$key] !== []) {
                $body[$key] = $extra[$key];
            }
        }
        $response = new WP_REST_Response($body, $status);
        $response->header('Content-Type', 'application/problem+json; charset='.get_option('blog_charset', 'UTF-8'));
        if (isset($body['retry_after'])) {
            $response->header('Retry-After', (string) (int) $body['retry_after']);
        }
        // Zusätzliche Antwort-Header (z. B. Content-Range bei 416), nicht im Fehlerobjekt.
        foreach ((array) ($extra['headers'] ?? []) as $name => $value) {
            $response->header((string) $name, (string) $value);
        }

        return $response;
    }

    public static function fromException(ApiException $e): WP_REST_Response
    {
        return self::response($e->errorCode, $e->getMessage(), $e->status, $e->extra);
    }

    /**
     * WordPress-Fehler (Parameterprüfung, unbekannte Route, Rechteprüfung) ins Fehlerobjekt übersetzen.
     */
    public static function fromWpError(WP_Error $error): WP_REST_Response
    {
        $code = (string) $error->get_error_code();
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        $message = (string) $error->get_error_message();
        $extra = is_array($data) ? array_intersect_key($data, array_flip(['errors', 'details', 'retry_after'])) : [];

        if ($code === 'rest_invalid_param' || $code === 'rest_missing_callback_param' || $code === 'rest_invalid_json') {
            $errors = [];
            foreach ((array) ($data['params'] ?? []) as $field => $text) {
                // rest_missing_callback_param: Liste der fehlenden Namen; rest_invalid_param: Name => Meldung.
                if (is_int($field)) {
                    $errors[(string) $text][] = __('Pflichtfeld fehlt.', 'bootspruefung-learning');
                } else {
                    $errors[(string) $field][] = wp_strip_all_tags((string) $text);
                }
            }
            if ($code === 'rest_invalid_json') {
                $errors['body'][] = __('Der Inhalt ist kein gültiges JSON.', 'bootspruefung-learning');
            }

            return self::response('VALIDATION_FAILED', __('Bitte prüfe deine Eingaben.', 'bootspruefung-learning'), 422, ['errors' => $errors]);
        }
        if ($code === 'rest_no_route') {
            return self::response('RESOURCE_NOT_FOUND', __('Diese Adresse gibt es in der App-Schnittstelle nicht.', 'bootspruefung-learning'), 404);
        }
        if ($code === 'rest_invalid_method') {
            return self::response('METHOD_NOT_ALLOWED', __('Diese Methode ist hier nicht erlaubt.', 'bootspruefung-learning'), 405);
        }
        if (preg_match('/^[A-Z][A-Z0-9_]+$/', $code) === 1) {
            return self::response($code, $message, $status, $extra);
        }
        if ($status === 401 || $code === 'rest_not_logged_in') {
            return self::response('UNAUTHENTICATED', __('Bitte melde dich an.', 'bootspruefung-learning'), 401);
        }
        if ($status === 403) {
            return self::response('ENTITLEMENT_REQUIRED', $message !== '' ? $message : __('Keine Berechtigung.', 'bootspruefung-learning'), 403);
        }
        if ($status === 404) {
            return self::response('RESOURCE_NOT_FOUND', $message !== '' ? $message : __('Nicht gefunden.', 'bootspruefung-learning'), 404);
        }
        if ($status >= 400 && $status < 500) {
            return self::response('VALIDATION_FAILED', $message !== '' ? $message : __('Bitte prüfe deine Eingaben.', 'bootspruefung-learning'), $status === 400 ? 422 : $status);
        }

        return self::response('INTERNAL_ERROR', __('Es ist ein Fehler aufgetreten. Bitte versuche es später erneut.', 'bootspruefung-learning'), 500);
    }
}
