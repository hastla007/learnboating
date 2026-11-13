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
 * Kursgutscheine in „Mein Lernen“ (seit 1.13.0): POST /vouchers/check (Vorschau) und POST /vouchers/redeem.
 * Der Nutzer ist immer der angemeldete Nutzer des App-Tokens – nie ein Feld im Request.
 *
 * Die Lernplattform kennt keine Gutscheine selbst: ein Gutschein-Connector (z. B. learnboating-voucher-connector
 * für Voucher Captain) stellt über den Filter bootspruefung_course_voucher_redeemer_v1 ein Objekt mit
 * check(code, user_id) und redeem(code, user_id) bereit. Es reserviert den Gutschein, ruft den Grant-Service
 * (VoucherGrants) und verbucht erst nach bestätigter Freischaltung. Ohne Connector antwortet die Route mit
 * COURSE_VOUCHERS_UNAVAILABLE. Gruppen-Beitrittscodes bleiben bei POST /coupons/redeem.
 *
 * Fehlercodes (docs/kursgutscheine.md): VOUCHER_INVALID, VOUCHER_RESERVED, VOUCHER_REDEEMED, VOUCHER_EXPIRED,
 * VOUCHER_NOT_YET_VALID, VOUCHER_WRONG_COURSE, VOUCHER_NOT_FOR_COURSES, VOUCHER_NOT_ALLOWED, ACCESS_NOT_POSSIBLE,
 * TEMPORARY_ERROR (mit retry_after; derselbe Code kann gefahrlos erneut gesendet werden), RATE_LIMITED.
 */
final class VoucherController extends BaseController
{
    public const REDEEMER_FILTER = 'bootspruefung_course_voucher_redeemer_v1';

    /** Fehlercode des Connectors/Grant-Service → [App-Code, HTTP-Status] */
    private const CODES = [
        'vcap_not_found' => ['VOUCHER_INVALID', 422],
        'vcap_invalid_request' => ['VOUCHER_INVALID', 422],
        'vcap_cancelled' => ['VOUCHER_INVALID', 410],
        'vcap_reserved' => ['VOUCHER_RESERVED', 409],
        'vcap_already_redeemed' => ['VOUCHER_REDEEMED', 409],
        'lb_voucher_redeemed' => ['VOUCHER_REDEEMED', 409],
        'vcap_expired' => ['VOUCHER_EXPIRED', 410],
        'vcap_not_active' => ['VOUCHER_NOT_YET_VALID', 409],
        'lb_wrong_course' => ['VOUCHER_WRONG_COURSE', 422],
        'vcap_not_redeemable' => ['VOUCHER_NOT_FOR_COURSES', 422],
        'lb_not_course_voucher' => ['VOUCHER_NOT_FOR_COURSES', 422],
        'vcap_not_allowed' => ['VOUCHER_NOT_ALLOWED', 403],
        'lb_target_unavailable' => ['ACCESS_NOT_POSSIBLE', 409],
        'lb_target_unmapped' => ['ACCESS_NOT_POSSIBLE', 409],
        'lb_target_group' => ['ACCESS_NOT_POSSIBLE', 409],
        'lb_target_upgrade' => ['ACCESS_NOT_POSSIBLE', 409],
        'lb_course_unavailable' => ['ACCESS_NOT_POSSIBLE', 409],
        'lb_access_exists' => ['ACCESS_NOT_POSSIBLE', 409],
        'vcap_target_unavailable' => ['ACCESS_NOT_POSSIBLE', 409],
        'vcap_rate_limited' => ['RATE_LIMITED', 429],
        'lb_temporary_failure' => ['TEMPORARY_ERROR', 503],
        'lb_shop_unavailable' => ['TEMPORARY_ERROR', 503],
        'vcap_temporary_failure' => ['TEMPORARY_ERROR', 503],
    ];

    public static function redeemer(): ?object
    {
        $redeemer = apply_filters(self::REDEEMER_FILTER, null);

        return is_object($redeemer) && method_exists($redeemer, 'check') && method_exists($redeemer, 'redeem') ? $redeemer : null;
    }

