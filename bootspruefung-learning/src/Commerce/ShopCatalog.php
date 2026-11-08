<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Audit\AuditLog;
use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Produktkatalog und Schnupper-Paket (Festlegung des Betreibers vom 06.11.2026).
 *
 * Anlegen/Aktualisieren über `wp bootspruefung shop setup` oder den Knopf unter
 * „Kostenlose Bereiche“. Wiederholbar: Produkte werden über die Artikelnummer (SKU)
 * gefunden, Zuordnungen und Regeln ersetzt statt verdoppelt.
 */
final class ShopCatalog
{
    private const SBF_ALL = ['SBF-SEE-BIN', 'SBF-SEE', 'SBF-BIN-MOTOR'];
    private const RADIO_ALL = ['SRC-UBI', 'SRC', 'UBI'];
    private const ADDONS = ['ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-BIN-SEGEL', 'ADD-UBI-TO-SRC', 'ADD-FKN-TO-SKN'];
    /** Kurse, in denen die Funkgerät-Simulation läuft (Funkzeugnisse). */
    public const SIM_COURSES = ['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC'];
    /** Simulator-Pässe (Festlegung 06.11.2026): nur Gerätesimulation, Einmalzahlung, kein Abo, keine Geld-zurück-Garantie. */
    public const SIM_PASSES = ['SIM-PASS-14D', 'SIM-PASS-3M', 'SIM-PASS-12M'];
    /** Upgrade im Checkout, nur zusammen mit einem Funkkurs (Order Bump). */
    public const SIM_UPGRADE = 'SIM-UPGRADE-CHECKOUT';
    /** Produkte, bei deren Kauf das Upgrade angeboten wird. */
    public const SIM_UPGRADE_FOR = ['BP-SRC', 'BP-UBI', 'BP-SRC-UBI', 'BP-ADD-UBI-TO-SRC', 'BP-SRC-KOMPLETT', 'BP-UBI-KOMPLETT', 'BP-ADD-UBI-TO-SRC-KOMPLETT'];

    /**
     * Videokurs als eigene Stufe (Festlegung 07.11.2026): Basis-SKU = Theorie (ohne Videokurs),
     * <SKU>-KOMPLETT = mit Videokurs (Aufschlag), <SKU>-VIDEO = Video-Upgrade zum Aufschlag
     * (nur zusammen mit der Theorie-Stufe im Warenkorb oder im Konto). Kombis, Bundle, Pässe
     * und Gruppenpakete enthalten den Videokurs immer.
     */
    public const VIDEO_TIERS = [
        'BP-SBF-SEE' => '11.90', 'BP-SBF-BIN' => '11.90', 'BP-SBF-BIN-SEGEL' => '11.90',
        'BP-SRC' => '7.90', 'BP-UBI' => '7.90', 'BP-SKN' => '4.90', 'BP-FKN' => '4.90',
        'BP-ADD-SEE-TO-BIN' => '4.90', 'BP-ADD-BIN-TO-SEE' => '4.90', 'BP-ADD-BIN-SEGEL' => '4.90', 'BP-ADD-UBI-TO-SRC' => '4.90', 'BP-ADD-FKN-TO-SKN' => '4.90',
    ];
    /** Eigene Preise des Betreibers (Admin „Preise“): SKU => Preis, überschreibt den Katalogpreis. */
    public const PRICE_OPTION = 'bp_learning_prices';
    public const KOMPLETT_SUFFIX = '-KOMPLETT';
    public const VIDEO_SUFFIX = '-VIDEO';

    public static function komplettSku(string $base): string
    {
        return $base.self::KOMPLETT_SUFFIX;
    }

    public static function videoSku(string $base): string
    {
        return $base.self::VIDEO_SUFFIX;
    }

    /** @return list<string> alle Video-Upgrade-SKUs */
    public static function videoUpgradeSkus(): array
    {
        return array_map([self::class, 'videoSku'], array_keys(self::VIDEO_TIERS));
    }

    /** Theorie-Basis eines Komplett- oder Upgrade-Produkts (sonst null). */
    public static function tierBase(string $sku): ?string
    {
        foreach ([self::KOMPLETT_SUFFIX, self::VIDEO_SUFFIX] as $suffix) {
            if (str_ends_with($sku, $suffix) && isset(self::VIDEO_TIERS[substr($sku, 0, -strlen($suffix))])) {
                return substr($sku, 0, -strlen($suffix));
            }
        }

        return null;
    }

    public const MONEY_BACK_DAYS = 14;
    public const MONEY_BACK_MAX_PROGRESS = 20.0;

    /** Schnupper-Paket je Kurs: Funktion => Teilumfang (siehe AccessService::LIMIT_TYPES). */
    public const FREE_TIER = [
        AccessService::QUESTION_BANK => 10, // Fragen je Modul
        AccessService::EXAM => 1,           // Prüfungsbogen
        AccessService::VIDEO => 1,          // erste Video-Lektion
        AccessService::NAVIGATION => 1,     // erste Navigationsaufgabe
        AccessService::PRAXIS => 1,         // erste Praxiskarte
    ];
    public const FREE_REASON = 'Schnupper-Paket (Shop-Katalog)';

