<?php

declare(strict_types=1);

/*
 * Automatische Prüfung im Navigationstrainer (NavigationCheck) und Markieren in der
 * Prüfungssimulation (Web-REST):
 * - Leser/Prüfer mit echten Lösungswerten aus dem Paket (viele Schreibweisen, Grenzfälle);
 * - Import: Spalte bp_navigation_question.answer_values (Schema 1.12.0) wie im Paket;
 * - Web-REST: check nach eigener Antwort, Sollwerte erst nach Aufdecken, Selbsteinschätzung
 *   unverändert, Teilaufgaben ohne Werte wie bisher;
 * - Web-REST: PUT /exam-sessions/{id}/answers/{position}/flag, Fortsetzen, nach Abgabe gesperrt,
 *   Ergebnis mit Markierungen.
 *
 *   WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php tests/integration/navigation-check-test.php
 *
 * Voraussetzung: Plugin aktiv (smoke-test.php). Nur gegen eine Test-Datenbank.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$packageDir = rtrim((string) getenv('PACKAGE_DIR'), '/');
if (! is_file($wpDir.'/wp-load.php') || ! is_file($packageDir.'/manifest.json')) {
    fwrite(STDERR, "WP_DIR und PACKAGE_DIR angeben.\n");
    exit(2);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';

use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Trainers\NavigationCheck;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';

// Lösungswerte aus dem Paket (Quelle der Wahrheit für die Prüfung).
$values = [];
$answers = [];
foreach (json_decode((string) file_get_contents($packageDir.'/data/navigation_tasks.json'), true) as $task) {
    foreach ($task['questions'] as $q) {
        $answers[$q['source_id']] = $q['answer_text'];
        if (isset($q['answer_values'])) {
            $values[$q['source_id']] = $q['answer_values'];
        }
    }
}

echo "Leser und Prüfer (NavigationCheck)\n";
$check(count($values) === 78, '78 Teilaufgaben mit Lösungswerten im Paket');
// [Teilaufgabe, Eingabe, erwarteter Status, erwartete ok je Wert]
$cases = [
    ['NAV-01-01', 'rwK 079°', 'within', [true]],
    ['NAV-01-01', '79', 'within', [true]],
    ['NAV-01-01', 'rwk=81', 'within', [true]],
    ['NAV-01-01', 'rwK = 82°', 'deviates', [false]],
    ['NAV-01-01', '079,5°', 'within', [true]],
    ['NAV-01-02', 'MgK = 074°', 'within', [true]],
    ['NAV-01-02', 'MgK = 079° - 1° - 4° = 074°', 'within', [true]],
    ['NAV-01-02', '079 - 1 - 4 = 074', 'within', [true]],
    ['NAV-01-02', 'MgK 075° *)', 'within', [true]],
    ['NAV-01-03', '6,1 sm', 'within', [true]],
    ['NAV-01-03', '6.3sm', 'within', [true]],
    ['NAV-01-03', '6,4 Seemeilen', 'deviates', [false]],
    ['NAV-01-03', '6,1', 'within', [true]],
    ['NAV-01-04', '46 min', 'within', [true]],
    ['NAV-01-04', '45,75 min., also 46 min.', 'within', [true]],
    ['NAV-01-04', '0:46', 'within', [true]],
    ['NAV-01-04', '0,75 h', 'within', [true]],
    ['NAV-01-04', '50 Minuten', 'deviates', [false]],
    ['NAV-01-05', "53° 55,8' N 007° 51,3' E", 'within', [true]],
    ['NAV-01-05', "53°55,8'N 7°51,3'E", 'within', [true]],
    ['NAV-01-05', '53 55.8 N 007 51.3 E', 'within', [true]],
    ['NAV-01-05', "N 53° 56,1' E 007° 51,0'", 'within', [true]],
    ['NAV-01-05', "53° 55,8’ N 007° 51,3’ O", 'within', [true]],
    ['NAV-01-05', "53° 56,2' N 007° 51,3' E", 'deviates', [false]],
    ['NAV-01-05', "53° 55,8' S 007° 51,3' E", 'deviates', [false]],
    ['NAV-01-06', 'Leuchttonne "4a" rwP = 170° Leuchttonne "ST" rwP = 065°', 'within', [true, true]],
    ['NAV-01-06', 'ST 065°, 4a 171°', 'within', [true, true]],
    ['NAV-01-06', 'rwP = 170° 4a, rwP = 065° ST', 'within', [true, true]],
    ['NAV-01-06', '170 65', 'within', [true, true]],
    ['NAV-01-06', '4a 170°', 'deviates', [true, false]],
    ['NAV-01-08', 'BV = 103° - 0,8 sm', 'within', [true, true]],
    ['NAV-01-08', '103 0,8', 'within', [true, true]],
    ['NAV-01-08', '0,8 sm in 103°', 'within', [true, true]],
    ['NAV-01-08', 'BV 101°, 1,1 sm', 'deviates', [true, false]],
    ['NAV-02-09', 'BV = 359° - 1,6 sm', 'deviates', [false, true]],
    ['NAV-02-09', 'BV 005° 1,7 sm', 'within', [true, true]],
    ['NAV-03-05', '15:12', 'within', [true]],
    ['NAV-03-05', '15.14 Uhr', 'within', [true]],
    ['NAV-03-05', '1510 Uhr', 'within', [true]],
    ['NAV-03-05', '15:16 Uhr', 'deviates', [false]],
    ['NAV-03-07', 'Tonne "TG15" rwP = 290° Tonne "TG17/Weser1" rwP = 345° Tonne "Accumer Ee" rwP = 232°', 'within', [true, true, true]],
    ['NAV-03-07', 'Accumer Ee 232°, TG15 290°, TG17 345°', 'within', [true, true, true]],
    ['NAV-03-07', '290 345 232', 'within', [true, true, true]],
    ['NAV-04-08', 'Tonne 035°, Kirche 115°', 'within', [true, true]],
    ['NAV-06-08', 'FüG = 6 kn', 'within', [true]],
    ['NAV-06-08', '6,2 Knoten', 'within', [true]],
    ['NAV-06-08', '6.4kn', 'deviates', [false]],
    ['NAV-08-08', 'Zeitraum = 20 min.', 'within', [true]],
    ['NAV-09-01', 'Gegen 13:00 Uhr', 'within', [true]],
    ['NAV-09-01', '12:58', 'within', [true]],
    ['NAV-09-06', 'Alte Weser 205°, Neuwerk 096°', 'within', [true, true]],
    ['NAV-10-06', '12 kn', 'within', [true]],
    ['NAV-10-08', 'ST 227° NGN 112°', 'within', [true, true]],
    ['NAV-11-08', 'rwP = 012° LT "Alte Weser" rwP = 103° LT "Tegeler Plate"', 'within', [true, true]],
    ['NAV-12-03', '359°', 'deviates', [false]],
    ['NAV-12-03', '9', 'within', [true]],
    ['NAV-12-03', '005°', 'within', [true]],
    ['NAV-13-01', 'Düne-S 008°, Helgoland-O 284°', 'within', [true, true]],
    ['NAV-14-08', 'rwP = 022° Süderpiep rwP = 100° Norderelbe', 'within', [true, true]],
    ['NAV-01-01', 'keine Ahnung', 'unrecognized', [false]],
    // FACH-15: gängige Schreibweisen
    ['NAV-01-05', "Breite 53° 55,8' N Länge 7° 51,3' O", 'within', [true]],
    ['NAV-01-05', "φ = 53°55,8' N, λ = 007°51,3' E", 'within', [true]],
    ['NAV-01-05', '53-55,8N 007-51,3E', 'within', [true]],
    ['NAV-01-04', '3/4 Stunde', 'within', [true]],
    ['NAV-01-04', 'in einer ¾ h', 'within', [true]],
    ['NAV-01-04', '1/2 Stunde', 'deviates', [false]],
    ['NAV-01-02', '74° (rwK 79°)', 'within', [true]],
    ['NAV-01-02', 'rwK 79°, MgK 74°', 'within', [true]],
    ['NAV-01-06', 'MgP 168 + 2 = rwP 170; ST 063 + 2 = 065', 'within', [true, true]],
];
foreach ($cases as [$id, $input, $status, $oks]) {
    $r = NavigationCheck::check($values[$id], $input, true);
    $got = array_column($r['values'], 'ok');
    $check($r['status'] === $status && $got === $oks, sprintf('%s „%s“ → %s (%s)', $id, $input, $r['status'], implode(', ', array_map(static fn ($v) => $v['label'].': '.($v['given'] ?? '–'), $r['values']))));
}
// Jede amtliche Lösung, so eingegeben, liegt im Toleranzbereich (Lösungswerte passen zum angezeigten Text).
$official = 0;
foreach ($values as $id => $v) {
    $official += NavigationCheck::check($v, $answers[$id], true)['status'] === 'within' ? 1 : 0;
}
$check($official === count($values), "amtliches Ergebnis als Eingabe: {$official}/".count($values).' im Toleranzbereich');
// Gradzahlen über 360° hinweg und Toleranzgrenzen.
$north = [['label' => 'rwK', 'quantity' => 'course', 'value' => 359, 'unit' => '°', 'tolerance' => 2]];
$check(NavigationCheck::check($north, '001°', true)['values'][0]['ok'] && NavigationCheck::check($north, '001°', true)['values'][0]['deviation'] == 2, '359° ↔ 001°: Abweichung 2° (über 360° hinweg)');
$check(! NavigationCheck::check($north, '002', true)['values'][0]['ok'] && NavigationCheck::check($north, '356', true)['values'][0]['deviation'] == -3, 'Grenzen ±2° über 360° hinweg');
$check(NavigationCheck::check($north, '360°', true)['values'][0]['given'] === '000°', '360° wird als 000° gelesen');
$r = NavigationCheck::check($values['NAV-01-01'], 'rwK 080°', false);
$check(! array_key_exists('expected', $r['values'][0]) && $r['values'][0]['deviation'] == 1, 'ohne Aufdecken keine Sollwerte');
$check(NavigationCheck::check($values['NAV-01-01'], '   ', true) === null && NavigationCheck::check([], 'rwK 079°', true) === null, 'leere Antwort oder keine Werte → keine Prüfung');
$check(NavigationCheck::decode('kaputt') === [] && NavigationCheck::decode(null) === [] && count(NavigationCheck::decode((string) wp_json_encode($values['NAV-01-08']))) === 2, 'decode: ungültig → leer');
$arrival = [
    ['label' => 'Ankunft', 'quantity' => 'time', 'value' => '15:12', 'unit' => 'Uhr', 'tolerance' => 3],
    ['label' => 'Fahrzeit', 'quantity' => 'duration', 'value' => 102, 'unit' => 'min', 'tolerance' => 3],
];
$r = NavigationCheck::check($arrival, 'Ankunft 15:12, Fahrzeit 1:42 h', true);
$check($r['status'] === 'within' && array_column($r['values'], 'given') === ['15:12 Uhr', '102 min'], '„Fahrzeit 1:42 h“ als Dauer, „15:12“ als Uhrzeit (FACH-15)');
$tokens = NavigationCheck::parse('Tonne "TG17/Weser1" 345°, 1b/Jade1 4a');
$check(count($tokens) === 1 && $tokens[0]['value'] == 345.0, 'Ziffern in Namen (TG17, Weser1, 1b, 4a) zählen nicht als Werte');

echo "Import (Schema 1.12.0, Spalte answer_values)\n";
Plugin::instance()->maybeMigrate();
$check(get_option(Migrator::OPTION_VERSION) === Migrator::SCHEMA_VERSION && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$p}navigation_question LIKE 'answer_values'"), 'Migration: Spalte navigation_question.answer_values');
wp_set_current_user(1);
$service = Plugin::instance()->importService();
$id = $service->createFromDirectory($packageDir);
$service->preview($id);
$service->approve($id, 'Integrationstest Navigation');
$service->runAll($id);
$check($service->batch($id)->status === 'published', 'Paket importiert (idempotent)');
$stored = [];
foreach ($wpdb->get_results("SELECT source_id, answer_values FROM {$p}navigation_question WHERE answer_values IS NOT NULL AND status <> 'source_archived'") as $row) {
    $stored[$row->source_id] = json_decode($row->answer_values, true);
}
ksort($stored);
ksort($values);
$check($stored == $values, '78 Teilaufgaben mit denselben Werten wie im Paket gespeichert');
$check($wpdb->get_var("SELECT answer_values FROM {$p}navigation_question WHERE source_id = 'NAV-01-07'") === null, '„Siehe Karte“ ohne Werte (NULL)');
$coverage = \Bootspruefung\Learning\Trainers\NavigationCheck::coverage();
$check($coverage['total'] === 135 && $coverage['with'] > 0 && $coverage['with'] < 135, 'Statusseite: Teilaufgaben mit Lösungswerten gezählt ('.$coverage['with'].' von '.$coverage['total'].')');

echo "Web-REST: Navigation\n";
$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'logged_in']));
$login = 'navcheck'.wp_rand(1000, 9999);
$uid = (int) wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => wp_generate_password()]);
wp_set_current_user($uid);
do_action('rest_api_init');
$req = static function (string $method, string $path, array $body = []): array {
    $r = new WP_REST_Request($method, '/bootspruefung/v1'.$path);
    $method === 'GET' ? $r->set_query_params($body) : $r->set_body_params($body);
    $res = rest_do_request($r);

    return [$res->get_status(), $res->get_data()];
};
[, $tasks] = $req('GET', '/courses/SBF-SEE/navigation');
[$s, $task] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
$parts = array_column($task['questions'], null, 'number');
$check($s === 200 && $parts[1]['auto_check'] === true && $parts[7]['auto_check'] === false && $parts[1]['check'] === null && $parts[1]['answer'] === null, 'Aufgabe: auto_check, noch keine Prüfung und keine Lösung');
[$s, $a] = $req('POST', '/courses/SBF-SEE/navigation-questions/'.$parts[1]['id'], ['answer' => 'rwK = 078°']);
$check($s === 200 && $a['auto_check'] === true && $a['check']['status'] === 'within' && $a['check']['values'][0]['expected'] === '079°' && $a['check']['values'][0]['given'] === '078°' && $a['answer'] === 'rwK = 079°', 'eigene Antwort: Prüfung im Toleranzbereich, amtliches Ergebnis');
$check($a['self_assessment'] === null && $a['done'] === false, 'Prüfung setzt keine Selbsteinschätzung');
[$s, $a] = $req('POST', '/courses/SBF-SEE/navigation-questions/'.$parts[1]['id'], ['answer' => 'rwK = 085°', 'self_assessment' => 'partly']);
$check($a['check']['status'] === 'deviates' && $a['check']['values'][0]['deviation'] == 6 && $a['self_assessment'] === 'partly', 'Abweichung gemeldet, Selbsteinschätzung (teilweise) gilt');
$saved = $wpdb->get_row($wpdb->prepare("SELECT answer_text, self_assessment, done FROM {$p}navigation_progress WHERE user_id = %d AND navigation_question_id = %d", $uid, $parts[1]['id']));
$check($saved && $saved->answer_text === 'rwK = 085°' && $saved->self_assessment === 'partly' && (int) $saved->done === 1, 'gespeichert wie bisher (Antwort, Einschätzung, erledigt)');
[, $a] = $req('POST', '/courses/SBF-SEE/navigation-questions/'.$parts[7]['id'], ['answer' => 'eingetragen']);
$check($a['auto_check'] === false && $a['check'] === null && $a['answer'] === 'Siehe Karte', 'Teilaufgabe ohne Werte: wie bisher');
[, $a] = $req('POST', '/courses/SBF-SEE/navigation-questions/'.$parts[2]['id'], []);
$check($a['check'] === null && $a['answer'] === 'MgK = 074° *)', 'Aufdecken ohne eigene Antwort: keine Prüfung');
[, $task] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
$parts = array_column($task['questions'], null, 'number');
$check($parts[1]['check']['status'] === 'deviates' && $parts[2]['check'] === null && $parts[7]['check'] === null, 'Aufgabe liefert die Prüfung der gespeicherten Antwort');

echo "Punktestand der Navigationsaufgabe (FACH-10)\n";
$check($parts[7]['chart_entry'] === true && $parts[1]['chart_entry'] === false, '„Siehe Karte“: Teilaufgabe als Karteneintragung gekennzeichnet');
$check($task['score'] === ['points' => 0, 'max' => 9, 'pass_points' => 7, 'assessed' => 1, 'partly' => 1, 'complete' => false, 'passed' => null], 'Punktestand: „teilweise“ zählt nicht, 7 von 9 zum Bestehen, Ergebnis erst nach allen Einschätzungen');
foreach ($parts as $n => $part) {
    $req('POST', '/courses/SBF-SEE/navigation-questions/'.$part['id'], ['self_assessment' => $n <= 7 ? 'correct' : 'wrong']);
}
[, $task] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
$check($task['score']['points'] === 7 && $task['score']['complete'] === true && $task['score']['passed'] === true, '7 von 9 richtig: bestanden');
$req('POST', '/courses/SBF-SEE/navigation-questions/'.$parts[7]['id'], ['self_assessment' => 'partly']);
[, $task] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
$check($task['score']['points'] === 6 && $task['score']['passed'] === false, '6 von 9 (eine nur teilweise): nicht bestanden');
$check(\Bootspruefung\Learning\Trainers\TrainerService::navigationScore(['correct', 'correct'])['pass_points'] === null, 'ohne 9 Teilaufgaben keine Bestehensgrenze');

echo "Web-REST: Prüfungsfragen markieren\n";
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$sid = (int) ($d['session_id'] ?? 0);
$check($s === 201 && $sid > 0, 'Prüfung gestartet');
[$s, $f] = $req('PUT', "/exam-sessions/{$sid}/answers/3/flag", ['flagged' => true]);
$check($s === 200 && $f === ['position' => 3, 'flagged' => true, 'flagged_count' => 1], 'Frage 3 markiert (flagged_count 1)');
[, $f] = $req('PUT', "/exam-sessions/{$sid}/answers/5/flag", ['flagged' => true]);
[, $f2] = $req('PUT', "/exam-sessions/{$sid}/answers/5/flag", ['flagged' => true]);
$check($f['flagged_count'] === 2 && $f2['flagged_count'] === 2, 'zweite Markierung, Wiederholung idempotent');
[$s] = $req('PUT', "/exam-sessions/{$sid}/answers/99/flag", ['flagged' => true]);
$check($s === 404, 'unbekannte Position → 404');
[$s] = $req('PUT', "/exam-sessions/{$sid}/answers/3/flag", []);
$check($s === 400, 'ohne flagged → 400');
[, $e] = $req('GET', "/exam-sessions/{$sid}");
$flagged = array_values(array_map(static fn ($q) => $q['position'], array_filter($e['questions'], static fn ($q) => $q['flagged'])));
$check($flagged === [3, 5], 'Fortsetzen: Markierungen wiederhergestellt');
[, $f] = $req('PUT', "/exam-sessions/{$sid}/answers/5/flag", ['flagged' => false]);
$check($f['flagged'] === false && $f['flagged_count'] === 1, 'Markierung entfernt');
$other = (int) wp_insert_user(['user_login' => $login.'x', 'user_email' => $login.'x@example.invalid', 'user_pass' => wp_generate_password()]);
wp_set_current_user($other);
[$s] = $req('PUT', "/exam-sessions/{$sid}/answers/3/flag", ['flagged' => false]);
$check($s === 404, 'fremde Prüfung → 404');
wp_set_current_user($uid);
[$s, $e] = $req('POST', "/exam-sessions/{$sid}/submit");
$resultFlags = array_values(array_map(static fn ($q) => $q['position'], array_filter($e['result']['questions'], static fn ($q) => $q['flagged'])));
$check($e['status'] === 'evaluated' && $resultFlags === [3], 'Ergebnis zeigt, welche Fragen markiert waren');
[$s, $f] = $req('PUT', "/exam-sessions/{$sid}/answers/4/flag", ['flagged' => true]);
$check($s === 422, 'nach Abgabe nicht mehr markierbar (422)');
wp_set_current_user(1);

echo "Aufräumen\n";
require_once ABSPATH.'wp-admin/includes/user.php';
$wpdb->query("DELETE FROM {$p}exam_session_question WHERE session_id IN (SELECT id FROM {$p}exam_session WHERE user_id IN ({$uid}, {$other}))");
$wpdb->query("DELETE FROM {$p}exam_session WHERE user_id IN ({$uid}, {$other})");
$wpdb->query("DELETE FROM {$p}navigation_progress WHERE user_id IN ({$uid}, {$other})");
wp_delete_user($uid);
wp_delete_user($other);
$settingsBefore === false ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $settingsBefore);
$check(get_userdata($uid) === false, 'Testkonten entfernt');
wp_set_current_user(0);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
