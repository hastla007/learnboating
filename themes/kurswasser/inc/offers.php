<?php
/**
 * Angebots-Sektion [kurswasser_offers]: echte Produkte aus dem Katalog des Lern-Plugins,
 * Preise aus WooCommerce (sonst Katalogpreis als Vorschau), Ersparnis gegenüber Einzelkauf
 * aus denselben Preisen berechnet, Inhalte aus den Produkttexten des Plugins.
 *
 * @package Kurswasser
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Reiter → [linke SKU, hervorgehobene SKU, rechte SKU], Ersparnis-Vergleich der Mitte. */
function kurswasser_offer_tabs(): array
{
    return [
        'sbf' => ['short' => __('SBF', 'kurswasser'), 'label' => __('Sportbootführerschein', 'kurswasser'), 'icon' => 'anchor', 'skus' => ['BP-SBF-BIN', 'BP-SBF-SEE-BIN', 'BP-SBF-SEE'], 'compare' => ['BP-SBF-SEE-KOMPLETT', 'BP-SBF-BIN-KOMPLETT'], 'more' => ['sbf', __('Auch: SBF Binnen unter Segel', 'kurswasser')]],
        'funk' => ['short' => __('Funk', 'kurswasser'), 'label' => __('Funkzeugnis', 'kurswasser'), 'icon' => 'radio', 'skus' => ['BP-SRC', 'BP-SRC-UBI', 'BP-UBI'], 'compare' => ['BP-SRC-KOMPLETT', 'BP-UBI-KOMPLETT'], 'more' => ['seenot', __('Auch: SKN und FKN (Seenotsignalmittel)', 'kurswasser')]],
        'pakete' => ['short' => __('Pakete', 'kurswasser'), 'label' => __('Pakete & Pässe', 'kurswasser'), 'icon' => 'wheel', 'skus' => ['BP-PASS-1M', 'BP-KAPITAEN', 'BP-PASS-3M'], 'compare' => ['BP-SBF-SEE-BIN', 'BP-SRC-UBI'], 'more' => ['ergaenzung', __('Schon einen Schein? Ergänzungskurse', 'kurswasser')]],
    ];
}

/** Preisangabe (PAngV), z. B. „inkl. MwSt.“ – aus den Plugin-Einstellungen. */
function kurswasser_price_note(): string
{
    return kurswasser_learning_compatible() ? \Bootspruefung\Learning\Settings\Settings::priceNote() : 'inkl. MwSt.';
}

/** @return array{name:string,price:float,days:int,url:?string,buy:?string,items:list<string>}|null */
function kurswasser_offer(string $sku): ?array
{
    if (! kurswasser_learning_compatible()) {
        return null;
    }
    static $cache = [];
    if (isset($cache[$sku])) {
        return $cache[$sku];
    }
    $def = null;
    foreach (\Bootspruefung\Learning\Commerce\ShopCatalog::products() as $p) {
        if ($p['sku'] === $sku) {
            $def = $p;
            break;
        }
    }
    if ($def === null) {
        return $cache[$sku] = null;
    }
    $price = (float) $def['price'];
    $url = null;
    $buy = null;
    $name = $def['name'];
    if (function_exists('wc_get_product_id_by_sku') && ($id = wc_get_product_id_by_sku($sku)) && ($product = wc_get_product($id)) && $product->get_status() === 'publish') {
        $price = (float) $product->get_price();
        $name = wp_specialchars_decode($product->get_name());
        $url = (string) get_permalink($id);
        $buy = $product->is_purchasable() ? add_query_arg('add-to-cart', (int) $id, wc_get_checkout_url()) : null;
    }
    $items = [];
    if (preg_match_all('#<li>(.*?)</li>#s', \Bootspruefung\Learning\Commerce\ShopCatalog::texts($def)['long'], $m)) {
        $items = array_map(static fn ($t) => kurswasser_offer_item(wp_strip_all_tags(html_entity_decode($t))), $m[1]);
    }

    return $cache[$sku] = ['name' => $name, 'price' => $price, 'days' => (int) $def['days'], 'url' => $url, 'buy' => $buy, 'items' => $items];
}

