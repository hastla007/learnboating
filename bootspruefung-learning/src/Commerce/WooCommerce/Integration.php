<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce\WooCommerce;

use Bootspruefung\Learning\Commerce\EntitlementPlanner;
use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Commerce\Labels;
use Bootspruefung\Learning\Commerce\OrderSync;
use Bootspruefung\Learning\Commerce\Plan;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Frontend\Router;
use Bootspruefung\Learning\Security\Capabilities;

/**
 * WooCommerce-Adapter (Kap. 7): reagiert auf Bestell-, Zahlungs- und
 * Erstattungsereignisse, erzwingt ein Kundenkonto für Lernprodukte und zeigt
 * Zugänge in Mein Konto, Bestellbestätigung, E-Mail und Bestell-Admin.
 * Kein eigenes Payment Gateway; keine Zahlungsdaten im Plugin.
 */
final class Integration
{
    public const ACCOUNT_ENDPOINT = 'mein-lernen';

    private static ?OrderSync $sync = null;

    public static function active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    public static function sync(): OrderSync
    {
        global $wpdb;
        if (self::$sync === null) {
            self::$sync = new OrderSync(new WooOrderSource(), new ProductAccessRepository($wpdb), new EntitlementService($wpdb), new \Bootspruefung\Learning\Commerce\EntitlementPlanner(), new \Bootspruefung\Learning\Commerce\GroupService($wpdb));
        }

        return self::$sync;
    }

