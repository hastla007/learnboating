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

    /** @return list<array{sku:string,name:string,price:string,days:int,courses:list<string>,group:string,seats?:int}> */
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
        $groups = ['BP-SBF-SEE-BIN' => 'sbf', 'BP-SBF-SEE' => 'sbf', 'BP-SBF-BIN' => 'sbf', 'BP-SBF-BIN-SEGEL' => 'sbf', 'BP-SRC-UBI' => 'funk', 'BP-SRC' => 'funk', 'BP-UBI' => 'funk', 'BP-SKN' => 'signal', 'BP-FKN' => 'signal'];
        foreach ($list as $i => $item) {
            $list[$i]['group'] = $groups[$item['sku']];
        }
        $names = Labels::courses();
        foreach (self::ADDONS as $code) {
            $list[] = ['sku' => 'BP-'.$code, 'name' => $names[$code] ?? $code, 'price' => '13.90', 'days' => 182, 'courses' => [$code], 'group' => 'addon'];
        }
        $list[] = ['sku' => 'BP-KAPITAEN', 'name' => 'Kapitäns-Bundle (SBF See + Binnen + Funk)', 'price' => '68.90', 'days' => 365, 'courses' => [...self::SBF_ALL, ...self::RADIO_ALL], 'group' => 'bundle'];
        $all = array_keys($names);
        $list[] = ['sku' => 'BP-PASS-1M', 'name' => '1-Monats-Pass (alle Inhalte)', 'price' => '13.90', 'days' => 30, 'courses' => $all, 'group' => 'bundle'];
        $list[] = ['sku' => 'BP-PASS-3M', 'name' => '3-Monats-Pass (alle Inhalte)', 'price' => '28.90', 'days' => 90, 'courses' => $all, 'group' => 'bundle'];
        // Gruppenpakete (Festlegung 06.11.2026): Käufer erhält ein Kontingent und lädt Mitlernende ein.
        $see = ['SBF-SEE'];
        $bin = ['SBF-BIN-MOTOR'];
        foreach ([
            ['GRP-PAIR-01', 'Crew-Ticket: SBF See (2 Pers.)', 2, $see, '49.90'],
            ['GRP-PAIR-02', 'Crew-Ticket: SBF Binnen (2 Pers.)', 2, $bin, '47.90'],
            ['GRP-PAIR-03', 'Crew-Ticket: Kombi See/Binnen (2 Pers.)', 2, self::SBF_ALL, '84.90'],
            ['GRP-PAIR-04', 'Crew-Ticket: Funk SRC/UBI (2 Pers.)', 2, self::RADIO_ALL, '66.90'],
            ['GRP-FAM-01', 'Familien-Flotte: SBF See (3 Pers.)', 3, $see, '69.90'],
            ['GRP-FAM-02', 'Familien-Flotte: Kombi See/Binnen (3 Pers.)', 3, self::SBF_ALL, '119.90'],
            ['GRP-FAM-03', 'Familien-Flotte: Kombi See/Binnen (4 Pers.)', 4, self::SBF_ALL, '149.90'],
            ['GRP-CREW-01', 'Skipper-Crew 5: SBF See', 5, $see, '109.90'],
            ['GRP-CREW-02', 'Skipper-Crew 5: Kombi See/Binnen', 5, self::SBF_ALL, '189.90'],
            ['GRP-CREW-03', 'Skipper-Crew 5: Funk SRC/UBI', 5, self::RADIO_ALL, '149.90'],
            ['GRP-CREW-04', 'Yacht-Crew 7: SBF See', 7, $see, '149.90'],
            ['GRP-CREW-05', 'Yacht-Crew 7: Kombi See/Binnen', 7, self::SBF_ALL, '249.90'],
            ['GRP-CLUB-10', 'Flottille 10: SBF See', 10, $see, '199.90'],
            ['GRP-CLUB-11', 'Flottille 10: Kombi See/Binnen', 10, self::SBF_ALL, '329.90'],
            ['GRP-CLUB-20', 'Vereins-Paket 20: SBF See', 20, $see, '369.90'],
            ['GRP-CLUB-21', 'Vereins-Paket 20: Kombi See/Binnen', 20, self::SBF_ALL, '599.90'],
            ['GRP-CLUB-50', 'Partner-Flat 50: Kombi See/Binnen', 50, self::SBF_ALL, '1249.00'],
        ] as [$sku, $name, $seats, $courses, $price]) {
            $list[] = ['sku' => $sku, 'name' => $name, 'price' => $price, 'days' => 365, 'courses' => $courses, 'group' => 'groups', 'seats' => $seats];
        }

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
            $seats = (int) ($def['seats'] ?? 0);
            // Gruppenpakete: mehrere Pakete in einer Bestellung möglich (Plätze = Menge × Plätze je Paket).
            $product->set_sold_individually($seats === 0);
            $product->set_catalog_visibility('visible');
            $text = self::texts($def);
            // Texte nur setzen, solange sie nicht im Shop von Hand geändert wurden.
            $ownHash = (string) $product->get_meta('_bp_catalog_text');
            $currentHash = md5($product->get_short_description().'|'.$product->get_description());
            if ($product->get_description() === '' || $ownHash === $currentHash) {
                $product->set_short_description($text['short']);
                $product->set_description($text['long']);
                $product->update_meta_data('_bp_catalog_text', md5($text['short'].'|'.$text['long']));
            }
            $product->set_category_ids([self::category($def['group'])]);
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
                foreach (ProductAccessRepository::features() as $feature) {
                    $scopes[] = ['course_code' => $course, 'feature_code' => $feature];
                }
            }
            $repo->save($id, 0, $scopes, $def['days'], 'extend', 'revoke_on_full_refund', $seats > 0 ? $seats : null);
            // Garantie nur für Einzelzugänge: bei Gruppen lernen die Eingeladenen, nicht der Käufer.
            MoneyBack::setPolicy($id, $seats > 0 ? null : self::MONEY_BACK_DAYS, $seats > 0 ? null : self::MONEY_BACK_MAX_PROGRESS);
            $report[] = ['sku' => $def['sku'], 'product_id' => $id, 'action' => $action];
        }
        if (! $dryRun) {
            AuditLog::record('shop_catalog_applied', 'settings', null, null, ['products' => count($report), 'publish' => $publish]);
        }

        return $report;
    }

    private static function fingerprint(\WC_Product $product): string
    {
        return md5(implode('|', [wp_specialchars_decode($product->get_name()), $product->get_regular_price(), $product->get_status(), $product->get_short_description(), $product->get_description(), implode(',', $product->get_category_ids()), $product->get_image_id()]));
    }

    private const CATEGORIES = [
        'sbf' => 'Sportbootführerscheine',
        'funk' => 'Funkzeugnisse',
        'signal' => 'Seenotsignalmittel',
        'addon' => 'Ergänzungskurse',
        'bundle' => 'Pakete & Pässe',
        'groups' => 'Gruppen & Vereine',
    ];

    private static function category(string $group): int
    {
        $name = self::CATEGORIES[$group] ?? self::CATEGORIES['bundle'];
        $term = get_term_by('name', $name, 'product_cat');
        if ($term instanceof \WP_Term) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, 'product_cat');

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
    public static function texts(array $def): array
    {
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
        $in = implode(',', array_fill(0, count($courses), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter dynamisch
        $count = static fn (string $sql): int => (int) $wpdb->get_var($wpdb->prepare($sql, ...$courses));
        $questions = $count("SELECT COUNT(DISTINCT mq.question_id) FROM {$p}course c JOIN {$p}course_module cm ON cm.course_id = c.id JOIN {$p}module_question mq ON mq.module_id = cm.module_id WHERE c.code IN ({$in})");
        $nav = $count("SELECT COUNT(DISTINCT x.task_id) FROM {$p}navigation_task_course x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})");
        $praxis = $count("SELECT COUNT(*) FROM {$p}praxis_task x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})");
        $videos = $count("SELECT COUNT(*) FROM {$p}video_lesson vl JOIN {$p}video_module vm ON vm.id = vl.module_id JOIN {$p}course c ON c.id = vm.course_id WHERE c.code IN ({$in})");
        $papers = $count("SELECT COUNT(*) FROM {$p}exam_paper x JOIN {$p}course c ON c.id = x.course_id WHERE c.code IN ({$in})");
        // phpcs:enable
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
            'addon' => sprintf('Du hast bereits einen Schein und brauchst nur noch den Ergänzungsteil (%s)? Dieser Kurs enthält genau die zusätzlichen Inhalte.', preg_replace('/^Ergänzung\s+/u', '', $names[0])),
            default => sprintf('Online-Vorbereitung auf die Prüfung %s: lernen, üben und Prüfungen simulieren – am Rechner, Tablet oder Smartphone.', trim((string) preg_replace('/\s+(Vollkurs|Kombikurs)$/u', '', $names[0]))),
        };
        $items = [];
        if ($questions > 0) {
            $items[] = sprintf('%d Prüfungsfragen im Smarttrainer mit Erklärungen, Wiederholung falsch beantworteter Fragen und Merkliste', $questions);
        }
        $items[] = $papers > 0 ? sprintf('Prüfungssimulation mit Zeitlimit und Auswertung, inkl. %d Prüfungsbögen', $papers) : 'Prüfungssimulation mit Zeitlimit und Auswertung';
        if ($nav > 0) {
            $items[] = sprintf('%d Navigationsaufgaben mit Musterlösungen', $nav);
        }
        if ($praxis > 0) {
            $items[] = sprintf('%d Praxiskarten zur Vorbereitung auf die praktische Prüfung', $praxis);
        }
        if ($videos > 0) {
            $items[] = sprintf('Videokurs mit %d Lektionen', $videos);
        }
        if ($radio) {
            $items[] = 'Funktraining: Funktexte, Vokabeln, Buchstabiertafel, Praxisszenarien und Gerätesimulation';
        }
        if ($pass) {
            $items[] = sprintf('Alle %d Kurse: Sportbootführerscheine, Funkzeugnisse, Seenotsignalmittel und Ergänzungskurse – mit allen Trainern und Videos', count($all));
        }
        $items[] = 'Lernfortschritt wird gespeichert – überall weiterlernen';
        if ($seats > 0) {
            array_unshift($items, sprintf('%d Plätze: Du lädst die Teilnehmenden per Link oder E-Mail ein; jede Person lernt mit eigenem Konto und eigenem Fortschritt. Ein Platz kann auch für dich selbst sein.', $seats));
        }
        $long = '<p>'.esc_html($intro).'</p><ul><li>'.implode('</li><li>', array_map('esc_html', $items)).'</li></ul>'
            .'<p>'.esc_html(sprintf('Zugang %1$s ab Kauf, freigeschaltet sobald die Zahlung eingegangen ist. Du lernst im Bereich „%2$s“; deine Zugänge findest du unter „Mein Lernen“ im Kundenkonto.', $months, \Bootspruefung\Learning\Settings\Settings::brand())).'</p>'
            .($seats > 0
                ? '<p>'.esc_html('Die Gruppe verwaltest du unter „Meine Gruppen“ im Lernbereich. Der Zugang gilt für alle Teilnehmenden bis zum Ende der Laufzeit ab Kauf. Dein gesetzliches Widerrufsrecht bleibt unberührt.').'</p>'
                : '<p><strong>'.esc_html(sprintf('Geld-zurück-Garantie: Innerhalb von %d Tagen nach dem Kauf erstatten wir dir den Preis, solange du weniger als %d %% der Inhalte gelernt hast.', self::MONEY_BACK_DAYS, (int) self::MONEY_BACK_MAX_PROGRESS)).'</strong> '.esc_html('Dein gesetzliches Widerrufsrecht bleibt unberührt.').'</p>')
            .(count($all) > 1 && ! $pass ? '<p>'.esc_html('Enthält: '.implode(', ', $names)).'</p>' : '');
        $short = $seats > 0
            ? sprintf('%d Lernzugänge · %s · Zugang %s', $seats, $questions > 0 ? $questions.' Prüfungsfragen je Person' : 'Alle Inhalte', $months)
            : sprintf('%s · %s · Zugang %s · Geld-zurück-Garantie', $questions > 0 ? $questions.' Prüfungsfragen' : 'Alle Inhalte', 'Prüfungssimulation', $months);

        return ['short' => esc_html($short), 'long' => $long];
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
