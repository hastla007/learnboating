<?php
/**
 * Einrichtungs-Assistent, Schritt 3 „Shop einrichten“ (nur mit aktivem WooCommerce).
 *
 * Ablauf: Formular (Land/Region, Anschrift, Mehrwertsteuer-Weg, Häkchen) → Vorschau „aktuell → neu“ je Einstellung,
 * Steuertabelle, Produkte (Probelauf) → Bestätigung per POST (Nonce, manage_options + manage_woocommerce) →
 * Ergebnis-Bericht. Shop-Seiten mit englischem WooCommerce-Standardtitel werden auf Wunsch deutsch benannt. Sicherheitsregel des Betreibers: Was in WooCommerce schon eingestellt ist, wird nicht
 * stillschweigend überschrieben – abweichende bestehende Werte sind in der Vorschau nicht angehakt, Steuersätze
 * werden nie gelöscht oder doppelt angelegt, Preise kommen ausschließlich von der Preis-Seite des Lern-Plugins.
 *
 * Produkte und Schnupper-Paket legt das Lern-Plugin an (ShopCatalog::applyProducts(false, false) = neue Produkte
 * als Entwurf; ShopCatalog::applyFreeTier()). Der Preis-Hinweis (z. B. Kleinunternehmer) ist die Einstellung
 * „Hinweis zum Preis“ des Lern-Plugins (bp_learning_settings[price_note]); das Plugin zeigt ihn an allen Preisen,
 * im Warenkorb, in der Kasse und in den Bestellmails.
 *
 * WP-CLI: wp kurswasser setup shop --country=DE --tax=oss|own|small [--rate=19] [--products] [--yes]
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Normalsätze der Mehrwertsteuer in den 27 EU-Ländern (Prozent), Länderkürzel wie in WooCommerce (Griechenland = GR).
 *
 * Stand siehe KURSWASSER_EU_VAT_RATES_STAND (letzte berücksichtigte Änderung). Diese Tabelle prüft der Betreiber
 * vor dem Livegang (z. B. bei der EU-Kommission „VAT rates in the EU“ bzw. mit dem Steuerberater) – Sätze ändern sich.
 * Berücksichtigt: Finnland 25,5 % (seit 01.09.2024), Luxemburg wieder 17 % (seit 01.01.2024), Slowakei 23 %
 * (seit 01.01.2025), Estland 24 % (seit 01.07.2025), Rumänien 21 % (seit 01.08.2025).
 * Nicht abgebildet: regionale Sätze (z. B. Madeira/Azoren, griechische Inseln, Büsingen/Helgoland, Åland).
 */
const KURSWASSER_EU_VAT_RATES_STAND = '2025-08-01';
const KURSWASSER_EU_VAT_RATES = [
    'AT' => 20.0, 'BE' => 21.0, 'BG' => 20.0, 'CY' => 19.0, 'CZ' => 21.0, 'DE' => 19.0, 'DK' => 25.0,
    'EE' => 24.0, 'ES' => 21.0, 'FI' => 25.5, 'FR' => 20.0, 'GR' => 24.0, 'HR' => 25.0, 'HU' => 27.0,
    'IE' => 23.0, 'IT' => 22.0, 'LT' => 21.0, 'LU' => 17.0, 'LV' => 21.0, 'MT' => 18.0, 'NL' => 21.0,
    'PL' => 23.0, 'PT' => 23.0, 'RO' => 21.0, 'SE' => 25.0, 'SI' => 22.0, 'SK' => 23.0,
];
/** Schweiz (kein EU-Land): nur Vorschlag für „eigener Satz“ (Normalsatz seit 01.01.2024). */
const KURSWASSER_CH_VAT_RATE = 8.1;
/** Name der angelegten Steuersätze (Spalte „Steuername“ in WooCommerce, erscheint in Warenkorb und Kasse). */
const KURSWASSER_TAX_RATE_NAME = 'MwSt.';

/** Schritt 3 anbieten? (WooCommerce aktiv; Filter für Tests.) */
function kurswasser_shop_available(): bool
{
    return (bool) apply_filters('kurswasser_shop_available', class_exists('WooCommerce') && function_exists('WC'));
}

function kurswasser_shop_learning_active(): bool
{
    return kurswasser_learning_compatible();
}

/** Gleicher Bruttopreis in allen EU-Ländern (OSS): WooCommerce rechnet sonst vom Netto des Shop-Landes hoch. */
add_filter('woocommerce_adjust_non_base_location_prices', static fn ($adjust) => get_option('kurswasser_shop_same_gross_price') === 'yes' ? false : $adjust);

/** Wählbare Länder: EU-27 und Schweiz (Kürzel => deutscher Name), Deutschland und Österreich zuerst. */
function kurswasser_shop_countries(): array
{
    $names = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
        'BE' => 'Belgien', 'BG' => 'Bulgarien', 'DK' => 'Dänemark', 'EE' => 'Estland', 'FI' => 'Finnland', 'FR' => 'Frankreich',
        'GR' => 'Griechenland', 'IE' => 'Irland', 'IT' => 'Italien', 'HR' => 'Kroatien', 'LV' => 'Lettland', 'LT' => 'Litauen',
        'LU' => 'Luxemburg', 'MT' => 'Malta', 'NL' => 'Niederlande', 'PL' => 'Polen', 'PT' => 'Portugal', 'RO' => 'Rumänien',
        'SE' => 'Schweden', 'SK' => 'Slowakei', 'SI' => 'Slowenien', 'ES' => 'Spanien', 'CZ' => 'Tschechien', 'HU' => 'Ungarn',
        'CY' => 'Zypern',
    ];

    return (array) apply_filters('kurswasser_shop_countries', $names);
}

/** Regionen eines Landes aus WooCommerce (Kürzel => Name), sonst []. */
function kurswasser_shop_states(string $country): array
{
    if (! function_exists('WC') || ! WC()->countries) {
        return [];
    }
    $states = WC()->countries->get_states($country);

    return is_array($states) ? $states : [];
}

/** Normalsatz-Vorschlag für „eigener Satz“ nach Land. */
function kurswasser_shop_rate_suggestion(string $country): float
{
    return $country === 'CH' ? KURSWASSER_CH_VAT_RATE : (float) (KURSWASSER_EU_VAT_RATES[$country] ?? 19.0);
}

/** Vorschlag für den Preis-Hinweis als Kleinunternehmer (passt hinter einen Preis und hinter „Alle Preise“). */
function kurswasser_shop_note_suggestion(string $country): string
{
    return match ($country) {
        'DE' => 'ohne MwSt. – Kleinunternehmer gem. § 19 UStG',
        'AT' => 'ohne USt. – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG',
        'CH' => 'ohne MwSt. – nicht mehrwertsteuerpflichtig',
        default => 'ohne MwSt. – Kleinunternehmerregelung',
    };
}

function kurswasser_shop_rate_label(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').' %';
}

/**
 * Eingaben aus Formular bzw. WP-CLI prüfen. Fehlt der Satz bei „eigener Satz“ bzw. der Hinweistext bei
 * „Kleinunternehmer“, gilt der Vorschlag für das Land.
 *
 * @return array{country:string,state:string,address:string,postcode:string,city:string,tax:string,rate:?float,note:string,sell:string,products:bool,free:bool,registration:bool,language:bool,errors:list<string>}
 */
function kurswasser_shop_input(array $raw): array
{
    $text = static fn (string $k, int $max = 200): string => mb_substr(trim(sanitize_text_field((string) ($raw[$k] ?? ''))), 0, $max);
    $errors = [];
    $country = strtoupper($text('country', 2));
    $countries = kurswasser_shop_countries();
    if (! isset($countries[$country])) {
        $errors[] = __('Bitte das Land des Shops wählen.', 'kurswasser');
    }
    $state = strtoupper($text('state', 20));
    if ($state !== '' && ! isset(kurswasser_shop_states($country)[$state])) {
        $state = '';
    }
    $tax = sanitize_key((string) ($raw['tax'] ?? ''));
    if (! in_array($tax, ['oss', 'own', 'small'], true)) {
        $errors[] = __('Bitte einen Weg für die Mehrwertsteuer wählen.', 'kurswasser');
        $tax = '';
    }
    if ($tax === 'oss' && $country !== '' && ! isset(KURSWASSER_EU_VAT_RATES[$country])) {
        $errors[] = __('Das OSS-Verfahren gibt es nur für Shops in der EU. Bitte „eigener Satz“ oder „Kleinunternehmer“ wählen.', 'kurswasser');
    }
    $rate = null;
    if ($tax === 'own') {
        $rateRaw = str_replace(',', '.', $text('rate', 10));
        if ($rateRaw === '') {
            $rate = kurswasser_shop_rate_suggestion($country);
        } elseif (! is_numeric($rateRaw) || (float) $rateRaw <= 0 || (float) $rateRaw > 30) {
            $errors[] = __('Bitte einen Steuersatz zwischen 0 und 30 % angeben, z. B. 19.', 'kurswasser');
        } else {
            $rate = round((float) $rateRaw, 2);
        }
    }
    $note = '';
    if ($tax === 'small') {
        $note = $text('note', 80);
        $note = $note !== '' ? $note : kurswasser_shop_note_suggestion($country);
    }
    $sell = ($raw['sell'] ?? 'eu') === 'all' ? 'all' : 'eu';

    return [
        'country' => $country, 'state' => $state, 'address' => $text('address'), 'postcode' => $text('postcode', 20), 'city' => $text('city', 100),
        'tax' => $tax, 'rate' => $rate, 'note' => $note, 'sell' => $sell,
        'products' => ! empty($raw['products']), 'free' => ! empty($raw['free']),
        'registration' => ! empty($raw['registration']), 'language' => ! empty($raw['language']), 'live' => ! empty($raw['live']),
        'errors' => $errors,
    ];
}

