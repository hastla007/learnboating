<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Support\Mail;

/**
 * Elektronische Widerrufsfunktion (§ 356a BGB, Art. 11a der Richtlinie 2011/83/EU in der Fassung der
 * Richtlinie (EU) 2023/2673, anwendbar seit 19.06.2026).
 *
 * - Schaltfläche „Vertrag hier widerrufen“ während der Widerrufsfrist: in der Fußzeile (Seite
 *   /vertrag-widerrufen/), unter „Mein Lernen“ und in der Bestellansicht.
 * - Formular mit Name, Bestellung und E-Mail-Adresse für die Bestätigung, Absenden mit „Widerruf bestätigen“.
 * - Eingangsbestätigung per E-Mail (dauerhafter Datenträger) mit Inhalt der Erklärung und Zeitpunkt des Eingangs.
 *
 * Der Widerruf wird nur erfasst und bestätigt; Rückzahlung und Ende des Zugangs veranlasst der
 * Betreiber über „Erstattung“ in der Bestellung (der Abgleich beendet dann den Zugang). Ob das
 * Widerrufsrecht im Einzelfall schon erloschen war (§ 356 Abs. 5 BGB), entscheidet der Betreiber.
 */
final class WithdrawalFunction
{
    public const META = '_bp_withdrawal';

    public const PAGE_OPTION = 'bp_learning_withdrawal_page';

    public const SLUG = 'vertrag-widerrufen';

    public const SHORTCODE = 'bootspruefung_vertrag_widerrufen';

    /** Widerrufsfrist in Tagen ab Vertragsschluss (§ 355 Abs. 2 BGB). */
    public const DAYS = 14;

    /** Höchstens so viele Erklärungen je Anschluss und Stunde (Schutz vor Missbrauch als Mail-Relay). */
    private const RATE_LIMIT = 5;

