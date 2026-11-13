<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Privacy;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Security\Capabilities;
use Bootspruefung\Learning\Settings\Settings;

/**
 * Bestätigte Datenschutz-Anträge ausführen (LAW-04, QA-08).
 *
 * Bisher entfernte ein Löschantrag (App „Konto löschen“, Web „Meine Daten“, Werkzeuge → „Personenbezogene
 * Daten löschen“) nur die Lerndaten; Konto, Login und Bestelldaten mit Namen blieben. Jetzt:
 *
 * - Bestätigt die Person den Link aus der Mail (wp-login.php?action=confirmaction bzw. App:
 *   POST /me/deletion-request/confirm → Aktion user_request_action_confirmed), laufen sofort alle
 *   registrierten Datenschutz-Löschungen (WordPress, WooCommerce, Plugin). Danach gilt der Antrag als
 *   erledigt (wie in den Werkzeugen) und wp_privacy_personal_data_erased folgt.
 * - Auf wp_privacy_personal_data_erased (auch wenn der Betreiber die Löschung in den Werkzeugen ausführt)
 *   werden die WooCommerce-Bestellungen anonymisiert (Name, Anschrift, E-Mail, Telefon, IP, Browser; Nummer,
 *   Datum, Positionen und Beträge bleiben für die Aufbewahrungspflicht) und das Konto gelöscht
 *   (wp_delete_user ohne Neuzuweisung). Gruppenplätze werden dabei frei (PersonalData, deleted_user).
 * - Konten mit Verwaltungsrechten (Administrator, Shop-Manager, Redaktion, Plugin-Rechte) werden nie
 *   automatisch gelöscht; ihr Antrag bleibt „bestätigt“ in den Werkzeugen stehen.
 * - Bestätigte Export-Anträge werden im Hintergrund erzeugt und per Download-Link gemailt (wie
 *   „Export-Link senden“ in den Werkzeugen).
 *
 * Abschaltbar in den Plugin-Einstellungen („Datenschutz-Anträge automatisch ausführen“); dann bleibt es beim
 * WordPress-Standard (Betreiber führt bestätigte Anträge in den Werkzeugen aus, ohne Kontolöschung).
 */
final class AccountDeletion
{
    /** Hintergrundaufgabe: bestätigten Export erzeugen und mailen (Argument: Antrags-ID). */
    public const EXPORT_HOOK = 'bp_privacy_export_generate';
    /** Antrags-Meta: Zeitpunkt der Kontolöschung. */
    public const DELETED_META = '_bp_account_deleted';
    private const MAX_PAGES = 500;

    public static function register(): void
    {
        // Nach WordPress (10: Status „bestätigt“, 12: Benachrichtigung an die Verwaltung).
        add_action('user_request_action_confirmed', [self::class, 'confirmed'], 20, 1);
        add_action('wp_privacy_personal_data_erased', [self::class, 'erased'], 20, 1);
        add_action(self::EXPORT_HOOK, [self::class, 'processExport'], 10, 1);
        add_filter('user_request_action_confirmed_message', [self::class, 'confirmedMessage'], 20, 2);
        // Löschantrag erst nach Klick auf „Konto jetzt löschen“ ausführen, nicht schon beim Aufruf des Mail-Links
        // (Link-Scanner von Mailprogrammen rufen Links ab; To-do T42). Vor wp-login.php (action=confirmaction).
        add_action('login_form_confirmaction', [self::class, 'confirmPage'], 1);
        add_filter('user_request_action_email_content', [self::class, 'emailContent'], 20, 2);
        // Geld-zurück-Anfrage einer anonymisierten Bestellung: Kontokennung entfernen, Zeitpunkt/Stand bleiben.
        add_action('woocommerce_privacy_remove_order_personal_data', [self::class, 'eraseOrderMeta'], 10, 1);
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('privacy_requests_auto');
    }

    /** Konten, die nie automatisch gelöscht werden (Verwaltung, Shop, Redaktion). */
    public static function isProtected(\WP_User $user): bool
    {
        $protected = array_intersect((array) $user->roles, ['administrator', 'shop_manager', 'editor', 'author', 'contributor']) !== []
            || user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || user_can($user, 'edit_posts') || user_can($user, 'list_users')
            || (is_multisite() && is_super_admin((int) $user->ID));
        foreach (Capabilities::ALL as $cap) {
            $protected = $protected || user_can($user, $cap);
        }

        /**
         * Filter: Konto von der automatischen Löschung ausnehmen.
         *
         * @param bool     $protected
         * @param \WP_User $user
         */
        return (bool) apply_filters('bootspruefung_account_deletion_protected', $protected, $user);
    }

