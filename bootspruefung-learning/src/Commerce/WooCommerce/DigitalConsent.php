<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\ProductAccessRepository;

/**
 * Zustimmung zum vorzeitigen Beginn bei digitalen Inhalten (§ 356 Abs. 5 BGB): Pflicht-Kästchen in der
 * Kasse (Block und klassisch) und auf „Bestellung bezahlen“, nur wenn der Warenkorb Lernprodukte enthält. Die Zustimmung wird mit
 * Zeitpunkt in der Bestellung gespeichert (_bp_digital_consent), im Bestell-Admin angezeigt und in den
 * Bestellmails an Kundinnen und Kunden bestätigt (dauerhafter Datenträger, § 312f Abs. 3 BGB).
 */
final class DigitalConsent
{
    public const META = '_bp_digital_consent';
    /** Zusätzliches Kassenfeld (Additional Checkout Fields API, Ort „order“). */
    public const FIELD = 'bootspruefung/digital-consent';
    /** Feldname im klassischen Checkout. */
    public const POST_FIELD = 'bp_digital_consent';
    /** Store-API-Erweiterung des Warenkorbs: cart.extensions.bootspruefung.digital (für die Feldregel). */
    public const EXTENSION = 'bootspruefung';

    private const EMAILS = ['customer_processing_order', 'customer_completed_order', 'customer_on_hold_order'];

