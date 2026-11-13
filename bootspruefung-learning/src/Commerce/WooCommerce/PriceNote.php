<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Settings\Settings;

/**
 * Preisangabe nach PAngV (Festlegung 07.11.2026): Hinweis wie „inkl. MwSt.“ an Produktpreisen,
 * im Warenkorb/in der Kasse (Block und klassisch) und in den Bestellmails. Text in den Einstellungen.
 */
final class PriceNote
{
    public function register(): void
    {
        add_filter('woocommerce_get_price_html', [$this, 'priceHtml'], 50, 2);
        add_filter('render_block_woocommerce/checkout', [$this, 'blockNote'], 40, 1);
        add_filter('render_block_woocommerce/cart', [$this, 'blockNote'], 40, 1);
        add_action('woocommerce_review_order_after_order_total', [$this, 'classicRow']);
        add_action('woocommerce_cart_totals_after_order_total', [$this, 'classicRow']);
        add_action('woocommerce_email_after_order_table', [$this, 'email'], 20, 4);
        add_action('wp_enqueue_scripts', [$this, 'smallScreenStyle'], 20);
    }

    /**
     * Mobile Warenkorb-Tabelle (woocommerce-smallscreen.css): WooCommerce stellt jeder Zelle „data-title: “ voran –
     * die Hinweis-Zeile hat keine Beschriftung, sonst stünde dort ein einzelner Doppelpunkt.
     */
    public function smallScreenStyle(): void
    {
        wp_add_inline_style('woocommerce-smallscreen', self::SMALLSCREEN_CSS);
    }

    public const SMALLSCREEN_CSS = '.woocommerce table.shop_table_responsive tr.bp-price-note-row td::before,.woocommerce-page table.shop_table_responsive tr.bp-price-note-row td::before{display:none}';

    public static function text(): string
    {
        return Settings::priceNote();
    }

    public function priceHtml($html, $product)
    {
        $note = self::text();
        if ($note === '' || $html === '' || is_admin() && ! wp_doing_ajax()) {
            return $html;
        }

        return $html.' <small class="bp-price-note">'.esc_html($note).'</small>';
    }

    public function blockNote($content)
    {
        $note = self::text();
        if ($note === '' || is_admin()) {
            return $content;
        }

        return $content.'<p class="bp-price-note bp-price-note-total">'.esc_html(sprintf(__('Alle Preise %s', 'bootspruefung-learning'), $note)).'</p>';
    }

    public function classicRow(): void
    {
        $note = self::text();
        if ($note !== '') {
            echo '<tr class="bp-price-note-row"><td colspan="2"><small>'.esc_html(sprintf(__('Alle Preise %s', 'bootspruefung-learning'), $note)).'</small></td></tr>';
        }
    }

    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        $note = self::text();
        if ($note === '') {
            return;
        }
        $line = sprintf(__('Alle Preise %s', 'bootspruefung-learning'), $note);
        echo $plainText ? esc_html($line)."\n\n" : '<p style="font-size:13px">'.esc_html($line).'</p>'; // phpcs:ignore
    }
}
