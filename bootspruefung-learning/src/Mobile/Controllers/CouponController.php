<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\Idempotency;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Mobile\TokenAuth;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Code einlösen. Bei uns schaltet der Shop-Kauf den Zugang automatisch frei – es gibt keine
 * per E-Mail verschickten Zugangscodes. Echte Zugangscodes sind nur die Beitrittscodes von
 * Gruppenlizenzen (Einladungslink „…/gruppe/<code>/“); diese werden über GroupService::join
 * eingelöst wie im Web. WooCommerce-Gutscheine sind Rabatte für den Kauf und werden mit klarer
 * Meldung abgelehnt. Kursgutscheine (seit 1.12.0, über einen Gutschein-Connector) werden hier nicht eingelöst:
 * 422 COURSE_VOUCHER verweist auf POST /vouchers/redeem.
 *
 * Seit 1.4.0 zuerst Idempotenz, dann Rate-Limit: Eine Netzwiederholung mit demselben
 * Idempotency-Key liefert die gespeicherte Antwort, ohne einen Versuch zu verbrauchen.
 */
final class CouponController extends BaseController
{
    public function redeem(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->user();
        $raw = trim((string) $request['code']);
        if ($raw === '') {
            throw ApiException::validation(['code' => [__('Bitte gib einen Code ein.', 'bootspruefung-learning')]]);
        }
        $limit = static fn () => RateLimiter::hit('redeem|'.$user, 10, HOUR_IN_SECONDS, __('Zu viele Versuche. Bitte warte eine Stunde und versuche es dann erneut.', 'bootspruefung-learning'));
        // Ganzer Einladungslink oder nur der Code.
        $code = preg_match('#/gruppe/([gi][0-9a-f]{32})/?#i', $raw, $m) === 1 ? strtolower($m[1]) : strtolower($raw);

        if (preg_match('/^[gi][0-9a-f]{32}$/', $code) === 1) {
            [$status, $body] = Idempotency::run($user, 'redeem', $this->idempotencyKey($request, false), ['code' => hash('sha256', $code)], function () use ($user, $code, $limit): array {
                // Erst hier zählen: Wiederholungen mit gleichem Key kommen gar nicht bis hierher.
                $limit();
                global $wpdb;
                $groups = new GroupService($wpdb);
                try {
                    $member = $groups->join($code, $user);
                } catch (\InvalidArgumentException $e) {
                    throw new ApiException('COUPON_INVALID', $e->getMessage(), 422);
                }
                $group = $groups->find((int) $member->group_id);

                return [200, ['data' => $this->payload($group)], 'group:'.(int) $member->group_id];
            });

            return new WP_REST_Response($body, $status);
        }
        $limit();
        if (VoucherController::isCourseVoucher($raw)) {
            // Kursgutscheine (Voucher Captain) schalten einen Kurs frei – über die eigene Route, nicht als Gruppencode.
            throw new ApiException('COURSE_VOUCHER', __('Das ist ein Kursgutschein. Löse ihn unter „Mein Lernen“ › „Kursgutschein einlösen“ ein.', 'bootspruefung-learning'), 422, ['details' => ['redeem_path' => '/vouchers/redeem', 'check_path' => '/vouchers/check']]);
        }
        if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($raw) > 0) {
            throw new ApiException('COUPON_NOT_SUPPORTED', __('Das ist ein Gutscheincode für den Shop. Löse ihn beim Kauf im Shop ein – dein Zugang wird nach dem Kauf automatisch freigeschaltet.', 'bootspruefung-learning'), 422);
        }
        throw new ApiException('COUPON_INVALID', __('Dieser Code ist ungültig. Hinweis: Kurse aus dem Shop brauchen keinen Code – dein Zugang wird nach dem Kauf automatisch freigeschaltet, sobald du dich mit demselben Konto anmeldest.', 'bootspruefung-learning'), 422);
    }

    /** @return array<string,mixed> */
    private function payload(?object $group): array
    {
        $courses = [];
        foreach ($group ? GroupService::scopes($group) : [] as $scope) {
            foreach ($this->publishedCourses() as $course) {
                if ($course->code === ($scope['course'] ?? null) && ! isset($courses[(int) $course->id])) {
                    $courses[(int) $course->id] = ['course_id' => (string) $course->id, 'code' => (string) $course->code, 'name' => (string) $course->name];
                }
            }
        }
        $first = reset($courses) ?: null;

        return [
            'redeemed' => true,
            'kind' => 'group_license',
            'group_name' => $group ? (string) $group->name : null,
            'course_id' => $first['course_id'] ?? null,
            'course_name' => $first['name'] ?? null,
            'courses' => array_values($courses),
            'entitlement' => ['status' => 'active', 'valid_from' => null, 'valid_until' => $group ? TokenAuth::iso($group->valid_until) : null],
        ];
    }
}