    public static function register(): void
    {
        if (! self::active()) {
            return;
        }
        $self = new self();

        // Bestellereignisse → vollständiger, idempotenter Abgleich der Bestellung.
        $sync = static function ($orderId): void {
            self::sync()->sync((int) $orderId);
        };
        add_action('woocommerce_order_status_changed', $sync, 20, 1);
        add_action('woocommerce_payment_complete', $sync, 20, 1);
        add_action('woocommerce_order_refunded', $sync, 20, 1);
        add_action('woocommerce_refund_deleted', static fn ($refundId, $orderId) => self::sync()->sync((int) $orderId), 20, 2);
        add_action('woocommerce_process_shop_order_meta', $sync, 60, 1);
        // Bestellung endgültig gelöscht: Zugänge und Gruppen widerrufen (der Abgleich findet sie danach nicht mehr).
        $deleted = static function ($orderId): void {
            static $done = [];
            if (isset($done[(int) $orderId])) {
                return; // CPT und HPOS-Sync lösen beide Hooks aus
            }
            $done[(int) $orderId] = true;
            self::sync()->revokeOrder((int) $orderId, __('Bestellung gelöscht', 'bootspruefung-learning'));
        };
        add_action('woocommerce_before_delete_order', $deleted, 10, 1);
        add_action('before_delete_post', static function ($postId) use ($deleted): void {
            if (in_array(get_post_type((int) $postId), ['shop_order', 'shop_order_placehold'], true)) {
                $deleted($postId);
            }
        }, 10, 1);

        // Öffentliche Hooks für Gateway-spezifische Ereignisse (z. B. Dispute-Webhooks).
        add_action('bootspruefung_suspend_order', static fn ($orderId, $reason = '') => self::sync()->suspendOrder((int) $orderId, (string) ($reason ?: __('Zahlungskonflikt gemeldet', 'bootspruefung-learning'))), 10, 2);
        add_action('bootspruefung_reactivate_order', static fn ($orderId, $reason = '') => self::sync()->releaseOrder((int) $orderId, (string) ($reason ?: __('Zahlungskonflikt geklärt', 'bootspruefung-learning'))), 10, 2);
        add_action('bootspruefung_entitlements_changed', [$self, 'orderNote'], 10, 2);

        // Checkout: Konto erforderlich, Lernprodukte nur einzeln.
        add_filter('woocommerce_is_sold_individually', [$self, 'soldIndividually'], 20, 2);
        add_filter('woocommerce_checkout_registration_required', [$self, 'registrationRequired'], 20);
        add_filter('woocommerce_checkout_registration_enabled', static fn ($enabled) => self::cartHasLearning() ? true : $enabled, 20);
        // Checkout-Block liest die Optionen direkt (nicht die Filter oben): für Warenkörbe mit
        // Lernprodukten Gastbestellung aus- und Kontoanlage an der Kasse einschalten.
        add_filter('option_woocommerce_enable_guest_checkout', static fn ($value) => self::cartHasLearningSafe() ? 'no' : $value, 20);
        add_filter('option_woocommerce_enable_signup_and_login_from_checkout', static fn ($value) => self::cartHasLearningSafe() ? 'yes' : $value, 20);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$self, 'blocksAccountGuard'], 10, 2);
        // Der Checkout-Block liest die Gast-Option nicht über diese Filter: Gäste mit Lernprodukt
        // sehen statt Formular und Bestellknopf einen Konto-Hinweis mit Anmeldung.
        add_filter('render_block_woocommerce/checkout', [$self, 'blockCheckoutForGuests'], 20, 1);

        // Kunde: Mein Konto, Danke-Seite, E-Mail.
        add_filter('woocommerce_get_query_vars', static fn (array $vars) => $vars + [self::ACCOUNT_ENDPOINT => self::ACCOUNT_ENDPOINT]);
        add_filter('woocommerce_account_menu_items', [$self, 'accountMenu']);
        add_action('woocommerce_account_'.self::ACCOUNT_ENDPOINT.'_endpoint', [$self, 'renderAccount']);
        add_filter('woocommerce_endpoint_'.self::ACCOUNT_ENDPOINT.'_title', static fn () => __('Mein Lernen', 'bootspruefung-learning'));
        add_action('woocommerce_thankyou', [$self, 'thankYou'], 5);
        add_action('woocommerce_email_after_order_table', [$self, 'email'], 10, 4);

        // Admin: Zugänge je Position, Bestellaktionen.
        add_action('woocommerce_after_order_itemmeta', [$self, 'itemMeta'], 10, 3);
        add_filter('woocommerce_order_actions', [$self, 'orderActions'], 10, 2);
        add_action('woocommerce_order_actions_end', [$self, 'orderActionsReason']);
        foreach (['sync', 'approve', 'suspend', 'release'] as $action) {
            add_action('woocommerce_order_action_bp_access_'.$action, static fn ($order) => $self->orderAction($action, $order));
        }

        (new ProductPanel())->register();
        (new MemberDiscounts())->register();
        (new MoneyBackWoo())->register();
        (new SimUpgrade())->register();
        (new VideoUpgrade())->register();
        (new PriceNote())->register();
        (new DigitalConsent())->register();
        (new WithdrawalFunction())->register();
        (new WithdrawalTexts())->register();
    }

    // ------------------------------------------------------------------ Checkout

    public function soldIndividually($value, $product)
    {
        if ($product instanceof \WC_Product && self::productHasLearning($product) && ! self::isGroupProduct($product)) {
            return true;
        }

        return $value;
    }

    public function registrationRequired($required)
    {
        return self::cartHasLearning() ? true : $required;
    }

    /** Checkout-Block (Store API): Gastbestellung mit Lernprodukt ablehnen. */
    public function blocksAccountGuard($order, $request): void
    {
        if (! $order instanceof \WC_Order || $order->get_customer_id() > 0 || is_user_logged_in()) {
            return;
        }
        if (is_object($request) && method_exists($request, 'get_param') && $request->get_param('create_account')) {
            return;
        }
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && $this->products()->hasAccess((int) $item->get_product_id(), (int) $item->get_variation_id())) {
                $message = __('Für Lernzugänge ist ein Kundenkonto erforderlich. Bitte melde dich an oder lege beim Bezahlen ein Konto an.', 'bootspruefung-learning');
                if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
                    throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('bootspruefung_account_required', $message, 400);
                }
                throw new \RuntimeException($message);
            }
        }
    }

    /** Checkout-Block für Gäste mit Lernprodukt: Hinweis, Anmeldeformular, Link zur Registrierung. */
    public function blockCheckoutForGuests($content)
    {
        if (is_user_logged_in() || is_admin() || ! self::cartHasLearning()) {
            return $content;
        }
        $checkout = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
        $html = '<div class="bp-checkout-account woocommerce">';
        $html .= '<div class="woocommerce-info" role="status">'.esc_html__('Für Lernzugänge ist ein Kundenkonto erforderlich: Dein Zugang wird deinem Konto zugeordnet. Bitte melde dich an oder lege ein Konto an – danach geht es hier weiter.', 'bootspruefung-learning').'</div>';
        if (function_exists('woocommerce_login_form')) {
            ob_start();
            woocommerce_login_form(['message' => '', 'redirect' => $checkout, 'hidden' => false]);
            $html .= (string) ob_get_clean();
        } else {
            $html .= '<p><a class="button" href="'.esc_url(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::loginUrl($checkout)).'">'.esc_html__('Anmelden', 'bootspruefung-learning').'</a></p>';
        }
        if (get_option('woocommerce_enable_myaccount_registration') === 'yes' || get_option('users_can_register')) {
            $html .= '<p><a class="button bp-checkout-register" href="'.esc_url(\Bootspruefung\Learning\Frontend\ReturnAfterLogin::registrationUrl($checkout)).'">'.esc_html__('Neues Kundenkonto anlegen', 'bootspruefung-learning').'</a></p>';
        }

        return $html.'</div>';
    }

    /** Wie cartHasLearning(), aber ohne Rekursion, falls der Warenkorb beim Laden selbst Optionen liest. */
    private static function cartHasLearningSafe(): bool
    {
        static $busy = false;
        if ($busy || is_admin() && ! wp_doing_ajax() || ! did_action('woocommerce_init')) {
            return false;
        }
        $busy = true;
        try {
            return self::cartHasLearning();
        } finally {
            $busy = false;
        }
    }

    public static function cartHasLearning(): bool
    {
        if (! function_exists('WC') || WC()->cart === null) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $line) {
            if (isset($line['data']) && $line['data'] instanceof \WC_Product && self::productHasLearning($line['data'])) {
                return true;
            }
        }

        return false;
    }

    /** Gruppenlizenzen dürfen in Mengen gekauft werden (Menge = Anzahl Pakete). */
    public static function isGroupProduct(\WC_Product $product): bool
    {
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        $rows = $product->is_type('variation') ? $repo->forItem((int) $product->get_parent_id(), (int) $product->get_id()) : $repo->rows((int) $product->get_id(), 0, true);

        return ProductAccessRepository::seats($rows) !== null;
    }

    public static function productHasLearning(\WC_Product $product): bool
    {
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        if ($product->is_type('variation')) {
            return $repo->hasAccess((int) $product->get_parent_id(), (int) $product->get_id());
        }
        if ($repo->rows((int) $product->get_id(), 0, true) !== []) {
            return true;
        }
        // Variables Produkt: Zuordnung nur an Variationen.
        foreach ($product->get_children() as $childId) {
            if ($repo->rows((int) $product->get_id(), (int) $childId, true) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kaufbare Produkte für gesperrte Features (Sperrhinweis im Lernbereich).
     *
     * @param array<string,bool> $features
     * @return array<string,list<array{name:string,url:string,price:string}>>
     */
    public static function offers(string $courseCode, array $features): array
    {
        if (! self::active()) {
            return [];
        }
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        $out = [];
        foreach ($features as $feature => $allowed) {
            if ($allowed) {
                continue;
            }
            $seen = [];
            foreach ($repo->grantingProducts($courseCode, $feature) as $ref) {
                $product = wc_get_product($ref['variation_id'] ?: $ref['product_id']);
                $parent = $product && $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
                // Nicht im Shop gelistete Produkte (z. B. Checkout-Upgrade, nur mit Funkkurs kaufbar) nicht anbieten.
                if (! $product || ! $parent || $parent->get_status() !== 'publish' || ! $product->is_purchasable() || isset($seen[$parent->get_id()]) || in_array($parent->get_catalog_visibility(), ['hidden', 'search'], true)) {
                    continue;
                }
                $seen[$parent->get_id()] = true;
                $out[$feature][] = ['name' => $parent->get_name(), 'url' => (string) $parent->get_permalink(), 'price' => html_entity_decode(wp_strip_all_tags((string) $parent->get_price_html()), ENT_QUOTES, 'UTF-8')];
            }
        }

        return $out;
    }

    public static function accountUrl(): ?string
    {
        return self::active() && function_exists('wc_get_account_endpoint_url') ? (string) wc_get_account_endpoint_url(self::ACCOUNT_ENDPOINT) : null;
    }

    // ------------------------------------------------------------------ Kundenansichten

    /** @param array<string,string> $items */
    public function accountMenu(array $items): array
    {
        $out = [];
        foreach ($items as $key => $label) {
            $out[$key] = $label;
            if ($key === 'dashboard') {
                $out[self::ACCOUNT_ENDPOINT] = __('Mein Lernen', 'bootspruefung-learning');
            }
        }

        return isset($out[self::ACCOUNT_ENDPOINT]) ? $out : [self::ACCOUNT_ENDPOINT => __('Mein Lernen', 'bootspruefung-learning')] + $out;
    }

    public function renderAccount(): void
    {
        $rows = $this->entitlements()->summaryForUser(get_current_user_id());
        echo '<p><a class="button" href="'.esc_url(Router::url()).'">'.esc_html__('Zum Lernbereich', 'bootspruefung-learning').'</a>';
        if ((self::sync()->groups()?->forOwner(get_current_user_id()) ?? []) !== []) {
            echo ' <a class="button" href="'.esc_url(Router::url('gruppen')).'">'.esc_html__('Meine Gruppen verwalten', 'bootspruefung-learning').'</a>';
        }
        echo '</p>';
        if ($rows === []) {
            echo '<p>'.esc_html__('Für dein Konto sind noch keine Lernzugänge gebucht.', 'bootspruefung-learning').'</p>';
            MoneyBackWoo::renderAccountSection(get_current_user_id());
            WithdrawalFunction::renderAccountSection(get_current_user_id());

            return;
        }
        echo '<table class="woocommerce-table shop_table bp-access-table"><thead><tr><th>'.esc_html__('Kurs', 'bootspruefung-learning').'</th><th>'.esc_html__('Umfang', 'bootspruefung-learning').'</th><th>'.esc_html__('Status', 'bootspruefung-learning').'</th><th>'.esc_html__('Gültig bis', 'bootspruefung-learning').'</th></tr></thead><tbody>';
        // Eine Zeile je Kurs/Status/Laufzeit statt je Funktion; reine Simulator-Zugänge (SRC/UBI-Hilfskurse) als eine Zeile.
        $groups = [];
        $sim = [];
        foreach ($rows as $row) {
            $key = $row['course'].'|'.$row['status'].'|'.$row['valid_until'];
            $groups[$key] ??= ['course' => $row['course'], 'status' => $row['status'], 'valid_until' => $row['valid_until'], 'features' => []];
            $groups[$key]['features'][] = (string) $row['feature'];
        }
        foreach ($groups as $key => $g) {
            if ($g['features'] === [\Bootspruefung\Learning\Learning\Services\AccessService::DEVICE]) {
                $simKey = $g['status'].'|'.$g['valid_until'];
                $sim[$simKey] ??= $g;
                unset($groups[$key]);
            }
        }
        $all = \Bootspruefung\Learning\Learning\Services\AccessService::FEATURES;
        $scope = static function (array $features) use ($all): string {
            $features = array_values(array_unique($features));
            if (in_array('all', $features, true) || array_diff($all, $features) === []) {
                return __('Alle Inhalte', 'bootspruefung-learning');
            }
            if (array_diff(array_diff($all, [\Bootspruefung\Learning\Learning\Services\AccessService::VIDEO]), $features) === []) {
                return __('Alle Inhalte außer Videokurs', 'bootspruefung-learning');
            }

            return implode(', ', array_map(static fn ($f) => Labels::feature($f), $features));
        };
        foreach ($groups as $g) {
            $course = Labels::course($g['course']);
            $cell = $g['status'] === EntitlementPlanner::ACTIVE
                ? '<a href="'.esc_url(Router::url(ContentRepository::slug($g['course']))).'">'.esc_html($course).'</a>'
                : esc_html($course);
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $cell, esc_html($scope($g['features'])), esc_html(Labels::status($g['status'])), esc_html(Labels::date($g['valid_until'])));
        }
        foreach ($sim as $g) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html__('Funkgerät-Simulator (SRC/UBI)', 'bootspruefung-learning'), esc_html(Labels::feature(\Bootspruefung\Learning\Learning\Services\AccessService::DEVICE)), esc_html(Labels::status($g['status'])), esc_html(Labels::date($g['valid_until'])));
        }
        echo '</tbody></table>';
        MoneyBackWoo::renderAccountSection(get_current_user_id());
        WithdrawalFunction::renderAccountSection(get_current_user_id());
    }

    public function thankYou($orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }
        self::sync()->sync((int) $orderId, 'thankyou'); // fängt verspätete Webhooks nicht ab, schadet aber nicht (idempotent)
        echo $this->accessNotice((int) $orderId, false); // phpcs:ignore WordPress.Security.EscapeOutput -- in accessNotice escaped
    }

    public function email($order, $sentToAdmin = false, $plainText = false, $email = null): void
    {
        if ($sentToAdmin || ! $order instanceof \WC_Order) {
            return;
        }
        // WooCommerce verschickt die Mail vor unserem Abgleich (Statushooks) – erst abgleichen, dann den Stand nennen.
        self::sync()->sync((int) $order->get_id(), 'email');
        // Erstattungsmail: Zugang (der erstatteten Kurse) endet – nicht „freigeschaltet“ melden.
        $emailId = is_object($email) && isset($email->id) ? (string) $email->id : '';
        if ($emailId === 'customer_refunded_order') {
            $partial = (float) $order->get_total_refunded() < (float) $order->get_total() - 0.009;
            $text = $partial
                ? __('Für die erstatteten Positionen endet der Lernzugang. Deine übrigen Zugänge findest du unter „Mein Lernen“.', 'bootspruefung-learning')
                : __('Mit der Erstattung endet der Lernzugang aus dieser Bestellung.', 'bootspruefung-learning');
            echo $plainText ? esc_html($text)."\n\n" : '<section class="woocommerce-order-bp-access"><h2>'.esc_html__('Lernzugang', 'bootspruefung-learning').'</h2><p>'.esc_html($text).'</p></section>'; // phpcs:ignore

            return;
        }
        $notice = $this->accessNotice((int) $order->get_id(), (bool) $plainText);
        echo $plainText ? esc_html(wp_strip_all_tags($notice))."\n\n" : $notice; // phpcs:ignore
    }

    private function accessNotice(int $orderId, bool $plain): string
    {
        $rows = $this->entitlements()->forOrder($orderId);
        $groups = array_filter(self::sync()->groups()?->forOrder($orderId) ?? [], static fn ($g) => $g->status === 'active');
        $groupText = '';
        if ($groups !== []) {
            $url = Router::url('gruppen');
            $seats = array_sum(array_map(static fn ($g) => (int) $g->seats, $groups));
            $groupText = $text = esc_html(sprintf(_n('Deine Gruppenlizenz mit %d Platz ist bereit.', 'Deine Gruppenlizenz mit %d Plätzen ist bereit.', $seats, 'bootspruefung-learning'), $seats)).' '
                .($plain ? __('Teilnehmende einladen:', 'bootspruefung-learning').' '.$url : '<a href="'.esc_url($url).'">'.esc_html__('Teilnehmende einladen', 'bootspruefung-learning').'</a>');
            if ($rows === []) {
                return $plain ? $text : '<section class="woocommerce-order-bp-access"><h2>'.esc_html__('Lernzugang', 'bootspruefung-learning').'</h2><p>'.$text.'</p></section>';
            }
        }
        if ($rows === []) {
            return '';
        }
        // Nur Zugänge nennen, die wirklich nutzbar sind (z. B. kein Video-Upgrade ohne Theorie).
        $access = new \Bootspruefung\Learning\Learning\Services\AccessService();
        $active = array_filter($rows, static fn ($r) => $r->status === EntitlementPlanner::ACTIVE
            && ($r->feature_code === 'all' ? $access->full((int) $r->user_id, (string) $r->course_code, \Bootspruefung\Learning\Learning\Services\AccessService::QUESTION_BANK) : $access->full((int) $r->user_id, (string) $r->course_code, (string) $r->feature_code)));
        $pending = array_filter($rows, static fn ($r) => $r->status === EntitlementPlanner::PENDING);
        if ($active !== []) {
            $courses = array_unique(array_map(static fn ($r) => $r->course_code, $active));
            $links = array_map(static fn ($c) => $plain ? Labels::course($c).': '.Router::url(ContentRepository::slug($c)) : '<a href="'.esc_url(Router::url(ContentRepository::slug($c))).'">'.esc_html(Labels::course($c)).'</a>', $courses);
            $text = esc_html__('Dein Lernzugang ist freigeschaltet:', 'bootspruefung-learning').' '.implode($plain ? ', ' : ' · ', $links);
        } elseif ($pending !== []) {
            $text = esc_html__('Dein Lernzugang wird freigeschaltet, sobald die Zahlung eingegangen ist.', 'bootspruefung-learning');
        } else {
            $text = '';
        }
        // Kombi + Gruppenpaket in einer Bestellung: beide Hinweise.
        $parts = array_values(array_filter([$text, $groupText]));
        if ($parts === []) {
            return '';
        }

        return $plain ? implode("\n", $parts) : '<section class="woocommerce-order-bp-access"><h2>'.esc_html__('Lernzugang', 'bootspruefung-learning').'</h2><p>'.implode('</p><p>', $parts).'</p></section>';
    }

    // ------------------------------------------------------------------ Admin

    public function itemMeta($itemId, $item, $product): void
    {
        if (! is_admin() || ! $item instanceof \WC_Order_Item_Product) {
            return;
        }
        $rows = $this->entitlements()->forItem((int) $itemId);
        if ($rows === [] && ! $this->products()->hasAccess((int) $item->get_product_id(), (int) $item->get_variation_id())) {
            return;
        }
        echo '<div class="bp-item-access" style="margin-top:6px"><strong>'.esc_html__('Lernzugang', 'bootspruefung-learning').':</strong> ';
        if ($rows === []) {
            echo esc_html__('noch kein Zugang angelegt (Bestellung unbezahlt oder ohne Kundenkonto)', 'bootspruefung-learning');
        }
        $parts = [];
        foreach ($rows as $row) {
            $manual = isset(EntitlementPlanner::meta($row)['manual']) ? ' · '.__('manuell', 'bootspruefung-learning') : '';
            $parts[] = esc_html(sprintf('%s · %s · %s%s · %s', Labels::course($row->course_code), Labels::feature($row->feature_code), Labels::status($row->status), $manual, Labels::date($row->valid_until)));
        }
        echo implode('<br>', $parts); // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped
        if (current_user_can(Capabilities::MANAGE_ENTITLEMENTS) && $rows !== []) {
            $order = $item->get_order_id();
            echo ' <a href="'.esc_url(admin_url('admin.php?page=bootspruefung-access&order='.(int) $order)).'">'.esc_html__('verwalten', 'bootspruefung-learning').'</a>';
        }
        echo '</div>';
    }

    /** @param array<string,string> $actions */
    public function orderActions(array $actions, $order = null): array
    {
        if (! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            return $actions;
        }
        $actions['bp_access_sync'] = __('Lernzugang abgleichen', 'bootspruefung-learning');
        $actions['bp_access_approve'] = __('Lernzugang vor Zahlungseingang freischalten', 'bootspruefung-learning');
        $actions['bp_access_suspend'] = __('Lernzugang aussetzen (z. B. Zahlungskonflikt)', 'bootspruefung-learning');
        $actions['bp_access_release'] = __('Manuelle Lernzugang-Entscheidung aufheben', 'bootspruefung-learning');

        return $actions;
    }

    public function orderActionsReason(): void
    {
        if (current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            echo '<li class="wide"><label for="bp_access_reason" class="screen-reader-text">'.esc_html__('Begründung Lernzugang', 'bootspruefung-learning').'</label><input type="text" id="bp_access_reason" name="bp_access_reason" style="width:100%" placeholder="'.esc_attr__('Begründung (für Lernzugang-Aktionen)', 'bootspruefung-learning').'"></li>';
        }
    }

    public function orderAction(string $action, $order): void
    {
        if (! $order instanceof \WC_Order || ! current_user_can(Capabilities::MANAGE_ENTITLEMENTS)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- Woo prüft die Nonce des Bestellformulars.
        $reason = sanitize_text_field(wp_unslash($_POST['bp_access_reason'] ?? ''));
        $id = (int) $order->get_id();
        if ($action !== 'sync' && $reason === '') {
            if (class_exists('WC_Admin_Meta_Boxes')) {
                \WC_Admin_Meta_Boxes::add_error(__('Lernzugang: Bitte eine Begründung angeben.', 'bootspruefung-learning'));
            }

            return;
        }
        match ($action) {
            'sync' => self::sync()->sync($id, 'admin_order_action'),
            'approve' => self::sync()->approveOrder($id, $reason),
            'suspend' => self::sync()->suspendOrder($id, $reason),
            'release' => self::sync()->releaseOrder($id, $reason),
        };
    }

    /** Bestellnotiz für den Support, keine Zahlungsdaten. */
    public function orderNote(int $orderId, Plan $plan): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }
        $lines = [];
        foreach ($plan->changes as $c) {
            if (isset($c['to'])) {
                $lines[] = sprintf('%s/%s → %s', Labels::course((string) $c['course']), Labels::feature((string) $c['feature']), Labels::status((string) $c['to']));
            }
        }
        if ($lines !== []) {
            $order->add_order_note(__('Lernzugang:', 'bootspruefung-learning').' '.implode('; ', array_unique($lines)));
        }
    }

    private function entitlements(): EntitlementService
    {
        return self::sync()->entitlements();
    }

    private function products(): ProductAccessRepository
    {
        global $wpdb;

        return new ProductAccessRepository($wpdb);
    }
}