    private const STATUSES = ['pending', 'on-hold', 'processing', 'completed'];

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('admin_post_bp_withdrawal', [$this, 'handle']);
        add_action('admin_post_nopriv_bp_withdrawal', [$this, 'handle']);
        add_action('woocommerce_order_details_after_order_table', [$this, 'orderDetails'], 20);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'adminOrder'], 20);
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            add_action(is_admin() ? 'admin_init' : 'init', [self::class, 'ensurePage'], 20);
        }
    }

    public static function buttonLabel(): string
    {
        return __('Vertrag hier widerrufen', 'bootspruefung-learning');
    }

    public static function confirmLabel(): string
    {
        return __('Widerruf bestätigen', 'bootspruefung-learning');
    }

    /** Seite mit dem Formular anlegen bzw. wiederherstellen (veröffentlicht: die Funktion muss jederzeit erreichbar sein). */
    public static function ensurePage(): int
    {
        $id = (int) get_option(self::PAGE_OPTION, 0);
        $post = $id ? get_post($id) : null;
        if ($post instanceof \WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash') {
            if ($post->post_status !== 'publish') {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            }

            return $id;
        }
        $existing = get_page_by_path(self::SLUG);
        if ($existing instanceof \WP_Post && $existing->post_status !== 'trash') {
            $id = (int) $existing->ID;
        } else {
            $id = (int) wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => self::SLUG,
                'post_title' => self::buttonLabel(),
                'post_content' => '<!-- wp:shortcode -->['.self::SHORTCODE.']<!-- /wp:shortcode -->',
                'comment_status' => 'closed',
            ]);
        }
        if ($id > 0) {
            update_option(self::PAGE_OPTION, $id, false);
        }

        return $id;
    }

    public static function url(?int $orderId = null): string
    {
        $id = (int) get_option(self::PAGE_OPTION, 0);
        $base = $id && get_post_status($id) === 'publish' ? (string) get_permalink($id) : home_url('/'.self::SLUG.'/');

        return $orderId ? add_query_arg('bestellung', $orderId, $base) : $base;
    }

    /** Ende der Widerrufsfrist (Ende des 14. Tages nach Vertragsschluss, Zeitzone der Website) oder null. */
    public static function deadline(\WC_Order $order): ?int
    {
        $created = $order->get_date_created();
        if (! $created) {
            return null;
        }
        $zone = wp_timezone();
        $day = (new \DateTimeImmutable('@'.$created->getTimestamp()))->setTimezone($zone)->setTime(23, 59, 59);

        return $day->modify('+'.self::DAYS.' days')->getTimestamp();
    }

    /** Widerrufsfunktion für diese Bestellung anbieten? (innerhalb der Frist, Bestellung nicht storniert/erstattet) */
    public static function offered(\WC_Order $order): bool
    {
        $deadline = self::deadline($order);

        return $deadline !== null && time() <= $deadline && in_array($order->get_status(), self::STATUSES, true);
    }

    /** @return list<array<string,mixed>> */
    public static function received(\WC_Order $order): array
    {
        $value = $order->get_meta(self::META, true);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    // ------------------------------------------------------------------ Ausgabe

    public function shortcode(): string
    {
        $out = '<div class="bp-withdrawal woocommerce">';
        $done = isset($_GET['bp_widerruf']) ? sanitize_key(wp_unslash((string) $_GET['bp_widerruf'])) : '';
        if ($done === 'ok') {
            $at = isset($_GET['zeit']) ? (int) $_GET['zeit'] : 0;
            $out .= '<div class="woocommerce-message" role="status"><p><strong>'.esc_html__('Dein Widerruf ist bei uns eingegangen.', 'bootspruefung-learning').'</strong> '
                .esc_html(sprintf(__('Eingang: %s. Eine Bestätigung mit dem Inhalt deiner Erklärung haben wir dir per E-Mail geschickt.', 'bootspruefung-learning'), $at > 0 ? self::formatTime($at) : '–'))
                .'</p></div></div>';

            return $out;
        }
        if ($done === 'fehler') {
            $out .= '<div class="woocommerce-error" role="alert">'.esc_html__('Bitte gib deinen Namen, die Bestellnummer und eine gültige E-Mail-Adresse an.', 'bootspruefung-learning').'</div>';
        } elseif ($done === 'limit') {
            $out .= '<div class="woocommerce-error" role="alert">'.esc_html(sprintf(__('Zu viele Versuche in kurzer Zeit. Bitte versuche es später erneut oder schreib uns an %s.', 'bootspruefung-learning'), self::contactEmail())).'</div>';
        }

        $user = wp_get_current_user();
        $orders = [];
        if ($user->ID > 0) {
            foreach (wc_get_orders(['customer_id' => $user->ID, 'limit' => 20, 'status' => self::STATUSES, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
                if ($order instanceof \WC_Order && self::offered($order)) {
                    $orders[] = $order;
                }
            }
        }
        $selected = isset($_GET['bestellung']) ? absint($_GET['bestellung']) : 0;

        $out .= '<p>'.esc_html(sprintf(__('Hier kannst du einen bei uns online geschlossenen Vertrag innerhalb der Widerrufsfrist (%d Tage ab Vertragsschluss) widerrufen. Gib dafür deinen Namen, die Bestellung und die E-Mail-Adresse an, an die wir die Eingangsbestätigung schicken sollen.', 'bootspruefung-learning'), self::DAYS)).'</p>';
        $out .= '<p class="bp-muted">'.wp_kses(sprintf(
            /* translators: %s: Link zur Widerrufsbelehrung */
            __('Hast du ausdrücklich zugestimmt, dass wir vor Ablauf der Widerrufsfrist mit der Ausführung beginnen, und hat die Ausführung begonnen, kann dein Widerrufsrecht erloschen sein. Einzelheiten stehen in der %s. Deine Geld-zurück-Garantie unter „Mein Lernen“ bleibt davon unberührt.', 'bootspruefung-learning'),
            '<a href="'.esc_url(DigitalConsent::withdrawalUrl()).'">'.esc_html__('Widerrufsbelehrung', 'bootspruefung-learning').'</a>'
        ), ['a' => ['href' => []]]).'</p>';

        $out .= '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bp-withdrawal-form">'
            .wp_nonce_field('bp_withdrawal', '_wpnonce', true, false)
            .'<input type="hidden" name="action" value="bp_withdrawal">'
            // Feld für Bots (bleibt bei Menschen leer)
            .'<p class="bp-hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>'.esc_html__('Bitte leer lassen', 'bootspruefung-learning').' <input type="text" name="bp_website" tabindex="-1" autocomplete="off"></label></p>';

        $name = trim((string) $user->first_name.' '.(string) $user->last_name);
        if ($name === '' && $user->ID > 0) {
            $name = trim((string) get_user_meta($user->ID, 'billing_first_name', true).' '.(string) get_user_meta($user->ID, 'billing_last_name', true));
        }
        $out .= '<p class="form-row"><label for="bp-withdrawal-name">'.esc_html__('Dein Name', 'bootspruefung-learning').' <abbr class="required" title="'.esc_attr__('erforderlich', 'bootspruefung-learning').'">*</abbr></label>'
            .'<input type="text" class="input-text" id="bp-withdrawal-name" name="bp_name" required autocomplete="name" value="'.esc_attr($name !== '' ? $name : (string) $user->display_name).'"></p>';

        if ($orders !== []) {
            $options = '';
            foreach ($orders as $order) {
                $created = $order->get_date_created();
                $options .= '<option value="'.(int) $order->get_id().'"'.selected($selected, (int) $order->get_id(), false).'>'
                    .esc_html(sprintf(__('Bestellung #%1$s vom %2$s – %3$s', 'bootspruefung-learning'), $order->get_order_number(), $created ? Labels::date(gmdate('Y-m-d H:i:s', $created->getTimestamp())) : '–', self::itemNames($order)))
                    .'</option>';
            }
            $out .= '<p class="form-row"><label for="bp-withdrawal-order">'.esc_html__('Bestellung', 'bootspruefung-learning').' <abbr class="required" title="'.esc_attr__('erforderlich', 'bootspruefung-learning').'">*</abbr></label>'
                .'<select id="bp-withdrawal-order" name="bp_order" required>'.$options.'</select></p>';
        } else {
            $out .= '<p class="form-row"><label for="bp-withdrawal-order">'.esc_html__('Bestellnummer', 'bootspruefung-learning').' <abbr class="required" title="'.esc_attr__('erforderlich', 'bootspruefung-learning').'">*</abbr></label>'
                .'<input type="text" class="input-text" id="bp-withdrawal-order" name="bp_order" required inputmode="numeric" value="'.($selected ? (int) $selected : '').'">'
                .'<small>'.esc_html__('Steht in deiner Bestellbestätigung.', 'bootspruefung-learning').'</small></p>';
        }
        $out .= '<p class="form-row"><label for="bp-withdrawal-email">'.esc_html__('E-Mail-Adresse für die Eingangsbestätigung', 'bootspruefung-learning').' <abbr class="required" title="'.esc_attr__('erforderlich', 'bootspruefung-learning').'">*</abbr></label>'
            .'<input type="email" class="input-text" id="bp-withdrawal-email" name="bp_email" required autocomplete="email" value="'.esc_attr((string) $user->user_email).'"></p>';
        $out .= '<p class="form-row"><label for="bp-withdrawal-note">'.esc_html__('Nachricht (freiwillig)', 'bootspruefung-learning').'</label>'
            .'<textarea id="bp-withdrawal-note" name="bp_note" rows="3" maxlength="1000"></textarea></p>';
        $out .= '<p><button type="submit" class="button wp-element-button bp-withdrawal-confirm">'.esc_html(self::confirmLabel()).'</button></p>';
        $out .= '<p class="bp-muted"><small>'.wp_kses(sprintf(
            __('Wir verwenden deine Angaben nur, um den Widerruf zu bearbeiten und dir den Eingang zu bestätigen (%s).', 'bootspruefung-learning'),
            '<a href="'.esc_url((string) (get_privacy_policy_url() ?: home_url('/datenschutz/'))).'">'.esc_html__('Datenschutzerklärung', 'bootspruefung-learning').'</a>'
        ), ['a' => ['href' => []]]).'</small></p>';
        $out .= '</form></div>';

        return $out;
    }

    /** Abschnitt unter „Mein Lernen“: Bestellungen innerhalb der Widerrufsfrist. */
    public static function renderAccountSection(int $userId): void
    {
        $rows = [];
        foreach (wc_get_orders(['customer_id' => $userId, 'limit' => 20, 'status' => self::STATUSES, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
            if (! $order instanceof \WC_Order || ! self::offered($order)) {
                continue;
            }
            $status = self::received($order) !== []
                ? esc_html__('Widerruf eingegangen', 'bootspruefung-learning')
                : '<a class="button bp-withdrawal-button" href="'.esc_url(self::url((int) $order->get_id())).'">'.esc_html(self::buttonLabel()).'</a>';
            $rows[] = sprintf('<tr><td>#%s</td><td>%s</td><td>%s</td></tr>', esc_html($order->get_order_number()), esc_html(Labels::date(gmdate('Y-m-d H:i:s', (int) self::deadline($order)))), $status);
        }
        if ($rows === []) {
            return;
        }
        echo '<h3>'.esc_html__('Widerruf', 'bootspruefung-learning').'</h3>';
        echo '<table class="woocommerce-table shop_table bp-withdrawal-table"><thead><tr><th>'.esc_html__('Bestellung', 'bootspruefung-learning').'</th><th>'.esc_html__('Widerrufsfrist bis', 'bootspruefung-learning').'</th><th></th></tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
    }

    public function orderDetails($order): void
    {
        if (! $order instanceof \WC_Order || ! self::offered($order) || ! self::hasLearning($order)) {
            return;
        }
        if (self::received($order) !== []) {
            echo '<p class="bp-withdrawal-note">'.esc_html__('Dein Widerruf zu dieser Bestellung ist bei uns eingegangen.', 'bootspruefung-learning').'</p>';

            return;
        }
        echo '<p><a class="button bp-withdrawal-button" href="'.esc_url(self::url((int) $order->get_id())).'">'.esc_html(self::buttonLabel()).'</a></p>';
    }

    public function adminOrder($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        foreach (self::received($order) as $entry) {
            echo '<p class="form-field form-field-wide bp-withdrawal-admin" style="clear:both"><strong>'.esc_html__('Widerruf (Widerrufsfunktion):', 'bootspruefung-learning').'</strong><br>'
                .esc_html(sprintf(__('Eingegangen am %1$s von %2$s <%3$s>.', 'bootspruefung-learning'), self::formatTime((int) $entry['time']), (string) $entry['name'], (string) $entry['email']))
                .'<br>'.esc_html__('Bitte prüfen und ggf. über „Erstattung“ zurückzahlen – der Zugang endet dann automatisch.', 'bootspruefung-learning').'</p>';
        }
    }

    // ------------------------------------------------------------------ Verarbeitung

    public function handle(): void
    {
        $back = wp_get_referer() ?: self::url();
        $back = remove_query_arg(['bp_widerruf', 'zeit'], $back);
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'bp_withdrawal')) {
            wp_die(esc_html__('Die Seite ist abgelaufen. Bitte lade sie neu und sende das Formular erneut.', 'bootspruefung-learning'), '', ['response' => 403, 'back_link' => true]);
        }
        if (! empty($_POST['bp_website'])) {
            wp_safe_redirect(add_query_arg('bp_widerruf', 'fehler', $back));
            exit;
        }
        $name = sanitize_text_field(wp_unslash((string) ($_POST['bp_name'] ?? '')));
        $email = sanitize_email(wp_unslash((string) ($_POST['bp_email'] ?? '')));
        $orderRef = sanitize_text_field(wp_unslash((string) ($_POST['bp_order'] ?? '')));
        $note = mb_substr(sanitize_textarea_field(wp_unslash((string) ($_POST['bp_note'] ?? ''))), 0, 1000);
        if ($name === '' || $orderRef === '' || ! is_email($email)) {
            wp_safe_redirect(add_query_arg('bp_widerruf', 'fehler', $back));
            exit;
        }
        if (! self::withinRateLimit()) {
            wp_safe_redirect(add_query_arg('bp_widerruf', 'limit', $back));
            exit;
        }

        $time = time();
        $order = self::findOrder($orderRef, $email);
        $entry = ['time' => $time, 'name' => $name, 'email' => $email, 'order_ref' => $orderRef, 'note' => $note, 'user_id' => get_current_user_id()];
        $statement = self::statement($entry, $order);

        if ($order instanceof \WC_Order) {
            $list = self::received($order);
            $list[] = $entry;
            $order->update_meta_data(self::META, $list);
            $order->add_order_note(sprintf(
                __("Widerruf über die Widerrufsfunktion eingegangen (%1\$s).\n%2\$s\nBitte prüfen und ggf. über „Erstattung“ zurückzahlen – der Zugang endet dann automatisch.", 'bootspruefung-learning'),
                self::formatTime($time),
                $statement
            ));
            $order->save();
        }
        AuditLog::record('withdrawal.received', 'order', $order instanceof \WC_Order ? (string) $order->get_id() : null, null, [
            'order_ref' => $orderRef, 'matched' => $order instanceof \WC_Order, 'name' => $name, 'email' => $email,
        ]);

        // Eingangsbestätigung auf dauerhaftem Datenträger (E-Mail) mit Inhalt und Zeitpunkt.
        Mail::send($email, sprintf(__('Eingangsbestätigung deines Widerrufs – %s', 'bootspruefung-learning'), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)), sprintf(
            __("Hallo %1\$s,\n\nwir bestätigen den Eingang deines Widerrufs am %2\$s.\n\nInhalt deiner Erklärung:\n%3\$s\n\nWir bearbeiten deinen Widerruf und melden uns bei dir. Bei Fragen erreichst du uns unter %4\$s.", 'bootspruefung-learning'),
            $name,
            self::formatTime($time),
            $statement,
            self::contactEmail()
        ));
        $to = (string) apply_filters('bootspruefung_withdrawal_recipient', get_option('admin_email'), $order);
        Mail::send($to, $order instanceof \WC_Order
            ? sprintf(__('Widerruf zu Bestellung #%s', 'bootspruefung-learning'), $order->get_order_number())
            : __('Widerruf ohne zugeordnete Bestellung', 'bootspruefung-learning'), sprintf(
                __("Über die Widerrufsfunktion ist am %1\$s ein Widerruf eingegangen.\n\n%2\$s\n\n%3\$s", 'bootspruefung-learning'),
                self::formatTime($time),
                $statement,
                $order instanceof \WC_Order
                    ? sprintf(__('Bestellung öffnen: %s', 'bootspruefung-learning'), $order->get_edit_order_url())
                    : __('Die Angaben passen zu keiner Bestellung (Bestellnummer und E-Mail-Adresse prüfen). Bitte mit der Person Kontakt aufnehmen.', 'bootspruefung-learning')
            ));

        wp_safe_redirect(add_query_arg(['bp_widerruf' => 'ok', 'zeit' => $time], remove_query_arg('bestellung', $back)));
        exit;
    }

    /** Bestellung zur Angabe: eigene Bestellung (angemeldet) oder Rechnungs-E-Mail stimmt überein. */
    private static function findOrder(string $ref, string $email): ?\WC_Order
    {
        $id = (int) preg_replace('/\D+/', '', $ref);
        $order = $id > 0 ? wc_get_order($id) : null;
        if (! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund) {
            return null;
        }
        $userId = get_current_user_id();
        if ($userId > 0 && (int) $order->get_customer_id() === $userId) {
            return $order;
        }

        return strcasecmp(trim((string) $order->get_billing_email()), trim($email)) === 0 ? $order : null;
    }

    /** @param array<string,mixed> $entry */
    private static function statement(array $entry, ?\WC_Order $order): string
    {
        $lines = [];
        if ($order instanceof \WC_Order) {
            $created = $order->get_date_created();
            $lines[] = sprintf(__('Hiermit widerrufe ich den Vertrag aus Bestellung #%1$s vom %2$s (%3$s).', 'bootspruefung-learning'), $order->get_order_number(), $created ? Labels::date(gmdate('Y-m-d H:i:s', $created->getTimestamp())) : '–', self::itemNames($order));
        } else {
            $lines[] = sprintf(__('Hiermit widerrufe ich den Vertrag aus Bestellung %s.', 'bootspruefung-learning'), (string) $entry['order_ref']);
        }
        $lines[] = sprintf(__('Name: %s', 'bootspruefung-learning'), (string) $entry['name']);
        $lines[] = sprintf(__('E-Mail-Adresse: %s', 'bootspruefung-learning'), (string) $entry['email']);
        if ((string) $entry['note'] !== '') {
            $lines[] = sprintf(__('Nachricht: %s', 'bootspruefung-learning'), (string) $entry['note']);
        }

        return implode("\n", $lines);
    }

    private static function itemNames(\WC_Order $order): string
    {
        $names = [];
        foreach ($order->get_items('line_item') as $item) {
            $names[] = wp_strip_all_tags($item->get_name());
        }

        return implode(', ', $names);
    }

    private static function hasLearning(\WC_Order $order): bool
    {
        global $wpdb;
        $repo = new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb);
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && $repo->hasAccess((int) $item->get_product_id(), (int) $item->get_variation_id())) {
                return true;
            }
        }

        return false;
    }

    private static function withinRateLimit(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'bp_withdrawal_'.substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 20);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);

        return true;
    }

    private static function contactEmail(): string
    {
        $address = (string) get_option('woocommerce_email_from_address', '');

        return is_email($address) ? $address : (string) get_option('admin_email');
    }

    private static function formatTime(int $time): string
    {
        /* translators: 1: Datum (TT.MM.JJJJ), 2: Uhrzeit (HH:MM:SS) */
        return sprintf(__('%1$s um %2$s Uhr', 'bootspruefung-learning'), (string) wp_date('d.m.Y', $time), (string) wp_date('H:i:s', $time));
    }
}
