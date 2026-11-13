<?php

declare(strict_types=1);

namespace LearnBoating\VoucherConnector;

use VCAP\API\V1\RedemptionService;
use VCAP\Voucher;

/**
 * Kursgutscheine: Voucher Captain verkauft den Gutschein, die Lernplattform schaltet den Kurs frei.
 *
 * - Ziel: Im Voucher-Captain-Produkt wird ein Kursprodukt (WooCommerce-Produkt/Variation mit „Lernzugang“)
 *   gewählt. Kurs, Bereiche, Laufzeit und Konflikte entscheidet allein die Lernplattform
 *   (bootspruefung_voucher_grants_v1). Das Ziel wird beim Kauf in die Bestellposition übernommen.
 * - Einlösung (App „Mein Lernen“ über bootspruefung_course_voucher_redeemer_v1, Einlöseseite [vcap_redeem]):
 *   reservieren (Schlüssel learnboating:grant:{Gutschein-ID}) → Grant (idempotent über denselben Schlüssel)
 *   → commit. Ein endgültiger Fehler gibt den Gutschein frei, ein vorübergehender lässt die Reservierung
 *   (24 h) stehen; die Warteschlange (stündlich und bei jeder neuen Einlösung) führt offene Einlösungen zu Ende
 *   oder gibt sie frei. Es entsteht nie ein zweiter Zugang und kein doppelter Verbrauch.
 * - Kanal learnboating: Kursgutscheine sind standardmäßig nur dort einlösbar (nicht im Warenkorb, nicht an
 *   der Kasse), es sei denn, das Produkt erlaubt den Shop ausdrücklich. Warenkorb und Kurseinlösung nutzen
 *   denselben Gutscheinstatus von Voucher Captain: eine Reservierung sperrt den jeweils anderen Weg.
 * - Widerruf: Wird der Gutschein ungültig gemacht (z. B. Kauf erstattet), widerruft die Lernplattform den
 *   Zugang nach der Erstattungsregel der Zuordnung („behalten“ bleibt).
 * - Gleichzeitigkeit (T83): Einlösung und Warteschlange bearbeiten einen Gutschein nur unter einer Sperre je
 *   Gutschein (MySQL GET_LOCK, endet mit der Verbindung). Die Warteschlange überspringt gesperrte Gutscheine, eine
 *   zweite Einlösung wartet kurz und bekommt sonst „wird gerade eingelöst“. Die Warteschlange selbst (Option
 *   lbvc_pending) wird nur unter einer eigenen Sperre gelesen und geschrieben (keine verlorenen Einträge).
 * - Einlöseseite [vcap_redeem] (T88, T109): höchstens 30 Versuche je IP und Stunde – ein gemeinsamer Zähler mit App
 *   und „Mein Lernen“ (Vouchers::hitIpLimit() der Lernplattform, Filter bootspruefung_voucher_redeem_per_ip) – und wie
 *   dort höchstens 10 je Konto und Stunde (IPv6 je /64-Netz).
 * - Warenkorb (T109): Die eindeutige Meldung „Das ist ein Kursgutschein …“ gibt es nur angemeldet und nach bestandener
 *   Limitprüfung (dieselben Zähler); Gäste und gesperrte Versuche bekommen eine neutrale Meldung.
 * - Die Warteschlange wird nie ohne ihre Sperre geschrieben: Ist sie belegt, wird die Änderung in 60 Sekunden per
 *   WP-Cron nachgeholt (QUEUE_HOOK), nach mehreren Fehlversuchen nur protokolliert. Nur wenn die Datenbank keine
 *   benannten Sperren kann (GET_LOCK liefert NULL), wird ohne Sperre geschrieben (T115).
 */
final class Connector
{
    public const CHANNEL = 'learnboating';
    public const TYPE = 'course';
    public const PRODUCT_META = '_lbvc_target';
    public const LINE_KEY = 'learnboating_target';
    public const PENDING_OPTION = 'lbvc_pending';
    public const RETRY_HOOK = 'lbvc_retry';
    public const RESERVATION_MINUTES = 1440;
    public const TARGET_PREFIX = 'learnboating:product:';
    /** Zwischenspeicher des Admin-Hinweises auf Kursgutscheine mit ungültigem Ziel. */
    public const NOTICE_TRANSIENT = 'lbvc_broken_targets';
    /** Höchstens so lange alt, falls eine Änderung ohne Produkt-Hook passiert (z. B. direkt in der Datenbank). */
    public const NOTICE_TTL = 10 * MINUTE_IN_SECONDS;
    /** Sekunden, die eine Einlösung auf die Sperre ihres Gutscheins wartet (Filter lbvc_lock_timeout). */
    public const LOCK_WAIT = 10;
    /**
     * Einlöseversuche je IP und Stunde, nur falls die Lernplattform Vouchers::hitIpLimit() (noch) nicht hat; sonst gilt
     * deren gemeinsamer Zähler. Grenze in beiden Fällen über den Filter bootspruefung_voucher_redeem_per_ip.
     */
    public const REDEEM_PER_IP = 30;
    /** WP-Cron: Änderung der Warteschlange nachholen, deren Sperre belegt war (T115). */
    public const QUEUE_HOOK = 'lbvc_queue_edit';
    /** So oft wird eine Änderung der Warteschlange versucht, danach nur noch protokolliert. */
    public const QUEUE_ATTEMPTS = 5;
    /** Einlöseversuche je Konto und Stunde – derselbe Zähler wie App und „Mein Lernen“. */
    public const REDEEM_PER_USER = 10;