    public function register(): void
    {
        // Checkout-Block: Kassenfeld + Warenkorb-Kennzeichen, an dem die Feldregel „ausblenden“ hängt.
        $this->whenReady('woocommerce_blocks_loaded', [$this, 'registerCartExtension']);
        $this->whenReady('woocommerce_init', [$this, 'registerBlockField']);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'blockOrder'], 20, 2);
        add_filter('render_block_woocommerce/checkout', [$this, 'blockNote'], 30, 1);

        // Klassischer Checkout.
        add_action('woocommerce_review_order_before_submit', [$this, 'classicField']);
        add_action('woocommerce_checkout_process', [$this, 'classicValidate']);
        add_action('woocommerce_checkout_create_order', [$this, 'classicSave'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'classicScript'], 20);

        // „Bestellung bezahlen“ (order-pay, z. B. vom Shop angelegte Bestellung oder erneuter Zahlungsversuch).
        add_action('woocommerce_pay_order_before_submit', [$this, 'payField']);
        add_action('woocommerce_before_pay_action', [$this, 'payValidate']);

        // Anzeige und Bestätigung.
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'adminNotice']);
        add_action('woocommerce_email_after_order_table', [$this, 'email'], 15, 4);
    }

    public static function label(): string
    {
        return __('Ich stimme ausdrücklich zu, dass ihr vor Ablauf der Widerrufsfrist mit der Bereitstellung der digitalen Inhalte beginnt. Mir ist bekannt, dass ich dadurch mein Widerrufsrecht verliere.', 'bootspruefung-learning');
    }

    public static function errorMessage(): string
    {
        return __('Bitte bestätige die Zustimmung zum sofortigen Beginn der Bereitstellung der digitalen Inhalte – ohne sie können wir deine Bestellung nicht annehmen.', 'bootspruefung-learning');
    }

    public static function withdrawalUrl(): string
    {
        $page = function_exists('get_page_by_path') ? get_page_by_path('widerruf') : null;

        return $page instanceof \WP_Post && $page->post_status === 'publish' ? (string) get_permalink($page) : home_url('/widerruf/');
    }

    /**
     * Gespeicherte Zustimmung einer Bestellung.
     *
     * @return array{time:int,text:string,source:string}|null
     */
    public static function consentFor(\WC_Order $order): ?array
    {
        $value = $order->get_meta(self::META, true);
        if (! is_array($value) || empty($value['time'])) {
            return null;
        }

        return ['time' => (int) $value['time'], 'text' => (string) ($value['text'] ?? ''), 'source' => (string) ($value['source'] ?? '')];
    }

    // ------------------------------------------------------------------ Checkout-Block

    public function registerCartExtension(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data') || ! class_exists(\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::class)) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => self::EXTENSION,
            'data_callback' => static fn (): array => ['digital' => Integration::cartHasLearning()],
            'schema_callback' => static fn (): array => [
                'digital' => [
                    'description' => __('Warenkorb enthält Lernzugänge (digitale Inhalte).', 'bootspruefung-learning'),
                    'type' => 'boolean',
                    'context' => ['view', 'edit'],
                    'readonly' => true,
                ],
            ],
            'schema_type' => ARRAY_A,
        ]);
    }

    public function registerBlockField(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        woocommerce_register_additional_checkout_field([
            'id' => self::FIELD,
            'label' => self::label(),
            'location' => 'order',
            'type' => 'checkbox',
            'required' => true,
            'error_message' => self::errorMessage(),
            // Nur ausblenden, wenn der Warenkorb ausdrücklich keine Lernprodukte meldet; fehlt das
            // Kennzeichen, bleibt das Feld sichtbar und Pflicht (sichere Richtung).
            'hidden' => [
                'cart' => [
                    'required' => ['extensions'],
                    'properties' => [
                        'extensions' => [
                            'required' => [self::EXTENSION],
                            'properties' => [
                                self::EXTENSION => [
                                    'required' => ['digital'],
                                    'properties' => ['digital' => ['const' => false]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** Store API (Bestellung absenden): Zustimmung prüfen (zusätzlich zur Feldregel) und mit Zeitpunkt speichern. */
    public function blockOrder($order, $request): void
    {
        if (! $order instanceof \WC_Order || ! is_object($request) || ! method_exists($request, 'get_method') || $request->get_method() !== 'POST') {
            return; // PUT/PATCH: Entwurf, noch keine Bestellung
        }
        if (! $this->orderHasLearning($order)) {
            return;
        }
        $fields = (array) $request->get_param('additional_fields');
        if (! filter_var($fields[self::FIELD] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('bootspruefung_digital_consent_required', self::errorMessage(), 400);
            }
            throw new \RuntimeException(self::errorMessage());
        }
        $this->store($order, 'block');
    }

    /** Link zur Widerrufsbelehrung unter der Kasse (das Feld-Label im Block erlaubt kein HTML). */
    public function blockNote($content)
    {
        if (is_admin() || ! Integration::cartHasLearning()) {
            return $content;
        }

        return $content.'<p class="bp-digital-consent-note"><small>'.sprintf(
            /* translators: %s: Link „Widerrufsbelehrung“ */
            esc_html__('Bei Lernzugängen bitten wir dich in der Kasse um die Zustimmung zum sofortigen Beginn. Details: %s', 'bootspruefung-learning'),
            '<a href="'.esc_url(self::withdrawalUrl()).'">'.esc_html__('Widerrufsbelehrung', 'bootspruefung-learning').'</a>'
        ).'</small></p>';
    }

    // ------------------------------------------------------------------ Klassischer Checkout

    public function classicField(): void
    {
        if (! Integration::cartHasLearning()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- nur Wiederanzeige nach Fehlermeldung
        echo self::fieldHtml(! empty($_POST[self::POST_FIELD])); // phpcs:ignore WordPress.Security.EscapeOutput -- in fieldHtml() escaped
    }

    /** Pflicht-Kästchen (klassische Kasse und „Bestellung bezahlen“), barrierearm: aria-required, Hinweis auf neues Fenster, „erforderlich“ für Screenreader. */
    public static function fieldHtml(bool $checked): string
    {
        $link = ' <a href="'.esc_url(self::withdrawalUrl()).'" target="_blank" rel="noopener">'.esc_html__('Widerrufsbelehrung', 'bootspruefung-learning')
            .' <span class="bp-new-window">'.esc_html__('(öffnet in neuem Fenster)', 'bootspruefung-learning').'</span></a>';

        return '<p class="form-row validate-required bp-digital-consent" id="'.esc_attr(self::POST_FIELD).'_field">'
            .'<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="'.esc_attr(self::POST_FIELD).'">'
            .'<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="'.esc_attr(self::POST_FIELD).'" id="'.esc_attr(self::POST_FIELD).'" value="1" aria-required="true"'.($checked ? ' checked' : '').'> '
            .'<span>'.esc_html(self::label()).$link.'</span>&nbsp;<span class="required" aria-hidden="true">*</span>'
            .'<span class="screen-reader-text">'.esc_html__('(erforderlich)', 'bootspruefung-learning').'</span>'
            .'</label></p>';
    }

    public function classicValidate(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Checkout-Nonce
        if (Integration::cartHasLearning() && empty($_POST[self::POST_FIELD])) {
            wc_add_notice(self::errorMessage(), 'error', ['id' => self::POST_FIELD]);
        }
    }

    public function classicSave($order, $data = []): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Checkout-Nonce
        if ($order instanceof \WC_Order && ! empty($_POST[self::POST_FIELD]) && $this->orderHasLearning($order)) {
            $this->store($order, 'classic');
        }
    }

    /** Der klassische Checkout ersetzt den Bereich mit dem Kästchen bei jeder Aktualisierung: Haken merken. */
    public function classicScript(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout() || ! wp_script_is('wc-checkout', 'enqueued')) {
            return;
        }
        $name = self::POST_FIELD;
        // Zustand bei jeder Änderung merken (nicht erst bei update_checkout): Wird das Kästchen gesetzt,
        // während update_order_review noch läuft, ersetzt die Antwort es sonst durch ein leeres.
        wp_add_inline_script('wc-checkout', "jQuery(function($){var c=$('#{$name}').prop('checked')===true;$(document.body).on('change','#{$name}',function(){c=this.checked;}).on('updated_checkout',function(){ $('#{$name}').prop('checked',c);});});");
    }

    // ------------------------------------------------------------------ Bestellung bezahlen (order-pay)

    public function payField(): void
    {
        $order = self::payOrder();
        if ($order === null || self::consentFor($order) !== null || ! $this->orderHasLearning($order)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- nur Wiederanzeige nach Fehlermeldung
        echo self::fieldHtml(! empty($_POST[self::POST_FIELD])); // phpcs:ignore WordPress.Security.EscapeOutput -- in fieldHtml() escaped
    }

    /**
     * WC_Form_Handler::pay_action(): Eine Fehlermeldung hier verhindert process_payment() (wc_notice_count('error')),
     * die Seite „Bestellung bezahlen“ wird mit der Meldung erneut angezeigt.
     */
    public function payValidate($order): void
    {
        if (! $order instanceof \WC_Order || self::consentFor($order) !== null || ! $this->orderHasLearning($order)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce prüft die Nonce woocommerce-pay
        if (empty($_POST[self::POST_FIELD])) {
            wc_add_notice(self::errorMessage(), 'error', ['id' => self::POST_FIELD]);

            return;
        }
        $this->store($order, 'order_pay');
        $order->save();
    }

    private static function payOrder(): ?\WC_Order
    {
        $id = absint(get_query_var('order-pay'));
        $order = $id > 0 ? wc_get_order($id) : false;

        return $order instanceof \WC_Order ? $order : null;
    }

    // ------------------------------------------------------------------ Admin und E-Mail

    public function adminNotice($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        $consent = self::consentFor($order);
        if ($consent === null && ! $this->orderHasLearning($order)) {
            return;
        }
        echo '<p class="form-field form-field-wide bp-digital-consent-admin"><strong>'.esc_html__('Widerrufsrecht (digitale Inhalte):', 'bootspruefung-learning').'</strong><br>';
        if ($consent === null) {
            echo esc_html__('Keine Zustimmung zum vorzeitigen Beginn gespeichert.', 'bootspruefung-learning');
        } else {
            echo esc_html(sprintf(
                /* translators: 1: Datum und Uhrzeit, 2: Checkout-Art */
                __('Zustimmung zum vorzeitigen Beginn und Kenntnis vom Verlust des Widerrufsrechts bestätigt am %1$s (%2$s).', 'bootspruefung-learning'),
                self::formatTime($consent['time']),
                self::sourceLabel($consent['source'])
            ));
        }
        echo '</p>';
    }

    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        $emailId = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if ($sentToAdmin || ! $order instanceof \WC_Order || ! in_array($emailId, self::EMAILS, true)) {
            return;
        }
        $consent = self::consentFor($order);
        if ($consent === null) {
            return;
        }
        $text = sprintf(
            /* translators: %s: Datum und Uhrzeit der Zustimmung */
            __('Bestätigung: Du hast am %s ausdrücklich zugestimmt, dass wir vor Ablauf der Widerrufsfrist mit der Bereitstellung der digitalen Inhalte beginnen, und bestätigt, dass dir bekannt ist, dass du dadurch dein Widerrufsrecht verlierst.', 'bootspruefung-learning'),
            self::formatTime($consent['time'])
        );
        if ($plainText) {
            echo esc_html($text).' '.esc_html__('Widerrufsbelehrung:', 'bootspruefung-learning').' '.esc_url_raw(self::withdrawalUrl())."\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput

            return;
        }
        echo '<p class="bp-digital-consent-email">'.esc_html($text).' <a href="'.esc_url(self::withdrawalUrl()).'">'.esc_html__('Widerrufsbelehrung', 'bootspruefung-learning').'</a></p>';
    }

    // ------------------------------------------------------------------ intern

    private function store(\WC_Order $order, string $source): void
    {
        if (self::consentFor($order) !== null) {
            return; // erste Zustimmung bleibt maßgeblich (z. B. erneuter Zahlungsversuch)
        }
        $order->update_meta_data(self::META, ['time' => time(), 'text' => self::label(), 'source' => $source]);
    }

    private static function sourceLabel(string $source): string
    {
        switch ($source) {
            case 'classic':
                return __('klassische Kasse', 'bootspruefung-learning');
            case 'order_pay':
                return __('Bestellung bezahlen', 'bootspruefung-learning');
            default:
                return __('Kassen-Block', 'bootspruefung-learning');
        }
    }

    private static function formatTime(int $time): string
    {
        $date = function_exists('wp_date') ? (string) wp_date('d.m.Y', $time) : gmdate('d.m.Y', $time);
        $clock = function_exists('wp_date') ? (string) wp_date('H:i', $time) : gmdate('H:i', $time);

        /* translators: 1: Datum (TT.MM.JJJJ), 2: Uhrzeit (HH:MM) */
        return sprintf(__('%1$s um %2$s Uhr', 'bootspruefung-learning'), $date, $clock);
    }

    private function orderHasLearning(\WC_Order $order): bool
    {
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && $repo->hasAccess((int) $item->get_product_id(), (int) $item->get_variation_id())) {
                return true;
            }
        }

        return false;
    }

    private function whenReady(string $hook, callable $callback): void
    {
        if (did_action($hook)) {
            $callback();

            return;
        }
        add_action($hook, $callback);
    }
}