    /**
     * Katalog mit den Preisen des Betreibers (falls gesetzt); 'catalog_price' = festgelegter Katalogpreis.
     * @return list<array{sku:string,name:string,price:string,catalog_price:string,days:int,courses:list<string>,group:string,seats?:int}>
     */
    public static function products(): array
    {
        $own = self::ownPrices();
        $list = self::catalogProducts();
        foreach ($list as $i => $def) {
            $list[$i]['catalog_price'] = $def['price'];
            if (isset($own[$def['sku']])) {
                $list[$i]['price'] = $own[$def['sku']];
            }
        }

        return $list;
    }

    /** @return array<string,string> gültige eigene Preise (SKU => „12.90“) */
    public static function ownPrices(): array
    {
        $raw = get_option(self::PRICE_OPTION, []);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $sku => $price) {
            if (is_string($sku) && is_string($price) && preg_match('/^\d{1,5}\.\d{2}$/', $price) && (float) $price > 0) {
                $out[$sku] = $price;
            }
        }

        return $out;
    }

    /**
     * Eigene Preise speichern; leer = Katalogpreis. Gibt Fehlermeldungen je SKU zurück.
     * @param array<string,string> $input SKU => Eingabe („12,90“ oder „12.90“)
     * @return array{saved:int,errors:array<string,string>}
     */
    public static function saveOwnPrices(array $input): array
    {
        $known = array_column(self::catalogProducts(), 'price', 'sku');
        $before = self::ownPrices();
        $own = [];
        $errors = [];
        foreach ($input as $sku => $value) {
            $sku = (string) $sku;
            $value = trim(str_replace([' ', '€'], '', (string) $value));
            if (! isset($known[$sku]) || $value === '') {
                continue;
            }
            $value = str_replace(',', '.', $value);
            if (! preg_match('/^\d{1,5}(\.\d{1,2})?$/', $value) || (float) $value <= 0) {
                $errors[$sku] = __('ungültiger Preis', 'bootspruefung-learning');
                if (isset($before[$sku])) {
                    $own[$sku] = $before[$sku]; // bisheriger eigener Preis bleibt
                }
                continue;
            }
            $value = number_format((float) $value, 2, '.', '');
            if ($value !== $known[$sku]) {
                $own[$sku] = $value;
            }
        }
        update_option(self::PRICE_OPTION, $own, false);
        if ($before !== $own) {
            AuditLog::record('shop_prices_changed', 'settings', null, null, ['before' => $before, 'after' => $own]);
        }

        return ['saved' => count($own), 'errors' => $errors];
    }

    /** @return list<array<string,mixed>> Katalog mit den festgelegten Preisen */
    private static function catalogProducts(): array
    {
        $list = [
            ['sku' => 'BP-SBF-SEE-BIN', 'name' => 'SBF See & Binnen (Kombi)', 'price' => '60.80', 'days' => 365, 'courses' => self::SBF_ALL],
            ['sku' => 'BP-SBF-SEE', 'name' => 'SBF See (Einzelkurs)', 'price' => '28.90', 'days' => 365, 'courses' => ['SBF-SEE']],
            ['sku' => 'BP-SBF-BIN', 'name' => 'SBF Binnen (Einzelkurs)', 'price' => '27.80', 'days' => 365, 'courses' => ['SBF-BIN-MOTOR']],
            ['sku' => 'BP-SRC-UBI', 'name' => 'SRC & UBI Kombikurs (Funk)', 'price' => '46.80', 'days' => 365, 'courses' => self::RADIO_ALL],
            ['sku' => 'BP-SRC', 'name' => 'SRC Vollkurs', 'price' => '23.90', 'days' => 365, 'courses' => ['SRC']],
            ['sku' => 'BP-UBI', 'name' => 'UBI Vollkurs', 'price' => '23.90', 'days' => 365, 'courses' => ['UBI']],
            ['sku' => 'BP-SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'price' => '18.90', 'days' => 365, 'courses' => ['SBF-BIN-SEGEL']],
            ['sku' => 'BP-SKN', 'name' => 'SKN Vollkurs', 'price' => '13.90', 'days' => 182, 'courses' => ['SKN']],
            ['sku' => 'BP-FKN', 'name' => 'FKN Vollkurs', 'price' => '13.90', 'days' => 182, 'courses' => ['FKN']],
        ];
        $groups = ['BP-SBF-SEE-BIN' => 'sbf', 'BP-SBF-SEE' => 'sbf', 'BP-SBF-BIN' => 'sbf', 'BP-SBF-BIN-SEGEL' => 'sbf', 'BP-SRC-UBI' => 'funk', 'BP-SRC' => 'funk', 'BP-UBI' => 'funk', 'BP-SKN' => 'signal', 'BP-FKN' => 'signal'];
        foreach ($list as $i => $item) {
            $list[$i]['group'] = $groups[$item['sku']];
        }
        $names = Labels::courses();
        foreach (self::ADDONS as $code) {
            $list[] = ['sku' => 'BP-'.$code, 'name' => $names[$code] ?? $code, 'price' => '13.90', 'days' => 182, 'courses' => [$code], 'group' => 'addon'];
        }
        // Funk-Theoriekurse ohne Gerätesimulation (kostenlose Testversion bleibt); Bundle und Pässe behalten sie.
        foreach ($list as $i => $item) {
            if (in_array($item['sku'], self::SIM_UPGRADE_FOR, true)) {
                $list[$i]['features'] = self::featuresWithoutSim();
            }
        }
        // Videokurs-Stufen: Basis = Theorie (ohne Video), dahinter Komplett (mit Video).
        $tiered = [];
        $upgrades = [];
        foreach ($list as $item) {
            $surcharge = self::VIDEO_TIERS[$item['sku']] ?? null;
            if ($surcharge === null) {
                $tiered[] = $item;
                continue;
            }
            $label = trim((string) preg_replace('/\s*\((Einzelkurs|Funk)\)$|\s+Vollkurs$/u', '', $item['name']));
            $features = $item['features'] ?? ProductAccessRepository::features();
            $tiered[] = ['name' => $label.' – Theorie', 'features' => array_values(array_diff($features, [ProductAccessRepository::FEATURE_ALL, AccessService::VIDEO])), 'tier' => 'theorie'] + $item;
            $tiered[] = ['sku' => self::komplettSku($item['sku']), 'name' => $label.' – Komplett mit Videokurs', 'price' => number_format((float) $item['price'] + (float) $surcharge, 2, '.', ''), 'features' => $features, 'tier' => 'komplett'] + $item;
            $upgrades[] = ['sku' => self::videoSku($item['sku']), 'name' => 'Videokurs-Upgrade: '.$label, 'price' => $surcharge, 'days' => $item['days'], 'courses' => $item['courses'], 'group' => 'videoupgrade', 'features' => [AccessService::VIDEO], 'hidden' => true, 'tier' => 'upgrade', 'upgrade_for' => $item['sku'], 'category' => $item['group']];
        }
        $list = $tiered;
        $list[] = ['sku' => 'BP-KAPITAEN', 'name' => 'Kapitäns-Bundle (SBF See + Binnen + Funk)', 'price' => '88.70', 'days' => 365, 'courses' => [...self::SBF_ALL, ...self::RADIO_ALL], 'group' => 'bundle'];
        $all = array_keys($names);
        $list[] = ['sku' => 'BP-PASS-1M', 'name' => '1-Monats-Pass (alle Inhalte)', 'price' => '16.90', 'days' => 30, 'courses' => $all, 'group' => 'bundle'];
        $list[] = ['sku' => 'BP-PASS-3M', 'name' => '3-Monats-Pass (alle Inhalte)', 'price' => '34.90', 'days' => 90, 'courses' => $all, 'group' => 'bundle'];
        // Gruppenpakete (Festlegung 06.11.2026): Käufer erhält ein Kontingent und lädt Mitlernende ein.
        $see = ['SBF-SEE'];
        $bin = ['SBF-BIN-MOTOR'];
        foreach ([
            ['GRP-PAIR-01', 'Crew-Ticket: SBF See (2 Pers.)', 2, $see, '68.90'],
            ['GRP-PAIR-02', 'Crew-Ticket: SBF Binnen (2 Pers.)', 2, $bin, '66.90'],
            ['GRP-PAIR-03', 'Crew-Ticket: Kombi See/Binnen (2 Pers.)', 2, self::SBF_ALL, '103.90'],
            ['GRP-PAIR-04', 'Crew-Ticket: Funk SRC/UBI (2 Pers.)', 2, self::RADIO_ALL, '79.90'],
            ['GRP-FAM-01', 'Familien-Flotte: SBF See (3 Pers.)', 3, $see, '98.90'],
            ['GRP-FAM-02', 'Familien-Flotte: Kombi See/Binnen (3 Pers.)', 3, self::SBF_ALL, '148.90'],
            ['GRP-FAM-03', 'Familien-Flotte: Kombi See/Binnen (4 Pers.)', 4, self::SBF_ALL, '187.90'],
            ['GRP-CREW-01', 'Skipper-Crew 5: SBF See', 5, $see, '157.90'],
            ['GRP-CREW-02', 'Skipper-Crew 5: Kombi See/Binnen', 5, self::SBF_ALL, '237.90'],
            ['GRP-CREW-03', 'Skipper-Crew 5: Funk SRC/UBI', 5, self::RADIO_ALL, '181.90'],
            ['GRP-CREW-04', 'Yacht-Crew 7: SBF See', 7, $see, '216.90'],
            ['GRP-CREW-05', 'Yacht-Crew 7: Kombi See/Binnen', 7, self::SBF_ALL, '316.90'],
            ['GRP-CLUB-10', 'Flottille 10: SBF See', 10, $see, '294.90'],
            ['GRP-CLUB-11', 'Flottille 10: Kombi See/Binnen', 10, self::SBF_ALL, '424.90'],
            ['GRP-CLUB-20', 'Vereins-Paket 20: SBF See', 20, $see, '559.90'],
            ['GRP-CLUB-21', 'Vereins-Paket 20: Kombi See/Binnen', 20, self::SBF_ALL, '789.90'],
            ['GRP-CLUB-50', 'Partner-Flat 50: Kombi See/Binnen', 50, self::SBF_ALL, '1725.00'],
        ] as [$sku, $name, $seats, $courses, $price]) {
            $item = ['sku' => $sku, 'name' => $name, 'price' => $price, 'days' => 365, 'courses' => $courses, 'group' => 'groups', 'seats' => $seats];
            if ($courses === self::RADIO_ALL) {
                $item['features'] = self::featuresWithoutSim(); // wie die Funk-Einzelkurse
            }
            $list[] = $item;
        }
        // Funkgerät-Simulator als eigene Zugänge (Web-App, alle Geräte, kein Abo).
        foreach ([
            ['SIM-PASS-14D', '14-Tage-Prüfungs-Sprint (SRC/UBI Web-App)', '9.90', 14],
            ['SIM-PASS-3M', '3-Monats-Standardzugang (SRC/UBI Web-App)', '23.50', 92], // 3 Kalendermonate sicher abgedeckt
            ['SIM-PASS-12M', '12-Monats-Sorglos-Zugang (SRC/UBI Web-App)', '34.50', 365],
            [self::SIM_UPGRADE, 'Praxis-Sorglos-Upgrade: Funkgerät-Simulator 12 Monate', '13.90', 365],
        ] as [$sku, $name, $price, $days]) {
            $list[] = ['sku' => $sku, 'name' => $name, 'price' => $price, 'days' => $days, 'courses' => self::SIM_COURSES, 'group' => 'simulator', 'features' => [AccessService::DEVICE], 'hidden' => $sku === self::SIM_UPGRADE, 'guarantee' => false];
        }
        // Video-Upgrades: nicht im Shop gelistet, nur mit Theorie-Stufe kaufbar (Geld-zurück-Garantie wie der Kurs).
        array_push($list, ...$upgrades);

        return $list;
    }

    /** @return list<string> alle Funktionen außer der Gerätesimulation */
    private static function featuresWithoutSim(): array
    {
        return array_values(array_diff(AccessService::FEATURES, [AccessService::DEVICE]));
    }

    /** @return array<string,mixed>|null Katalogeintrag zur Artikelnummer */
    public static function find(string $sku): ?array
    {
        foreach (self::products() as $def) {
            if ($def['sku'] === $sku) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Legt die Produkte an bzw. gleicht sie ab (Preis, Name, Zuordnung, Garantie).
     *
     * @return list<array{sku:string,product_id:int,action:string}>
     */
    public static function applyProducts(bool $dryRun = false, bool $publish = false): array
    {
        if (! function_exists('wc_get_product_id_by_sku')) {
            throw new \RuntimeException(__('WooCommerce ist nicht aktiv.', 'bootspruefung-learning'));
        }
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        $report = [];
        foreach (self::products() as $position => $def) {
            $id = (int) wc_get_product_id_by_sku($def['sku']);
            $product = $id ? wc_get_product($id) : null;
            $action = $product ? 'aktualisiert' : 'neu';
            if ($dryRun) {
                $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action.' (Vorschau)'];
                continue;
            }
            $before = $product ? self::fingerprint($product) : '';
            if (! $product) {
                $product = new \WC_Product_Simple();
                $product->set_sku($def['sku']);
                $product->set_status($publish ? 'publish' : 'draft');
            } elseif ($publish) {
                $product->set_status('publish');
            }
            $product->set_name($def['name']);
            $product->set_regular_price($def['price']);
            $product->set_virtual(true);
            $product->set_reviews_allowed(false); // keine Produktbewertungen (leerer Reiter „Bewertungen (0)“)
            $seats = (int) ($def['seats'] ?? 0);
            // Gruppenpakete: mehrere Pakete in einer Bestellung möglich (Plätze = Menge × Plätze je Paket).
            $product->set_sold_individually($seats === 0);
            // Upgrade nur im Checkout anbieten, nicht im Shop listen.
            $product->set_catalog_visibility(! empty($def['hidden']) ? 'hidden' : 'visible');
            $product->set_menu_order((int) $position); // Shop-Reihenfolge wie im Katalog
            $text = self::texts($def);
            // Texte nur setzen, solange sie nicht im Shop von Hand geändert wurden.
            $ownHash = (string) $product->get_meta('_bp_catalog_text');
            $currentHash = md5($product->get_short_description().'|'.$product->get_description());
            if ($product->get_description() === '' || $ownHash === $currentHash) {
                $product->set_short_description($text['short']);
                $product->set_description($text['long']);
                $product->update_meta_data('_bp_catalog_text', md5($text['short'].'|'.$text['long']));
            }
            $product->set_category_ids([self::category($def['category'] ?? $def['group'])]);
            $image = self::image($def['sku'], (int) $product->get_image_id());
            if ($image > 0) {
                $product->set_image_id($image);
            }
            if ($action !== 'neu') {
                $action = self::fingerprint($product) === $before ? 'unverändert' : 'aktualisiert';
            }
            $id = (int) $product->save();
            $scopes = [];
            foreach (array_unique($def['courses']) as $course) {
                foreach ($def['features'] ?? ProductAccessRepository::features() as $feature) {
                    $scopes[] = ['course_code' => $course, 'feature_code' => $feature];
                }
            }
            $repo->save($id, 0, $scopes, $def['days'], 'extend', 'revoke_on_full_refund', $seats > 0 ? $seats : null);
            // Garantie nur für Einzelzugänge: bei Gruppen lernen die Eingeladenen, nicht der Käufer;
            // Simulator-Zugänge ohne Garantie (Festlegung 06.11.2026).
            $guarantee = $seats === 0 && ($def['guarantee'] ?? true);
            MoneyBack::setPolicy($id, $guarantee ? self::MONEY_BACK_DAYS : null, $guarantee ? self::MONEY_BACK_MAX_PROGRESS : null);
            $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action];
        }
        if (! $dryRun) {
            AuditLog::record('shop_catalog_applied', 'settings', null, null, ['products' => count($report), 'publish' => $publish]);
        }

        return $report;
    }

    private static function fingerprint(\WC_Product $product): string
    {
        return md5(implode('|', [wp_specialchars_decode($product->get_name()), $product->get_regular_price(), $product->get_status(), $product->get_menu_order(), $product->get_short_description(), $product->get_description(), implode(',', $product->get_category_ids()), $product->get_image_id(), $product->get_catalog_visibility(), (int) $product->get_sold_individually()]));
    }

    private const CATEGORIES = [
        'sbf' => 'Sportbootführerscheine',
        'funk' => 'Funkzeugnisse',
        'signal' => 'Seenotsignalmittel',
        'addon' => 'Ergänzungskurse',
        'bundle' => 'Pakete & Pässe',
        'groups' => 'Gruppen & Vereine',
        'simulator' => 'Funkgerät-Simulator',
    ];

    /** Feste Kürzel (Slugs) – das Theme verlinkt darauf, unabhängig von Sprache/Permalinks. */
    public const CATEGORY_SLUGS = ['sbf' => 'sbf', 'funk' => 'funk', 'signal' => 'seenot', 'addon' => 'ergaenzung', 'bundle' => 'pakete', 'groups' => 'gruppen', 'simulator' => 'funk-simulator'];

    private static function category(string $group): int
    {
        $name = self::CATEGORIES[$group] ?? self::CATEGORIES['bundle'];
        $slug = self::CATEGORY_SLUGS[$group] ?? 'pakete';
        $term = get_term_by('slug', $slug, 'product_cat') ?: get_term_by('name', $name, 'product_cat');
        if ($term instanceof \WP_Term) {
            if ($term->slug !== $slug) {
                wp_update_term((int) $term->term_id, 'product_cat', ['slug' => $slug]);
            }

            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, 'product_cat', ['slug' => $slug]);

        return is_wp_error($created) ? 0 : (int) $created['term_id'];
    }

    /** Produktbild aus assets/products/<SKU>.png übernehmen (einmalig; eigenes Bild im Shop bleibt). */
    private static function image(string $sku, int $currentId): int
    {
        if ($currentId > 0 && (string) get_post_meta($currentId, '_bp_catalog_image', true) !== $sku) {
            return 0; // von Hand gesetztes Bild
        }
        if ($currentId > 0) {
            return $currentId;
        }
        $file = dirname(__DIR__, 2).'/assets/products/'.$sku.'.png';
        if (! is_file($file)) {
            return 0;
        }
        $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_bp_catalog_image', 'meta_value' => $sku]);
        if ($existing !== []) {
            return (int) $existing[0];
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $tmp = wp_tempnam($sku.'.png');
        copy($file, $tmp);
        $id = media_handle_sideload(['name' => strtolower($sku).'.png', 'tmp_name' => $tmp], 0, $sku);
        if (is_wp_error($id)) {
            @unlink($tmp);

            return 0;
        }
        update_post_meta((int) $id, '_bp_catalog_image', $sku);

        return (int) $id;
    }

    /**
     * Produkttexte aus dem tatsächlichen Inhalt der Kurse (Zahlen aus der Datenbank).
     *
     * @param array{sku:string,name:string,days:int,courses:list<string>,group:string} $def
     * @return array{short:string,long:string}
     */
    /**
     * Kennzahlen über eine Kursmenge (Fragen als Vereinigung, Rest summiert).
     *
     * @param list<string> $courses
     * @return array{questions:int,nav:int,praxis:int,videos:int,papers:int}
     */
    public static function courseStats(array $courses): array
    {
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $courses = array_values(array_unique($courses));
        if ($courses === []) {
            return ['questions' => 0, 'nav' => 0, 'praxis' => 0, 'videos' => 0, 'papers' => 0];
        }
        static $cache = [];
        $key = implode(',', $courses);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $in = implode(',', array_fill(0, count($courses), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter dynamisch
        $count = static fn (string $sql): int => (int) $wpdb->get_var($wpdb->prepare($sql, ...$courses));

        return $cache[$key] = [
            'questions' => $count("SELECT COUNT(DISTINCT mq.question_id) FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id WHERE c.code IN ({$in})"),
            'nav' => $count("SELECT COUNT(DISTINCT x.task_id) FROM {$p}navigation_task_course x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
            'praxis' => $count("SELECT COUNT(*) FROM {$p}praxis_task x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
            'videos' => $count("SELECT COUNT(*) FROM {$p}video_lesson vl JOIN {$p}video_module vm ON vm.id = vl.module_id JOIN {$p}course c ON c.id = vm.course_id WHERE c.code IN ({$in})"),
            'papers' => $count("SELECT COUNT(*) FROM {$p}exam_paper x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
        ];
        // phpcs:enable
    }

    public static function texts(array $def): array
    {
        if (($def['group'] ?? '') === 'simulator') {
            return self::simTexts($def);
        }
        if (($def['group'] ?? '') === 'videoupgrade') {
            return self::videoUpgradeTexts($def);
        }
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $all = array_values(array_unique($def['courses']));
        $pass = str_starts_with($def['sku'], 'BP-PASS');
        // Kombikurse enthalten die Einzelkurse: nur den Hauptkurs zählen (Bundle: beide Kombis), sonst doppelt.
        $courses = match (true) {
            $pass => $all,
            $def['sku'] === 'BP-KAPITAEN' => ['SBF-SEE-BIN', 'SRC-UBI'],
            default => [$all[0]],
        };
        $stats = self::courseStats($courses);
        ['questions' => $questions, 'nav' => $nav, 'praxis' => $praxis, 'videos' => $videos, 'papers' => $papers] = $stats;
        $radio = array_intersect($all, ['SRC', 'UBI', 'SRC-UBI', 'ADD-UBI-TO-SRC']) !== [];
        if ($pass) {
            [$nav, $praxis, $videos, $papers] = [0, 0, 0, 0];
        }
        $months = $def['days'] >= 360 ? '12 Monate' : ($def['days'] >= 180 ? '6 Monate' : $def['days'].' Tage');
        $names = array_map([Labels::class, 'course'], $all);

        $seats = (int) ($def['seats'] ?? 0);
        $intro = match ($def['group']) {
            'groups' => sprintf('%d Lernzugänge für %s – zum Beispiel für Paare, Familien, Crews oder Vereine, die gemeinsam auf die Prüfung lernen.', $seats, implode(' und ', array_unique(array_map(static fn ($n) => preg_replace('/\s+(Vollkurs|Kombikurs)$/u', '', $n), [$names[0]])))),
            'bundle' => str_starts_with($def['sku'], 'BP-PASS')
                ? sprintf('Voller Zugriff auf alle Kurse der Plattform für %s – ideal, wenn du konzentriert auf einen Prüfungstermin hinlernst oder mehrere Scheine kombinierst.', $months)
                : 'Alle wichtigen Scheine für Küste und Binnen in einem Paket: Sportbootführerschein See und Binnen plus die Funkzeugnisse SRC und UBI.',
            'addon' => sprintf('Du hast bereits einen Schein und brauchst nur noch den Ergänzungsteil (%s)? Dieser Kurs bereitet dich gezielt auf die Ergänzungsprüfung vor.', preg_replace('/^Ergänzung\s+/u', '', $names[0])),
            default => sprintf('Online-Vorbereitung auf die Prüfung %s: lernen, üben und Prüfungen simulieren – am Rechner, Tablet oder Smartphone.', trim((string) preg_replace('/\s+(Vollkurs|Kombikurs)$/u', '', $names[0]))),
        };
        $items = [];
        if ($questions > 0) {
            $items[] = sprintf('%d Prüfungsfragen im Smarttrainer mit Erklärungen, Wiederholung falsch beantworteter Fragen und Favoriten', $questions);
        }
        $items[] = $papers > 0 ? sprintf('Prüfungssimulation mit Zeitlimit und Auswertung, inkl. %d Prüfungsbögen', $papers) : 'Prüfungssimulation mit Zeitlimit und Auswertung';
        if ($nav > 0) {
            $items[] = sprintf('%d Navigationsaufgaben mit Musterlösungen', $nav);
        }
        if ($praxis > 0) {
            $items[] = sprintf('%d Praxiskarten zur Vorbereitung auf die praktische Prüfung', $praxis);
        }
        $withVideo = in_array(AccessService::VIDEO, $def['features'] ?? [AccessService::VIDEO], true);
        if ($videos > 0 && $withVideo) {
            $items[] = sprintf('Videokurs mit %d Lektionen', $videos);
        } elseif ($videos > 0 && ($def['tier'] ?? '') === 'theorie') {
            $items[] = sprintf('Videokurs (%d Lektionen) nicht enthalten – als Upgrade für %s € erhältlich, erste Lektion kostenlos', $videos, number_format((float) (self::find(self::videoSku($def['sku']))['price'] ?? self::VIDEO_TIERS[$def['sku']] ?? 0), 2, ',', '.'));
        }
        $withSim = in_array(AccessService::DEVICE, $def['features'] ?? [AccessService::DEVICE], true);
        if ($radio && $seats > 0 && ! $withSim) {
            $items[] = 'Funktraining: Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien; Funkgerät-Simulator nicht enthalten (für jede Person als Simulator-Zugang erhältlich)';
        } elseif ($radio) {
            $items[] = $withSim
                ? 'Funktraining: Funktexte, Vokabeln, Buchstabiertafel, Praxisszenarien und Funkgerät-Simulator'
                : 'Funktraining: Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien; Funkgerät-Simulator als kostenlose Testversion (Vollversion als Upgrade)';
        }
        if ($pass) {
            $items[] = sprintf('Alle %d Kurse: Sportbootführerscheine, Funkzeugnisse, Seenotsignalmittel und Ergänzungskurse – mit allen Trainern und Videos', count($all));
        }
        $items[] = 'Lernfortschritt wird gespeichert – überall weiterlernen';
        if ($seats > 0) {
            array_unshift($items, sprintf('%d Plätze: Du lädst die Teilnehmenden per Link oder E-Mail ein; jede Person lernt mit eigenem Konto und eigenem Fortschritt. Ein Platz kann auch für dich selbst sein.', $seats));
        }
        $long = '<p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $items)).'</li></ul>'
            .'<p>'.esc_html(sprintf('Zugang %1$s ab Zahlungseingang – freigeschaltet, sobald die Zahlung eingegangen ist. Du lernst im Bereich „%2$s“; deine Zugänge findest du unter „Mein Lernen“ im Kundenkonto.', $months, \Bootspruefung\Learning\Settings\Settings::brand())).'</p>'
            .($seats > 0
                ? '<p>'.esc_html('Die Gruppe verwaltest du unter „Meine Gruppen“ im Lernbereich. Der Zugang gilt für alle Teilnehmenden bis zum Ende der Laufzeit ab Zahlungseingang. Dein gesetzliches Widerrufsrecht bleibt unberührt.').'</p>'
                : self::guaranteeParagraph())
            .(count($all) > 1 && ! $pass ? '<p>'.esc_html('Enthält: '.implode(', ', $names)).'</p>' : '');
        $short = $seats > 0
            ? sprintf('%d Lernzugänge · %s · Zugang %s', $seats, $questions > 0 ? $questions.' Prüfungsfragen je Person' : 'Alle Inhalte', $months)
            : sprintf('%s · %s · Zugang %s · %d Tage Geld-zurück-Garantie', $questions > 0 ? $questions.' Prüfungsfragen' : 'Alle Inhalte', 'Prüfungssimulation', $months, self::MONEY_BACK_DAYS);

        return ['short' => esc_html($short), 'long' => $long];
    }

    /** Garantie-Absatz der Produkttexte (Bedingungen wie in den AGB, Abschnitt „Geld-zurück-Garantie“). */
    private static function guaranteeParagraph(): string
    {
        return '<p><strong>'.esc_html(sprintf('%d Tage Geld-zurück-Garantie (weniger als %d %% genutzt):', self::MONEY_BACK_DAYS, (int) self::MONEY_BACK_MAX_PROGRESS)).'</strong> '
            .esc_html(sprintf('Innerhalb von %1$d Tagen ab Zahlungseingang erstatten wir dir den Preis, wenn du weniger als %2$d %% der gekauften Inhalte genutzt hast (beantwortete Fragen bzw. abgeschlossene Video-Lektionen). Anfrage unter „Mein Lernen“. Dein gesetzliches Widerrufsrecht bleibt unberührt.', self::MONEY_BACK_DAYS, (int) self::MONEY_BACK_MAX_PROGRESS))
            .' <a href="'.esc_url(home_url('/agb/#geld-zurueck-garantie')).'">'.esc_html('Details in den AGB').'</a></p>';
    }

    /** Texte des Video-Upgrades (nur zusammen mit der Theorie-Stufe). @return array{short:string,long:string} */
    private static function videoUpgradeTexts(array $def): array
    {
        $course = Labels::course($def['courses'][0]);
        $videos = self::courseStats([$def['courses'][0]])['videos'];
        $long = '<p>'.esc_html(sprintf('Erweitere deinen Theoriekurs %s um den Videokurs: Der gesamte Prüfungsstoff Kapitel für Kapitel erklärt%s.', $course, $videos > 0 ? sprintf(' – %d Lektionen', $videos) : '')).'</p>'
            .'<p>'.esc_html(sprintf('Nur zusammen mit dem Theoriekurs %s erhältlich (im Warenkorb oder bereits gekauft). Der Videokurs läuft so lange wie dein Theoriezugang zu diesem Kurs.', $course)).'</p>'
            .self::guaranteeParagraph();

        return ['short' => esc_html(sprintf('Videokurs zum Theoriekurs %s · Laufzeit wie dein Theoriezugang · %d Tage Geld-zurück-Garantie', $course, self::MONEY_BACK_DAYS)), 'long' => $long];
    }

    /** Web-App-Vorteile des Simulators (ohne Vergleich mit Anbietern, Festlegung 06.11.2026). @return list<string> */
    public static function simBenefits(): array
    {
        return [
            'Ein Login für alle Geräte: Übe am PC, auf dem Tablet oder auf dem Smartphone.',
            'Keine Installation: läuft direkt im Browser auf Mac, Windows, iOS und Android.',
            'Keine Updates nötig: Aktualisierungen kommen automatisch.',
        ];
    }

    /** @param array<string,mixed> $def @return array{short:string,long:string} */
    private static function simTexts(array $def): array
    {
        $intro = match ($def['sku']) {
            'SIM-PASS-14D' => 'Voller Web-Zugriff auf alle Funkgeräte-Simulationen – für die letzten Tage vor der praktischen Prüfung. Läuft nach 14 Tagen automatisch aus.',
            'SIM-PASS-3M' => 'Der Standard-Zugang für die reguläre Kurszeit: alle Funkgeräte-Simulationen für drei Monate.',
            'SIM-PASS-12M' => 'Für alle, die sich maximal Zeit lassen wollen oder vor der Prüfung immer wieder üben möchten: alle Funkgeräte-Simulationen für zwölf Monate. Persönlicher Zugang für eine Person.',
            default => 'Zusatzoption beim Kauf eines Funkkurses: schaltet den Funkgerät-Simulator für zwölf Monate frei.',
        };
        $sim = self::simStats();
        $items = [
            sprintf('%d Lektionen an %d Funkgeräten: Bedienung, Routineverkehr, DSC, Not-, Dringlichkeits- und Sicherheitsverkehr', $sim['lessons'], $sim['devices']),
            sprintf('Notalarm (DISTRESS) mit DSC-Controller und Notmeldung per Sprechfunk'),
            sprintf('Prüfungsmodus: %d Prüfungsaufgaben in %d Prüfungsreihen wie in der praktischen SRC- und UBI-Prüfung', $sim['tasks'], $sim['series']),
            'Freies Üben: Rufe empfangen und beantworten',
        ];
        $months = $def['days'] >= 360 ? '12 Monate' : ($def['days'] >= 91 ? '3 Monate' : $def['days'].' Tage');
        $long = '<p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $items)).'</li></ul>'
            .'<p><strong>'.esc_html('Läuft direkt im Browser').'</strong></p><ul><li>'.implode('</li><li>', array_map('esc_html', self::simBenefits())).'</li></ul>'
            .'<p>'.esc_html(sprintf('Zugang %s ab Zahlungseingang, Einmalzahlung ohne Abo – endet automatisch. Gilt für die Funkkurse SRC und UBI. Für diesen Zugang gibt es keine Geld-zurück-Garantie; dein gesetzliches Widerrufsrecht bleibt unberührt.', $months)).'</p>';
        $short = sprintf('Funkgerät-Simulator · alle Lektionen und Prüfungsaufgaben · Zugang %s · kein Abo', $months);

        return ['short' => esc_html($short), 'long' => $long];
    }

    /** Kennzahlen der Gerätesimulation aus der Datenbank. @return array{lessons:int,devices:int,series:int,tasks:int} */
    public static function simStats(): array
    {
        global $wpdb;
        $json = $wpdb->get_var("SELECT data FROM {$wpdb->prefix}bp_device WHERE source_id = 'geraetesimulation' AND status = 'published'");
        $data = $json ? (json_decode((string) $json, true) ?: []) : [];
        $tasks = 0;
        foreach ($data['exams'] ?? [] as $lessons) {
            $tasks += count((array) $lessons);
        }

        return ['lessons' => count($data['lessons'] ?? []), 'devices' => count($data['devices'] ?? []), 'series' => count($data['exams'] ?? []), 'tasks' => $tasks];
    }

    /**
     * Schnupper-Paket für alle Kurse (ersetzt frühere Regeln dieses Pakets, andere bleiben).
     *
     * @return array{added:int,courses:int}
     */
    public static function applyFreeTier(bool $dryRun = false): array
    {
        global $wpdb;
        $table = $wpdb->prefix.'bp_access_rule';
        $courses = array_keys(Labels::courses());
        $added = 0;
        $now = current_time('mysql', true);
        foreach ($courses as $course) {
            foreach (self::FREE_TIER as $feature => $limit) {
                $limitType = AccessService::LIMIT_TYPES[$feature] ?? null;
                if ($limitType === null) {
                    continue;
                }
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE course_code = %s AND feature_code = %s AND module_code IS NULL AND access_type = 'free_limited' AND limit_type = %s AND limit_value = %d AND valid_until IS NULL",
                    $course, $feature, $limitType, $limit
                ));
                if ($exists > 0) {
                    continue;
                }
                $added++;
                if (! $dryRun) {
                    $wpdb->insert($table, ['course_code' => $course, 'feature_code' => $feature, 'access_type' => 'free_limited', 'limit_type' => $limitType,
                        'limit_value' => $limit, 'valid_from' => $now, 'valid_until' => null, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        if (! $dryRun && $added > 0) {
            AuditLog::record('free_tier_applied', 'settings', null, self::FREE_REASON, ['rules' => $added]);
        }

        return ['added' => $added, 'courses' => count($courses)];
    }
}
