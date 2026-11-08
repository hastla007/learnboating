<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Bestell-Schaltfläche nach § 312j Abs. 3 BGB: „Zahlungspflichtig bestellen“ für alle kostenpflichtigen
 * Bestellungen – klassische Kasse, „Bestellung bezahlen“ und Kassen-Block.
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
        add_filter('woocommerce_order_button_text', [self::class, 'label'], 99);
        add_filter('woocommerce_pay_order_button_text', [self::class, 'label'], 99);
        add_filter('render_block_data', [$this, 'blockData'], 10, 1);
        add_filter('woocommerce_available_payment_gateways', [$this, 'gateways'], 99);
    }

    public static function label(): string
    {
        return __('Zahlungspflichtig bestellen', 'bootspruefung-learning');
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
        $block['attrs'] = array_merge((array) ($block['attrs'] ?? []), ['placeOrderButtonLabel' => self::label()]);
        if (! is_admin() && function_exists('wp_script_is') && wp_script_is('wc-blocks-checkout', 'registered')) {
            // Beschriftung der Zahlungsart (paymentMethodButtonLabel) hat im Block Vorrang – per Checkout-Filter festlegen.
            $label = (string) wp_json_encode(self::label());
            wp_add_inline_script('wc-blocks-checkout', "(function(){var b=window.wc&&window.wc.blocksCheckout;if(b&&b.registerCheckoutFilters){b.registerCheckoutFilters('bootspruefung-order-button',{placeOrderButtonLabel:function(){return {$label};}});}})();");
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
