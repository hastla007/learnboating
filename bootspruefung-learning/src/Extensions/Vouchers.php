<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Extensions;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\VoucherGrants;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Commerce\WooCommerce\MemberDiscounts;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\RateLimiter;
use WP_Error;

/**
 * Gegenstelle für ein Gutschein-Plugin (docs/schnittstellen-erweiterungen.md).
 *
 * Ein Kursgutschein schaltet den Zugang frei, die Laufzeit beginnt mit der Einlösung. Freigeschaltet wird
 * ausschließlich über den Grant-Dienst der Kursgutscheine (Commerce\VoucherGrants, Vertrag
 * bootspruefung_voucher_grants_v1, docs/kursgutscheine.md): Kurs, Bereiche und Laufzeit kommen aus der
 * Produktzuordnung „Lernzugang“, der Zugang trägt die Referenz voucher:{Einlöse-Schlüssel}, ist idempotent und
 * im Audit-Log nachvollziehbar. Diese Klasse ist die einfache Gegenstelle für Gutschein-Plugins ohne eigenen
 * Reservieren/Verbuchen-Ablauf: Einlöse-Schlüssel ist der SHA-256 des Codes (Präfix „code-“), je Code also genau
 * eine Freischaltung. Voucher Captain nutzt den vollständigen Vertrag (Connector, Routen /vouchers/check|redeem).
 *
 * Bis 1.11 legte diese Gegenstelle interne 0-€-Bestellungen an; solche Bestellungen (Meta _bp_voucher_code_hash)
 * werden weiter erkannt (Garantie, Wiederholung), neue entstehen nicht mehr.
 *
 * Ohne WooCommerce wird nichts angelegt; der Vorgang landet im Audit-Log (voucher_redeem_skipped).
 */
final class Vouchers
{
    public const CODE_META = '_bp_voucher_code_hash';
    public const VOUCHER_ORDER_META = '_bp_voucher_order';
    /** Vom Gutschein-Plugin gesetzt: Teil des Bestellbetrags (brutto), der mit einem Gutschein bezahlt wurde. */
    public const PAID_META = '_bp_paid_by_voucher';
    public const CREATED_VIA = 'bootspruefung_voucher';
    /** Option „yes“: Shop-Gutscheine dürfen zusammen mit einem Mitgliederrabatt eingelöst werden. */
    public const OPTION_COMBINE = 'bp_learning_voucher_with_member_discount';
    /** Höchstzahl der Einlöseversuche je Nutzer und Stunde (App und Web zusammen, wie POST /coupons/redeem). */
    public const RATE_LIMIT = 10;

    public static function register(): void
    {
        add_action('bootspruefung_voucher_redeemed', [self::class, 'onRedeemed'], 10, 4);
        add_action('admin_post_bp_voucher_redeem', [self::class, 'handleWebForm']);
        add_filter('bootspruefung_coupon_allow_with_member_discount', [self::class, 'allowWithMemberDiscount'], 10, 3);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', trim($code));
    }

    /** Belegt ein Gutschein-Plugin den Einlöse-Filter? (Formular unter „Mein Lernen“, App-Einlösung) */
    public static function available(): bool
    {
        return has_filter('bootspruefung_voucher_redeem') !== false;
    }

    /**
     * Aktion bootspruefung_voucher_redeemed (Gutschein-Plugin → Lern-Plugin). Ergebnis und Fehler im Audit-Log.
     *
     * @param mixed $args
     */
    public static function onRedeemed($code, $userId = 0, $productId = 0, $args = []): void
    {
        $result = self::redeem((string) $code, (int) $userId, (int) $productId, is_array($args) ? $args : []);
        if ($result instanceof WP_Error) {
            AuditLog::record('voucher_redeem_failed', 'user', (string) (int) $userId, (string) $result->get_error_code(), ['product_id' => (int) $productId, 'code_hash' => substr(self::hash((string) $code), 0, 16)]);
        }
    }

