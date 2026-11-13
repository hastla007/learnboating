<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Privacy;

use Bootspruefung\Learning\Mobile\ApiException;
use Bootspruefung\Learning\Mobile\RateLimiter;

/**
 * „Meine Daten“ unter WooCommerce „Mein Konto“ (QA-08): Datenexport anfordern und Konto löschen in
 * Selbstbedienung – wie in der App über die WordPress-Datenschutzanträge (wp_create_user_request) mit
 * Bestätigungsmail. Ausgeführt wird nach der Bestätigung (AccountDeletion), sonst vom Betreiber in den
 * Werkzeugen.
 */
final class SelfService
{
    public const ENDPOINT = 'meine-daten';
    /** Bei Änderung der Endpunkte neu schreiben (Permalinks). */
    private const REWRITE_VERSION = '1';
    public const REWRITE_OPTION = 'bp_privacy_endpoint_version';
    private const TYPES = ['export' => 'export_personal_data', 'delete' => 'remove_personal_data'];

    public static function register(): void
    {
        add_filter('woocommerce_get_query_vars', static fn (array $vars) => $vars + [self::ENDPOINT => self::ENDPOINT]);
        add_filter('woocommerce_account_menu_items', [self::class, 'menu'], 30);
        add_action('woocommerce_account_'.self::ENDPOINT.'_endpoint', [self::class, 'render']);
        add_filter('woocommerce_endpoint_'.self::ENDPOINT.'_title', static fn () => __('Meine Daten', 'bootspruefung-learning'));
        add_action('admin_post_bp_privacy_request', [self::class, 'handle']);
        add_action('init', [self::class, 'maybeFlush'], 99);
    }

