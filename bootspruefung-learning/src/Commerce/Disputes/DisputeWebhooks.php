<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\Disputes;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Commerce\WooCommerce\Integration;
use Bootspruefung\Learning\Infrastructure\Lock;
use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\RateLimiter;
use Bootspruefung\Learning\Rest\RestRegistrar;
use Bootspruefung\Learning\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Automatische Behandlung von Zahlungskonflikten (Kap. 7.5) für Gateways, die
 * Disputes nicht als WooCommerce-Status abbilden:
 *
 * - PayPal: Webhook /wp-json/bootspruefung/v1/webhooks/paypal für
 *   CUSTOMER.DISPUTE.CREATED/UPDATED/RESOLVED. Jede Nachricht wird über die
 *   PayPal-API (POST /v1/notifications/verify-webhook-signature) geprüft; die
 *   Bestellung wird über die Transaktions-ID (Capture-ID) gefunden. Zugangsdaten
 *   aus den Einstellungen oder aus wp-config.php (Konstanten BOOTSPRUEFUNG_PAYPAL_*),
 *   nie im Code. Ohne Zugangsdaten nimmt der Webhook nichts an (503); Einstellungen
 *   und Systemstatus zeigen dann „Signatur nicht geprüft“ (T64).
 *   Prüfung abgelehnt (FAILURE, fehlende Kopfzeilen) → 401; PayPal nicht erreichbar
 *   bzw. kein Token → 503 (PayPal stellt erneut zu). In beiden Fällen wird nichts
 *   verarbeitet; Audit-Log (gedrosselt) und PHP-Fehlerprotokoll.
 * - Klarna und alle anderen: gateway-neutraler Webhook
 *   /wp-json/bootspruefung/v1/webhooks/dispute mit HMAC-SHA256-Signatur
 *   (Header X-Bootspruefung-Signature: sha256=<hex>) über den Rohtext, z. B. für
 *   Benachrichtigungen aus dem Klarna-Händlerportal über eine Automatisierung.
 * - Klarna von Hand (T65): Bestellaktion „Klarna-Konflikt melden“ in der Bestellung;
 *   sie nimmt denselben Weg wie der allgemeine Webhook (handle()).
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

    /** PayPal-Webhook: größte angenommene Nachricht in Bytes (Konflikt-Ereignisse sind wenige KB groß). */
    public const PAYPAL_MAX_BODY = 262144;
    /** PayPal-Webhook: Anfragen je IP und Minute vor der Signaturprüfung (Filter bootspruefung_paypal_webhook_per_ip). */
    public const PAYPAL_PER_IP = 60;
    /** Kopfzeilen, die PayPal jeder Webhook-Nachricht mitgibt (für die Signaturprüfung nötig). */
    public const PAYPAL_HEADERS = ['paypal_transmission_id', 'paypal_transmission_time', 'paypal_transmission_sig', 'paypal_cert_url', 'paypal_auth_algo'];

    /** Option: Ergebnis der letzten Prüfung bei PayPal [result, time, mode] – für Einstellungen und Systemstatus. */
    public const OPTION_LAST_VERIFY = 'bp_learning_paypal_verify_last';

    /** Konstanten in wp-config.php (Vorrang vor den Einstellungen) => Schlüssel in settings(). */
    public const CONSTANTS = [
        'BOOTSPRUEFUNG_PAYPAL_CLIENT_ID' => 'paypal_client_id',
        'BOOTSPRUEFUNG_PAYPAL_SECRET' => 'paypal_secret',
        'BOOTSPRUEFUNG_PAYPAL_WEBHOOK_ID' => 'paypal_webhook_id',
        'BOOTSPRUEFUNG_PAYPAL_MODE' => 'paypal_mode',
    ];

    /** Bestellaktionen „Klarna-Konflikt melden“ (T65): Aktion => Ereignis wie beim allgemeinen Webhook. */
    public const KLARNA_ACTIONS = ['bp_dispute_klarna_opened' => 'opened', 'bp_dispute_klarna_won' => 'won', 'bp_dispute_klarna_lost' => 'lost'];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_post_bp_dispute_settings', [$this, 'saveSettings']);
        // Klarna liefert WooCommerce keine Konflikt-Benachrichtigungen: Meldung von Hand in der Bestellung (T65).
        add_filter('woocommerce_order_actions', [$this, 'klarnaOrderActions'], 20, 2);
        add_action('woocommerce_order_actions_end', [$this, 'klarnaCaseField'], 20);
        foreach (self::KLARNA_ACTIONS as $hook => $event) {
            add_action('woocommerce_order_action_'.$hook, fn ($order) => $this->klarnaOrderAction($event, $order));
        }
    }

    /** @return array{paypal_client_id:string,paypal_secret:string,paypal_webhook_id:string,paypal_mode:string,generic_secret:string,generic_strict:bool} */
    public static function settings(): array
    {
        $settings = self::storedSettings();
        foreach (self::constantValues() as $key => $value) {
            $settings[$key] = $value;
        }
        $settings['paypal_mode'] = $settings['paypal_mode'] === 'sandbox' ? 'sandbox' : 'live';

        return $settings;
    }

    /** @return array<string,mixed> nur die gespeicherte Option (ohne Konstanten aus wp-config.php) */
    private static function storedSettings(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(['paypal_client_id' => '', 'paypal_secret' => '', 'paypal_webhook_id' => '', 'paypal_mode' => 'live', 'generic_secret' => '', 'generic_strict' => false], is_array($stored) ? $stored : []);
    }

    /** @return array<string,string> in wp-config.php gesetzte PayPal-Werte (nicht leer) */
    public static function constantValues(): array
    {
        $out = [];
        foreach (self::CONSTANTS as $constant => $key) {
            if (defined($constant) && is_scalar(constant($constant)) && trim((string) constant($constant)) !== '') {
                $out[$key] = trim((string) constant($constant));
            }
        }

        return $out;
    }

    /**
     * Stand der PayPal-Signaturprüfung für Einstellungen und Systemstatus (ohne Geheimnisse).
     *
     * @return array{verified:bool,missing:list<string>,mode:string,constants:bool,last:array{result:string,time:int,mode:string}|null}
     */
    public static function paypalStatus(): array
    {
        $s = self::settings();
        $missing = [];
        foreach (['paypal_client_id' => 'Client-ID', 'paypal_secret' => 'Secret', 'paypal_webhook_id' => 'Webhook-ID'] as $key => $label) {
            if ($s[$key] === '') {
                $missing[] = $label;
            }
        }
        $last = get_option(self::OPTION_LAST_VERIFY, null);

        return [
            'verified' => $missing === [],
            'missing' => $missing,
            'mode' => $s['paypal_mode'],
            'constants' => self::constantValues() !== [],
            'last' => is_array($last) && isset($last['result'], $last['time']) ? ['result' => (string) $last['result'], 'time' => (int) $last['time'], 'mode' => (string) ($last['mode'] ?? '')] : null,
        ];
    }

    /**
     * Kurztext zum Stand der Signaturprüfung, z. B. „Signatur nicht geprüft – es fehlt: Webhook-ID …“.
     *
     * @param array<string,mixed>|null $status Ergebnis von paypalStatus(); null = aktuell ermitteln
     */
    public static function paypalStatusText(?array $status = null): string
    {
        $status ??= self::paypalStatus();
        if (! $status['verified']) {
            return sprintf(
                /* translators: %s: fehlende Angaben, z. B. „Client-ID, Webhook-ID“ */
                __('Signatur nicht geprüft – es fehlt: %s. Der PayPal-Webhook nimmt bis dahin keine Nachrichten an (Antwort 503). PayPal-Konflikte bitte von Hand mit „Lernzugang aussetzen“ behandeln.', 'bootspruefung-learning'),
                implode(', ', $status['missing'])
            );
        }
        $text = sprintf(
            /* translators: %s: Live oder Sandbox */
            __('Signatur wird bei PayPal geprüft (%s).', 'bootspruefung-learning'),
            $status['mode'] === 'sandbox' ? 'Sandbox' : 'Live'
        );
        if ($status['last'] === null) {
            return $text.' '.__('Noch keine Nachricht geprüft. Zum Test in der PayPal-Sandbox einen Konflikt auslösen.', 'bootspruefung-learning');
        }
        $results = [
            'success' => __('erfolgreich', 'bootspruefung-learning'),
            'failure' => __('abgelehnt (FAILURE)', 'bootspruefung-learning'),
            'error' => __('PayPal nicht erreichbar oder Zugangsdaten falsch', 'bootspruefung-learning'),
        ];

        return $text.' '.sprintf(
            /* translators: 1: Datum und Uhrzeit, 2: Ergebnis, 3: Live oder Sandbox */
            __('Letzte Prüfung: %1$s – %2$s (%3$s).', 'bootspruefung-learning'),
            wp_date(get_option('date_format').' '.get_option('time_format'), $status['last']['time']),
            $results[$status['last']['result']] ?? $status['last']['result'],
            $status['last']['mode'] === 'sandbox' ? 'Sandbox' : 'Live'
        );
    }

    public function routes(): void
    {
        register_rest_route(RestRegistrar::NAMESPACE, '/webhooks/paypal', ['methods' => 'POST', 'callback' => [$this, 'paypal'], 'permission_callback' => '__return_true']);
        register_rest_route(RestRegistrar::NAMESPACE, '/webhooks/dispute', ['methods' => 'POST', 'callback' => [$this, 'generic'], 'permission_callback' => '__return_true']);
    }

    /** @param list<string> $prefixes Ist eine Zahlungsart aktiv, deren ID so beginnt (z. B. „ppcp“, „kco“; „klarna“ auch mittendrin)? */
    private static function gatewayEnabled(array $prefixes): bool
    {
        foreach (\Bootspruefung\Learning\Commerce\WooCommerce\Gateways::enabled() as $g) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $g['id'], $prefix) || ($prefix === 'klarna' && str_contains((string) $g['id'], 'klarna'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Abschnitt „Zahlungskonflikte“ im Systemstatus (T64/T65). */
    public static function renderStatus(): void
    {
        $paypal = self::paypalStatus();
        $paypalActive = self::gatewayEnabled(['ppcp', 'paypal']);
        echo '<h2>'.esc_html__('Zahlungskonflikte', 'bootspruefung-learning').'</h2>';
        if (! $paypal['verified'] && $paypalActive) {
            echo '<div class="notice notice-error inline"><p><strong>'.esc_html__('PayPal: Signatur nicht geprüft.', 'bootspruefung-learning').'</strong> '
                .esc_html__('PayPal ist als Zahlungsart aktiv, aber die Zugangsdaten für die Signaturprüfung fehlen. Konflikte kommen so nicht automatisch an. Einrichten unter Bootsprüfung › Einstellungen › Zahlungskonflikte.', 'bootspruefung-learning').'</p></div>';
        }
        $rows = [
            __('PayPal-Webhook', 'bootspruefung-learning') => self::paypalStatusText($paypal),
            __('Allgemeiner Dispute-Webhook', 'bootspruefung-learning') => self::settings()['generic_secret'] !== ''
                ? __('eingerichtet (Signatur mit Schlüssel, HMAC-SHA256)', 'bootspruefung-learning')
                : __('nicht eingerichtet – nimmt nichts an (Antwort 503)', 'bootspruefung-learning'),
            __('Klarna', 'bootspruefung-learning') => __('Klarna meldet Konflikte nicht an WooCommerce. Weiterleitung an den allgemeinen Webhook oder von Hand in der Bestellung: Aktion „Klarna-Konflikt melden“.', 'bootspruefung-learning')
                .(self::gatewayEnabled(['klarna', 'kco']) ? '' : ' '.__('(Klarna ist derzeit nicht als Zahlungsart aktiv.)', 'bootspruefung-learning')),
        ];
        echo '<table class="widefat striped" style="max-width:720px"><tbody>';
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------ PayPal

    public function paypal(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $rejected = self::prefilterPaypal($request);
        if ($rejected !== null) {
            return $rejected;
        }
        $settings = self::settings();
        if ($settings['paypal_webhook_id'] === '' || $settings['paypal_client_id'] === '' || $settings['paypal_secret'] === '') {
            // Wie bisher: ohne Zugangsdaten keine Prüfung möglich, also nichts annehmen (Einstellungen/Systemstatus warnen).
            self::logRejected('paypal_unconfigured', [], 'not_configured');

            return new WP_Error('bp_not_configured', 'PayPal webhook not configured.', ['status' => 503]);
        }
        $body = (string) $request->get_body();
        $event = json_decode($body, true);
        if (! is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return new WP_Error('bp_invalid', 'Invalid payload.', ['status' => 400]);
        }
        $verified = $this->verifyPaypal($request, $event, $body, $settings);
        if ($verified !== 'success') {
            $context = ['event' => mb_substr((string) $event['id'], 0, 64), 'result' => $verified, 'mode' => $settings['paypal_mode']];
            self::logRejected('paypal', $context);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- abgewiesene Webhooks gehören ins Fehlerprotokoll.
            error_log(sprintf('[bootspruefung] PayPal-Webhook %s abgewiesen: Signaturprüfung %s (%s).', $context['event'], $verified, $settings['paypal_mode']));

            // Abgelehnt: 401. PayPal nicht erreichbar bzw. kein Token: 503 – PayPal stellt die Nachricht später erneut zu.
            return $verified === 'error'
                ? new WP_Error('bp_verify_unavailable', 'Signature could not be verified.', ['status' => 503])
                : new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401]);
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

    /**
     * Vorfilter vor JSON-Auswertung und Signaturprüfung (T88): Offensichtlich ungültige Anfragen kosten so keine
     * Anfrage bei PayPal und keinen Datenbankzugriff auf Bestellungen. Geprüft werden Limit je IP (IPv6 je /64),
     * Methode, Content-Type application/json, Größe und die PAYPAL-Kopfzeilen samt Zertifikatsadresse bei PayPal.
     */
    public static function prefilterPaypal(WP_REST_Request $request): ?WP_Error
    {
        try {
            RateLimiter::hit('webhook-paypal-ip|'.RateLimiter::clientKey(), max(1, (int) apply_filters('bootspruefung_paypal_webhook_per_ip', self::PAYPAL_PER_IP)), MINUTE_IN_SECONDS);
        } catch (ApiException) {
            return new WP_Error('bp_rate_limited', 'Too many requests.', ['status' => 429]);
        }
        $reason = null;
        $status = 400;
        $body = (string) $request->get_body();
        $type = strtolower(trim(explode(';', (string) $request->get_header('content_type'))[0]));
        if (strtoupper($request->get_method()) !== 'POST') {
            [$reason, $status] = ['method', 405];
        } elseif ($type !== 'application/json') {
            [$reason, $status] = ['content_type', 415];
        } elseif ($body === '') {
            $reason = 'empty';
        } elseif (strlen($body) > self::PAYPAL_MAX_BODY) {
            [$reason, $status] = ['too_large', 413];
        } else {
            foreach (self::PAYPAL_HEADERS as $header) {
                if (trim((string) $request->get_header($header)) === '') {
                    [$reason, $status] = ['headers', 401];
                    break;
                }
            }
            if ($reason === null) {
                $cert = wp_parse_url(trim((string) $request->get_header('paypal_cert_url')));
                $host = strtolower((string) ($cert['host'] ?? ''));
                if (($cert['scheme'] ?? '') !== 'https' || ! str_ends_with('.'.$host, '.paypal.com')) {
                    [$reason, $status] = ['cert_url', 401];
                }
            }
        }
        if ($reason === null) {
            return null;
        }
        self::logRejected('paypal_prefilter', ['reason' => $reason], 'prefilter');

        return $status === 401
            ? new WP_Error('bp_signature', 'Signature verification failed.', ['status' => 401])
            : new WP_Error('bp_invalid', 'Invalid request.', ['status' => $status]);
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
    private static function logRejected(string $source, array $context, string $reason = 'signature'): void
    {
        $key = 'bp_webhook_rejected_'.$source;
        $count = (int) get_transient($key);
        if ($count === 0) {
            AuditLog::record('dispute_webhook_rejected', 'webhook', $source, $reason, $context);
        }
        set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
    }

    /**
     * Prüft die Signatur über die PayPal-API (POST /v1/notifications/verify-webhook-signature).
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $settings
     * @return string success | failure (abgelehnt, fehlende Kopfzeilen) | error (PayPal nicht erreichbar, kein Token, gedrosselt)
     */
    private function verifyPaypal(WP_REST_Request $request, array $event, string $body, array $settings): string
    {
        /** Filter: Prüfergebnis vorgeben (nur für Tests); null = echte Prüfung bei PayPal. */
        $forced = apply_filters('bootspruefung_paypal_verify', null, $event);
        if (is_bool($forced)) {
            return $forced ? 'success' : 'failure';
        }
        // Ohne PayPal-Signaturkopfzeilen gar nicht erst bei PayPal anfragen; Prüfungen gedrosselt (Schutz vor Flut).
        $fields = [];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $field) {
            $fields[$field] = trim((string) $request->get_header('paypal_'.$field));
            if ($fields[$field] === '') {
                return 'failure';
            }
        }
        $calls = (int) get_transient('bp_paypal_verify_calls');
        if ($calls >= 60) {
            return 'error';
        }
        set_transient('bp_paypal_verify_calls', $calls + 1, MINUTE_IN_SECONDS);
        $api = $settings['paypal_mode'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $access = self::paypalToken($api, $settings);
        if ($access === null) {
            return self::rememberVerify('error', $settings['paypal_mode']);
        }
        // webhook_event als unveränderter Rohtext: Neu kodiertes JSON (andere Zahlen- oder Unicode-Schreibweise)
        // kann bei PayPal als FAILURE enden. Der Rohtext ist gültiges JSON (json_decode in paypal()).
        $payload = (string) wp_json_encode($fields + ['webhook_id' => $settings['paypal_webhook_id'], 'webhook_event' => '__BP_EVENT__']);
        $payload = str_replace('"__BP_EVENT__"', trim($body), $payload);
        $verify = wp_remote_post($api.'/v1/notifications/verify-webhook-signature', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer '.$access, 'Content-Type' => 'application/json'],
            'body' => $payload,
        ]);
        $code = (int) wp_remote_retrieve_response_code($verify);
        if (is_wp_error($verify) || $code < 200 || $code >= 300) {
            if ($code === 401) {
                delete_transient(self::tokenKey($settings)); // Token abgelaufen oder widerrufen: beim nächsten Mal neu holen.
            }

            return self::rememberVerify('error', $settings['paypal_mode']);
        }
        $data = json_decode((string) wp_remote_retrieve_body($verify), true);
        $status = is_array($data) ? (string) ($data['verification_status'] ?? '') : '';

        return self::rememberVerify($status === 'SUCCESS' ? 'success' : 'failure', $settings['paypal_mode']);
    }

    /**
     * OAuth-Token (Client-ID/Secret) für die PayPal-API, zwischengespeichert bis kurz vor Ablauf.
     *
     * @param array<string,mixed> $settings
     * @return string|null null = kein Token erhalten (Netzwerkfehler, falsche Zugangsdaten)
     */
    private static function paypalToken(string $api, array $settings): ?string
    {
        $key = self::tokenKey($settings);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $response = wp_remote_post($api.'/v1/oauth2/token', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Basic '.base64_encode($settings['paypal_client_id'].':'.$settings['paypal_secret']), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => 'grant_type=client_credentials',
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            return null;
        }
        $ttl = (int) ($data['expires_in'] ?? 0) - 300;
        if ($ttl > 60) {
            set_transient($key, $token, min($ttl, 8 * HOUR_IN_SECONDS));
        }

        return $token;
    }

    /** @param array<string,mixed> $settings Schlüssel hängt an Umgebung und Client-ID (Wechsel = neues Token). */
    private static function tokenKey(array $settings): string
    {
        return 'bp_paypal_token_'.md5($settings['paypal_mode'].'|'.$settings['paypal_client_id']);
    }

    /** Ergebnis der Prüfung für die Anzeige merken (ohne Inhalte der Nachricht). */
    private static function rememberVerify(string $result, string $mode): string
    {
        update_option(self::OPTION_LAST_VERIFY, ['result' => $result, 'time' => time(), 'mode' => $mode], false);

        return $result;
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

    // ------------------------------------------------------------------ Klarna von Hand (Bestellaktion, T65)

    /** Bezahlt mit Klarna (Klarna Payments, Klarna Checkout „kco“, Klarna über Stripe u. a.)? */
    public static function isKlarnaOrder(mixed $order): bool
    {
        if (! $order instanceof \WC_Order) {
            return false;
        }
        $method = strtolower((string) $order->get_payment_method());

        return str_contains($method, 'klarna') || str_starts_with($method, 'kco');
    }

    /**
     * Bestellaktionen „Klarna-Konflikt melden“ – nur bei Klarna-Bestellungen und mit dem Recht, Zugänge zu verwalten.
     *
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function klarnaOrderActions(array $actions, $order = null): array
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS) || ! self::isKlarnaOrder($order)) {
            return $actions;
        }
        $actions['bp_dispute_klarna_opened'] = __('Klarna-Konflikt melden: eröffnet (Lernzugang aussetzen)', 'bootspruefung-learning');
        $actions['bp_dispute_klarna_won'] = __('Klarna-Konflikt melden: gewonnen (Lernzugang wiederherstellen)', 'bootspruefung-learning');
        $actions['bp_dispute_klarna_lost'] = __('Klarna-Konflikt melden: verloren (Lernzugang widerrufen)', 'bootspruefung-learning');

        return $actions;
    }

    /** Zusätzliches Feld „Klarna-Fall-ID“ unter der Begründung (nur bei Klarna-Bestellungen). */
    public function klarnaCaseField($orderId = 0): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order((int) $orderId) : null;
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS) || ! self::isKlarnaOrder($order)) {
            return;
        }
        echo '<li class="wide"><label for="bp_dispute_case" class="screen-reader-text">'.esc_html__('Klarna-Fall-ID', 'bootspruefung-learning').'</label><input type="text" id="bp_dispute_case" name="bp_dispute_case" maxlength="64" style="width:100%" placeholder="'.esc_attr__('Klarna-Fall-ID (optional, für Klarna-Konflikt)', 'bootspruefung-learning').'"></li>';
    }

    /**
     * Klarna-Konflikt von Hand melden: derselbe Weg wie der allgemeine Webhook (handle(): aussetzen bzw.
     * wiederherstellen/widerrufen, Reihenfolge je Fall, Audit-Log, Bestellnotiz). Begründung ist Pflicht
     * (Feld der Lernzugang-Aktionen).
     */
    public function klarnaOrderAction(string $event, $order): ?array
    {
        if (! $order instanceof \WC_Order || ! current_user_can(Capabilities::MANAGE_ENTITLEMENTS) || ! isset(['opened' => 1, 'won' => 1, 'lost' => 1][$event])) {
            return null;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- Woo prüft die Nonce des Bestellformulars.
        $reason = sanitize_text_field(wp_unslash($_POST['bp_access_reason'] ?? ''));
        $caseId = mb_substr(trim(sanitize_text_field(wp_unslash($_POST['bp_dispute_case'] ?? ''))), 0, 64);
        // phpcs:enable
        if ($reason === '') {
            self::adminError(__('Klarna-Konflikt: Bitte eine Begründung angeben.', 'bootspruefung-learning'));

            return null;
        }
        $id = (int) $order->get_id();
        // Eigene Ereignis-ID je Meldung; der Fall (Klarna-Fall-ID, sonst ein Fall je Bestellung) ordnet die Meldungen.
        $result = $this->handle('klarna', 'admin-'.wp_generate_uuid4(), $event, $id, [], $reason, time(), $caseId !== '' ? $caseId : null);
        if (! empty($result['retry'])) {
            self::adminError(__('Klarna-Konflikt: Die Bestellung wird gerade bearbeitet. Bitte gleich noch einmal versuchen.', 'bootspruefung-learning'));
        } elseif (empty($result['matched'])) {
            self::adminError(__('Klarna-Konflikt: nicht angewendet – die WooCommerce-Anbindung des Lern-Plugins ist nicht aktiv.', 'bootspruefung-learning'));
        } elseif (! empty($result['ignored'])) {
            $states = wc_get_order($id) instanceof \WC_Order ? wc_get_order($id)->get_meta(self::META_STATE) : [];
            $status = is_array($states) ? (string) ($states['klarna|'.$caseId]['status'] ?? '') : '';
            self::adminError(in_array($status, ['won', 'lost'], true) && ($event === 'opened' || $status !== $event)
                ? __('Klarna-Konflikt: nicht angewendet – dieser Konfliktfall ist bereits abgeschlossen (siehe Bestellnotiz). Für einen neuen Konflikt bitte die Klarna-Fall-ID angeben.', 'bootspruefung-learning')
                : __('Klarna-Konflikt: Dieser Stand ist bereits gemeldet – nichts geändert.', 'bootspruefung-learning'));
        }

        return $result;
    }

    private static function adminError(string $message): void
    {
        if (class_exists('WC_Admin_Meta_Boxes')) {
            \WC_Admin_Meta_Boxes::add_error($message);
        }
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
        // Gespeicherte Werte ohne Konstanten: Werte aus wp-config.php landen nie in der Datenbank.
        $current = self::storedSettings();
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
        $changed = false;
        foreach (['paypal_client_id', 'paypal_secret', 'paypal_webhook_id', 'paypal_mode'] as $key) {
            $changed = $changed || $new[$key] !== $current[$key];
        }
        if ($changed) {
            // Neue Zugangsdaten: altes Token und alte „letzte Prüfung“ gelten nicht mehr.
            delete_transient(self::tokenKey($current));
            delete_transient(self::tokenKey(self::settings()));
            delete_option(self::OPTION_LAST_VERIFY);
        }
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
        $status = self::paypalStatus();
        printf('<tr><th>%s</th><td><div class="notice inline %s"><p><strong>%s</strong></p></div></td></tr>', esc_html__('Signaturprüfung PayPal', 'bootspruefung-learning'),
            $status['verified'] ? ($status['last'] !== null && $status['last']['result'] !== 'success' ? 'notice-warning' : 'notice-success') : 'notice-error', esc_html(self::paypalStatusText($status)));
        // In wp-config.php gesetzte Werte (BOOTSPRUEFUNG_PAYPAL_*) haben Vorrang und sind hier nicht änderbar.
        $fixed = self::constantValues();
        $fromConfig = static fn (string $key): string => isset($fixed[$key]) ? ' disabled' : '';
        $configNote = static fn (string $key): string => isset($fixed[$key]) ? '<p class="description">'.esc_html__('Festgelegt in wp-config.php.', 'bootspruefung-learning').'</p>' : '';
        printf('<tr><th><label for="bp-pp-client">%s</label></th><td><input id="bp-pp-client" class="regular-text" name="paypal_client_id" value="%s"%s>%s</td></tr>', esc_html__('PayPal Client-ID', 'bootspruefung-learning'), esc_attr($s['paypal_client_id']), $fromConfig('paypal_client_id'), $configNote('paypal_client_id'));
        printf('<tr><th><label for="bp-pp-secret">%s</label></th><td><input id="bp-pp-secret" type="password" class="regular-text" name="paypal_secret" autocomplete="new-password" placeholder="%s"%s>%s</td></tr>', esc_html__('PayPal Secret', 'bootspruefung-learning'), esc_attr($s['paypal_secret'] !== '' ? __('gespeichert – leer lassen zum Behalten', 'bootspruefung-learning') : ''), $fromConfig('paypal_secret'), $configNote('paypal_secret'));
        printf('<tr><th><label for="bp-pp-webhook">%s</label></th><td><input id="bp-pp-webhook" class="regular-text" name="paypal_webhook_id" value="%s"%s>%s</td></tr>', esc_html__('PayPal Webhook-ID', 'bootspruefung-learning'), esc_attr($s['paypal_webhook_id']), $fromConfig('paypal_webhook_id'), $configNote('paypal_webhook_id'));
        printf('<tr><th>%s</th><td><label><input type="radio" name="paypal_mode" value="live" %s%s> Live</label> <label><input type="radio" name="paypal_mode" value="sandbox" %s%s> Sandbox</label> <label style="margin-left:16px"><input type="checkbox" name="clear_paypal" value="1"> %s</label>%s<p class="description">%s</p></td></tr>',
            esc_html__('Umgebung', 'bootspruefung-learning'), checked($s['paypal_mode'], 'live', false), $fromConfig('paypal_mode'), checked($s['paypal_mode'], 'sandbox', false), $fromConfig('paypal_mode'), esc_html__('PayPal-Zugangsdaten löschen', 'bootspruefung-learning'), $configNote('paypal_mode'),
            esc_html__('Sandbox = Testkonto aus dem PayPal-Developer-Dashboard, Live = echtes Händlerkonto. Client-ID, Secret und Webhook-ID müssen zur gewählten Umgebung gehören. Statt hier können sie auch in wp-config.php stehen: BOOTSPRUEFUNG_PAYPAL_CLIENT_ID, BOOTSPRUEFUNG_PAYPAL_SECRET, BOOTSPRUEFUNG_PAYPAL_WEBHOOK_ID, BOOTSPRUEFUNG_PAYPAL_MODE (live oder sandbox).', 'bootspruefung-learning'));
        printf('<tr><th>%s</th><td><code>%s</code><p class="description">%s</p>%s</td></tr>', esc_html__('Allgemeiner Dispute-Webhook (Klarna u. a.)', 'bootspruefung-learning'), esc_html(rest_url(RestRegistrar::NAMESPACE.'/webhooks/dispute')),
            esc_html__('POST mit JSON {"event":"opened|won|lost","order_id":123 oder "transaction_id":"…","id":"eindeutige Ereignis-ID","source":"klarna","dispute_id":"Fall-ID","timestamp":"2026-11-12T10:00:00Z"} und Header X-Bootspruefung-Signature: sha256=HMAC-SHA256(Rohtext, Schlüssel). dispute_id und timestamp sind optional, aber empfohlen: Ein gewonnener oder verlorener Fall ist endgültig, ein später eintreffendes „opened“ desselben Falls wird ignoriert. Geeignet für Benachrichtigungen aus dem Klarna-Händlerportal über eine Automatisierung (z. B. E-Mail-/Webhook-Weiterleitung). Ohne Automatisierung: in der Klarna-Bestellung die Bestellaktion „Klarna-Konflikt melden“ nutzen – sie wirkt genauso.', 'bootspruefung-learning'),
            $s['generic_secret'] !== '' ? '<p>'.esc_html__('Schlüssel:', 'bootspruefung-learning').' <code>'.esc_html($s['generic_secret']).'</code> <label><input type="checkbox" name="regenerate_secret" value="1"> '.esc_html__('neu erzeugen', 'bootspruefung-learning').'</label></p>' : '<p>'.esc_html__('Der Schlüssel wird beim Speichern erzeugt.', 'bootspruefung-learning').'</p>');
        printf('<tr><th>%s</th><td><label><input type="checkbox" name="generic_strict" value="1" %s> %s</label><p class="description">%s</p></td></tr>', esc_html__('Pflichtfelder', 'bootspruefung-learning'), checked(! empty($s['generic_strict']), true, false),
            esc_html__('dispute_id und timestamp verlangen (sonst 400)', 'bootspruefung-learning'),
            esc_html__('Empfohlen, sobald die Automatisierung beide Felder mitschickt. Ohne dispute_id gilt ein Fall je Quelle und Bestellung: Ein zweiter Konflikt zur selben Bestellung nach einem abgeschlossenen würde nicht erkannt. Ohne timestamp zählt die Eingangszeit, verspätete Nachrichten können dann falsch einsortiert werden.', 'bootspruefung-learning'));
        echo '</tbody></table>';
        submit_button(__('Konflikt-Einstellungen speichern', 'bootspruefung-learning'));
        echo '</form>';
    }
}