    /** Ist der Code ein Kursgutschein? (für den Hinweis aus /coupons/redeem) */
    public static function isCourseVoucher(string $code): bool
    {
        $redeemer = self::redeemer();

        return $redeemer !== null && method_exists($redeemer, 'is_course_voucher') && (bool) $redeemer->is_course_voucher($code);
    }

    public function check(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $code = $this->code($request);
        RateLimiter::hit('voucher|'.$user, 30, HOUR_IN_SECONDS, __('Zu viele Versuche. Bitte warte eine Stunde und versuche es dann erneut.', 'bootspruefung-learning'));
        $result = $this->service()->check($code, $user);
        if ($result instanceof \WP_Error) {
            throw self::problem($result);
        }
        $this->assertCourse($request, $result);

        return $this->ok(self::payload($result, false));
    }

    public function redeem(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $code = $this->code($request);
        [$status, $body] = Idempotency::run($user, 'voucher_redeem', $this->idempotencyKey($request, false), ['code' => hash('sha256', strtoupper($code)), 'course_id' => (string) ($request['course_id'] ?? '')], function () use ($user, $code, $request): array {
            RateLimiter::hit('voucher|'.$user, 30, HOUR_IN_SECONDS, __('Zu viele Versuche. Bitte warte eine Stunde und versuche es dann erneut.', 'bootspruefung-learning'));
            $service = $this->service();
            if (($request['course_id'] ?? null) !== null && (string) $request['course_id'] !== '') {
                // Aus einem Kurs heraus eingelöst: erst prüfen, ob der Gutschein zu diesem Kurs gehört.
                $preview = $service->check($code, $user);
                if ($preview instanceof \WP_Error) {
                    throw self::problem($preview);
                }
                $this->assertCourse($request, $preview);
            }
            $result = $service->redeem($code, $user);
            if ($result instanceof \WP_Error) {
                throw self::problem($result);
            }

            return [200, ['data' => self::payload($result, true)], 'voucher_grant:'.(string) ($result['grant']['grant_id'] ?? '')];
        });

        return new WP_REST_Response($body, $status);
    }

    private function service(): object
    {
        $redeemer = self::redeemer();
        if ($redeemer === null) {
            throw new ApiException('COURSE_VOUCHERS_UNAVAILABLE', __('Kursgutscheine können hier derzeit nicht eingelöst werden.', 'bootspruefung-learning'), 503, ['retry_after' => 3600]);
        }

        return $redeemer;
    }

    private function code(WP_REST_Request $request): string
    {
        $code = trim((string) $request['code']);
        if ($code === '') {
            throw ApiException::validation(['code' => [__('Bitte gib einen Gutscheincode ein.', 'bootspruefung-learning')]]);
        }

        return $code;
    }

    /** @param array<string,mixed> $result */
    private function assertCourse(WP_REST_Request $request, array $result): void
    {
        $wanted = (string) ($request['course_id'] ?? '');
        if ($wanted === '') {
            return;
        }
        $ids = array_map(static fn ($s) => (string) ($s['course_id'] ?? ''), (array) ($result['target']['scopes'] ?? []));
        if (! in_array($wanted, $ids, true)) {
            throw self::problem(new \WP_Error('lb_wrong_course', ''));
        }
    }

