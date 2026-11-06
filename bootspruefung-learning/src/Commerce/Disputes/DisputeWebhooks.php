<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\Disputes;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Rest\RestRegistrar;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Automatische Behandlung von Zahlungskonflikten (Kap. 7.5) für Gateways, die
 * Disputes nicht als WooCommerce-Status abbilden:
 *
 * - PayPal: Webhook /wp-json/bootspruefung/v1/webhooks/paypal für
 *   CUSTOMER.DISPUTE.CREATED/UPDATED/RESOLVED. Jede Nachricht wird über die
 *   PayPal-API (verify-webhook-signature) geprüft; die Bestellung wird über die
 *   Transaktions-ID (Capture-ID) gefunden.
 * - Klarna und alle anderen: gateway-neutraler Webhook
 *   /wp-json/bootspruefung/v1/webhooks/dispute mit HMAC-SHA256-Signatur
 *   (Header X-Bootspruefung-Signature: sha256=<hex>) über den Rohtext, z. B. für
 *   Benachrichtigungen aus dem Klarna-Händlerportal über eine Automatisierung.
 *
 * Ergebnis: Konflikt eröffnet → aussetzen; zugunsten des Händlers geklärt →
 * aufheben; zugunsten des Käufers → widerrufen. Jedes Ereignis wird nur einmal
 * verarbeitet und protokolliert.
 */
