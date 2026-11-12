<?php

declare(strict_types=1);

namespace LearnBoating\AffiliateConnector;

use Bootspruefung\Learning\Commerce\MoneyBack;
use Bootspruefung\Learning\Commerce\ProductAccessRepository;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Mobile\MobileApi;

/**
 * LearnBoating-spezifische Anbindung an WP Affiliate Captain (öffentlicher Vertrag v1, Konzept Kap. 13).
 *
 * Nutzt ausschließlich die öffentlichen Funktionen und Filter von Affiliate Captain und öffentliche Klassen
 * der Lernplattform. Keine Abfragen gegen Affiliate-Captain-Tabellen; Kurszugangscodes der App
 * (POST /coupons/redeem) bleiben unberührt – Partnercodes und Zugangscodes sind getrennte Welten.
 */
final class Connector
{
    public const SOURCE = 'learnboating';
    public const CONTRACT = '1.0.0';
    public const OPTION = 'lbac_settings';
    /** Query-Parameter bzw. HTTP-Header, mit dem die App den Partnercode aus dem Deep Link mitschickt. */
    public const PARAM = 'partner';
    public const HEADER = 'HTTP_X_PARTNER_CODE';

    /** Grund, warum der Connector inaktiv ist ('' = aktiv). */
    private static string $problem = '';

    public static function boot(): void
    {
        self::$problem = self::diagnose();
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_lbac_save', [self::class, 'save']);
        if (self::$problem !== '') {
            return; // Kontrolliert deaktiviert: keine Filter registrieren.
        }
        add_filter('wpac_registered_integrations', [self::class, 'registerIntegration']);
        add_filter('wpac_commission_base', [self::class, 'commissionBase'], 10, 3);
        add_filter('wpac_hold_until', [self::class, 'holdUntil'], 10, 2);
        add_filter('bootspruefung_shop_offer_url', [self::class, 'offerUrl'], 10, 1);
    }

    /** Leerer String, wenn alles kompatibel ist; sonst verständliche Fehlermeldung. */
    public static function diagnose(): string
    {
        if (! function_exists('wpac_contract_version') || ! function_exists('wpac_resolve_partner_code')) {
            return __('WP Affiliate Captain ist nicht aktiv.', 'learnboating-affiliate-connector');
        }
        if (strtok((string) wpac_contract_version(), '.') !== strtok(self::CONTRACT, '.')) {
            /* translators: 1: installierte Vertragsversion, 2: unterstützte Version */
            return sprintf(__('WP Affiliate Captain meldet Vertragsversion %1$s; unterstützt wird %2$s.x.', 'learnboating-affiliate-connector'), (string) wpac_contract_version(), strtok(self::CONTRACT, '.'));
        }
        if (! class_exists(ShopCatalog::class) || ! class_exists(MoneyBack::class) || ! class_exists(ProductAccessRepository::class)) {
            return __('Die Bootsprüfung-Lernplattform ist nicht aktiv oder zu alt.', 'learnboating-affiliate-connector');
        }

        return '';
    }

    public static function active(): bool
    {
        return self::$problem === '';
    }

    /** @return array{exclude_groups:bool,exclude_upgrades:bool,exclude_skus:string,withdrawal_days:int,buffer_days:int,app_handover:bool,app_campaign:string} */
    public static function settings(): array
    {
        $saved = get_option(self::OPTION, []);
        $defaults = ['exclude_groups' => true, 'exclude_upgrades' => true, 'exclude_skus' => '', 'withdrawal_days' => 14, 'buffer_days' => 2, 'app_handover' => true, 'app_campaign' => 'app'];

        return array_merge($defaults, is_array($saved) ? array_intersect_key($saved, $defaults) : []);
    }

    /* ------------------------------------------------------------------ Affiliate Captain → Connector */

