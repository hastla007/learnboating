<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\OrderItemSnapshot;
use Bootspruefung\Learning\Commerce\OrderSnapshot;
use Bootspruefung\Learning\Commerce\OrderSource;

/**
 * Liest Bestellungen ausschließlich über WC_Order/CRUD- und Order-Query-APIs
 * (HPOS-kompatibel, Kap. 7.7) – keine direkten wp_posts/wp_postmeta-Abfragen.
 */
final class WooOrderSource implements OrderSource
{
    public function snapshot(int $orderId): ?OrderSnapshot
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund) {
            return null;
        }
        $items = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $items[] = new OrderItemSnapshot(
                (int) $itemId,
                (int) $item->get_product_id(),
                (int) $item->get_variation_id(),
                (int) $item->get_quantity(),
                $product ? (string) $product->get_sku() : '',
                (float) $item->get_total() + (float) $item->get_total_tax(),
                abs((int) $order->get_qty_refunded_for_item($itemId)),
                abs((float) $order->get_total_refunded_for_item($itemId)) + abs($this->taxRefunded($order, (int) $itemId)),
            );
        }
        $paid = $order->get_date_paid();

        return new OrderSnapshot(
            (int) $order->get_id(),
            (int) $order->get_customer_id(),
            (string) $order->get_status(),
            (bool) $order->is_paid(),
            $paid ? gmdate('Y-m-d H:i:s', $paid->getTimestamp()) : null,
            (string) $order->get_payment_method(),
            $items,
            max(0.0, (float) $order->get_total_refunded() - array_sum(array_map(static fn ($i) => $i->refundedTotal, $items))),
        );
    }

    private function taxRefunded(\WC_Order $order, int $itemId): float
    {
        $sum = 0.0;
        foreach ($order->get_taxes() as $tax) {
            $sum += (float) $order->get_tax_refunded_for_item($itemId, $tax->get_rate_id());
        }

        return $sum;
    }

    public function orderIds(array $productIds, ?string $since, int $page, int $perPage): array
    {
        // Order-Query kennt keinen Produktfilter: Bestellungen seitenweise lesen und Positionen prüfen.
        $args = ['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'limit' => $perPage, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects'];
        if ($since !== null) {
            $args['date_created'] = '>='.strtotime($since.' UTC');
        }
        $wanted = array_flip($productIds);
        $out = [];
        $orders = wc_get_orders($args);
        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item) {
                if ($item instanceof \WC_Order_Item_Product && isset($wanted[(int) $item->get_product_id()])) {
                    $out[] = (int) $order->get_id();
                    break;
                }
            }
        }

        return ['ids' => $out, 'more' => count($orders) === $perPage];
    }
}