/** Rohwerte zum Vorausfüllen des Formulars: GET-Parameter, sonst letzte Wahl, sonst aus WooCommerce. */
function kurswasser_shop_form_values(): array
{
    if (isset($_GET['kw_shop']) && is_array($_GET['kw_shop'])) { // phpcs:ignore WordPress.Security.NonceVerification -- nur Vorausfüllen
        return wp_unslash($_GET['kw_shop']); // phpcs:ignore
    }
    $saved = get_option('kurswasser_shop_setup');
    if (is_array($saved) && $saved !== []) {
        return $saved;
    }
    [$country, $state] = array_pad(explode(':', (string) get_option('woocommerce_default_country', '')), 2, '');
    $country = isset(kurswasser_shop_countries()[$country]) ? $country : 'DE';

    return [
        'country' => $country, 'state' => $state, 'address' => (string) get_option('woocommerce_store_address', ''),
        'postcode' => (string) get_option('woocommerce_store_postcode', ''), 'city' => (string) get_option('woocommerce_store_city', ''),
        'tax' => '', 'rate' => '', 'note' => '', 'sell' => 'eu', 'products' => '1', 'free' => '1', 'registration' => '1', 'language' => '1', 'live' => '',
    ];
}

/** Eingaben als Formularfelder (für Vorschau → Bestätigung und „Zurück“). */
function kurswasser_shop_query_args(array $input): array
{
    $args = array_intersect_key($input, array_flip(['country', 'state', 'address', 'postcode', 'city', 'tax', 'note', 'sell']));
    $args['rate'] = $input['rate'] !== null ? (string) $input['rate'] : '';
    foreach (['products', 'free', 'registration', 'language', 'live'] as $flag) {
        $args[$flag] = $input[$flag] ? '1' : '';
    }

    return $args;
}

/*
 * Einstellungen: Vorschau und Werte.
 */

/** Werkseinstellungen von WooCommerce (bzw. des Lern-Plugins/WordPress) – solche Werte gelten als „noch nicht eingestellt“. */
function kurswasser_shop_factory_defaults(): array
{
    return [
        'woocommerce_default_country' => 'US:CA', 'woocommerce_store_address' => '', 'woocommerce_store_postcode' => '', 'woocommerce_store_city' => '',
        'woocommerce_currency' => 'USD', 'woocommerce_currency_pos' => 'left', 'woocommerce_price_thousand_sep' => ',', 'woocommerce_price_decimal_sep' => '.',
        'woocommerce_price_num_decimals' => '2', 'woocommerce_calc_taxes' => 'no', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_based_on' => 'shipping',
        'woocommerce_tax_display_shop' => 'excl', 'woocommerce_tax_display_cart' => 'excl', 'woocommerce_allowed_countries' => 'all',
        'woocommerce_specific_allowed_countries' => '', 'woocommerce_ship_to_countries' => '', 'woocommerce_enable_myaccount_registration' => 'no',
        'woocommerce_coming_soon' => 'yes', 'kurswasser_shop_same_gross_price' => '', 'bp_price_note' => 'inkl. MwSt.', 'WPLANG' => '',
    ];
}

/** Vergleichbare Form eines Werts (Listen sortiert, kommagetrennt). */
function kurswasser_shop_norm($value): string
{
    if (is_array($value)) {
        $list = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $value), 'strlen'));
        sort($list);

        return implode(',', $list);
    }

    return $value === null ? '' : trim((string) $value);
}

/** Aktueller Wert einer Einstellung (null = nicht gesetzt). */
function kurswasser_shop_current(string $key)
{
    if ($key === 'bp_price_note') {
        return kurswasser_shop_learning_active() ? \Bootspruefung\Learning\Settings\Settings::priceNote() : null;
    }

    return get_option($key, null);
}

/** Lesbare Darstellung eines Werts. */
function kurswasser_shop_value_label(string $key, $value): string
{
    $v = kurswasser_shop_norm($value);
    $yesNo = static fn (string $v): string => $v === 'yes' ? __('an', 'kurswasser') : __('aus', 'kurswasser');
    switch ($key) {
        case 'woocommerce_default_country':
            [$c, $s] = array_pad(explode(':', $v), 2, '');
            $name = kurswasser_shop_countries()[$c] ?? (function_exists('WC') && WC()->countries ? (WC()->countries->get_countries()[$c] ?? $c) : $c);
            $state = $s !== '' ? (kurswasser_shop_states($c)[$s] ?? $s) : '';

            return $v === '' ? __('(leer)', 'kurswasser') : html_entity_decode($name.($state !== '' ? ' – '.$state : ''), ENT_QUOTES);
        case 'woocommerce_currency_pos':
            return ['left' => __('links (€99,99)', 'kurswasser'), 'right' => __('rechts (99,99€)', 'kurswasser'), 'left_space' => __('links mit Leerzeichen (€ 99,99)', 'kurswasser'), 'right_space' => __('rechts mit Leerzeichen (99,99 €)', 'kurswasser')][$v] ?? $v;
        case 'woocommerce_price_thousand_sep':
        case 'woocommerce_price_decimal_sep':
            return '„'.$v.'“';
        case 'woocommerce_calc_taxes':
        case 'woocommerce_enable_myaccount_registration':
            return $yesNo($v);
        case 'woocommerce_prices_include_tax':
            return $v === 'yes' ? __('inklusive Steuer (brutto)', 'kurswasser') : __('ohne Steuer (netto)', 'kurswasser');
        case 'woocommerce_tax_based_on':
            return ['billing' => __('Rechnungsadresse', 'kurswasser'), 'shipping' => __('Lieferadresse', 'kurswasser'), 'base' => __('Shop-Adresse', 'kurswasser')][$v] ?? $v;
        case 'woocommerce_tax_display_shop':
        case 'woocommerce_tax_display_cart':
            return $v === 'incl' ? __('inkl. Steuer', 'kurswasser') : __('zzgl. Steuer', 'kurswasser');
        case 'woocommerce_allowed_countries':
            return ['all' => __('alle Länder', 'kurswasser'), 'all_except' => __('alle Länder außer …', 'kurswasser'), 'specific' => __('bestimmte Länder', 'kurswasser')][$v] ?? $v;
        case 'woocommerce_specific_allowed_countries':
            $list = $v === '' ? [] : explode(',', $v);

            return $list === [] ? __('(keine)', 'kurswasser') : sprintf(_n('%1$d Land (%2$s)', '%1$d Länder (%2$s)', count($list), 'kurswasser'), count($list), implode(', ', $list));
        case 'woocommerce_ship_to_countries':
            return ['' => __('an alle Verkaufsländer', 'kurswasser'), 'all' => __('alle Länder', 'kurswasser'), 'specific' => __('bestimmte Länder', 'kurswasser'), 'disabled' => __('kein Versand, keine Lieferadresse', 'kurswasser')][$v] ?? $v;
        case 'kurswasser_shop_same_gross_price':
            return $v === 'yes' ? __('ja – gleicher Bruttopreis in allen EU-Ländern', 'kurswasser') : __('nein – WooCommerce passt den Bruttopreis an den Satz des Kundenlands an', 'kurswasser');
        case 'bp_price_note':
            return $v === '' ? __('(kein Hinweis)', 'kurswasser') : '„'.$v.'“';
        case 'woocommerce_coming_soon':
            return $v === 'yes' ? __('„Demnächst verfügbar“ – Gäste sehen den Shop nicht', 'kurswasser') : __('sichtbar für alle', 'kurswasser');
        case 'WPLANG':
            return ['' => __('Englisch (USA)', 'kurswasser'), 'en_US' => __('Englisch (USA)', 'kurswasser'), 'de_DE' => __('Deutsch', 'kurswasser'), 'de_DE_formal' => __('Deutsch (Sie)', 'kurswasser'), 'de_AT' => __('Deutsch (Österreich)', 'kurswasser'), 'de_CH' => __('Deutsch (Schweiz)', 'kurswasser')][$v] ?? $v;
    }

    return $v === '' ? __('(leer)', 'kurswasser') : $v;
}

/**
 * Soll-Werte der Einstellungen in Anzeigereihenfolge: Schlüssel => [Bereich, Bezeichnung, Wert, Erklärung].
 */
