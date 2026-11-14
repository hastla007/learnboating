<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Bestell-Schaltfläche nach § 312j Abs. 3 BGB: „Zahlungspflichtig bestellen“ für alle kostenpflichtigen
 * Bestellungen – klassische Kasse, „Bestellung bezahlen“ und Kassen-Block. Ist nichts zu bezahlen
 * (Gesamtbetrag 0, z. B. 100-%-Gutschein), bleibt die Standardbeschriftung von WooCommerce.
 *
 * Zahlungsarten dürfen die Beschriftung sonst ersetzen (klassisch: data-order_button_text, Block:
 * placeOrderButtonLabel der Zahlungsart, z. B. „Weiter zu PayPal“); beides wird hier zurückgesetzt.
 * Zahlungsarten mit eigenem Knopf (z. B. PayPal-Smart-Buttons) sind davon nicht erfasst.
 */
final class OrderButton
{
    private const BLOCK = 'woocommerce/checkout-actions-block';

    public function register(): void
    {
        add_filter('woocommerce_order_button_text', [self::class, 'checkoutText'], 99, 1);
        add_filter('woocommerce_pay_order_button_text', [self::class, 'payText'], 99, 1);
        add_filter('render_block_data', [$this, 'blockData'], 10, 1);
        add_filter('woocommerce_available_payment_gateways', [$this, 'gateways'], 99);
    }

    public static function label(): string
    {
        return __('Zahlungspflichtig bestellen', 'bootspruefung-learning');
    }

    /** Klassische Kasse: nur wenn der Warenkorb zu bezahlen ist (ohne Warenkorb sicherheitshalber ja). */
    public static function checkoutText($text = ''): string
    {
        $cart = function_exists('WC') && WC() ? WC()->cart : null;
        if (is_object($cart) && method_exists($cart, 'needs_payment') && ! $cart->needs_payment()) {
            return (string) $text;
        }

        return self::label();
    }

    /**
     * „Bestellung bezahlen“: Der Filter übergibt nur den Text – die Bestellung kommt aus der Abfragevariable
     * order-pay (ohne erkennbare Bestellung sicherheitshalber „Zahlungspflichtig bestellen“).
     */
    public static function payText($text = ''): string
    {
        $id = function_exists('get_query_var') ? absint(get_query_var('order-pay')) : 0;
        $order = $id > 0 && function_exists('wc_get_order') ? wc_get_order($id) : false;
        if ($order instanceof \WC_Order && ! $order->needs_payment()) {
            return (string) $text;
        }

        return self::label();
    }

    /**
     * Kassen-Block: Das Attribut placeOrderButtonLabel gelangt über BlockTypesController::add_data_attributes()
     * als data-place-order-button-label in das Markup, aus dem das Frontend die Attribute liest.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    public function blockData($block)
    {
        if (! is_array($block) || ($block['blockName'] ?? '') !== self::BLOCK) {
            return $block;
        }
        $attrs = (array) ($block['attrs'] ?? []);
        $original = (string) ($attrs['placeOrderButtonLabel'] ?? '');
        if ($original === '' || $original === self::label()) {
            $original = __('Place Order', 'woocommerce');
        }
        $cart = function_exists('WC') && WC() ? WC()->cart : null;
        $free = is_object($cart) && method_exists($cart, 'needs_payment') && ! $cart->needs_payment();
        if (! $free) {
            $block['attrs'] = array_merge($attrs, ['placeOrderButtonLabel' => self::label()]);
        }
        if (! is_admin() && function_exists('wp_script_is') && wp_script_is('wc-blocks-checkout', 'registered')) {
            // Beschriftung der Zahlungsart (paymentMethodButtonLabel) hat im Block Vorrang – per Checkout-Filter festlegen,
            // aber nur bei Gesamtbetrag > 0 (Store wc/store/cart). Ändert sich der Betrag (Gutschein), Filter neu
            // registrieren: Das leert den Ergebnis-Zwischenspeicher von applyCheckoutFilter.
            $label = (string) wp_json_encode(self::label());
            $fallback = (string) wp_json_encode($original);
            wp_add_inline_script('wc-blocks-checkout', <<<JS
(function(){var b=window.wc&&window.wc.blocksCheckout,d=window.wp&&window.wp.data;if(!b||!b.registerCheckoutFilters||window.bpOrderButton){return;}window.bpOrderButton=1;
function pay(){try{var t=d&&d.select('wc/store/cart')&&d.select('wc/store/cart').getCartTotals();if(!t||t.total_price===undefined||t.total_price===null||t.total_price===''){return true;}return parseInt(t.total_price,10)>0;}catch(e){return true;}}
function reg(){b.registerCheckoutFilters('bootspruefung-order-button',{placeOrderButtonLabel:function(v){if(pay()){return {$label};}return v==={$label}?{$fallback}:v;}});}
reg();var last=pay();if(d&&d.subscribe){d.subscribe(function(){var now=pay();if(now!==last){last=now;reg();}},'wc/store/cart');}})();
JS);
        }

        return $block;
    }

    /**
     * Klassische Kasse und „Bestellung bezahlen“: checkout.js ersetzt die Beschriftung beim Wählen der
     * Zahlungsart durch deren order_button_text (data-order_button_text) – leer lassen, dann bleibt data-value.
     *
     * @param array<string,\WC_Payment_Gateway> $gateways
     * @return array<string,\WC_Payment_Gateway>
     */
    public function gateways($gateways)
    {
        if (! is_array($gateways) || is_admin() && ! wp_doing_ajax()) {
            return $gateways;
        }
        foreach ($gateways as $gateway) {
            if (is_object($gateway) && property_exists($gateway, 'order_button_text')) {
                $gateway->order_button_text = null;
            }
        }

        return $gateways;
    }
}
