<?php

declare(strict_types=1);

/*
 * Eigenständiger Test des LearnBoating-Connectors ohne WordPress: WordPress-Funktionen, die Lernplattform und
 * der Vertrag von WP Affiliate Captain werden deterministisch nachgebildet.
 *
 *   php learnboating-affiliate-connector/tests/connector-test.php
 */

namespace {
    define('ABSPATH', __DIR__.'/');
    define('DAY_IN_SECONDS', 86400);
    define('LBAC_VERSION', 'test');
    define('REST_REQUEST', true);
    $GLOBALS['test_options'] = [];
    $GLOBALS['test_hooks'] = [];
    $GLOBALS['test_contract'] = '1.1.0';
    $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/boatingschool/v1/courses/1']];
    function __($t, $d = null) { return $t; }
    function esc_html__($t, $d = null) { return $t; }
    function add_action(...$a) { return true; }
    function add_filter($hook, $cb, $p = 10, $n = 1) { $GLOBALS['test_hooks'][$hook] = $cb; return true; }
    function get_option($k, $d = false) { return $GLOBALS['test_options'][$k] ?? $d; }
    function wp_unslash($v) { return $v; }
    function add_query_arg(array $args, string $url): string { return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($args, '', '&', PHP_QUERY_RFC3986); }
    function wpac_contract_version() { return $GLOBALS['test_contract']; }
    function wpac_resolve_partner_code($code, array $ctx = []) { $GLOBALS['test_resolve_calls'] = ($GLOBALS['test_resolve_calls'] ?? 0) + 1; return in_array(strtoupper($code), ['NORD-1'], true) ? ['affiliate_id' => 1, 'code_id' => 1, 'code' => 'NORD-1'] : null; }
    function wc_get_product($id) { return null; }
}

namespace Bootspruefung\Learning\Commerce {
    final class ShopCatalog
    {
        public const SIM_UPGRADE = 'SIM-UPGRADE-CHECKOUT';
        public static function videoUpgradeSkus(): array { return ['BP-SBF-SEE-VIDEO']; }
        public static function find(string $sku): ?array
        {
            return [
                'BP-SBF-SEE' => ['sku' => 'BP-SBF-SEE', 'group' => 'sbf', 'tier' => 'theorie'],
                'BP-SBF-SEE-VIDEO' => ['sku' => 'BP-SBF-SEE-VIDEO', 'group' => 'videoupgrade', 'tier' => 'upgrade'],
                'BP-GRUPPE-10' => ['sku' => 'BP-GRUPPE-10', 'group' => 'groups', 'seats' => 10],
                'BP-ADD-SEE-TO-BIN' => ['sku' => 'BP-ADD-SEE-TO-BIN', 'group' => 'addon', 'tier' => 'theorie'],
            ][$sku] ?? null;
        }
    }
    final class MoneyBack
    {
        public static function policy(int $productId): ?array { return [10 => ['days' => 30, 'max_progress' => 20.0], 11 => ['days' => 60, 'max_progress' => 20.0]][$productId] ?? null; }
    }
    final class ProductAccessRepository
    {
        public function __construct($db) {}
        public function rows(int $p, int $v): array { return $p === 50 ? [(object) ['seats' => 5]] : []; }
        public static function seats(array $rows): ?int { $s = 0; foreach ($rows as $r) { $s = max($s, (int) $r->seats); } return $s ?: null; }
    }
}

namespace Bootspruefung\Learning\Mobile {
    final class MobileApi { public const NAMESPACE = 'boatingschool/v1'; }
}

namespace {
    $GLOBALS['wpdb'] = null;
    require dirname(__DIR__).'/src/Connector.php';

    use LearnBoating\AffiliateConnector\Connector;

    $failures = 0;
    $check = static function (bool $ok, string $label) use (&$failures): void {
        echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
        $failures += $ok ? 0 : 1;
    };
    $woo = ['source' => 'woocommerce', 'paid_at' => '2026-11-01 10:00:00', 'items' => []];

    Connector::boot();
    $check(Connector::active() && isset($GLOBALS['test_hooks']['wpac_commission_base'], $GLOBALS['test_hooks']['wpac_hold_until'], $GLOBALS['test_hooks']['bootspruefung_shop_offer_url']), 'aktiv bei Vertrag 1.x, Filter registriert');