    /** Einlöse-Schlüssel des Grant-Dienstes zu einem Code (Hash, der Code selbst wird nicht gespeichert). */
    public static function redemptionKey(string $code): string
    {
        return 'code-'.substr(self::hash($code), 0, 48);
    }

    /**
     * Kursgutschein einlösen: Freischaltung über den Grant-Dienst (idempotent je Code).
     *
     * @param array<string,mixed> $args voucher_order_id (Bestellung des Gutscheinkaufs), voucher_id; weitere Schlüssel werden ignoriert
     * @return array{created:bool,redemption_key:string,grant:array<string,mixed>,order_id:int}|WP_Error order_id nur bei
     *         Einlösungen aus der Zeit vor 1.12 (interne Bestellung), sonst 0
     */
    public static function redeem(string $code, int $userId, int $productId, array $args = []): array|WP_Error
    {
        $code = trim($code);
        if ($code === '' || $userId <= 0 || ! get_userdata($userId) || $productId <= 0) {
            return new WP_Error('bp_voucher_invalid', __('Dieser Gutschein kann nicht eingelöst werden.', 'bootspruefung-learning'));
        }
        $hash = self::hash($code);
        if (! Integration::active()) {
            AuditLog::record('voucher_redeem_skipped', 'user', (string) $userId, 'woocommerce_inactive', ['product_id' => $productId, 'code_hash' => substr($hash, 0, 16)]);

            return new WP_Error('bp_voucher_no_shop', __('Kursgutscheine können gerade nicht eingelöst werden. Bitte versuche es später erneut oder wende dich an uns.', 'bootspruefung-learning'));
        }
        // Einlösung aus der Zeit vor 1.12 (interne 0-€-Bestellung): gleiches Ergebnis wie damals, nichts Neues.
        $legacy = self::orderForHash($hash);
        if ($legacy !== null) {
            $order = wc_get_order($legacy);
            if ($order instanceof \WC_Order && (int) $order->get_customer_id() === $userId) {
                return ['created' => false, 'redemption_key' => '', 'grant' => self::legacyGrant($legacy), 'order_id' => $legacy];
            }

            return new WP_Error('bp_voucher_used', __('Dieser Gutschein wurde bereits eingelöst.', 'bootspruefung-learning'));
        }
        $product = wc_get_product($productId);
        if (! $product instanceof \WC_Product || $product->is_type('variable')) {
            return new WP_Error('bp_voucher_product', __('Zu diesem Gutschein gehört kein Kurs. Bitte wende dich an uns.', 'bootspruefung-learning'));
        }
        $variation = $product->is_type('variation');
        $voucherOrder = (int) ($args['voucher_order_id'] ?? 0);
        $key = self::redemptionKey($code);
        $grants = apply_filters(VoucherGrants::FILTER, null);
        if (! $grants instanceof VoucherGrants) {
            global $wpdb;
            $grants = new VoucherGrants($wpdb);
        }
        $result = $grants->grant([
            'redemption_key' => $key,
            'user_id' => $userId,
            'product_id' => $variation ? (int) $product->get_parent_id() : (int) $product->get_id(),
            'variation_id' => $variation ? (int) $product->get_id() : 0,
            'voucher_id' => isset($args['voucher_id']) ? (int) $args['voucher_id'] : null,
            'source' => 'voucher_plugin',
            'source_reference' => $voucherOrder > 0 ? 'order:'.$voucherOrder : '',
        ]);
        if ($result instanceof WP_Error) {
            return self::mapError($result);
        }
        if ($result['created']) {
            AuditLog::record('voucher_redeemed', 'user', (string) $userId, null, ['product_id' => $productId, 'redemption_key' => $key, 'voucher_order' => $voucherOrder ?: null]);
        }

        return ['created' => (bool) $result['created'], 'redemption_key' => $key, 'grant' => $result, 'order_id' => 0];
    }

