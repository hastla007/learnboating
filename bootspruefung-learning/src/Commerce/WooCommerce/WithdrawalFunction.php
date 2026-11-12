<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\Labels;

/**
 * Elektronische Widerrufsfunktion (§ 356a BGB, Art. 11a der Richtlinie 2011/83/EU in der Fassung der
 * Richtlinie (EU) 2023/2673, anwendbar seit 19.06.2026).
 *
 * Formular, Bestätigungsschritt, Eingangsbestätigung per E-Mail, Missbrauchsschutz und Datenschutz-
 * Löschung übernimmt die WooCommerce-Funktion „Order withdrawal“ (ab WooCommerce 11.1, Endpunkt
 * „order-withdrawal“ unter Mein Konto, auch ohne Anmeldung erreichbar). Dieses Plugin
 * - stellt eine feste Adresse /vertrag-widerrufen/ bereit (Fußzeile, Rechtstexte), die dorthin weiterleitet,
 * - zeigt „Vertrag hier widerrufen“ während der Frist unter „Mein Lernen“ und in der Bestellansicht,
 * - schaltet die WooCommerce-Funktion einmalig automatisch ein (ab Werk aus); ein späteres bewusstes
 *   Ausschalten bleibt bestehen,
 * - weist im Admin darauf hin, wenn die WooCommerce-Funktion ausgeschaltet ist (mit Knopf zum Einschalten);
 *   solange sie aus ist, führt /vertrag-widerrufen/ zur Widerrufsbelehrung (Kontakt, Muster-Formular),
 * - zeigt Widerrufe aus der früheren eigenen Funktion (_bp_withdrawal) weiter im Bestell-Admin an und
 *   nimmt sie in Datenexport und -löschung von WooCommerce auf.
 *
 * Rückzahlung und Ende des Zugangs veranlasst der Betreiber über „Erstattung“ in der Bestellung.
 */
final class WithdrawalFunction
{
    public const FEATURE = 'order_withdrawal';

    /** Endpunkt-Schlüssel der WooCommerce-Funktion (Slug einstellbar unter WooCommerce → Einstellungen → Erweitert). */
    public const ENDPOINT = 'order-withdrawal';

    /** Feste, sprechende Adresse (Weiterleitung auf den WooCommerce-Endpunkt). */
    public const SLUG = 'vertrag-widerrufen';

    /** Query-Parameter: Bestellung, mit der das Formular vorbefüllt wird (nur eigene Bestellungen). */
    public const PREFILL_ARG = 'bestellung';

    /** Von WooCommerce gesetzt, sobald zu einer Bestellung ein Widerruf eingegangen ist. */
    public const REQUESTED_META = '_order_withdrawal_requested';

    /** Seite der früheren eigenen Widerrufsfunktion (wird entfernt, sobald die WooCommerce-Funktion an ist). */
    private const LEGACY_PAGE_OPTION = 'bp_learning_withdrawal_page';

    /** Widerrufe der früheren eigenen Funktion: Liste mit time, name, email, order_ref, note, user_id. */
    public const LEGACY_META = '_bp_withdrawal';

    /** Gesetzt, sobald die WooCommerce-Funktion einmal automatisch eingeschaltet wurde ('notice' = Hinweis noch zeigen). */
    public const AUTOENABLED_OPTION = 'bp_learning_withdrawal_autoenabled';

    /** Widerrufsfrist in Tagen ab Vertragsschluss (§ 355 Abs. 2 BGB). */
    public const DAYS = 14;

    private const STATUSES = ['pending', 'on-hold', 'processing', 'completed'];