    /** Konto eines Antrags (Kennung im Antrag, sonst über die E-Mail-Adresse). */
    public static function userFor(\WP_User_Request $request): ?\WP_User
    {
        $user = $request->user_id > 0 ? get_userdata((int) $request->user_id) : false;
        if (! $user instanceof \WP_User) {
            $user = get_user_by('email', (string) $request->email);
        }

        return $user instanceof \WP_User ? $user : null;
    }

    /** user_request_action_confirmed: bestätigten Antrag ausführen. */
    public static function confirmed($requestId): void
    {
        if (! self::enabled()) {
            return;
        }
        $request = wp_get_user_request((int) $requestId);
        if (! $request instanceof \WP_User_Request || $request->status !== 'request-confirmed') {
            return;
        }
        if ($request->action_name === 'remove_personal_data') {
            self::processDeletion((int) $request->ID);
        } elseif ($request->action_name === 'export_personal_data') {
            self::scheduleExport((int) $request->ID);
        }
    }

    /**
     * Löschantrag ausführen: alle Datenschutz-Löschungen, Antrag erledigt, danach Konto löschen (über
     * wp_privacy_personal_data_erased). @return string completed|protected|failed|skipped
     */
    public static function processDeletion(int $requestId): string
    {
        $request = wp_get_user_request($requestId);
        if (! $request instanceof \WP_User_Request || $request->action_name !== 'remove_personal_data' || $request->status !== 'request-confirmed') {
            return 'skipped';
        }
        $user = self::userFor($request);
        if ($user !== null && self::isProtected($user)) {
            AuditLog::record('account_deletion_skipped', 'user_request', (string) $requestId, 'protected_account');

            return 'protected';
        }
        $result = self::runErasers((string) $request->email);
        if (! $result['ok']) {
            AuditLog::record('account_deletion_failed', 'user_request', (string) $requestId, 'eraser_failed', ['messages' => array_slice($result['messages'], 0, 5)]);

            return 'failed';
        }
        require_once ABSPATH.'wp-admin/includes/privacy-tools.php';
        _wp_privacy_completed_request($requestId);
        // Bestätigung an die Person (in den Werkzeugen hängt WordPress das nur im Verwaltungsbereich an).
        if (! has_action('wp_privacy_personal_data_erased', '_wp_privacy_send_erasure_fulfillment_notification')) {
            _wp_privacy_send_erasure_fulfillment_notification($requestId);
        }
        /** Core-Aktion wie nach „Personenbezogene Daten löschen“ in den Werkzeugen (löst die Kontolöschung aus). */
        do_action('wp_privacy_personal_data_erased', $requestId);

        return 'completed';
    }

    /** wp_privacy_personal_data_erased: Bestellungen anonymisieren, Konto löschen. */
    public static function erased($requestId): void
    {
        if (! self::enabled()) {
            return;
        }
        $request = wp_get_user_request((int) $requestId);
        if (! $request instanceof \WP_User_Request || $request->action_name !== 'remove_personal_data') {
            return;
        }
        $user = self::userFor($request);
        if ($user === null || self::isProtected($user)) {
            return;
        }
        self::deleteAccount($user, (int) $request->ID);
    }

    /** Bestellungen anonymisieren und Konto löschen (Inhalte werden niemandem zugewiesen). */
    public static function deleteAccount(\WP_User $user, int $requestId = 0): bool
    {
        if (self::isProtected($user)) {
            return false;
        }
        self::anonymizeOrders($user);
        require_once ABSPATH.'wp-admin/includes/user.php';
        $pseudonym = hash('sha256', (string) $user->ID.wp_salt());
        $ok = wp_delete_user((int) $user->ID);
        AuditLog::record($ok ? 'account_deleted' : 'account_deletion_failed', 'user_request', (string) $requestId, $ok ? 'privacy_request' : 'wp_delete_user', ['user' => $pseudonym]);
        if ($ok && $requestId > 0) {
            update_post_meta($requestId, self::DELETED_META, time());
        }

        return (bool) $ok;
    }

