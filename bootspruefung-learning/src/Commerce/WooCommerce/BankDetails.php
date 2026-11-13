<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

/**
 * Bankdaten bei offener Überweisung jederzeit abrufbar (T50): WooCommerce zeigt sie nur auf der Dankeseite und in der
 * Bestellmail. Solange eine Überweisungs-Bestellung auf Zahlung wartet, stehen Anleitung und Bankverbindung auch in
 * „Mein Konto › Bestellungen › Bestellung ansehen“ (dorthin führt der Hinweis „Zahlung offen“ unter „Mein Lernen“).
 */
final class BankDetails
{
    public function register(): void
    {
        add_action('woocommerce_view_order', [$this, 'render'], 5);
    }

    /** @param mixed $orderId */
    public function render($orderId): void
    {
        echo self::html((int) $orderId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ausgabe von WooCommerce (Gateway)
    }

    /** Anleitung und Bankdaten der Überweisung, wenn die Bestellung noch auf Zahlung wartet, sonst ''. */
    public static function html(int $orderId): string
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order instanceof \WC_Order || $order->get_payment_method() !== 'bacs' || ! in_array($order->get_status(), ['on-hold', 'pending'], true)) {
            return '';
        }
        $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $bacs = $gateways['bacs'] ?? null;
        if (! is_object($bacs) || ! method_exists($bacs, 'thankyou_page')) {
            return '';
        }
        ob_start();
        $bacs->thankyou_page($orderId);
        $inner = trim((string) ob_get_clean());

        return $inner === '' ? '' : '<section class="bp-bank-details"><h2>'.esc_html__('Zahlung per Überweisung', 'bootspruefung-learning').'</h2>'.$inner.'</section>';
    }
}
