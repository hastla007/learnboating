<?php

declare(strict_types=1);

/*
 * Integrationstest Smarttrainer mit verteilter Wiederholung und Prüfung ohne Netz (Expertenrunde 3):
 * - Reihenfolge wie bisher (Betreiber-Entscheidung): zuerst neue Fragen, dann falsch beantwortete, dann fällige
 *   Wiederholungen; falsche Fragen bleiben im Modus „Falsch beantwortete“ sofort abrufbar;
 * - FACH-02: drei richtige in Folge = „sicher heute“, gefestigt erst mit einer richtigen Antwort an einem
 *   anderen Tag (≥ 20 h), danach Wiedervorlage nach wachsendem Abstand; Bestand „gefestigt“ bleibt
 *   gefestigt und wird nach 7 Tagen wieder vorgelegt; Zählung je Stufe und App-Felder (abwärtskompatibel);
 * - QA-02: Prüfungsantworten mit Gerätezeit (age_ms) bis LATE_TOLERANCE_SECONDS nach Fristende, später
 *   abgewiesen; ein älterer Stand überschreibt keinen neueren; Web-REST liefert den gespeicherten Stand und
 *   den Fachcode; App-API nutzt dieselbe Logik (Parameter age_ms/answered_at);
 * - QA-10: laufende Zufallsprüfung nach Zugangsende nicht weiter bearbeitbar, auch mit kostenlosem Teilumfang.
 *
 *   WP_DIR=/pfad/zu/wordpress php tests/integration/learning-review-test.php
 *
 * Voraussetzung: content-flow-test.php (Kurse importiert). Nur gegen eine Test-Datenbank.
 */

$wpDir = rtrim((string) getenv('WP_DIR'), '/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wpDir.'/wp-load.php';
require __DIR__.'/support/guard.php';
require_once ABSPATH.'wp-admin/includes/user.php';

use Bootspruefung\Learning\Commerce\EntitlementService;
use Bootspruefung\Learning\Content\Repositories\ContentRepository;
use Bootspruefung\Learning\Exams\Services\ExamService;
use Bootspruefung\Learning\Infrastructure\Database\Migrator;
use Bootspruefung\Learning\Learning\Services\AccessService;
use Bootspruefung\Learning\Learning\Services\LearningService;
use Bootspruefung\Learning\Learning\Services\QuestionPresenter;
use Bootspruefung\Learning\Mobile\Mapper\CourseMapper;
use Bootspruefung\Learning\Mobile\Mapper\QuestionMapper;
use Bootspruefung\Learning\Plugin;
use Bootspruefung\Learning\Settings\Settings;
use Bootspruefung\Learning\Support\AccessDenied;
use Bootspruefung\Learning\Support\DomainException;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';

Plugin::instance()->maybeMigrate();
$check(get_option(Migrator::OPTION_VERSION) === Migrator::SCHEMA_VERSION && (bool) $wpdb->get_var("SHOW COLUMNS FROM {$p}progress LIKE 'due_at'"), 'Migration 1.14.0: Spalte progress.due_at');

$settingsBefore = get_option(Settings::OPTION);
update_option(Settings::OPTION, array_merge(Settings::all(), ['default_access' => 'entitled_only']));
$user = static function (string $login): int {
    $u = get_user_by('login', $login);
    if ($u) {
        wp_delete_user((int) $u->ID);
    }

    return (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login.'@example.test', 'role' => 'customer']);
};
$lea = $user('bp_review_lea');
$ole = $user('bp_review_ole');
$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code = 'SBF-SEE'");

$content = new ContentRepository();
$presenter = new QuestionPresenter($content);
$access = new AccessService();
$learning = new LearningService($content, $presenter, $access);
$exams = new ExamService($content, $presenter, $access);
$entitlements = new EntitlementService($wpdb);
$course = $content->courseByCode('SBF-SEE');
wp_set_current_user(1);
$entitlements->grantManual($lea, 'SBF-SEE', ['question_bank', 'exam_simulation', 'learning_explanations'], null, 'Test Wiederholung');
$oleIds = $entitlements->grantManual($ole, 'SBF-SEE', ['question_bank', 'exam_simulation'], null, 'Test Zugangsende');
wp_set_current_user(0);

