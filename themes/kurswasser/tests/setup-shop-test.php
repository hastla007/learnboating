<?php

declare(strict_types=1);

/*
 * Integrationstest Einrichtungs-Assistent, Schritt 3 „Shop einrichten“ (Theme Kurswasser, inc/setup-shop.php):
 * Karte nur mit WooCommerce, Eingabeprüfung, EU-Steuertabelle, Vorschau ohne Änderung, Anwenden mit DE/OSS
 * (27 Sätze, idempotent), abweichende Werte nur mit Häkchen, Konflikt mit vorhandenem Satz, AT/eigener Satz,
 * Kleinunternehmer (calc_taxes no, Hinweistext), Produkte als Entwurf, Rechte- und Nonce-Prüfung, Sprachpaket
 * ohne Netz. Räumt danach auf (Optionen, Steuersätze, Produkte, Bilder, Kategorien, Regeln).
 *
 * Braucht WooCommerce; ist es nicht aktiv, aktiviert der Test es, läuft in einem zweiten Prozess und
 * deaktiviert es danach wieder.
 *
 *   WP_DIR=/pfad/zu/wordpress php themes/kurswasser/tests/setup-shop-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require dirname(__DIR__, 3).'/bootspruefung-learning/tests/integration/support/guard.php';
require_once ABSPATH.'wp-admin/includes/admin.php';

if (! class_exists('WooCommerce')) {
    // WooCommerce muss von Beginn der Anfrage an geladen sein.
    if (getenv('KW_SHOP_TEST_CHILD') === '1') {
        fwrite(STDERR, "WooCommerce ließ sich nicht laden.\n");
        exit(1);
    }
    wp_set_current_user(1);
    $activated = activate_plugin('woocommerce/woocommerce.php');
    if (is_wp_error($activated)) {
        fwrite(STDERR, 'WooCommerce nicht verfügbar: '.$activated->get_error_message()."\n");
        exit(1);
    }
    echo "WooCommerce für den Test aktiviert.\n";
    passthru('KW_SHOP_TEST_CHILD=1 '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__), $code);
    deactivate_plugins('woocommerce/woocommerce.php');
    echo "WooCommerce wieder deaktiviert.\n";
    exit((int) $code);
}

$theme = dirname(__DIR__);
if (! function_exists('kurswasser_setup_shop_card')) {
    require_once $theme.'/functions.php';
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$html = static function (callable $render): string {
    ob_start();
    $render();

    return (string) ob_get_clean();
};

global $wpdb;
wp_set_current_user(1);
$uid = get_current_user_id();
$learning = kurswasser_shop_learning_active();
$Settings = '\Bootspruefung\Learning\Settings\Settings';
$Catalog = '\Bootspruefung\Learning\Commerce\ShopCatalog';
$rates = $wpdb->prefix.'woocommerce_tax_rates';
$locations = $wpdb->prefix.'woocommerce_tax_rate_locations';
$rules = $wpdb->prefix.'bp_access_rule';

// Ausgangsstand sichern; vorhandene Steuersätze für die Dauer des Tests beiseitelegen.
$optionKeys = array_values(array_diff(array_merge(array_keys(kurswasser_shop_factory_defaults()), ['kurswasser_shop_setup', 'woocommerce_specific_ship_to_countries']), ['bp_price_note']));
if ($learning) {
    $optionKeys[] = $Settings::OPTION;
}
$snapshot = [];
foreach ($optionKeys as $k) {
    $snapshot[$k] = get_option($k, '__kw_missing__');
}
$stashRates = $wpdb->get_results("SELECT * FROM {$rates}", ARRAY_A) ?: [];
$stashLocations = $wpdb->get_results("SELECT * FROM {$locations}", ARRAY_A) ?: [];
$wpdb->query("DELETE FROM {$locations}");
$wpdb->query("DELETE FROM {$rates}");
WC_Cache_Helper::invalidate_cache_group('taxes');
$productsBefore = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
$statusBefore = [];
foreach ($productsBefore as $pid) {
    $statusBefore[(int) $pid] = get_post_status((int) $pid);
}
$attachmentsBefore = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image']);
$termsBefore = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids']);
$maxRule = $learning ? (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$rules}") : 0;
$testUsers = [];

$rateRows = static fn (): array => $wpdb->get_results("SELECT tax_rate_country AS country, tax_rate AS rate, tax_rate_class AS class FROM {$rates} ORDER BY tax_rate_country", ARRAY_A) ?: [];
$rateCount = static fn (): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rates}");
$state = static function () use ($optionKeys, $rateCount, $wpdb, $rules, $learning): string {
    $o = [];
    foreach ($optionKeys as $k) {
        $o[$k] = get_option($k, '__kw_missing__');
    }

    $products = $wpdb->get_results("SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'product' GROUP BY post_status", ARRAY_A);

    return md5(serialize([$o, $rateCount(), $products, $learning ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules}") : 0]));
};
// Frischer Shop: WooCommerce-Werkseinstellungen, keine Steuersätze.
$fresh = static function () use ($wpdb, $rates, $locations, $learning, $Settings): void {
    foreach (kurswasser_shop_factory_defaults() as $k => $v) {
        if ($k === 'bp_price_note') {
            if ($learning) {
                $all = $Settings::all();
                $all['price_note'] = $v;
                update_option($Settings::OPTION, $all);
            }
        } elseif ($k === 'kurswasser_shop_same_gross_price') {
            delete_option($k);
        } else {
            update_option($k, $v);
        }
    }
    delete_option('kurswasser_shop_setup');
    $wpdb->query("DELETE FROM {$locations}");
    $wpdb->query("DELETE FROM {$rates}");
    WC_Cache_Helper::invalidate_cache_group('taxes');
};
$find = static fn (string $country): array => array_values(array_map(static fn ($r) => (float) $r['rate'], WC_Tax::find_rates(['country' => $country, 'state' => '', 'postcode' => '', 'city' => '', 'tax_class' => ''])));
$noNet = static fn () => new WP_Error('http_request_failed', 'Testlauf ohne Netz');
// Kein Test lädt etwas aus dem Netz (Sprachpaket nur im Fehlerfall geprüft).
add_filter('pre_http_request', $noNet, 10, 3);
$base = ['country' => 'DE', 'state' => 'DE-SH', 'address' => 'Hafenstraße 1', 'postcode' => '24103', 'city' => 'Lübeck', 'sell' => 'eu', 'registration' => '1', 'language' => ''];

echo "Schritt 3 nur mit WooCommerce\n";
$fresh();
$main = $html('kurswasser_setup_page');
$check(str_contains($main, '3. Shop einrichten') && str_contains($main, 'name="kw_shop[tax]" value="oss"') && str_contains($main, 'value="own"') && str_contains($main, 'value="small"') && str_contains($main, 'Steuerberater') && str_contains($main, 'Drei Schritte'), 'Karte mit Formular und drei Mehrwertsteuer-Wegen');
$check(str_contains($main, 'name="kw_shop[rate]"') && str_contains($main, 'data-rate="20"') && str_contains($main, 'name="kw_shop[note]"') && str_contains($main, 'name="kw_shop[registration]" value="1" checked') && str_contains($main, 'name="kw_shop[language]" value="1" checked'), 'Satz mit Vorschlag nach Land, Hinweistext, Häkchen Registrierung/Sprache');
$check(! preg_match('/name="kw_shop\[tax\]" value="[a-z]+" checked/', $main), 'kein Mehrwertsteuer-Weg vorausgewählt');
$check(str_contains($main, 'value="AT"') && str_contains($main, 'value="CH"') && str_contains($main, 'value="FI"') && substr_count($main, 'data-eu="1"') === 27, 'Länder: 27 EU-Länder und Schweiz');
add_filter('kurswasser_shop_available', '__return_false');
$main = $html('kurswasser_setup_page');
$check(! str_contains($main, '3. Shop einrichten') && str_contains($main, 'sobald WooCommerce aktiv ist'), 'ohne WooCommerce: keine Karte, nur Hinweis');
remove_filter('kurswasser_shop_available', '__return_false');

echo "Eingaben prüfen\n";
$in = kurswasser_shop_input(['country' => 'CH', 'tax' => 'oss']);
$check($in['errors'] !== [] && str_contains(implode(' ', $in['errors']), 'OSS'), 'OSS nur für EU-Länder (Schweiz: Fehler)');
$check(kurswasser_shop_input(['country' => 'XX', 'tax' => 'own'])['errors'] !== [] && kurswasser_shop_input(['country' => 'DE'])['errors'] !== [], 'unbekanntes Land bzw. kein Steuerweg: Fehler');
$check(kurswasser_shop_input(['country' => 'DE', 'tax' => 'own'])['rate'] === 19.0 && kurswasser_shop_input(['country' => 'AT', 'tax' => 'own'])['rate'] === 20.0 && kurswasser_shop_input(['country' => 'CH', 'tax' => 'own'])['rate'] === 8.1, 'Satz-Vorschlag: DE 19, AT 20, CH 8,1');
$check(kurswasser_shop_input(['country' => 'DE', 'tax' => 'own', 'rate' => '7,5'])['rate'] === 7.5 && kurswasser_shop_input(['country' => 'DE', 'tax' => 'own', 'rate' => '50'])['errors'] !== [], 'eigener Satz mit Komma; über 30 %: Fehler');
$check(str_contains(kurswasser_shop_input(['country' => 'DE', 'tax' => 'small'])['note'], '§ 19 UStG') && str_contains(kurswasser_shop_input(['country' => 'AT', 'tax' => 'small'])['note'], '§ 6 Abs. 1 Z 27 UStG'), 'Kleinunternehmer-Hinweis je Land (DE § 19 UStG, AT § 6 Abs. 1 Z 27 UStG)');
$check(kurswasser_shop_input(['country' => 'DE', 'state' => 'DE-BY', 'tax' => 'own'])['state'] === 'DE-BY' && kurswasser_shop_input(['country' => 'AT', 'state' => 'DE-BY', 'tax' => 'own'])['state'] === '', 'Region nur, wenn sie zum Land gehört');

echo "EU-Steuertabelle\n";
$eu = WC()->countries->get_european_union_countries();
sort($eu);
$codes = array_keys(KURSWASSER_EU_VAT_RATES);
sort($codes);
$check($codes === $eu && count($codes) === 27, '27 EU-Länder wie in WooCommerce');
$check(KURSWASSER_EU_VAT_RATES['DE'] === 19.0 && KURSWASSER_EU_VAT_RATES['AT'] === 20.0 && KURSWASSER_EU_VAT_RATES['FI'] === 25.5 && KURSWASSER_EU_VAT_RATES['EE'] === 24.0 && KURSWASSER_EU_VAT_RATES['SK'] === 23.0 && KURSWASSER_EU_VAT_RATES['RO'] === 21.0 && KURSWASSER_EU_VAT_RATES['HU'] === 27.0, 'Sätze u. a. DE 19, AT 20, FI 25,5, EE 24, SK 23, RO 21, HU 27');
$check((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', KURSWASSER_EU_VAT_RATES_STAND), 'Stand der Tabelle: '.KURSWASSER_EU_VAT_RATES_STAND);

echo "Vorschau ohne Änderung\n";
$fresh();
update_option('woocommerce_store_city', 'Kiel');              // vom Betreiber eingestellt
update_option('woocommerce_currency_pos', 'left_space');      // vom Betreiber eingestellt
$before = $state();
$_GET['kw_view'] = 'shop';
$_GET['kw_shop'] = $base + ['tax' => 'oss', 'products' => '1', 'free' => '1'];
$page = $html('kurswasser_setup_page');
unset($_GET['kw_view'], $_GET['kw_shop']);
$check(str_contains($page, 'Shop einrichten – Vorschau') && str_contains($page, 'Noch ist nichts geändert') && str_contains($page, 'name="action" value="kurswasser_setup_shop"') && str_contains($page, 'name="_wpnonce"'), 'Vorschau mit Bestätigungsformular (POST, Nonce)');
$check(str_contains($page, 'Finnland (FI): 25,5 %') && str_contains($page, 'Deutschland (DE): 19 %') && substr_count($page, '<span class="kw-pill is-new">neu</span>') === 27 && str_contains($page, 'Stand 01.08.2025'), 'Steuertabelle: 27 neue Sätze mit Stand');
$check(str_contains($page, 'value="woocommerce_calc_taxes" checked') && str_contains($page, 'value="woocommerce_currency" checked') && str_contains($page, 'value="woocommerce_store_address" checked'), 'Werkseinstellungen und leere Felder: angehakt');
$check(str_contains($page, 'value="woocommerce_store_city">') && str_contains($page, 'value="woocommerce_currency_pos">') && str_contains($page, 'von dir eingestellt'), 'abweichende Werte (Ort, Währungsposition): nicht angehakt, markiert');
$check(str_contains($page, 'Kiel') && str_contains($page, 'Lübeck') && str_contains($page, 'links mit Leerzeichen') && str_contains($page, 'rechts mit Leerzeichen (99,99 €)'), 'aktuell → neu je Einstellung');
if ($learning) {
    $check(str_contains($page, 'Produkte aus dem Katalog des Lern-Plugins') && str_contains($page, 'value="products" checked') && str_contains($page, 'value="free" checked'), 'Produkte (Probelauf) und Schnupper-Paket in der Vorschau');
}
$check($state() === $before, 'Vorschau hat nichts geändert (Optionen, Steuersätze, Produkte, Regeln)');

echo "Anwenden: DE, OSS, Standardauswahl\n";
// Gibt es schon alle Katalog-Produkte (z. B. aus früheren Läufen), wird eines kurz „versteckt“ (andere
// Artikelnummer), damit der Lauf ein neues Produkt anlegt; danach zurück.
$hidden = null;
if ($learning) {
    $firstSku = (string) $Catalog::products()[0]['sku'];
    $existingId = (int) wc_get_product_id_by_sku($firstSku);
    if ($existingId > 0 && ! array_filter($Catalog::products(), static fn ($d) => (int) wc_get_product_id_by_sku($d['sku']) === 0)) {
        $p = wc_get_product($existingId);
        $p->set_sku($firstSku.'-kw-test-'.getmypid());
        $p->save();
        $hidden = [$existingId, $firstSku];
    }
}
$inOss = kurswasser_shop_input($base + ['tax' => 'oss', 'products' => $learning ? '1' : '', 'free' => $learning ? '1' : '']);
$plan = kurswasser_shop_plan($inOss);
$sel = kurswasser_shop_default_selection($plan);
$check(! in_array('woocommerce_store_city', $sel, true) && ! in_array('woocommerce_currency_pos', $sel, true) && in_array('woocommerce_calc_taxes', $sel, true) && in_array('tax_rates', $sel, true), 'Standardauswahl ohne abweichende Werte');
$t0 = microtime(true);
$report = kurswasser_shop_apply($inOss, $sel);
$check($report['errors'] === [], 'keine Fehler'.($report['errors'] ? ': '.implode(' | ', $report['errors']) : ''));
$check(get_option('woocommerce_default_country') === 'DE:DE-SH' && get_option('woocommerce_store_address') === 'Hafenstraße 1' && get_option('woocommerce_store_postcode') === '24103', 'Land/Region und Anschrift gesetzt');
$check(get_option('woocommerce_store_city') === 'Kiel' && get_option('woocommerce_currency_pos') === 'left_space', 'abweichende Werte ohne Häkchen nicht überschrieben');
$check(str_contains(implode(' ', $report['skipped']), 'Ort: nicht übernommen, bleibt „Kiel“'), 'Bericht: übersprungen mit bisherigem Wert');
$check(get_option('woocommerce_currency') === 'EUR' && get_option('woocommerce_price_thousand_sep') === '.' && get_option('woocommerce_price_decimal_sep') === ',' && (string) get_option('woocommerce_price_num_decimals') === '2', 'EUR, Tausender „.“, Dezimal „,“');
$check(get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_prices_include_tax') === 'yes' && get_option('woocommerce_tax_based_on') === 'billing' && get_option('woocommerce_tax_display_shop') === 'incl' && get_option('woocommerce_tax_display_cart') === 'incl', 'Steuer an, Preise inkl., nach Rechnungsadresse, Anzeige inkl.');
$allowed = (array) get_option('woocommerce_specific_allowed_countries');
$check(get_option('woocommerce_allowed_countries') === 'specific' && count($allowed) === 28 && in_array('CH', $allowed, true) && in_array('FI', $allowed, true) && get_option('woocommerce_ship_to_countries') === 'disabled', 'Verkauf an EU + Schweiz, kein Versand');
$check(get_option('woocommerce_enable_myaccount_registration') === 'yes' && get_option('kurswasser_shop_same_gross_price') === 'yes', 'Registrierung an; gleicher Bruttopreis (OSS)');
$check(get_option('WPLANG') === '' && ! isset($plan['settings']['WPLANG']), 'Sprache nicht gewählt: unverändert');
$stored = $rateRows();
$byCountry = array_column($stored, 'rate', 'country');
$okRates = count($stored) === 27 && ! array_filter($stored, static fn ($r) => $r['class'] !== '');
foreach (KURSWASSER_EU_VAT_RATES as $c => $r) {
    $okRates = $okRates && isset($byCountry[$c]) && abs((float) $byCountry[$c] - $r) < 0.0001;
}
$check($okRates, '27 Sätze in der Standard-Klasse, Werte wie in der Tabelle');
$check($find('FR') === [20.0] && $find('FI') === [25.5] && $find('CH') === [] && $find('US') === [], 'WooCommerce findet FR 20, FI 25,5; Schweiz/USA ohne Satz');
if ($learning) {
    $check($Settings::priceNote() === 'inkl. MwSt.', 'Preis-Hinweis „inkl. MwSt.“');
    $prows = (array) $report['products'];
    $catalog = array_column($Catalog::products(), null, 'sku');
    $newIds = array_map(static fn ($r) => (int) $r['product_id'], array_filter($prows, static fn ($r) => $r['action'] === 'neu'));
    $drafts = array_filter($newIds, static fn ($id) => get_post_status($id) === 'draft');
    $check(count($prows) === count($catalog) && $newIds !== [] && count($drafts) === count($newIds), 'Produkte: '.count($prows).' abgeglichen, '.count($newIds).' neu – alle als Entwurf');
    $statusKept = true;
    foreach ($statusBefore as $pid => $st) {
        $statusKept = $statusKept && get_post_status($pid) === $st;
    }
    $priceOk = true;
    foreach ($prows as $r) {
        $priceOk = $priceOk && wc_get_product((int) $r['product_id'])->get_regular_price() === $catalog[$r['sku']]['price'];
    }
    $check($statusKept && $priceOk && ! array_filter($prows, static fn ($r) => get_post_status((int) $r['product_id']) === 'publish' && ! isset($statusBefore[(int) $r['product_id']])), 'nichts veröffentlicht, Status vorhandener Produkte bleibt, Preise von der Preis-Seite');
    $check(is_array($report['free']) && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules} WHERE access_type = 'free_limited' AND id > {$maxRule}") === $report['free']['added'], 'Schnupper-Paket: '.($report['free']['added'] ?? 0).' Regel(n) angelegt');
}
echo '  ('.round(microtime(true) - $t0, 1)." s)\n";

echo "Zweiter Lauf: idempotent\n";
$plan2 = kurswasser_shop_plan($inOss);
$check(array_unique(array_column($plan2['rates']['rows'], 'status')) === ['exists'], 'Vorschau: alle 27 Sätze vorhanden');
$report2 = kurswasser_shop_apply($inOss, kurswasser_shop_default_selection($plan2));
$check($rateCount() === 27 && ! str_contains(implode(' ', $report2['done']), 'Steuersätze angelegt') && str_contains(implode(' ', $report2['skipped']), '27 Steuersätze waren schon vorhanden'), 'keine doppelten Sätze');
$check(! array_filter($plan2['settings'], static fn ($s) => $s['state'] === 'default'), 'alle Einstellungen „schon so“ oder abweichend');
if ($learning) {
    $check(! array_filter((array) $report2['products'], static fn ($r) => $r['action'] === 'neu') && $report2['free']['added'] === 0, 'keine neuen Produkte, keine neuen Regeln');
}

echo "Abweichender Wert nur mit Häkchen\n";
$report3 = kurswasser_shop_apply($inOss, ['woocommerce_currency_pos']);
$price = str_replace("\u{00A0}", ' ', html_entity_decode(wp_strip_all_tags(wc_price(1234.5)), ENT_QUOTES, 'UTF-8'));
$check(get_option('woocommerce_currency_pos') === 'right_space' && $price === '1.234,50 €', 'mit Häkchen überschrieben; Preisformat „'.$price.'“');
$plan4 = kurswasser_shop_plan($inOss);
$seenKiel = kurswasser_shop_seen_hash($plan4['settings']['woocommerce_store_city']['current']);
update_option('woocommerce_store_city', 'Flensburg'); // zwischen Vorschau und Bestätigung geändert
$report4 = kurswasser_shop_apply($inOss, ['woocommerce_store_city'], ['woocommerce_store_city' => $seenKiel]);
$check(get_option('woocommerce_store_city') === 'Flensburg' && str_contains(implode(' ', $report4['skipped']), 'seit der Vorschau geändert'), 'seit der Vorschau geändert: nicht überschrieben');

echo "Vorhandener anderer Satz\n";
$fresh();
WC_Tax::_insert_tax_rate(['tax_rate_country' => 'FI', 'tax_rate' => '24', 'tax_rate_name' => 'ALV', 'tax_rate_priority' => 1, 'tax_rate_class' => '']);
$inPlain = kurswasser_shop_input($base + ['tax' => 'oss']);
$planFi = kurswasser_shop_plan($inPlain);
$fi = array_values(array_filter($planFi['rates']['rows'], static fn ($r) => $r['country'] === 'FI'))[0];
$check($fi['status'] === 'conflict' && $fi['existing'] === [24.0], 'Vorschau: FI 24 % vorhanden, 25,5 % wird nicht angelegt');
$repFi = kurswasser_shop_apply($inPlain, kurswasser_shop_default_selection($planFi));
$fiRows = array_filter($rateRows(), static fn ($r) => $r['country'] === 'FI');
$check($rateCount() === 27 && count($fiRows) === 1 && abs((float) array_values($fiRows)[0]['rate'] - 24) < 0.001 && str_contains(implode(' ', $repFi['skipped']), 'FI 25,5 % nicht angelegt'), '26 angelegt, FI unverändert, kein zweiter Satz');

echo "AT, eigener Satz\n";
$fresh();
WC_Tax::_insert_tax_rate(['tax_rate_country' => 'DE', 'tax_rate' => '19', 'tax_rate_name' => 'MwSt.', 'tax_rate_priority' => 1, 'tax_rate_class' => '']);
$inAt = kurswasser_shop_input(['country' => 'AT', 'address' => 'Ringstraße 1', 'postcode' => '1010', 'city' => 'Wien', 'tax' => 'own', 'rate' => '', 'sell' => 'all', 'registration' => '1']);
$planAt = kurswasser_shop_plan($inAt);
$check($inAt['rate'] === 20.0 && count($planAt['rates']['rows']) === 1 && $planAt['rates']['rows'][0]['country'] === '' && str_contains(implode(' ', array_column($planAt['notes'], 1)), 'Weitere Steuersätze'), 'Vorschau: ein Satz 20 % für alle Länder, Hinweis auf vorhandenen DE-Satz');
$repAt = kurswasser_shop_apply($inAt, kurswasser_shop_default_selection($planAt));
$check($repAt['errors'] === [] && get_option('woocommerce_default_country') === 'AT' && get_option('woocommerce_store_city') === 'Wien' && get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_allowed_countries') === 'all', 'AT, Wien, Steuer an, Verkauf an alle Länder');
$check($rateCount() === 2 && $find('AT') === [20.0] && $find('CH') === [20.0] && $find('DE') === [19.0] && get_option('kurswasser_shop_same_gross_price', '') === '', 'Satz 20 % für alle; vorhandener DE-Satz bleibt; kein OSS-Bruttopreis');

echo "Kleinunternehmer\n";
$fresh();
$inSmall = kurswasser_shop_input($base + ['tax' => 'small', 'note' => '']);
$repSmall = kurswasser_shop_apply($inSmall, kurswasser_shop_default_selection(kurswasser_shop_plan($inSmall)));
$check($repSmall['errors'] === [] && get_option('woocommerce_calc_taxes') === 'no' && $rateCount() === 0 && get_option('woocommerce_tax_based_on') === 'shipping', 'calc_taxes no, keine Steuersätze, übrige Steuer-Einstellungen unberührt');
if ($learning) {
    $check($Settings::priceNote() === 'ohne MwSt. – Kleinunternehmer gem. § 19 UStG', 'Hinweis gespeichert: „'.$Settings::priceNote().'“');
    $product = new WC_Product_Simple();
    $check(str_contains((string) apply_filters('woocommerce_get_price_html', '<span>29,90 €</span>', $product), '§ 19 UStG'), 'Hinweis unter dem Preis (Lern-Plugin)');
    $card = $html('kurswasser_setup_shop_card');
    $check(str_contains($card, 'value="small" checked') && str_contains($card, 'value="ohne MwSt. – Kleinunternehmer gem. § 19 UStG"'), 'Formular zeigt gespeicherte Wahl und Hinweistext (editierbar)');
    $inSmallAt = kurswasser_shop_input(['country' => 'AT', 'tax' => 'small', 'note' => 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG']);
    $planSmallAt = kurswasser_shop_plan($inSmallAt);
    $check($planSmallAt['settings']['bp_price_note']['state'] === 'differs' && ! in_array('bp_price_note', kurswasser_shop_default_selection($planSmallAt), true), 'geänderter Hinweis ist „von dir eingestellt“: nur mit Häkchen');
    kurswasser_shop_apply($inSmallAt, ['bp_price_note']);
    $check($Settings::priceNote() === 'Umsatzsteuerbefreit – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG', 'eigener Text (AT) übernommen');
}

echo "Rechte und Nonce\n";
$fresh();
$throwDie = static fn () => static function ($message, $title = '', $args = []) {
    throw new RuntimeException('die:'.(is_array($args) ? (int) ($args['response'] ?? 0) : 0).':'.wp_strip_all_tags(is_string($message) ? $message : ''));
};
add_filter('wp_die_handler', $throwDie);
$throwRedirect = static function (string $location): string {
    throw new RuntimeException('redirect:'.$location);
};
add_filter('wp_redirect', $throwRedirect);
$run = static function (): string {
    try {
        do_action('admin_post_kurswasser_setup_shop');
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
};
$_POST = $_REQUEST = ['action' => 'kurswasser_setup_shop', 'kw_shop' => $base + ['tax' => 'oss'], 'kw_apply' => ['woocommerce_calc_taxes', 'woocommerce_currency']];
$before = $state();
$sub = wp_insert_user(['user_login' => 'kw-shop-sub-'.getmypid(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$testUsers[] = $sub;
wp_set_current_user($sub);
$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('kurswasser_setup_shop');
$out = $run();
$check(str_starts_with($out, 'die:403') && str_contains($out, 'Keine Berechtigung') && $state() === $before, 'Abonnent: 403, nichts geändert');
$manager = wp_insert_user(['user_login' => 'kw-shop-mgr-'.getmypid(), 'user_pass' => wp_generate_password(), 'role' => get_role('shop_manager') ? 'shop_manager' : 'subscriber']);
$testUsers[] = $manager;
(new WP_User($manager))->add_cap('manage_woocommerce');
wp_set_current_user($manager);
$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('kurswasser_setup_shop');
$out = $run();
$check(str_starts_with($out, 'die:403') && str_contains($out, 'Keine Berechtigung') && $state() === $before, 'Shop-Manager ohne manage_options: 403, nichts geändert');
wp_set_current_user($uid);
$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'falsch';
$out = $run();
$check(str_starts_with($out, 'die:403') && ! str_contains($out, 'Keine Berechtigung') && $state() === $before, 'Admin mit falscher Nonce: abgewiesen, nichts geändert');
$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('kurswasser_setup_shop');
$out = $run();
$check(str_starts_with($out, 'redirect:') && str_contains($out, 'kw_view=shop-result'), 'Admin mit Nonce: ausgeführt, weiter zum Ergebnis');
$check(get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_currency') === 'EUR' && get_option('woocommerce_currency_pos') === 'left' && get_option('woocommerce_tax_based_on') === 'shipping' && $rateCount() === 0, 'nur die angehakten Einstellungen geschrieben');
$_GET['kw_view'] = 'shop-result';
$result = $html('kurswasser_setup_page');
unset($_GET['kw_view']);
$check(str_contains($result, 'Shop einrichten – Ergebnis') && str_contains($result, 'Erledigt') && str_contains($result, 'Steuerberechnung: „aus“ → „an“') && str_contains($result, 'Übersprungen') && str_contains($result, 'Steuersätze: nicht angelegt'), 'Ergebnis-Bericht: erledigt und übersprungen');
remove_filter('wp_die_handler', $throwDie);
remove_filter('wp_redirect', $throwRedirect);
$_POST = $_REQUEST = [];

echo "Sprache: Sprachpaket ohne Netz\n";
$fresh();
if (in_array('de_DE', get_available_languages(), true)) {
    echo "  skip (de_DE ist hier schon installiert)\n";
} else {
    delete_site_transient('available_translations');
    // wordpress.org nicht erreichbar (wie translations_api ohne Netz; ohne dessen PHP-Warnung im Log).
    $offline = static fn () => new WP_Error('translations_api_failed', 'Testlauf ohne Netz');
    add_filter('translations_api', $offline);
    $inLang = kurswasser_shop_input(array_merge($base, ['tax' => 'own', 'language' => '1']));
    $planLang = kurswasser_shop_plan($inLang);
    $check(isset($planLang['settings']['WPLANG']) && $planLang['settings']['WPLANG']['checked'], 'Vorschau: Sprache Deutsch angehakt');
    $repLang = kurswasser_shop_apply($inLang, kurswasser_shop_default_selection($planLang));
    $err = implode(' ', $repLang['errors']);
    $check(str_contains($err, 'deutsche Sprachpaket konnte nicht geladen werden') && str_contains($err, 'Einstellungen › Allgemein'), 'klare Meldung ('.mb_substr($err, 0, 90).' …)');
    $check(get_option('WPLANG') === '' && get_option('woocommerce_calc_taxes') === 'yes' && $rateCount() === 1, 'Sprache bleibt; alles andere trotzdem ausgeführt');
    remove_filter('translations_api', $offline);
}

// Aufräumen
remove_filter('pre_http_request', $noNet, 10);
if ($hidden !== null) {
    $dup = (int) wc_get_product_id_by_sku($hidden[1]);
    if ($dup > 0 && $dup !== $hidden[0]) {
        (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->save($dup, 0, [], null, 'extend', 'revoke_on_full_refund');
        wp_delete_post($dup, true);
    }
    $p = wc_get_product($hidden[0]);
    $p->set_sku($hidden[1]);
    $p->save();
}
foreach (get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $pid) {
    if (! in_array($pid, $productsBefore, true)) {
        if ($learning) {
            (new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb))->save((int) $pid, 0, [], null, 'extend', 'revoke_on_full_refund');
        }
        wp_delete_post((int) $pid, true);
    }
}
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image']) as $att) {
    if (! in_array($att, $attachmentsBefore, true)) {
        wp_delete_attachment((int) $att, true);
    }
}
foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids']) as $term) {
    if (! in_array($term, $termsBefore, true)) {
        wp_delete_term((int) $term, 'product_cat');
    }
}
if ($learning) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$rules} WHERE id > %d", $maxRule));
}
$wpdb->query("DELETE FROM {$locations}");
$wpdb->query("DELETE FROM {$rates}");
foreach ($stashRates as $row) {
    $wpdb->insert($rates, $row);
}
foreach ($stashLocations as $row) {
    $wpdb->insert($locations, $row);
}
WC_Cache_Helper::invalidate_cache_group('taxes');
foreach ($snapshot as $k => $v) {
    $v === '__kw_missing__' ? delete_option($k) : update_option($k, $v);
}
foreach ($testUsers as $u) {
    wp_delete_user((int) $u);
}
delete_transient('kurswasser_shop_report_'.$uid);
delete_transient('kurswasser_setup_flash_'.$uid);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