    private static ?self $instance = null;
    /** @var array<string,bool> Ergebnis der Limitprüfung je Konto und Gutschein in dieser Anfrage (Warenkorb-Meldung). */
    private array $shopChecked = [];

    public function __construct(private readonly object $grants, private readonly RedemptionService $redemptions)
    {
    }

    public static function boot(): void
    {
        $grants = apply_filters('bootspruefung_voucher_grants_v1', null);
        $ok = function_exists('vcap') && isset(vcap()->redemptions) && vcap()->redemptions instanceof RedemptionService
            && is_object($grants) && method_exists($grants, 'grant') && defined(get_class($grants).'::CONTRACT_VERSION')
            && str_starts_with((string) constant(get_class($grants).'::CONTRACT_VERSION'), '1.');
        if (! $ok) {
            add_action('admin_notices', static function (): void {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-warning"><p>'.esc_html__('LearnBoating Kursgutschein-Connector ist inaktiv: Er braucht die Bootsprüfung-Lernplattform (Grant-Vertrag 1.x) und Voucher Captain.', 'learnboating-voucher-connector').'</p></div>';
                }
            });

            return;
        }
        self::$instance = new self($grants, vcap()->redemptions);
        self::$instance->register();
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::RETRY_HOOK);
        wp_unschedule_hook(self::QUEUE_HOOK);
    }

    public function register(): void
    {
        add_filter('vcap_voucher_types', static fn (array $types): array => $types + [self::TYPE => __('Kursgutschein (LearnBoating)', 'learnboating-voucher-connector')]);
        add_filter('vcap_redemption_channels', static fn (array $c): array => $c + [self::CHANNEL => __('LearnBoating („Mein Lernen“)', 'learnboating-voucher-connector')]);
        add_filter('vcap_redemption_channel_for_target', static fn ($channel, $target) => str_starts_with((string) $target, 'learnboating:') ? self::CHANNEL : $channel, 10, 2);
        add_filter('vcap_redemption_channel_allowed', [$this, 'channelAllowed'], 10, 4);
        add_filter('vcap_shop_channel_refused_message', [$this, 'shopRefusedMessage'], 10, 2);
        add_filter('vcap_redemption_allowed', [$this, 'redemptionAllowed'], 10, 4);
        add_action('vcap_product_settings_fields', [$this, 'productField']);
        add_action('vcap_product_settings_save', [$this, 'productSave'], 10, 2);
        add_filter('vcap_line_snapshot', [$this, 'snapshot'], 10, 2);
        add_filter('vcap_redeem_code', [$this, 'redeemPage'], 10, 2);
        add_action('vcap_voucher_event', [$this, 'onVoucherEvent'], 10, 2);
        add_filter('vcap_event_labels', static fn (array $l): array => $l + [
            'learnboating_granted' => __('Kurs freigeschaltet (LearnBoating)', 'learnboating-voucher-connector'),
            'learnboating_failed' => __('Kursfreischaltung fehlgeschlagen', 'learnboating-voucher-connector'),
            'learnboating_revoked' => __('Kurszugang widerrufen (LearnBoating)', 'learnboating-voucher-connector'),
        ]);
        add_filter('bootspruefung_course_voucher_redeemer_v1', fn () => $this);
        add_filter('bootspruefung_voucher_admin_url', static fn ($url, $meta) => isset($meta['voucher_id']) && ($meta['grant_source'] ?? '') === 'voucher-captain' ? admin_url('admin.php?page=vcap-vouchers&voucher='.(int) $meta['voucher_id']) : $url, 10, 2);
        add_action('admin_notices', [$this, 'targetNotice']);
        // Hinweis sofort neu prüfen, wenn sich Produkte, Variationen oder deren Lernzugang ändern (ProductPanel speichert
        // den Lernzugang bei woocommerce_process_product_meta mit Priorität 20).
        foreach (['woocommerce_process_product_meta' => 30, 'woocommerce_save_product_variation' => 30, 'woocommerce_new_product' => 10, 'woocommerce_update_product' => 10,
            'woocommerce_delete_product' => 10, 'woocommerce_trash_product' => 10, 'woocommerce_update_product_variation' => 10, 'woocommerce_delete_product_variation' => 10,
            'vcap_product_settings_save' => 99, 'untrashed_post' => 10] as $hook => $priority) {
            add_action($hook, [self::class, 'flushNotice'], $priority, 0);
        }
        add_action(self::RETRY_HOOK, [$this, 'retry']);
        add_action(self::QUEUE_HOOK, [self::class, 'deferredQueueEdit'], 10, 4);
        if (! wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::RETRY_HOOK);
        }
    }

    /* ---- Ziel --------------------------------------------------------------------------------------- */

    /** @return array{product_id:int,variation_id:int}|null "product:variation" → IDs */
    public static function parseTarget(string $raw): ?array
    {
        if (preg_match('/^(\d+)(?::(\d+))?$/', trim($raw), $m) !== 1 || (int) $m[1] <= 0) {
            return null;
        }

        return ['product_id' => (int) $m[1], 'variation_id' => (int) ($m[2] ?? 0)];
    }

    /** Kursziel eines Gutscheins: beim Kauf übernommen (Bestellposition), sonst die Produkteinstellung. */
    public function target(Voucher $voucher): ?array
    {
        $target = null;
        if ($voucher->order_item_id && class_exists('\VCAP\Issuer')) {
            $line = wc_get_order_item_meta($voucher->order_item_id, \VCAP\Issuer::LINE_META, true);
            if (is_array($line) && is_array($line[self::LINE_KEY] ?? null)) {
                $target = ['product_id' => (int) $line[self::LINE_KEY]['product_id'], 'variation_id' => (int) ($line[self::LINE_KEY]['variation_id'] ?? 0)];
            }
        }
        if ($target === null) {
            $product = $voucher->product();
            $base = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
            $target = $base ? self::parseTarget((string) $base->get_meta(self::PRODUCT_META)) : null;
        }
        /** Ziel für Gutscheine ohne Produkt (z. B. importierte Codes): ['product_id' => …, 'variation_id' => …] */
        $target = apply_filters('lbvc_voucher_target', $target, $voucher);

        return is_array($target) && (int) ($target['product_id'] ?? 0) > 0 ? ['product_id' => (int) $target['product_id'], 'variation_id' => (int) ($target['variation_id'] ?? 0)] : null;
    }

    public static function targetString(array $target): string
    {
        return self::TARGET_PREFIX.$target['product_id'].':'.$target['variation_id'];
    }

    /* ---- Voucher-Captain-Regeln --------------------------------------------------------------------- */

    /** Kursgutscheine nur über learnboating (Shop nur, wenn am Produkt ausdrücklich angehakt); andere nie über learnboating. */
    public function channelAllowed($allowed, $voucher, $channel, $channels)
    {
        if (! $voucher instanceof Voucher) {
            return $allowed;
        }
        $course = $this->target($voucher) !== null;
        if ($channel === self::CHANNEL) {
            return $course && $allowed;
        }
        if ($course) {
            return in_array((string) $channel, (array) $channels, true) && $allowed;
        }

        return $allowed;
    }

    /**
     * Meldung, wenn ein Kursgutschein im Warenkorb abgelehnt wird (T109): Die eindeutige Meldung bestätigt, dass der Code
     * ein gültiger Kursgutschein ist. Sie kommt deshalb nur für angemeldete Kunden und nach bestandener Limitprüfung
     * (gleiche Zähler wie die Einlösewege: je IP und je Konto); sonst eine neutrale Meldung, die nichts über den Code sagt.
     */
    public function shopRefusedMessage($message, $voucher)
    {
        if (! $voucher instanceof Voucher || $this->target($voucher) === null) {
            return $message;
        }
        $userId = get_current_user_id();
        if ($userId <= 0 || $this->shopLimited($userId, (int) $voucher->id)) {
            return self::neutralShopMessage();
        }

        return __('Das ist ein Kursgutschein. Du löst ihn in „Mein Lernen“ ein (App oder Gutschein-Einlöseseite) – nicht im Warenkorb.', 'learnboating-voucher-connector');
    }

    /** Neutrale Ablehnung im Warenkorb: bestätigt nicht, dass der Code ein Kursgutschein ist. */
    public static function neutralShopMessage(): string
    {
        return __('Dieser Gutscheincode kann im Warenkorb nicht eingelöst werden. Kursgutscheine löst du nach der Anmeldung in „Mein Lernen“ ein.', 'learnboating-voucher-connector');
    }

    /** Limitprüfung für die Warenkorb-Meldung, einmal je Konto und Gutschein in dieser Anfrage (der Warenkorb fragt mehrfach). */
    private function shopLimited(int $userId, int $voucherId): bool
    {
        return $this->shopChecked[$userId.':'.$voucherId] ??= self::redeemLimited($userId);
    }

    /** Für Ziele learnboating:* nur der eigene Kursgutschein mit genau diesem Ziel (auch bei direkten API-Aufrufen). */
    public function redemptionAllowed($allowed, $voucher, $target, $userId)
    {
        if (! str_starts_with((string) $target, 'learnboating:') || ! $voucher instanceof Voucher) {
            return $allowed;
        }
        $own = $this->target($voucher);
        if ($own === null) {
            return new \WP_Error('lb_not_course_voucher', __('Das ist kein Kursgutschein.', 'learnboating-voucher-connector'), ['status' => 422]);
        }
        if (self::targetString($own) !== (string) $target) {
            return new \WP_Error('lb_wrong_course', __('Dieser Gutschein gilt für einen anderen Kurs.', 'learnboating-voucher-connector'), ['status' => 422]);
        }

        return $allowed;
    }

    /* ---- Produkteinstellung („Admin-Verknüpfung“) ----------------------------------------------------- */

    /** @return array<string,string> "product:variation" => Name – nur Produkte mit aktivem Lernzugang */
    public function targetOptions(): array
    {
        global $wpdb;
        $options = [];
        $rows = $wpdb->get_results("SELECT DISTINCT product_id, variation_id FROM {$wpdb->prefix}bp_product_access WHERE active = 1 ORDER BY product_id, variation_id"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ((array) $rows as $row) {
            $target = $this->grants->target((int) $row->product_id, (int) $row->variation_id);
            if (is_array($target)) {
                $options[$row->product_id.':'.$row->variation_id] = $target['name'].($target['sku'] !== '' ? ' ('.$target['sku'].')' : '');
            }
        }
        asort($options);

        return $options;
    }

    public function productField($product): void
    {
        $value = $product instanceof \WC_Product ? (string) $product->get_meta(self::PRODUCT_META) : '';
        $options = ['' => __('– kein Kursgutschein –', 'learnboating-voucher-connector')] + $this->targetOptions();
        if ($value !== '' && ! isset($options[$value])) {
            $options[$value] = __('Nicht mehr verfügbares Kursprodukt', 'learnboating-voucher-connector').' (#'.$value.')';
        }
        woocommerce_wp_select([
            'id' => self::PRODUCT_META,
            'label' => __('Kursprodukt (LearnBoating)', 'learnboating-voucher-connector'),
            'value' => $value,
            'options' => $options,
            'desc_tip' => true,
            'description' => __('Macht den Gutschein zum Kursgutschein: Beim Einlösen in „Mein Lernen“ wird genau dieses Kursprodukt freigeschaltet – Kurse, Bereiche und Laufzeit aus seinem Lernzugang. Das Gutscheinprodukt selbst darf keinen Lernzugang haben.', 'learnboating-voucher-connector'),
        ]);
    }

    /** Speichern nur mit gültigem Ziel; das Gutscheinprodukt selbst darf keinen Lernzugang tragen (sonst Freischaltung schon beim Kauf). */
    public function productSave(\WC_Product $product, $post): void
    {
        if (! is_array($post) || ! array_key_exists(self::PRODUCT_META, $post)) {
            return;
        }
        $raw = sanitize_text_field((string) $post[self::PRODUCT_META]);
        if ($raw === '') {
            $product->delete_meta_data(self::PRODUCT_META);

            return;
        }
        $target = self::parseTarget($raw);
        $valid = $target !== null ? $this->grants->target($target['product_id'], $target['variation_id']) : new \WP_Error('lb_invalid_request', '');
        $problem = is_wp_error($valid) ? ($valid->get_error_message() ?: __('Ungültiges Kursprodukt.', 'learnboating-voucher-connector')) : null;
        global $wpdb;
        if ($problem === null && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}bp_product_access WHERE product_id = %d AND active = 1", $product->get_id())) > 0) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $problem = __('Das Gutscheinprodukt hat selbst einen Lernzugang und würde den Kurs schon beim Kauf freischalten. Entferne den Lernzugang am Gutscheinprodukt.', 'learnboating-voucher-connector');
        }
        if ($problem !== null) {
            if (class_exists('\WC_Admin_Meta_Boxes')) {
                \WC_Admin_Meta_Boxes::add_error(__('Kursgutschein nicht gespeichert:', 'learnboating-voucher-connector').' '.$problem);
            }

            return; // Bisheriges Ziel bleibt.
        }
        $product->update_meta_data(self::PRODUCT_META, $target['product_id'].':'.$target['variation_id']);
        // Mit Kanalauswahl: learnboating ergänzen (ohne Auswahl gilt ohnehin nur learnboating, siehe channelAllowed()).
        $channels = array_values(array_filter((array) $product->get_meta('_vcap_channels')));
        if ($channels !== [] && ! in_array(self::CHANNEL, $channels, true)) {
            $channels[] = self::CHANNEL;
            $product->update_meta_data('_vcap_channels', $channels);
        }
    }

    /** Ziel beim Kauf festhalten: spätere Produktänderungen ändern verkaufte Gutscheine nicht. */
    public function snapshot($extra, $item)
    {
        $extra = (array) $extra;
        $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
        $base = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        $target = $base ? self::parseTarget((string) $base->get_meta(self::PRODUCT_META)) : null;
        if ($target !== null) {
            $extra[self::LINE_KEY] = $target;
        }

        return $extra;
    }

    /** Gespeicherten Hinweis-Stand verwerfen: der nächste Admin-Aufruf prüft die Kursgutscheine neu. */
    public static function flushNotice(): void
    {
        delete_transient(self::NOTICE_TRANSIENT);
    }

    /** Hinweis im Admin auf Kursgutscheine, deren Kursprodukt entfernt, deaktiviert oder nicht mehr zugeordnet ist. */
    public function targetNotice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $broken = get_transient(self::NOTICE_TRANSIENT);
        if (! is_array($broken)) {
            $broken = [];
            foreach (get_posts(['post_type' => 'product', 'post_status' => ['publish', 'private', 'draft'], 'meta_key' => self::PRODUCT_META, 'fields' => 'ids', 'numberposts' => 200]) as $id) { // phpcs:ignore WordPress.DB.SlowDBQuery
                $target = self::parseTarget((string) get_post_meta((int) $id, self::PRODUCT_META, true));
                $check = $target ? $this->grants->target($target['product_id'], $target['variation_id']) : null;
                if (! is_array($check)) {
                    $broken[(int) $id] = is_wp_error($check) ? $check->get_error_message() : '';
                }
            }
            set_transient(self::NOTICE_TRANSIENT, $broken, self::NOTICE_TTL);
        }
        if ($broken === []) {
            return;
        }
        $links = [];
        foreach ($broken as $id => $why) {
            $links[] = '<a href="'.esc_url((string) get_edit_post_link((int) $id)).'">'.esc_html(get_the_title((int) $id)).'</a>'.($why !== '' ? ' – '.esc_html($why) : '');
        }
        echo '<div class="notice notice-error"><p>'.esc_html__('Kursgutscheine können nicht eingelöst werden, weil ihr Kursprodukt fehlt oder keinen Lernzugang mehr hat:', 'learnboating-voucher-connector').'</p><ul><li>'.implode('</li><li>', $links).'</li></ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
    }

    /* ---- Einlösung ---------------------------------------------------------------------------------- */

    public static function key(Voucher $voucher, int $attempt = 1): string
    {
        return 'learnboating:grant:'.$voucher->id.($attempt > 1 ? ':'.$attempt : '');
    }

    /** Laufender oder abgeschlossener Versuch: erster reservierter/verbuchter Schlüssel, sonst der nächste freie. */
    public function attempt(Voucher $voucher): array
    {
        for ($n = 1; $n <= 50; $n++) {
            $row = $this->redemptions->get(self::key($voucher, $n));
            if (is_wp_error($row)) {
                return [$n, null];
            }
            if ($row['state'] !== RedemptionService::STATE_RELEASED) {
                return [$n, $row];
            }
        }

        return [51, null];
    }

    public function is_course_voucher(string $code): bool // phpcs:ignore -- Vertragsname der Lernplattform.
    {
        $voucher = $this->find($code);

        return $voucher !== null && $this->target($voucher) !== null;
    }

    /** Vorschau ohne Reservierung. @return array<string,mixed>|\WP_Error */
    public function check(string $code, int $userId)
    {
        [$voucher, $target] = $this->resolve($code);
        if ($voucher instanceof \WP_Error) {
            return $voucher;
        }
        [, $existing] = $this->attempt($voucher);
        if ($existing && $existing['state'] === RedemptionService::STATE_COMMITTED) {
            return new \WP_Error('vcap_already_redeemed', __('Dieser Gutschein wurde bereits eingelöst.', 'learnboating-voucher-connector'), ['status' => 409]);
        }
        $vc = $this->redemptions->check($voucher->code, ['target' => self::targetString($target), 'user_id' => $userId, 'email' => self::email($userId)]);
        if (is_wp_error($vc) && ! ($existing && $existing['state'] === RedemptionService::STATE_RESERVED && (int) $existing['user_id'] === $userId)) {
            return $vc;
        }
        $preview = $this->grants->check($userId, $target['product_id'], $target['variation_id']);
        if (is_wp_error($preview)) {
            return $preview;
        }

        return ['voucher' => self::voucherData($voucher), 'target' => $preview];
    }

    /**
     * Einlösen für den angemeldeten Nutzer: reservieren → freischalten → verbuchen.
     *
     * @return array{voucher:array,target:array,grant:array}|\WP_Error
     */
    public function redeem(string $code, int $userId)
    {
        $this->retry(20); // Offene Einlösungen anderer zuerst zu Ende führen.
        [$voucher, $target] = $this->resolve($code);
        if ($voucher instanceof \WP_Error) {
            return $voucher;
        }
        // Ein Gutschein wird nur von einer Anfrage zugleich bearbeitet (T83): Einlösung, Doppelklick, Warteschlange.
        if (! self::lockVoucher((int) $voucher->id, self::LOCK_WAIT)) {
            return new \WP_Error('vcap_reserved', __('Dieser Gutschein wird gerade eingelöst.', 'learnboating-voucher-connector'), ['status' => 409, 'retryable' => true]);
        }
        try {
            return $this->redeemLocked($voucher, $target, $userId);
        } finally {
            self::unlockVoucher((int) $voucher->id);
        }
    }

    /** Einlösung unter der Sperre des Gutscheins. @return array|\WP_Error */
    private function redeemLocked(Voucher $voucher, array $target, int $userId)
    {
        [$attempt, $existing] = $this->attempt($voucher);
        $key = self::key($voucher, $attempt);
        if ($existing && $existing['state'] === RedemptionService::STATE_COMMITTED) {
            $grant = $this->grants->get($key);
            if (is_array($grant) && (int) $grant['user_id'] === $userId) {
                return ['voucher' => self::voucherData($voucher), 'target' => $this->targetData($target), 'grant' => $grant]; // Wiederholung: dieselbe Freischaltung.
            }

            return new \WP_Error('vcap_already_redeemed', __('Dieser Gutschein wurde bereits eingelöst.', 'learnboating-voucher-connector'), ['status' => 409]);
        }
        if ($existing && $existing['state'] === RedemptionService::STATE_RESERVED && (int) $existing['user_id'] !== $userId) {
            return new \WP_Error('vcap_reserved', __('Dieser Gutschein wird gerade eingelöst.', 'learnboating-voucher-connector'), ['status' => 409]);
        }
        $own = $existing && $existing['state'] === RedemptionService::STATE_RESERVED;
        if (! $own) {
            // Vor der Reservierung prüfen: kein Zugang möglich → nichts reserviert, der Gutschein bleibt frei.
            // (Ein eigener offener Versuch wird ohne diese Prüfung fortgesetzt: sein Zugang kann schon bestehen.)
            $preview = $this->grants->check($userId, $target['product_id'], $target['variation_id']);
            if (is_wp_error($preview)) {
                return $preview;
            }
        }
        $targetString = self::targetString($target);
        $reserved = $this->redemptions->reserve($voucher->code, [
            'key' => $key,
            'target' => $targetString,
            'user_id' => $userId,
            'email' => self::email($userId), // E-Mail des angemeldeten Kontos: öffnet Gutscheine „nur für diesen Empfänger“.
            'meta' => ['product_id' => $target['product_id'], 'variation_id' => $target['variation_id'], 'user_id' => $userId],
            'ttl_minutes' => (int) apply_filters('lbvc_reservation_minutes', self::RESERVATION_MINUTES),
        ]);
        if (is_wp_error($reserved) && $reserved->get_error_code() === 'vcap_idempotency_conflict' && $existing && $existing['state'] === RedemptionService::STATE_RESERVED && $existing['target'] === $targetString) {
            $reserved = $existing; // Eigener offener Versuch (z. B. nach Zeitüberschreitung): fortsetzen.
        }
        if (is_wp_error($reserved)) {
            return $reserved;
        }
        if (($reserved['state'] ?? '') !== RedemptionService::STATE_RESERVED) {
            return new \WP_Error('vcap_temporary_failure', __('Vorübergehender Fehler. Bitte versuche es erneut.', 'learnboating-voucher-connector'), ['status' => 503]);
        }
        $this->pending($key, ['voucher_id' => $voucher->id, 'user_id' => $userId] + $target);

        return $this->finish($voucher, $key, $userId, $target);
    }

    /** Freischalten und verbuchen (auch aus der Warteschlange). @return array|\WP_Error */
    private function finish(Voucher $voucher, string $key, int $userId, array $target)
    {
        $grant = $this->grants->grant([
            'redemption_key' => $key,
            'user_id' => $userId,
            'product_id' => $target['product_id'],
            'variation_id' => $target['variation_id'],
            'voucher_id' => $voucher->id,
            'source' => 'voucher-captain',
            'source_reference' => 'vcap:voucher:'.$voucher->id,
        ]);
        if (is_wp_error($grant)) {
            $data = (array) $grant->get_error_data();
            if (empty($data['retryable']) && (int) ($data['status'] ?? 500) < 500) {
                $this->redemptions->release($key); // Endgültig: der Gutschein bleibt gültig.
                $this->done($key);
            }
            vcap()->vouchers->log($voucher->id, 'learnboating_failed', null, null, $grant->get_error_code().': '.$grant->get_error_message());

            return $grant;
        }
        $committed = $this->redemptions->commit($key);
        if (is_wp_error($committed)) {
            $code = $committed->get_error_code();
            if (in_array($code, ['vcap_reservation_missing', 'vcap_cancelled', 'vcap_expired'], true)) {
                // Gutschein inzwischen nicht mehr einlösbar (ungültig gemacht, Reservierung verloren): Zugang zurück.
                $this->grants->revoke($key, __('Gutschein nicht mehr einlösbar', 'learnboating-voucher-connector'));
                $this->redemptions->release($key);
                $this->done($key);
                vcap()->vouchers->log($voucher->id, 'learnboating_failed', null, null, $code);

                return $committed;
            }
            // Vorübergehend: Zugang besteht, der Gutschein bleibt reserviert; die Warteschlange verbucht später.
            return ['voucher' => self::voucherData($voucher), 'target' => $this->targetData($target), 'grant' => $grant];
        }
        $this->done($key);
        if (! empty($grant['created'])) {
            $user = get_userdata($userId);
            vcap()->vouchers->log($voucher->id, 'learnboating_granted', null, null, sprintf(
                /* translators: 1: user id, 2: course product, 3: redemption key */
                __('Nutzer #%1$d, Kursprodukt %2$s, Einlöse-ID %3$s', 'learnboating-voucher-connector'),
                $userId,
                $target['product_id'].($target['variation_id'] ? '/'.$target['variation_id'] : ''),
                $key
            ));
            do_action('lbvc_course_granted', $voucher, $grant, $user);
        }

        return ['voucher' => self::voucherData($voucher), 'target' => $this->targetData($target), 'grant' => $grant];
    }

    /**
     * Warteschlange: offene Einlösungen (Grant oder Verbuchung nicht bestätigt) zu Ende führen oder freigeben.
     */
    public function retry(int $limit = 100): void
    {
        $pending = self::queue();
        $n = 0;
        foreach ($pending as $key => $data) {
            if (++$n > $limit) {
                break;
            }
            $age = time() - (int) ($data['since'] ?? 0);
            if ($age < 60 && $limit < 100) {
                continue; // Frisch: läuft vermutlich gerade (eigener Aufruf).
            }
            $voucherId = (int) ($data['voucher_id'] ?? 0);
            if (! self::lockVoucher($voucherId, 0)) {
                continue; // Wird gerade von einer anderen Anfrage bearbeitet: beim nächsten Lauf.
            }
            try {
                $this->retryOne((string) $key, $data, $age);
            } finally {
                self::unlockVoucher($voucherId);
            }
        }
    }

    /** Ein Eintrag der Warteschlange (unter der Sperre seines Gutscheins). @param array<string,mixed> $data */
    private function retryOne(string $key, array $data, int $age): void
    {
        // Unter der Sperre neu lesen: eine parallele Einlösung kann den Eintrag inzwischen erledigt haben.
        $data = self::queue()[$key] ?? null;
        if ($data === null) {
            return;
        }
        $row = $this->redemptions->get((string) $key);
        $voucher = vcap()->vouchers->get((int) ($data['voucher_id'] ?? 0));
        if (is_wp_error($row) || ! $voucher) {
            $this->done((string) $key);
            return;
        }
        if ($row['state'] === RedemptionService::STATE_COMMITTED) {
            $this->done((string) $key);
            return;
        }
        if ($row['state'] === RedemptionService::STATE_RELEASED) {
            // Reservierung abgelaufen/freigegeben: ein schon angelegter Zugang darf nicht ohne Gutschein bleiben.
            if ($this->grants->get((string) $key) !== null) {
                $this->grants->revoke((string) $key, __('Gutscheinreservierung abgelaufen', 'learnboating-voucher-connector'));
                vcap()->vouchers->log($voucher->id, 'learnboating_revoked', null, null, (string) $key);
            }
            $this->done((string) $key);
            return;
        }
        $result = $this->finish($voucher, (string) $key, (int) $data['user_id'], ['product_id' => (int) $data['product_id'], 'variation_id' => (int) $data['variation_id']]);
        if (is_wp_error($result) && $age > (self::RESERVATION_MINUTES - 60) * MINUTE_IN_SECONDS) {
            // Kurz vor Ablauf der Reservierung immer noch nicht möglich: freigeben, der Gutschein bleibt gültig.
            $this->redemptions->release((string) $key);
            $this->done((string) $key);
        }
    }


    /* ---- Sperren und Limits (T83, T88) -------------------------------------------------------------- */

    /**
     * Sperre eines Gutscheins holen; wartet höchstens $wait Sekunden. false = belegt. Kann die Datenbank keine benannten
     * Sperren (NULL), geht es ohne Sperre weiter (Voucher Captain reserviert trotzdem atomar).
     */
    public static function lockVoucher(int $voucherId, int $wait): bool
    {
        return self::lock('voucher:'.$voucherId, $wait) !== false;
    }

    public static function unlockVoucher(int $voucherId): void
    {
        self::unlock('voucher:'.$voucherId);
    }

    /** Name der Sperre in der Datenbank (höchstens 64 Zeichen, je Datenbank und Tabellenpräfix). */
    public static function lockName(string $name): string
    {
        global $wpdb;

        return 'lbvc:'.substr(hash('sha256', DB_NAME.'|'.$wpdb->prefix.'|'.$name), 0, 40);
    }

    /**
     * GET_LOCK auf der Verbindung von WordPress (Name mit Datenbank und Präfix, siehe lockName()).
     * @return bool|null true = gehalten, false = belegt (Wartezeit abgelaufen), null = Datenbank kann keine benannten Sperren
     */
    private static function lock(string $name, int $wait): ?bool
    {
        global $wpdb;
        $wait = max(0, (int) apply_filters('lbvc_lock_timeout', $wait, $name));
        // HyperDB/LudicrousDB: Sperre und Freigabe nicht auf einem Lese-Replikat (andere Verbindung, andere Sperre).
        if (method_exists($wpdb, 'send_reads_to_masters')) {
            $wpdb->send_reads_to_masters();
        }
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lockName($name), $wait));
        if ($result === null) {
            error_log('[learnboating-voucher-connector] GET_LOCK nicht verfügbar: '.$wpdb->last_error); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            return null;
        }

        return (int) $result === 1;
    }

    private static function unlock(string $name): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($name)));
    }

    /**
     * Einlöseseite und Warenkorb: Versuch zählen und prüfen (je IP und je Konto), mit den Zählern der Lernplattform.
     * Das IP-Limit ist derselbe Zähler wie in App und „Mein Lernen“ (T109).
     * @return bool true = zu viele Versuche
     */
    public static function redeemLimited(int $userId): bool
    {
        $limiter = '\Bootspruefung\Learning\Mobile\RateLimiter';
        $vouchers = '\Bootspruefung\Learning\Extensions\Vouchers';
        if (! class_exists($limiter)) {
            return false;
        }
        try {
            if (method_exists($vouchers, 'hitIpLimit')) {
                $vouchers::hitIpLimit();
            } else {
                // Ältere Lernplattform: gleicher Zählername und gleiche Grenze, nur ohne die gemeinsame Hilfe.
                $limiter::hit('lbvc-redeem-ip|'.$limiter::clientKey(), max(1, (int) apply_filters('bootspruefung_voucher_redeem_per_ip', self::REDEEM_PER_IP)), HOUR_IN_SECONDS);
            }
            if ($userId > 0) {
                $limiter::hit('redeem|'.$userId, self::REDEEM_PER_USER, HOUR_IN_SECONDS);
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    /** Gutschein ungültig gemacht (z. B. Kauf erstattet): Zugang nach Erstattungsregel widerrufen. */
    public function onVoucherEvent($voucherId, $event): void
    {
        if ($event !== 'voided') {
            return;
        }
        $voucher = vcap()->vouchers->get((int) $voucherId);
        if (! $voucher || $this->target($voucher) === null || ! apply_filters('lbvc_revoke_on_void', true, $voucher)) {
            return;
        }
        [$attempt, $row] = $this->attempt($voucher);
        if (! $row || $row['state'] !== RedemptionService::STATE_COMMITTED) {
            return;
        }
        $done = $this->grants->revoke(self::key($voucher, $attempt), __('Gutschein ungültig gemacht (z. B. Kauf erstattet)', 'learnboating-voucher-connector'));
        if (is_array($done)) {
            vcap()->vouchers->log($voucher->id, 'learnboating_revoked', null, null, sprintf('%d widerrufen, %d behalten', $done['revoked'], $done['kept']));
        }
    }

    /** Einlöseseite [vcap_redeem]: Kursgutscheine für den angemeldeten Kunden freischalten statt in den Warenkorb. */
    public function redeemPage($handled, $code)
    {
        if ($handled !== null) {
            return $handled;
        }
        // Vor dem Nachschlagen zählen: sonst ließen sich Codes über die Antworten durchprobieren.
        if (self::redeemLimited(get_current_user_id())) {
            wc_add_notice(__('Zu viele Versuche. Bitte warte eine Stunde und versuche es dann erneut.', 'learnboating-voucher-connector'), 'error');

            return 'rejected';
        }
        if (! $this->is_course_voucher((string) $code)) {
            return $handled;
        }
        if (! is_user_logged_in()) {
            wc_add_notice(__('Bitte melde dich an (oder lege ein Konto an), um deinen Kursgutschein einzulösen. Der Kurs wird deinem Konto freigeschaltet.', 'learnboating-voucher-connector'), 'notice');

            return 'login_required';
        }
        $result = $this->redeem((string) $code, get_current_user_id());
        if (is_wp_error($result)) {
            $data = (array) $result->get_error_data();
            $retry = ! empty($data['retryable']) || (int) ($data['status'] ?? 0) >= 500;
            wc_add_notice($retry ? __('Gerade ist ein vorübergehender Fehler aufgetreten. Dein Gutschein ist nicht verloren – bitte versuche es in ein paar Minuten noch einmal.', 'learnboating-voucher-connector') : $result->get_error_message(), 'error');

            return 'rejected';
        }
        $names = array_unique(array_map(static fn ($s) => (string) $s['course_name'], (array) ($result['target']['scopes'] ?? [])));
        wc_add_notice(sprintf(
            /* translators: 1: course names, 2: link */
            __('Freigeschaltet: %1$s. Du findest den Kurs in „Mein Lernen“: %2$s', 'learnboating-voucher-connector'),
            esc_html(implode(', ', $names)),
            '<a href="'.esc_url((string) apply_filters('lbvc_learning_url', home_url('/bootsscheine/'))).'">'.esc_html__('Jetzt lernen', 'learnboating-voucher-connector').'</a>'
        ), 'success');

        return 'course_granted';
    }

    /* ---- Hilfen ------------------------------------------------------------------------------------- */

    private function find(string $code): ?Voucher
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 100) {
            return null;
        }

        return vcap()->vouchers->get_by_code(wc_format_coupon_code($code)) ?? vcap()->vouchers->get_by_code($code) ?? vcap()->vouchers->get_by_code(strtoupper($code));
    }

    /** @return array{0:Voucher|\WP_Error,1:?array} */
    private function resolve(string $code): array
    {
        $voucher = $this->find($code);
        if (! $voucher) {
            return [new \WP_Error('vcap_not_found', __('Dieser Gutscheincode ist ungültig.', 'learnboating-voucher-connector'), ['status' => 404]), null];
        }
        $target = $this->target($voucher);
        if ($target === null || $voucher->is_multi_use()) {
            return [new \WP_Error('lb_not_course_voucher', __('Das ist kein Kursgutschein.', 'learnboating-voucher-connector'), ['status' => 422]), null];
        }

        return [$voucher, $target];
    }

    private function targetData(array $target): array
    {
        $data = $this->grants->target($target['product_id'], $target['variation_id']);
        if (is_array($data)) {
            unset($data['rows']);

            return $data;
        }

        return $target + ['name' => '', 'scopes' => []];
    }

    private static function voucherData(Voucher $voucher): array
    {
        return ['id' => $voucher->id, 'code_hint' => '…'.substr(strtoupper($voucher->code), -4), 'expires_at' => $voucher->expires_at];
    }

    private static function email(int $userId): string
    {
        $user = get_userdata($userId);

        return $user ? (string) $user->user_email : '';
    }

    private function pending(string $key, array $data): void
    {
        self::editQueue('add', $key, $data);
    }

    private function done(string $key): void
    {
        self::editQueue('done', $key);
    }

    /** Warteschlange frisch aus der Datenbank (nicht aus dem Objekt-Cache einer anderen Anfrage). @return array<string,array> */
    private static function queue(): array
    {
        wp_cache_delete(self::PENDING_OPTION, 'options');

        return (array) get_option(self::PENDING_OPTION, []);
    }

    /**
     * Lesen-Ändern-Schreiben der Warteschlange unter eigener Sperre (parallele Einlösungen verlieren keine Einträge).
     * $op 'add' trägt $key mit $data ein (der Beginn „since“ bleibt erhalten), 'done' entfernt ihn.
     *
     * Sperre belegt (false): nicht schreiben – ohne Sperre könnte die Änderung einer parallelen Anfrage verloren gehen.
     * Die Änderung wird stattdessen per WP-Cron in 60 Sekunden nachgeholt (insgesamt höchstens QUEUE_ATTEMPTS Versuche,
     * danach nur protokolliert; ein liegengebliebener Eintrag wird über den Gutscheinstatus ohnehin richtig behandelt).
     * Nur wenn die Datenbank keine benannten Sperren kann (null), wird ohne Sperre geschrieben (T115).
     *
     * @param array<string,mixed> $data
     * @return bool true = geschrieben (oder nichts zu ändern), false = vertagt bzw. verworfen
     */
    public static function editQueue(string $op, string $key, array $data = [], int $attempt = 0): bool
    {
        $locked = self::lock('queue', 5);
        if ($locked === false) {
            $again = $attempt + 1 < self::QUEUE_ATTEMPTS;
            if ($again) {
                wp_schedule_single_event(time() + 60, self::QUEUE_HOOK, [$op, $key, $data, $attempt + 1]);
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Betriebshinweis
            error_log(sprintf('[learnboating-voucher-connector] Warteschlange gesperrt: Änderung „%s“ für %s %s.', $op, $key, $again ? 'wird in 60 Sekunden erneut versucht' : 'verworfen'));

            return false;
        }
        try {
            $before = self::queue();
            $after = $before;
            if ($op === 'add') {
                $after[$key] = $data + ['since' => (int) ($before[$key]['since'] ?? time())];
            } else {
                unset($after[$key]);
            }
            if ($after !== $before) {
                update_option(self::PENDING_OPTION, $after, false);
            }
        } finally {
            if ($locked === true) {
                self::unlock('queue');
            }
        }

        return true;
    }

    /** WP-Cron (QUEUE_HOOK): vertagte Änderung der Warteschlange nachholen. */
    public static function deferredQueueEdit($op, $key, $data = [], $attempt = 1): void
    {
        if (in_array($op, ['add', 'done'], true) && is_string($key) && $key !== '') {
            self::editQueue($op, $key, is_array($data) ? $data : [], (int) $attempt);
        }
    }
}