    $check(5000 === Connector::commissionBase(5000, ['sku' => 'BP-SBF-SEE', 'product_id' => 10], $woo), 'Kurs bleibt provisionsfähig');
    $check(0 === Connector::commissionBase(1190, ['sku' => 'BP-SBF-SEE-VIDEO', 'product_id' => 12], $woo), 'Video-Upgrade ausgeschlossen');
    $check(0 === Connector::commissionBase(490, ['sku' => 'SIM-UPGRADE-CHECKOUT', 'product_id' => 13], $woo), 'Simulator-Upgrade im Checkout ausgeschlossen');
    $check(0 === Connector::commissionBase(9900, ['sku' => 'BP-GRUPPE-10', 'product_id' => 14], $woo), 'Gruppenpaket laut Katalog ausgeschlossen');
    $check(0 === Connector::commissionBase(9900, ['sku' => 'EIGENES-PAKET', 'product_id' => 50], $woo), 'Gruppenpaket über Plätze in der Freischaltung ausgeschlossen');
    $check(1390 === Connector::commissionBase(1390, ['sku' => 'BP-ADD-SEE-TO-BIN', 'product_id' => 15], $woo), 'Ergänzungskurs standardmäßig provisionsfähig');
    $GLOBALS['test_options'][Connector::OPTION] = ['exclude_skus' => 'BP-ADD-*'];
    $check(0 === Connector::commissionBase(1390, ['sku' => 'BP-ADD-SEE-TO-BIN', 'product_id' => 15], $woo), 'Artikelnummern-Muster BP-ADD-* schließt aus');
    $GLOBALS['test_options'][Connector::OPTION] = ['exclude_upgrades' => false];
    $check(1190 === Connector::commissionBase(1190, ['sku' => 'BP-SBF-SEE-VIDEO', 'product_id' => 12], $woo), 'Upgrade-Ausschluss abschaltbar');
    $GLOBALS['test_options'][Connector::OPTION] = [];
    $check(1190 === Connector::commissionBase(1190, ['sku' => 'BP-SBF-SEE-VIDEO'], ['source' => 'pass-captain']), 'andere Quellen unverändert');

    $paid = strtotime('2026-11-01 10:00:00 UTC');
    $sale = array_merge($woo, ['items' => [['product_id' => 10, 'parent_id' => 0], ['product_id' => 99, 'parent_id' => 11]]]);
    $check($paid + 62 * DAY_IN_SECONDS === Connector::holdUntil($paid + 30 * DAY_IN_SECONDS, $sale), 'Haltefrist = längste Garantie (60 Tage, Variante → Elternprodukt) + 2 Tage Puffer');
    $only = array_merge($woo, ['items' => [['product_id' => 77, 'parent_id' => 0]]]);
    $check($paid + 30 * DAY_IN_SECONDS === Connector::holdUntil($paid + 30 * DAY_IN_SECONDS, $only), 'späteres Datum von Affiliate Captain bleibt (Widerruf 14 + 2 < 30)');
    $check($paid + 16 * DAY_IN_SECONDS === Connector::holdUntil($paid, $only), 'ohne Garantie: Widerrufsfrist 14 + Puffer 2 Tage');

    $url = 'https://shop.example/product/sbf-see/';
    $_GET = ['partner' => 'nord-1'];
    $check($url.'?partner=NORD-1&utm_campaign=app' === Connector::offerUrl($url), 'App: gültiger Code und Kampagne werden angehängt');
    $_GET = ['partner' => 'gesperrt'];
    $check($url === Connector::offerUrl($url), 'App: ungültiger/gesperrter Code wird nicht weitergegeben');
    $_GET = [];
    $_SERVER['HTTP_X_PARTNER_CODE'] = 'NORD-1';
    $check(str_contains(Connector::offerUrl($url), 'partner=NORD-1'), 'App: Code per Header X-Partner-Code');
    $check($url.'?utm_campaign=sommer&partner=NORD-1' === Connector::offerUrl($url.'?utm_campaign=sommer'), 'vorhandene Kampagne bleibt');
    $calls = $GLOBALS['test_resolve_calls'] ?? 0;
    for ($i = 0; $i < 20; $i++) {
        Connector::offerUrl($url.'?n='.$i);
    }
    $check(($GLOBALS['test_resolve_calls'] ?? 0) === $calls, 'Partnercode einmal je Anfrage aufgelöst (20 Angebote, keine weitere Abfrage)');
    $GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/cart';
    $check($url === Connector::offerUrl($url), 'nur Mobile-API, nicht Web oder Store API');
    $req = new class {
        public function get_route(): string { return '/boatingschool/v1/courses/2'; }
    };
    Connector::enterRest(null, null, $req);
    $check(str_contains(Connector::offerUrl($url), 'partner=NORD-1'), 'interne Anfrage an die App-Schnittstelle (rest_do_request) wird erkannt');
    Connector::leaveRest(null, null, $req);
    $check($url === Connector::offerUrl($url), 'nach der internen Anfrage wieder Web');

    $GLOBALS['test_contract'] = '2.0.0';
    $GLOBALS['test_hooks'] = [];
    Connector::boot();
    $check(! Connector::active() && str_contains(Connector::diagnose(), '2.0.0') && ! isset($GLOBALS['test_hooks']['wpac_commission_base']), 'inkompatibler Vertrag: kontrolliert inaktiv mit verständlicher Meldung');

    echo $failures ? "\n$failures Fehler\n" : "\nAlle Prüfungen bestanden.\n";
    exit($failures ? 1 : 0);
}