    public static function registerIntegration($list): array
    {
        $list = is_array($list) ? $list : [];
        $list[self::SOURCE] = ['name' => 'LearnBoating-Connector', 'version' => LBAC_VERSION, 'contract' => self::CONTRACT, 'active' => true];

        return $list;
    }

    /**
     * `wpac_commission_base`: Gruppenpakete, Upgrades (Order Bumps) und konfigurierte Artikelnummern sind
     * nicht provisionsfähig. Andere Quellen als WooCommerce bleiben unverändert.
     */
    public static function commissionBase($base, $item, $sale)
    {
        if (! is_array($item) || ! is_array($sale) || ($sale['source'] ?? '') !== 'woocommerce' || (int) $base <= 0) {
            return $base;
        }
        $reason = self::exclusionReason($item);

        return $reason === '' ? $base : 0;
    }

    /** Ausschlussgrund einer Bestellposition ('' = provisionsfähig). */
    public static function exclusionReason(array $item): string
    {
        $settings = self::settings();
        $productId = (int) ($item['product_id'] ?? 0);
        $parentId = (int) ($item['parent_id'] ?? 0);
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        if ($sku === '' && $productId && function_exists('wc_get_product') && ($product = wc_get_product($productId))) {
            $sku = strtoupper((string) $product->get_sku());
        }
        $def = $sku !== '' ? ShopCatalog::find($sku) : null;

        if ($settings['exclude_upgrades'] && ($sku === ShopCatalog::SIM_UPGRADE || in_array($sku, ShopCatalog::videoUpgradeSkus(), true) || ($def['tier'] ?? '') === 'upgrade' || ($def['group'] ?? '') === 'videoupgrade')) {
            return 'upgrade';
        }
        if ($settings['exclude_groups'] && ((($def['group'] ?? '') === 'groups') || (int) ($def['seats'] ?? 0) > 0 || self::hasSeats($productId, $parentId))) {
            return 'group';
        }
        foreach (array_filter(array_map('trim', explode(',', strtoupper($settings['exclude_skus'])))) as $pattern) {
            if (str_ends_with($pattern, '*') ? str_starts_with($sku, substr($pattern, 0, -1)) : $sku === $pattern) {
                return 'sku';
            }
        }

        return '';
    }

    /** Gruppenpakete erkennt die Plattform an Plätzen in der Produktfreischaltung. */
    private static function hasSeats(int $productId, int $parentId): bool
    {
        global $wpdb;
        if (! $productId) {
            return false;
        }
        $repo = new ProductAccessRepository($wpdb);
        $base = $parentId ?: $productId;
        $variation = $parentId ? $productId : 0;

        return ProductAccessRepository::seats($repo->rows($base, $variation)) !== null;
    }

    /**
     * `wpac_hold_until`: Provision erst nach dem späteren Ende von Geld-zurück-Garantie (je Produkt) und
     * Widerrufsfrist freigeben, plus Puffer für späte Rückbuchungen.
     */
    public static function holdUntil($timestamp, $sale)
    {
        if (! is_array($sale) || ($sale['source'] ?? '') !== 'woocommerce') {
            return $timestamp;
        }
        $settings = self::settings();
        $paid = ! empty($sale['paid_at']) ? (int) strtotime($sale['paid_at'].' UTC') : time();
        $days = max(0, (int) $settings['withdrawal_days']);
        foreach ((array) ($sale['items'] ?? []) as $item) {
            $productId = (int) (($item['parent_id'] ?? 0) ?: ($item['product_id'] ?? 0));
            $policy = $productId ? MoneyBack::policy($productId) : null;
            if ($policy) {
                $days = max($days, (int) $policy['days']);
            }
        }
        $until = $paid + ($days + max(0, (int) $settings['buffer_days'])) * DAY_IN_SECONDS;

        return max((int) $timestamp, $until);
    }

    /* ------------------------------------------------------------------ App → Shop */