    public function register(): void
    {
        add_action('template_redirect', [$this, 'redirect'], 1);
        add_action('wp_footer', [$this, 'prefillScript'], 20);
        add_action('woocommerce_order_details_after_order_table', [$this, 'orderDetails'], 20);
        add_action('admin_notices', [$this, 'adminNotice']);
        add_action('admin_post_bp_enable_withdrawal', [$this, 'handleEnable']);
        add_action('admin_init', [self::class, 'autoEnable'], 5);
        add_action('admin_init', [self::class, 'removeLegacyPage']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'adminLegacy'], 20);
        add_filter('woocommerce_privacy_export_order_personal_data', [self::class, 'exportPersonalData'], 10, 2);
        add_action('woocommerce_privacy_remove_order_personal_data', [self::class, 'erasePersonalData'], 10, 1);
        // Frühere eigene Seite enthielt diesen Shortcode: Knopf statt Formular.
        add_shortcode('bootspruefung_vertrag_widerrufen', [self::class, 'shortcode']);
    }

    public static function shortcode(): string
    {
        if (self::enabled()) {
            return '<p><a class="button wp-element-button" href="'.esc_url(self::endpointUrl()).'">'.esc_html(self::buttonLabel()).'</a></p>';
        }

        return '<p>'.sprintf(
            /* translators: %s: Link „Widerrufsbelehrung“ */
            esc_html__('Das Online-Formular ist gerade nicht erreichbar. Du kannst deinen Widerruf formlos per E-Mail oder Post erklären – Kontakt und Muster-Widerrufsformular findest du in der %s.', 'bootspruefung-learning'),
            '<a href="'.esc_url(DigitalConsent::withdrawalUrl()).'">'.esc_html__('Widerrufsbelehrung', 'bootspruefung-learning').'</a>'
        ).'</p>';
    }

    /**
     * Einmalig beim ersten Admin-Aufruf nach dem Update: WooCommerce-Funktion einschalten (ab Werk aus).
     * Das Kennzeichen verhindert, dass ein späteres bewusstes Ausschalten wieder rückgängig gemacht wird.
     */
    public static function autoEnable(): void
    {
        if (get_option(self::AUTOENABLED_OPTION, '') !== '' || ! self::available()) {
            return; // schon erledigt bzw. WooCommerce zu alt – nach dessen Update erneut versuchen
        }
        if (self::enabled()) {
            update_option(self::AUTOENABLED_OPTION, 'yes', false);

            return;
        }
        update_option(self::AUTOENABLED_OPTION, self::enable() ? 'notice' : 'yes', false);
    }

    public static function buttonLabel(): string
    {
        return __('Vertrag hier widerrufen', 'bootspruefung-learning');
    }

    /** Ist die WooCommerce-Funktion verfügbar (WooCommerce ≥ 11.1) und eingeschaltet? */
    public static function available(): bool
    {
        return class_exists(\Automattic\WooCommerce\Internal\OrderWithdrawal\OrderWithdrawalController::class);
    }

    public static function enabled(): bool
    {
        return self::available() && class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)
            && \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled(self::FEATURE);
    }

    /** Schaltet die WooCommerce-Funktion ein. */
    public static function enable(): bool
    {
        if (! self::available() || ! function_exists('wc_get_container')) {
            return false;
        }
        $features = wc_get_container()->get(\Automattic\WooCommerce\Internal\Features\FeaturesController::class);

        return (bool) $features->change_feature_enable(self::FEATURE, true);
    }

    /**
     * Feste Adresse für Links (Fußzeile, Rechtstexte, Mails). Bei „einfachen“ Permalinks erreicht
     * /vertrag-widerrufen/ WordPress nicht (kein Rewrite) – dann direkt das Formular bzw. der Ersatz.
     */
    public static function url(): string
    {
        if ((string) get_option('permalink_structure') === '') {
            return self::endpointUrl();
        }

        return home_url('/'.self::SLUG.'/');
    }

    /**
     * Formular der WooCommerce-Funktion. Ist sie aus: Seite der früheren eigenen Funktion, falls noch
     * vorhanden, sonst die Widerrufsbelehrung (Kontakt und Muster-Formular).
     */
    public static function endpointUrl(): string
    {
        $account = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
        if (! self::enabled() || $account === '' || ! function_exists('wc_get_endpoint_url')) {
            return self::fallbackUrl();
        }

        return (string) wc_get_endpoint_url(self::ENDPOINT, '', $account);
    }

    /** Ersatzziel, solange die WooCommerce-Funktion aus ist. */
    private static function fallbackUrl(): string
    {
        $legacy = self::legacyPage();

        return $legacy !== null ? (string) get_permalink($legacy) : DigitalConsent::withdrawalUrl();
    }

    /** Noch veröffentlichte Seite /vertrag-widerrufen/ (frühere eigene Funktion oder vom Betreiber angelegt). */
    private static function legacyPage(): ?\WP_Post
    {
        $page = get_page_by_path(self::SLUG, OBJECT, 'page');

        return $page instanceof \WP_Post && $page->post_status === 'publish' ? $page : null;
    }

    /**
     * /vertrag-widerrufen/ → Formular von WooCommerce. Ist die Funktion aus: Eine noch vorhandene Seite
     * liefert WordPress selbst aus; sonst Weiterleitung zur Widerrufsbelehrung statt „Seite nicht gefunden“.
     */
    public function redirect(): void
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (trim(substr($path, strlen(rtrim($home, '/'))), '/') !== self::SLUG) {
            return;
        }
        if (! self::enabled() && self::legacyPage() !== null) {
            return;
        }
        nocache_headers();
        wp_safe_redirect(self::enabled() ? self::endpointUrl() : DigitalConsent::withdrawalUrl(), 302, 'Bootspruefung');
        exit;
    }

    /**
     * Ende der Widerrufsfrist (Ende des 14. Tages, Zeitzone der Website) oder null. Fristbeginn ist der
     * Vertragsschluss; bei später bezahlten Bestellungen (z. B. vom Admin angelegt) zählt zugunsten
     * der Kunden der Zahlungseingang.
     */
    public static function deadline(\WC_Order $order): ?int
    {
        $created = $order->get_date_created();
        if (! $created) {
            return null;
        }
        $paid = $order->get_date_paid();
        $start = max($created->getTimestamp(), $paid ? $paid->getTimestamp() : 0);
        $day = (new \DateTimeImmutable('@'.$start))->setTimezone(wp_timezone())->setTime(23, 59, 59);

        return $day->modify('+'.self::DAYS.' days')->getTimestamp();
    }

    /** „Vertrag hier widerrufen“ für diese Bestellung anbieten? (innerhalb der Frist, nicht storniert/erstattet) */
    public static function offered(\WC_Order $order): bool
    {
        $deadline = self::deadline($order);

        return $deadline !== null && time() <= $deadline && in_array($order->get_status(), self::STATUSES, true);
    }

    /** Widerruf eingegangen – über die WooCommerce-Funktion oder die frühere eigene Funktion? */
    public static function requested(\WC_Order $order): bool
    {
        return $order->get_meta(self::REQUESTED_META, true) === 'yes' || self::legacyEntries($order) !== [];
    }

    /**
     * Widerrufe aus der früheren eigenen Funktion.
     *
     * @return list<array<string,mixed>>
     */
    public static function legacyEntries(\WC_Order $order): array
    {
        $value = $order->get_meta(self::LEGACY_META, true);

        return is_array($value) ? array_values(array_filter($value, static fn ($e) => is_array($e) && ! empty($e['time']))) : [];
    }

    /** Bestell-Admin: früher eingegangene Widerrufe weiter anzeigen (nur lesend). */
    public function adminLegacy($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        foreach (self::legacyEntries($order) as $entry) {
            echo '<p class="form-field form-field-wide bp-withdrawal-admin" style="clear:both"><strong>'.esc_html__('Widerruf (frühere Widerrufsfunktion):', 'bootspruefung-learning').'</strong><br>'
                .esc_html(sprintf(
                    /* translators: 1: Datum und Uhrzeit, 2: Name, 3: E-Mail-Adresse */
                    __('Eingegangen am %1$s von %2$s <%3$s>.', 'bootspruefung-learning'),
                    self::formatTime((int) $entry['time']),
                    (string) ($entry['name'] ?? ''),
                    (string) ($entry['email'] ?? '')
                ))
                .'<br>'.esc_html__('Bitte prüfen und ggf. über „Erstattung“ zurückzahlen – der Zugang endet dann automatisch.', 'bootspruefung-learning').'</p>';
        }
    }

    /**
     * WooCommerce-Datenexport einer Bestellung: frühere Widerrufe und Zustimmung zum vorzeitigen Beginn.
     *
     * @param array<int,array{name:string,value:string}> $data
     * @return array<int,array{name:string,value:string}>
     */
    public static function exportPersonalData($data, $order)
    {
        if (! is_array($data) || ! $order instanceof \WC_Order) {
            return $data;
        }
        foreach (self::legacyEntries($order) as $entry) {
            $parts = [
                sprintf(__('Eingang: %s', 'bootspruefung-learning'), self::formatTime((int) $entry['time'])),
                sprintf(__('Name: %s', 'bootspruefung-learning'), (string) ($entry['name'] ?? '')),
                sprintf(__('E-Mail: %s', 'bootspruefung-learning'), (string) ($entry['email'] ?? '')),
                sprintf(__('Bestellangabe: %s', 'bootspruefung-learning'), (string) ($entry['order_ref'] ?? '')),
            ];
            if ((string) ($entry['note'] ?? '') !== '') {
                $parts[] = sprintf(__('Anmerkung: %s', 'bootspruefung-learning'), (string) $entry['note']);
            }
            $data[] = ['name' => __('Widerruf (frühere Widerrufsfunktion)', 'bootspruefung-learning'), 'value' => implode('; ', $parts)];
        }
        $consent = DigitalConsent::consentFor($order);
        if ($consent !== null) {
            $data[] = [
                'name' => __('Zustimmung zum vorzeitigen Beginn (digitale Inhalte)', 'bootspruefung-learning'),
                'value' => sprintf(__('Zeitpunkt: %1$s; Quelle: %2$s; Wortlaut: %3$s', 'bootspruefung-learning'), self::formatTime($consent['time']), DigitalConsent::sourceLabel($consent['source']), $consent['text']),
            ];
        }

        return $data;
    }

    /** WooCommerce-Datenlöschung einer Bestellung: Name, E-Mail und Anmerkung früherer Widerrufe anonymisieren, Zeitpunkt bleibt. */
    public static function erasePersonalData($order): void
    {
        if (! $order instanceof \WC_Order) {
            return;
        }
        $entries = self::legacyEntries($order);
        if ($entries === []) {
            return;
        }
        foreach ($entries as &$entry) {
            $entry['name'] = function_exists('wp_privacy_anonymize_data') ? wp_privacy_anonymize_data('text', (string) ($entry['name'] ?? '')) : '';
            $entry['email'] = function_exists('wp_privacy_anonymize_data') ? wp_privacy_anonymize_data('email', (string) ($entry['email'] ?? '')) : '';
            $entry['note'] = '';
            $entry['user_id'] = 0;
        }
        unset($entry);
        $order->update_meta_data(self::LEGACY_META, $entries);
        $order->save();
    }

    /** Abschnitt unter „Mein Lernen“: Bestellungen innerhalb der Widerrufsfrist. */
    public static function renderAccountSection(int $userId): void
    {
        if (! self::enabled()) {
            return;
        }
        $rows = [];
        foreach (wc_get_orders(['customer_id' => $userId, 'limit' => 20, 'status' => self::STATUSES, 'orderby' => 'date', 'order' => 'DESC']) as $order) {
            if (! $order instanceof \WC_Order || ! self::offered($order)) {
                continue;
            }
            $action = self::requested($order)
                ? esc_html__('Widerruf eingegangen', 'bootspruefung-learning')
                : self::button($order);
            $rows[] = sprintf(
                '<tr><td data-title="%s">#%s</td><td data-title="%s">%s</td><td class="actions bp-withdrawal-action">%s</td></tr>',
                esc_attr__('Bestellung', 'bootspruefung-learning'),
                esc_html($order->get_order_number()),
                esc_attr__('Widerrufsfrist bis', 'bootspruefung-learning'),
                esc_html(Labels::date(gmdate('Y-m-d H:i:s', (int) self::deadline($order)))),
                $action
            );
        }
        if ($rows === []) {
            return;
        }
        echo '<h3>'.esc_html__('Widerruf', 'bootspruefung-learning').'</h3>';
        echo '<p>'.esc_html__('Im Formular gibst du deinen Namen, deine E-Mail-Adresse und die Bestellnummer an; die Eingangsbestätigung kommt per E-Mail.', 'bootspruefung-learning').'</p>';
        // Schmale Bildschirme (woocommerce-smallscreen.css): Zeilen gestapelt mit Beschriftung je Zelle, der Knopf in einer eigenen
        // Zeile darunter – in drei Spalten ragte „Vertrag hier widerrufen“ bei 390 px aus der Tabelle (UX-09).
        echo '<div class="bp-table-scroll" style="overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch"><table class="woocommerce-table shop_table shop_table_responsive bp-withdrawal-table"><thead><tr><th>'.esc_html__('Bestellung', 'bootspruefung-learning').'</th><th>'.esc_html__('Widerrufsfrist bis', 'bootspruefung-learning').'</th><th><span class="screen-reader-text">'.esc_html__('Aktion', 'bootspruefung-learning').'</span></th></tr></thead><tbody>'.implode('', $rows).'</tbody></table></div>';
    }

    public function orderDetails($order): void
    {
        if (! $order instanceof \WC_Order || ! self::enabled() || ! self::offered($order)) {
            return;
        }
        if (self::requested($order)) {
            echo '<p class="bp-withdrawal-note">'.esc_html__('Dein Widerruf zu dieser Bestellung ist bei uns eingegangen.', 'bootspruefung-learning').'</p>';

            return;
        }
        echo '<p>'.self::button($order).'</p>'; // phpcs:ignore
    }

    private static function button(?\WC_Order $order = null): string
    {
        $url = self::endpointUrl();
        if ($order && self::enabled()) {
            $url = add_query_arg(self::PREFILL_ARG, (int) $order->get_id(), $url);
        }

        return '<a class="button bp-withdrawal-button" href="'.esc_url($url).'">'.esc_html(self::buttonLabel()).'</a>';
    }

    /**
     * WooCommerce-Formular für angemeldete Kunden vorbefüllen (WooCommerce selbst bietet das nicht):
     * Vor-/Nachname und E-Mail aus der Bestellung bzw. dem Konto, die Bestellnummer, wenn der Kunde
     * über „Vertrag hier widerrufen“ einer eigenen Bestellung kommt. Nur leere Felder, nur im ersten
     * Schritt (GET); alles bleibt änderbar.
     */
    public function prefillScript(): void
    {
        if (! self::enabled() || ! is_user_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
            || ! function_exists('is_account_page') || ! is_account_page() || ! function_exists('WC') || ! WC()->query
            || WC()->query->get_current_endpoint() !== self::ENDPOINT) {
            return;
        }
        $user = wp_get_current_user();
        $values = [
            'first_name' => (string) get_user_meta($user->ID, 'billing_first_name', true) ?: (string) $user->first_name,
            'last_name' => (string) get_user_meta($user->ID, 'billing_last_name', true) ?: (string) $user->last_name,
            'email' => (string) get_user_meta($user->ID, 'billing_email', true) ?: (string) $user->user_email,
            'order_number' => '',
        ];
        $order = isset($_GET[self::PREFILL_ARG]) ? wc_get_order(absint($_GET[self::PREFILL_ARG])) : null;
        if ($order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund && (int) $order->get_customer_id() === (int) $user->ID) {
            // Für die Zuordnung zählt die Rechnungs-E-Mail der Bestellung.
            $values['first_name'] = $order->get_billing_first_name() ?: $values['first_name'];
            $values['last_name'] = $order->get_billing_last_name() ?: $values['last_name'];
            $values['email'] = $order->get_billing_email() ?: $values['email'];
            $values['order_number'] = (string) $order->get_order_number();
        }
        $values['email_confirmation'] = $values['email'];
        $fields = [];
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $fields['order_withdrawal_'.$key] = $value;
            }
        }
        if ($fields === []) {
            return;
        }
        printf(
            '<script>(function(v){Object.keys(v).forEach(function(id){var el=document.getElementById(id);if(el&&!el.value){el.value=v[id];}});})(%s);</script>',
            wp_json_encode($fields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        );
    }

    public function adminNotice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        if (self::enabled()) {
            if (get_option(self::AUTOENABLED_OPTION) === 'notice') {
                update_option(self::AUTOENABLED_OPTION, 'yes', false);
                echo '<div class="notice notice-success is-dismissible"><p><strong>'.esc_html__('Bootsprüfung: Widerrufsfunktion (§ 356a BGB) eingeschaltet.', 'bootspruefung-learning').'</strong> '
                    .esc_html__('Die WooCommerce-Funktion „Order withdrawal“ wurde beim Update einmalig eingeschaltet: „Vertrag hier widerrufen“ in der Fußzeile und unter „Mein Lernen“ führt jetzt zum Online-Formular mit Eingangsbestätigung per E-Mail. Ausschalten kannst du sie unter WooCommerce → Einstellungen → Erweitert → Funktionen; sie bleibt dann aus.', 'bootspruefung-learning').'</p></div>';
            }

            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Bootsprüfung: Widerrufsfunktion (§ 356a BGB) ist aus.', 'bootspruefung-learning').'</strong> ';
        if (! self::available()) {
            echo esc_html__('Sie braucht WooCommerce 11.1 oder neuer. Bitte WooCommerce aktualisieren.', 'bootspruefung-learning').'</p></div>';

            return;
        }
        echo esc_html__('Seit 19.06.2026 müssen Online-Shops Verbrauchern einen Button „Vertrag hier widerrufen“ mit Online-Formular anbieten. WooCommerce bringt die Funktion mit, sie ist aber ausgeschaltet. Der Link „Vertrag hier widerrufen“ in der Fußzeile führt solange nur zur Widerrufsbelehrung (Kontakt und Muster-Formular) – ein Online-Formular gibt es erst, wenn du die Funktion einschaltest.', 'bootspruefung-learning').'</p>'
            .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><p>'
            .wp_nonce_field('bp_enable_withdrawal', '_wpnonce', true, false)
            .'<input type="hidden" name="action" value="bp_enable_withdrawal">'
            .'<button type="submit" class="button button-primary">'.esc_html__('Widerrufsfunktion einschalten', 'bootspruefung-learning').'</button></p></form></div>';
    }

    public function handleEnable(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Keine Berechtigung.', 'bootspruefung-learning'), '', ['response' => 403]);
        }
        check_admin_referer('bp_enable_withdrawal');
        self::enable();
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** Seite der früheren eigenen Widerrufsfunktion entfernen (die Adresse leitet jetzt weiter). */
    public static function removeLegacyPage(): void
    {
        if (! self::enabled()) {
            return; // Seite bleibt Anlaufstelle, solange es kein Online-Formular gibt
        }
        $id = (int) get_option(self::LEGACY_PAGE_OPTION, 0);
        if ($id <= 0) {
            return;
        }
        $post = get_post($id);
        if ($post instanceof \WP_Post && $post->post_type === 'page' && has_shortcode((string) $post->post_content, 'bootspruefung_vertrag_widerrufen')) {
            wp_delete_post($id, true);
        }
        delete_option(self::LEGACY_PAGE_OPTION);
    }

    private static function formatTime(int $time): string
    {
        /* translators: 1: Datum (TT.MM.JJJJ), 2: Uhrzeit */
        return sprintf(__('%1$s um %2$s Uhr', 'bootspruefung-learning'), (string) wp_date('d.m.Y', $time), (string) wp_date('H:i:s', $time));
    }
}