    /**
     * WooCommerce-Bestellungen des Kontos anonymisieren (WooCommerce-Datenschutzfunktion): Name, Anschrift,
     * E-Mail, Telefon und Browser werden entfernt, die IP-Adresse gekürzt, Bestellnotizen gelöscht. Bestellnummer, Datum,
     * Positionen, Beträge, Steuern, Zahlungsart und die Transaktionsnummer des Zahlungsdienstes bleiben
     * (Aufbewahrungspflicht für Buchungsbelege). @return int Anzahl anonymisierter Bestellungen
     */
    public static function anonymizeOrders(\WP_User $user): int
    {
        if (! function_exists('wc_get_orders') || ! class_exists(\WC_Privacy_Erasers::class)) {
            return 0;
        }
        $keep = static function ($props) {
            return is_array($props) ? array_diff_key($props, ['transaction_id' => true]) : $props;
        };
        add_filter('woocommerce_privacy_remove_order_personal_data_props', $keep, 99);
        $n = 0;
        $seen = [];
        try {
            // Anonymisierte Bestellungen passen nicht mehr zur Suche (Kunde 0, E-Mail entfernt) – daher immer Seite 1.
            for ($round = 0; $round < 200; $round++) {
                $orders = wc_get_orders(['customer' => [(int) $user->ID, (string) $user->user_email], 'limit' => 50, 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses())]);
                $todo = array_filter($orders, static fn ($o) => $o instanceof \WC_Order && ! isset($seen[$o->get_id()]));
                if ($todo === []) {
                    break;
                }
                foreach ($todo as $order) {
                    $seen[$order->get_id()] = true;
                    \WC_Privacy_Erasers::remove_order_personal_data($order);
                    $n++;
                }
            }
        } finally {
            remove_filter('woocommerce_privacy_remove_order_personal_data_props', $keep, 99);
        }

