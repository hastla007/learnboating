<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\MoneyBack;

/** Geld-zurück-Garantie in WooCommerce: „Mein Lernen“, Anfrage, Bestellansicht. */
final class MoneyBackWoo
{
    public function register(): void
    {
        add_action('admin_post_bp_money_back', [$this, 'handleRequest']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'adminOrder']);
    }

    /** @return array{id:int,user_id:int,status:string,date_paid:?string,refunded:bool,items:list<array{product_id:int,variation_id:int}>} */
    public static function snapshot(\WC_Order $order): array
    {
        $items = [];
        $allocated = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $refundedAmount = abs((float) $order->get_total_refunded_for_item($item->get_id()));
                $allocated += $refundedAmount;
                $items[] = [
                    'product_id' => (int) $item->get_product_id(),
                    'variation_id' => (int) $item->get_variation_id(),
                    // Position (auch teilweise, z. B. Kulanz) erstattet? Dann gilt für sie keine Garantie mehr.
                    'refunded' => $refundedAmount > 0 || abs((int) $order->get_qty_refunded_for_item($item->get_id())) > 0,
                ];
            }
        }
        // Erstattung ohne Positionsbezug (nur Betrag): gilt für die ganze Bestellung.
        if ((float) $order->get_total_refunded() - $allocated > 0.009) {
            foreach ($items as $i => $row) {
                $items[$i]['refunded'] = true;
            }
        }
        $paid = $order->get_date_paid();

        return [
            'id' => (int) $order->get_id(),
            'user_id' => (int) $order->get_customer_id(),
            'status' => (string) $order->get_status(),
            'date_paid' => $paid ? gmdate('Y-m-d H:i:s', $paid->getTimestamp()) : null,
            'refunded' => (float) $order->get_total_refunded() > 0,
            'items' => $items,
        ];
    }

    /** @return array<string,mixed> */
    public static function status(\WC_Order $order): array
    {
        $request = $order->get_meta(MoneyBack::REQUEST_META);

        return MoneyBack::status(self::snapshot($order), is_array($request) && $request !== [] ? $request : null);
    }

    /**
     * Welche Positionen fallen unter die Garantie? Simulator-Zugänge und Gruppenpakete nicht.
     *
     * @return array{amount:float,excluded:list<string>}
     */
    public static function breakdown(\WC_Order $order): array
    {
        $amount = 0.0;
        $excluded = [];
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }
            if (MoneyBack::policy((int) $item->get_product_id()) !== null) {
                $amount += (float) $item->get_total() + (float) $item->get_total_tax();
            } else {
                $excluded[] = wp_strip_all_tags($item->get_name());
            }
        }

        return ['amount' => round($amount, 2), 'excluded' => $excluded];
    }

    private static function money(float $value): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($value)), ENT_QUOTES, 'UTF-8');
    }

    /** Abschnitt unter „Mein Lernen“. */
    public static function renderAccountSection(int $userId): void
    {
        $orders = wc_get_orders(['customer_id' => $userId, 'limit' => 20, 'status' => ['processing', 'completed', 'refunded'], 'orderby' => 'date', 'order' => 'DESC']);
        $rows = [];
        foreach ($orders as $order) {
            $s = self::status($order);
            if (! $s['applies'] || in_array($s['reason'], ['expired', 'not_paid'], true) && $s['requested'] === null) {
                continue;
            }
            $action = '';
            if ($s['eligible']) {
                $parts = self::breakdown($order);
                $confirm = $parts['excluded'] === []
                    ? __('Erstattung anfordern? Nach der Erstattung endet dein Zugang.', 'bootspruefung-learning')
                    : sprintf(__('Erstattung anfordern? Erstattet werden %1$s; nicht umfasst: %2$s. Nach der Erstattung endet der Zugang zu den erstatteten Kursen.', 'bootspruefung-learning'), self::money($parts['amount']), implode(', ', $parts['excluded']));
                $action = '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\''.esc_js($confirm).'\')">'
                    .wp_nonce_field('bp_money_back_'.$order->get_id(), '_wpnonce', true, false)
                    .'<input type="hidden" name="action" value="bp_money_back"><input type="hidden" name="order" value="'.(int) $order->get_id().'">'
                    .'<button type="submit" class="button bp-money-back">'.esc_html__('Geld zurück anfordern', 'bootspruefung-learning').'</button></form>';
            }
            $rows[] = sprintf(
                '<tr><td>#%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
                (int) $order->get_id(),
                // Erstattet bzw. ohne Frist: keine Lernstand-/Fristangaben (sonst „0 % / 0 %“, „dauerhaft“).
                $s['deadline'] === null ? '–' : esc_html(wc_format_localized_decimal((string) $s['progress']).' % / '.wc_format_localized_decimal((string) $s['max_progress']).' %'),
                $s['deadline'] === null ? '–' : esc_html(Labels::date($s['deadline'])),
                esc_html(MoneyBack::reasonLabel($s['reason'])),
                $action,
                ''
            );
        }
        if ($rows === []) {
            return;
        }
        echo '<h3>'.esc_html__('Geld-zurück-Garantie', 'bootspruefung-learning').'</h3>';
        echo '<p>'.esc_html__('Innerhalb der Frist und solange du weniger als die angegebene Grenze gelernt hast, erstatten wir dir den Kaufpreis. Gezählt wird seit dem Kauf, was du gekauft hast: beantwortete Fragen bzw. abgeschlossene Video-Lektionen (beim Video-Upgrade nur die Lektionen). Gruppenpakete und Zugänge zum Funkgerät-Simulator sind von der Garantie ausgenommen. Dein gesetzliches Widerrufsrecht bleibt unberührt.', 'bootspruefung-learning').'</p>';
        echo '<table class="woocommerce-table shop_table bp-money-back-table"><thead><tr><th>'.esc_html__('Bestellung', 'bootspruefung-learning').'</th><th>'.esc_html__('Gelernt / Grenze', 'bootspruefung-learning').'</th><th>'.esc_html__('Frist bis', 'bootspruefung-learning').'</th><th>'.esc_html__('Status', 'bootspruefung-learning').'</th><th></th></tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
    }

    public function handleRequest(): void
    {
        $orderId = absint($_POST['order'] ?? 0);
        check_admin_referer('bp_money_back_'.$orderId);
        $order = wc_get_order($orderId);
        $userId = get_current_user_id();
        $back = Integration::accountUrl() ?? home_url('/');
        if (! $order instanceof \WC_Order || $userId <= 0 || (int) $order->get_customer_id() !== $userId) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        $status = self::status($order);
        if (! $status['eligible']) {
            wc_add_notice(MoneyBack::reasonLabel($status['reason']), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $parts = self::breakdown($order);
        $scope = $parts['excluded'] === []
            ? sprintf(__('Zu erstatten: %s (gesamte Bestellung).', 'bootspruefung-learning'), self::money($parts['amount']))
            : sprintf(__('Zu erstatten: %1$s – nur die Positionen mit Garantie. Ausgenommen (nicht erstatten): %2$s.', 'bootspruefung-learning'), self::money($parts['amount']), implode(', ', $parts['excluded']));
        $request = MoneyBack::recordRequest($orderId, $userId, $status);
        $order->update_meta_data(MoneyBack::REQUEST_META, $request);
        $order->add_order_note(sprintf(
            /* translators: 1: Lernstand, 2: Grenze, 3: Frist */
            __('Geld-zurück-Garantie angefragt. Lernstand %1$s %% (Grenze %2$s %%), Frist bis %3$s. %4$s Bitte über „Erstattung“ die betreffenden Positionen erstatten – deren Zugang endet dann automatisch.', 'bootspruefung-learning'),
            wc_format_localized_decimal((string) $status['progress']),
            wc_format_localized_decimal((string) $status['max_progress']),
            Labels::date($status['deadline']),
            $scope
        ));
        $order->save();
        $to = (string) apply_filters('bootspruefung_money_back_recipient', get_option('admin_email'), $order);
        wp_mail($to, sprintf(__('Geld-zurück-Anfrage zu Bestellung #%d', 'bootspruefung-learning'), $orderId), sprintf(
            __("Kunde %1\$s fordert die Geld-zurück-Garantie an.\n\nBestellung: #%2\$d\nLernstand: %3\$s %% (Grenze %4\$s %%)\nFrist bis: %5\$s\n%7\$s\n\nBestellung öffnen: %6\$s", 'bootspruefung-learning'),
            $order->get_billing_email(),
            $orderId,
            wc_format_localized_decimal((string) $status['progress']),
            wc_format_localized_decimal((string) $status['max_progress']),
            Labels::date($status['deadline']),
            $order->get_edit_order_url(),
            $scope
        ));
        do_action('bootspruefung_money_back_requested', $orderId, $request);
        wc_add_notice($parts['excluded'] === []
            ? __('Deine Anfrage ist eingegangen. Wir erstatten den Betrag in Kürze über deine Zahlungsart.', 'bootspruefung-learning')
            : sprintf(__('Deine Anfrage ist eingegangen. Wir erstatten %1$s in Kürze über deine Zahlungsart. Nicht von der Garantie umfasst und nicht erstattet: %2$s.', 'bootspruefung-learning'), self::money($parts['amount']), implode(', ', $parts['excluded'])));
        wp_safe_redirect($back);
        exit;
    }

    public function adminOrder($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        $s = self::status($order);
        if (! $s['applies']) {
            return;
        }
        echo '<p class="form-field form-field-wide bp-money-back-admin"><strong>'.esc_html__('Geld-zurück-Garantie', 'bootspruefung-learning').':</strong><br>';
        if ($s['deadline'] === null) {
            echo esc_html(MoneyBack::reasonLabel($s['reason'])).'</p>';

            return;
        }
        echo esc_html(sprintf(
            __('Lernstand %1$s %% (Grenze %2$s %%), Frist bis %3$s – %4$s', 'bootspruefung-learning'),
            wc_format_localized_decimal((string) $s['progress']),
            wc_format_localized_decimal((string) $s['max_progress']),
            Labels::date($s['deadline']),
            MoneyBack::reasonLabel($s['reason'])
        ));
        if ($s['requested'] !== null) {
            echo '<br>'.esc_html(sprintf(__('Angefragt am %1$s bei %2$s %% Lernstand.', 'bootspruefung-learning'), Labels::date((string) $s['requested']['at']), wc_format_localized_decimal((string) $s['requested']['progress'])));
        }
        echo '</p>';
    }
}