/** Inhaltspunkte des Produkttexts für die Karte kürzen (gleiche Fakten, knapper). */
function kurswasser_offer_item(string $text): string
{
    $rules = [
        '/^(\d+) Prüfungsfragen im Smarttrainer.*$/u' => '$1 Prüfungsfragen mit Erklärungen',
        '/^Prüfungssimulation mit Zeitlimit und Auswertung, inkl\. (\d+) Prüfungsbögen$/u' => 'Prüfungssimulation + $1 Prüfungsbögen',
        '/^Prüfungssimulation mit Zeitlimit und Auswertung$/u' => 'Prüfungssimulation mit Zeitlimit',
        '/^(\d+) Praxiskarten zur Vorbereitung auf die praktische Prüfung$/u' => '$1 Praxiskarten für die Praxis',
        '/^Funktraining:.*Testversion.*$/u' => 'Funktraining + Simulator zum Testen',
        '/^Funktraining:.*Simulator$/u' => 'Funktraining mit Funkgerät-Simulator',
        '/^(\d+) Lektionen an (\d+) Funkgeräten:.*$/u' => '$1 Lektionen an $2 Funkgeräten',
        '/^Prüfungsmodus: (\d+) Prüfungsaufgaben.*$/u' => 'Prüfungsmodus: $1 Aufgaben',
        '/^Notalarm \(DISTRESS\).*$/u' => 'Notalarm (DISTRESS) mit DSC',
        '/^Alle (\d+) Kurse:.*$/u' => 'Alle $1 Kurse mit allen Trainern',
        '/^Videokurs \((\d+) Lektionen\) nicht enthalten – als Upgrade für ([\d,]+) € erhältlich.*$/u' => 'Videokurs optional (+$2 €)',
    ];

    return (string) preg_replace(array_keys($rules), array_values($rules), $text);
}

function kurswasser_money(float $value): string
{
    return function_exists('wc_price') ? wp_strip_all_tags(wc_price($value)) : number_format($value, 2, ',', '.').' €';
}

/** Kurzer Name ohne Zusätze in Klammern, Untertitel aus der Klammer. */
function kurswasser_offer_title(string $name): array
{
    if (preg_match('/^(.*?)\s*\((.+)\)$/u', $name, $m) || preg_match('/^(.*?)\s+–\s+(.+)$/u', $name, $m)) {
        return [$m[1], mb_strtoupper(mb_substr($m[2], 0, 1)).mb_substr($m[2], 1)];
    }

    return [$name, ''];
}

/** Ziel für „Geld-zurück-Garantie“: die Garantieseite, sobald sie veröffentlicht ist, sonst der AGB-Abschnitt. */
function kurswasser_guarantee_url(): string
{
    $page = function_exists('kurswasser_page_published') && kurswasser_page_published('geld-zurueck-garantie-bootsfuehrerschein')
        ? get_page_by_path('geld-zurueck-garantie-bootsfuehrerschein', OBJECT, 'page') : null;

    return $page instanceof WP_Post ? (string) get_permalink($page) : kurswasser_url('agb').'#geld-zurueck-garantie';
}

function kurswasser_offer_trust(): string
{
    return '<ul class="kw-offers-trust"><li><span class="kw-icon kw-icon-shield" aria-hidden="true"></span><a href="'.esc_url(kurswasser_guarantee_url()).'">'.esc_html__('14 Tage Geld-zurück-Garantie', 'kurswasser').'</a> – '.esc_html__('bei weniger als 20 % genutzt', 'kurswasser').'</li><li><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html__('Zugang direkt nach Zahlungseingang', 'kurswasser').'</li><li><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html__('Kein Abo – einmal zahlen', 'kurswasser').'</li></ul>';
}

/**
 * Angebotskarten (ohne Reiter). $featured hervorgehoben, Ersparnis gegenüber der Summe von $compare.
 *
 * @param list<string> $skus
 * @param list<string> $compare
 */