        return $n;
    }

    /** Anonymisierte Bestellung: Kontokennung aus der Geld-zurück-Anfrage entfernen. */
    public static function eraseOrderMeta($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        $request = $order->get_meta(\Bootspruefung\Learning\Commerce\MoneyBack::REQUEST_META);
        if (is_array($request) && isset($request['user_id'])) {
            unset($request['user_id']);
            $order->update_meta_data(\Bootspruefung\Learning\Commerce\MoneyBack::REQUEST_META, $request);
            $order->save_meta_data();
        }
    }

    /**
     * Alle registrierten Datenschutz-Löschungen für eine Adresse, seitenweise bis „fertig“.
     *
     * @return array{ok:bool,removed:bool,retained:bool,messages:list<string>}
     */
    public static function runErasers(string $email): array
    {
        $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
        $out = ['ok' => true, 'removed' => false, 'retained' => false, 'messages' => []];
        foreach ((array) $erasers as $key => $eraser) {
            if (! is_array($eraser) || ! isset($eraser['callback']) || ! is_callable($eraser['callback'])) {
                continue;
            }
            $done = false;
            for ($page = 1; $page <= self::MAX_PAGES && ! $done; $page++) {
                $response = call_user_func($eraser['callback'], $email, $page);
                if (! is_array($response) || ! array_key_exists('done', $response)) {
                    $out['ok'] = false;
                    $out['messages'][] = sprintf('%s: invalid response', (string) $key);

                    return $out;
                }
                $out['removed'] = $out['removed'] || ! empty($response['items_removed']);
                $out['retained'] = $out['retained'] || ! empty($response['items_retained']);
                foreach ((array) ($response['messages'] ?? []) as $message) {
                    $out['messages'][] = (string) $message;
                }
                $done = (bool) $response['done'];
            }
            if (! $done) {
                $out['ok'] = false;
                $out['messages'][] = sprintf('%s: too many pages', (string) $key);

                return $out;
            }
        }

        return $out;
    }

    /** Export im Hintergrund erzeugen: Action Scheduler (WooCommerce), sonst WP-Cron-Einzelereignis. */
    public static function scheduleExport(int $requestId): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::EXPORT_HOOK, [$requestId], 'bootspruefung-learning');

            return;
        }
        wp_schedule_single_event(time(), self::EXPORT_HOOK, [$requestId]);
    }

    /**
     * Bestätigten Export erzeugen (ZIP wie in den Werkzeugen) und den Download-Link mailen. Schlägt etwas
     * fehl, bleibt der Antrag „bestätigt“ und der Betreiber erledigt ihn in den Werkzeugen.
     */
    public static function processExport($requestId): bool
    {
        $requestId = (int) $requestId;
        $request = wp_get_user_request($requestId);
        if (! $request instanceof \WP_User_Request || $request->action_name !== 'export_personal_data' || $request->status !== 'request-confirmed') {
            return false;
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/privacy-tools.php';
        $fail = static function (string $why) use ($requestId): bool {
            AuditLog::record('privacy_export_failed', 'user_request', (string) $requestId, $why);

            return false;
        };
        // Vorbedingungen hier prüfen: wp_privacy_generate_personal_data_export_file() bricht sonst mit wp_die() ab.
        if (! class_exists('ZipArchive') || ! is_email($request->email) || ! wp_mkdir_p(wp_privacy_exports_dir())) {
            return $fail('export_unavailable');
        }
        $items = [];
        foreach ((array) apply_filters('wp_privacy_personal_data_exporters', []) as $key => $exporter) {
            if (! is_array($exporter) || ! isset($exporter['callback']) || ! is_callable($exporter['callback'])) {
                continue;
            }
            $done = false;
            for ($page = 1; $page <= self::MAX_PAGES && ! $done; $page++) {
                $response = call_user_func($exporter['callback'], (string) $request->email, $page);
                if (! is_array($response) || ! isset($response['data']) || ! is_array($response['data']) || ! array_key_exists('done', $response)) {
                    return $fail('exporter_invalid:'.(string) $key);
                }
                $items = array_merge($items, $response['data']);
                $done = (bool) $response['done'];
            }
            if (! $done) {
                return $fail('exporter_pages:'.(string) $key);
            }
        }
        // Gruppieren wie wp_privacy_process_personal_data_export_page().
        $groups = [];
        foreach ($items as $item) {
            $gid = (string) ($item['group_id'] ?? '');
            $groups[$gid] ??= ['group_label' => (string) ($item['group_label'] ?? $gid), 'group_description' => (string) ($item['group_description'] ?? ''), 'items' => []];
            $iid = (string) ($item['item_id'] ?? '');
            $groups[$gid]['items'][$iid] = array_merge((array) ($item['data'] ?? []), $groups[$gid]['items'][$iid] ?? []);
        }
        update_post_meta($requestId, '_export_data_grouped', $groups);
        wp_privacy_generate_personal_data_export_file($requestId);
        delete_post_meta($requestId, '_export_data_grouped');
        if ((string) get_post_meta($requestId, '_export_file_name', true) === '') {
            return $fail('export_file');
        }
        $sent = wp_privacy_send_personal_data_export_email($requestId);
        if (is_wp_error($sent)) {
            return $fail('export_mail');
        }
        _wp_privacy_completed_request($requestId);
        AuditLog::record('privacy_export_sent', 'user_request', (string) $requestId);

        return true;
    }

    /** Text nach dem Bestätigungslink (wp-login.php?action=confirmaction). */
    public static function confirmedMessage($message, $requestId)
    {
        if (! self::enabled()) {
            return $message;
        }
        $request = wp_get_user_request((int) $requestId);
        if (! $request instanceof \WP_User_Request) {
            return $message;
        }
        if ($request->action_name === 'remove_personal_data' && get_post_meta((int) $requestId, self::DELETED_META, true)) {
            return '<p class="success">'.esc_html__('Dein Konto ist gelöscht.', 'bootspruefung-learning').'</p><p>'
                .esc_html__('Dein Kundenkonto und deine Lerndaten sind gelöscht; eine Bestätigung ist per E-Mail unterwegs. Bestellungen bewahren wir ohne Namen und Kontaktdaten auf, solange gesetzliche Aufbewahrungsfristen laufen.', 'bootspruefung-learning').'</p>';
        }
        if ($request->action_name === 'remove_personal_data' && $request->status === 'request-completed') {
            return '<p class="success">'.esc_html__('Deine Daten sind gelöscht.', 'bootspruefung-learning').'</p><p>'
                .esc_html__('Eine Bestätigung ist per E-Mail unterwegs.', 'bootspruefung-learning').'</p>';
        }
        if ($request->action_name === 'export_personal_data' && in_array($request->status, ['request-confirmed', 'request-completed'], true)) {
            return '<p class="success">'.esc_html__('Danke für deine Bestätigung.', 'bootspruefung-learning').'</p><p>'
                .esc_html__('Wir stellen deinen Datenexport zusammen und schicken dir den Download-Link in Kürze per E-Mail.', 'bootspruefung-learning').'</p>';
        }

        return $message;
    }

    /**
     * Braucht dieser Aufruf von wp-login.php?action=confirmaction erst den Knopf? Nur bei Löschanträgen mit
     * automatischer Ausführung und solange nicht per POST bestätigt (Exporte bleiben wie bisher).
     */
    public static function needsButton(int $requestId, string $method, bool $confirmedByButton): bool
    {
        if (! self::enabled() || $requestId <= 0) {
            return false;
        }
        $request = wp_get_user_request($requestId);
        if (! $request instanceof \WP_User_Request || $request->action_name !== 'remove_personal_data' || ! in_array($request->status, ['request-pending', 'request-failed'], true)) {
            return false;
        }

        return ! (strtoupper($method) === 'POST' && $confirmedByButton);
    }

    /** login_form_confirmaction: Bestätigungsseite statt sofortiger Löschung. */
    public static function confirmPage(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- abgesichert über den geheimen confirm_key wie in WordPress
        $requestId = absint($_GET['request_id'] ?? 0);
        $key = sanitize_text_field(wp_unslash((string) ($_GET['confirm_key'] ?? '')));
        $confirmed = ! empty($_POST['bp_confirm_deletion']);
        // phpcs:enable
        if ($key === '' || ! self::needsButton($requestId, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $confirmed)) {
            return; // WordPress fährt fort (prüft den Schlüssel, bestätigt, führt aus)
        }
        if (is_wp_error(wp_validate_user_request_key($requestId, $key))) {
            return; // WordPress zeigt die Fehlermeldung
        }
        if (function_exists('login_header')) {
            login_header(__('Konto löschen', 'bootspruefung-learning'));
        }
        echo self::confirmForm($requestId, $key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- in confirmForm maskiert
        if (function_exists('login_footer')) {
            login_footer();
        }
        exit;
    }

    /** Formular „Konto jetzt löschen“ (POST an dieselbe Adresse). */
    public static function confirmForm(int $requestId, string $key): string
    {
        $action = add_query_arg(['action' => 'confirmaction', 'request_id' => $requestId, 'confirm_key' => rawurlencode($key)], wp_login_url());

        return '<div class="message"><p><strong>'.esc_html__('Möchtest du dein Konto wirklich löschen?', 'bootspruefung-learning').'</strong></p><p>'
            .esc_html__('Mit dem Klick auf „Konto jetzt löschen“ werden dein Kundenkonto und deine Lerndaten (Lernstand, Prüfungen, Einstellungen) sofort gelöscht. Gekaufte Zugänge enden damit. Bestellungen bewahren wir ohne Namen und Kontaktdaten auf, solange gesetzliche Aufbewahrungsfristen laufen. Das lässt sich nicht rückgängig machen.', 'bootspruefung-learning').'</p></div>'
            .'<form method="post" action="'.esc_url($action).'"><input type="hidden" name="bp_confirm_deletion" value="1">'
            .'<p class="submit"><button type="submit" class="button button-primary button-large">'.esc_html__('Konto jetzt löschen', 'bootspruefung-learning').'</button></p></form>'
            .'<p><a href="'.esc_url(home_url('/')).'">'.esc_html__('Abbrechen und Konto behalten', 'bootspruefung-learning').'</a></p>';
    }

    /**
     * Bestätigungsmail eines Löschantrags: erklären, was nach dem Klick passiert (Knopf auf der Seite, dann sofort).
     * @param mixed $content @param mixed $emailData
     */
    public static function emailContent($content, $emailData): string
    {
        $content = (string) $content;
        $request = is_array($emailData) ? ($emailData['request'] ?? null) : null;
        if (! self::enabled() || ! $request instanceof \WP_User_Request || $request->action_name !== 'remove_personal_data' || ! str_contains($content, '###CONFIRM_URL###')) {
            return $content;
        }
        $note = __('Der Link öffnet eine Seite mit dem Knopf „Konto jetzt löschen“. Erst dieser Klick löscht dein Kundenkonto und deine Lerndaten – sofort und endgültig. Bestellungen bewahren wir ohne Namen und Kontaktdaten auf, solange gesetzliche Aufbewahrungsfristen laufen. Hast du die Löschung nicht beantragt, ignoriere diese E-Mail.', 'bootspruefung-learning');

        return (string) preg_replace('/###CONFIRM_URL###/', "###CONFIRM_URL###\n\n".$note, $content, 1);
    }

    /**
     * Konto gelöscht: Zugänge ohne Bestellung (manuell, Gruppe, Gutschein) entfernen, Zugänge zu Bestellungen
     * beenden (die Bestellung bleibt anonymisiert der Nachweis). Sonst blieben Zeilen ohne Konto stehen.
     */
    public static function closeEntitlements(int $userId): int
    {
        global $wpdb;
        $t = $wpdb->prefix.'bp_entitlement';
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE user_id = %d AND order_id IS NULL", $userId));
        $ended = (int) $wpdb->query($wpdb->prepare("UPDATE {$t} SET status = 'revoked', reason = %s WHERE user_id = %d AND status <> 'revoked'", __('Konto gelöscht', 'bootspruefung-learning'), $userId));
        if ($deleted + $ended > 0) {
            AuditLog::record('entitlements_closed_account_deleted', 'user', hash('sha256', $userId.wp_salt()), null, ['deleted' => $deleted, 'revoked' => $ended]);
        }

        return $deleted + $ended;
    }
}