    /** Fehler des Grant-Dienstes in die Fehler dieser Gegenstelle übersetzen (Meldung bleibt die des Dienstes). */
    private static function mapError(WP_Error $error): WP_Error
    {
        $code = (string) $error->get_error_code();
        $mapped = match (true) {
            $code === 'lb_idempotency_conflict' => new WP_Error('bp_voucher_used', __('Dieser Gutschein wurde bereits eingelöst.', 'bootspruefung-learning')),
            $code === 'lb_target_unmapped' => new WP_Error('bp_voucher_product', __('Zu diesem Gutschein gehört kein Kurs. Bitte wende dich an uns.', 'bootspruefung-learning')),
            str_starts_with($code, 'lb_target_'), $code === 'lb_course_unavailable' => new WP_Error('bp_voucher_product', $error->get_error_message()),
            $code === 'lb_temporary_failure', $code === 'lb_shop_unavailable' => new WP_Error('bp_voucher_busy', $error->get_error_message()),
            $code === 'lb_access_exists' => new WP_Error('bp_voucher_access_exists', $error->get_error_message()),
            default => new WP_Error('bp_voucher_invalid', $error->get_error_message() ?: __('Dieser Gutschein kann nicht eingelöst werden.', 'bootspruefung-learning')),
        };
        $mapped->add_data(['grant_error' => $code]);

        return $mapped;
    }

    /** Ergebnis wie vom Grant-Dienst für eine Einlösung aus der Zeit vor 1.12 (interne Bestellung). @return array<string,mixed> */
    private static function legacyGrant(int $orderId): array
    {
        $rows = Integration::sync()->entitlements()->forOrder($orderId);

        return ['entitlements' => array_map(static fn ($r) => [
            'id' => (int) $r->id, 'course' => (string) $r->course_code, 'feature' => (string) $r->feature_code, 'status' => (string) $r->status,
            'valid_from' => $r->valid_from, 'valid_until' => $r->valid_until,
        ], $rows)];
    }

    /** Bestellung zu einem Code-Hash (direkt aus der Datenbank, HPOS oder Beitragsmeta, ohne Objekt-Cache). */
    public static function orderForHash(string $hash): ?int
    {
        global $wpdb;
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $id = $hpos
            ? $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s ORDER BY id LIMIT 1", self::CODE_META, $hash))
            : $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY meta_id LIMIT 1", self::CODE_META, $hash));

        return $id !== null ? (int) $id : null;
    }