    /** Fachlicher Fehler → App-Fehlerobjekt mit eigener, verständlicher Meldung (Du-Form wie die App). */
    public static function problem(\WP_Error $error): ApiException
    {
        $source = (string) $error->get_error_code();
        [$code, $status] = self::CODES[$source] ?? ['TEMPORARY_ERROR', 503];
        $data = (array) $error->get_error_data();
        if (! isset(self::CODES[$source]) && (int) ($data['status'] ?? 500) < 500) {
            [$code, $status] = ['VOUCHER_INVALID', (int) $data['status']];
        }
        $messages = [
            'VOUCHER_INVALID' => $status === 410 ? __('Dieser Gutschein ist nicht mehr gültig.', 'bootspruefung-learning') : __('Dieser Gutscheincode ist ungültig. Bitte prüfe die Eingabe.', 'bootspruefung-learning'),
            'VOUCHER_RESERVED' => __('Dieser Gutschein wird gerade an anderer Stelle eingelöst, zum Beispiel in einer offenen Bestellung. Bitte versuche es später erneut.', 'bootspruefung-learning'),
            'VOUCHER_REDEEMED' => __('Dieser Gutschein wurde bereits eingelöst.', 'bootspruefung-learning'),
            'VOUCHER_EXPIRED' => __('Dieser Gutschein ist abgelaufen.', 'bootspruefung-learning'),
            'VOUCHER_NOT_YET_VALID' => __('Dieser Gutschein ist noch nicht gültig.', 'bootspruefung-learning'),
            'VOUCHER_WRONG_COURSE' => __('Dieser Gutschein gilt für einen anderen Kurs.', 'bootspruefung-learning'),
            'VOUCHER_NOT_FOR_COURSES' => __('Das ist kein Kursgutschein. Shop-Gutscheine löst du beim Kauf im Shop ein.', 'bootspruefung-learning'),
            'VOUCHER_NOT_ALLOWED' => __('Dieser Gutschein ist für ein anderes Konto ausgestellt. Melde dich mit der E-Mail-Adresse an, für die er bestimmt ist.', 'bootspruefung-learning'),
            // Fachliche Gründe des Grant-Service sind bereits verständliche Meldungen (z. B. „Kurs wird nicht angeboten“).
            'ACCESS_NOT_POSSIBLE' => $error->get_error_message() !== '' && str_starts_with($source, 'lb_') ? $error->get_error_message() : __('Mit diesem Gutschein kann gerade kein Zugang freigeschaltet werden.', 'bootspruefung-learning'),
            'RATE_LIMITED' => __('Zu viele Versuche. Bitte warte kurz und versuche es dann erneut.', 'bootspruefung-learning'),
            'TEMPORARY_ERROR' => __('Gerade ist ein vorübergehender Fehler aufgetreten. Dein Gutschein ist nicht verloren – bitte versuche es in ein paar Minuten noch einmal.', 'bootspruefung-learning'),
        ];
        $extra = ['details' => ['reason' => $source]];
        if ($code === 'TEMPORARY_ERROR') {
            $extra['retry_after'] = 60;
        } elseif ($code === 'RATE_LIMITED') {
            $extra['retry_after'] = 900;
        }

        return new ApiException($code, $messages[$code], $status, $extra);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function payload(array $result, bool $redeemed): array
    {
        $target = (array) ($result['target'] ?? []);
        $courses = [];
        foreach ((array) ($target['scopes'] ?? []) as $scope) {
            $courses[(string) $scope['course']] = ['course_id' => (string) $scope['course_id'], 'code' => (string) $scope['course'], 'name' => (string) $scope['course_name']];
        }
        $out = [
            'kind' => 'course_voucher',
            'redeemed' => $redeemed,
            'voucher' => ['code_hint' => (string) ($result['voucher']['code_hint'] ?? ''), 'expires_at' => TokenAuth::iso($result['voucher']['expires_at'] ?? null)],
            'product' => ['id' => (int) ($target['product_id'] ?? 0), 'variation_id' => (int) ($target['variation_id'] ?? 0), 'name' => (string) ($target['name'] ?? '')],
            'courses' => array_values($courses),
        ];
        if ($redeemed) {
            $out['reused'] = empty($result['grant']['created']);
            $out['entitlements'] = array_map(static fn ($e) => [
                'course' => $e['course'], 'feature' => $e['feature'], 'status' => $e['status'],
                'valid_from' => TokenAuth::iso($e['valid_from']), 'valid_until' => TokenAuth::iso($e['valid_until']),
            ], (array) ($result['grant']['entitlements'] ?? []));
        } else {
            $out['preview'] = array_map(static fn ($s) => [
                'course' => $s['course'], 'feature' => $s['feature'], 'existing' => $s['existing'] ?? 'none',
                'starts_at' => TokenAuth::iso($s['starts_at'] ?? null), 'ends_at' => TokenAuth::iso($s['ends_at'] ?? null),
            ], (array) ($target['scopes'] ?? []));
        }

        return $out;
    }
}
