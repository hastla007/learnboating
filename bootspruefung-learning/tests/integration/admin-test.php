<?php

declare(strict_types=1);

/*
 * Integrationstest Admin/Import: veraltete Freigaben, abgebrochene Importe (Mischstand,
 * Wiederherstellung), hängengebliebene Importe, Vorschau in Teilschritten, Sammelfreigabe
 * der Rechte, Preiseingabe. Mit aktivem WooCommerce zusätzlich: Katalog-Abgleich respektiert
 * Handänderungen je Feld.
 *
 *   WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php tests/integration/admin-test.php
 *
 * Voraussetzung: Plugin aktiv und Paket bereits einmal importiert (z. B. nach content-flow-test.php).
 * Ohne PACKAGE_DIR laufen nur die Teile ohne Paket.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$packageDir = rtrim((string) getenv('PACKAGE_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php')) {
    fwrite(STDERR, "WP_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';

use Bootspruefung\Learning\Admin\SystemStatus;
use Bootspruefung\Learning\Commerce\ShopCatalog;
use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Content\RightsRelease;
use Bootspruefung\Learning\Plugin;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
$throws = static function (callable $fn, string $needle = ''): bool {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }

    return false;
};
global $wpdb;
$p = $wpdb->prefix.'bp_';
$service = Plugin::instance()->importService();
wp_set_current_user(1);
$setStats = static function (int $id, array $changes) use ($service, $wpdb, $p): void {
    $stats = array_merge($service->stats($service->batch($id)), $changes);
    $wpdb->update($p.'import_batch', ['stats' => wp_json_encode($stats)], ['id' => $id]);
};

if ($packageDir !== '' && is_file($packageDir.'/manifest.json')) {
    // Ausgangslage: Paket veröffentlicht (sonst einmal importieren).
    if ($service->activeBatchId() === null) {
        $first = $service->createFromDirectory($packageDir);
        $service->preview($first);
        $service->approve($first, 'Admin-Test: Ausgangsstand');
        $service->runAll($first);
    }
    $activeBefore = $service->activeBatchId();
    $check($activeBefore !== null, 'Ausgangsstand: veröffentlichtes Paket');

    echo "Veraltete Vorschau und Freigabe\n";
    $old = $service->createFromDirectory($packageDir);
    $service->preview($old);
    $oldApproved = $service->createFromDirectory($packageDir);
    $service->preview($oldApproved);
    $service->approve($oldApproved, 'Admin-Test');
    $newer = $service->createFromDirectory($packageDir);
    $service->preview($newer);
    $service->approve($newer, 'Admin-Test');
    $service->runAll($newer);
    $check($service->batch($newer)->status === 'published', 'neuerer Import veröffentlicht');
    $check($service->supersededBy($service->batch($old)) === $newer, 'ältere Vorschau als veraltet erkannt');
    $check($throws(fn () => $service->approve($old), '#'.$newer), 'Freigabe einer älteren Vorschau abgewiesen');
    $check($throws(fn () => $service->runStep($oldApproved), '#'.$newer), 'älterer freigegebener Import startet nicht (würde Neueres überschreiben)');
    $check($service->batch($oldApproved)->status === 'approved', 'älterer Import blieb unverändert freigegeben');
    $service->discard($old);
    $service->discard($oldApproved);
    $check($service->batch($oldApproved)->status === 'discarded', 'veralteter Import verwerfbar');

    echo "Vorschau in Teilschritten\n";
    $chunked = $service->createFromDirectory($packageDir);
    $rounds = 0;
    do {
        $summary = $service->preview($chunked, 0.0);
        $rounds++;
    } while ($summary === null && $rounds < 1000);
    $full = $service->preview($chunked);
    $check($rounds > 1 && $summary === $full, "Teilschritte ({$rounds}) ergeben dieselbe Vorschau wie am Stück");
    $check($service->batch($chunked)->status === 'previewed' && $service->previewProgress($service->batch($chunked)) === null, 'nach letztem Teilschritt: Status previewed');
    $service->discard($chunked);

    echo "Abgebrochener und hängengebliebener Import\n";
    $aborted = $service->createFromDirectory($packageDir);
    $service->preview($aborted);
    $service->approve($aborted, 'Admin-Test');
    $step = $service->runStep($aborted, 1);
    $check(! $step['done'] && $service->batch($aborted)->status === 'importing', 'Import läuft (erster Teilschritt)');
    $check(! $service->isAborted($service->batch($aborted)) && $throws(fn () => $service->discard($aborted)), 'laufender Import nicht verwerfbar');
    $blocker = $service->createFromDirectory($packageDir);
    $service->preview($blocker);
    $service->approve($blocker, 'Admin-Test');
    $check($throws(fn () => $service->runStep($blocker), '#'.$aborted), 'zweiter Import wartet auf den laufenden');
    // Fatal/Timeout simulieren: seit über 10 Minuten kein Schritt.
    $setStats($aborted, ['heartbeat' => time() - ImportService::STALE_AFTER - 5]);
    $batch = $service->batch($aborted);
    $check($service->isStale($batch) && $service->isAborted($batch), 'ohne Schritt seit > 10 min: hängengeblieben');
    $check($service->abortedImport() === $aborted, 'Mischstand erkannt');
    $service->discard($blocker);
    $service->discard($aborted);
    $check($service->batch($aborted)->status === 'discarded' && $service->abortedImport() === $aborted, 'hängengebliebener Import verworfen, Mischstand bleibt vermerkt');
    $restore = $service->restoreActive('Admin-Test: Wiederherstellung');
    $check(is_int($restore) && (int) $service->stats($service->batch($restore))['rollback_of'] === $service->activeBatchId(), 'Rollback auf das aktive Paket angelegt (trotz „bereits aktiv“)');
    $service->preview($restore);
    $service->approve($restore, 'Admin-Test');
    $service->runAll($restore);
    $check($service->batch($restore)->status === 'published' && $service->abortedImport() === null, 'nach Wiederherstellung kein Mischstand mehr');
    $check($throws(fn () => $service->rollback((int) $service->activeBatchId(), 'Admin-Test'), 'bereits aktiv'), 'ohne Abbruch: aktives Paket nicht erneut zurücksetzbar');

    echo "Fehler außerhalb der Datensatz-Transaktion\n";
    $broken = $service->createFromDirectory($packageDir);
    $service->preview($broken);
    $service->approve($broken, 'Admin-Test');
    $dir = \Bootspruefung\Learning\Content\Media\MediaStorage::packagesDir().'/batch-'.$broken;
    rename($dir, $dir.'-weg');
    $check($throws(fn () => $service->runStep($broken), (string) $broken), 'fehlende Paketdateien: Schritt bricht ab');
    $batch = $service->batch($broken);
    $check($batch->status === 'importing' && (string) $batch->error !== '' && $service->isAborted($batch), 'Fehler festgehalten: sofort als abgebrochen verwerfbar');
    rename($dir.'-weg', $dir);
    $service->discard($broken);
    $restore = $service->restoreActive('Admin-Test: Wiederherstellung');
    $service->preview($restore);
    $service->approve($restore, 'Admin-Test');
    $service->runAll($restore);
    $check($service->abortedImport() === null, 'wiederhergestellt');

    echo "Medien-Teilschritte nach Bytes\n";
    $records = [['size' => 40], ['size' => 40], ['size' => 40]];
    $check(count(ImportService::limitBytes($records, 100)) === 2 && count(ImportService::limitBytes([['size' => 500]], 100)) === 1, 'Teilschritt begrenzt (mindestens eine Datei)');
}

echo "Sammelfreigabe Rechte\n";
$type = null;
$unknown = [];
foreach (['media' => 'media', 'praxis_task' => 'praxis_task', 'video_module' => 'video_module', 'navigation_task' => 'navigation_task'] as $t => $table) {
    $unknown = array_map('intval', $wpdb->get_col("SELECT x.id FROM {$p}{$table} x ".Overrides::rightsJoin($t, 'x', 'o')." WHERE o.object_id IS NULL AND x.rights_status = 'unknown' AND x.status <> 'source_archived' ORDER BY x.id LIMIT 3"));
    if (count($unknown) === 3) {
        $type = $t;
        break;
    }
}
if ($type === null) {
    echo "  (übersprungen: keine Inhalte mit Rechte-Status unknown ohne Override)\n";
} else {
    [$restricted, $archived, $free] = $unknown;
    $archivedStatus = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}{$type} WHERE id = %d", $archived));
    Overrides::set($type, [$restricted], 'rights_status', 'restricted', 'Admin-Test');
    $wpdb->update($p.$type, ['status' => 'source_archived'], ['id' => $archived]);
    $before = RightsRelease::releaseAll('Admin-Test', true)[$type];
    RightsRelease::releaseAll('Admin-Test');
    $check(Overrides::get($type, $restricted, 'rights_status') === 'restricted', "{$type}: bewusstes restricted bleibt");
    $check(Overrides::get($type, $archived, 'rights_status') === null, "{$type}: archivierte Inhalte ausgenommen");
    $check(Overrides::get($type, $free, 'rights_status') === 'licensed' && $before >= 1, "{$type}: unknown ohne Override freigegeben");
    $check(array_sum(RightsRelease::releaseAll('x', true)) === 0, 'danach nichts mehr offen');
    $wpdb->update($p.$type, ['status' => $archivedStatus], ['id' => $archived]);
    $wpdb->delete($p.'override', ['reason' => 'Admin-Test']);
    wp_cache_flush_group('bootspruefung');
}

echo "Preise\n";
$pricesBefore = get_option(ShopCatalog::PRICE_OPTION, null);
$r = ShopCatalog::saveOwnPrices(['BP-SKN' => '1.299,00', 'BP-FKN' => '1.234', 'BP-SRC' => '12,3,4']);
$own = ShopCatalog::ownPrices();
$check(($own['BP-SKN'] ?? '') === '1299.00' && ($own['BP-FKN'] ?? '') === '1234.00' && isset($r['errors']['BP-SRC']), 'Tausenderpunkt toleriert, Unsinn abgewiesen');
$pricesBefore === null ? delete_option(ShopCatalog::PRICE_OPTION) : update_option(ShopCatalog::PRICE_OPTION, $pricesBefore, false);
$check(SystemStatus::taxWarning(['prices_include_tax' => false, 'tax_enabled' => true]) !== null && SystemStatus::taxWarning(['prices_include_tax' => true, 'tax_enabled' => true]) === null && SystemStatus::taxWarning(['prices_include_tax' => null]) === null, 'Warnung bei Nettopreisen in WooCommerce');
$check(SystemStatus::taxWarning(['prices_include_tax' => false, 'tax_enabled' => false, 'price_note' => 'ohne MwSt. – Kleinunternehmer gem. § 19 UStG']) === null && SystemStatus::taxWarning(['prices_include_tax' => false, 'tax_enabled' => false, 'price_note' => 'inkl. MwSt.']) !== null && SystemStatus::taxWarning(['prices_include_tax' => false, 'tax_enabled' => true, 'price_note' => 'Kleinunternehmer']) !== null, 'Steuer aus: keine Warnung bei Kleinunternehmer-Hinweis am Preis, sonst Warnung');

echo "Shop-Grundeinstellungen (T73)\n";
$check(SystemStatus::ibanValid('DE89 3704 0044 0532 0130 00') && ! SystemStatus::ibanValid('DE89370400440532013001') && ! SystemStatus::ibanValid('DE12') && SystemStatus::maskIban('DE89370400440532013000') === 'DE89 … 3000', 'IBAN-Prüfziffer und gekürzte Anzeige');
$t73Keys = ['woocommerce_bacs_settings', 'woocommerce_bacs_accounts', 'woocommerce_store_address', 'woocommerce_store_postcode', 'woocommerce_store_city', 'woocommerce_default_country', 'woocommerce_email_from_address', 'woocommerce_email_from_name', 'woocommerce_customer_processing_order_settings'];
$t73Bak = [];
foreach ($t73Keys as $k) {
    $t73Bak[$k] = get_option($k, null);
}
$langDir = sys_get_temp_dir().'/bp-t73-lang-'.wp_generate_password(6, false);
wp_mkdir_p($langDir.'/plugins');
$basics = static fn (string $locale = 'de_DE'): array => array_column(SystemStatus::shopBasics($locale, $langDir), null, 'key');
update_option('woocommerce_bacs_settings', ['enabled' => 'yes', 'title' => 'Überweisung']);
update_option('woocommerce_bacs_accounts', [['account_name' => 'Test', 'iban' => '', 'bic' => '']]);
update_option('woocommerce_store_address', 'Hafenstraße 1');
update_option('woocommerce_store_postcode', '');
update_option('woocommerce_store_city', 'Kiel');
update_option('woocommerce_default_country', 'US:CA');
update_option('woocommerce_email_from_address', 'keine-adresse');
update_option('woocommerce_email_from_name', 'Testschule');
$b = $basics('en_US');
$check(! $b['language']['ok'] && ! $b['woocommerce_language']['ok'] && str_contains($b['woocommerce_language']['detail'], 'woocommerce-de_DE.mo'), 'Sprache Englisch und fehlende WooCommerce-Sprachdatei gemeldet');
$check(! $b['bacs_account']['ok'] && str_contains($b['bacs_account']['detail'], 'keine Bankverbindung'), 'Überweisung aktiv ohne IBAN gemeldet');
$check(! $b['store_address']['ok'] && str_contains($b['store_address']['detail'], 'PLZ') && str_contains($b['store_address']['detail'], 'Land') && ! str_contains($b['store_address']['detail'], 'Ort'), 'unvollständige Shop-Adresse: PLZ und Land fehlen');
$check(! $b['email_from']['ok'], 'Absender ohne gültige Adresse gemeldet');
update_option('woocommerce_bacs_accounts', [['account_name' => 'Test', 'iban' => 'DE89370400440532013001']]);
$b = $basics();
$check(! $b['bacs_account']['ok'] && str_contains($b['bacs_account']['detail'], 'ungültig'), 'IBAN mit falscher Prüfziffer gemeldet');
file_put_contents($langDir.'/plugins/woocommerce-de_DE.mo', 'x');
update_option('woocommerce_bacs_accounts', [['account_name' => 'Test', 'iban' => 'DE89 3704 0044 0532 0130 00']]);
update_option('woocommerce_store_postcode', '24103');
update_option('woocommerce_default_country', 'DE');
update_option('woocommerce_email_from_address', 'kurse@example.test');
update_option('woocommerce_customer_processing_order_settings', ['enabled' => 'yes', 'email_type' => 'multipart']);
$b = $basics();
$check(count(array_filter($b, static fn ($r) => ! $r['ok'])) === 0 && str_contains($b['bacs_account']['detail'], '3000') && ! str_contains($b['bacs_account']['detail'], '05320130'), 'alles vollständig: keine Hinweise, IBAN nur gekürzt angezeigt');
if (function_exists('WC')) {
    update_option('woocommerce_customer_processing_order_settings', ['enabled' => 'yes', 'email_type' => 'plain']);
    $b = $basics();
    $check(! $b['email_type']['ok'], 'Kundenmail „nur Text“ gemeldet');
}
update_option('woocommerce_bacs_settings', ['enabled' => 'no']);
$check(! isset($basics()['bacs_account']), 'Überweisung aus: keine Prüfung der Bankverbindung');
foreach ($t73Bak as $k => $v) {
    $v === null ? delete_option($k) : update_option($k, $v);
}
@unlink($langDir.'/plugins/woocommerce-de_DE.mo');
@rmdir($langDir.'/plugins');
@rmdir($langDir);

echo "Admin-Anzeige\n";
$check(\Bootspruefung\Learning\Admin\Ui::label('draft') === 'Entwurf' && \Bootspruefung\Learning\Admin\Ui::label('single_choice') === 'Einfachauswahl' && str_contains(\Bootspruefung\Learning\Admin\Ui::status('source_archived'), 'archiviert'), 'Status und Typen auf Deutsch');
ob_start();
(new \Bootspruefung\Learning\Admin\ContentPages())->renderOverview();
$html = (string) ob_get_clean();
$sumOk = preg_match_all('#<tr><td>[^<]+</td>((?:<td>\d+</td>){6})</tr>#', $html, $m) > 0;
foreach ($m[1] ?? [] as $cells) {
    preg_match_all('#<td>(\d+)</td>#', $cells, $n);
    $v = array_map('intval', $n[1]);
    $sumOk = $sumOk && $v[0] === array_sum(array_slice($v, 1));
}
$check($sumOk, 'Bestand: Spalten ergeben die Gesamtzahl');

if (function_exists('wc_get_product_id_by_sku')) {
    echo "Verwaiste Produktzuordnung\n";
    $orphan = new WC_Product_Simple();
    $orphan->set_name('Admin-Test verwaist');
    $orphanId = (int) $orphan->save();
    $repoO = new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb);
    $repoO->save($orphanId, 0, [['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank']], null, 'extend', 'revoke_on_full_refund');
    $check($throws(fn () => \Bootspruefung\Learning\Commerce\MappingCheck::removeOrphan($orphanId, 0)), 'bestehendes Produkt: Zuordnung nicht über die Übersicht entfernbar');
    wp_delete_post($orphanId, true);
    $issue = array_values(array_filter(\Bootspruefung\Learning\Commerce\MappingCheck::issues(), static fn ($i) => $i['product_id'] === $orphanId));
    $check(($issue[0]['deleted'] ?? false) === true, 'gelöschtes Produkt erkannt (kein Bearbeiten-Link)');
    $check(\Bootspruefung\Learning\Commerce\MappingCheck::removeOrphan($orphanId, 0) === 1 && $repoO->rows($orphanId, 0) === [], 'verwaiste Zuordnung entfernt');

    echo "Katalog-Abgleich respektiert Handänderungen\n";
    $rows = ShopCatalog::applyProducts();
    $bySku = array_column($rows, null, 'sku');
    $id = (int) $bySku['BP-SKN']['product_id'];
    $product = wc_get_product($id);
    $check(is_array(get_post_meta($id, ShopCatalog::FIELDS_META, true)) && count(get_post_meta($id, ShopCatalog::FIELDS_META, true)) >= 6, 'Fingerprints je Feld gespeichert');
    $extra = wp_insert_term('Admin-Test-Kategorie', 'product_cat');
    $extraId = is_wp_error($extra) ? (int) get_term_by('name', 'Admin-Test-Kategorie', 'product_cat')->term_id : (int) $extra['term_id'];
    $catalogCats = $product->get_category_ids();
    $product->set_name('Eigener Name');
    $product->set_catalog_visibility('search');
    $product->set_category_ids([$extraId]);
    $product->save();
    \Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($id, 30, 10.0);
    $repo = new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb);
    $rowsBefore = $repo->rows($id, 0);
    $repo->save($id, 0, array_map(static fn ($r) => ['course_code' => $r->course_code, 'feature_code' => $r->feature_code], $rowsBefore), 180, 'parallel', 'keep');
    $preview = array_column(ShopCatalog::applyProducts(true), null, 'sku');
    $check(in_array('name', $preview['BP-SKN']['skipped'], true) && in_array('access', $preview['BP-SKN']['skipped'], true), 'Vorschau nennt übersprungene Felder');
    $second = array_column(ShopCatalog::applyProducts(), null, 'sku');
    $product = wc_get_product($id);
    $skipped = $second['BP-SKN']['skipped'];
    $check($product->get_name() === 'Eigener Name' && $product->get_catalog_visibility() === 'search', 'Name und Sichtbarkeit bleiben');
    $check(in_array($extraId, $product->get_category_ids(), true) && array_diff($catalogCats, $product->get_category_ids()) === [], 'eigene Kategorie bleibt, Katalog-Kategorie ergänzt');
    $mb = \Bootspruefung\Learning\Commerce\MoneyBack::policy($id);
    $accessRows = $repo->rows($id, 0);
    $check($mb !== null && $mb['days'] === 30 && (int) $accessRows[0]->duration_days === 180 && $accessRows[0]->renewal_mode === 'parallel', 'Garantie und Lernzugang (Laufzeit, Verlängerung) bleiben');
    $check(array_values(array_intersect(['name', 'visibility', 'categories', 'access', 'money_back'], $skipped)) === ['name', 'visibility', 'categories', 'access', 'money_back'], 'Bericht: übersprungene Felder');
    $check(($second['BP-FKN']['skipped'] ?? null) === [] && $product->get_regular_price() === ShopCatalog::find('BP-SKN')['price'], 'andere Produkte unberührt, Preis weiter vom Katalog');
    // Zurück auf Katalogwerte: danach gleicht der Katalog wieder ab.
    $product->set_name(ShopCatalog::find('BP-SKN')['name']);
    $product->set_catalog_visibility('visible');
    $product->set_category_ids($catalogCats);
    $product->save();
    \Bootspruefung\Learning\Commerce\MoneyBack::setPolicy($id, ShopCatalog::MONEY_BACK_DAYS, ShopCatalog::MONEY_BACK_MAX_PROGRESS);
    $repo->save($id, 0, array_map(static fn ($r) => ['course_code' => $r->course_code, 'feature_code' => $r->feature_code], $rowsBefore), (int) $rowsBefore[0]->duration_days, 'extend', 'revoke_on_full_refund');
    $third = array_column(ShopCatalog::applyProducts(), null, 'sku');
    $check($third['BP-SKN']['skipped'] === [], 'auf Katalogwert zurückgesetzt: wieder vom Katalog verwaltet');

    echo "Ergänzung FKN → SKN vorerst nicht im Verkauf\n";
    $skus = array_column(ShopCatalog::products(), 'sku');
    $check(! array_intersect(['BP-ADD-FKN-TO-SKN', 'BP-ADD-FKN-TO-SKN-KOMPLETT', 'BP-ADD-FKN-TO-SKN-VIDEO'], $skus) && in_array('BP-ADD-SEE-TO-BIN', $skus, true) && in_array('BP-SKN', $skus, true), 'kein Produkt für Ergänzung FKN → SKN, andere Ergänzungen bleiben');
    $legacy = new WC_Product_Simple();
    $legacy->set_sku('BP-ADD-FKN-TO-SKN');
    $legacy->set_name('Ergänzung FKN → SKN (alt)');
    $legacy->set_status('publish');
    $legacyId = (int) $legacy->save();
    $preview = array_column(ShopCatalog::applyProducts(true), null, 'sku');
    $check(($preview['BP-ADD-FKN-TO-SKN']['action'] ?? '') === 'deaktiviert (Vorschau)' && get_post_status($legacyId) === 'publish', 'Vorschau nennt die Deaktivierung, ändert nichts');
    $applied = array_column(ShopCatalog::applyProducts(), null, 'sku');
    $check(($applied['BP-ADD-FKN-TO-SKN']['action'] ?? '') === 'deaktiviert (Entwurf)' && get_post_status($legacyId) === 'draft', 'vorhandenes Produkt auf Entwurf gesetzt (nicht gelöscht)');
    $check(\Bootspruefung\Learning\Commerce\CourseRelations::evaluate(['FKN'], ['ADD-FKN-TO-SKN', 'SKN'])['ADD-FKN-TO-SKN']['recommended_for'] === [], 'keine Empfehlung für FKN-Inhaber');
    wp_delete_post($legacyId, true);
    wp_delete_term($extraId, 'product_cat');

    echo "Katalog-Abgleich: von Hand geänderte Preise und zurückgestellte Entwürfe (T98)\n";
    $sknId = (int) wc_get_product_id_by_sku('BP-SKN');
    $fknId = (int) wc_get_product_id_by_sku('BP-FKN');
    // Stand aller Katalogprodukte sichern (der Test veröffentlicht), am Ende wiederherstellen.
    $statusBak = [];
    foreach (ShopCatalog::products() as $def) {
        if ($pid = (int) wc_get_product_id_by_sku($def['sku'])) {
            $statusBak[$pid] = [get_post_status($pid), get_post_meta($pid, ShopCatalog::STATUS_META, true), get_post_meta($pid, ShopCatalog::MANUAL_STATUS_META, true)];
        }
    }
    $catalogSkn = ShopCatalog::find('BP-SKN')['price'];
    ShopCatalog::applyProducts();
    $check(get_post_meta($sknId, ShopCatalog::PRICE_META, true) === number_format((float) $catalogSkn, 2, '.', ''), 'zuletzt geschriebener Katalogpreis gemerkt');
    $p = wc_get_product($sknId);
    $p->set_regular_price('99.00');
    $p->save();
    $dry = array_column(ShopCatalog::applyProducts(true), null, 'sku')['BP-SKN'];
    $check(in_array('price', $dry['skipped'], true) && str_contains($dry['price'], 'nicht überschrieben') && str_contains($dry['price'], '99,00'), 'Vorschau meldet: Preis von Hand geändert, nicht überschrieben');
    $row = array_column(ShopCatalog::applyProducts(), null, 'sku')['BP-SKN'];
    $check(wc_get_product($sknId)->get_regular_price() === '99.00' && in_array('price', $row['skipped'], true), 'Abgleich überschreibt den Handpreis nicht still');
    $check(\Bootspruefung\Learning\Admin\PricePage::skippedMessage([$row]) !== '' && ShopCatalog::fieldLabel('price') !== 'price', 'Preis-Seite meldet den übersprungenen Preis');
    $row = array_column(ShopCatalog::applyProducts(false, false, ['overwrite_prices' => ['BP-SKN']]), null, 'sku')['BP-SKN'];
    $check(wc_get_product($sknId)->get_regular_price() === $catalogSkn && str_contains($row['price'], 'bewusst überschrieben'), 'bewusst überschreiben: Katalogpreis gesetzt');
    $ownBak = get_option(ShopCatalog::PRICE_OPTION, []);
    ShopCatalog::saveOwnPrices(['BP-SKN' => '33,90']);
    $row = array_column(ShopCatalog::applyProducts(), null, 'sku')['BP-SKN'];
    $check(wc_get_product($sknId)->get_regular_price() === '33.90' && ! in_array('price', $row['skipped'], true), 'neuer eigener Preis der Preis-Seite wird übertragen (keine Handänderung)');
    update_option(ShopCatalog::PRICE_OPTION, $ownBak, false);
    ShopCatalog::applyProducts();
    $check(wc_get_product($sknId)->get_regular_price() === ShopCatalog::find('BP-SKN')['price'], 'eigener Preis zurück: Katalogpreis wieder im Shop');
    delete_post_meta($sknId, ShopCatalog::PRICE_META);
    $p = wc_get_product($sknId);
    $p->set_regular_price('77.00');
    $p->save();
    $check(ShopCatalog::priceChangedByHand(wc_get_product($sknId), ShopCatalog::find('BP-SKN')), 'ältere Produkte ohne Vermerk: abweichender Preis gilt als Handänderung');
    ShopCatalog::applyProducts(false, false, ['overwrite_prices' => true]);

    // Entwurf von Hand zurückgestellt (Statuswechsel außerhalb des Katalogs).
    wp_update_post(['ID' => $fknId, 'post_status' => 'publish']);
    wp_update_post(['ID' => $fknId, 'post_status' => 'draft']);
    $check(get_post_meta($fknId, ShopCatalog::MANUAL_STATUS_META, true) === 'draft', 'Statuswechsel von Hand gemerkt');
    $dry = array_column(ShopCatalog::applyProducts(true, true), null, 'sku');
    $check(str_contains($dry['BP-FKN']['status'], 'zurückgestellt') && in_array('status', $dry['BP-FKN']['skipped'], true), 'Vorschau mit Veröffentlichen: zurückgestellter Entwurf bleibt Entwurf');
    ShopCatalog::applyProducts(false, true);
    $check(get_post_status($fknId) === 'draft', 'Veröffentlichen lässt den zurückgestellten Entwurf Entwurf');
    ShopCatalog::applyProducts(false, true, ['publish_held' => ['BP-FKN']]);
    $check(get_post_status($fknId) === 'publish', 'bewusst gewählt: zurückgestellter Entwurf veröffentlicht');
    // Vom Katalog als Entwurf angelegt (kein Statuswechsel von Hand): wird veröffentlicht.
    wp_update_post(['ID' => $fknId, 'post_status' => 'draft']);
    delete_post_meta($fknId, ShopCatalog::MANUAL_STATUS_META);
    update_post_meta($fknId, ShopCatalog::STATUS_META, 'draft');
    $check(! ShopCatalog::heldBack(wc_get_product($fknId)), 'Katalog-Entwurf gilt nicht als zurückgestellt');
    ShopCatalog::applyProducts(false, true);
    $check(get_post_status($fknId) === 'publish' && get_post_meta($fknId, ShopCatalog::STATUS_META, true) === 'publish', 'Katalog-Entwurf wird veröffentlicht');
    // Ältere Produkte ohne Vermerk: im Editor bearbeitet (_edit_last) = zurückgestellt.
    wp_update_post(['ID' => $fknId, 'post_status' => 'draft']);
    delete_post_meta($fknId, ShopCatalog::MANUAL_STATUS_META);
    delete_post_meta($fknId, ShopCatalog::STATUS_META);
    update_post_meta($fknId, '_edit_last', 1);
    $check(ShopCatalog::heldBack(wc_get_product($fknId)), 'älterer Entwurf, im Editor bearbeitet: zurückgestellt');
    delete_post_meta($fknId, '_edit_last');
    $check(! ShopCatalog::heldBack(wc_get_product($fknId)), 'älterer Entwurf ohne Bearbeitung: wird veröffentlicht');
    // Deaktivierter Kurs: bleibt immer Entwurf.
    $legacy = new WC_Product_Simple();
    $legacy->set_sku('BP-ADD-FKN-TO-SKN');
    $legacy->set_name('Ergänzung FKN → SKN (alt)');
    $legacy->set_status('draft');
    $legacyId = (int) $legacy->save();
    $dry = array_column(ShopCatalog::applyProducts(true, true), null, 'sku');
    $check(str_contains((string) ($dry['BP-ADD-FKN-TO-SKN']['status'] ?? ''), 'deaktiviert'), 'Vorschau: deaktivierter Kurs bleibt Entwurf');
    ShopCatalog::applyProducts(false, true, ['publish_held' => true]);
    $check(get_post_status($legacyId) === 'draft', 'deaktivierter Kurs wird auch mit „veröffentlichen“ nicht veröffentlicht');
    wp_delete_post($legacyId, true);
    foreach ($statusBak as $pid => [$st, $catalogMeta, $manualMeta]) {
        if (get_post_status($pid) !== $st) {
            wp_update_post(['ID' => $pid, 'post_status' => $st]);
        }
        foreach ([ShopCatalog::STATUS_META => $catalogMeta, ShopCatalog::MANUAL_STATUS_META => $manualMeta] as $key => $value) {
            $value === '' ? delete_post_meta($pid, $key) : update_post_meta($pid, $key, $value);
        }
    }
}

echo "Laufzeit vorbelegt, ohne Ablauf nur ausdrücklich (T101)\n";
require_once ABSPATH.'wp-admin/includes/template.php';
$dd = \Bootspruefung\Learning\Commerce\DefaultDuration::class;
$check($dd::forCourse('SBF-SEE') === 365 && $dd::forCourse('SKN') === 182 && $dd::forCourse('GIBT-ES-NICHT') === $dd::MANUAL_DAYS && $dd::MANUAL_DAYS === 182, 'Laufzeit je Kurs aus dem Katalog (Vollkurs 365, SKN 182), sonst 182 Tage');
$check($dd::forSku('BP-PASS-1M') === 30 && $dd::forSku('') === 365 && $dd::forSku('EIGENES-PRODUKT') === $dd::PRODUCT_DAYS, 'Produkt: Katalog-Laufzeit der SKU, sonst 365 Tage');
$pp = \Bootspruefung\Learning\Commerce\WooCommerce\ProductPanel::class;
$check($pp::duration(['duration' => ''], 365) === 365 && $pp::duration([], 182) === 182 && $pp::duration(['duration' => '90'], 365) === 90 && $pp::duration(['duration' => '90', 'unlimited' => '1'], 365) === null, 'Formular: leer = Vorbelegung, dauerhaft nur mit „ohne Ablauf“');
$check(\Bootspruefung\Learning\Admin\AccessPages::defaultUntil(182) === wp_date('Y-m-d', time() + 182 * DAY_IN_SECONDS), 'Enddatum der manuellen Freischaltung vorbelegt');
ob_start();
(new \Bootspruefung\Learning\Admin\AccessPages())->render();
$html = (string) ob_get_clean();
if (\Bootspruefung\Learning\Commerce\Labels::courses() !== []) {
    $check(str_contains($html, 'name="unlimited"') && str_contains($html, 'data-bp-until="') && ! str_contains($html, 'leer = dauerhaft') && preg_match('#id="bp-grant-until" type="date" name="valid_until" value="\d{4}-\d{2}-\d{2}"#', $html) === 1, 'Formular „Zugang manuell freischalten“: Datum vorbelegt, Checkbox „ohne Ablauf“');
}
if (function_exists('wc_get_product')) {
    $durProduct = new WC_Product_Simple();
    $durProduct->set_name('Admin-Test Laufzeit');
    $durId = (int) $durProduct->save();
    $repoD = new \Bootspruefung\Learning\Commerce\ProductAccessRepository($wpdb);
    $renderPanel = static function (int $id): string {
        $GLOBALS['post'] = get_post($id);
        ob_start();
        (new \Bootspruefung\Learning\Commerce\WooCommerce\ProductPanel())->panel();

        return (string) ob_get_clean();
    };
    $panel = $renderPanel($durId);
    $check(str_contains($panel, 'name="bp_access[duration]" value="365"') && preg_match('#name="bp_access\[unlimited\]" value="1"\s*>#', $panel) === 1, 'neues Produkt: 365 Tage vorbelegt, „ohne Ablauf“ nicht angekreuzt');
    $repoD->save($durId, 0, [['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank']], null, 'extend', 'revoke_on_full_refund');
    $panel = $renderPanel($durId);
    $check(preg_match('#name="bp_access\[unlimited\]" value="1"\s+checked#', $panel) === 1 && str_contains($panel, 'name="bp_access[duration]" value=""'), 'gespeichert „dauerhaft“: bleibt so angezeigt (keine stille Änderung)');
    $check((int) $repoD->rows($durId, 0)[0]->duration_days === 0, 'gespeicherter Wert unverändert');
    $durIssues = array_values(array_filter(\Bootspruefung\Learning\Commerce\MappingCheck::issues(), static fn ($i) => $i['product_id'] === $durId && str_contains($i['problem'], 'ohne Ablauf')));
    $check(count($durIssues) === 1 && $durIssues[0]['severity'] === 'warning', 'Produktzuordnungen: Hinweis bei Zugang ohne Ablauf');
    $repoD->save($durId, 0, [['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank']], 365, 'extend', 'revoke_on_full_refund');
    $check(array_filter(\Bootspruefung\Learning\Commerce\MappingCheck::issues(), static fn ($i) => $i['product_id'] === $durId && str_contains($i['problem'], 'ohne Ablauf')) === [], 'mit Laufzeit: kein Hinweis');
    $repoD->save($durId, 0, [], null, 'extend', 'revoke_on_full_refund');
    wp_delete_post($durId, true);
    unset($GLOBALS['post']);
}

echo "Protokoll im Admin (T102)\n";
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/user.php';
$audit = \Bootspruefung\Learning\Admin\AuditPage::class;
$t102Mail = 't102-'.wp_generate_password(6, false, false).'@example.test';
$t102User = (int) wp_insert_user(['user_login' => 't102-'.wp_generate_password(6, false, false), 'user_email' => $t102Mail, 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$t102Course = (string) (array_key_first(\Bootspruefung\Learning\Commerce\Labels::courses()) ?? 'SBF-SEE');
$ents = new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb);
$t102Ids = $ents->grantManual($t102User, $t102Course, ['question_bank'], gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS), 'Admin-Test T102');
$t102Id = (int) $t102Ids[0];
$ents->extend($t102Id, null, 'Admin-Test T102 verlängert');
$ents->setStatusManual($t102Id, 'suspended', 'Admin-Test T102 ausgesetzt');
$history = $audit::forEntitlementId($t102Id);
$actions = array_map(static fn ($r) => $r->action, $history);
$check(in_array('entitlement_manual_grant', $actions, true) && in_array('entitlement_extended', $actions, true) && in_array('entitlement_manual_suspended', $actions, true), 'Verlauf je Zugang: Freischaltung, Enddatum, Aussetzen');
$extended = array_values(array_filter($history, static fn ($r) => $r->action === 'entitlement_extended'))[0] ?? null;
$text = $extended ? $audit::details($extended) : '';
$check(str_contains($text, 'ohne Ablauf') && ! str_contains($text, '{') && ! str_contains($text, '"'), 'Details als Klartext („von … auf ohne Ablauf“), kein JSON');
$grant = array_values(array_filter($history, static fn ($r) => $r->action === 'entitlement_manual_grant'))[0] ?? null;
$check($grant !== null && str_contains($audit::details($grant), \Bootspruefung\Learning\Commerce\Labels::course($t102Course)) && str_contains($audit::details($grant), 'Fragen lernen'), 'Kurs und Funktion mit Namen');
$check($audit::actionLabel('entitlement_manual_suspended') === 'Zugang manuell ausgesetzt' && str_contains($audit::actionLabel('gibt_es_nicht'), 'gibt_es_nicht'), 'Ereignisse auf Deutsch, Unbekanntes mit internem Namen');
$check($audit::reasonLabel('{"manual":"Kulanz"}') === 'Kulanz' && $audit::reasonLabel('item_removed') === 'Position aus der Bestellung entfernt', 'Begründungen lesbar');
$byUser = $audit::query($audit::filterFromRequest(['user' => $t102Mail]));
$check($byUser['total'] >= 3, 'Filter Nutzer (E-Mail)');
$byArea = $audit::query($audit::filterFromRequest(['user' => (string) $t102User, 'event' => 'area:entitlement']));
$check($byArea['total'] >= 3 && array_filter($byArea['rows'], static fn ($r) => ! str_starts_with($r->action, 'entitlement')) === [], 'Filter Bereich „Zugänge“');
$byAction = $audit::query($audit::filterFromRequest(['user' => (string) $t102User, 'event' => 'action:entitlement_extended']));
$check($byAction['total'] === 1, 'Filter einzelnes Ereignis');
$future = wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS);
$check($audit::query($audit::filterFromRequest(['user' => (string) $t102User, 'from' => $future]))['total'] === 0 && $audit::query($audit::filterFromRequest(['user' => (string) $t102User, 'from' => wp_date('Y-m-d'), 'to' => $future]))['total'] >= 3, 'Filter Zeitraum');
$check($audit::query($audit::filterFromRequest(['object' => 'entitlement', 'object_id' => (string) $t102Id]))['total'] === count($history), 'Filter Zugang Nr.');
$check($audit::query($audit::filterFromRequest(['user' => 'niemand-'.wp_generate_password(5, false).'@example.test']))['total'] === 0 && $audit::filterFromRequest(['user' => 'niemand@example.invalid'])['error'] !== '', 'unbekannter Nutzer: keine Treffer, Hinweis');
$check($audit::filterFromRequest(['event' => "action:x' OR 1=1"])['event'] === '' && $audit::filterFromRequest(['event' => 'area:gibtsnicht'])['event'] === '', 'ungültiger Ereignis-Filter verworfen');
$page2 = $audit::query(['user_id' => $t102User, 'user_input' => (string) $t102User], 2, 2);
$check(count($page2['rows']) >= 1 && $page2['total'] >= 3, 'seitenweise');
$gid = 900000 + wp_rand(1, 99999);
\Bootspruefung\Learning\Audit\AuditLog::record('group_created', 'order', '424242', 'Admin-Test T102', ['group' => $gid, 'seats' => 5]);
\Bootspruefung\Learning\Audit\AuditLog::record('group_suspended', 'group', (string) $gid, 'Admin-Test T102', ['from' => 'active', 'manual' => true]);
$groupLog = $audit::forGroup($gid);
$check(count($groupLog) === 2 && str_contains($audit::details($groupLog[0]), 'von aktiv auf') && str_contains($audit::details($groupLog[0]), 'von Hand: ja'), 'Verlauf je Gruppe (inkl. Anlage aus der Bestellung), Klartext');
$check(\Bootspruefung\Learning\Admin\AuditPage::CAPABILITY === 'manage_options', 'Protokollseite nur für Administratoren');
ob_start();
(new \Bootspruefung\Learning\Admin\AuditPage())->render();
$html = (string) ob_get_clean();
$check(str_contains($html, 'Protokoll') && str_contains($html, 'Zugang manuell ausgesetzt') && ! str_contains($html, '{&quot;') && ! str_contains($html, $t102Mail), 'Seite: Klartext, keine E-Mail-Adressen');
$_GET['entitlement'] = $t102Id;
ob_start();
(new \Bootspruefung\Learning\Admin\AccessPages())->render();
$html = (string) ob_get_clean();
unset($_GET['entitlement']);
$check(str_contains($html, 'class="bp-history"') && str_contains($html, 'Zugang manuell freigeschaltet') && str_contains($html, 'page=bootspruefung-audit'), 'Zugang: Verlauf aufklappbar mit Link zum Protokoll');
$editor = (int) wp_insert_user(['user_login' => 't102-ed-'.wp_generate_password(6, false, false), 'user_email' => 't102-ed-'.wp_generate_password(6, false, false).'@example.test', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
(new WP_User($editor))->add_cap(\Bootspruefung\Learning\Security\Capabilities::MANAGE_ENTITLEMENTS);
wp_set_current_user($editor);
$dieHandler = static fn () => static function (): void {
    throw new RuntimeException('wp_die');
};
add_filter('wp_die_handler', $dieHandler);
$denied = $throws(static function (): void {
    ob_start();
    try {
        (new \Bootspruefung\Learning\Admin\AuditPage())->render();
    } finally {
        ob_end_clean();
    }
}, 'wp_die');
remove_filter('wp_die_handler', $dieHandler);
wp_set_current_user(1);
$check($denied, 'ohne Administratorrechte: Protokollseite gesperrt');
$wpdb->query("DELETE FROM {$p}audit_log WHERE reason LIKE 'Admin-Test T102%'");
$wpdb->delete($p.'entitlement', ['user_id' => $t102User]);
wp_delete_user($t102User);
wp_delete_user($editor);

echo "Rechte- und Regelwerk-Freigabe mit Rückfrage (T103)\n";
require_once ABSPATH.'wp-admin/includes/template.php';
$cp = \Bootspruefung\Learning\Admin\ContentPages::class;
$check(str_contains($cp::releaseConfirmMessage(7), '7 Inhalte') && str_contains($cp::rulesConfirmMessage(1), '1 Regelwerk') && str_contains($cp::overrideConfirmMessage(12), '12 Einträge'), 'Rückfrage nennt die Anzahl');
$sum = $cp::overrideSummary('media', 'rights_status', 'licensed', 5);
$check(str_contains($sum, '5 Einträge') && str_contains($sum, 'Medien') && str_contains($sum, 'lizenziert') && str_contains($cp::overrideSummary('exam_rule', 'verified', '', 1), 'importierter Wert gilt'), 'Rückfrage im Klartext: Anzahl, Art und neuer Wert');
$check($cp::overrideText(['verified' => '1', 'status' => 'published']) === 'verifiziert: ja · Status: veröffentlicht' && $cp::overrideText([]) === '–', 'Lokale Abweichung eines Regelwerks als Text statt JSON');
$_POST = [];
$check(! \Bootspruefung\Learning\Admin\Ui::confirmed(), 'ohne Bestätigung: nicht ausführen');
$_POST['bp_confirmed'] = '1';
$check(\Bootspruefung\Learning\Admin\Ui::confirmed(), 'mit Bestätigung (Dialog oder Zwischenschritt): ausführen');
$_POST = [];
$back = \Bootspruefung\Learning\Admin\Ui::rememberConfirm('bp_override', 'bp_override', ['type' => 'media', 'value' => 'licensed', 'reason' => 'T103 Grund', 'ids' => '1,2'], 'T103: 2 Einträge ändern?', admin_url('admin.php?page=bootspruefung-rights'));
$check(str_contains($back, 'bp_confirm=1'), 'ohne JavaScript: Rücksprung zum Zwischenschritt');
$_GET['bp_confirm'] = '1';
ob_start();
\Bootspruefung\Learning\Admin\Ui::confirmBox();
$box = (string) ob_get_clean();
unset($_GET['bp_confirm']);
$check(str_contains($box, 'T103: 2 Einträge ändern?') && str_contains($box, 'name="bp_confirmed" value="1"') && str_contains($box, 'name="ids" value="1,2"') && str_contains($box, 'T103 Grund') && str_contains($box, 'Abbrechen') && str_contains($box, '_wpnonce'), 'Zwischenschritt: Rückfrage, gemerkte Werte, Nonce, Abbrechen');
delete_transient('bp_learning_confirm_'.get_current_user_id());
ob_start();
(new \Bootspruefung\Learning\Admin\ContentPages())->renderRights();
$html = (string) ob_get_clean();
$check(! str_contains($html, 'value="'.esc_attr(\Bootspruefung\Learning\Content\RightsRelease::DEFAULT_REASON).'"'), 'Rechte: Begründung nicht vorbelegt');
$preselectOk = preg_match_all('#name="effective" value="([a-z]*)".*?<select name="value"[^>]*>(.*?)</select>#s', $html, $forms, PREG_SET_ORDER) > 0;
foreach ($forms as $f) {
    $preselectOk = $preselectOk && preg_match('#<option value="'.preg_quote($f[1], '#').'"\s+selected#', $f[2]) === 1;
}
$check($preselectOk, 'Rechte je Gruppe: aktueller (wirksamer) Status vorausgewählt');
$check(preg_match_all('#data-bp-confirm="Wert für \d+ Eintr#', $html) === count($forms) && str_contains($html, 'name="bp_confirmed" value=""'), 'jede Änderung mit Rückfrage und Anzahl');
$check(! str_contains($html, '<code>{') && ! str_contains($html, '{&quot;'), 'Letzte Freigaben ohne JSON');
ob_start();
(new \Bootspruefung\Learning\Admin\ContentPages())->renderRules();
$html = (string) ob_get_clean();
$check(! str_contains($html, 'value="'.esc_attr(\Bootspruefung\Learning\Content\RightsRelease::RULES_REASON).'"') && ! str_contains($html, '{&quot;'), 'Regelwerke: Begründung nicht vorbelegt, Abweichung ohne JSON');
$rulesOk = true;
foreach ($wpdb->get_results("SELECT id FROM {$p}exam_rule") as $rule) {
    $current = \Bootspruefung\Learning\Content\Repositories\Overrides::get('exam_rule', (int) $rule->id, 'verified') ?? '';
    $rulesOk = $rulesOk && preg_match('#name="ids" value="'.(int) $rule->id.'".*?<option value="'.preg_quote($current, '#').'"\s+selected#s', $html) === 1;
}
$check($rulesOk, 'Regelwerk: aktueller Stand vorausgewählt');

echo "Einheitliche Begriffe im Admin (T100)\n";
require_once ABSPATH.'wp-admin/includes/template.php';
$terms = \Bootspruefung\Learning\Admin\Glossary::terms();
$check(count(array_intersect(['Zugang', 'Funktion', 'Umfang', 'Freischalten', 'Freigeben', 'Aussetzen', 'Widerrufen', 'Zeitversetzte Freischaltung', 'Lokale Abweichung', 'Inhaltspaket', 'Protokoll'], array_keys($terms))) === 11, 'Glossar: feste Begriffsliste');
$check(is_file(dirname(__DIR__, 3).'/docs/glossar.md') && str_contains((string) file_get_contents(dirname(__DIR__, 3).'/docs/glossar.md'), '**Zeitversetzte Freischaltung**'), 'Glossar auch in docs/glossar.md');
$t100Pages = [
    'Übersicht' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderOverview(),
    'Kurse' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderCourses(),
    'Fragen' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderQuestions(),
    'Prüfungsregeln' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderRules(),
    'Rechte' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderRights(),
    'Zugänge' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->render(),
    'Kostenlose Bereiche' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->renderFree(),
    'Gruppenlizenzen' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->renderGroups(),
    'Protokoll' => static fn () => (new \Bootspruefung\Learning\Admin\AuditPage())->render(),
];
$firstQuestion = (int) $wpdb->get_var("SELECT id FROM {$p}question ORDER BY id LIMIT 1");
if ($firstQuestion > 0) {
    $t100Pages['Frage'] = static function () use ($firstQuestion): void {
        $_GET['question'] = $firstQuestion;
        (new \Bootspruefung\Learning\Admin\ContentPages())->renderQuestions();
        unset($_GET['question']);
    };
}
$overviewHtml = '';
foreach ($t100Pages as $name => $renderPage) {
    ob_start();
    $renderPage();
    $html = (string) ob_get_clean();
    $overviewHtml = $name === 'Übersicht' ? $html : $overviewHtml;
    // Nur sichtbarer Text (ohne Attribute und Skripte).
    $visible = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    $found = preg_match_all('/\b(Entitlements?|Scopes?|Features?|Drip|Packages?|Releases?|Overrides?|Content-Pakete?|Audit-Log|live geschaltet|Live-Modus)\b|Kap\.\s*\d|Kapitel\s+\d|docs\//u', $visible, $hits);
    $check($found === 0, "{$name}: keine englischen Fachwörter, Kapitel- oder docs-Verweise".($found ? ' ('.implode(', ', array_unique($hits[0])).')' : ''));
}
$check(str_contains($overviewHtml, 'class="bp-glossary"') && str_contains($overviewHtml, 'Zeitversetzte Freischaltung'), 'Übersicht: Glossar aufklappbar');

echo "Einstellungen, Menü und Barrierefreiheit (T104)\n";
require_once ABSPATH.'wp-admin/includes/template.php';
// Kontrast der Badge-Farben mit weißer Schrift (WCAG AA ≥ 4,5:1).
$lum = static function (string $hex): float {
    $c = array_map(static fn ($x) => hexdec($x) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(static fn ($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$minContrast = min(array_map(static fn ($hex) => 1.05 / ($lum($hex) + 0.05), \Bootspruefung\Learning\Admin\Ui::BADGE_COLORS));
$check($minContrast >= 4.5, sprintf('Badges: Kontrast mindestens 4,5:1 (kleinster Wert %.2f)', $minContrast));
$badgeOk = true;
foreach (['published', 'draft', 'unknown', 'review', 'licensed', 'failed', 'gibt-es-nicht'] as $st) {
    preg_match('#background:(\#[0-9a-f]{6})#i', \Bootspruefung\Learning\Admin\Ui::status($st), $bg);
    $badgeOk = $badgeOk && isset($bg[1]) && 1.05 / ($lum($bg[1]) + 0.05) >= 4.5;
}
$check($badgeOk && str_contains(\Bootspruefung\Learning\Admin\Ui::status('unknown'), 'ungeklärt'), 'Status-Badges nutzen die Kontrastfarben und zeigen Text');
$check(\Bootspruefung\Learning\Admin\Ui::yesNo(true) === 'ja' && \Bootspruefung\Learning\Admin\Ui::yesNo(false) === 'nein', 'ja/nein statt ✓/✗');

// Menü-Reihenfolge
$menu = \Bootspruefung\Learning\Admin\Menu::class;
$entries = array_map(static fn ($slug) => ['x', 'cap', $slug], ['bootspruefung', 'bootspruefung-status', 'bootspruefung-settings', 'bootspruefung-access', 'bootspruefung-mobile', 'unbekannt-1', 'bootspruefung-audit', 'bootspruefung-import', 'bootspruefung-prices', 'bootspruefung-groups']);
$sorted = array_map(static fn ($e) => $e[2], $menu::sortEntries($entries));
$pos = array_flip($sorted);
$check($sorted[0] === 'bootspruefung' && $pos['bootspruefung-import'] < $pos['bootspruefung-access'] && $pos['bootspruefung-access'] < $pos['bootspruefung-groups'] && $pos['bootspruefung-groups'] < $pos['bootspruefung-prices']
    && $pos['bootspruefung-prices'] < $pos['bootspruefung-audit'] && $pos['bootspruefung-audit'] < $pos['bootspruefung-settings'] && $pos['bootspruefung-settings'] < $pos['bootspruefung-mobile'] && end($sorted) === 'bootspruefung-status',
    'Menü: Übersicht, Inhalte, Zugänge, Gruppen, Preise, Protokoll, Einstellungen, Mobile App, Systemstatus');
$check($pos['unbekannt-1'] < $pos['bootspruefung-audit'] && $pos['unbekannt-1'] > $pos['bootspruefung-prices'], 'unbekannte Seiten werden vor „Protokoll“ eingeordnet');

// Einstellungen: Bereich „Sicherheit“ mit den vertrauenswürdigen Proxys (Option unverändert)
$mobileBak = get_option(\Bootspruefung\Learning\Mobile\MobileSettings::OPTION, null);
update_option(\Bootspruefung\Learning\Mobile\MobileSettings::OPTION, array_merge(\Bootspruefung\Learning\Mobile\MobileSettings::all(), ['tenant_slug' => 't104-schule', 'trusted_proxies' => '']));
$count = $menu::saveTrustedProxies("10.0.0.0/8\nkein-proxy\n192.0.2.7");
$ms = \Bootspruefung\Learning\Mobile\MobileSettings::all();
$check($count === 2 && $ms['trusted_proxies'] === "10.0.0.0/8\n192.0.2.7" && $ms['tenant_slug'] === 't104-schule', 'Sicherheit: Proxys gespeichert (gleicher Schlüssel), übrige App-Einstellungen unverändert');
ob_start();
(new \Bootspruefung\Learning\Admin\Menu(\Bootspruefung\Learning\Plugin::instance()))->renderSettings();
$html = (string) ob_get_clean();
$secPos = strpos($html, 'id="bp-set-security"');
$check($secPos !== false && strpos($html, 'name="trusted_proxies"', (int) $secPos) !== false && str_contains($html, '10.0.0.0/8'), 'Einstellungen: Bereich „Sicherheit“ mit „Vertrauenswürdige Proxys“');
foreach (['bp-set-access', 'bp-set-display', 'bp-set-privacy', 'bp-set-disputes'] as $sec) {
    $check(str_contains($html, 'id="'.$sec.'"'), "Einstellungen: Bereich {$sec}");
}
$check(str_contains($html, 'name="bp_learning_settings[default_access]"') && str_contains($html, 'name="bp_learning_settings[expiry_reminder_days]"') && str_contains($html, 'name="bp_learning_settings[delete_data_on_uninstall]"'), 'Option-Schlüssel unverändert');
// Jedes Formularfeld hat eine Beschriftung (label for, umschließendes label oder aria-label).
$unlabeled = static function (string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $ids = [];
    foreach ($xp->query('//label[@for]') as $l) {
        $ids[$l->getAttribute('for')] = true;
    }
    $missing = [];
    foreach ($xp->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea') as $el) {
        if ($el->getAttribute('aria-label') !== '' || $el->getAttribute('aria-labelledby') !== '' || isset($ids[$el->getAttribute('id')]) || $xp->query('ancestor::label', $el)->length > 0
            || ($xp->query('ancestor::*[@role="group"][@aria-labelledby]', $el)->length > 0 && $xp->query('ancestor::label', $el)->length > 0)) {
            continue;
        }
        $missing[] = $el->nodeName.'['.$el->getAttribute('name').']';
    }

    return $missing;
};
$settingsUnlabeled = array_values(array_filter($unlabeled($html), static fn ($n) => ! str_contains($n, 'paypal') && ! str_contains($n, 'generic') && ! str_contains($n, 'dispute')));
$check($settingsUnlabeled === [], 'Einstellungen (Plugin-Felder): alle Felder beschriftet'.($settingsUnlabeled ? ' – fehlt: '.implode(', ', $settingsUnlabeled) : ''));
$t104Pages = [
    'Zugänge' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->render(),
    'Kostenlose Bereiche' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->renderFree(),
    'Gruppenlizenzen' => static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->renderGroups(),
    'Fragen' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderQuestions(),
    'Prüfungsregeln' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderRules(),
    'Rechte' => static fn () => (new \Bootspruefung\Learning\Admin\ContentPages())->renderRights(),
    'Protokoll' => static fn () => (new \Bootspruefung\Learning\Admin\AuditPage())->render(),
];
if (function_exists('wc_format_localized_decimal')) {
    $t104Pages['Mitgliederrabatte'] = static fn () => (new \Bootspruefung\Learning\Admin\AccessPages())->renderDiscounts();
}
$freeHtml = '';
foreach ($t104Pages as $name => $renderPage) {
    ob_start();
    $renderPage();
    $page = (string) ob_get_clean();
    $freeHtml = $name === 'Kostenlose Bereiche' ? $page : $freeHtml;
    $missing = $unlabeled($page);
    $check($missing === [], "{$name}: alle Formularfelder beschriftet".($missing ? ' – fehlt: '.implode(', ', array_slice($missing, 0, 5)) : ''));
    $check(! preg_match('/[✓✗]/u', wp_strip_all_tags(preg_replace('#<span aria-hidden="true">.*?</span>#su', '', $page))), "{$name}: kein ✓/✗ ohne Text");
}
$check(! str_contains($freeHtml, 'value="products"') && (! function_exists('wc_get_product_id_by_sku') || str_contains($freeHtml, 'page=bootspruefung-prices')), 'Katalog-Abgleich nur unter „Preise“ (Kostenlose Bereiche verlinkt dorthin)');
ob_start();
(new \Bootspruefung\Learning\Mobile\Admin\MobileAdminPage())->render();
$mobileHtml = (string) ob_get_clean();
$check(! str_contains($mobileHtml, 'bp_mobile[trusted_proxies]') && str_contains($mobileHtml, 'bp-set-security'), 'Mobile App: verweist auf Einstellungen › Sicherheit');
$mobileBak === null ? delete_option(\Bootspruefung\Learning\Mobile\MobileSettings::OPTION) : update_option(\Bootspruefung\Learning\Mobile\MobileSettings::OPTION, $mobileBak);
$wpdb->delete($p.'audit_log', ['action' => 'security_settings_saved', 'object_id' => \Bootspruefung\Learning\Mobile\MobileSettings::OPTION]);
$check(is_file(dirname(__DIR__, 2).'/assets/admin/admin.css'), 'Admin-CSS vorhanden');

echo "Paket-Import: Upload-Grenze, Vorprüfung vor dem Entpacken, Ablageordner (T96/T88)\n";
use Bootspruefung\Learning\Content\Import\ImportSources;
use Bootspruefung\Learning\Content\Media\MediaStorage;
use Bootspruefung\Learning\Content\Package\Package;

$work = sys_get_temp_dir().'/bp-t96-'.getmypid();
wp_mkdir_p($work);
$makeZip = static function (string $name, array $files, array $links = []) use ($work): string {
    $path = $work.'/'.$name;
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $entry => $content) {
        $zip->addFromString($entry, $content);
    }
    foreach ($links as $entry => $target) {
        $zip->addFromString($entry, $target);
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }
    $zip->close();

    return $path;
};
$manifest = ['manifest.json' => '{}'];
$rejects = [
    'Pfad außerhalb' => [$makeZip('traversal.zip', $manifest + ['media/../../evil.php' => 'x']), 'Unzulässiger Pfad'],
    'PHP-Datei in media/' => [$makeZip('php.zip', $manifest + ['media/shell.php' => '<?php']), 'Medium nicht erlaubt'],
    'Doppelendung .php.png' => [$makeZip('double.zip', $manifest + ['media/bild.php.png' => 'x']), 'Doppelte Endung'],
    'versteckte Datei' => [$makeZip('hidden.zip', $manifest + ['media/.htaccess' => 'x']), 'Medium nicht erlaubt'],
    'fremde Datei oben' => [$makeZip('extra.zip', $manifest + ['index.php' => '<?php']), 'Unerwartete Datei'],
    'symbolischer Link' => [$makeZip('link.zip', $manifest, ['media/link.png' => '/etc/passwd']), 'Symbolischer Link'],
    'ZIP-Bombe' => [$makeZip('bomb.zip', $manifest + ['data/questions.json' => str_repeat('0', 8 * 1024 * 1024)]), 'ZIP-Bombe'],
    'ohne manifest.json' => [$makeZip('nomanifest.zip', ['data/courses.json' => '[]']), 'manifest.json fehlt'],
];
foreach ($rejects as $label => [$zipPath, $needle]) {
    $target = $work.'/x-'.md5($label);
    $check($throws(static fn () => Package::extract($zipPath, $target), $needle) && ! is_dir($target), "abgelehnt vor dem Entpacken, nichts entpackt: {$label}");
}
$ok = $makeZip('ok.zip', $manifest + ['data/courses.json' => '[]', 'media/elwis/bild.png' => 'png']);
$check(Package::inspect($ok) instanceof ZipArchive, 'gültige Struktur besteht die Vorprüfung');
$before = glob(MediaStorage::packagesDir().'/upload-*') ?: [];
$check($throws(static fn () => $service->createFromZip($rejects['PHP-Datei in media/'][0]), 'Medium nicht erlaubt') && (glob(MediaStorage::packagesDir().'/upload-*') ?: []) === $before, 'createFromZip: abgelehntes Paket hinterlässt keine Teilentpackung');
$dirPkg = $work.'/dirpkg';
wp_mkdir_p($dirPkg.'/media');
file_put_contents($dirPkg.'/manifest.json', '{}');
file_put_contents($dirPkg.'/media/x.phtml', '<?php');
$check($throws(static fn () => Package::inspectDirectory($dirPkg), 'Medium nicht erlaubt'), 'Paketordner mit .phtml abgelehnt');
unlink($dirPkg.'/media/x.phtml');
symlink('/etc/passwd', $dirPkg.'/media/link.png');
$check($throws(static fn () => Package::inspectDirectory($dirPkg), 'Symbolischer Link'), 'Paketordner mit symbolischem Link abgelehnt');
unlink($dirPkg.'/media/link.png');

$limit = ImportSources::uploadLimit();
$check($limit > 0 && $limit <= Package::MAX_ZIP_BYTES && $limit <= (int) wp_max_upload_size(), 'Upload-Grenze = kleinster Wert (PHP, WordPress, 200 MB)');
$check(ImportSources::postTooLarge(20 * 1024 * 1024, 8 * 1024 * 1024) && ! ImportSources::postTooLarge(1024, 8 * 1024 * 1024) && ! ImportSources::postTooLarge(20 * 1024 * 1024, 0), 'zu große Anfrage (CONTENT_LENGTH > post_max_size) erkannt');
$msg = (string) ImportSources::uploadErrorMessage(UPLOAD_ERR_INI_SIZE, 11 * 1024 * 1024);
$check(str_contains($msg, 'zu groß') && str_contains($msg, ImportSources::DROP_FOLDER) && str_contains($msg, 'wp bootspruefung import') && str_contains($msg, 'post_max_size'), 'Meldung „zu groß“ nennt Ablageordner, WP-CLI und Servergrenze');
$check(ImportSources::uploadErrorMessage(UPLOAD_ERR_FORM_SIZE) === ImportSources::tooLargeMessage() && ImportSources::uploadErrorMessage(UPLOAD_ERR_OK) === null && str_contains((string) ImportSources::uploadErrorMessage(UPLOAD_ERR_PARTIAL), 'teilweise'), 'Upload-Fehlercodes verständlich');

$drop = ImportSources::dropDir();
$check(is_dir($drop) && is_file($drop.'/.htaccess') && is_file($drop.'/index.php'), 'Ablageordner angelegt und für Besucher gesperrt');
copy($ok, $drop.'/t96-paket.zip');
file_put_contents($drop.'/notizen.txt', 'x');
symlink($ok, $drop.'/t96-link.zip');
$names = array_column(ImportSources::dropItems(), 'name');
$check(in_array('t96-paket.zip', $names, true) && ! in_array('notizen.txt', $names, true) && ! in_array('t96-link.zip', $names, true), 'Liste: nur ZIP-Dateien und Paketordner, keine Links');
$check(ImportSources::resolveDrop('t96-paket.zip') === realpath($drop.'/t96-paket.zip'), 'Paket im Ablageordner auflösbar');
foreach (['../wp-config.php', '../../wp-config.php', 'sub/../t96-paket.zip', 'notizen.txt', 't96-link.zip', '.htaccess', 'fehlt.zip', ''] as $bad) {
    $check($throws(static fn () => ImportSources::resolveDrop($bad)), 'Ablageordner: abgelehnt „'.$bad.'“');
}
unlink($drop.'/t96-paket.zip');
unlink($drop.'/notizen.txt');
unlink($drop.'/t96-link.zip');
$check($throws(static fn () => ImportSources::download('http://example.com/paket.zip'), 'https') && $throws(static fn () => ImportSources::download('https://127.0.0.1/paket.zip')) && $throws(static fn () => ImportSources::download('file:///etc/passwd'), 'https'), 'Link: nur https, keine internen Adressen');
$check((glob(MediaStorage::packagesDir().'/download-*') ?: []) === [], 'Link: keine temporären Dateien liegen geblieben');

ob_start();
require_once ABSPATH.'wp-admin/includes/template.php';
(new \Bootspruefung\Learning\Admin\ImportPage())->render();
$html = (string) ob_get_clean();
$check(str_contains($html, 'Höchstgröße zum Hochladen auf diesem Server: '.size_format($limit)) && str_contains($html, 'data-max-bytes="'.$limit.'"') && str_contains($html, 'action=bp_import_upload'), 'Seite zeigt die Upload-Grenze, Formular trägt die Aktion in der Adresse');
$check(str_contains($html, 'Ohne Hochladen importieren') && str_contains($html, ImportSources::DROP_FOLDER) && str_contains($html, 'bp_import_url') && str_contains($html, 'wp bootspruefung import'), 'Seite bietet Ablageordner, Link und WP-CLI an');
$js = (string) file_get_contents(dirname(__DIR__, 2).'/assets/admin/import.js');
$check(str_contains($js, 'data-max-bytes') && str_contains($js, 'AbortController') && str_contains($js, 'text.http') && ! preg_match('/[\'"](Error|Import running|failed)[\'"]/', $js), 'import.js: Größenprüfung, Zeitüberschreitung, HTTP-Fehler; Texte aus PHP (deutsch)');
ImportService::removeDir($work);

echo "Markenname (T10)\n";
$settingsBak = get_option(\Bootspruefung\Learning\Settings\Settings::OPTION);
$blogBak = get_option('blogname');
update_option('blogname', 'Wassertest');
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge(\Bootspruefung\Learning\Settings\Settings::all(), ['brand_name' => 'XXXX']));
$check(\Bootspruefung\Learning\Settings\Settings::brand() === 'Wassertest' && ! \Bootspruefung\Learning\Settings\Settings::brandIsSet(), 'alter Platzhalter „XXXX“ gespeichert: Name der Website statt XXXX');
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge(\Bootspruefung\Learning\Settings\Settings::all(), ['brand_name' => 'Kurswasser']));
$check(\Bootspruefung\Learning\Settings\Settings::brand() === 'Kurswasser' && \Bootspruefung\Learning\Settings\Settings::brandIsSet(), 'eigener Markenname gewinnt');
$check(\Bootspruefung\Learning\Settings\Settings::sanitize(['brand_name' => 'XXXX'])['brand_name'] === '' && \Bootspruefung\Learning\Settings\Settings::sanitize(['brand_name' => ''])['brand_name'] === '', 'Speichern: leer bzw. XXXX = nicht festgelegt');
update_option('blogname', '');
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, array_merge(\Bootspruefung\Learning\Settings\Settings::all(), ['brand_name' => '']));
$check(\Bootspruefung\Learning\Settings\Settings::brand() === 'XXXX', 'ohne Markenname und Website-Namen: Platzhalter');
update_option('blogname', $blogBak);
update_option(\Bootspruefung\Learning\Settings\Settings::OPTION, $settingsBak);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
