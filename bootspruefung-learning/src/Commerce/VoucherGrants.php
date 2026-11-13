<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Infrastructure\Lock;

/**
 * Freischaltung eines Kurszugangs aus einer externen Gutscheineinlösung (öffentlicher PHP-Vertrag v1,
 * docs/kursgutscheine.md). Die Lernplattform entscheidet allein über Kurs, Bereiche, Laufzeit und
 * Zugangskonflikte – anhand der Produktzuordnung „Lernzugang“ des gewählten WooCommerce-Kursprodukts.
 * Ein Gutschein-Plugin legt keine eigene Kurs- oder Laufzeitkonfiguration an; es nennt nur das Kursprodukt.
 *
 * Zugriff: apply_filters('bootspruefung_voucher_grants_v1', null) liefert diesen Dienst.
 *
 * - target():  Ist das Kursprodukt als Gutscheinziel geeignet? (vorhanden, veröffentlicht, Zuordnung aktiv,
 *              keine Gruppenlizenz/kein Upgrade, Kurse veröffentlicht)
 * - check():   Vorschau für einen Nutzer (geplante Laufzeit je Bereich, vorhandener Zugang)
 * - grant():   idempotent über den Einlöse-Schlüssel des Gutschein-Plugins: derselbe Schlüssel liefert denselben
 *              Grant, parallele Aufrufe legen keinen zweiten Zugang an (eindeutige Referenz je Kurs/Bereich)
 * - get():     Grant zu einem Einlöse-Schlüssel (Support, Wiederholung)
 * - revoke():  Widerruf (Gutschein ungültig gemacht, Kauf erstattet) nach der Erstattungsregel der Zuordnung
 *
 * Fehler sind WP_Error mit stabilen Codes (lb_*) und data['status'] (HTTP-Stil); data['retryable'] = true heißt:
 * vorübergehend, mit demselben Schlüssel wiederholen.
 */
final class VoucherGrants
{
    public const CONTRACT_VERSION = '1.0.0';
    public const FILTER = 'bootspruefung_voucher_grants_v1';
    /** Wie der Einlöse-Schlüssel von Voucher Captain: 8–64 Zeichen. */
    public const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{7,63}$/';
    public const SOURCE = EntitlementService::SOURCE_VOUCHER;

    private ProductAccessRepository $products;
    private EntitlementService $entitlements;
    private ContentRepository $content;

    public function __construct(private readonly \wpdb $db, ?ProductAccessRepository $products = null, ?EntitlementService $entitlements = null, ?ContentRepository $content = null)
    {
        $this->products = $products ?? new ProductAccessRepository($db);
        $this->entitlements = $entitlements ?? new EntitlementService($db);
        $this->content = $content ?? new ContentRepository();
    }

    public static function register(): void
    {
        add_filter(self::FILTER, static function ($service) {
            global $wpdb;

            return $service ?? new self($wpdb);
        });
    }

    /** Referenz der Zugänge einer Einlösung (Spalte reference_key, max. 100 Zeichen). */
    public static function reference(string $redemptionKey): string
    {
        return 'voucher:'.$redemptionKey;
    }

    /**
     * Ist das Kursprodukt (bzw. die Variation) ein gültiges Gutscheinziel?
     *
     * @return array{product_id:int,variation_id:int,name:string,sku:string,scopes:list<array<string,mixed>>,rows:list<object>}|\WP_Error
     */
    public function target(int $productId, int $variationId = 0): array|\WP_Error
    {
        if (! function_exists('wc_get_product')) {
            return self::error('lb_shop_unavailable', __('Der Shop ist gerade nicht erreichbar. Bitte versuche es später erneut.', 'bootspruefung-learning'), 503, true);
        }
        $product = $productId > 0 ? wc_get_product($variationId > 0 ? $variationId : $productId) : null;
        if (! $product instanceof \WC_Product) {
            return self::error('lb_target_unavailable', __('Das Kursprodukt dieses Gutscheins gibt es nicht mehr.', 'bootspruefung-learning'), 409);
        }
        if ($variationId > 0 && (int) $product->get_parent_id() !== $productId) {
            return self::error('lb_invalid_request', __('Die Variation gehört nicht zu diesem Kursprodukt.', 'bootspruefung-learning'), 400);
        }
        $parent = $variationId > 0 ? wc_get_product($productId) : $product;
        foreach (array_filter([$product, $parent]) as $p) {
            if (! in_array($p->get_status(), ['publish', 'private'], true)) {
                return self::error('lb_target_unavailable', __('Das Kursprodukt dieses Gutscheins ist nicht mehr verfügbar.', 'bootspruefung-learning'), 409);
            }
        }
        $rows = $this->products->forItem($productId, $variationId);
        if ($rows === []) {
            return self::error('lb_target_unmapped', __('Für das Kursprodukt dieses Gutscheins ist kein Lernzugang hinterlegt.', 'bootspruefung-learning'), 409);
        }
        if (ProductAccessRepository::seats($rows) !== null) {
            return self::error('lb_target_group', __('Gruppenlizenzen können nicht mit einem Gutschein freigeschaltet werden.', 'bootspruefung-learning'), 409);
        }
        $sku = (string) $product->get_sku();
        if (str_ends_with($sku, ShopCatalog::VIDEO_SUFFIX) || $sku === ShopCatalog::SIM_UPGRADE) {
            return self::error('lb_target_upgrade', __('Upgrades setzen einen gekauften Kurs voraus und können nicht mit einem Gutschein freigeschaltet werden.', 'bootspruefung-learning'), 409);
        }
        $scopes = [];
        foreach ($rows as $row) {
            $course = $this->content->courseByCode((string) $row->course_code);
            if ($course === null) {
                return self::error('lb_course_unavailable', __('Der Kurs dieses Gutscheins wird derzeit nicht angeboten.', 'bootspruefung-learning'), 409);
            }
            $scopes[] = [
                'course' => (string) $row->course_code, 'course_id' => (int) $course->id, 'course_name' => (string) $course->name,
                'feature' => (string) $row->feature_code,
                'duration_days' => $row->duration_days !== null ? (int) $row->duration_days : null,
                'renewal_mode' => (string) $row->renewal_mode, 'refund_rule' => (string) $row->refund_rule,
            ];
        }

        return ['product_id' => $productId, 'variation_id' => $variationId, 'name' => (string) $product->get_name(), 'sku' => $sku, 'scopes' => $scopes, 'rows' => $rows];
    }

