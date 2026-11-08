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
 * - weist im Admin darauf hin, wenn die WooCommerce-Funktion ausgeschaltet ist (mit Knopf zum Einschalten).
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

    /** Von WooCommerce gesetzt, sobald zu einer Bestellung ein Widerruf eingegangen ist. */
    public const REQUESTED_META = '_order_withdrawal_requested';

    /** Seite der früheren eigenen Widerrufsfunktion (wird einmalig entfernt). */
    private const LEGACY_PAGE_OPTION = 'bp_learning_withdrawal_page';

    /** Widerrufsfrist in Tagen ab Vertragsschluss (§ 355 Abs. 2 BGB). */
    public const DAYS = 14;

    private const STATUSES = ['pending', 'on-hold', 'processing', 'completed'];

    public function register(): void
    {
        add_action('template_redirect', [$this, 'redirect'], 1);
        add_action('woocommerce_order_details_after_order_table', [$this, 'orderDetails'], 20);
        add_action('admin_notices', [$this, 'adminNotice']);
        add_action('admin_post_bp_enable_withdrawal', [$this, 'handleEnable']);
        add_action('admin_init', [self::class, 'removeLegacyPage']);
        // Frühere eigene Seite enthielt diesen Shortcode: Knopf statt Formular.
        add_shortcode('bootspruefung_vertrag_widerrufen', static fn () => '<p><a class="button wp-element-button" href="'.esc_url(self::endpointUrl()).'">'.esc_html(self::buttonLabel()).'</a></p>');
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

    /** Feste Adresse für Links (Fußzeile, Rechtstexte, Mails). */
    public static function url(): string
    {
        return home_url('/'.self::SLUG.'/');
    }

    /** Formular der WooCommerce-Funktion. */
    public static function endpointUrl(): string
    {
        $account = function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
        if ($account === '' || ! function_exists('wc_get_endpoint_url')) {
            return self::url();
        }

        return (string) wc_get_endpoint_url(self::ENDPOINT, '', $account);
    }

    /** /vertrag-widerrufen/ → Formular von WooCommerce. */
    public function redirect(): void
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (trim(substr($path, strlen(rtrim($home, '/'))), '/') !== self::SLUG || ! self::enabled()) {
            return;
        }
        nocache_headers();
        wp_safe_redirect(self::endpointUrl(), 302, 'Bootspruefung');
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

    public static function requested(\WC_Order $order): bool
    {
        return $order->get_meta(self::REQUESTED_META, true) === 'yes';
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
                : self::button();
            $rows[] = sprintf('<tr><td>#%s</td><td>%s</td><td>%s</td></tr>', esc_html($order->get_order_number()), esc_html(Labels::date(gmdate('Y-m-d H:i:s', (int) self::deadline($order)))), $action);
        }
        if ($rows === []) {
            return;
        }
        echo '<h3>'.esc_html__('Widerruf', 'bootspruefung-learning').'</h3>';
        echo '<p>'.esc_html__('Im Formular gibst du deinen Namen, deine E-Mail-Adresse und die Bestellnummer an; die Eingangsbestätigung kommt per E-Mail.', 'bootspruefung-learning').'</p>';
        echo '<table class="woocommerce-table shop_table bp-withdrawal-table"><thead><tr><th>'.esc_html__('Bestellung', 'bootspruefung-learning').'</th><th>'.esc_html__('Widerrufsfrist bis', 'bootspruefung-learning').'</th><th></th></tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
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
        echo '<p>'.self::button().'</p>'; // phpcs:ignore
    }

    private static function button(): string
    {
        return '<a class="button bp-withdrawal-button" href="'.esc_url(self::endpointUrl()).'">'.esc_html(self::buttonLabel()).'</a>';
    }

    public function adminNotice(): void
    {
        if (! current_user_can('manage_woocommerce') || self::enabled()) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Bootsprüfung: Widerrufsfunktion (§ 356a BGB) ist aus.', 'bootspruefung-learning').'</strong> ';
        if (! self::available()) {
            echo esc_html__('Sie braucht WooCommerce 11.1 oder neuer. Bitte WooCommerce aktualisieren.', 'bootspruefung-learning').'</p></div>';

            return;
        }
        echo esc_html__('Seit 19.06.2026 müssen Online-Shops Verbrauchern einen Button „Vertrag hier widerrufen“ anbieten. WooCommerce bringt die Funktion mit; die Fußzeile verlinkt sie, sobald sie eingeschaltet ist.', 'bootspruefung-learning').'</p>'
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
}