final class DisputeWebhooks
{
    public const OPTION = 'bp_learning_disputes';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_post_bp_dispute_settings', [$this, 'saveSettings']);
    }

    /** @return array{paypal_client_id:string,paypal_secret:string,paypal_webhook_id:string,paypal_mode:string,generic_secret:string} */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(['paypal_client_id' => '', 'paypal_secret' => '', 'paypal_webhook_id' => '', 'paypal_mode' => 'live', 'generic_secret' => ''], is_array($stored) ? $stored : []);
    }

    public function routes(): void
    {
        register_rest_route(RestRegistrar::NAMESPACE, '/webhooks/paypal', ['methods' => 'POST', 'callback' => [$this, 'paypal'], 'permission_callback' => '__return_true']);
        register_rest_route(RestRegistrar::NAMESPACE, '/webhooks/dispute', ['methods' => 'POST', 'callback' => [$this, 'generic'], 'permission_callback' => '__return_true']);
    }

    // ------------------------------------------------------------------ PayPal

    public function paypal(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $settings = self::settings();
        if ($settings['paypal_webhook_id'] === '' || $settings['paypal_client_id'] === '' || $settings['paypal_secret'] === '') {
            return new WP_Error('bp_not_configured', 'PayPal webhook not configured.', ['status' => 503]);
        }
        $event = json_decode((string) $request->get_body(), true);
        if (! is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return new WP_Error('bp_invalid', 'Invalid payload.', ['status' => 400]);
        }
        if (! $this->verifyPaypal($request, $event, $settings)) {
            AuditLog::record('dispute_webhook_rejected', 'webhook', 'paypal', 'signature', ['event' => (string) $event['id']]);

            return new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401]);
        }
        $action = self::paypalAction($event);
        if ($action === null) {
            return new WP_REST_Response(['ignored' => true], 200);
        }
        $transactions = array_filter(array_map(static fn ($t) => (string) ($t['seller_transaction_id'] ?? ''), (array) ($event['resource']['disputed_transactions'] ?? [])));

        return new WP_REST_Response($this->handle('paypal', (string) $event['id'], $action, null, $transactions, (string) ($event['resource']['dispute_id'] ?? '')), 200);
    }

    /**
     * Ordnet ein PayPal-Ereignis einer Aktion zu.
     *
     * @param array<string,mixed> $event
     */
    public static function paypalAction(array $event): ?string
    {
        $type = (string) $event['event_type'];
        if (in_array($type, ['CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED'], true)) {
            return 'opened';
        }
        if ($type === 'CUSTOMER.DISPUTE.RESOLVED') {
            $outcome = (string) ($event['resource']['dispute_outcome']['outcome_code'] ?? '');

            return in_array($outcome, ['RESOLVED_BUYER_FAVOUR', 'ACCEPTED'], true) ? 'lost' : 'won';
        }

        return null;
    }

    /** @param array<string,mixed> $event @param array<string,string> $settings */
    private function verifyPaypal(WP_REST_Request $request, array $event, array $settings): bool
    {
        /** Filter: Prüfergebnis vorgeben (nur für Tests); null = echte Prüfung bei PayPal. */
        $forced = apply_filters('bootspruefung_paypal_verify', null, $event);
        if (is_bool($forced)) {
            return $forced;
        }
        $api = $settings['paypal_mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $token = wp_remote_post($api.'/v1/oauth2/token', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Basic '.base64_encode($settings['paypal_client_id'].':'.$settings['paypal_secret']), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'grant_type=client_credentials',
        ]);
        $access = json_decode((string) wp_remote_retrieve_body($token), true)['access_token'] ?? null;
        if (! is_string($access)) {
            return false;
        }
        $verify = wp_remote_post($api.'/v1/notifications/verify-webhook-signature', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer '.$access, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'auth_algo' => $request->get_header('paypal_auth_algo'),
                'cert_url' => $request->get_header('paypal_cert_url'),
                'transmission_id' => $request->get_header('paypal_transmission_id'),
                'transmission_sig' => $request->get_header('paypal_transmission_sig'),
                'transmission_time' => $request->get_header('paypal_transmission_time'),
                'webhook_id' => $settings['paypal_webhook_id'],
                'webhook_event' => $event,
            ]),
        ]);

        return (json_decode((string) wp_remote_retrieve_body($verify), true)['verification_status'] ?? '') === 'SUCCESS';
    }

    // ------------------------------------------------------------------ gateway-neutral (Klarna u. a.)

    public function generic(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $secret = self::settings()['generic_secret'];
        if ($secret === '') {
            return new WP_Error('bp_not_configured', 'Dispute webhook not configured.', ['status' => 503]);
        }
        $body = (string) $request->get_body();
        $signature = (string) $request->get_header('x_bootspruefung_signature');
        if (! hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
            AuditLog::record('dispute_webhook_rejected', 'webhook', 'generic', 'signature');

            return new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401]);
        }
        $data = json_decode($body, true);
        $action = is_array($data) ? (string) ($data['event'] ?? '') : '';
        if (! in_array($action, ['opened', 'won', 'lost'], true) || (empty($data['order_id']) && empty($data['transaction_id']))) {
            return new WP_Error('bp_invalid', 'Expected event (opened|won|lost) and order_id or transaction_id.', ['status' => 400]);
        }
        $eventId = (string) ($data['id'] ?? hash('sha256', $body));

        return new WP_REST_Response($this->handle((string) ($data['source'] ?? 'generic'), $eventId, $action, empty($data['order_id']) ? null : (int) $data['order_id'],
            empty($data['transaction_id']) ? [] : [(string) $data['transaction_id']], (string) ($data['reason'] ?? '')), 200);
    }

    // ------------------------------------------------------------------ gemeinsam

    /**
     * @param list<string> $transactions
     * @return array<string,mixed>
     */
    public function handle(string $source, string $eventId, string $action, ?int $orderId, array $transactions, string $reference): array
    {
        $key = 'bp_dispute_'.md5($source.'|'.$eventId);
        if (get_transient($key)) {
            return ['duplicate' => true];
        }
        $orderIds = $orderId ? [$orderId] : [];
        if ($transactions !== [] && function_exists('wc_get_orders')) {
            foreach ($transactions as $tx) {
                foreach (wc_get_orders(['transaction_id' => $tx, 'limit' => 5, 'return' => 'ids', 'type' => 'shop_order']) as $id) {
                    $orderIds[] = (int) $id;
                }
            }
        }
        $orderIds = array_values(array_unique(array_filter($orderIds)));
        if ($orderIds === [] || ! Integration::active()) {
            AuditLog::record('dispute_unmatched', 'webhook', $source, $reference, ['event' => $eventId, 'action' => $action]);

            return ['matched' => false];
        }
        $reason = sprintf('%s-Konflikt %s%s', $source === 'paypal' ? 'PayPal' : ucfirst($source), ['opened' => 'eröffnet', 'won' => 'gewonnen', 'lost' => 'verloren'][$action], $reference !== '' ? ' ('.$reference.')' : '');
        $sync = Integration::sync();
        foreach ($orderIds as $id) {
            match ($action) {
                'opened' => $sync->suspendOrder($id, $reason),
                'won' => $sync->releaseOrder($id, $reason),
                'lost' => $sync->revokeOrder($id, $reason),
            };
            if (($order = wc_get_order($id)) instanceof \WC_Order) {
                $order->add_order_note(__('Lernzugang:', 'bootspruefung-learning').' '.$reason);
            }
            AuditLog::record('dispute_'.$action, 'order', (string) $id, $reason, ['source' => $source, 'event' => $eventId]);
        }
        set_transient($key, 1, 90 * DAY_IN_SECONDS);

        return ['matched' => true, 'orders' => $orderIds, 'action' => $action];
    }

    public function saveSettings(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_dispute_settings');
        $current = self::settings();
        $secret = trim(sanitize_text_field(wp_unslash($_POST['paypal_secret'] ?? '')));
        $new = [
            'paypal_client_id' => trim(sanitize_text_field(wp_unslash($_POST['paypal_client_id'] ?? ''))),
            // Leeres Feld = gespeichertes Secret behalten (wird nie angezeigt).
            'paypal_secret' => $secret !== '' ? $secret : $current['paypal_secret'],
            'paypal_webhook_id' => trim(sanitize_text_field(wp_unslash($_POST['paypal_webhook_id'] ?? ''))),
            'paypal_mode' => ($_POST['paypal_mode'] ?? '') === 'sandbox' ? 'sandbox' : 'live',
            'generic_secret' => ! empty($_POST['regenerate_secret']) || $current['generic_secret'] === '' ? wp_generate_password(40, false, false) : $current['generic_secret'],
        ];
        if (! empty($_POST['clear_paypal'])) {
            $new['paypal_client_id'] = $new['paypal_secret'] = $new['paypal_webhook_id'] = '';
        }
        update_option(self::OPTION, $new, false);
        AuditLog::record('dispute_settings_saved', 'settings', null, null, ['paypal' => $new['paypal_webhook_id'] !== '', 'mode' => $new['paypal_mode']]);
        wp_safe_redirect(admin_url('admin.php?page=bootspruefung-settings&saved=disputes'));
        exit;
    }

    public static function renderSettings(): void
    {
        $s = self::settings();
        echo '<h2>'.esc_html__('Zahlungskonflikte (Disputes)', 'bootspruefung-learning').'</h2>';
        echo '<p>'.esc_html__('Stripe meldet Disputes als „In Wartestellung“ – das erkennt das Plugin ohne Einrichtung. Für PayPal und Klarna hier die Benachrichtigungen einrichten; ein eröffneter Konflikt setzt den Zugang aus, ein gewonnener hebt das auf, ein verlorener widerruft.', 'bootspruefung-learning').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bp_dispute_settings');
        echo '<input type="hidden" name="action" value="bp_dispute_settings"><table class="form-table"><tbody>';
        printf('<tr><th>%s</th><td><code>%s</code><p class="description">%s</p></td></tr>', esc_html__('PayPal-Webhook-URL', 'bootspruefung-learning'), esc_html(rest_url(RestRegistrar::NAMESPACE.'/webhooks/paypal')),
            esc_html__('Im PayPal-Developer-Dashboard bei der App als Webhook eintragen, Ereignisse: Customer dispute created, updated, resolved.', 'bootspruefung-learning'));
        printf('<tr><th><label for="bp-pp-client">%s</label></th><td><input id="bp-pp-client" class="regular-text" name="paypal_client_id" value="%s"></td></tr>', esc_html__('PayPal Client-ID', 'bootspruefung-learning'), esc_attr($s['paypal_client_id']));
        printf('<tr><th><label for="bp-pp-secret">%s</label></th><td><input id="bp-pp-secret" type="password" class="regular-text" name="paypal_secret" autocomplete="new-password" placeholder="%s"></td></tr>', esc_html__('PayPal Secret', 'bootspruefung-learning'), esc_attr($s['paypal_secret'] !== '' ? __('gespeichert – leer lassen zum Behalten', 'bootspruefung-learning') : ''));
        printf('<tr><th><label for="bp-pp-webhook">%s</label></th><td><input id="bp-pp-webhook" class="regular-text" name="paypal_webhook_id" value="%s"></td></tr>', esc_html__('PayPal Webhook-ID', 'bootspruefung-learning'), esc_attr($s['paypal_webhook_id']));
        printf('<tr><th>%s</th><td><label><input type="radio" name="paypal_mode" value="live" %s> Live</label> <label><input type="radio" name="paypal_mode" value="sandbox" %s> Sandbox</label> <label style="margin-left:16px"><input type="checkbox" name="clear_paypal" value="1"> %s</label></td></tr>',
            esc_html__('Umgebung', 'bootspruefung-learning'), checked($s['paypal_mode'], 'live', false), checked($s['paypal_mode'], 'sandbox', false), esc_html__('PayPal-Zugangsdaten löschen', 'bootspruefung-learning'));
        printf('<tr><th>%s</th><td><code>%s</code><p class="description">%s</p>%s</td></tr>', esc_html__('Allgemeiner Dispute-Webhook (Klarna u. a.)', 'bootspruefung-learning'), esc_html(rest_url(RestRegistrar::NAMESPACE.'/webhooks/dispute')),
            esc_html__('POST mit JSON {"event":"opened|won|lost","order_id":123 oder "transaction_id":"…","id":"eindeutige Ereignis-ID","source":"klarna"} und Header X-Bootspruefung-Signature: sha256=HMAC-SHA256(Rohtext, Schlüssel). Geeignet für Benachrichtigungen aus dem Klarna-Händlerportal über eine Automatisierung (z. B. E-Mail-/Webhook-Weiterleitung).', 'bootspruefung-learning'),
            $s['generic_secret'] !== '' ? '<p>'.esc_html__('Schlüssel:', 'bootspruefung-learning').' <code>'.esc_html($s['generic_secret']).'</code> <label><input type="checkbox" name="regenerate_secret" value="1"> '.esc_html__('neu erzeugen', 'bootspruefung-learning').'</label></p>' : '<p>'.esc_html__('Der Schlüssel wird beim Speichern erzeugt.', 'bootspruefung-learning').'</p>');
        echo '</tbody></table>';
        submit_button(__('Konflikt-Einstellungen speichern', 'bootspruefung-learning'));
        echo '</form>';
    }
}