    public static function maybeFlush(): void
    {
        if (! class_exists('WooCommerce') || get_option(self::REWRITE_OPTION) === self::REWRITE_VERSION) {
            return;
        }
        flush_rewrite_rules(false);
        update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, true);
    }

    public static function url(): ?string
    {
        return function_exists('wc_get_account_endpoint_url') ? (string) wc_get_account_endpoint_url(self::ENDPOINT) : null;
    }

    /** Menüpunkt vor „Abmelden“. @param array<string,string> $items @return array<string,string> */
    public static function menu(array $items): array
    {
        $out = [];
        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $out[self::ENDPOINT] = __('Meine Daten', 'bootspruefung-learning');
            }
            $out[$key] = $label;
        }
        $out[self::ENDPOINT] ??= __('Meine Daten', 'bootspruefung-learning');

        return $out;
    }

    /** Offener Antrag (wartet auf Bestätigung bzw. bestätigt, noch nicht erledigt) einer Adresse. */
    public static function openRequest(string $email, string $action): ?\WP_User_Request
    {
        $ids = get_posts([
            'post_type' => 'user_request', 'post_name__in' => [$action], 'title' => $email,
            'post_status' => ['request-pending', 'request-confirmed'], 'fields' => 'ids', 'numberposts' => 1, 'suppress_filters' => true,
        ]);
        $request = $ids !== [] ? wp_get_user_request((int) $ids[0]) : false;

        return $request instanceof \WP_User_Request ? $request : null;
    }

    /**
     * Antrag stellen bzw. Bestätigungsmail erneut senden.
     *
     * @return array{result:string,message:string,request_id:?int} result: created|resent|confirmed|protected|limited|error
     */
    public static function request(int $userId, string $type): array
    {
        $user = get_userdata($userId);
        $action = self::TYPES[$type] ?? null;
        if (! $user instanceof \WP_User || $action === null) {
            return ['result' => 'error', 'message' => __('Diese Anfrage ist ungültig.', 'bootspruefung-learning'), 'request_id' => null];
        }
        if ($type === 'delete' && AccountDeletion::isProtected($user)) {
            return ['result' => 'protected', 'message' => __('Dieses Konto hat Verwaltungsrechte und kann hier nicht gelöscht werden.', 'bootspruefung-learning'), 'request_id' => null];
        }
        try {
            RateLimiter::hit('web-privacy|'.$type.'|'.$userId, 5, DAY_IN_SECONDS);
        } catch (ApiException) {
            return ['result' => 'limited', 'message' => __('Du hast heute schon mehrere Anfragen gestellt. Bitte versuche es morgen erneut.', 'bootspruefung-learning'), 'request_id' => null];
        }
        $email = (string) $user->user_email;
        $open = self::openRequest($email, $action);
        if ($open !== null && $open->status === 'request-confirmed') {
            return ['result' => 'confirmed', 'message' => __('Du hast diese Anfrage bereits bestätigt. Wir bearbeiten sie und melden uns per E-Mail.', 'bootspruefung-learning'), 'request_id' => (int) $open->ID];
        }
        if ($open !== null) {
            $sent = wp_send_user_request((int) $open->ID);

            return is_wp_error($sent)
                ? ['result' => 'error', 'message' => __('Die E-Mail konnte nicht gesendet werden. Bitte versuche es später erneut.', 'bootspruefung-learning'), 'request_id' => (int) $open->ID]
                : ['result' => 'resent', 'message' => sprintf(__('Wir haben dir die E-Mail an %s noch einmal geschickt. Bitte bestätige den Link darin.', 'bootspruefung-learning'), $email), 'request_id' => (int) $open->ID];
        }
        $id = wp_create_user_request($email, $action, ['source' => 'web'], 'pending');
        if (is_wp_error($id)) {
            return ['result' => 'error', 'message' => $id->get_error_message(), 'request_id' => null];
        }
        $sent = wp_send_user_request((int) $id);
        if (is_wp_error($sent)) {
            error_log('[bootspruefung-learning] Bestätigungsmail Datenschutzantrag fehlgeschlagen: '.$sent->get_error_message());
        }

        return ['result' => 'created', 'request_id' => (int) $id, 'message' => $type === 'delete'
            ? sprintf(__('Wir haben dir eine E-Mail an %s geschickt. Erst wenn du den Link darin bestätigst, wird dein Konto gelöscht.', 'bootspruefung-learning'), $email)
            : sprintf(__('Wir haben dir eine E-Mail an %s geschickt. Bitte bestätige den Link darin; danach bekommst du deinen Datenexport.', 'bootspruefung-learning'), $email)];
    }

    public static function handle(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            auth_redirect();
        }
        check_admin_referer('bp_privacy_request');
        $result = self::request($userId, sanitize_key(wp_unslash((string) ($_POST['type'] ?? ''))));
        set_transient('bp_privacy_notice_'.$userId, ['message' => $result['message'], 'type' => in_array($result['result'], ['created', 'resent', 'confirmed'], true) ? 'success' : 'error'], 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url() ?? home_url('/'));
        exit;
    }

    public static function render(): void
    {
        $user = wp_get_current_user();
        if (! $user instanceof \WP_User || $user->ID <= 0) {
            return;
        }
        $notice = get_transient('bp_privacy_notice_'.$user->ID);
        if (is_array($notice) && ! empty($notice['message'])) {
            delete_transient('bp_privacy_notice_'.$user->ID);
            $error = ($notice['type'] ?? '') === 'error';
            printf('<div class="%s" role="%s">%s</div>', $error ? 'woocommerce-error' : 'woocommerce-message', $error ? 'alert' : 'status', esc_html((string) $notice['message']));
        }
        $email = (string) $user->user_email;
        $auto = AccountDeletion::enabled();
        echo '<div class="bp-my-data">';
        echo '<p>'.esc_html(sprintf(__('Hier kannst du eine Kopie deiner gespeicherten Daten anfordern oder dein Konto löschen. Beides bestätigst du über einen Link, den wir dir an %s schicken – so stellen wir sicher, dass die Anfrage von dir kommt.', 'bootspruefung-learning'), $email)).'</p>';

        // Export
        echo '<h3>'.esc_html__('Meine Daten exportieren', 'bootspruefung-learning').'</h3>';
        $days = max(1, (int) round((int) apply_filters('wp_privacy_export_expiration', 3 * DAY_IN_SECONDS) / DAY_IN_SECONDS));
        echo '<p>'.esc_html__('Du bekommst eine ZIP-Datei mit deinen Kontodaten, Bestellungen, deinem Lernstand, deinen Prüfungssimulationen, Kurszugängen, Gruppen sowie App-Sitzungen und -Einstellungen.', 'bootspruefung-learning').' '
            .esc_html($auto
                /* translators: %d: days */
                ? sprintf(_n('Nach deiner Bestätigung schicken wir dir einen Download-Link per E-Mail; er ist %d Tag gültig.', 'Nach deiner Bestätigung schicken wir dir einen Download-Link per E-Mail; er ist %d Tage gültig.', $days, 'bootspruefung-learning'), $days)
                : __('Nach deiner Bestätigung stellen wir den Export zusammen und schicken dir einen Download-Link per E-Mail.', 'bootspruefung-learning')).'</p>';
        $open = self::openRequest($email, self::TYPES['export']);
        self::renderOpen($open, 'export');
        self::form('export', $open !== null && $open->status === 'request-pending' ? __('Bestätigungsmail erneut senden', 'bootspruefung-learning') : __('Datenexport anfordern', 'bootspruefung-learning'), '', $open !== null && $open->status === 'request-confirmed');

        // Löschung
        echo '<h3>'.esc_html__('Konto löschen', 'bootspruefung-learning').'</h3>';
        if (AccountDeletion::isProtected($user)) {
            echo '<p>'.esc_html__('Dieses Konto hat Verwaltungsrechte und kann hier nicht gelöscht werden.', 'bootspruefung-learning').'</p></div>';

            return;
        }
        echo '<p><strong>'.esc_html__('Was gelöscht wird', 'bootspruefung-learning').'</strong></p><ul>';
        $deleted = $auto
            ? [
                __('Dein Kundenkonto mit Anmeldung, Profil und gespeicherten Adressen – du kannst dich danach weder hier noch in der App anmelden.', 'bootspruefung-learning'),
                __('Dein Lernstand: beantwortete Fragen, Prüfungssimulationen, Favoriten und Sammlungen, Fortschritt in Videokurs, Navigation, Praxis und Funk sowie App-Einstellungen und -Sitzungen.', 'bootspruefung-learning'),
                __('Gekaufte Zugänge kannst du danach nicht mehr nutzen. Einen Platz in der Lerngruppe einer anderen Person geben wir frei.', 'bootspruefung-learning'),
                __('Gruppen, die du gekauft hast, bleiben für ihre Mitglieder bis zum Ende der Laufzeit bestehen; ihr Name wird neutralisiert.', 'bootspruefung-learning'),
            ]
            : [
                __('Dein Lernstand: beantwortete Fragen, Prüfungssimulationen, Favoriten und Sammlungen, Fortschritt in Videokurs, Navigation, Praxis und Funk sowie App-Einstellungen und -Sitzungen.', 'bootspruefung-learning'),
                __('Deine E-Mail-Adresse in Gruppen; Namen von Gruppen, die du gekauft hast, werden neutralisiert.', 'bootspruefung-learning'),
            ];
        foreach ($deleted as $line) {
            echo '<li>'.esc_html($line).'</li>';
        }
        echo '</ul><p><strong>'.esc_html__('Was aus gesetzlichen Gründen bleibt', 'bootspruefung-learning').'</strong></p><ul>';
        echo '<li>'.esc_html($auto
            ? __('Deine Bestellungen mit Bestellnummer, Datum, Produkten und Beträgen bewahren wir auf, solange gesetzliche Aufbewahrungsfristen laufen. Name, Anschrift, E-Mail-Adresse und Telefonnummer entfernen wir dabei aus den Bestellungen, die IP-Adresse wird gekürzt.', 'bootspruefung-learning')
            : __('Bestelldaten und Kurszugänge bewahren wir auf, solange gesetzliche Aufbewahrungsfristen laufen.', 'bootspruefung-learning')).'</li>';
        echo '<li>'.esc_html(sprintf(__('Protokolleinträge zu Kauf- und Zugangsvorgängen löschen wir nach %d Jahren.', 'bootspruefung-learning'), PersonalData::AUDIT_RETENTION_YEARS)).'</li></ul>';
        echo '<p>'.esc_html__('Möchtest du noch einen Vertrag widerrufen oder die Geld-zurück-Garantie nutzen? Dann tu das bitte vor der Löschung.', 'bootspruefung-learning').' '
            .esc_html($auto
                ? __('Sobald du den Link in der E-Mail bestätigst, löschen wir dein Konto sofort und endgültig.', 'bootspruefung-learning')
                : __('Nach deiner Bestätigung bearbeiten wir deinen Antrag und melden uns per E-Mail.', 'bootspruefung-learning')).'</p>';
        $open = self::openRequest($email, self::TYPES['delete']);
        self::renderOpen($open, 'delete');
        self::form(
            'delete',
            $open !== null && $open->status === 'request-pending' ? __('Bestätigungsmail erneut senden', 'bootspruefung-learning') : __('Konto löschen beantragen', 'bootspruefung-learning'),
            __('Wir schicken dir jetzt eine E-Mail. Erst wenn du den Link darin bestätigst, wird dein Konto gelöscht. Fortfahren?', 'bootspruefung-learning'),
            $open !== null && $open->status === 'request-confirmed'
        );
        echo '</div>';
    }

    private static function renderOpen(?\WP_User_Request $open, string $type): void
    {
        if ($open === null) {
            return;
        }
        $date = wp_date((string) get_option('date_format'), (int) $open->created_timestamp);
        $text = $open->status === 'request-confirmed'
            ? __('Du hast diese Anfrage bestätigt. Wir bearbeiten sie und melden uns per E-Mail.', 'bootspruefung-learning')
            /* translators: %s: date */
            : sprintf($type === 'delete'
                ? __('Du hast am %s die Löschung beantragt. Bitte bestätige sie über den Link in der E-Mail.', 'bootspruefung-learning')
                : __('Du hast am %s einen Datenexport angefordert. Bitte bestätige ihn über den Link in der E-Mail.', 'bootspruefung-learning'), $date);
        echo '<p class="woocommerce-info" role="status">'.esc_html($text).'</p>';
    }

    private static function form(string $type, string $label, string $confirm, bool $hidden): void
    {
        if ($hidden) {
            return;
        }
        $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\''.esc_js($confirm).'\')"' : '';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.$onsubmit.'>'
            .wp_nonce_field('bp_privacy_request', '_wpnonce', true, false)
            .'<input type="hidden" name="action" value="bp_privacy_request"><input type="hidden" name="type" value="'.esc_attr($type).'">'
            .'<p><button type="submit" class="woocommerce-Button button bp-privacy-'.esc_attr($type).'">'.esc_html($label).'</button></p></form>';
    }
}
