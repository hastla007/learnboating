<?php

declare(strict_types=1);

/*
 * Integrationstest „Prüfung anmelden“ (Anleitung + Prüfungsausschüsse, Selbstanmeldung):
 * Import aus dem Paket, Freischaltung, Seiten je Kurs, Umfang/Bestehensgrenze wie
 * ExamBooking der Quelle, Prüfungsausschüsse, Schutz lokaler Änderungen.
 *
 *   WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php tests/integration/exam-guide-test.php
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$packageDir = rtrim((string) getenv('PACKAGE_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';

use Bootspruefung\Learning\Content\Import\EntityImporter;
use Bootspruefung\Learning\Content\Package\Package;
use Bootspruefung\Learning\ExamGuide\ExamGuideService;
use Bootspruefung\Learning\Settings\Settings;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';
wp_set_current_user(1);
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['exam_guide_enabled' => false]));
$service = new ExamGuideService();

echo "Inhalte\n";
// Tipps & Tricks (Starttexte)
\Bootspruefung\Learning\Tips\Tips::maybeSeed();
$tips = \Bootspruefung\Learning\Tips\Tips::payload();
$tipText = wp_json_encode($tips, JSON_UNESCAPED_UNICODE);
$check(array_column($tips, 'name') === ['Lernen mit der Plattform', 'Prüfungsvorbereitung', 'Anmeldung und Prüfungstag'] && array_sum(array_map(static fn ($g) => count($g['tips']), $tips)) === 13, 'Tipps: 3 Kategorien, 13 Starttexte');
$check(! preg_match('/€|\bEuro\b|\d+\s*(Jahre|Tage vor|Wochen vor)|Bootsschule/u', $tipText), 'Tipps ohne Gebühren, Altersgrenzen, Fristen oder Bootsschul-Bezug');
$prevUser = get_current_user_id();
wp_set_current_user(0);
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/tips'))->get_status() === 401, 'Tipps nur angemeldet');
wp_set_current_user($prevUser);
$check(count($service->guides()) === 6, '6 Anleitungen (SRC, UBI, SBF See, SBF Binnen, FKN, SKN)');
$check(count($service->committees()) === 18, '18 Prüfungsausschüsse');

echo "Freischaltung\n";
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see/pruefung-anmelden'))->get_status() === 404, 'deaktiviert: REST 404');
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data()['exam_guide'] === null, 'deaktiviert: keine Kachel');
update_option(Settings::OPTION, array_merge(Settings::all(), ['exam_guide_enabled' => true]));
$check(rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see'))->get_data()['exam_guide']['tabs'] === ['SBF See'], 'freigeschaltet: Kachel mit Reiter');

echo "Seiten und Prüfungsformen\n";
$combo = rest_do_request(new WP_REST_Request('GET', '/bootspruefung/v1/courses/sbf-see-bin/pruefung-anmelden'))->get_data();
$check(array_column($combo['tabs'], 'key') === ['SBF-SEE', 'SBF-BIN'], 'Kombikurs: Reiter SBF See und SBF Binnen');
$check($combo['tabs'][1]['variants'][0]['key'] === 'combo', 'Binnen-Reiter beginnt mit der Kombi-Variante');
$see = $service->payload('SBF-SEE')['tabs'][0];
$full = $see['variants'][0];
$check($full['key'] === 'full' && $full['scope'] === '30 Fragen: 7 Basisfragen aus 72, 23 See-Fragen aus 213', 'Umfang wie die Quelle (ohne freie Kartenaufgaben): '.$full['scope']);
$check($full['pass_text'] === 'Basisfragen mind. 5 von 7 und See-Fragen mind. 18 von 23', 'Bestehensgrenze mehrteilig');
$check($service->payload('ADD-BIN-TO-SEE')['tabs'][0]['variants'][0]['key'] === 'addon', 'Ergänzungskurs: Ergänzungsprüfung zuerst');
$src = $service->payload('SRC')['tabs'][0]['variants'][0];
$check($src['pass_text'] === 'ab 19 richtigen (max. 5 Fehler)' && $src['scope'] === '24 Fragen aus 180', 'SRC: 24 Fragen aus 180, ab 19 richtigen');
$fkn = $service->payload('FKN')['tabs'][0];
$check($fkn['key'] === 'FKN' && str_starts_with($fkn['variants'][0]['pass_text'], 'mind. 24 von 30 Punkten'), 'FKN: Punkteregel der Quelle');
$skn = $service->payload('ADD-FKN-TO-SKN')['tabs'][0]['variants'][0];
$check($skn['key'] === 'addon' && $skn['pass_text'] === null && str_starts_with($skn['scope'], 'Fragen aus dem amtlichen Katalog'), 'SKN für FKN-Inhaber: ohne Bestehensgrenze');
$check(count($see['faq']) >= 5 && $see['fee'] === '148,38 €' && count($see['providers']) === 2, 'FAQ, Gebühr, DSV/DMYV');
$bin = $service->payload('SBF-BIN-MOTOR')['tabs'][0];
$fknTab = $service->payload('FKN')['tabs'][0];
$allText = wp_json_encode(array_map(static fn ($g) => $g['faq'], $service->guides()), JSON_UNESCAPED_UNICODE);
$check($bin['fee'] === '130,78 €' && str_contains(wp_json_encode($bin['faq'], JSON_UNESCAPED_UNICODE), 'ca. 130,78 €') && $fknTab['fee'] === '28,89 €', 'Gebühren korrigiert: SBF Binnen 130,78 €, FKN 28,89 €');
$check(! str_contains($allText, 'Bootsschule') && str_contains($allText, 'nicht über diese Lernplattform') && str_contains((string) $see['fee_note'], 'Stand November 2026'), 'kein Bootsschul-Bezug, Gebühr mit Stand');
$funk = $service->committees('SRC');
$funkIdx = array_search('Postanschrift Funk (UBI/SRC/LRC)', array_column($funk, 'location_type'), true);
$sbfIdx = array_search('Geschäftsstelle SBF/SKS/FKN', array_column($funk, 'location_type'), true);
$check($funkIdx === 0 && $sbfIdx === count($funk) - 1, 'SRC: Funk-Anschrift zuerst, SBF/FKN-Stelle zuletzt');

echo "Prüfungsausschüsse\n";
$first = $service->committees()[3];
$wpdb->update($p.'exam_committee', ['course_codes' => 'SBF-SEE'], ['id' => $first['id']]);
$list = $service->committees('SBF-SEE');
$check($list[0]['id'] === $first['id'] && $list[0]['matches'], 'für den Kurs gepflegte Ausschüsse zuerst');
$check($service->committees('SRC')[0]['id'] !== $first['id'], 'andere Kurse unverändert sortiert');
$wpdb->update($p.'exam_committee', ['phone' => '+49 000 LOKAL', 'locally_modified' => 1], ['id' => $first['id']]);
$package = new Package($packageDir);
$sourceId = $wpdb->get_var($wpdb->prepare("SELECT source_id FROM {$p}exam_committee WHERE id = %d", $first['id']));
$record = array_values(array_filter($package->entities('exam_committees'), static fn ($c) => $c['source_id'] === $sourceId))[0];
$record['hash'] = 'sha256:'.str_repeat('0', 64);
$importer = new EntityImporter($wpdb, $package, 'test', false);
$check($importer->import('exam_committees', $record) === 'unchanged' && $wpdb->get_var("SELECT phone FROM {$p}exam_committee WHERE id = {$first['id']}") === '+49 000 LOKAL', 'lokale Änderung übersteht Reimport');
$wpdb->update($p.'exam_committee', ['phone' => $record['phone'], 'course_codes' => '', 'locally_modified' => 0], ['id' => $first['id']]);

update_option(Settings::OPTION, $settingsBefore);
echo "Bundesländer der Prüfungsausschüsse (Karte)\n";
$check(\Bootspruefung\Learning\ExamGuide\ExamGuideService::statesFor('Prüfungszentrum Brandenburg, Sachsen, Sachsen-Anhalt und Thüringen Nord') === ['DE-BB', 'DE-ST', 'DE-SN', 'DE-TH'], 'Brandenburg … Thüringen Nord: 4 Länder, Sachsen und Sachsen-Anhalt getrennt');
$check(\Bootspruefung\Learning\ExamGuide\ExamGuideService::statesFor('Prüfungsausschuss Mannheim-Darmstadt/Saar-Mosel') === ['DE-BW', 'DE-RP', 'DE-HE', 'DE-SL'], 'Mannheim-Darmstadt/Saar-Mosel: BW, RP, HE, SL');
$allStates = [];
foreach ((new \Bootspruefung\Learning\ExamGuide\ExamGuideService())->committees() as $cm) {
    $allStates = array_merge($allStates, $cm['states']);
}
$check(count(array_unique($allStates)) === 16, 'alle 16 Bundesländer haben mindestens einen Prüfungsausschuss');
echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
