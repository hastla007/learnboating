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

    /** @return list<array{sku:string,name:string,price:string,days:int,courses:list<string>}> */
    public static function products(): array
    {
        $list = [
            ['sku' => 'BP-SBF-SEE-BIN', 'name' => 'SBF See & Binnen (Kombi)', 'price' => '48.90', 'days' => 365, 'courses' => self::SBF_ALL],
            ['sku' => 'BP-SBF-SEE', 'name' => 'SBF See (Einzelkurs)', 'price' => '28.90', 'days' => 365, 'courses' => ['SBF-SEE']],
            ['sku' => 'BP-SBF-BIN', 'name' => 'SBF Binnen (Einzelkurs)', 'price' => '27.80', 'days' => 365, 'courses' => ['SBF-BIN-MOTOR']],
            ['sku' => 'BP-SRC-UBI', 'name' => 'SRC & UBI Kombikurs (Funk)', 'price' => '38.90', 'days' => 365, 'courses' => self::RADIO_ALL],
            ['sku' => 'BP-SRC', 'name' => 'SRC Vollkurs', 'price' => '23.90', 'days' => 365, 'courses' => ['SRC']],
            ['sku' => 'BP-UBI', 'name' => 'UBI Vollkurs', 'price' => '23.90', 'days' => 365, 'courses' => ['UBI']],
            ['sku' => 'BP-SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'price' => '18.90', 'days' => 365, 'courses' => ['SBF-BIN-SEGEL']],
            ['sku' => 'BP-SKN', 'name' => 'SKN Vollkurs', 'price' => '13.90', 'days' => 182, 'courses' => ['SKN']],
            ['sku' => 'BP-FKN', 'name' => 'FKN Vollkurs', 'price' => '13.90', 'days' => 182, 'courses' => ['FKN']],
        ];
        $names = Labels::courses();
        foreach (self::ADDONS as $code) {
            $list[] = ['sku' => 'BP-'.$code, 'name' => $names[$code] ?? $code, 'price' => '13.90', 'days' => 182, 'courses' => [$code]];
        }
        $list[] = ['sku' => 'BP-KAPITAEN', 'name' => 'Kapitäns-Bundle (SBF See + Binnen + Funk)', 'price' => '68.90', 'days' => 365, 'courses' => [...self::SBF_ALL, ...self::RADIO_ALL]];
        $all = array_keys($names);
        $list[] = ['sku' => 'BP-PASS-1M', 'name' => '1-Monats-Pass (alle Inhalte)', 'price' => '13.90', 'days' => 30, 'courses' => $all];
        $list[] = ['sku' => 'BP-PASS-3M', 'name' => '3-Monats-Pass (alle Inhalte)', 'price' => '28.90', 'days' => 90, 'courses' => $all];

        return $list;
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
        foreach (self::products() as $def) {
            $id = (int) wc_get_product_id_by_sku($def['sku']);
            $product = $id ? wc_get_product($id) : null;
            $action = $product ? 'aktualisiert' : 'neu';
            if ($product && $product->get_regular_price() === $def['price'] && wp_specialchars_decode($product->get_name()) === $def['name']) {
                $action = 'unverändert';
            }
            if ($dryRun) {
                $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action.' (Vorschau)'];
                continue;
            }
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
            $product->set_sold_individually(true);
            $id = (int) $product->save();
            $scopes = [];
            foreach (array_unique($def['courses']) as $course) {
                foreach (ProductAccessRepository::features() as $feature) {
                    $scopes[] = ['course_code' => $course, 'feature_code' => $feature];
                }
            }
            $repo->save($id, 0, $scopes, $def['days'], 'extend', 'revoke_on_full_refund');
            MoneyBack::setPolicy($id, self::MONEY_BACK_DAYS, self::MONEY_BACK_MAX_PROGRESS);
            $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action];
        }
        if (! $dryRun) {
            AuditLog::record('shop_catalog_applied', 'settings', null, null, ['products' => count($report), 'publish' => $publish]);
        }

        return $report;
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