$correctIds = static fn (int $revisionId): array => array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $revisionId)));
$wrongId = static fn (int $revisionId): int => (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 0 LIMIT 1", $revisionId));
$noRateLimit = static fn (int $u) => delete_transient('bp_rl_'.$u.'_'.intdiv(time(), 60));
$answer = static function (int $u, array $q, bool $right) use ($learning, $correctIds, $wrongId, $noRateLimit): array {
    $noRateLimit($u);

    return $learning->answer($u, $q['token'], $right ? $correctIds((int) $q['revision_id']) : [$wrongId((int) $q['revision_id'])], null, wp_generate_uuid4(), 1500);
};
// Frage-Token ohne Smarttrainer-Auswahl (wie next(): Darstellung + Token mit Kontext learn:KURS).
$tokenFor = static function (int $u, int $questionId) use ($presenter, $wpdb, $p): array {
    $revision = (int) $wpdb->get_var($wpdb->prepare("SELECT current_revision_id FROM {$p}question WHERE id = %d", $questionId));
    $presented = $presenter->present($revision);

    return $presented + ['token' => $presenter->token($u, $presented['revision_id'], $presented['answer_order'], 'learn:SBF-SEE')];
};
$progressRow = static fn (int $u, int $q) => $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}progress WHERE user_id = %d AND question_id = %d", $u, $q));
// Ein Modul mit genug Einfachauswahl-Fragen für die Reihenfolge.
$module = null;
foreach ($learning->courseProgress($lea, $course)['modules'] as $m) {
    if (! $m['locked'] && $m['available'] >= 20 && ($module === null || $m['available'] > $module['available'])) {
        $module = $m;
    }
}
$moduleId = (int) $module['id'];

echo "Reihenfolge: zuerst neue, dann falsche Fragen (wie bisher)\n";
$first = $learning->next($lea, $course, $moduleId, 'smart');
$r = $answer($lea, $first, false);
$check($r['correct'] === false && $r['progress']['status'] === 'unsure', 'erste Frage falsch beantwortet');
$back = null;
$seen = [];
$reasons = [];
for ($i = 1; $i <= 12; $i++) {
    $q = $learning->next($lea, $course, $moduleId, 'smart');
    if ($q['question_id'] === $first['question_id']) {
        $back = $i;
        break;
    }
    $seen[] = $q['question_id'];
    $reasons[$q['reason']] = true;
    $answer($lea, $q, true);
}
$check($back === null && array_keys($reasons) === ['Noch nie gesehen'], 'solange neue Fragen da sind, kommen neue zuerst (falsche Frage nicht nach 12 Schritten)');
$check(count(array_unique($seen)) === count($seen), 'dazwischen keine Frage doppelt');
$q = $learning->next($lea, $course, $moduleId, 'wrong');
$check($q['question_id'] === $first['question_id'], 'Modus „Falsch beantwortete“ liefert die falsche Frage sofort');
$q = $learning->next($lea, $course, $moduleId, 'smart', null, [(int) $first['question_id'], (int) $seen[0]]);
$check($q['question_id'] === $first['question_id'] && $q['reason'] === 'Zuletzt falsch beantwortet', 'ohne neue Fragen: falsche vor richtig beantworteten, Grund „Zuletzt falsch beantwortet“');