    /** Gutschein-Einlösung (interne Bestellung)? */
    public static function isVoucherOrder(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::CODE_META) !== '';
    }

    /**
     * Einlösung über den Filter bootspruefung_voucher_redeem (App und „Mein Lernen“): Das Gutschein-Plugin prüft
     * den Code und liefert das Produkt; das Lern-Plugin schaltet über den Grant-Dienst frei.
     *
     * @return array{created:bool,redemption_key:string,grant:array<string,mixed>,order_id:int}|WP_Error|null null = kein Kursgutschein (bisheriges Verhalten)
     */
    public static function redeemCode(string $code, int $userId): array|WP_Error|null
    {
        $code = trim($code);
        if ($code === '' || ! self::available()) {
            return null;
        }
        /**
         * Kursgutschein prüfen und als eingelöst verbuchen (Gutschein-Plugin).
         *
         * @param null|array|WP_Error $result  null = kein Gutschein dieses Plugins; ['product_id' => int, 'voucher_order_id' => int]
         *                                     = gültig; WP_Error = abgelehnt (Meldung wird angezeigt)
         * @param string              $code
         * @param int                 $userId
         */
        $answer = apply_filters('bootspruefung_voucher_redeem', null, $code, $userId);
        if ($answer === null || $answer === false) {
            return null;
        }
        if ($answer instanceof WP_Error) {
            return $answer;
        }
        if (! is_array($answer) || (int) ($answer['product_id'] ?? 0) <= 0) {
            return new WP_Error('bp_voucher_invalid', __('Dieser Gutschein kann nicht eingelöst werden.', 'bootspruefung-learning'));
        }
        $result = self::redeem($code, $userId, (int) $answer['product_id'], $answer);
        if ($result instanceof WP_Error) {
            /**
             * Einlösung im Lern-Plugin gescheitert, nachdem das Gutschein-Plugin den Code angenommen hat –
             * z. B. Reservierung zurücknehmen.
             *
             * @param string   $code
             * @param int      $userId
             * @param WP_Error $error
             */
            do_action('bootspruefung_voucher_redeem_failed', $code, $userId, $result);
        }

        return $result;
    }

    /**
     * Zusammenfassung einer Einlösung: Kurse und Zugang (für App und Web).
     *
     * @param array<string,mixed> $grant Ergebnis des Grant-Dienstes (Schlüssel entitlements)
     * @return array{courses:list<string>,status:string,valid_from:?string,valid_until:?string}
     */
    public static function summary(array $grant): array
    {
        $rows = (array) ($grant['entitlements'] ?? []);
        $courses = array_values(array_unique(array_map(static fn ($r) => (string) $r['course'], $rows)));
        $main = null;
        foreach ($rows as $row) {
            if (in_array((string) $row['feature'], ['all', AccessService::QUESTION_BANK], true)) {
                $main = $row;
                break;
            }
        }
        $main ??= $rows[0] ?? null;

        return [
            'courses' => $courses,
            'status' => $main ? (string) $main['status'] : EntitlementPlanner::PENDING,
            'valid_from' => $main && $main['valid_from'] ? (string) $main['valid_from'] : null,
            'valid_until' => $main && $main['valid_until'] ? (string) $main['valid_until'] : null,
        ];
    }

    // ------------------------------------------------------------------ Web: „Mein Lernen“

    /**
     * Einlösung aus dem Formular unter „Mein Lernen“ (gleiche Logik und gleiches Limit wie POST /coupons/redeem).
     *
     * @return array{result:string,message:string,redemption_key:?string}
     */
    public static function submitWeb(int $userId, string $code): array
    {
        $code = trim($code);
        if ($userId <= 0 || $code === '') {
            return ['result' => 'invalid', 'message' => __('Bitte gib einen Code ein.', 'bootspruefung-learning'), 'redemption_key' => null];
        }
        try {
            RateLimiter::hit('redeem|'.$userId, self::RATE_LIMIT, HOUR_IN_SECONDS);
        } catch (ApiException) {
            return ['result' => 'rate_limited', 'message' => __('Zu viele Versuche. Bitte warte eine Stunde und versuche es dann erneut.', 'bootspruefung-learning'), 'redemption_key' => null];
        }
        $result = self::redeemCode($code, $userId);
        if ($result === null) {
            return ['result' => 'invalid', 'message' => __('Dieser Code ist kein gültiger Kursgutschein.', 'bootspruefung-learning'), 'redemption_key' => null];
        }
        if ($result instanceof WP_Error) {
            return ['result' => 'invalid', 'message' => $result->get_error_message(), 'redemption_key' => null];
        }
        $summary = self::summary($result['grant']);
        $names = implode(', ', array_map([Labels::class, 'course'], $summary['courses']));

        return ['result' => $result['created'] ? 'redeemed' : 'already_redeemed', 'redemption_key' => $result['redemption_key'], 'message' => $result['created']
            /* translators: %s: Kursnamen */
            ? sprintf(__('Dein Gutschein ist eingelöst: %s ist freigeschaltet.', 'bootspruefung-learning'), $names)
            /* translators: %s: Kursnamen */
            : sprintf(__('Diesen Gutschein hast du bereits eingelöst (%s).', 'bootspruefung-learning'), $names)];
    }

    public static function handleWebForm(): void
    {
        check_admin_referer('bp_voucher_redeem');
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wp_die(esc_html__('Bitte melde dich an.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        $result = self::submitWeb($userId, sanitize_text_field(wp_unslash((string) ($_POST['bp_voucher_code'] ?? ''))));
        set_transient('bp_voucher_notice_'.$userId, ['message' => $result['message'], 'type' => in_array($result['result'], ['redeemed', 'already_redeemed'], true) ? 'success' : 'error'], 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(Integration::accountUrl() ?? home_url('/'));
        exit;
    }

    /** Formular „Kursgutschein einlösen“ unter „Mein Lernen“ – nur, wenn ein Gutschein-Plugin den Filter belegt. */
    public static function renderAccountForm(int $userId): void
    {
        if ($userId <= 0 || ! self::available()) {
            return;
        }
        $notice = get_transient('bp_voucher_notice_'.$userId);
        if (is_array($notice) && ! empty($notice['message'])) {
            delete_transient('bp_voucher_notice_'.$userId);
            $error = ($notice['type'] ?? '') === 'error';
            printf('<div class="%s" role="%s">%s</div>', $error ? 'woocommerce-error' : 'woocommerce-message', $error ? 'alert' : 'status', esc_html((string) $notice['message']));
        }
        echo '<form method="post" class="bp-voucher-redeem" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_voucher_redeem');
        echo '<input type="hidden" name="action" value="bp_voucher_redeem">';
        echo '<p><label for="bp_voucher_code"><strong>'.esc_html__('Kursgutschein einlösen', 'bootspruefung-learning').'</strong></label><br>';
        echo '<input type="text" class="input-text" id="bp_voucher_code" name="bp_voucher_code" maxlength="190" autocomplete="off" required> ';
        echo '<button type="submit" class="button">'.esc_html__('Einlösen', 'bootspruefung-learning').'</button></p>';
        echo '<p class="description">'.esc_html__('Mit einem Kursgutschein wird der Kurs sofort in deinem Konto freigeschaltet; die Laufzeit beginnt mit der Einlösung.', 'bootspruefung-learning').'</p>';
        echo '</form>';
    }

    // ------------------------------------------------------------------ Mitgliederrabatt

    /**
     * Filter bootspruefung_coupon_allow_with_member_discount: Shop-Gutschein nicht zusammen mit einem Mitgliederrabatt
     * des Lern-Plugins – es sei denn, die Option OPTION_COMBINE ist „yes“ (oder ein späterer Filter erlaubt es).
     *
     * @param mixed $allow
     * @param mixed $coupon
     * @param mixed $cart
     */
    public static function allowWithMemberDiscount($allow, $coupon = null, $cart = null): bool
    {
        if (! $allow) {
            return false;
        }
        if (get_option(self::OPTION_COMBINE) === 'yes') {
            return true;
        }

        return ! self::cartHasMemberDiscount($cart);
    }

    /** Greift im Warenkorb ein Mitgliederrabatt (vermerkt an einer Position oder für Nutzer/Produkt berechnet)? */
    public static function cartHasMemberDiscount($cart = null): bool
    {
        if (! $cart instanceof \WC_Cart) {
            $cart = function_exists('WC') && WC()->cart instanceof \WC_Cart ? WC()->cart : null;
        }
        if ($cart === null || ! class_exists(MemberDiscounts::class)) {
            return false;
        }
        $customer = $cart->get_customer();
        $userId = $customer instanceof \WC_Customer && $customer->get_id() > 0 ? (int) $customer->get_id() : get_current_user_id();
        foreach ($cart->get_cart() as $line) {
            if (! empty($line['bp_member_discount'])) {
                return true;
            }
            $product = $line['data'] ?? null;
            if ($product instanceof \WC_Product && MemberDiscounts::discountFor($userId, $product) !== null) {
                return true;
            }
        }

        return false;
    }
}
