<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Mobile\Controllers;

use Bootspruefung\Learning\Commerce\GroupService;
use Bootspruefung\Learning\Extensions\Vouchers;
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
 * Meldung abgelehnt.
 *
 * Seit 1.11.0 Kursgutscheine eines Gutschein-Plugins: Ist der Code kein Beitrittscode, fragt der Controller den
 * Filter bootspruefung_voucher_redeem (Extensions\Vouchers). Liefert das Plugin ein Produkt, legt das Lern-Plugin
 * eine interne 0-€-Bestellung an (Zugang ab jetzt) und antwortet mit kind „course_voucher“; ein WP_Error des
 * Plugins wird als 422 COUPON_INVALID mit dessen Meldung zurückgegeben; null = bisheriges Verhalten.
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
        if (Vouchers::available()) {
            [$status, $body] = Idempotency::run($user, 'redeem', $this->idempotencyKey($request, false), ['code' => hash('sha256', $raw)], function () use ($user, $raw, $limit): array {
                $limit();
                $result = Vouchers::redeemCode($raw, $user);
                if ($result === null) {
                    $this->rejectUnknown($raw);
                }
                if ($result instanceof \WP_Error) {
                    throw new ApiException('COUPON_INVALID', $result->get_error_message(), 422);
                }

                return [200, ['data' => $this->voucherPayload($result['order_id'])], 'voucher_order:'.$result['order_id']];
            });

            return new WP_REST_Response($body, $status);
        }
        $limit();
        $this->rejectUnknown($raw);
    }

    /** Kein Zugangscode: Shop-Gutschein (Rabatt beim Kauf) oder unbekannt – immer 422. */
    private function rejectUnknown(string $raw): never
    {
        if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($raw) > 0) {
            throw new ApiException('COUPON_NOT_SUPPORTED', __('Das ist ein Gutscheincode für den Shop. Löse ihn beim Kauf im Shop ein – dein Zugang wird nach dem Kauf automatisch freigeschaltet.', 'bootspruefung-learning'), 422);
        }
        throw new ApiException('COUPON_INVALID', __('Dieser Code ist ungültig. Hinweis: Kurse aus dem Shop brauchen keinen Code – dein Zugang wird nach dem Kauf automatisch freigeschaltet, sobald du dich mit demselben Konto anmeldest.', 'bootspruefung-learning'), 422);
    }

    /** Antwort wie beim Gruppencode, kind „course_voucher“. @return array<string,mixed> */
    private function voucherPayload(int $orderId): array
    {
        $summary = Vouchers::summary($orderId);
        $courses = [];
        foreach ($this->publishedCourses() as $course) {
            if (in_array((string) $course->code, $summary['courses'], true)) {
                $courses[] = ['course_id' => (string) $course->id, 'code' => (string) $course->code, 'name' => (string) $course->name];
            }
        }

        return [
            'redeemed' => true,
            'kind' => 'course_voucher',
            'group_name' => null,
            'course_id' => $courses[0]['course_id'] ?? null,
            'course_name' => $courses[0]['name'] ?? null,
            'courses' => $courses,
            'entitlement' => ['status' => $summary['status'], 'valid_from' => TokenAuth::iso($summary['valid_from']), 'valid_until' => TokenAuth::iso($summary['valid_until'])],
        ];
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