function kurswasser_offer_cards(array $skus, ?string $featured, array $compare, string $flag): string
{
    $skus = array_values(array_filter($skus, static fn ($s) => kurswasser_offer($s) !== null));
    $html = '<div class="kw-offers-grid kw-offers-n'.count($skus).'">';
    foreach ($skus as $sku) {
        $o = kurswasser_offer($sku);
        $isFeatured = $sku === $featured;
        [$title, $sub] = kurswasser_offer_title($o['name']);
        $saving = 0.0;
        if ($isFeatured && $compare !== []) {
            $sum = array_sum(array_map(static fn ($s) => kurswasser_offer($s)['price'] ?? 0.0, $compare));
            $saving = round($sum - $o['price'], 2);
        }
        $months = $o['days'] >= 360 ? 12 : ($o['days'] >= 180 ? 6 : ($o['days'] >= 91 ? 3 : 0));
        $duration = $months ? sprintf(_n('%d Monat Zugang', '%d Monate Zugang', $months, 'kurswasser'), $months) : sprintf(__('%d Tage Zugang', 'kurswasser'), $o['days']);
        $html .= '<article class="kw-offer'.($isFeatured ? ' is-featured' : '').'">';
        if ($isFeatured && $flag !== '') {
            $html .= '<p class="kw-offer-flag">'.esc_html($flag).'</p>';
        }
        $html .= '<header><h3>'.esc_html($title).'</h3>'.($sub !== '' ? '<p class="kw-offer-sub">'.esc_html($sub).'</p>' : '').'</header>';
        // Kein durchgestrichener Vergleichspreis (Festlegung Inhaltsmoderation 10.11.2026): Die Summe der Einzelkurse
        // steht sachlich unter dem Preis, die Ersparnis ist live aus den aktuellen Einzelpreisen berechnet.
        $html .= '<div class="kw-offer-price"><span class="kw-offer-amount">'.esc_html(kurswasser_money($o['price'])).'</span><span class="kw-offer-once">'.esc_html__('einmalig', 'kurswasser').'</span></div>';
        $meta = array_filter([kurswasser_price_note(), $duration]);
        if ($months === 12) {
            // Umrechnung nur zur Orientierung; „kein Abo“ dazu, damit sie nicht wie eine Monatsgebühr wirkt (Betreiber, 11.11.2026).
            $meta[] = sprintf(__('entspricht ≈ %s pro Monat, kein Abo', 'kurswasser'), kurswasser_money(round($o['price'] / 12, 2)));
        }
        $html .= '<p class="kw-offer-meta">'.esc_html(implode(' · ', $meta)).'</p>';
        if ($saving >= 1) {
            $html .= '<p class="kw-offer-save">'.esc_html(sprintf(__('Einzeln gekauft: %1$s – du sparst %2$s gegenüber den Einzelkursen', 'kurswasser'), kurswasser_money($o['price'] + $saving), kurswasser_money($saving))).'</p>';
        }
        $html .= '<ul class="kw-offer-list">';
        $list = array_values(array_filter($o['items'], static fn ($t) => ! str_contains($t, 'Lernfortschritt')));
        // Hauptunterschiede sichtbar halten: Videokurs (enthalten/optional) und Funkgerät-Simulator.
        $priority = array_values(array_filter($list, static fn ($t) => str_starts_with($t, 'Videokurs') || $t === 'Funktraining mit Funkgerät-Simulator'));
        $short = array_slice($list, 0, 4);
        $slot = 3;
        foreach ($priority as $line) {
            if (! in_array($line, $short, true) && $slot >= 2) {
                $short[$slot--] = $line;
            }
        }
        $list = $short;
        foreach ($list as $item) {
            $html .= '<li>'.esc_html($item).'</li>';
        }
        $html .= '</ul><div class="kw-offer-actions">';
        // Theorie-Stufe: Komplett mit Videokurs als zweite Wahl auf derselben Karte.
        $full = isset(\Bootspruefung\Learning\Commerce\ShopCatalog::VIDEO_TIERS[$sku]) ? kurswasser_offer(\Bootspruefung\Learning\Commerce\ShopCatalog::komplettSku($sku)) : null;
        if ($full !== null) {
            $html .= '<p class="kw-offer-variant"><span class="kw-icon kw-icon-check" aria-hidden="true"></span>'.esc_html(sprintf(__('Komplett mit Videokurs: %s', 'kurswasser'), kurswasser_money($full['price']))).'</p>';
        }
        if ($o['buy']) {
            $html .= '<a class="kw-offer-buy" href="'.esc_url($o['buy']).'" rel="nofollow">'.esc_html($full !== null ? __('Theorie starten', 'kurswasser') : __('Jetzt starten', 'kurswasser')).' <span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></a>';
            if ($full !== null && $full['buy']) {
                $html .= '<a class="kw-offer-buy is-secondary" href="'.esc_url($full['buy']).'" rel="nofollow">'.esc_html__('Mit Videokurs starten', 'kurswasser').'</a>';
            }
        } else {
            $html .= '<span class="kw-offer-buy is-disabled">'.esc_html__('Bald verfügbar', 'kurswasser').'</span>';
        }
        if ($o['url']) {
            $html .= '<a class="kw-offer-details" href="'.esc_url($o['url']).'">'.esc_html__('Details ansehen', 'kurswasser').'</a>';
        }
        $html .= '</div></article>';
    }

    return $html.'</div>';
}

