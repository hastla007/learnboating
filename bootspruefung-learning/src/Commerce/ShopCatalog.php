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
    /**
     * Vorerst nicht verkaufte Kurse (Entscheidung Betreiber 13.11.2026): „Ergänzung FKN → SKN“ hat denselben Inhalt und
     * Preis wie der SKN Vollkurs; bis zur fachlichen Klärung (T37) kein eigenes Produkt. Der Kurs bleibt im Lernbereich
     * (z. B. in Pässen). Wieder einschalten: Filter bootspruefung_catalog_disabled_courses.
     */
    public const DISABLED_COURSES = ['ADD-FKN-TO-SKN'];

    /** @return list<string> */
    public static function disabledCourses(): array
    {
        return array_values(array_map('strval', (array) apply_filters('bootspruefung_catalog_disabled_courses', self::DISABLED_COURSES)));
    }

    /** SKUs der deaktivierten Kurse (Theorie, Komplett, Video-Upgrade). @return list<string> */
    public static function disabledSkus(): array
    {
        $out = [];
        foreach (self::disabledCourses() as $code) {
            $base = 'BP-'.$code;
            array_push($out, $base, self::komplettSku($base), self::videoSku($base));
        }

        return array_values(array_unique($out));
    }

    public static function products(): array
    {
        $own = self::ownPrices();
        $disabled = self::disabledCourses();
        // Einzelprodukte deaktivierter Kurse weglassen (Pässe und Bundle mit allen Kursen bleiben).
        $list = array_values(array_filter(self::catalogProducts(), static fn (array $def): bool => array_diff(array_unique($def['courses']), $disabled) !== []));
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
            // Tausenderpunkte vor dem Komma tolerieren („1.299,00“, „1.299“).
            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value)) {
                $value = str_replace('.', '', $value);
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
            [self::SIM_UPGRADE, 'Praxis-Sorglos-Upgrade: Funkgerät-Simulator zum Funkkurs', '13.90', 365], // ruht ohne aktiven Funkkurs (AccessService)
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

    /** Meta mit den Fingerprints der zuletzt vom Katalog geschriebenen Werte (Feld => md5). */
    public const FIELDS_META = '_bp_catalog_fields';

    /** Vom Katalog verwaltete Felder, die nach einer Handänderung im Shop nicht mehr überschrieben werden. */
    public const MANAGED_FIELDS = ['name', 'texts', 'categories', 'visibility', 'sold_individually', 'access', 'money_back'];

    /** Meta: zuletzt vom Katalog geschriebener regulärer Preis („29.90“). */
    public const PRICE_META = '_bp_catalog_price';

    /** Meta: zuletzt vom Katalog gesetzter Status (draft/publish). */
    public const STATUS_META = '_bp_catalog_status';

    /** Meta: zuletzt von Hand (nicht vom Katalog) gesetzter Status, siehe trackManualStatus(). */
    public const MANUAL_STATUS_META = '_bp_manual_status';

    /** Schreibt der Katalog gerade selbst? (Statuswechsel dann nicht als Handänderung merken.) */
    private static bool $writing = false;

    /** Hook transition_post_status: Statuswechsel eines Produkts von Hand merken (Entwurf = bewusst zurückgestellt). */
    public static function trackManualStatus(string $new, string $old, \WP_Post $post): void
    {
        if (self::$writing || $new === $old || $post->post_type !== 'product' || in_array($new, ['auto-draft', 'inherit', 'trash'], true) || $old === 'new' || $old === 'auto-draft') {
            return;
        }
        update_post_meta($post->ID, self::MANUAL_STATUS_META, $new);
    }

    /** Preis wie im Katalog („29.90“), leer bleibt leer. */
    private static function money(string $price): string
    {
        return $price === '' ? '' : number_format((float) $price, 2, '.', '');
    }

    /**
     * Preis im Shop von Hand geändert? Ja, wenn der reguläre Preis im Shop vom zuletzt vom Katalog geschriebenen
     * Preis abweicht (Meta PRICE_META) und nicht schon dem Sollpreis entspricht. Ohne Meta (Produkte aus älteren
     * Abgleichen) gilt der Katalog-Standardpreis bzw. der eigene Preis als zuletzt geschrieben.
     * @param array<string,mixed> $def Katalogeintrag aus products()
     */
    public static function priceChangedByHand(\WC_Product $product, array $def): bool
    {
        $current = self::money((string) $product->get_regular_price('edit'));
        $wanted = self::money((string) $def['price']);
        if ($current === '' || $current === $wanted) {
            return false;
        }
        $last = self::money((string) $product->get_meta(self::PRICE_META));
        if ($last !== '') {
            return $current !== $last;
        }

        return $current !== self::money((string) ($def['catalog_price'] ?? $def['price']));
    }

    /**
     * Bleibt ein vorhandener Entwurf beim Veröffentlichen Entwurf? Ja, wenn der Betreiber ihn selbst auf Entwurf
     * gesetzt hat (Statuswechsel von Hand gemerkt) oder – bei Produkten aus älteren Abgleichen ohne Vermerk – das
     * Produkt im Editor bearbeitet wurde. Nur vom Katalog als Entwurf angelegte Produkte werden veröffentlicht.
     */
    public static function heldBack(\WC_Product $product): bool
    {
        $status = $product->get_status();
        if ($status === 'publish') {
            return false;
        }
        if ($status !== 'draft') {
            return true; // privat, ausstehend, geplant: bewusste Wahl des Betreibers
        }
        $id = (int) $product->get_id();
        $manual = (string) get_post_meta($id, self::MANUAL_STATUS_META, true);
        if ($manual !== '') {
            return $manual === 'draft';
        }
        if ((string) get_post_meta($id, self::STATUS_META, true) === 'draft') {
            return false;
        }

        return get_post_meta($id, '_edit_last', true) !== '';
    }

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'price' => __('Preis (im Shop von Hand geändert)', 'bootspruefung-learning'),
            'status' => __('Status (Entwurf bewusst zurückgestellt)', 'bootspruefung-learning'),
            'name' => __('Name', 'bootspruefung-learning'),
            'texts' => __('Texte', 'bootspruefung-learning'),
            'categories' => __('Kategorien', 'bootspruefung-learning'),
            'visibility' => __('Sichtbarkeit', 'bootspruefung-learning'),
            'sold_individually' => __('Einzelverkauf', 'bootspruefung-learning'),
            'access' => __('Lernzugang (Zuordnung, Laufzeit, Verlängerung, Erstattung, Plätze)', 'bootspruefung-learning'),
            'money_back' => __('Geld-zurück-Garantie', 'bootspruefung-learning'),
            default => $field,
        };
    }

    /**
     * Legt die Produkte an bzw. gleicht sie ab (Preis, Name, Zuordnung, Garantie).
     *
     * Preise kommen immer aus dem Katalog bzw. der Preis-Seite. Alle anderen verwalteten
     * Felder werden beim ersten Anlegen gesetzt und danach nur überschrieben, solange ihr
     * aktueller Wert noch dem zuletzt vom Katalog geschriebenen entspricht (Fingerprint
     * je Feld). Von Hand im Shop geänderte Felder bleiben erhalten und stehen in
     * 'skipped'. Kategorien werden ergänzt, nie entfernt.
     *
     * Preis (T98): Weicht der reguläre Preis im Shop vom zuletzt vom Katalog geschriebenen ab, wurde er von Hand
     * geändert – er wird nicht still überschrieben, sondern gemeldet ('price', Feld 'price' in 'skipped'), außer
     * die SKU steht in $options['overwrite_prices'] (true = alle). Veröffentlichen ($publish) gilt nur für neu
     * angelegte und vom Katalog als Entwurf angelegte Produkte; vom Betreiber zurückgestellte Entwürfe bleiben
     * Entwurf ('status'), außer die SKU steht in $options['publish_held'] (true = alle). Deaktivierte Kurse
     * (z. B. ADD-FKN-TO-SKN) bleiben immer Entwurf.
     *
     * @param array{overwrite_prices?:list<string>|bool,publish_held?:list<string>|bool} $options
     * @return list<array{sku:string,product_id:int,action:string,skipped:list<string>,price:string,status:string}>
     */
    public static function applyProducts(bool $dryRun = false, bool $publish = false, array $options = []): array
    {
        if (! function_exists('wc_get_product_id_by_sku')) {
            throw new \RuntimeException(__('WooCommerce ist nicht aktiv.', 'bootspruefung-learning'));
        }
        global $wpdb;
        $repo = new ProductAccessRepository($wpdb);
        $report = [];
        $listed = static function (string $key, string $sku) use ($options): bool {
            $value = $options[$key] ?? [];

            return $value === true || (is_array($value) && in_array($sku, $value, true));
        };
        $de = static fn (string $price): string => self::priceText($price);
        foreach (self::products() as $position => $def) {
            $id = (int) wc_get_product_id_by_sku($def['sku']);
            $product = $id ? wc_get_product($id) : null;
            $isNew = ! $product;
            $action = $product ? 'aktualisiert' : 'neu';
            // Preis: von Hand im Shop geändert? Dann nur mit ausdrücklicher Bestätigung überschreiben.
            $priceNote = '';
            $setPrice = true;
            if ($product && self::priceChangedByHand($product, $def)) {
                $shopPrice = (string) $product->get_regular_price('edit');
                if ($listed('overwrite_prices', $def['sku'])) {
                    $priceNote = sprintf(__('von Hand geändert (%1$s), bewusst überschrieben mit %2$s', 'bootspruefung-learning'), $de($shopPrice), $de((string) $def['price']));
                } else {
                    $setPrice = false;
                    $priceNote = sprintf(__('von Hand geändert, nicht überschrieben: im Shop %1$s, Katalog %2$s', 'bootspruefung-learning'), $de($shopPrice), $de((string) $def['price']));
                }
            }
            // Status: neue Produkte nach Wunsch veröffentlichen, zurückgestellte Entwürfe nicht.
            $statusNote = '';
            $publishThis = $publish && ! $isNew && $product->get_status() !== 'publish';
            if ($publishThis && self::heldBack($product) && ! $listed('publish_held', $def['sku'])) {
                $publishThis = false;
                $statusNote = sprintf(__('bleibt %s (von Hand zurückgestellt)', 'bootspruefung-learning'), $product->get_status() === 'draft' ? __('Entwurf', 'bootspruefung-learning') : $product->get_status());
            } elseif ($publishThis || ($isNew && $publish)) {
                $statusNote = __('wird veröffentlicht', 'bootspruefung-learning');
            } elseif ($isNew) {
                $statusNote = __('neu als Entwurf', 'bootspruefung-learning');
            }
            $seats = (int) ($def['seats'] ?? 0);
            $scopes = [];
            foreach (array_unique($def['courses']) as $course) {
                foreach ($def['features'] ?? ProductAccessRepository::features() as $feature) {
                    $scopes[] = ['course_code' => $course, 'feature_code' => $feature];
                }
            }
            // Garantie nur für Einzelzugänge: bei Gruppen lernen die Eingeladenen, nicht der Käufer;
            // Simulator-Zugänge ohne Garantie (Festlegung 06.11.2026).
            $guarantee = $seats === 0 && ($def['guarantee'] ?? true);
            $wantedAccess = self::accessKey(array_map(static fn ($s) => (object) ($s + ['duration_days' => $def['days'], 'renewal_mode' => 'extend', 'refund_rule' => 'revoke_on_full_refund', 'seats' => $seats > 0 ? $seats : null, 'active' => 1]), $scopes));
            $wantedPolicy = self::policyKey($guarantee ? ['days' => self::MONEY_BACK_DAYS, 'max_progress' => self::MONEY_BACK_MAX_PROGRESS] : null);
            $text = self::texts($def);
            $categoryId = $dryRun ? 0 : self::category($def['category'] ?? $def['group']);

            $stored = $product ? $product->get_meta(self::FIELDS_META) : [];
            $hashes = is_array($stored) ? $stored : [];
            // Ältere Abgleiche speicherten nur den Text-Fingerprint (md5 der Texte).
            $legacyText = $product ? (string) $product->get_meta('_bp_catalog_text') : '';
            if ($legacyText !== '' && ! isset($hashes['texts'])) {
                $hashes['texts'] = md5($legacyText);
            }
            $skipped = [];
            if (! $setPrice) {
                $skipped[] = 'price';
            }
            if ($publish && ! $isNew && ! $publishThis && $product->get_status() !== 'publish') {
                $skipped[] = 'status';
            }
            // Feld übernehmen? Neu, schon gleich, oder seit dem letzten Abgleich nicht von Hand geändert.
            $may = static function (string $field, string $current, string $wanted) use ($isNew, &$hashes, &$skipped): bool {
                if ($isNew || $current === $wanted) {
                    return true;
                }
                if (isset($hashes[$field]) && hash_equals((string) $hashes[$field], md5($current))) {
                    return true;
                }
                $skipped[] = $field;

                return false;
            };

            $currentAccess = $product ? self::accessKey($repo->rows($id, 0)) : '';
            $currentPolicy = $product ? self::policyKey(MoneyBack::policy($id)) : '';
            if ($dryRun) {
                if ($product) {
                    $may('name', wp_specialchars_decode($product->get_name()), $def['name']);
                    $may('texts', md5($product->get_short_description().'|'.$product->get_description()), md5($text['short'].'|'.$text['long']));
                    $may('visibility', $product->get_catalog_visibility(), ! empty($def['hidden']) ? 'hidden' : 'visible');
                    $may('sold_individually', (string) (int) $product->get_sold_individually(), (string) (int) ($seats === 0));
                    $may('access', $currentAccess, $wantedAccess);
                    $may('money_back', $currentPolicy, $wantedPolicy);
                }
                $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action.' (Vorschau)', 'skipped' => $skipped, 'price' => $priceNote, 'status' => $statusNote];
                continue;
            }
            $before = $product ? self::fingerprint($product) : '';
            $statusWritten = null;
            if (! $product) {
                $product = new \WC_Product_Simple();
                $product->set_sku($def['sku']);
                $statusWritten = $publish ? 'publish' : 'draft';
                $product->set_status($statusWritten);
            } elseif ($publishThis) {
                $statusWritten = 'publish';
                $product->set_status($statusWritten);
            }
            // Preis: aus Katalog/Preis-Seite (dort ist die Quelle) – außer von Hand im Shop geändert (T98).
            if ($setPrice) {
                $product->set_regular_price($def['price']);
            }
            $product->set_virtual(true);
            $product->set_reviews_allowed(false); // keine Produktbewertungen (leerer Reiter „Bewertungen (0)“)
            $product->set_menu_order((int) $position); // Shop-Reihenfolge wie im Katalog

            $apply = [];
            if ($may('name', wp_specialchars_decode($product->get_name()), $def['name'])) {
                $product->set_name($def['name']);
                $apply[] = 'name';
            }
            // Texte: leere Beschreibung gilt als nicht gepflegt.
            $currentText = md5($product->get_short_description().'|'.$product->get_description());
            if ($product->get_description() === '' || $may('texts', $currentText, md5($text['short'].'|'.$text['long']))) {
                $product->set_short_description($text['short']);
                $product->set_description($text['long']);
                $apply[] = 'texts';
                $skipped = array_values(array_diff($skipped, ['texts']));
            }
            // Gruppenpakete: mehrere Pakete in einer Bestellung möglich (Plätze = Menge × Plätze je Paket).
            if ($may('sold_individually', (string) (int) $product->get_sold_individually(), (string) (int) ($seats === 0))) {
                $product->set_sold_individually($seats === 0);
                $apply[] = 'sold_individually';
            }
            // Upgrade nur im Checkout anbieten, nicht im Shop listen.
            $visibility = ! empty($def['hidden']) ? 'hidden' : 'visible';
            if ($may('visibility', $product->get_catalog_visibility(), $visibility)) {
                $product->set_catalog_visibility($visibility);
                $apply[] = 'visibility';
            }
            // Kategorien: Katalog-Kategorie ergänzen; eigene Kategorien des Betreibers bleiben.
            $currentCats = array_map('intval', $product->get_category_ids());
            sort($currentCats);
            $wantedCats = $categoryId > 0 ? [$categoryId] : [];
            if ($may('categories', implode(',', $currentCats), implode(',', $wantedCats))) {
                // Unverändert seit dem letzten Abgleich: Katalogstand setzen (WooCommerce-Standardkategorie fällt weg).
                $product->set_category_ids($wantedCats ?: $currentCats);
                $apply[] = 'categories';
            } elseif ($categoryId > 0 && ! in_array($categoryId, $currentCats, true)) {
                $product->set_category_ids(array_merge($currentCats, [$categoryId]));
            }
            $image = self::image($def['sku'], (int) $product->get_image_id());
            if ($image > 0) {
                $product->set_image_id($image);
            }
            if ($action !== 'neu') {
                $action = self::fingerprint($product) === $before ? 'unverändert' : 'aktualisiert';
            }
            self::$writing = true;
            try {
                $id = (int) $product->save();
            } finally {
                self::$writing = false;
            }
            if ($setPrice) {
                update_post_meta($id, self::PRICE_META, self::money((string) $def['price']));
            }
            if ($statusWritten !== null) {
                update_post_meta($id, self::STATUS_META, $statusWritten);
            }

            if ($may('access', $currentAccess, $wantedAccess)) {
                $repo->save($id, 0, $scopes, $def['days'], 'extend', 'revoke_on_full_refund', $seats > 0 ? $seats : null);
                $apply[] = 'access';
                if ($action === 'unverändert' && $currentAccess !== $wantedAccess) {
                    $action = 'aktualisiert';
                }
            }
            if ($may('money_back', $currentPolicy, $wantedPolicy)) {
                MoneyBack::setPolicy($id, $guarantee ? self::MONEY_BACK_DAYS : null, $guarantee ? self::MONEY_BACK_MAX_PROGRESS : null);
                $apply[] = 'money_back';
                if ($action === 'unverändert' && $currentPolicy !== $wantedPolicy) {
                    $action = 'aktualisiert';
                }
            }

            // Fingerprints der tatsächlich geschriebenen Werte (nach dem Speichern gelesen).
            $saved = wc_get_product($id);
            $savedCats = array_map('intval', $saved ? $saved->get_category_ids() : []);
            sort($savedCats);
            $now = [
                'name' => $saved ? wp_specialchars_decode($saved->get_name()) : '',
                'texts' => $saved ? md5($saved->get_short_description().'|'.$saved->get_description()) : '',
                'categories' => implode(',', $savedCats),
                'visibility' => $saved ? $saved->get_catalog_visibility() : '',
                'sold_individually' => $saved ? (string) (int) $saved->get_sold_individually() : '',
                'access' => self::accessKey($repo->rows($id, 0)),
                'money_back' => self::policyKey(MoneyBack::policy($id)),
            ];
            foreach ($apply as $field) {
                $hashes[$field] = md5($now[$field]);
            }
            update_post_meta($id, self::FIELDS_META, array_intersect_key($hashes, array_flip(self::MANAGED_FIELDS)));
            if (in_array('texts', $apply, true)) {
                update_post_meta($id, '_bp_catalog_text', md5($text['short'].'|'.$text['long']));
            }
            $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action, 'skipped' => $skipped, 'price' => $priceNote, 'status' => $statusNote];
        }
        // Deaktivierte Kurse: vorhandene Produkte auf Entwurf (nicht löschen – Bestellungen verweisen darauf),
        // nie veröffentlichen – auch nicht mit „veröffentlichen“.
        foreach (self::disabledSkus() as $sku) {
            $id = (int) wc_get_product_id_by_sku($sku);
            $product = $id ? wc_get_product($id) : null;
            if (! $product) {
                continue;
            }
            if ($product->get_status() === 'draft') {
                if ($publish) {
                    $report[] = ['sku' => $sku, 'product_id' => $id, 'action' => $dryRun ? 'unverändert (Vorschau)' : 'unverändert', 'skipped' => [], 'price' => '', 'status' => __('bleibt Entwurf (Kurs deaktiviert)', 'bootspruefung-learning')];
                }
                continue;
            }
            if (! $dryRun) {
                self::$writing = true;
                try {
                    $product->set_status('draft');
                    $product->save();
                } finally {
                    self::$writing = false;
                }
                update_post_meta($id, self::STATUS_META, 'draft');
            }
            $report[] = ['sku' => $sku, 'product_id' => $id, 'action' => $dryRun ? 'deaktiviert (Vorschau)' : 'deaktiviert (Entwurf)', 'skipped' => [], 'price' => '', 'status' => __('wird Entwurf (Kurs deaktiviert)', 'bootspruefung-learning')];
        }
        if (! $dryRun) {
            $skippedTotal = array_filter(array_column($report, 'skipped', 'sku'));
            AuditLog::record('shop_catalog_applied', 'settings', null, null, [
                'products' => count($report), 'publish' => $publish, 'skipped' => $skippedTotal,
                'prices_kept' => array_keys(array_filter(array_column($report, 'skipped', 'sku'), static fn ($s) => in_array('price', (array) $s, true))),
                'prices_overwritten' => $options['overwrite_prices'] ?? [],
            ]);
        }

        return $report;
    }

    /**
     * Produkte, deren (neuer) regulärer Preis nicht über dem Angebotspreis im Shop liegt.
     * WooCommerce entfernt den Angebotspreis beim Speichern in diesem Fall.
     * @return array<string,string> SKU => Angebotspreis
     */
    public static function saleConflicts(): array
    {
        if (! function_exists('wc_get_product_id_by_sku')) {
            return [];
        }
        $out = [];
        foreach (self::products() as $def) {
            $id = (int) wc_get_product_id_by_sku($def['sku']);
            $product = $id ? wc_get_product($id) : null;
            $sale = $product ? (string) $product->get_sale_price('edit') : '';
            if ($sale !== '' && (float) $def['price'] <= (float) $sale) {
                $out[$def['sku']] = $sale;
            }
        }

        return $out;
    }

    /**
     * Bestehende Produkte, die ein Abgleich verändern würde (für Rückfragen).
     * @param list<array{sku:string,product_id:int,action:string,skipped:list<string>}> $preview Ergebnis von applyProducts(true)
     */
    public static function existingCount(array $preview): int
    {
        return count(array_filter($preview, static fn ($r) => (int) $r['product_id'] > 0));
    }

    /** Vergleichbarer Schlüssel einer Zugangszuordnung. @param list<object> $rows */
    private static function accessKey(array $rows): string
    {
        $parts = [];
        foreach ($rows as $r) {
            $parts[] = implode('|', [
                (string) $r->course_code,
                (string) $r->feature_code,
                (int) ($r->duration_days ?? 0) > 0 ? (string) (int) $r->duration_days : '',
                (string) ($r->renewal_mode ?? 'extend'),
                (string) ($r->refund_rule ?? 'revoke_on_full_refund'),
                (int) ($r->seats ?? 0) > 0 ? (string) (int) $r->seats : '',
                (string) (int) ($r->active ?? 1),
            ]);
        }
        sort($parts);

        return implode(';', $parts);
    }

    /** @param array{days:int,max_progress:float}|null $policy */
    private static function policyKey(?array $policy): string
    {
        return $policy === null ? '' : (int) $policy['days'].'|'.number_format((float) $policy['max_progress'], 2, '.', '');
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

    /** Transient mit den Kennzahlen je Kursmenge (T85). */
    public const STATS_TRANSIENT = 'bp_learning_course_stats';

    /** @var array<string,array{questions:int,nav:int,praxis:int,videos:int,papers:int}> */
    private static array $statsMemo = [];

    /**
     * Kennzahlen über eine Kursmenge (Fragen als Vereinigung, Rest summiert).
     *
     * Zwischengespeichert (T85): je Anfrage im Speicher und anfrageübergreifend im Transient STATS_TRANSIENT
     * (ein Optionswert statt fünf Zählabfragen je Kurskarte). Der Schlüssel enthält das aktive Inhaltspaket;
     * Import, Paketwechsel und Rechte-Änderungen (ContentCache::invalidate()) sowie Produktänderungen leeren ihn
     * (flushCourseStats()).
     *
     * @param list<string> $courses
     * @return array{questions:int,nav:int,praxis:int,videos:int,papers:int}
     */
    public static function courseStats(array $courses): array
    {
        $courses = array_values(array_unique($courses));
        if ($courses === []) {
            return ['questions' => 0, 'nav' => 0, 'praxis' => 0, 'videos' => 0, 'papers' => 0];
        }
        $key = implode(',', $courses);
        if (isset(self::$statsMemo[$key])) {
            return self::$statsMemo[$key];
        }
        $stamp = (string) get_option('bp_learning_active_package', '');
        $stored = get_transient(self::STATS_TRANSIENT);
        $stored = is_array($stored) && ($stored['stamp'] ?? null) === $stamp && is_array($stored['stats'] ?? null) ? $stored : ['stamp' => $stamp, 'stats' => []];
        if (is_array($stored['stats'][$key] ?? null)) {
            return self::$statsMemo[$key] = $stored['stats'][$key];
        }
        $stats = self::countCourseStats($courses);
        $stored['stats'][$key] = $stats;
        set_transient(self::STATS_TRANSIENT, $stored, DAY_IN_SECONDS);

        return self::$statsMemo[$key] = $stats;
    }

    /** Zwischengespeicherte Kennzahlen verwerfen (Import, Paketwechsel, Rechte-Änderung, Produktänderung). */
    public static function flushCourseStats(): void
    {
        self::$statsMemo = [];
        delete_transient(self::STATS_TRANSIENT);
    }

    /**
     * @param list<string> $courses
     * @return array{questions:int,nav:int,praxis:int,videos:int,papers:int}
     */
    private static function countCourseStats(array $courses): array
    {
        global $wpdb;
        $p = $wpdb->prefix.'bp_';
        $in = implode(',', array_fill(0, count($courses), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter dynamisch
        $count = static fn (string $sql): int => (int) $wpdb->get_var($wpdb->prepare($sql, ...$courses));

        return [
            // Nur veröffentlichte Fragen (wie im Lernbereich), keine Entwürfe/Archivierten.
            'questions' => $count("SELECT COUNT(DISTINCT mq.question_id) FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id JOIN {$p}question q ON q.id = mq.question_id AND q.status = 'published' WHERE c.code IN ({$in})"),
            'nav' => $count("SELECT COUNT(DISTINCT x.task_id) FROM {$p}navigation_task_course x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
            'praxis' => $count("SELECT COUNT(*) FROM {$p}praxis_task x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
            'videos' => $count("SELECT COUNT(*) FROM {$p}video_lesson vl JOIN {$p}video_module vm ON vm.id = vl.module_id JOIN {$p}course c ON c.id = vm.course_id WHERE c.code IN ({$in})"),
            'papers' => $count("SELECT COUNT(*) FROM {$p}exam_paper x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})"),
        ];
        // phpcs:enable
    }

    /**
     * Produkttexte aus dem tatsächlichen Inhalt der Kurse (Zahlen aus der Datenbank).
     *
     * Übersetzbar (T128): alle Sätze über __() mit Platzhaltern; Beträge über priceText() im Format des Shops.
     *
     * @param array{sku:string,name:string,days:int,courses:list<string>,group:string} $def
     * @return array{short:string,long:string}
     */
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
        $months = self::termLabel((int) $def['days'], 180, __('6 Monate', 'bootspruefung-learning'));
        $names = array_map([Labels::class, 'course'], $all);
        // Kursname ohne „Vollkurs“/„Kombikurs“ (z. B. „SBF See“).
        $shortName = trim((string) preg_replace('/\s+(Vollkurs|Kombikurs)$/u', '', $names[0]));

        $seats = (int) ($def['seats'] ?? 0);
        $intro = match ($def['group']) {
            /* translators: 1: number of seats, 2: course name */
            'groups' => sprintf(__('%1$d Lernzugänge für %2$s – zum Beispiel für Paare, Familien, Crews oder Vereine, die gemeinsam auf die Prüfung lernen.', 'bootspruefung-learning'), $seats, (string) preg_replace('/\s+(Vollkurs|Kombikurs)$/u', '', $names[0])),
            'bundle' => str_starts_with($def['sku'], 'BP-PASS')
                /* translators: %s: term, e.g. "12 Monate" */
                ? sprintf(__('Voller Zugriff auf alle Kurse der Plattform für %s – ideal, wenn du konzentriert auf einen Prüfungstermin hinlernst oder mehrere Scheine kombinierst.', 'bootspruefung-learning'), $months)
                : __('Alle wichtigen Scheine für Küste und Binnen in einem Paket: Sportbootführerschein See und Binnen plus die Funkzeugnisse SRC und UBI.', 'bootspruefung-learning'),
            // FKN → SKN: keine belegte verkürzte Prüfung; der Kurs enthält den vollständigen SKN-Katalog (T52/LIVE-07).
            'addon' => $all === ['ADD-FKN-TO-SKN']
                ? __('Du hast schon den Fachkundenachweis Seenotsignalmittel (FKN) und willst den SKN? Dieser Kurs enthält den vollständigen SKN-Fragenkatalog mit Prüfungssimulation. Welche Prüfungsteile dir angerechnet werden, sagt dir dein Prüfungsausschuss.', 'bootspruefung-learning')
                /* translators: %s: name of the supplementary part, e.g. "SBF Binnen Segeln" */
                : sprintf(__('Du hast bereits einen Schein und brauchst nur noch den Ergänzungsteil (%s)? Dieser Kurs bereitet dich gezielt auf die Ergänzungsprüfung vor.', 'bootspruefung-learning'), preg_replace('/^Ergänzung\s+/u', '', $names[0])),
            /* translators: %s: course name */
            default => sprintf(__('Online-Vorbereitung auf die Prüfung %s: lernen, üben und Prüfungen simulieren – am Rechner, Tablet oder Smartphone.', 'bootspruefung-learning'), $shortName),
        };
        $items = [];
        if ($questions > 0) {
            /* translators: %d: number of exam questions */
            $items[] = sprintf(__('%d Prüfungsfragen im Smarttrainer mit Erklärungen, Wiederholung falsch beantworteter Fragen und Favoriten', 'bootspruefung-learning'), $questions);
        }
        $items[] = $papers > 0
            /* translators: %d: number of exam papers */
            ? sprintf(__('Prüfungssimulation mit Zeitlimit und Auswertung, inkl. %d Prüfungsbögen', 'bootspruefung-learning'), $papers)
            : __('Prüfungssimulation mit Zeitlimit und Auswertung', 'bootspruefung-learning');
        if ($nav > 0) {
            /* translators: %d: number of navigation tasks */
            $items[] = sprintf(__('%d Navigationsaufgaben mit Musterlösungen', 'bootspruefung-learning'), $nav);
        }
        if ($praxis > 0) {
            /* translators: %d: number of practice cards */
            $items[] = sprintf(__('%d Praxiskarten zur Vorbereitung auf die praktische Prüfung', 'bootspruefung-learning'), $praxis);
        }
        $withVideo = in_array(AccessService::VIDEO, $def['features'] ?? [AccessService::VIDEO], true);
        if ($videos > 0 && $withVideo) {
            /* translators: %d: number of video lessons */
            $items[] = sprintf(__('Videokurs mit %d Lektionen', 'bootspruefung-learning'), $videos);
        } elseif ($videos > 0 && ($def['tier'] ?? '') === 'theorie') {
            $upgradePrice = (string) (self::find(self::videoSku($def['sku']))['price'] ?? self::VIDEO_TIERS[$def['sku']] ?? 0);
            /* translators: 1: number of video lessons, 2: price with currency, e.g. "11,90 €" */
            $items[] = sprintf(__('Videokurs (%1$d Lektionen) nicht enthalten – als Upgrade für %2$s erhältlich, erste Lektion kostenlos', 'bootspruefung-learning'), $videos, self::priceText($upgradePrice));
        }
        $withSim = in_array(AccessService::DEVICE, $def['features'] ?? [AccessService::DEVICE], true);
        if ($radio && $seats > 0 && ! $withSim) {
            $items[] = __('Funktraining: Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien; Funkgerät-Simulator nicht enthalten (für jede Person als Simulator-Zugang erhältlich)', 'bootspruefung-learning');
        } elseif ($radio) {
            $items[] = $withSim
                ? __('Funktraining: Funktexte, Vokabeln, Buchstabiertafel, Praxisszenarien und Funkgerät-Simulator', 'bootspruefung-learning')
                : __('Funktraining: Funktexte, Vokabeln, Buchstabiertafel und Praxisszenarien; Funkgerät-Simulator als kostenlose Testversion (Vollversion als Upgrade)', 'bootspruefung-learning');
        }
        if ($pass) {
            /* translators: %d: number of courses */
            $items[] = sprintf(__('Alle %d Kurse: Sportbootführerscheine, Funkzeugnisse, Seenotsignalmittel und Ergänzungskurse – mit allen Trainern und Videos', 'bootspruefung-learning'), count($all));
        }
        $items[] = __('Lernfortschritt wird gespeichert – überall weiterlernen', 'bootspruefung-learning');
        if ($seats > 0) {
            /* translators: %d: number of seats */
            array_unshift($items, sprintf(__('%d Plätze: Du lädst die Teilnehmenden per Link oder E-Mail ein; jede Person lernt mit eigenem Konto und eigenem Fortschritt. Ein Platz kann auch für dich selbst sein.', 'bootspruefung-learning'), $seats));
        }
        /* translators: 1: term, e.g. "12 Monate", 2: name of the learning area */
        $access = sprintf(__('Zugang %1$s ab Zahlungseingang – freigeschaltet, sobald die Zahlung eingegangen ist. Du lernst im Bereich „%2$s“; deine Zugänge findest du unter „Mein Lernen“ im Kundenkonto.', 'bootspruefung-learning'), $months, \Bootspruefung\Learning\Settings\Settings::brand());
        $long = '<p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $items)).'</li></ul>'
            .'<p>'.esc_html($access).'</p>'
            .($seats > 0
                ? '<p>'.esc_html__('Die Gruppe verwaltest du unter „Meine Gruppen“ im Lernbereich. Der Zugang gilt für alle Teilnehmenden bis zum Ende der Laufzeit ab Zahlungseingang. Für das gesetzliche Widerrufsrecht gilt die Widerrufsbelehrung.', 'bootspruefung-learning').'</p>'
                : self::guaranteeParagraph())
            /* translators: %s: comma-separated list of course names */
            .(count($all) > 1 && ! $pass ? '<p>'.esc_html(sprintf(__('Enthält: %s', 'bootspruefung-learning'), implode(', ', $names))).'</p>' : '');
        if ($seats > 0) {
            $short = sprintf(
                /* translators: 1: number of seats, 2: "N Prüfungsfragen je Person" or "Alle Inhalte", 3: term, e.g. "12 Monate" */
                __('%1$d Lernzugänge · %2$s · Zugang %3$s', 'bootspruefung-learning'),
                $seats,
                /* translators: %d: number of exam questions */
                $questions > 0 ? sprintf(__('%d Prüfungsfragen je Person', 'bootspruefung-learning'), $questions) : __('Alle Inhalte', 'bootspruefung-learning'),
                $months
            );
        } else {
            $short = sprintf(
                /* translators: 1: "N Prüfungsfragen" or "Alle Inhalte", 2: term, e.g. "12 Monate", 3: days of the money-back guarantee */
                __('%1$s · Prüfungssimulation · Zugang %2$s · %3$d Tage Geld-zurück-Garantie', 'bootspruefung-learning'),
                /* translators: %d: number of exam questions */
                $questions > 0 ? sprintf(__('%d Prüfungsfragen', 'bootspruefung-learning'), $questions) : __('Alle Inhalte', 'bootspruefung-learning'),
                $months,
                self::MONEY_BACK_DAYS
            );
        }

        return ['short' => esc_html($short), 'long' => $long];
    }

    /**
     * Betrag mit Währung im Format des Shops (WooCommerce: Währungszeichen, Position, Trennzeichen, Nachkommastellen),
     * als Text ohne HTML; ohne WooCommerce „11,90 €“ nach Sprache der Website (T128). Geschützte Leerzeichen werden zu
     * normalen, damit die Produkttexte bei deutschem Shop-Format unverändert bleiben (kein Neuschreiben beim Abgleich).
     */
    public static function priceText(float|int|string $amount): string
    {
        $amount = (float) $amount;
        if (function_exists('wc_price')) {
            $text = html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $text));
        }

        /* translators: %s: amount, e.g. "11,90" */
        return sprintf(__('%s €', 'bootspruefung-learning'), number_format_i18n($amount, 2));
    }

    /** Laufzeit als Text: „12 Monate“ ab 360 Tagen, $mid ab $midDays, sonst „N Tage“. */
    private static function termLabel(int $days, int $midDays, string $mid): string
    {
        if ($days >= 360) {
            return __('12 Monate', 'bootspruefung-learning');
        }
        if ($days >= $midDays) {
            return $mid;
        }

        /* translators: %d: number of days */
        return sprintf(__('%d Tage', 'bootspruefung-learning'), $days);
    }

    /** Garantie-Absatz der Produkttexte (Bedingungen wie in den AGB, Abschnitt „Geld-zurück-Garantie“). */
    private static function guaranteeParagraph(): string
    {
        $days = self::MONEY_BACK_DAYS;
        $max = (int) self::MONEY_BACK_MAX_PROGRESS;

        /* translators: 1: days, 2: maximum usage in percent */
        return '<p><strong>'.esc_html(sprintf(__('%1$d Tage Geld-zurück-Garantie (weniger als %2$d %% genutzt):', 'bootspruefung-learning'), $days, $max)).'</strong> '
            /* translators: 1: days, 2: maximum usage in percent */
            .esc_html(sprintf(__('Innerhalb von %1$d Tagen ab Zahlungseingang erstatten wir dir den Preis, wenn du weniger als %2$d %% der gekauften Inhalte genutzt hast (beantwortete Fragen bzw. abgeschlossene Video-Lektionen). Anfrage unter „Mein Lernen“. Die Garantie gilt zusätzlich zum gesetzlichen Widerrufsrecht (siehe Widerrufsbelehrung).', 'bootspruefung-learning'), $days, $max))
            .' <a href="'.esc_url(home_url('/agb/#geld-zurueck-garantie')).'">'.esc_html__('Details in den AGB', 'bootspruefung-learning').'</a></p>';
    }

    /** Texte des Video-Upgrades (nur zusammen mit der Theorie-Stufe). @return array{short:string,long:string} */
    private static function videoUpgradeTexts(array $def): array
    {
        $course = Labels::course($def['courses'][0]);
        $videos = self::courseStats([$def['courses'][0]])['videos'];
        $intro = $videos > 0
            /* translators: 1: course name, 2: number of video lessons */
            ? sprintf(__('Erweitere deinen Theoriekurs %1$s um den Videokurs: Der gesamte Prüfungsstoff Kapitel für Kapitel erklärt – %2$d Lektionen.', 'bootspruefung-learning'), $course, $videos)
            /* translators: %s: course name */
            : sprintf(__('Erweitere deinen Theoriekurs %s um den Videokurs: Der gesamte Prüfungsstoff Kapitel für Kapitel erklärt.', 'bootspruefung-learning'), $course);
        $long = '<p>'.esc_html($intro).'</p>'
            /* translators: %s: course name */
            .'<p>'.esc_html(sprintf(__('Nur zusammen mit dem Theoriekurs %s erhältlich (im Warenkorb oder bereits gekauft). Der Videokurs läuft so lange wie dein Theoriezugang zu diesem Kurs.', 'bootspruefung-learning'), $course)).'</p>'
            .self::guaranteeParagraph();

        /* translators: 1: course name, 2: days of the money-back guarantee */
        return ['short' => esc_html(sprintf(__('Videokurs zum Theoriekurs %1$s · Laufzeit wie dein Theoriezugang · %2$d Tage Geld-zurück-Garantie', 'bootspruefung-learning'), $course, self::MONEY_BACK_DAYS)), 'long' => $long];
    }

    /** Web-App-Vorteile des Simulators (ohne Vergleich mit Anbietern, Festlegung 06.11.2026). @return list<string> */
    public static function simBenefits(): array
    {
        return [
            __('Ein Login für alle Geräte: Übe am PC, auf dem Tablet oder auf dem Smartphone.', 'bootspruefung-learning'),
            __('Keine Installation: läuft direkt im Browser auf Mac, Windows, iOS und Android.', 'bootspruefung-learning'),
            __('Keine Updates nötig: Aktualisierungen kommen automatisch.', 'bootspruefung-learning'),
        ];
    }

    /** @param array<string,mixed> $def @return array{short:string,long:string} */
    private static function simTexts(array $def): array
    {
        $intro = match ($def['sku']) {
            'SIM-PASS-14D' => __('Voller Web-Zugriff auf alle Funkgeräte-Simulationen – für die letzten Tage vor der praktischen Prüfung. Läuft nach 14 Tagen automatisch aus.', 'bootspruefung-learning'),
            'SIM-PASS-3M' => __('Der Standard-Zugang für die reguläre Kurszeit: alle Funkgeräte-Simulationen für drei Monate.', 'bootspruefung-learning'),
            'SIM-PASS-12M' => __('Für alle, die sich maximal Zeit lassen wollen oder vor der Prüfung immer wieder üben möchten: alle Funkgeräte-Simulationen für zwölf Monate. Persönlicher Zugang für eine Person.', 'bootspruefung-learning'),
            default => __('Zusatzoption beim Kauf eines Funkkurses: schaltet den Funkgerät-Simulator frei, solange ein Funkkurs (SRC oder UBI) aktiv ist, höchstens 12 Monate.', 'bootspruefung-learning'),
        };
        $sim = self::simStats();
        $items = [
            /* translators: 1: number of lessons, 2: number of radio devices */
            sprintf(__('%1$d Lektionen an %2$d Funkgeräten: Bedienung, Routineverkehr, DSC, Not-, Dringlichkeits- und Sicherheitsverkehr', 'bootspruefung-learning'), $sim['lessons'], $sim['devices']),
            __('Notalarm (DISTRESS) mit DSC-Controller und Notmeldung per Sprechfunk', 'bootspruefung-learning'),
            /* translators: 1: number of exam tasks, 2: number of exam series */
            sprintf(__('Prüfungsmodus: %1$d Prüfungsaufgaben in %2$d Prüfungsreihen wie in der praktischen SRC- und UBI-Prüfung', 'bootspruefung-learning'), $sim['tasks'], $sim['series']),
            __('Freies Üben: Rufe empfangen und beantworten', 'bootspruefung-learning'),
        ];
        $months = self::termLabel((int) $def['days'], 91, __('3 Monate', 'bootspruefung-learning'));
        // Upgrade: ruht, sobald kein Funkkurs (SRC/UBI) mehr aktiv ist (AccessService::entitledFeatures).
        $upgrade = $def['sku'] === self::SIM_UPGRADE;
        $term = $upgrade
            ? __('Zugang, solange ein Funkkurs (SRC oder UBI) aktiv ist, höchstens 12 Monate ab Zahlungseingang', 'bootspruefung-learning')
            /* translators: %s: term, e.g. "3 Monate" */
            : sprintf(__('Zugang %s ab Zahlungseingang', 'bootspruefung-learning'), $months);
        $long = '<p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $items)).'</li></ul>'
            .'<p><strong>'.esc_html__('Läuft direkt im Browser', 'bootspruefung-learning').'</strong></p><ul><li>'.implode('</li><li>', array_map('esc_html', self::simBenefits())).'</li></ul>'
            /* translators: %s: access term, e.g. "Zugang 3 Monate ab Zahlungseingang" */
            .'<p>'.esc_html(sprintf(__('%s, Einmalzahlung ohne Abo – endet automatisch. Gilt für die Funkkurse SRC und UBI. Für diesen Zugang gibt es keine Geld-zurück-Garantie; für das gesetzliche Widerrufsrecht gilt die Widerrufsbelehrung.', 'bootspruefung-learning'), $term)).'</p>';
        $short = $upgrade
            ? __('Funkgerät-Simulator · alle Lektionen und Prüfungsaufgaben · solange ein Funkkurs (SRC oder UBI) aktiv ist, höchstens 12 Monate · kein Abo', 'bootspruefung-learning')
            /* translators: %s: term, e.g. "3 Monate" */
            : sprintf(__('Funkgerät-Simulator · alle Lektionen und Prüfungsaufgaben · Zugang %s · kein Abo', 'bootspruefung-learning'), $months);

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