    /**
     * Kauf-URLs der Mobile-API: einen gültigen Partnercode aus dem App-Deep-Link anhängen, damit er bis zur
     * Kurs-Shopseite erhalten bleibt. Ungültige oder gesperrte Codes werden nicht weitergegeben.
     */
    public static function offerUrl($url)
    {
        if (! self::settings()['app_handover'] || ! is_string($url) || $url === '' || ! self::isMobileRequest()) {
            return $url;
        }
        $code = self::requestCode();
        if ($code === '') {
            return $url;
        }
        $args = [self::PARAM => rawurlencode($code)];
        // Kampagne „app“ macht App-Käufe in den Kampagnenberichten von Affiliate Captain sichtbar.
        $campaign = (string) self::settings()['app_campaign'];
        if ($campaign !== '' && ! str_contains($url, 'utm_campaign=')) {
            $args['utm_campaign'] = rawurlencode($campaign);
        }

        return add_query_arg($args, $url);
    }

    /** Partnercode der aktuellen App-Anfrage – nur wenn Affiliate Captain ihn als aktiv auflöst. */
    public static function requestCode(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur lesend, wird gegen Affiliate Captain geprüft.
        $raw = isset($_GET[self::PARAM]) ? (string) wp_unslash($_GET[self::PARAM]) : (string) ($_SERVER[self::HEADER] ?? '');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $raw) ?? '');
        if ($code === '' || strlen($code) > 64) {
            return '';
        }
        $resolved = wpac_resolve_partner_code($code, ['context' => 'app', 'source' => self::SOURCE]);

        return is_array($resolved) && ! empty($resolved['code']) ? (string) $resolved['code'] : '';
    }

    private static function isMobileRequest(): bool
    {
        if (! defined('REST_REQUEST') || ! REST_REQUEST) {
            return false;
        }
        $route = (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? '');

        return class_exists(MobileApi::class) && ($route === '/'.MobileApi::NAMESPACE || str_starts_with($route, '/'.MobileApi::NAMESPACE.'/'));
    }

    /* ------------------------------------------------------------------ Admin */

    public static function notice(): void
    {
        if (self::$problem === '' || ! current_user_can('activate_plugins')) {
            return;
        }
        printf('<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>', esc_html__('LearnBoating-Partnerprogramm-Connector inaktiv:', 'learnboating-affiliate-connector'), esc_html(self::$problem), esc_html__('Bestellungen werden weiter von Affiliate Captain erfasst, aber ohne LearnBoating-Regeln.', 'learnboating-affiliate-connector'));
    }

    public static function menu(): void
    {
        add_options_page(__('LearnBoating-Partnerprogramm', 'learnboating-affiliate-connector'), __('LearnBoating-Partner', 'learnboating-affiliate-connector'), 'manage_options', 'lbac', [self::class, 'page']);
    }

    public static function page(): void
    {
        $s = self::settings();
        echo '<div class="wrap"><h1>'.esc_html__('LearnBoating-Partnerprogramm', 'learnboating-affiliate-connector').'</h1>';
        echo '<p>'.(self::$problem === '' ? '✅ '.esc_html__('Verbunden mit WP Affiliate Captain (Vertrag 1.x).', 'learnboating-affiliate-connector') : '⚠️ '.esc_html(self::$problem)).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="lbac_save">';
        wp_nonce_field('lbac_save');
        echo '<table class="form-table" role="presentation">';
        printf('<tr><th scope="row">%s</th><td><label><input type="checkbox" name="exclude_groups" value="1" %s> %s</label><br><label><input type="checkbox" name="exclude_upgrades" value="1" %s> %s</label></td></tr>', esc_html__('Keine Provision auf', 'learnboating-affiliate-connector'), checked($s['exclude_groups'], true, false), esc_html__('Gruppenpakete (Produkte mit Plätzen)', 'learnboating-affiliate-connector'), checked($s['exclude_upgrades'], true, false), esc_html__('Upgrades im Checkout (Videokurs-Upgrade, Simulator-Upgrade)', 'learnboating-affiliate-connector'));
        printf('<tr><th scope="row"><label for="lbac-skus">%s</label></th><td><input type="text" class="regular-text code" id="lbac-skus" name="exclude_skus" value="%s"><p class="description">%s</p></td></tr>', esc_html__('Weitere Artikelnummern ausschließen', 'learnboating-affiliate-connector'), esc_attr($s['exclude_skus']), esc_html__('Kommagetrennt, * am Ende als Platzhalter, z. B. BP-ADD-*, BP-PASS-1M', 'learnboating-affiliate-connector'));
        printf('<tr><th scope="row"><label for="lbac-wd">%s</label></th><td><input type="number" min="0" max="365" class="small-text" id="lbac-wd" name="withdrawal_days" value="%d"> %s<p class="description">%s</p></td></tr>', esc_html__('Widerrufsfrist', 'learnboating-affiliate-connector'), (int) $s['withdrawal_days'], esc_html__('Tage', 'learnboating-affiliate-connector'), esc_html__('Die Provision bleibt bis zum späteren Ende von Widerrufsfrist und Geld-zurück-Garantie des Produkts offen.', 'learnboating-affiliate-connector'));
        printf('<tr><th scope="row"><label for="lbac-buf">%s</label></th><td><input type="number" min="0" max="60" class="small-text" id="lbac-buf" name="buffer_days" value="%d"> %s</td></tr>', esc_html__('Puffer für späte Rückbuchungen', 'learnboating-affiliate-connector'), (int) $s['buffer_days'], esc_html__('Tage', 'learnboating-affiliate-connector'));
        printf('<tr><th scope="row">%s</th><td><label><input type="checkbox" name="app_handover" value="1" %s> %s</label><p class="description">%s</p></td></tr>', esc_html__('App-Übergabe', 'learnboating-affiliate-connector'), checked($s['app_handover'], true, false), esc_html__('Partnercode aus der App an Kauf-Links anhängen', 'learnboating-affiliate-connector'), esc_html__('Die App schickt den Code aus dem Deep Link als Parameter „partner“ oder Header „X-Partner-Code“ an die Mobile-API.', 'learnboating-affiliate-connector'));
        printf('<tr><th scope="row"><label for="lbac-camp">%s</label></th><td><input type="text" class="regular-text code" id="lbac-camp" name="app_campaign" value="%s" maxlength="40"><p class="description">%s</p></td></tr>', esc_html__('Kampagne für App-Käufe', 'learnboating-affiliate-connector'), esc_attr($s['app_campaign']), esc_html__('Erscheint in den Kampagnenberichten von Affiliate Captain; leer = keine Kampagne.', 'learnboating-affiliate-connector'));
        echo '</table>';
        submit_button();
        echo '</form></div>';
    }

    public static function save(): void
    {
        check_admin_referer('lbac_save');
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'learnboating-affiliate-connector'), 403);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce oben geprüft.
        $skus = strtoupper(preg_replace('/[^A-Za-z0-9_\-*, ]/', '', (string) wp_unslash($_POST['exclude_skus'] ?? '')) ?? '');
        update_option(self::OPTION, [
            'exclude_groups' => ! empty($_POST['exclude_groups']),
            'exclude_upgrades' => ! empty($_POST['exclude_upgrades']),
            'exclude_skus' => substr($skus, 0, 500),
            'withdrawal_days' => max(0, min(365, (int) ($_POST['withdrawal_days'] ?? 14))),
            'buffer_days' => max(0, min(60, (int) ($_POST['buffer_days'] ?? 2))),
            'app_handover' => ! empty($_POST['app_handover']),
            'app_campaign' => substr(sanitize_key((string) wp_unslash($_POST['app_campaign'] ?? 'app')), 0, 40),
        ], false);
        // phpcs:enable
        wp_safe_redirect(admin_url('options-general.php?page=lbac&updated=1'));
        exit;
    }
}