/**
 * Hinweis Zahlungsarten, Verkaufsländer und Lieferung (wie unter dem Kaufknopf) zum freien Platzieren, z. B. in der
 * Fußzeile oder auf Kursseiten (To-do T38). Ohne Lern-Plugin bzw. ohne WooCommerce leer. Nicht automatisch eingefügt.
 */
add_shortcode('kurswasser_purchase_info', static function (): string {
    if (! class_exists('\Bootspruefung\Learning\Commerce\WooCommerce\PurchaseInfo') || ! function_exists('WC') || ! kurswasser_learning_compatible()) {
        return '';
    }

    return \Bootspruefung\Learning\Commerce\WooCommerce\PurchaseInfo::html(true);
});

add_shortcode('kurswasser_offers', static function (): string {
    $tabs = kurswasser_offer_tabs();
    if (kurswasser_offer('BP-SBF-SEE') === null) {
        return '';
    }
    $uid = 'kwo'.wp_unique_id();
    $html = '<div class="kw-offers" data-kw-tabs>';
    $html .= '<div class="kw-offers-tabs" role="tablist" aria-label="'.esc_attr__('Angebote', 'kurswasser').'">';
    $first = true;
    foreach ($tabs as $key => $tab) {
        $html .= sprintf(
            '<button type="button" role="tab" id="%1$s-tab-%2$s" aria-controls="%1$s-%2$s" aria-selected="%3$s" tabindex="%4$s" aria-label="%6$s"><span class="kw-icon kw-icon-%5$s" aria-hidden="true"></span><span class="kw-tab-long">%6$s</span><span class="kw-tab-short" aria-hidden="true">%7$s</span></button>',
            esc_attr($uid), esc_attr($key), $first ? 'true' : 'false', $first ? '0' : '-1', esc_attr($tab['icon']), esc_html($tab['label']), esc_html($tab['short'])
        );
        $first = false;
    }
    $html .= '</div>';
    $first = true;
    foreach ($tabs as $key => $tab) {
        $html .= sprintf('<div class="kw-offers-panel" role="tabpanel" id="%1$s-%2$s" aria-labelledby="%1$s-tab-%2$s"%3$s>', esc_attr($uid), esc_attr($key), $first ? '' : ' hidden');
        $first = false;
        $html .= kurswasser_offer_cards($tab['skus'], $tab['skus'][1] ?? null, $tab['compare'], $key === 'pakete' ? __('Alles in einem Paket', 'kurswasser') : __('Unsere Empfehlung', 'kurswasser'));
        [$moreTarget, $moreLabel] = $tab['more'];
        $html .= '<p class="kw-offers-more"><a href="'.esc_url(kurswasser_url($moreTarget)).'">'.esc_html($moreLabel).' <span class="kw-icon kw-icon-arrow" aria-hidden="true"></span></a></p></div>';
    }
    $html .= kurswasser_offer_trust().'</div>';
    wp_enqueue_script('kurswasser-offers');

    return $html;
});

add_action('wp_enqueue_scripts', static function (): void {
    wp_register_script('kurswasser-offers', get_template_directory_uri().'/assets/js/offers.js', [], KURSWASSER_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
});