function kurswasser_shop_targets(array $input): array
{
    $t = [];
    $location = $input['country'].($input['state'] !== '' ? ':'.$input['state'] : '');
    $t['woocommerce_default_country'] = [__('Shop-Anschrift', 'kurswasser'), __('Land / Region', 'kurswasser'), $location, __('Sitz des Shops; Grundlage für Steuer und Preisanzeige.', 'kurswasser')];
    foreach (['address' => ['woocommerce_store_address', __('Straße und Hausnummer', 'kurswasser')], 'postcode' => ['woocommerce_store_postcode', __('PLZ', 'kurswasser')], 'city' => ['woocommerce_store_city', __('Ort', 'kurswasser')]] as $field => [$key, $label]) {
        if ($input[$field] !== '') {
            $t[$key] = [__('Shop-Anschrift', 'kurswasser'), $label, $input[$field], ''];
        }
    }
    $money = __('Währung und Preise', 'kurswasser');
    $t['woocommerce_currency'] = [$money, __('Währung', 'kurswasser'), 'EUR', __('Die Preise der Preis-Seite sind Euro-Preise.', 'kurswasser')];
    $t['woocommerce_currency_pos'] = [$money, __('Position des Währungssymbols', 'kurswasser'), 'right_space', ''];
    $t['woocommerce_price_thousand_sep'] = [$money, __('Tausender-Trennzeichen', 'kurswasser'), '.', ''];
    $t['woocommerce_price_decimal_sep'] = [$money, __('Dezimal-Trennzeichen', 'kurswasser'), ',', ''];
    $t['woocommerce_price_num_decimals'] = [$money, __('Nachkommastellen', 'kurswasser'), '2', ''];
    $tax = __('Steuer', 'kurswasser');
    $t['woocommerce_calc_taxes'] = [$tax, __('Steuerberechnung', 'kurswasser'), $input['tax'] === 'small' ? 'no' : 'yes', $input['tax'] === 'small' ? __('Kleinunternehmer: keine Steuer.', 'kurswasser') : ''];
    $t['woocommerce_prices_include_tax'] = [$tax, __('Eingegebene Preise', 'kurswasser'), 'yes', __('Die Preise der Preis-Seite sind Bruttopreise.', 'kurswasser')];
    if ($input['tax'] !== 'small') {
        $t['woocommerce_tax_based_on'] = [$tax, __('Steuer berechnen nach', 'kurswasser'), 'billing', __('Digitale Leistungen: Ort des Kunden = Rechnungsadresse.', 'kurswasser')];
        $t['woocommerce_tax_display_shop'] = [$tax, __('Preise im Shop anzeigen', 'kurswasser'), 'incl', ''];
        $t['woocommerce_tax_display_cart'] = [$tax, __('Preise in Warenkorb und Kasse', 'kurswasser'), 'incl', ''];
    }
    if ($input['tax'] === 'oss') {
        $t['kurswasser_shop_same_gross_price'] = [$tax, __('Gleicher Bruttopreis für alle EU-Kunden', 'kurswasser'), 'yes', __('Der Preis der Preis-Seite gilt überall; nur der enthaltene MwSt.-Anteil ändert sich. Ohne diese Einstellung rechnet WooCommerce den Nettopreis des Shop-Lands mit dem Satz des Kundenlands hoch (z. B. 29,90 € in DE, 30,15 € in AT).', 'kurswasser')];
    }
    if (kurswasser_shop_learning_active()) {
        $t['bp_price_note'] = [$tax, __('Hinweis zum Preis (Lern-Plugin)', 'kurswasser'), $input['tax'] === 'small' ? $input['note'] : 'inkl. MwSt.',
            __('Steht an allen Preisen, in Warenkorb und Kasse („Alle Preise …“) und in den Bestellmails. Später änderbar unter Bootsprüfung › Einstellungen › Hinweis zum Preis.', 'kurswasser')];
    }
    $sales = __('Verkauf und Versand', 'kurswasser');
    $t['woocommerce_allowed_countries'] = [$sales, __('Verkauf an', 'kurswasser'), $input['sell'] === 'eu' ? 'specific' : 'all', ''];
    if ($input['sell'] === 'eu') {
        $list = array_keys(KURSWASSER_EU_VAT_RATES);
        $list[] = 'CH';
        sort($list);
        $t['woocommerce_specific_allowed_countries'] = [$sales, __('Verkaufsländer', 'kurswasser'), $list, __('Die 27 EU-Länder und die Schweiz.', 'kurswasser')];
    }
    $t['woocommerce_ship_to_countries'] = [$sales, __('Versand', 'kurswasser'), 'disabled', __('Nur digitale Produkte: kein Versand, an der Kasse keine Lieferadresse.', 'kurswasser')];
    $site = __('Konten und Sprache', 'kurswasser');
    if ($input['registration']) {
        $t['woocommerce_enable_myaccount_registration'] = [$site, __('Kundenkonto auf „Mein Konto“ anlegen', 'kurswasser'), 'yes', __('Damit „Kostenlos testen“ für Gäste funktioniert: Der Lernbereich zeigt dann „Konto anlegen“.', 'kurswasser')];
    }
    if ($input['live']) {
        $t['woocommerce_coming_soon'] = [__('Sichtbarkeit', 'kurswasser'), __('Shop für Kunden sichtbar', 'kurswasser'), 'no', __('Schaltet den WooCommerce-Modus „Demnächst verfügbar“ aus: Gäste sehen Shop, Produkte, Warenkorb und Kasse. Erst einschalten, wenn Preise und Rechtstexte stehen.', 'kurswasser')];
    }
    if ($input['language']) {
        $t['WPLANG'] = [$site, __('Sprache der Website', 'kurswasser'), 'de_DE', __('Lädt das deutsche Sprachpaket von wordpress.org (und die Übersetzungen der Plugins).', 'kurswasser')];
    }

    return $t;
}

/**
 * Von WooCommerce angelegte Shop-Seiten: Option => [englische Standardtitel, englischer Standard-Slug, deutscher Titel, deutscher Slug].
 * Wird WooCommerce vor der Sprachumstellung aktiviert, heißen die Seiten „Cart“, „Checkout“, „My account“ (UX-10).
 */
function kurswasser_shop_page_names(): array
{
    return [
        'woocommerce_cart_page_id' => [['Cart'], 'cart', __('Warenkorb', 'kurswasser'), 'warenkorb'],
        'woocommerce_checkout_page_id' => [['Checkout'], 'checkout', __('Kasse', 'kurswasser'), 'kasse'],
        'woocommerce_myaccount_page_id' => [['My account', 'My Account'], 'my-account', __('Mein Konto', 'kurswasser'), 'mein-konto'],
    ];
}

/**
 * Shop-Seiten, die noch den englischen WooCommerce-Standardtitel tragen. Von Hand benannte Seiten bleiben, wie sie sind;
 * der Slug wird nur ersetzt, wenn er noch der englische Standard ist.
 *
 * @return list<array{option:string,id:int,title:string,slug:string,new_title:string,new_slug:string}>
 */
function kurswasser_shop_pages_plan(): array
{
    $rows = [];
    foreach (kurswasser_shop_page_names() as $option => [$titles, $slug, $newTitle, $newSlug]) {
        $id = (int) get_option($option, 0);
        $post = $id > 0 ? get_post($id) : null;
        if (! $post instanceof WP_Post || $post->post_type !== 'page' || ! in_array(trim($post->post_title), $titles, true)) {
            continue;
        }
        $rows[] = ['option' => $option, 'id' => $id, 'title' => $post->post_title, 'slug' => $post->post_name, 'new_title' => $newTitle, 'new_slug' => $post->post_name === $slug ? $newSlug : $post->post_name];
    }

    return $rows;
}

/**
 * Bestehende Steuersätze der Standard-Klasse.
 *
 * @return list<array{id:int,country:string,state:string,rate:float,name:string}>
 */