    /**
     * Vorschau für einen Nutzer: geplante Laufzeit je Bereich ab jetzt, vorhandener Zugang. Kein Zugang möglich,
     * wenn der Nutzer jeden Bereich bereits dauerhaft hat (der Gutschein brächte nichts).
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function check(int $userId, int $productId, int $variationId = 0): array|\WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return self::error('lb_user_required', __('Bitte melde dich an, um den Gutschein einzulösen.', 'bootspruefung-learning'), 401);
        }
        $target = $this->target($productId, $variationId);
        if ($target instanceof \WP_Error) {
            return $target;
        }
        $now = EntitlementService::now();
        $permanent = 0;
        foreach ($target['scopes'] as $i => $scope) {
            [$hasPermanent, $latest] = $this->existing($userId, $scope['course'], $scope['feature']);
            $permanent += $hasPermanent ? 1 : 0;
            $start = $now;
            if ($scope['duration_days'] !== null && $scope['renewal_mode'] === 'extend' && $latest !== null && $latest > $now) {
                $start = $latest; // Verlängerung: beginnt am Ende des vorhandenen befristeten Zugangs.
            }
            $target['scopes'][$i] += [
                'existing' => $hasPermanent ? 'permanent' : ($latest !== null && $latest > $now ? 'until' : 'none'),
                'existing_until' => $hasPermanent ? null : ($latest !== null && $latest > $now ? $latest : null),
                'starts_at' => $start,
                'ends_at' => $scope['duration_days'] !== null ? gmdate('Y-m-d H:i:s', strtotime($start.' UTC') + $scope['duration_days'] * DAY_IN_SECONDS) : null,
            ];
        }
        if ($permanent === count($target['scopes'])) {
            return self::error('lb_access_exists', __('Du hast diesen Kurs bereits dauerhaft freigeschaltet. Der Gutschein bleibt gültig – du kannst ihn verschenken.', 'bootspruefung-learning'), 409);
        }
        unset($target['rows']);

        return $target + ['user_id' => $userId];
    }

    /**
     * Schaltet frei. Pflichtangaben: redemption_key (Einlöse-Schlüssel des Gutschein-Plugins), user_id, product_id;
     * optional variation_id, voucher_id, source (z. B. "voucher-captain"), source_reference (Herkunft, z. B.
     * "vcap:voucher:123"). Die Laufzeit beginnt mit der bestätigten Einlösung (jetzt); ein Wunschdatum gibt es nicht.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|\WP_Error
     */
    public function grant(array $request): array|\WP_Error
    {
        $key = (string) ($request['redemption_key'] ?? '');
        $userId = (int) ($request['user_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $variationId = (int) ($request['variation_id'] ?? 0);
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || $productId <= 0) {
            return self::error('lb_invalid_request', __('Einlöse-Schlüssel und Kursprodukt sind erforderlich.', 'bootspruefung-learning'), 400);
        }
        $reference = self::reference($key);
        $lock = 'bp_voucher_grant_'.md5($reference);
        if (! Lock::acquire($lock, 30)) {
            return self::error('lb_temporary_failure', __('Die Freischaltung läuft bereits. Bitte versuche es gleich noch einmal.', 'bootspruefung-learning'), 503, true);
        }
        try {
            $existing = $this->entitlements->forReference($reference);
            if ($existing !== []) {
                $first = $existing[0];
                if ((int) $first->user_id !== $userId || (int) $first->product_id !== $productId || (int) ($first->variation_id ?? 0) !== $variationId) {
                    return self::error('lb_idempotency_conflict', __('Dieser Einlöse-Schlüssel wurde bereits für eine andere Freischaltung verwendet.', 'bootspruefung-learning'), 409);
                }

                return $this->result($key, $existing, false);
            }
            $check = $this->check($userId, $productId, $variationId);
            if ($check instanceof \WP_Error) {
                return $check;
            }
            $target = $this->target($productId, $variationId);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            $now = EntitlementService::now();
            $context = [
                'voucher_id' => isset($request['voucher_id']) ? (int) $request['voucher_id'] : null,
                'redemption_key' => $key,
                'grant_source' => substr(sanitize_key((string) ($request['source'] ?? 'voucher')), 0, 40),
                'source_reference' => substr(sanitize_text_field((string) ($request['source_reference'] ?? '')), 0, 190),
                'redeemed_at' => $now,
            ];
            $reason = sprintf(
                /* translators: 1: voucher id, 2: source */
                __('Kursgutschein #%1$s (%2$s)', 'bootspruefung-learning'),
                $context['voucher_id'] ?? '–',
                $context['grant_source']
            );
            $done = $this->entitlements->grantFromReference(self::SOURCE, $reference, $userId, $target['rows'], $productId, $variationId, $target['sku'], $now, $context, $reason);
            $rows = $this->entitlements->forReference($reference);
            if ($rows === []) {
                return self::error('lb_temporary_failure', __('Der Zugang konnte nicht gespeichert werden. Bitte versuche es erneut.', 'bootspruefung-learning'), 503, true);
            }
            if ($done['created'] > 0) {
                AuditLog::record('entitlement_voucher_grant', 'user', (string) $userId, $reason, [
                    'voucher_id' => $context['voucher_id'], 'redemption_key' => $key, 'source' => $context['grant_source'],
                    'source_reference' => $context['source_reference'], 'product_id' => $productId, 'variation_id' => $variationId,
                    'scopes' => array_map(static fn ($r) => $r->course_code.'/'.$r->feature_code, $rows), 'redeemed_at' => $now,
                ]);
            }
            $result = $this->result($key, $rows, $done['created'] > 0);
            do_action('bootspruefung_voucher_granted', $result);

            return $result;
        } finally {
            Lock::release($lock);
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $redemptionKey): ?array
    {
        $rows = $this->entitlements->forReference(self::reference($redemptionKey));

        return $rows === [] ? null : $this->result($redemptionKey, $rows, false);
    }

    /** @return array{revoked:int,kept:int}|\WP_Error */
    public function revoke(string $redemptionKey, string $reason): array|\WP_Error
    {
        $reference = self::reference($redemptionKey);
        if ($this->entitlements->forReference($reference) === []) {
            return self::error('lb_grant_not_found', __('Zu diesem Einlöse-Schlüssel gibt es keine Freischaltung.', 'bootspruefung-learning'), 404);
        }
        $reason = trim($reason) !== '' ? trim($reason) : __('Kursgutschein widerrufen', 'bootspruefung-learning');
        $done = $this->entitlements->revokeReference($reference, $reason);
        AuditLog::record('entitlement_voucher_revoke', 'grant', $reference, $reason, $done + ['redemption_key' => $redemptionKey]);
        do_action('bootspruefung_voucher_revoked', $redemptionKey, $done);

        return $done;
    }

    /** @param list<object> $rows @return array<string,mixed> */
    private function result(string $key, array $rows, bool $created): array
    {
        $first = $rows[0];
        $meta = EntitlementPlanner::meta($first);

        return [
            'grant_id' => self::reference($key),
            'redemption_key' => $key,
            'created' => $created,
            'user_id' => (int) $first->user_id,
            'product_id' => (int) $first->product_id,
            'variation_id' => (int) ($first->variation_id ?? 0),
            'voucher_id' => isset($meta['voucher_id']) ? (int) $meta['voucher_id'] : null,
            'source_reference' => (string) ($meta['source_reference'] ?? ''),
            'granted_at' => (string) ($meta['redeemed_at'] ?? $first->created_at),
            'entitlements' => array_map(static fn ($r) => [
                'id' => (int) $r->id, 'course' => (string) $r->course_code, 'feature' => (string) $r->feature_code, 'status' => (string) $r->status,
                'valid_from' => $r->valid_from, 'valid_until' => $r->valid_until,
            ], $rows),
        ];
    }

    /** @return array{0:bool,1:?string} [dauerhafter aktiver Zugang?, spätestes Ende befristeter aktiver Zugänge] */
    private function existing(int $userId, string $course, string $feature): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT valid_until FROM {$this->db->prefix}bp_entitlement WHERE user_id = %d AND course_code = %s AND feature_code IN (%s, %s) AND status = 'active'",
            $userId, $course, $feature, ProductAccessRepository::FEATURE_ALL
        ));
        $permanent = false;
        $latest = null;
        foreach ($rows as $row) {
            if ($row->valid_until === null) {
                $permanent = true;
            } elseif ($latest === null || $row->valid_until > $latest) {
                $latest = (string) $row->valid_until;
            }
        }

        return [$permanent, $latest];
    }

    private static function error(string $code, string $message, int $status, bool $retryable = false): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status, 'retryable' => $retryable]);
    }
}
