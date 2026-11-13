<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\Disputes;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Infrastructure\Lock;
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
 *
 * Reihenfolge: Webhooks kommen verspätet, doppelt oder vertauscht an. Je Bestellung und
 * Konfliktfall (PayPal: dispute_id; allgemein: dispute_id bzw. Quelle) steht der Stand mit
 * Ereigniszeit in der Bestellung (META_STATE). Ein abgeschlossener Fall (gewonnen/verloren)
 * ist endgültig: Ein später zugestelltes „eröffnet“ öffnet ihn nicht wieder; zwischen zwei
 * Ergebnissen gilt das jüngere. Verarbeitete Ereignis-IDs stehen ebenfalls in der Bestellung
 * (nicht nur im Transient), Wiederholungen sind damit dauerhaft wirkungslos.
 */
final class DisputeWebhooks
{
    public const OPTION = 'bp_learning_disputes';

    /** Order-Meta: Stand je Konfliktfall [Fall => [status, time, event]]. */
    public const META_STATE = '_bp_dispute_state';

    /** Order-Meta: bereits verarbeitete Ereignisse (Schlüssel, höchstens MAX_EVENTS). */
    public const META_EVENTS = '_bp_dispute_events';

    private const MAX_EVENTS = 100;

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_post_bp_dispute_settings', [$this, 'saveSettings']);
    }

    /** @return array{paypal_client_id:string,paypal_secret:string,paypal_webhook_id:string,paypal_mode:string,generic_secret:string,generic_strict:bool} */
    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(['paypal_client_id' => '', 'paypal_secret' => '', 'paypal_webhook_id' => '', 'paypal_mode' => 'live', 'generic_secret' => '', 'generic_strict' => false], is_array($stored) ? $stored : []);
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
            self::logRejected('paypal', ['event' => mb_substr((string) $event['id'], 0, 64)]);

            return new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401]);
        }
        $action = self::paypalAction($event);
        if ($action === null) {
            return new WP_REST_Response(['ignored' => true], 200);
        }
        $transactions = array_filter(array_map(static fn ($t) => (string) ($t['seller_transaction_id'] ?? ''), (array) ($event['resource']['disputed_transactions'] ?? [])));

        $disputeId = (string) ($event['resource']['dispute_id'] ?? '');
        $time = self::eventTime($event['resource']['update_time'] ?? $event['create_time'] ?? null);

        return self::respond($this->handle('paypal', (string) $event['id'], $action, null, $transactions, $disputeId, $time, $disputeId !== '' ? $disputeId : null));
    }

    /** @param array<string,mixed> $result */
    private static function respond(array $result): WP_REST_Response
    {
        // Gleichzeitiges Ereignis derselben Bestellung: 409, das Gateway stellt erneut zu.
        return new WP_REST_Response($result, ! empty($result['retry']) ? 409 : 200);
    }

    /** Ereigniszeit aus ISO 8601 oder Unix-Zeit; null = unbekannt (dann Zeitpunkt des Eingangs). */
    public static function eventTime(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts !== false ? $ts : null;
        }

        return null;
    }

    /**
     * Ordnet ein PayPal-Ereignis einer Aktion zu.
     *
     * @param array<string,mixed> $event
     */
    public static function paypalAction(array $event): ?string
    {
        $type = (string) $event['event_type'];
        // UPDATED meldet auch die Klärung (resource.status = RESOLVED) – dann wie RESOLVED auswerten.
        if ($type === 'CUSTOMER.DISPUTE.UPDATED' && strtoupper((string) ($event['resource']['status'] ?? '')) === 'RESOLVED') {
            $type = 'CUSTOMER.DISPUTE.RESOLVED';
        }
        if (in_array($type, ['CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.UPDATED'], true)) {
            return 'opened';
        }
        if ($type === 'CUSTOMER.DISPUTE.RESOLVED') {
            $outcome = (string) ($event['resource']['dispute_outcome']['outcome_code'] ?? '');

            return in_array($outcome, ['RESOLVED_BUYER_FAVOUR', 'ACCEPTED'], true) ? 'lost' : 'won';
        }

        return null;
    }

    /** Abgewiesene Webhooks höchstens einmal je 10 Minuten und Quelle protokollieren (Protokoll nicht flutbar). */
    private static function logRejected(string $source, array $context): void
    {
        $key = 'bp_webhook_rejected_'.$source;
        $count = (int) get_transient($key);
        if ($count === 0) {
            AuditLog::record('dispute_webhook_rejected', 'webhook', $source, 'signature', $context);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
    }

    /** @param array<string,mixed> $event @param array<string,string> $settings */
    private function verifyPaypal(WP_REST_Request $request, array $event, array $settings): bool
    {
        /** Filter: Prüfergebnis vorgeben (nur für Tests); null = echte Prüfung bei PayPal. */
        $forced = apply_filters('bootspruefung_paypal_verify', null, $event);
        if (is_bool($forced)) {
            return $forced;
        }
        // Ohne PayPal-Signaturkopfzeilen gar nicht erst bei PayPal anfragen; Prüfungen gedrosselt (Schutz vor Flut).
        foreach (['paypal_auth_algo', 'paypal_cert_url', 'paypal_transmission_id', 'paypal_transmission_sig', 'paypal_transmission_time'] as $header) {
            if ((string) $request->get_header($header) === '') {
                return false;
            }
        }
        $calls = (int) get_transient('bp_paypal_verify_calls');
        if ($calls >= 60) {
            return false;
        }
        set_transient('bp_paypal_verify_calls', $calls + 1, MINUTE_IN_SECONDS);
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
            self::logRejected('generic', []);

            return new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401]);
        }
        $data = json_decode($body, true);
        $action = is_array($data) ? (string) ($data['event'] ?? '') : '';
        if (! in_array($action, ['opened', 'won', 'lost'], true) || (empty($data['order_id']) && empty($data['transaction_id']))) {
            return new WP_Error('bp_invalid', 'Expected event (opened|won|lost) and order_id or transaction_id.', ['status' => 400]);
        }
        // Pflichtfelder (Einstellung, To-do T39): ohne dispute_id gälte ein Fall je Quelle und Bestellung – ein zweiter
        // Konflikt nach einem abgeschlossenen ginge verloren; ohne timestamp zählt die Eingangszeit.
        if (! empty(self::settings()['generic_strict']) && (trim((string) ($data['dispute_id'] ?? '')) === '' || self::eventTime($data['timestamp'] ?? $data['occurred_at'] ?? null) === null)) {
            return new WP_Error('bp_invalid', 'Expected dispute_id and timestamp.', ['status' => 400]);
        }
        // Ohne ID: Hash über den normalisierten Inhalt (Leerzeichen oder Schlüsselreihenfolge ändern nichts).
        $eventId = (string) ($data['id'] ?? hash('sha256', (string) wp_json_encode(self::normalize($data))));
        $disputeId = (string) ($data['dispute_id'] ?? '');

        return self::respond($this->handle((string) ($data['source'] ?? 'generic'), $eventId, $action, empty($data['order_id']) ? null : (int) $data['order_id'],
            empty($data['transaction_id']) ? [] : [(string) $data['transaction_id']], (string) ($data['reason'] ?? ''), self::eventTime($data['timestamp'] ?? $data['occurred_at'] ?? null), $disputeId !== '' ? $disputeId : null));
    }

    /** @param array<mixed> $data @return array<mixed> rekursiv nach Schlüsseln sortiert */
    private static function normalize(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::normalize($v);
            }
        }

        return $data;
    }

    // ------------------------------------------------------------------ gemeinsam

    /**
     * @param list<string> $transactions
     * @param int|null     $eventTime Zeitpunkt des Ereignisses beim Gateway (Unix-Zeit), null = Eingang
     * @param string|null  $caseId    Konfliktfall (z. B. PayPal-dispute_id), null = ein Fall je Quelle
     * @return array<string,mixed>
     */
    public function handle(string $source, string $eventId, string $action, ?int $orderId, array $transactions, string $reference, ?int $eventTime = null, ?string $caseId = null): array
    {
        $eventKey = md5($source.'|'.$eventId);
        $key = 'bp_dispute_'.$eventKey;
        if (get_transient($key)) {
            return ['duplicate' => true];
        }
        $orderIds = $orderId ? [$orderId] : [];
        if ($transactions !== [] && function_exists('wc_get_orders')) {
            foreach ($transactions as $tx) {
                foreach (wc_get_orders(['transaction_id' => $tx, 'limit' => 5, 'return' => 'ids', 'type' => 'shop_order']) as $id) {
                    // Nur exakte Treffer übernehmen (Schutz, falls der Filter nicht greift).
                    $order = wc_get_order((int) $id);
                    if ($order instanceof \WC_Order && $tx !== '' && $order->get_transaction_id() === $tx) {
                        $orderIds[] = (int) $id;
                    }
                }
            }
        }
        $orderIds = array_values(array_unique(array_filter($orderIds)));
        if ($orderIds === [] || ! Integration::active()) {
            AuditLog::record('dispute_unmatched', 'webhook', $source, $reference, ['event' => $eventId, 'action' => $action]);

            return ['matched' => false];
        }
        $eventTime ??= time();
        $case = $source.'|'.($caseId ?? '');
        $labels = ['opened' => 'eröffnet', 'won' => 'gewonnen', 'lost' => 'verloren'];
        $reason = sprintf('%s-Konflikt %s%s', $source === 'paypal' ? 'PayPal' : ucfirst($source), $labels[$action], $reference !== '' ? ' ('.$reference.')' : '');
        $sync = Integration::sync();
        $applied = [];
        $ignored = [];
        $duplicates = 0;
        foreach ($orderIds as $id) {
            // Ereignisse derselben Bestellung nacheinander auswerten (gleichzeitige Zustellung).
            if (! Lock::acquire('bp_dispute_lock_'.$id, MINUTE_IN_SECONDS)) {
                return ['retry' => true];
            }
            try {
                $order = wc_get_order($id);
                if (! $order instanceof \WC_Order) {
                    continue;
                }
                $events = is_array($stored = $order->get_meta(self::META_EVENTS)) ? $stored : [];
                if (in_array($eventKey, $events, true)) {
                    $duplicates++;
                    continue;
                }
                $states = is_array($stored = $order->get_meta(self::META_STATE)) ? $stored : [];
                $decision = self::decide(is_array($states[$case] ?? null) ? $states[$case] : null, $action, $eventTime);
                if ($decision === 'apply') {
                    match ($action) {
                        'opened' => $sync->suspendOrder($id, $reason),
                        'won' => $sync->releaseOrder($id, $reason),
                        'lost' => $sync->revokeOrder($id, $reason),
                    };
                    $states[$case] = ['status' => $action, 'time' => $eventTime, 'event' => mb_substr($eventId, 0, 64)];
                    $order = wc_get_order($id); // nach dem Abgleich neu laden (Notizen, Meta)
                    $order->add_order_note(__('Lernzugang:', 'bootspruefung-learning').' '.$reason);
                    AuditLog::record('dispute_'.$action, 'order', (string) $id, $reason, ['source' => $source, 'event' => $eventId, 'event_time' => gmdate('c', $eventTime)]);
                    $applied[] = $id;
                } else {
                    $previous = (string) ($states[$case]['status'] ?? '');
                    if ($decision === 'ignore') {
                        // Abgeschlossener Fall bzw. veraltetes Ergebnis: Zugang bleibt, für den Betreiber sichtbar vermerken.
                        $order->add_order_note(sprintf(
                            /* translators: 1: Ereignis, z. B. „PayPal-Konflikt eröffnet“, 2: Stand des Falls, z. B. „gewonnen“ */
                            __('Lernzugang: %1$s nicht angewendet – der Konfliktfall ist bereits %2$s (neuerer Stand).', 'bootspruefung-learning'),
                            $reason,
                            $labels[$previous] ?? $previous
                        ));
                        AuditLog::record('dispute_ignored', 'order', (string) $id, $reason, ['source' => $source, 'event' => $eventId, 'case_status' => $previous, 'event_time' => gmdate('c', $eventTime)]);
                    }
                    $ignored[] = $id;
                }
                $events[] = $eventKey;
                $order->update_meta_data(self::META_EVENTS, array_slice(array_values(array_unique($events)), -self::MAX_EVENTS));
                $order->update_meta_data(self::META_STATE, $states);
                $order->save();
            } finally {
                Lock::release('bp_dispute_lock_'.$id);
            }
        }
        set_transient($key, 1, 90 * DAY_IN_SECONDS);
        if ($applied === [] && $ignored === [] && $duplicates > 0) {
            return ['duplicate' => true];
        }

        return ['matched' => true, 'orders' => $orderIds, 'action' => $action] + ($ignored !== [] ? ['ignored' => $ignored] : []);
    }

    /**
     * Entscheidet über ein Ereignis anhand des bisherigen Stands des Konfliktfalls.
     *
     * @param array{status?:string,time?:int}|null $case
     * @return string apply | ignore (abgeschlossen bzw. veraltet) | noop (Fall bereits eröffnet bzw. gleiches Ergebnis)
     */
    public static function decide(?array $case, string $action, int $eventTime): string
    {
        $status = (string) ($case['status'] ?? '');
        $time = (int) ($case['time'] ?? 0);
        $closed = in_array($status, ['won', 'lost'], true);
        if ($action === 'opened') {
            // Ein abgeschlossener Fall ist endgültig – auch ein später zugestelltes „eröffnet“ öffnet ihn nicht.
            return $closed ? 'ignore' : ($status === 'opened' ? 'noop' : 'apply');
        }
        // Ergebnis: schließt einen offenen Fall immer; gegenüber einem anderen Ergebnis gilt das jüngere.
        if ($closed && $eventTime < $time) {
            return 'ignore';
        }

        return $closed && $status === $action ? 'noop' : 'apply';
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
            'generic_strict' => ! empty($_POST['generic_strict']),
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
            esc_html__('POST mit JSON {"event":"opened|won|lost","order_id":123 oder "transaction_id":"…","id":"eindeutige Ereignis-ID","source":"klarna","dispute_id":"Fall-ID","timestamp":"2026-11-12T10:00:00Z"} und Header X-Bootspruefung-Signature: sha256=HMAC-SHA256(Rohtext, Schlüssel). dispute_id und timestamp sind optional, aber empfohlen: Ein gewonnener oder verlorener Fall ist endgültig, ein später eintreffendes „opened“ desselben Falls wird ignoriert. Geeignet für Benachrichtigungen aus dem Klarna-Händlerportal über eine Automatisierung (z. B. E-Mail-/Webhook-Weiterleitung).', 'bootspruefung-learning'),
            $s['generic_secret'] !== '' ? '<p>'.esc_html__('Schlüssel:', 'bootspruefung-learning').' <code>'.esc_html($s['generic_secret']).'</code> <label><input type="checkbox" name="regenerate_secret" value="1"> '.esc_html__('neu erzeugen', 'bootspruefung-learning').'</label></p>' : '<p>'.esc_html__('Der Schlüssel wird beim Speichern erzeugt.', 'bootspruefung-learning').'</p>');
        printf('<tr><th>%s</th><td><label><input type="checkbox" name="generic_strict" value="1" %s> %s</label><p class="description">%s</p></td></tr>', esc_html__('Pflichtfelder', 'bootspruefung-learning'), checked(! empty($s['generic_strict']), true, false),
            esc_html__('dispute_id und timestamp verlangen (sonst 400)', 'bootspruefung-learning'),
            esc_html__('Empfohlen, sobald die Automatisierung beide Felder mitschickt. Ohne dispute_id gilt ein Fall je Quelle und Bestellung: Ein zweiter Konflikt zur selben Bestellung nach einem abgeschlossenen würde nicht erkannt. Ohne timestamp zählt die Eingangszeit, verspätete Nachrichten können dann falsch einsortiert werden.', 'bootspruefung-learning'));
        echo '</tbody></table>';
        submit_button(__('Konflikt-Einstellungen speichern', 'bootspruefung-learning'));
        echo '</form>';
    }
}