function kurswasser_shop_existing_rates(): array
{
    global $wpdb;
    $rows = $wpdb->get_results("SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = '' ORDER BY tax_rate_order, tax_rate_id", ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    return array_map(static fn ($r) => ['id' => (int) $r['tax_rate_id'], 'country' => (string) $r['tax_rate_country'], 'state' => (string) $r['tax_rate_state'], 'rate' => (float) $r['tax_rate'], 'name' => (string) $r['tax_rate_name']], is_array($rows) ? $rows : []);
}

/**
 * Plan der Steuersätze: status new (wird angelegt) | exists (gleiches Land + Satz + Klasse vorhanden) |
 * conflict (anderer Satz für das Land vorhanden – wird nicht angelegt, nichts gelöscht).
 *
 * @return array{rows:list<array{country:string,rate:float,status:string,existing:list<float>}>,other:list<array>}
 */
function kurswasser_shop_rate_plan(array $input): array
{
    $wanted = match ($input['tax']) {
        'oss' => KURSWASSER_EU_VAT_RATES,
        'own' => ['' => (float) $input['rate']],
        default => [],
    };
    $existing = kurswasser_shop_existing_rates();
    $rows = [];
    foreach ($wanted as $country => $rate) {
        $country = (string) $country;
        $same = array_filter($existing, static fn ($r) => $r['country'] === $country);
        $rates = array_map(static fn ($r) => $r['rate'], $same);
        $hit = array_filter($rates, static fn ($r) => abs($r - (float) $rate) < 0.00005);
        $rows[] = ['country' => $country, 'rate' => (float) $rate, 'status' => $hit ? 'exists' : ($rates ? 'conflict' : 'new'), 'existing' => array_values($rates)];
    }
    $other = $wanted === [] ? [] : array_values(array_filter($existing, static fn ($r) => ! array_key_exists($r['country'], $wanted)));

    return ['rows' => $rows, 'other' => $other];
}

/**
 * Vorschau: was sich ändern würde. Schreibt nichts.
 *
 * settings: Schlüssel => [group,label,hint,current,new,state same|default|differs,checked]
 * – same: schon so (nichts zu tun); default: Werkseinstellung bzw. leer (angehakt); differs: von dir anders
 *   eingestellt (nicht angehakt, wird nur mit Häkchen geändert).
 */
function kurswasser_shop_plan(array $input): array
{
    $defaults = kurswasser_shop_factory_defaults();
    $settings = [];
    foreach (kurswasser_shop_targets($input) as $key => [$group, $label, $new, $hint]) {
        $current = kurswasser_shop_current($key);
        $cur = kurswasser_shop_norm($current);
        $isDefault = $current === null || $cur === kurswasser_shop_norm($defaults[$key] ?? '') || ($key === 'WPLANG' && $cur === 'en_US');
        // Länderliste gilt nur bei „bestimmte Länder“; eine alte Liste ohne Wirkung zählt nicht als eingestellt.
        if ($key === 'woocommerce_specific_allowed_countries' && get_option('woocommerce_allowed_countries') !== 'specific') {
            $isDefault = true;
        }
        $state = $cur === kurswasser_shop_norm($new) ? 'same' : ($isDefault ? 'default' : 'differs');
        $settings[$key] = compact('group', 'label', 'hint', 'current', 'new', 'state') + ['checked' => $state === 'default'];
    }
    $rates = kurswasser_shop_rate_plan($input);
    $products = null;
    $conflicts = [];
    $free = null;
    $notes = [];
    if ($input['products'] || $input['free']) {
        if (! kurswasser_shop_learning_active()) {
            $notes[] = ['warning', __('Produkte und Schnupper-Paket legt das Lern-Plugin an – es ist nicht aktiv (Schritt 1).', 'kurswasser')];
        }
    }
    if (kurswasser_shop_learning_active()) {
        $catalog = \Bootspruefung\Learning\Commerce\ShopCatalog::class;
        if ($input['products']) {
            try {
                $products = $catalog::applyProducts(true, false);
                $conflicts = $catalog::saleConflicts();
            } catch (\RuntimeException $e) {
                $notes[] = ['error', $e->getMessage()];
            }
        }
        if ($input['free']) {
            $free = $catalog::applyFreeTier(true);
        }
        if (\Bootspruefung\Learning\Settings\Settings::get('default_access') === 'logged_in') {
            $notes[] = ['warning', __('Im Lern-Plugin dürfen derzeit alle angemeldeten Nutzer alles lernen (Bootsprüfung › Einstellungen › Zugang ohne Shop). Vor dem Verkaufsstart auf „Nur kostenlose Regeln und Freischaltungen“ umstellen – erst dann gelten Käufe und das Schnupper-Paket. Der Assistent ändert das nicht.', 'kurswasser')];
        }
    } elseif ($input['tax'] === 'small') {
        $notes[] = ['warning', __('Den Kleinunternehmer-Hinweis an den Preisen zeigt das Lern-Plugin. Es ist nicht aktiv – nach dem Aktivieren diesen Schritt bitte erneut ausführen.', 'kurswasser')];
    }
    if ($rates['other'] !== []) {
        $list = implode(', ', array_map(static fn ($r) => ($r['country'] === '' ? __('alle Länder', 'kurswasser') : $r['country']).' '.kurswasser_shop_rate_label($r['rate']), $rates['other']));
        $notes[] = ['warning', sprintf(__('Weitere Steuersätze in der Standard-Klasse bleiben bestehen und gelten weiter: %s. Bei Bedarf unter WooCommerce › Einstellungen › Steuer › Standard entfernen.', 'kurswasser'), $list)];
    }
    if (array_filter($rates['rows'], static fn ($r) => $r['status'] === 'conflict')) {
        $notes[] = ['warning', __('Für manche Länder gibt es schon einen anderen Satz. Der Assistent legt dort keinen zweiten an und löscht nichts – bitte den vorhandenen Satz prüfen.', 'kurswasser')];
    }
    if ($conflicts !== []) {
        $notes[] = ['error', sprintf(__('Produkte werden nicht abgeglichen: Bei %s ist der reguläre Preis nicht höher als der Angebotspreis im Shop. Bitte unter Bootsprüfung › Preise abgleichen.', 'kurswasser'), implode(', ', array_keys($conflicts)))];
    }

    if (! $input['live'] && get_option('woocommerce_coming_soon') === 'yes') {
        $notes[] = ['info', __('Der Shop ist im WooCommerce-Modus „Demnächst verfügbar“: Gäste sehen Shop, Produkte, Warenkorb und Kasse nicht. Zum Livegang hier „Shop für Kunden sichtbar machen“ wählen oder unter WooCommerce › Einstellungen › Sichtbarkeit umstellen.', 'kurswasser')];
    }

    return ['input' => $input, 'settings' => $settings, 'rates' => $rates, 'pages' => kurswasser_shop_pages_plan(), 'products' => $products, 'conflicts' => $conflicts, 'free' => $free, 'notes' => $notes];
}

/** Vorausgewählte Punkte der Vorschau (für WP-CLI ohne --overwrite und als Formular-Vorgabe). */
function kurswasser_shop_default_selection(array $plan, bool $overwrite = false): array
{
    $keys = array_keys(array_filter($plan['settings'], static fn ($s) => $s['checked'] || ($overwrite && $s['state'] === 'differs')));
    if (array_filter($plan['rates']['rows'], static fn ($r) => $r['status'] === 'new')) {
        $keys[] = 'tax_rates';
    }
    if (($plan['pages'] ?? []) !== []) {
        $keys[] = 'pages';
    }
    if ($plan['products'] !== null && $plan['conflicts'] === []) {
        $keys[] = 'products';
    }
    if ($plan['free'] !== null) {
        $keys[] = 'free';
    }

    return $keys;
}

function kurswasser_shop_seen_hash($value): string
{
    return md5(kurswasser_shop_norm($value));
}

/*
 * Anwenden.
 */

/**
 * Ausgewählte Punkte schreiben. $seen (Schlüssel => Hash des in der Vorschau gezeigten Werts): hat sich ein Wert
 * seitdem geändert, wird er nicht überschrieben. Prüft keine Rechte (das tun Formular-Handler bzw. WP-CLI).
 *
 * @return array{done:list<string>,skipped:list<string>,errors:list<string>,notes:list<array>,products:?list<array>,free:?array}
 */
function kurswasser_shop_apply(array $input, array $selected, ?array $seen = null): array
{
    $report = ['done' => [], 'skipped' => [], 'errors' => [], 'notes' => [], 'products' => null, 'free' => null];
    if (! empty($input['errors'])) {
        $report['errors'] = $input['errors'];

        return $report;
    }
    if (! kurswasser_shop_available()) {
        $report['errors'][] = __('WooCommerce ist nicht aktiv.', 'kurswasser');

        return $report;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
    $plan = kurswasser_shop_plan($input);
    $report['notes'] = array_values(array_filter($plan['notes'], static fn ($n) => $n[0] !== 'error'));
    $language = null;
    $q = static fn (string $v): string => str_starts_with($v, '„') ? $v : '„'.$v.'“';
    foreach ($plan['settings'] as $key => $s) {
        $name = $s['label'];
        $new = $q(kurswasser_shop_value_label($key, $s['new']));
        if ($s['state'] === 'same') {
            $report['skipped'][] = sprintf(__('%1$s: schon %2$s.', 'kurswasser'), $name, $new);
            continue;
        }
        if (! in_array($key, $selected, true)) {
            $report['skipped'][] = sprintf(__('%1$s: nicht übernommen, bleibt %2$s.', 'kurswasser'), $name, $q(kurswasser_shop_value_label($key, $s['current'])));
            continue;
        }
        if ($seen !== null && isset($seen[$key]) && ! hash_equals((string) $seen[$key], kurswasser_shop_seen_hash($s['current']))) {
            $report['skipped'][] = sprintf(__('%s: wurde seit der Vorschau geändert – nicht überschrieben. Bitte die Vorschau neu laden.', 'kurswasser'), $name);
            continue;
        }
        if ($key === 'WPLANG') {
            $language = $s;
            continue; // zuletzt (Download)
        }
        if ($key === 'bp_price_note') {
            $all = \Bootspruefung\Learning\Settings\Settings::all();
            $all['price_note'] = (string) $s['new'];
            update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $all);
        } else {
            update_option($key, $s['new']);
        }
        $report['done'][] = sprintf(__('%1$s: %2$s → %3$s.', 'kurswasser'), $name, $q(kurswasser_shop_value_label($key, $s['current'])), $new);
    }

    // Steuersätze (nie löschen, nie doppelt).
    $rows = $plan['rates']['rows'];
    $country = static fn (string $c): string => $c === '' ? __('alle Länder', 'kurswasser') : $c;
    $missing = array_filter($rows, static fn ($r) => $r['status'] === 'new');
    if ($missing !== [] && ! in_array('tax_rates', $selected, true)) {
        $report['skipped'][] = __('Steuersätze: nicht angelegt (nicht ausgewählt).', 'kurswasser');
    } elseif ($rows !== []) {
        $created = [];
        $order = count(kurswasser_shop_existing_rates());
        foreach ($rows as $r) {
            if ($r['status'] === 'exists') {
                continue;
            }
            if ($r['status'] === 'conflict') {
                $report['skipped'][] = sprintf(__('Steuersatz %1$s %2$s nicht angelegt: Es gibt schon %3$s – bitte prüfen.', 'kurswasser'), $country($r['country']), kurswasser_shop_rate_label($r['rate']), implode(', ', array_map('kurswasser_shop_rate_label', $r['existing'])));
                continue;
            }
            $id = WC_Tax::_insert_tax_rate([
                'tax_rate_country' => $r['country'], 'tax_rate_state' => '', 'tax_rate' => (string) $r['rate'], 'tax_rate_name' => KURSWASSER_TAX_RATE_NAME,
                'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => $order++, 'tax_rate_class' => '',
            ]);
            if ($id > 0) {
                $created[] = $country($r['country']).' '.kurswasser_shop_rate_label($r['rate']);
            } else {
                $report['errors'][] = sprintf(__('Steuersatz %s konnte nicht angelegt werden.', 'kurswasser'), $country($r['country']));
            }
        }
        $exists = count(array_filter($rows, static fn ($r) => $r['status'] === 'exists'));
        if ($created !== []) {
            $report['done'][] = sprintf(_n('%1$d Steuersatz angelegt: %2$s.', '%1$d Steuersätze angelegt: %2$s.', count($created), 'kurswasser'), count($created), implode(', ', $created));
        }
        if ($exists > 0) {
            $report['skipped'][] = sprintf(_n('%d Steuersatz war schon vorhanden.', '%d Steuersätze waren schon vorhanden.', $exists, 'kurswasser'), $exists);
        }
    }

    // Shop-Seiten mit englischem WooCommerce-Standardtitel umbenennen (Titel und Slug).
    if ($plan['pages'] !== [] && ! in_array('pages', $selected, true)) {
        $report['skipped'][] = __('Shop-Seiten: nicht umbenannt (nicht ausgewählt).', 'kurswasser');
    } else {
        foreach ($plan['pages'] as $page) {
            $result = wp_update_post(['ID' => $page['id'], 'post_title' => $page['new_title'], 'post_name' => $page['new_slug']], true);
            if (is_wp_error($result) || (int) $result === 0) {
                $report['errors'][] = sprintf(__('Seite „%s“ konnte nicht umbenannt werden.', 'kurswasser'), $page['title']);
                continue;
            }
            $report['done'][] = sprintf(__('Seite „%1$s“ (/%2$s/) → „%3$s“ (/%4$s/).', 'kurswasser'), $page['title'], $page['slug'], $page['new_title'], (string) get_post_field('post_name', $page['id']));
        }
    }

    // Produkte (neue als Entwurf) und Schnupper-Paket – über das Lern-Plugin.
    if (kurswasser_shop_learning_active()) {
        $catalog = \Bootspruefung\Learning\Commerce\ShopCatalog::class;
        if ($input['products'] && ! in_array('products', $selected, true)) {
            $report['skipped'][] = __('Produkte: nicht angelegt (nicht ausgewählt).', 'kurswasser');
        } elseif ($input['products'] && ($conflicts = $catalog::saleConflicts()) !== []) {
            $report['errors'][] = sprintf(__('Produkte nicht abgeglichen: Bei %s ist der reguläre Preis nicht höher als der Angebotspreis im Shop. Bitte unter Bootsprüfung › Preise abgleichen.', 'kurswasser'), implode(', ', array_keys($conflicts)));
        } elseif ($input['products']) {
            try {
                $rows = $catalog::applyProducts(false, false);
                $report['products'] = $rows;
                $new = count(array_filter($rows, static fn ($r) => $r['action'] === 'neu'));
                $report['done'][] = sprintf(__('Produktkatalog abgeglichen: %1$d Produkte, davon %2$d neu (als Entwurf). Veröffentlichen unter Produkte bzw. Bootsprüfung › Kostenlose Bereiche.', 'kurswasser'), count($rows), $new);
                $hand = array_filter($rows, static fn ($r) => ! empty($r['skipped']));
                if ($hand !== []) {
                    $report['skipped'][] = sprintf(_n('Bei %d Produkt blieben von Hand geänderte Felder erhalten.', 'Bei %d Produkten blieben von Hand geänderte Felder erhalten.', count($hand), 'kurswasser'), count($hand));
                }
            } catch (\RuntimeException $e) {
                $report['errors'][] = $e->getMessage();
            }
        }
        if ($input['free'] && ! in_array('free', $selected, true)) {
            $report['skipped'][] = __('Schnupper-Paket: nicht angelegt (nicht ausgewählt).', 'kurswasser');
        } elseif ($input['free']) {
            $report['free'] = $catalog::applyFreeTier();
            $report[$report['free']['added'] > 0 ? 'done' : 'skipped'][] = $report['free']['added'] > 0
                ? sprintf(__('Schnupper-Paket: %d Regel(n) angelegt.', 'kurswasser'), $report['free']['added'])
                : __('Schnupper-Paket: schon vollständig vorhanden.', 'kurswasser');
        }
    } elseif ($input['products'] || $input['free']) {
        $report['errors'][] = __('Produkte und Schnupper-Paket nicht angelegt: Das Lern-Plugin ist nicht aktiv.', 'kurswasser');
    }

    if ($language !== null) {
        $result = kurswasser_shop_install_language((string) $language['new']);
        if ($result['ok']) {
            update_option('WPLANG', $language['new']);
            $report['done'][] = sprintf(__('%1$s: %2$s → %3$s.', 'kurswasser'), $language['label'], $q(kurswasser_shop_value_label('WPLANG', $language['current'])), $q(kurswasser_shop_value_label('WPLANG', $language['new']))).($result['message'] !== '' ? ' '.$result['message'] : '');
        } else {
            $report['errors'][] = $result['message'];
        }
    }

    update_option('kurswasser_shop_setup', kurswasser_shop_query_args($input), false);

    return $report;
}

/**
 * Sprachpaket laden (wp_download_language_pack) und danach die Übersetzungen der Plugins und Themes.
 *
 * @return array{ok:bool,message:string}
 */
function kurswasser_shop_install_language(string $locale): array
{
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/translation-install.php';
    $installed = in_array($locale, get_available_languages(), true);
    if (! $installed) {
        $why = '';
        if (! wp_can_install_language_pack()) {
            $why = __('WordPress darf bzw. kann hier keine Sprachdateien schreiben (DISALLOW_FILE_MODS oder fehlende Schreibrechte für wp-content/languages).', 'kurswasser');
        } elseif (wp_download_language_pack($locale) !== $locale) {
            $why = __('wordpress.org ist nicht erreichbar oder lieferte das Paket nicht.', 'kurswasser');
        }
        if ($why !== '') {
            return ['ok' => false, 'message' => sprintf(__('Das deutsche Sprachpaket konnte nicht geladen werden: %s Die Website-Sprache bleibt unverändert; alle anderen Punkte wurden trotzdem ausgeführt. Nachholen unter Einstellungen › Allgemein › Sprache der Website.', 'kurswasser'), $why)];
        }
    }
    // Übersetzungen der Plugins (u. a. WooCommerce) und Themes – nur ein Versuch, Fehler halten nichts auf.
    $extra = '';
    try {
        require_once ABSPATH.'wp-admin/includes/update.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        wp_clean_update_cache();
        wp_update_plugins();
        wp_update_themes();
        $updates = wp_get_translation_updates();
        if ($updates !== []) {
            $result = (new Language_Pack_Upgrader(new Automatic_Upgrader_Skin()))->bulk_upgrade($updates);
            $ok = is_array($result) ? count(array_filter($result, static fn ($r) => $r && ! is_wp_error($r))) : 0;
            $extra = sprintf(__('%d Übersetzung(en) für Plugins und Themes geladen.', 'kurswasser'), $ok);
        }
    } catch (\Throwable $e) {
        $extra = __('Übersetzungen der Plugins konnten nicht geladen werden (später unter Dashboard › Aktualisierungen).', 'kurswasser');
    }

    return ['ok' => true, 'message' => trim(($installed ? __('Sprachpaket war schon installiert.', 'kurswasser') : __('Sprachpaket geladen.', 'kurswasser')).' '.$extra)];
}

/*
 * Formular-Handler.
 */

add_action('admin_post_kurswasser_setup_shop', 'kurswasser_setup_shop_handle');

function kurswasser_setup_shop_handle(): void
{
    if (! current_user_can('manage_options') || ! current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Keine Berechtigung.', 'kurswasser'), '', ['response' => 403]);
    }
    check_admin_referer('kurswasser_setup_shop');
    $raw = isset($_POST['kw_shop']) && is_array($_POST['kw_shop']) ? wp_unslash($_POST['kw_shop']) : [];
    $clean = static fn (array $list): array => array_values(array_filter(array_map(static fn ($k) => preg_replace('/[^A-Za-z0-9_]/', '', (string) $k), $list)));
    $selected = isset($_POST['kw_apply']) && is_array($_POST['kw_apply']) ? $clean(wp_unslash($_POST['kw_apply'])) : [];
    $seen = [];
    if (isset($_POST['kw_seen']) && is_array($_POST['kw_seen'])) {
        foreach (wp_unslash($_POST['kw_seen']) as $k => $hash) {
            $seen[(string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $k)] = preg_replace('/[^a-f0-9]/', '', (string) $hash);
        }
    }
    $input = kurswasser_shop_input((array) $raw);
    set_transient('kurswasser_shop_report_'.get_current_user_id(), ['input' => kurswasser_shop_query_args($input), 'report' => kurswasser_shop_apply($input, $selected, $seen)], 30 * MINUTE_IN_SECONDS);
    wp_safe_redirect(kurswasser_setup_url(['kw_view' => 'shop-result']));
    exit;
}

/*
 * Ausgabe.
 */

function kurswasser_setup_shop_styles(): void
{
    echo '<style>.kw-setup .kw-shop-table td,.kw-setup .kw-shop-table th{vertical-align:top}.kw-setup tr.kw-differs td{background:#fcf9e8}.kw-setup tr.kw-same td{color:#646970}'
        .'.kw-setup .kw-shop-form th{width:200px}.kw-setup .kw-rates{columns:3;max-width:700px}.kw-setup .kw-rates li{margin:0}'
        .'.kw-setup .kw-report li{margin:.2em 0}.kw-setup .kw-pill{display:inline-block;padding:0 7px;border-radius:9px;font-size:12px;background:#f0f0f1}'
        .'.kw-setup .kw-pill.is-new{background:#edfaef;color:#00630f}.kw-setup .kw-pill.is-warn{background:#fcf9e8;color:#6e4b00}</style>';
}

/** Karte „3. Shop einrichten“ auf der Hauptseite (nur mit WooCommerce). */
function kurswasser_setup_shop_card(): void
{
    if (! kurswasser_shop_available()) {
        return;
    }
    kurswasser_setup_shop_styles();
    $v = kurswasser_shop_form_values();
    $val = static fn (string $k): string => (string) ($v[$k] ?? '');
    $on = static fn (string $k): bool => ! empty($v[$k]);
    $countries = kurswasser_shop_countries();
    $learning = kurswasser_shop_learning_active();
    echo '<div class="card" id="kw-setup-shop"><h2>'.esc_html__('3. Shop einrichten', 'kurswasser').'</h2>';
    echo '<p>'.esc_html__('Grundeinstellungen für WooCommerce: Anschrift, Euro-Preise im deutschen Format, Mehrwertsteuer, Verkauf ohne Versand (nur digitale Produkte), Kundenkonten und Sprache. Zuerst siehst du eine Vorschau „aktuell → neu“; geändert wird erst nach deiner Bestätigung. Was du in WooCommerce schon anders eingestellt hast, bleibt, solange du es nicht anhakst.', 'kurswasser').'</p>';
    echo '<form method="get" action="'.esc_url(admin_url('themes.php')).'" class="kw-shop-form"><input type="hidden" name="page" value="'.esc_attr(KURSWASSER_SETUP_PAGE).'"><input type="hidden" name="kw_view" value="shop">';
    echo '<table class="form-table" role="presentation"><tbody>';
    // Land und Region
    echo '<tr><th scope="row"><label for="kw-shop-country">'.esc_html__('Land', 'kurswasser').'</label></th><td><select id="kw-shop-country" name="kw_shop[country]" required>';
    foreach ($countries as $code => $name) {
        $suggest = kurswasser_shop_rate_suggestion((string) $code);
        echo '<option value="'.esc_attr((string) $code).'" data-rate="'.esc_attr((string) $suggest).'" data-note="'.esc_attr(kurswasser_shop_note_suggestion((string) $code)).'" data-eu="'.(isset(KURSWASSER_EU_VAT_RATES[$code]) ? '1' : '0').'"'.selected($val('country'), $code, false).'>'.esc_html($name).'</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="kw-shop-state">'.esc_html__('Region (optional)', 'kurswasser').'</label></th><td><select id="kw-shop-state" name="kw_shop[state]"><option value="">'.esc_html__('– keine Angabe –', 'kurswasser').'</option>';
    foreach ($countries as $code => $name) {
        $states = kurswasser_shop_states((string) $code);
        if ($states === []) {
            continue;
        }
        echo '<optgroup label="'.esc_attr($name).'" data-country="'.esc_attr((string) $code).'">';
        foreach ($states as $sc => $sn) {
            echo '<option value="'.esc_attr((string) $sc).'"'.selected($val('country') === $code ? $val('state') : '', $sc, false).'>'.esc_html(html_entity_decode((string) $sn, ENT_QUOTES)).'</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></td></tr>';
    foreach (['address' => __('Straße und Hausnummer', 'kurswasser'), 'postcode' => __('PLZ', 'kurswasser'), 'city' => __('Ort', 'kurswasser')] as $f => $label) {
        echo '<tr><th scope="row"><label for="kw-shop-'.esc_attr($f).'">'.esc_html($label).'</label></th><td><input type="text" id="kw-shop-'.esc_attr($f).'" name="kw_shop['.esc_attr($f).']" value="'.esc_attr($val($f)).'" class="'.($f === 'postcode' ? 'small-text' : 'regular-text').'"></td></tr>';
    }
    // Mehrwertsteuer
    echo '<tr><th scope="row">'.esc_html__('Mehrwertsteuer', 'kurswasser').'</th><td><fieldset>';
    $ways = [
        'oss' => [__('OSS: Satz des Kundenlands (EU)', 'kurswasser'),
            __('Für Shops, die am OSS-Verfahren (One-Stop-Shop) teilnehmen – Pflicht, sobald die Verkäufe an Privatkunden in anderen EU-Ländern 10.000 € im Jahr übersteigen. Es werden die Normalsätze aller 27 EU-Länder angelegt; WooCommerce berechnet die Steuer nach der Rechnungsadresse. Kunden außerhalb der EU zahlen keine Mehrwertsteuer.', 'kurswasser')],
        'own' => [__('Nur eigener Satz für alle Kunden', 'kurswasser'),
            __('Ein Satz für alle Kunden, z. B. 19 % in Deutschland oder 20 % in Österreich – solange du nicht am OSS teilnimmst (unter der EU-Lieferschwelle).', 'kurswasser')],
        'small' => [__('Kleinunternehmer: keine Mehrwertsteuer', 'kurswasser'),
            __('Keine Steuerberechnung. Unter den Preisen, in Warenkorb und Kasse und in den Bestellmails steht ein Hinweis – in Deutschland nach § 19 UStG, in Österreich nach der Kleinunternehmerregelung (§ 6 Abs. 1 Z 27 UStG).', 'kurswasser')],
    ];
    foreach ($ways as $way => [$label, $help]) {
        echo '<div class="kw-choice"><label><input type="radio" name="kw_shop[tax]" value="'.esc_attr($way).'"'.checked($val('tax'), $way, false).' required> <strong>'.esc_html($label).'</strong></label><p>'.esc_html($help).'</p>';
        if ($way === 'own') {
            $rate = $val('rate') !== '' ? $val('rate') : (string) kurswasser_shop_rate_suggestion($val('country') ?: 'DE');
            echo '<p><label>'.esc_html__('Satz in %:', 'kurswasser').' <input type="number" id="kw-shop-rate" name="kw_shop[rate]" value="'.esc_attr(str_replace(',', '.', $rate)).'" min="0.1" max="30" step="0.1" class="small-text"></label> <span class="description">'.esc_html__('Vorschlag nach Land: DE 19, AT 20.', 'kurswasser').'</span></p>';
        }
        if ($way === 'small') {
            $note = $val('note') !== '' ? $val('note') : kurswasser_shop_note_suggestion($val('country') ?: 'DE');
            echo '<p><label>'.esc_html__('Hinweistext:', 'kurswasser').' <input type="text" id="kw-shop-note" name="kw_shop[note]" value="'.esc_attr($note).'" maxlength="80" class="regular-text"></label><br><span class="description">'.esc_html__('Steht hinter jedem Preis und in Warenkorb und Kasse als „Alle Preise …“ (höchstens 80 Zeichen). Später änderbar unter Bootsprüfung › Einstellungen › Hinweis zum Preis.', 'kurswasser').'</span></p>';
        }
        echo '</div>';
    }
    echo '<p class="description"><strong>'.esc_html__('Welcher Weg für dich gilt, klärst du im Zweifel mit deinem Steuerberater.', 'kurswasser').'</strong> '.esc_html__('Bestehende Steuersätze werden nie gelöscht; Sätze und Einstellungen kannst du später unter WooCommerce › Einstellungen › Steuer ändern.', 'kurswasser').'</p>';
    echo '</fieldset></td></tr>';
    echo '<tr><th scope="row">'.esc_html__('Verkauf an', 'kurswasser').'</th><td><fieldset>'
        .'<label><input type="radio" name="kw_shop[sell]" value="eu"'.checked($val('sell') !== 'all', true, false).'> '.esc_html__('Kunden in der EU und der Schweiz (empfohlen)', 'kurswasser').'</label><br>'
        .'<label><input type="radio" name="kw_shop[sell]" value="all"'.checked($val('sell'), 'all', false).'> '.esc_html__('Kunden in allen Ländern', 'kurswasser').'</label>'
        .'<p class="description">'.esc_html__('Nur digitale Produkte: Versand und Lieferadresse werden abgeschaltet.', 'kurswasser').'</p></fieldset></td></tr>';
    echo '<tr><th scope="row">'.esc_html__('Außerdem', 'kurswasser').'</th><td><fieldset>';
    if ($learning) {
        echo '<label><input type="checkbox" name="kw_shop[products]" value="1"'.checked($on('products'), true, false).'> '.esc_html__('Produkte als Entwurf anlegen (Katalog des Lern-Plugins, Preise von der Preis-Seite; nichts wird veröffentlicht)', 'kurswasser').'</label><br>';
        echo '<label><input type="checkbox" name="kw_shop[free]" value="1"'.checked($on('free'), true, false).'> '.esc_html__('Schnupper-Paket anlegen (kostenloser Teilumfang je Kurs für „Kostenlos testen“)', 'kurswasser').'</label><br>';
    } else {
        echo '<p class="description">'.esc_html__('Produkte und Schnupper-Paket: erst mit aktivem Lern-Plugin (Schritt 1).', 'kurswasser').'</p>';
    }
    echo '<label><input type="checkbox" name="kw_shop[registration]" value="1"'.checked($on('registration'), true, false).'> '.esc_html__('Kunden-Registrierung einschalten (Konto anlegen auf „Mein Konto“)', 'kurswasser').'</label><br>';
    echo '<label><input type="checkbox" name="kw_shop[language]" value="1"'.checked($on('language'), true, false).'> '.esc_html__('Website-Sprache Deutsch (lädt das Sprachpaket von wordpress.org)', 'kurswasser').'</label><br>';
    echo '<label><input type="checkbox" name="kw_shop[live]" value="1"'.checked($on('live'), true, false).'> '.esc_html__('Shop für Kunden sichtbar machen (WooCommerce-Modus „Demnächst verfügbar“ aus) – erst zum Livegang', 'kurswasser').'</label>';
    echo '</fieldset></td></tr></tbody></table>';
    echo '<p><button type="submit" class="button button-primary">'.esc_html__('Vorschau anzeigen', 'kurswasser').'</button> <span class="description">'.esc_html__('Es wird noch nichts geändert.', 'kurswasser').'</span></p></form>';
    // Vorschläge nach Land (ohne JavaScript gilt der Vorschlag, wenn das Feld leer ist).
    echo '<script>(function(){var c=document.getElementById("kw-shop-country"),s=document.getElementById("kw-shop-state"),r=document.getElementById("kw-shop-rate"),n=document.getElementById("kw-shop-note");if(!c)return;'
        .'var last=c.options[c.selectedIndex];function upd(init){var o=c.options[c.selectedIndex];if(s){for(var g=s.getElementsByTagName("optgroup"),i=0;i<g.length;i++){var m=g[i].getAttribute("data-country")===o.value;g[i].hidden=!m;g[i].disabled=!m;}'
        .'var so=s.options[s.selectedIndex];if(so&&so.parentNode.tagName==="OPTGROUP"&&so.parentNode.disabled)s.value="";}'
        .'if(!init){if(r&&(r.value===""||r.value===last.getAttribute("data-rate")))r.value=o.getAttribute("data-rate");if(n&&(n.value===""||n.value===last.getAttribute("data-note")))n.value=o.getAttribute("data-note");}'
        .'var oss=document.querySelector("input[name=\'kw_shop[tax]\'][value=oss]");if(oss){oss.disabled=o.getAttribute("data-eu")!=="1";if(oss.disabled&&oss.checked)oss.checked=false;}last=o;}c.addEventListener("change",function(){upd(false);});upd(true);})();</script>';
    echo '</div>';
}

/** Vorschau- bzw. Ergebnisseite. */
function kurswasser_setup_shop_view(string $view): void
{
    echo '<p><a href="'.esc_url(kurswasser_setup_url().'#kw-setup-shop').'">&larr; '.esc_html__('Zurück zur Einrichtung', 'kurswasser').'</a></p>';
    kurswasser_setup_shop_styles();
    if (! kurswasser_shop_available()) {
        echo '<div class="notice notice-warning inline"><p>'.esc_html__('WooCommerce ist nicht aktiv – bitte zuerst in Schritt 1 installieren und aktivieren.', 'kurswasser').'</p></div>';

        return;
    }
    if ($view === 'shop-result') {
        kurswasser_setup_shop_result();

        return;
    }
    $input = kurswasser_shop_input(kurswasser_shop_form_values());
    $back = kurswasser_setup_url(['kw_shop' => kurswasser_shop_query_args($input)]).'#kw-setup-shop';
    if ($input['errors'] !== []) {
        foreach ($input['errors'] as $e) {
            echo '<div class="notice notice-error inline"><p>'.esc_html($e).'</p></div>';
        }
        echo '<p><a class="button" href="'.esc_url($back).'">'.esc_html__('Eingaben ändern', 'kurswasser').'</a></p>';

        return;
    }
    if (! current_user_can('manage_woocommerce')) {
        echo '<div class="notice notice-error inline"><p>'.esc_html__('Dein Benutzerkonto darf die WooCommerce-Einstellungen nicht ändern (manage_woocommerce).', 'kurswasser').'</p></div>';

        return;
    }
    $plan = kurswasser_shop_plan($input);
    $selected = kurswasser_shop_default_selection($plan);
    echo '<div class="card" style="max-width:1100px"><h2>'.esc_html__('Shop einrichten – Vorschau', 'kurswasser').'</h2>';
    echo '<p>'.esc_html__('Noch ist nichts geändert. Angehakt ist, was neu gesetzt wird: leere Felder und WooCommerce-Werkseinstellungen. Gelb markiert und nicht angehakt sind Werte, die du schon anders eingestellt hast – sie bleiben, außer du hakst sie an.', 'kurswasser').'</p>';
    foreach ($plan['notes'] as [$type, $text]) {
        echo '<div class="notice notice-'.esc_attr($type === 'error' ? 'error' : ($type === 'info' ? 'info' : 'warning')).' inline"><p>'.esc_html($text).'</p></div>';
    }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="kurswasser_setup_shop">';
    wp_nonce_field('kurswasser_setup_shop');
    foreach (kurswasser_shop_query_args($input) as $k => $value) {
        echo '<input type="hidden" name="kw_shop['.esc_attr($k).']" value="'.esc_attr((string) $value).'">';
    }
    echo '<h3>'.esc_html__('Einstellungen', 'kurswasser').'</h3><table class="widefat kw-shop-table"><thead><tr><th style="width:2.2em"></th><th>'.esc_html__('Einstellung', 'kurswasser').'</th><th>'.esc_html__('Aktuell', 'kurswasser').'</th><th>'.esc_html__('Neu', 'kurswasser').'</th></tr></thead><tbody>';
    $group = '';
    foreach ($plan['settings'] as $key => $s) {
        if ($s['group'] !== $group) {
            $group = $s['group'];
            echo '<tr><th colspan="4"><strong>'.esc_html($group).'</strong></th></tr>';
        }
        $id = 'kw-apply-'.sanitize_html_class($key);
        $box = $s['state'] === 'same' ? '' : '<input type="checkbox" id="'.esc_attr($id).'" name="kw_apply[]" value="'.esc_attr($key).'"'.checked(in_array($key, $selected, true), true, false).'>'
            .'<input type="hidden" name="kw_seen['.esc_attr($key).']" value="'.esc_attr(kurswasser_shop_seen_hash($s['current'])).'">';
        $status = match ($s['state']) {
            'same' => '<span class="kw-pill">'.esc_html__('schon so', 'kurswasser').'</span>',
            'differs' => '<span class="kw-pill is-warn">'.esc_html__('von dir eingestellt – bleibt ohne Häkchen', 'kurswasser').'</span>',
            default => '<span class="kw-pill is-new">'.esc_html($s['current'] === null || kurswasser_shop_norm($s['current']) === '' ? __('leer', 'kurswasser') : __('Werkseinstellung', 'kurswasser')).'</span>',
        };
        echo '<tr class="kw-'.esc_attr($s['state']).'"><td>'.$box.'</td><td><label for="'.esc_attr($id).'">'.esc_html($s['label']).'</label>'.($s['hint'] !== '' ? '<br><span class="description">'.esc_html($s['hint']).'</span>' : '').'</td>' // phpcs:ignore WordPress.Security.EscapeOutput -- oben maskiert
            .'<td>'.esc_html(kurswasser_shop_value_label($key, $s['current'])).'<br>'.$status.'</td><td>'.esc_html(kurswasser_shop_value_label($key, $s['new'])).'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    echo '</tbody></table>';

    $rows = $plan['rates']['rows'];
    if ($rows !== []) {
        $new = count(array_filter($rows, static fn ($r) => $r['status'] === 'new'));
        echo '<h3>'.esc_html__('Steuersätze (Standard-Klasse)', 'kurswasser').'</h3>';
        if ($input['tax'] === 'oss') {
            echo '<p>'.esc_html(sprintf(__('Normalsätze der 27 EU-Länder, Stand %s. Bitte vor dem Livegang prüfen – Sätze ändern sich (z. B. bei der EU-Kommission unter „VAT rates“ oder mit dem Steuerberater). Regionale Sätze (z. B. Madeira, Azoren, griechische Inseln) sind nicht enthalten.', 'kurswasser'), date_i18n('d.m.Y', (int) strtotime(KURSWASSER_EU_VAT_RATES_STAND)))).'</p>';
        }
        $names = kurswasser_shop_countries();
        usort($rows, static fn ($a, $b) => strcoll((string) ($names[$a['country']] ?? $a['country']), (string) ($names[$b['country']] ?? $b['country'])));
        echo '<ul class="kw-rates">';
        foreach ($rows as $r) {
            $label = $r['country'] === '' ? __('alle Länder', 'kurswasser') : ($names[$r['country']] ?? $r['country']).' ('.$r['country'].')';
            $pill = match ($r['status']) {
                'exists' => '<span class="kw-pill">'.esc_html__('vorhanden', 'kurswasser').'</span>',
                'conflict' => '<span class="kw-pill is-warn">'.esc_html(sprintf(__('vorhanden: %s – wird nicht angelegt', 'kurswasser'), implode(', ', array_map('kurswasser_shop_rate_label', $r['existing'])))).'</span>',
                default => '<span class="kw-pill is-new">'.esc_html__('neu', 'kurswasser').'</span>',
            };
            echo '<li>'.esc_html($label.': '.kurswasser_shop_rate_label($r['rate'])).' '.$pill.'</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</ul>';
        if ($new > 0) {
            echo '<p><label><input type="checkbox" name="kw_apply[]" value="tax_rates"'.checked(in_array('tax_rates', $selected, true), true, false).'> '.esc_html(sprintf(_n('%d fehlenden Steuersatz anlegen', '%d fehlende Steuersätze anlegen', $new, 'kurswasser'), $new)).'</label> <span class="description">'.esc_html__('Bestehende Sätze werden nicht verändert oder gelöscht.', 'kurswasser').'</span></p>';
        } else {
            echo '<p>'.esc_html__('Keine Steuersätze anzulegen.', 'kurswasser').'</p>';
        }
    }
    if ($plan['pages'] !== []) {
        echo '<h3>'.esc_html__('Shop-Seiten', 'kurswasser').'</h3><p>'.esc_html__('WooCommerce hat diese Seiten mit englischem Namen angelegt. Neuer Titel und neue Adresse; WooCommerce verlinkt automatisch die neuen Adressen, die bisherigen gelten danach nicht mehr.', 'kurswasser').'</p><ul class="kw-rates">';
        foreach ($plan['pages'] as $page) {
            echo '<li>'.esc_html(sprintf('„%1$s“ (/%2$s/) → „%3$s“ (/%4$s/)', $page['title'], $page['slug'], $page['new_title'], $page['new_slug'])).'</li>';
        }
        echo '</ul><p><label><input type="checkbox" name="kw_apply[]" value="pages"'.checked(in_array('pages', $selected, true), true, false).'> '.esc_html__('Shop-Seiten auf Deutsch umbenennen', 'kurswasser').'</label></p>';
    }
    if ($plan['products'] !== null) {
        $actions = array_count_values(array_map(static fn ($r) => str_replace(' (Vorschau)', '', $r['action']), $plan['products']));
        echo '<h3>'.esc_html__('Produkte', 'kurswasser').'</h3><p>'.esc_html(sprintf(__('%1$d Produkte aus dem Katalog des Lern-Plugins: %2$d neu (als Entwurf), %3$d schon vorhanden (Status bleibt; Preise kommen von der Preis-Seite, von Hand geänderte Felder bleiben).', 'kurswasser'), count($plan['products']), $actions['neu'] ?? 0, count($plan['products']) - ($actions['neu'] ?? 0))).'</p>';
        if ($plan['conflicts'] === []) {
            echo '<p><label><input type="checkbox" name="kw_apply[]" value="products"'.checked(in_array('products', $selected, true), true, false).'> '.esc_html__('Produkte anlegen bzw. abgleichen (neue als Entwurf)', 'kurswasser').'</label></p>';
        }
    }
    if ($plan['free'] !== null) {
        echo '<h3>'.esc_html__('Schnupper-Paket', 'kurswasser').'</h3><p><label><input type="checkbox" name="kw_apply[]" value="free"'.checked(in_array('free', $selected, true), true, false).'> '
            .esc_html($plan['free']['added'] > 0 ? sprintf(__('%1$d Regeln für %2$d Kurse anlegen (10 Fragen je Modul, 1 Prüfungsbogen, erste Video-Lektion, erste Navigations- und Praxisaufgabe)', 'kurswasser'), $plan['free']['added'], $plan['free']['courses']) : __('schon vollständig vorhanden – nichts anzulegen', 'kurswasser')).'</label></p>';
    }
    echo '<p><button type="submit" class="button button-primary">'.esc_html__('Ausgewählte übernehmen', 'kurswasser').'</button> <a class="button" href="'.esc_url($back).'">'.esc_html__('Eingaben ändern', 'kurswasser').'</a></p></form></div>';
}

function kurswasser_setup_shop_result(): void
{
    $key = 'kurswasser_shop_report_'.get_current_user_id();
    $data = get_transient($key);
    if (! is_array($data) || ! isset($data['report'])) {
        echo '<p>'.esc_html__('Kein Ergebnis vorhanden.', 'kurswasser').'</p>';

        return;
    }
    $r = $data['report'];
    echo '<div class="card" style="max-width:1100px"><h2>'.esc_html__('Shop einrichten – Ergebnis', 'kurswasser').'</h2>';
    foreach (['errors' => [__('Fehler', 'kurswasser'), 'error'], 'done' => [__('Erledigt', 'kurswasser'), 'success'], 'skipped' => [__('Übersprungen bzw. unverändert', 'kurswasser'), 'info']] as $part => [$title, $type]) {
        if (empty($r[$part])) {
            continue;
        }
        echo '<div class="notice notice-'.esc_attr($type).' inline"><p><strong>'.esc_html($title).'</strong></p><ul class="kw-report" style="list-style:disc;margin-left:1.5em">';
        foreach ($r[$part] as $line) {
            echo '<li>'.esc_html((string) $line).'</li>';
        }
        echo '</ul></div>';
    }
    foreach ((array) ($r['notes'] ?? []) as [$type, $text]) {
        echo '<div class="notice notice-'.esc_attr($type === 'info' ? 'info' : 'warning').' inline"><p>'.esc_html((string) $text).'</p></div>';
    }
    if (! empty($r['products'])) {
        echo '<h3>'.esc_html__('Produkte', 'kurswasser').'</h3><table class="widefat striped"><thead><tr><th>'.esc_html__('Artikelnummer', 'kurswasser').'</th><th>'.esc_html__('Ergebnis', 'kurswasser').'</th><th>'.esc_html__('Von Hand geändert, nicht überschrieben', 'kurswasser').'</th></tr></thead><tbody>';
        foreach ($r['products'] as $row) {
            $link = $row['product_id'] ? get_edit_post_link((int) $row['product_id']) : '';
            echo '<tr><td>'.($link ? '<a href="'.esc_url($link).'">'.esc_html($row['sku']).'</a>' : esc_html($row['sku'])).'</td><td>'.esc_html($row['action']).'</td><td>'.esc_html(implode(', ', (array) $row['skipped'])).'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table>';
    }
    echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=tax&section=standard')).'">'.esc_html__('Steuersätze in WooCommerce ansehen', 'kurswasser').'</a> '
        .'<a class="button" href="'.esc_url(admin_url('edit.php?post_type=product&post_status=draft')).'">'.esc_html__('Produkt-Entwürfe ansehen', 'kurswasser').'</a></p></div>';
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Schritt 3 „Shop einrichten“: Vorschau bzw. mit --yes anwenden.
     *
     * Ohne --overwrite werden nur leere Felder und WooCommerce-Werkseinstellungen gesetzt; was schon anders
     * eingestellt ist, bleibt (wird als übersprungen gemeldet).
     *
     * ## OPTIONS
     *
     * --country=<land>
     * : Länderkürzel: EU-Land oder CH (z. B. DE, AT).
     *
     * --tax=<weg>
     * : oss (Satz je EU-Kundenland), own (eigener Satz für alle) oder small (Kleinunternehmer).
     * ---
     * options:
     *   - oss
     *   - own
     *   - small
     * ---
     *
     * [--rate=<prozent>]
     * : Bei --tax=own; Standard: Normalsatz des Landes (DE 19, AT 20).
     *
     * [--note=<text>]
     * : Bei --tax=small: Hinweis an den Preisen; Standard je Land (DE § 19 UStG, AT § 6 Abs. 1 Z 27 UStG).
     *
     * [--state=<region>]
     * : WooCommerce-Kürzel der Region, z. B. DE-BY.
     *
     * [--address=<strasse>]
     * : Shop-Anschrift: Straße und Hausnummer.
     *
     * [--postcode=<plz>]
     * : Shop-Anschrift: PLZ.
     *
     * [--city=<ort>]
     * : Shop-Anschrift: Ort.
     *
     * [--sell=<laender>]
     * : eu (EU und Schweiz, Standard) oder all.
     *
     * [--products]
     * : Produkte des Lern-Plugins anlegen bzw. abgleichen (neue als Entwurf).
     *
     * [--free]
     * : Schnupper-Paket anlegen.
     *
     * [--registration]
     * : Kunden-Registrierung einschalten (Standard: an; --no-registration schaltet ab).
     *
     * [--language]
     * : Website-Sprache Deutsch, Sprachpaket laden (Standard: an; --no-language schaltet ab).
     *
     * [--live]
     * : Shop für Kunden sichtbar machen (WooCommerce-Modus „Demnächst verfügbar“ aus). Standard: aus.
     *
     * [--overwrite]
     * : Auch Werte überschreiben, die schon anders eingestellt sind.
     *
     * [--yes]
     * : Ausführen (ohne: nur Vorschau).
     *
     * ## EXAMPLES
     *
     *     wp kurswasser setup shop --country=DE --tax=oss --products
     *     wp kurswasser setup shop --country=AT --tax=own --rate=20 --products --yes --user=admin
     *     wp kurswasser setup shop --country=DE --tax=small --no-language --yes
     */
    WP_CLI::add_command('kurswasser setup shop', static function (array $args, array $assoc): void {
        if (! kurswasser_shop_available()) {
            WP_CLI::error('WooCommerce ist nicht aktiv.');
        }
        $flag = static fn (string $k, bool $default): bool => (bool) \WP_CLI\Utils\get_flag_value($assoc, $k, $default);
        $input = kurswasser_shop_input([
            'country' => $assoc['country'] ?? '', 'state' => $assoc['state'] ?? '', 'address' => $assoc['address'] ?? '', 'postcode' => $assoc['postcode'] ?? '',
            'city' => $assoc['city'] ?? '', 'tax' => $assoc['tax'] ?? '', 'rate' => $assoc['rate'] ?? '', 'note' => $assoc['note'] ?? '', 'sell' => $assoc['sell'] ?? 'eu',
            'products' => $flag('products', false), 'free' => $flag('free', false), 'registration' => $flag('registration', true), 'language' => $flag('language', true),
            'live' => $flag('live', false),
        ]);
        if ($input['errors'] !== []) {
            WP_CLI::error(implode(' ', $input['errors']));
        }
        $plan = kurswasser_shop_plan($input);
        $items = [];
        foreach ($plan['settings'] as $key => $s) {
            $items[] = ['Einstellung' => $key, 'Aktuell' => kurswasser_shop_value_label($key, $s['current']), 'Neu' => kurswasser_shop_value_label($key, $s['new']),
                'Aktion' => $s['state'] === 'same' ? 'schon so' : ($s['state'] === 'default' || ! empty($assoc['overwrite']) ? 'wird gesetzt' : 'bleibt (abweichend; --overwrite)')];
        }
        \WP_CLI\Utils\format_items('table', $items, ['Einstellung', 'Aktuell', 'Neu', 'Aktion']);
        if ($plan['rates']['rows'] !== []) {
            WP_CLI::log(sprintf('Steuersätze (Stand %s): ', KURSWASSER_EU_VAT_RATES_STAND).implode(', ', array_map(static fn ($r) => ($r['country'] ?: '*').' '.kurswasser_shop_rate_label($r['rate']).($r['status'] === 'new' ? ' (neu)' : ($r['status'] === 'exists' ? ' (vorhanden)' : ' (anderer Satz vorhanden, übersprungen)')), $plan['rates']['rows'])));
        }
        foreach ($plan['pages'] as $page) {
            WP_CLI::log(sprintf('Shop-Seite: „%1$s“ (/%2$s/) → „%3$s“ (/%4$s/)', $page['title'], $page['slug'], $page['new_title'], $page['new_slug']));
        }
        if ($plan['products'] !== null) {
            $newCount = count(array_filter($plan['products'], static fn ($r) => str_starts_with($r['action'], 'neu')));
            WP_CLI::log(sprintf('Produkte: %d, davon %d neu (als Entwurf).', count($plan['products']), $newCount));
        }
        foreach ($plan['notes'] as [$type, $text]) {
            $type === 'info' ? WP_CLI::log($text) : WP_CLI::warning($text);
        }
        if (empty($assoc['yes'])) {
            WP_CLI::log('Vorschau – nichts geändert. Mit --yes ausführen.');

            return;
        }
        $report = kurswasser_shop_apply($input, kurswasser_shop_default_selection($plan, ! empty($assoc['overwrite'])));
        foreach ($report['done'] as $line) {
            WP_CLI::log('erledigt: '.$line);
        }
        foreach ($report['skipped'] as $line) {
            WP_CLI::log('übersprungen: '.$line);
        }
        foreach ($report['errors'] as $line) {
            WP_CLI::warning($line);
        }
        $report['errors'] !== [] ? WP_CLI::error('Shop nicht vollständig eingerichtet.') : WP_CLI::success('Shop eingerichtet.');
    });
}