echo "FACH-02: sicher heute, gefestigt an einem anderen Tag, Wiedervorlage\n";
$qid = (int) $first['question_id'];
$streaks = [];
$r = $answer($lea, $q, true);
$streaks[] = $r['progress']['streak'];
// Zwei weitere richtige Antworten mit anderen Fragen dazwischen (Abstand wie im Smarttrainer).
for ($round = 0; $round < 2; $round++) {
    $other = $learning->next($lea, $course, $moduleId, 'new');
    $answer($lea, $other, true);
    $r = $answer($lea, $tokenFor($lea, $qid), true);
    $streaks[] = $r['progress']['streak'];
}
$row = $progressRow($lea, $qid);
$check($streaks === [1, 2, 3] && $r['progress']['status'] === 'secure' && $row->learn_status === 'secure', 'drei richtige in Folge: „sicher heute“, nicht gefestigt');
$due = strtotime($row->due_at.' UTC') - time();
$check(abs($due - LearningService::CONSOLIDATE_GAP_SECONDS) < 60 && $r['progress']['due_at'] === $row->due_at, 'Wiederholung fällig nach 20 Stunden (due_at, auch in der Antwort)');
$other = $learning->next($lea, $course, $moduleId, 'new');
$answer($lea, $other, true);
$r = $answer($lea, $tokenFor($lea, $qid), true);
$check($r['streak_counted'] === false && $r['progress']['status'] === 'secure' && $progressRow($lea, $qid)->due_at === $row->due_at, 'noch am selben Tag richtig: zählt nicht, bleibt „sicher heute“, Fälligkeit unverändert');
$summary = $learning->summary($lea, [$qid]);
$check($summary['secure'] === 1 && $summary['mastered'] === 0 && $summary['percent'] === 0 && $summary['secure_percent'] === 100 && $summary['due'] === 0, 'Zählung: 1 sicher heute, 0 gefestigt (percent bleibt der Anteil gefestigt)');
// Einen Tag später: fällig, Smarttrainer legt die Frage vor neuen Fragen vor.
$wpdb->update($p.'progress', ['due_at' => gmdate('Y-m-d H:i:s', time() - 60), 'last_answered_at' => gmdate('Y-m-d H:i:s', time() - 21 * HOUR_IN_SECONDS)], ['user_id' => $lea, 'question_id' => $qid]);
$wpdb->query($wpdb->prepare("UPDATE {$p}attempt SET created_at = %s WHERE user_id = %d", gmdate('Y-m-d H:i:s', time() - 21 * HOUR_IN_SECONDS), $lea));
$check($learning->summary($lea, [$qid])['due'] === 1, 'Zählung: 1 fällig');
$fresh = (int) $learning->next($lea, $course, $moduleId, 'new')['question_id'];
$q = $learning->next($lea, $course, $moduleId, 'smart', null, [$qid, $fresh]);
$check($q['question_id'] === $fresh && $q['reason'] === 'Noch nie gesehen', 'neue Frage weiter vor der fälligen Wiederholung (Reihenfolge wie bisher)');
$q = $learning->next($lea, $course, $moduleId, 'smart', null, [$qid, (int) $seen[0], (int) $seen[1]]);
$check($q['question_id'] === $qid && str_contains($q['reason'], 'neuen Tag'), 'fällige „sicher heute“-Frage kommt vor nicht fälligen ('.$q['reason'].')');
$r = $answer($lea, $q, true);
$row = $progressRow($lea, $qid);
$check($r['streak_counted'] === true && $r['progress']['status'] === 'mastered' && abs(strtotime($row->due_at.' UTC') - time() - 7 * DAY_IN_SECONDS) < 60, 'richtig an einem anderen Tag: gefestigt, nächste Wiederholung in 7 Tagen');
$r = $answer($lea, $tokenFor($lea, $qid), true);
$check($r['streak_counted'] === false && $r['progress']['status'] === 'mastered' && $progressRow($lea, $qid)->due_at === $row->due_at, 'gefestigt und nicht fällig: zählt nicht, Fälligkeit unverändert');
$wpdb->update($p.'progress', ['due_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['user_id' => $lea, 'question_id' => $qid]);
$wpdb->query($wpdb->prepare("UPDATE {$p}attempt SET created_at = %s WHERE user_id = %d", gmdate('Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS), $lea));
$q = $learning->next($lea, $course, $moduleId, 'smart', null, [$qid, (int) $seen[0], (int) $seen[1]]);
$check($q['question_id'] === $qid && $q['reason'] === 'Wiederholung fällig', 'fällige gefestigte Frage wird wieder vorgelegt');
$r = $answer($lea, $q, true);
$check($r['streak_counted'] === true && abs(strtotime($progressRow($lea, $qid)->due_at.' UTC') - time() - 21 * DAY_IN_SECONDS) < 60, 'Wiederholung richtig: nächster Abstand 21 Tage (wachsend)');
$r = $answer($lea, $tokenFor($lea, $qid), false);
$row = $progressRow($lea, $qid);
$check($r['progress']['status'] === 'unsure' && $row->due_at === null && (int) $row->streak === 0, 'falsche Antwort: zurück auf „unsicher“, keine Fälligkeit');

echo "Bestand vor 1.14.0 (gefestigt ohne due_at)\n";
$legacy = array_slice(array_values(array_diff(array_keys($learning->allowedQuestions($lea, $course, [$moduleId])), array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$p}progress WHERE user_id = %d", $lea))))), 0, 2);
foreach ($legacy as $i => $lq) {
    $wpdb->insert($p.'progress', ['user_id' => $lea, 'question_id' => $lq, 'attempts' => 3, 'correct_count' => 3, 'streak' => 3, 'mastery_score' => 1, 'learn_status' => 'mastered',
        'first_answered_at' => gmdate('Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS), 'last_answered_at' => gmdate('Y-m-d H:i:s', time() - ($i === 0 ? 8 : 1) * DAY_IN_SECONDS), 'due_at' => null]);
}
$summary = $learning->summary($lea, $legacy);
$check($summary['mastered'] === 2 && $summary['percent'] === 100 && $summary['due'] === 1, 'alter Stand bleibt gefestigt (kein rückwirkender Verlust), die vor 8 Tagen beantwortete ist fällig');
$check(LearningService::dueAt($progressRow($lea, $legacy[1])) === gmdate('Y-m-d H:i:s', strtotime($progressRow($lea, $legacy[1])->last_answered_at.' UTC') + 7 * DAY_IN_SECONDS), 'Fälligkeit ohne due_at: 7 Tage nach der letzten Antwort');
$q = $learning->next($lea, $course, $moduleId, 'smart', null, $legacy);
$check($q['question_id'] === $legacy[0] && $q['reason'] === 'Wiederholung fällig', 'fällige Bestandsfrage wird wieder vorgelegt');

echo "App-Felder (abwärtskompatibel)\n";
$mp = QuestionMapper::progress(['status' => 'secure', 'streak' => 3, 'attempts' => 3, 'due_at' => '2026-11-13 10:00:00']);
$check($mp['learning_status'] === 'learning' && $mp['learning_state'] === 'in_bearbeitung' && $mp['stage'] === 'secure' && $mp['due_at'] === '2026-11-13T10:00:00Z', 'Frage: learning_status im alten Wertebereich, neu stage = secure und due_at');
$check(QuestionMapper::progress(['status' => 'mastered', 'streak' => 4, 'attempts' => 4])['learning_status'] === 'mastered' && QuestionMapper::progress(null)['stage'] === 'new', 'Frage: gefestigt bzw. neu unverändert');
$ms = CourseMapper::progressSummary(['questions' => 10, 'new' => 4, 'unsure' => 1, 'learning' => 2, 'secure' => 2, 'mastered' => 1, 'percent' => 10, 'secure_percent' => 20, 'due' => 1]);
$check($ms['learning'] + $ms['new'] + $ms['unsure'] + $ms['mastered'] === $ms['total'] && $ms['secure'] === 2 && $ms['secure_percent'] === 20 && $ms['due'] === 1 && $ms['completed'] === 1, 'Fortschritt: new + unsure + learning + mastered = total (learning enthält „sicher heute“), neu secure, secure_percent, due');

echo "QA-02: Prüfung ohne Netz – Antworten nachsenden\n";
$sid = $exams->start($lea, $course, null);
$wpdb->update($p.'exam_session', ['started_at' => gmdate('Y-m-d H:i:s', time() - 1800)], ['id' => $sid]); // läuft seit 30 Minuten
$view = $exams->show($lea, $sid);
$rev = static fn (int $pos): int => (int) $wpdb->get_var($wpdb->prepare("SELECT revision_id FROM {$p}exam_session_question WHERE session_id = %d AND position = %d", $sid, $pos));
$ids = static fn (int $pos): array => array_map(static fn ($a) => (int) $a['id'], $view['questions'][$pos - 1]['answers']);
$s1 = $exams->saveAnswer($lea, $sid, 1, $correctIds($rev(1)), null);
$s1b = $exams->saveAnswer($lea, $sid, 1, $correctIds($rev(1)), null);
$check($s1['applied'] && $s1b['applied'] && $s1b['selected_ids'] === $correctIds($rev(1)) && $s1b['answered'], 'dieselbe Antwort zweimal: gleicher gespeicherter Stand (idempotent)');
$newer = $exams->saveAnswer($lea, $sid, 2, [$ids(2)[1]], null, 0);
$older = $exams->saveAnswer($lea, $sid, 2, [$ids(2)[0]], null, 60000);
$check($newer['applied'] && ! $older['applied'] && $older['selected_ids'] === [$ids(2)[1]], 'verspätet zugestellter älterer Stand überschreibt den neueren nicht (applied = false, Antwort enthält den gespeicherten Stand)');
// Frist vor 60 Sekunden abgelaufen: Sitzung läuft für Nachzügler noch bis LATE_TOLERANCE_SECONDS.
$wpdb->update($p.'exam_session', ['started_at' => gmdate('Y-m-d H:i:s', time() - 3700), 'deadline_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $sid]);
$wpdb->query($wpdb->prepare("UPDATE {$p}exam_session_question SET answered_at = %s WHERE session_id = %d AND answered_at IS NOT NULL", gmdate('Y-m-d H:i:s', time() - 600), $sid)); // vor Fristende gegeben
$check($exams->show($lea, $sid)['status'] === 'running' && $exams->show($lea, $sid)['remaining_seconds'] === 0, 'Frist vor 60 s: Sitzung noch offen (Restzeit 0)');
$check(in_array((int) $wpdb->get_var($wpdb->prepare("SELECT question_id FROM {$p}exam_session_question WHERE session_id = %d AND position = 3", $sid)), LearningService::blockedByRunningExam($lea), true), 'Fragen bleiben bis zum Ende der Kulanz im Lernmodus gesperrt');
$err = null;
try {
    $exams->saveAnswer($lea, $sid, 3, $correctIds($rev(3)), null);
} catch (DomainException $e) {
    $err = $e;
}
$check($err !== null && $err->errorCode() === DomainException::EXAM_EXPIRED && $wpdb->get_var($wpdb->prepare("SELECT answered_at FROM {$p}exam_session_question WHERE session_id = %d AND position = 3", $sid)) === null, 'Antwort nach Fristende (ohne Gerätezeit) abgewiesen');
$err = null;
try {
    $exams->setFlag($lea, $sid, 4, true);
} catch (DomainException $e) {
    $err = $e;
}
$check($err !== null && $err->errorCode() === DomainException::EXAM_EXPIRED, 'nach Fristende keine Markierung mehr');
$late = $exams->saveAnswer($lea, $sid, 3, $correctIds($rev(3)), null, 90000);
$check($late['applied'] && strtotime($late['answered_at'].' UTC') <= time() - 85, 'offline vor Fristende gegebene Antwort (age_ms = 90 s) innerhalb der Kulanz angenommen');
// Web-REST: Fehler mit Fachcode, gespeicherter Stand in der Antwort.
wp_set_current_user($lea);
$rest = static function (string $method, string $path, array $body = []): WP_REST_Response {
    $request = new WP_REST_Request($method, '/'.\Bootspruefung\Learning\Rest\RestRegistrar::NAMESPACE.$path);
    if ($body !== []) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
    }

    return rest_ensure_response(rest_do_request($request));
};
$res = $rest('PUT', "/exam-sessions/{$sid}/answers/4", ['answer_ids' => $correctIds($rev(4))]);
$check($res->get_status() === 422 && ($res->get_data()['data']['reason'] ?? '') === DomainException::EXAM_EXPIRED, 'Web-REST: späte Antwort → 422 mit reason exam_expired');
$res = $rest('PUT', "/exam-sessions/{$sid}/answers/4", ['answer_ids' => $correctIds($rev(4)), 'age_ms' => 70000]);
$check($res->get_status() === 200 && $res->get_data()['applied'] === true && $res->get_data()['selected_ids'] === $correctIds($rev(4)) && $res->get_data()['position'] === 4, 'Web-REST: nachgesendete Antwort mit age_ms gespeichert, Stand in der Antwort');
$res = $rest('POST', "/exam-sessions/{$sid}/submit");
$result = $res->get_data()['result'] ?? [];
$byPos = [];
foreach ($result['questions'] ?? [] as $rq) {
    $byPos[$rq['position']] = $rq['correct'];
}
$check($res->get_data()['status'] === 'evaluated' && $result['expired'] === true && ($byPos[1] ?? false) && ($byPos[3] ?? false) && ($byPos[4] ?? false), 'Abgabe: nachgesendete Antworten werden gewertet');
wp_set_current_user(0);
$routes = rest_get_server()->get_routes();
$mobileArgs = $routes['/boatingschool/v1/exams/(?P<examId>\d+)/answers/(?P<questionId>\d+)'][0]['args'] ?? ($routes['/boatingschool/v1/exams/(?P<examId>[0-9]+)/answers/(?P<questionId>[0-9]+)'][0]['args'] ?? null);
if ($mobileArgs === null) {
    foreach ($routes as $route => $handlers) {
        if (str_starts_with($route, '/boatingschool/v1/exams/') && str_ends_with($route, ')') && substr_count($route, '/answers/') === 1 && ! str_contains($route, 'flag') && ! str_contains($route, 'reveal')) {
            $mobileArgs = $handlers[0]['args'] ?? null;
        }
    }
}
$check(is_array($mobileArgs) && isset($mobileArgs['age_ms'], $mobileArgs['answered_at']), 'App-API: PUT /exams/{id}/answers/{pos} nimmt age_ms und answered_at an (gleicher ExamService)');
// Nach Ende der Kulanz: automatisch ausgewertet, nichts mehr angenommen.
$sid2 = $exams->start($lea, $course, null);
$wpdb->update($p.'exam_session', ['started_at' => gmdate('Y-m-d H:i:s', time() - 3900), 'deadline_at' => gmdate('Y-m-d H:i:s', time() - ExamService::LATE_TOLERANCE_SECONDS - 30)], ['id' => $sid2]);
$err = null;
try {
    $exams->saveAnswer($lea, $sid2, 1, [1], null, 200000);
} catch (DomainException $e) {
    $err = $e;
}
$shown = $exams->show($lea, $sid2);
$check($err !== null && in_array($err->errorCode(), [DomainException::EXAM_EXPIRED, DomainException::EXAM_FINISHED], true) && $shown['status'] === 'evaluated' && $shown['result']['expired'] === true, 'nach Ende der Kulanz: abgewiesen und automatisch ausgewertet');

echo "QA-10: Zugangsende während einer Zufallsprüfung\n";
$now = current_time('mysql', true);
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => 'exam_simulation', 'access_type' => 'free_limited', 'limit_type' => AccessService::LIMIT_TYPES['exam_simulation'], 'limit_value' => 1, 'created_at' => $now, 'updated_at' => $now]);
$sim = $exams->start($ole, $course, null);
$exams->saveAnswer($ole, $sim, 1, [$correctIds((int) $wpdb->get_var($wpdb->prepare("SELECT revision_id FROM {$p}exam_session_question WHERE session_id = %d AND position = 1", $sim)))[0]], null);
wp_set_current_user(1);
foreach ($oleIds as $eid) {
    $entitlements->setStatusManual($eid, 'revoked', 'Test: Erstattung');
}
wp_set_current_user(0);
$denied = false;
try {
    $exams->saveAnswer($ole, $sim, 2, [1], null);
} catch (AccessDenied $e) {
    $denied = true;
}
$check($access->can($ole, 'SBF-SEE', 'exam_simulation') && $denied, 'kostenloser Teilumfang aktiv, Zufallsprüfung trotzdem gesperrt');
$exams->submit($ole, $sim);
$check($exams->show($ole, $sim)['status'] === 'evaluated', 'Abgabe bleibt möglich (gespeicherte Antworten werden gewertet)');

// Aufräumen
$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code = 'SBF-SEE'");
foreach ([$lea, $ole] as $u) {
    foreach (['attempt_answer' => "attempt_id IN (SELECT id FROM {$p}attempt WHERE user_id = {$u})", 'attempt' => "user_id = {$u}", 'progress' => "user_id = {$u}", 'exam_session_question' => "session_id IN (SELECT id FROM {$p}exam_session WHERE user_id = {$u})", 'exam_session' => "user_id = {$u}", 'entitlement' => "user_id = {$u}"] as $table => $where) {
        $wpdb->query("DELETE FROM {$p}{$table} WHERE {$where}");
    }
    wp_delete_user($u);
}
update_option(Settings::OPTION, $settingsBefore);

echo $failures === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
