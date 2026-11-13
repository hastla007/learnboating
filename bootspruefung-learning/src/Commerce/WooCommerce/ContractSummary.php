<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

defined('ABSPATH') || exit;

use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\MoneyBack;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;

/**
 * Vertragsbestätigung (To-do T52, LIVE-09/-10): In den Bestellmails an Kunden je Position die Laufzeit und – sobald der
 * Zugang angelegt ist – das Enddatum; dazu die Garantie-Erklärung mit Garantiegeber, begünstigten Positionen und dem
 * Hinweis, dass Widerrufsrecht und gesetzliche Mängelrechte unberührt bleiben (§ 479 BGB).
 */
final class ContractSummary
{
    /** Mails, in denen die Übersicht erscheint (nicht bei Erstattung oder Stornierung). */
    private const EMAILS = ['customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_note'];

    public function register(): void
    {
        // Nach dem Hinweis „Lernzugang“ (Integration, 10), vor Zustimmung zum vorzeitigen Beginn (DigitalConsent, 15).
        add_action('woocommerce_email_after_order_table', [$this, 'email'], 12, 4);
    }

    /** @param mixed $order @param mixed $sentToAdmin @param mixed $plainText @param mixed $email */
    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        $emailId = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if ($sentToAdmin || ! $order instanceof \WC_Order || ($emailId !== '' && ! in_array($emailId, self::EMAILS, true))) {
            return;
        }
        echo $plainText ? esc_html(self::text($order))."\n\n" : self::html($order); // phpcs:ignore WordPress.Security.EscapeOutput -- in html() maskiert
    }

    /**
     * Positionen mit Lernzugang bzw. Gruppenlizenz.
     *
     * @return list<array{name:string,duration:string,until:?string,guarantee:bool}>
     */
    public static function lines(\WC_Order $order): array
    {
        global $wpdb;
        $products = new ProductAccessRepository($wpdb);
        $sync = Integration::sync();
        $lines = [];
        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $rows = $products->forItem((int) $item->get_product_id(), (int) $item->get_variation_id());
            if ($rows === []) {
                continue;
            }
            $days = array_map(static fn ($r) => $r->duration_days === null ? null : (int) $r->duration_days, $rows);
            $unlimited = in_array(null, $days, true);
            $duration = $unlimited ? __('unbefristet', 'bootspruefung-learning') : Labels::duration((int) max($days));
            $until = null;
            $group = $sync->groups()?->byItem((int) $item->get_id());
            if ($group !== null && ! empty($group->valid_until)) {
                $until = (string) $group->valid_until;
            }
            foreach ($sync->entitlements()->forItem((int) $item->get_id()) as $ent) {
                if (in_array($ent->status, [EntitlementPlanner::ACTIVE], true) && $ent->valid_until !== null && ($until === null || $ent->valid_until > $until)) {
                    $until = (string) $ent->valid_until;
                }
            }
            $lines[] = [
                'name' => wp_strip_all_tags($item->get_name()),
                'duration' => $duration,
                'until' => $unlimited ? null : $until,
                'guarantee' => MoneyBack::policy((int) $item->get_product_id()) !== null,
            ];
        }

        return $lines;
    }

    /** Laufzeit-Angabe einer Position, z. B. „6 Monate, bis 13.05.2027“ oder „6 Monate ab Zahlungseingang“. @param array{duration:string,until:?string} $line */
    public static function term(array $line): string
    {
        if ($line['duration'] === __('unbefristet', 'bootspruefung-learning')) {
            return $line['duration'];
        }

        return $line['until'] !== null
            ? sprintf(__('%1$s, bis %2$s', 'bootspruefung-learning'), $line['duration'], Labels::date($line['until']))
            : sprintf(__('%s ab Zahlungseingang', 'bootspruefung-learning'), $line['duration']);
    }

    /**
     * Garantiegeber (Name, Anschrift, E-Mail). Standard: Name der Website und Shop-Adresse aus WooCommerce; das Theme
     * setzt per Filter bootspruefung_guarantor die Betreiberangaben ein. Leer, wenn keine Anschrift bekannt ist.
     */
    public static function guarantor(): string
    {
        $address = array_filter([
            trim((string) get_option('woocommerce_store_address').' '.(string) get_option('woocommerce_store_address_2')),
            trim((string) get_option('woocommerce_store_postcode').' '.(string) get_option('woocommerce_store_city')),
        ], static fn ($v) => $v !== '');
        $default = count($address) === 2 ? wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES).', '.implode(', ', $address) : '';

        return trim((string) apply_filters('bootspruefung_guarantor', $default));
    }

    /** Garantie-Erklärung für eine Bestellung (leer ohne Garantie-Positionen). @param list<array{name:string,guarantee:bool}> $lines */
    public static function guaranteeText(array $lines): string
    {
        $names = array_values(array_unique(array_map(static fn ($l) => $l['name'], array_filter($lines, static fn ($l) => $l['guarantee']))));
        if ($names === []) {
            return '';
        }
        $guarantor = self::guarantor();

        return sprintf(
            __('Für %1$s gilt unsere kostenlose Geld-zurück-Garantie: Innerhalb von %2$d Tagen ab Zahlungseingang erstatten wir den Kaufpreis, solange du weniger als %3$d %% der gekauften Inhalte genutzt hast (Bedingungen in den AGB, Nr. 9; Anfrage unter „Mein Lernen“).', 'bootspruefung-learning'),
            implode(', ', $names),
            \Bootspruefung\Learning\Commerce\ShopCatalog::MONEY_BACK_DAYS,
            (int) \Bootspruefung\Learning\Commerce\ShopCatalog::MONEY_BACK_MAX_PROGRESS
        )
            .($guarantor !== '' ? ' '.sprintf(__('Garantiegeber: %s.', 'bootspruefung-learning'), $guarantor) : '')
            .' '.self::statutoryRights();
    }

    /** Hinweis auf die gesetzlichen Rechte (Garantie zusätzlich, nichts eingeschränkt). */
    public static function statutoryRights(): string
    {
        return __('Die Garantie gilt zusätzlich zu deinen gesetzlichen Rechten: Dein Widerrufsrecht und deine gesetzlichen Rechte bei Mängeln (Gewährleistung) bleiben unberührt und werden durch die Garantie nicht eingeschränkt.', 'bootspruefung-learning');
    }

    public static function html(\WC_Order $order): string
    {
        $lines = self::lines($order);
        if ($lines === []) {
            return '';
        }
        $rows = '';
        foreach ($lines as $line) {
            $rows .= '<tr><td style="text-align:left;padding:6px 12px 6px 0;border-bottom:1px solid #e5e5e5">'.esc_html($line['name']).'</td>'
                .'<td style="text-align:left;padding:6px 0;border-bottom:1px solid #e5e5e5">'.esc_html(self::term($line)).'</td></tr>';
        }
        $guarantee = self::guaranteeText($lines);

        return '<section class="bp-contract-summary"><h2>'.esc_html__('Laufzeit deiner Lernzugänge', 'bootspruefung-learning').'</h2>'
            .'<table cellspacing="0" cellpadding="0" style="width:100%;margin-bottom:12px"><thead><tr><th style="text-align:left;padding:0 12px 6px 0">'.esc_html__('Produkt', 'bootspruefung-learning').'</th><th style="text-align:left;padding:0 0 6px">'.esc_html__('Laufzeit', 'bootspruefung-learning').'</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p>'.esc_html__('Die Laufzeit beginnt mit dem Zahlungseingang. Der Zugang endet danach automatisch, es entsteht kein Abo.', 'bootspruefung-learning').'</p>'
            .($guarantee !== '' ? '<p>'.esc_html($guarantee).'</p>' : '')
            .'</section>';
    }

    public static function text(\WC_Order $order): string
    {
        $lines = self::lines($order);
        if ($lines === []) {
            return '';
        }
        $out = strtoupper(__('Laufzeit deiner Lernzugänge', 'bootspruefung-learning'))."\n";
        foreach ($lines as $line) {
            $out .= '- '.$line['name'].': '.self::term($line)."\n";
        }
        $out .= __('Die Laufzeit beginnt mit dem Zahlungseingang. Der Zugang endet danach automatisch, es entsteht kein Abo.', 'bootspruefung-learning');
        $guarantee = self::guaranteeText($lines);

        return $out.($guarantee !== '' ? "\n\n".$guarantee : '');
    }
}
