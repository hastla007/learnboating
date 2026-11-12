<?php

declare(strict_types=1);

/*
 * Integrationstest Import → Content-Browser-Daten → Lernen → Prüfung gegen
 * eine echte WordPress-Installation und ein echtes Content-Paket.
 *
 *   WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php tests/integration/content-flow-test.php
 *
 * Voraussetzung: Plugin ist aktiv (z. B. nach smoke-test.php).
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

use Bootspruefung\Learning\Content\Import\ImportService;
use Bootspruefung\Learning\Content\Repositories\Overrides;
use Bootspruefung\Learning\Plugin;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ').$label."\n";
    if (! $ok) {
        $failures++;
    }
};
global $wpdb;
$p = $wpdb->prefix.'bp_';
$count = static fn (string $table, string $where = '1=1') => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}{$table} WHERE {$where}");
$manifest = json_decode((string) file_get_contents($packageDir.'/manifest.json'), true);
$service = Plugin::instance()->importService();
wp_set_current_user(1);

echo "Import\n";
$id = $service->createFromDirectory($packageDir);
$check($service->batch($id)->status === 'validated', 'Paket geprüft');
$threw = false;
try {
    $service->runStep($id);
} catch (RuntimeException $e) {
    $threw = true;
}
$check($threw, 'Import ohne Vorschau und Freigabe wird abgewiesen');
$preview = $service->preview($id);
$service->approve($id, 'Integrationstest');
$service->runAll($id);
$check($service->batch($id)->status === 'published', 'Paket veröffentlicht');
foreach (['courses' => 'course', 'modules' => 'module', 'questions' => 'question', 'media' => 'media', 'exam_rules' => 'exam_rule', 'exam_papers' => 'exam_paper', 'navigation_tasks' => 'navigation_task', 'praxis_tasks' => 'praxis_task', 'video_modules' => 'video_module', 'radio_texts' => 'radio_text', 'radio_vocabulary' => 'radio_vocabulary', 'radio_practice' => 'radio_scenario', 'device_simulation' => 'device'] as $entity => $table) {
    $check($count($table, "status <> 'source_archived'") === $manifest['counts'][$entity], "{$entity}: ".$manifest['counts'][$entity].' wie im Manifest');
}
$check($count('answer', "revision_id IN (SELECT current_revision_id FROM {$p}question)") === $manifest['counts']['answers'], 'Antworten wie im Manifest');
$check($count('exam_paper_question', "paper_id IN (SELECT id FROM {$p}exam_paper WHERE status <> 'source_archived')") === $manifest['counts']['exam_paper_questions'], 'Bogenfragen wie im Manifest');
$check($count('navigation_question', "status <> 'source_archived'") === $manifest['counts']['navigation_questions'], 'Navigations-Teilaufgaben wie im Manifest');
$check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}media WHERE storage_key IS NOT NULL") === $manifest['counts']['media'], 'alle Mediendateien abgelegt');
$check($count('video_lesson', "status <> 'source_archived'") === $manifest['counts']['video_lessons'], 'Videolektionen wie im Manifest');
$check($count('video_lesson_question') === $manifest['counts']['video_lesson_questions'], 'Videoquiz-Zuordnungen wie im Manifest');

echo "Reimport\n";
$id2 = $service->createFromDirectory($packageDir);
$preview = $service->preview($id2);
$changed = 0;
foreach ($preview as $entity => $c) {
    if ($entity !== '_rights') {
        $changed += $c['create'] + $c['update'] + $c['archive'];
    }
}
$check($changed === 0, 'identisches Paket: keine Änderungen');
$revisions = $count('question_revision');
$service->approve($id2);
$service->runAll($id2);
$check($count('question_revision') === $revisions, 'keine neuen Revisionen');

echo "Geändertes Paket\n";
$tmp = sys_get_temp_dir().'/bp-flow-mod-'.getmypid();
exec('cp -r '.escapeshellarg($packageDir).' '.escapeshellarg($tmp));
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
$questions = json_decode((string) file_get_contents("{$tmp}/data/questions.json"), true);
$questions[0]['revision']['question_text'] .= ' (korrigiert)';
unset($questions[0]['hash']);
$questions[0]['hash'] = 'sha256:'.hash('sha256', json_encode($questions[0], $flags));
file_put_contents("{$tmp}/data/questions.json", json_encode($questions, $flags));
$praxis = json_decode((string) file_get_contents("{$tmp}/data/praxis_tasks.json"), true);
$removed = array_pop($praxis)['source_id'];
file_put_contents("{$tmp}/data/praxis_tasks.json", json_encode($praxis, $flags));
$m = json_decode((string) file_get_contents("{$tmp}/manifest.json"), true);
$m['package_id'] = (string) preg_replace('/-[0-9a-f]{12}$/', '-00000000c0de', $m['package_id']);
foreach ($m['files'] as &$f) {
    if (str_starts_with($f['path'], 'data/')) {
        $f['size'] = filesize("{$tmp}/{$f['path']}");
        $f['sha256'] = hash_file('sha256', "{$tmp}/{$f['path']}");
    }
}
unset($f);
file_put_contents("{$tmp}/manifest.json", json_encode($m, $flags));
$id3 = $service->createFromDirectory($tmp);
$preview = $service->preview($id3);
$check($preview['questions']['update'] === 1 && $preview['praxis_tasks']['archive'] === 1, 'Vorschau: 1 Frage geändert, 1 Praxiskarte archiviert');
$service->approve($id3);
$service->runAll($id3);
$qid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}question WHERE source_id = %s", $questions[0]['source_id']));
$revs = $wpdb->get_results($wpdb->prepare("SELECT revision_no, editorial_status FROM {$p}question_revision WHERE question_id = %d ORDER BY revision_no", $qid));
$check(count($revs) >= 2 && end($revs)->editorial_status === 'published' && $revs[count($revs) - 2]->editorial_status === 'deprecated', 'neue Revision veröffentlicht, alte deprecated');
$check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}praxis_task WHERE source_id = %s", $removed)) === 'source_archived', 'entfernte Praxiskarte archiviert, nicht gelöscht');
echo "Rollback\n";
$threw = false;
try {
    $service->rollback($id2, ' ');
} catch (RuntimeException $e) {
    $threw = true;
}
$check($threw, 'Rollback ohne Begründung abgewiesen');
$revCount = $count('question_revision', "question_id = {$qid}");
$id4 = $service->rollback($id2, 'Integrationstest: Korrektur zurücknehmen');
$preview = $service->preview($id4);
$check($preview['questions']['update'] === 1 && $preview['praxis_tasks']['update'] === 1, 'Rollback-Vorschau: 1 Frage, 1 Praxiskarte zurück');
$service->approve($id4, 'Rollback');
$service->runAll($id4);
$revs = $wpdb->get_results($wpdb->prepare("SELECT id, revision_no, editorial_status FROM {$p}question_revision WHERE question_id = %d ORDER BY revision_no", $qid));
$currentRev = (int) $wpdb->get_var($wpdb->prepare("SELECT current_revision_id FROM {$p}question WHERE id = %d", $qid));
$check($count('question_revision', "question_id = {$qid}") === $revCount, 'Rollback legt keine neue Revision an');
$check($currentRev === (int) $revs[0]->id && $revs[0]->editorial_status === 'published' && end($revs)->editorial_status === 'deprecated', 'ursprüngliche Revision wieder aktiv, Korrektur deprecated');
$check($wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}praxis_task WHERE source_id = %s", $removed)) === 'published', 'Praxiskarte nach Rollback wieder aktiv');
$check(get_option('bp_learning_active_package') === $manifest['package_id'], 'ursprüngliches Paket wieder aktiv');
$check($count('audit_log', "action = 'import_rolled_back'") > 0, 'Rollback im Audit-Log');
ImportService::removeDir($tmp);

$bad = sys_get_temp_dir().'/bp-bad-'.getmypid().'.zip';
$zip = new ZipArchive();
$zip->open($bad, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('../evil.php', '<?php');
$zip->close();
$threw = false;
try {
    $service->createFromZip($bad);
} catch (RuntimeException $e) {
    $threw = true;
}
unlink($bad);
$check($threw, 'ZIP mit Pfad außerhalb des Pakets abgewiesen');

echo "Rechte-Override\n";
$praxisIds = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}praxis_task LIMIT 3"));
Overrides::set('praxis_task', $praxisIds, 'rights_status', 'licensed', 'Integrationstest');
$check(Overrides::get('praxis_task', $praxisIds[0], 'rights_status') === 'licensed', 'Override gespeichert');
$check($count('audit_log', "action = 'override_rights_status'") > 0, 'Override im Audit-Log');
$threw = false;
try {
    Overrides::set('praxis_task', $praxisIds, 'rights_status', 'licensed', ' ');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
$check($threw, 'Override ohne Begründung abgewiesen');
Overrides::set('praxis_task', $praxisIds, 'rights_status', null, 'Integrationstest zurück');

echo "Lernen\n";
$login = 'flow'.wp_rand(1000, 9999);
$uid = wp_insert_user(['user_login' => $login, 'user_email' => $login.'@example.invalid', 'user_pass' => wp_generate_password()]);
wp_set_current_user($uid);
do_action('rest_api_init');
$req = static function (string $method, string $path, array $body = []): array {
    $r = new WP_REST_Request($method, '/bootspruefung/v1'.$path);
    $method === 'GET' ? $r->set_query_params($body) : $r->set_body_params($body);
    $res = rest_do_request($r);

    return [$res->get_status(), $res->get_data()];
};
[$s, $courses] = $req('GET', '/me/courses');
$check($s === 200 && count($courses) === $manifest['counts']['courses'], 'Kursliste');
[$s, $d] = $req('GET', '/courses/SBF-SEE/next-question');
$q = $d['question'];
$check($s === 200 && ! str_contains((string) wp_json_encode($d), 'is_correct'), 'Frage ohne Lösung ausgeliefert');
$answers = $wpdb->get_results($wpdb->prepare("SELECT id, is_correct FROM {$p}answer WHERE revision_id = %d", $q['revision_id']));
$right = (int) current(array_filter($answers, static fn ($a) => (int) $a->is_correct === 1))->id;
$wrong = (int) current(array_filter($answers, static fn ($a) => (int) $a->is_correct === 0))->id;
$key = wp_generate_uuid4();
[$s, $r] = $req('POST', '/answers', ['token' => $q['token'], 'answer_ids' => [$wrong], 'idempotency_key' => $key]);
$check($s === 200 && $r['correct'] === false && $r['correct_ids'] === [$right], 'falsche Antwort ausgewertet, Lösung danach sichtbar');
[$s, $r] = $req('POST', '/answers', ['token' => $q['token'], 'answer_ids' => [$wrong], 'idempotency_key' => $key]);
$check($r['repeated'] === true && $r['progress']['attempts'] === 1, 'Doppel-Submit zählt nicht doppelt');
// Serie zählt nur mit Abstand (Kurzzeitgedächtnis): letzte Antwort 10 Minuten zurückdatieren.
$wpdb->query($wpdb->prepare("UPDATE {$p}progress SET last_answered_at = %s WHERE user_id = %d", gmdate('Y-m-d H:i:s', time() - 600), $uid));
[$s, $d] = $req('GET', '/courses/SBF-SEE/next-question', ['mode' => 'wrong']);
$check($d['question']['question_id'] === $q['question_id'], 'Modus „falsch beantwortet“ zeigt die Frage');
[, $r] = $req('POST', '/answers', ['token' => $d['question']['token'], 'answer_ids' => [$right], 'idempotency_key' => wp_generate_uuid4()]);
$check($r['correct'] === true && $r['progress']['status'] === 'learning' && $r['progress']['streak'] === 1, 'richtige Antwort: in Bearbeitung, Serie 1');
[$s, $r] = $req('POST', '/answers', ['token' => $q['token'].'x', 'answer_ids' => [$right], 'idempotency_key' => wp_generate_uuid4()]);
$check($s === 422, 'manipuliertes Token abgewiesen');
[$s] = $req('PUT', '/favorites/'.$q['question_id']);
[, $d] = $req('GET', '/courses/SBF-SEE/next-question', ['mode' => 'favorites']);
$check($s === 200 && $d['question']['question_id'] === $q['question_id'], 'Merkliste');

echo "Prüfung\n";
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$sid = $d['session_id'];
[, $again] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$check($s === 201 && $again['session_id'] === $sid, 'Start und Fortsetzen derselben Simulation');
[, $e] = $req('GET', "/exam-sessions/{$sid}");
$check(count($e['questions']) === 30 && ! preg_match('/is_correct|correct_ids|explanation/', (string) wp_json_encode($e['questions'])), '30 Fragen ohne Lösungen');
[, $learnNext] = $req('GET', '/courses/SBF-SEE/next-question');
$examQuestionIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$p}exam_session_question WHERE session_id = %d", $sid)));
$check($learnNext['done'] || ! in_array($learnNext['question']['question_id'], $examQuestionIds, true), 'Lernmodus zeigt keine Fragen der laufenden Prüfung');
$mix = [];
foreach ($wpdb->get_results($wpdb->prepare("SELECT m.code FROM {$p}exam_session_question sq JOIN {$p}module_question mq ON mq.question_id = sq.question_id JOIN {$p}module m ON m.id = mq.module_id WHERE sq.session_id = %d", $sid)) as $row) {
    $mix[$row->code] = ($mix[$row->code] ?? 0) + 1;
}
ksort($mix);
$check($mix === ['SBF_BASIS' => 7, 'SBF_SEE' => 23], 'Blueprint 7 Basis + 23 See');
foreach ($e['questions'] as $i => $eq) {
    $rid = (int) $wpdb->get_var($wpdb->prepare("SELECT revision_id FROM {$p}exam_session_question WHERE session_id = %d AND position = %d", $sid, $eq['position']));
    $correct = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $rid)));
    $incorrect = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 0", $rid)));
    // Die ersten 3 Basisfragen falsch → Basisteil 4/7 < 5/7 → nicht bestanden trotz 27/30.
    $isBasis = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}module_question mq JOIN {$p}module m ON m.id = mq.module_id JOIN {$p}exam_session_question sq ON sq.question_id = mq.question_id WHERE sq.session_id = %d AND sq.position = %d AND m.code = 'SBF_BASIS'", $sid, $eq['position']));
    static $wrongBasis = 0;
    $pick = ($isBasis && $wrongBasis < 3) ? [$incorrect[0]] : $correct;
    if ($isBasis && $wrongBasis < 3) {
        $wrongBasis++;
    }
    [$s] = $req('PUT', "/exam-sessions/{$sid}/answers/{$eq['position']}", ['answer_ids' => $pick]);
}
[$s, $e] = $req('POST', "/exam-sessions/{$sid}/submit");
$res = $e['result'];
$check($e['status'] === 'evaluated' && $res['score'] === 27, 'Bewertung 27/30');
$check($res['passed'] === false && $res['parts'][0]['passed'] === false && $res['parts'][1]['passed'] === true, 'Teilgrenze Basis 5/7 verfehlt → nicht bestanden');
[$s] = $req('PUT', "/exam-sessions/{$sid}/answers/1", ['answer_ids' => [1]]);
$check($s === 422, 'nach Abgabe keine Antworten mehr');
[, $o] = $req('GET', '/courses/SBF-SEE/exam');
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions', ['paper_id' => $o['papers'][0]['id']]);
$paperSession = $d['session_id'];
[, $e] = $req('GET', "/exam-sessions/{$paperSession}");
$check($e['mode'] === 'paper' && count($e['questions']) === 30, 'fester Bogen 1 mit 30 Fragen');
// Fristende plus Kulanz für nachgesendete Antworten (LATE_TOLERANCE_SECONDS) vorbei.
$wpdb->update($p.'exam_session', ['deadline_at' => gmdate('Y-m-d H:i:s', time() - \Bootspruefung\Learning\Exams\Services\ExamService::LATE_TOLERANCE_SECONDS - 60)], ['id' => $paperSession]);
[, $e] = $req('GET', "/exam-sessions/{$paperSession}");
$check($e['status'] === 'evaluated' && $e['result']['expired'] === true, 'abgelaufene Prüfung automatisch abgegeben');
[, $o] = $req('GET', '/courses/SBF-SEE-BIN/exam');
$check($o['live'] === false, 'unverifiziertes Regelwerk → Übungsmodus');
[, $d] = $req('GET', '/courses/FKN/next-question');
if ($d['question']['type'] === 'open_text') {
    [$s, $r] = $req('POST', '/answers/reveal', ['token' => $d['question']['token']]);
    [, $r2] = $req('POST', '/answers', ['token' => $d['question']['token'], 'known' => true, 'idempotency_key' => wp_generate_uuid4()]);
    $check($s === 200 && $r['model_answer'] !== '' && $r2['correct'] === true, 'offene Frage: Musterantwort und Selbsteinschätzung');
}

echo "Spezialtrainer\n";
wp_set_current_user(1);
foreach (['navigation_task', 'praxis_task', 'video_module'] as $type) {
    Overrides::set($type, array_map('intval', $wpdb->get_col("SELECT id FROM {$p}{$type}")), 'rights_status', 'licensed', 'Integrationstest');
}
wp_set_current_user($uid);
[, $course] = $req('GET', '/courses/SBF-SEE');
$check($course['trainers']['navigation']['tasks'] === 15 && $course['trainers']['praxis']['cards'] > 0 && $course['trainers']['video']['lessons'] > 0, 'Kursseite meldet Navigation, Praxis und Video');
[, $tasks] = $req('GET', '/courses/SBF-SEE/navigation');
[, $task] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
$check(count($task['questions']) === 9 && $task['questions'][0]['answer'] === null, 'Navigationsaufgabe: 9 Teilaufgaben, Ergebnis verborgen');
[$s, $a] = $req('POST', '/courses/SBF-SEE/navigation-questions/'.$task['questions'][0]['id'], ['answer' => 'Test', 'self_assessment' => 'correct']);
[, $tasks] = $req('GET', '/courses/SBF-SEE/navigation');
$check($s === 200 && $a['answer'] !== '' && $tasks[0]['done'] === 1, 'Navigation: Ergebnis und Selbsteinschätzung gespeichert');
$req('POST', '/courses/SBF-SEE/navigation-questions/'.$task['questions'][0]['id'], ['answer' => 'Neu 50%d']);
$saved = $wpdb->get_row($wpdb->prepare("SELECT answer_text, self_assessment FROM {$p}navigation_progress WHERE navigation_question_id = %d ORDER BY id DESC LIMIT 1", $task['questions'][0]['id']));
$check($saved && $saved->self_assessment === 'correct' && $saved->answer_text === 'Neu 50%d', 'Navigation: neue Antwort ohne Einschätzung behält die Einschätzung (auch mit %)');
[, $praxis] = $req('GET', '/courses/SBF-SEE/praxis');
[$s] = $req('PUT', '/courses/SBF-SEE/praxis/'.$praxis['categories'][0]['cards'][0]['id'], ['status' => 'known']);
[, $praxis] = $req('GET', '/courses/SBF-SEE/praxis');
$check($s === 200 && $praxis['known'] === 1, 'Praxis: Karte als sicher markiert');
[, $videos] = $req('GET', '/courses/SBF-SEE/videos');
$lessonId = null;
foreach ($videos['modules'] as $m) {
    foreach ($m['lessons'] as $l) {
        $lessonId ??= $l['quiz'] > 0 ? $l['id'] : null;
    }
}
[, $lesson] = $req('GET', '/courses/SBF-SEE/videos/'.$lessonId);
$check(count($lesson['quiz']) > 0 && ! str_contains((string) wp_json_encode($lesson['quiz']), 'is_correct'), 'Videolektion mit Quiz ohne Lösungen');
$isPlaceholder = (int) $wpdb->get_var($wpdb->prepare("SELECT is_placeholder FROM {$p}video_lesson WHERE id = %d", $lessonId)) === 1;
$check($lesson['placeholder'] === $isPlaceholder && (! $isPlaceholder || $lesson['video_url'] === null), 'Platzhalter-Lektion liefert keine externe Video-URL');
// Echte Lektion (vorübergehend): URL wird geliefert; Garantie-Nenner zählt nur echte, nicht archivierte Lektionen.
$lessonBefore = $wpdb->get_row($wpdb->prepare("SELECT is_placeholder, video_url, status FROM {$p}video_lesson WHERE id = %d", $lessonId), ARRAY_A);
$lessonTotal = static fn (): int => \Bootspruefung\Learning\Commerce\MoneyBack::progress($uid, ['SBF-SEE'], '2000-01-01 00:00:00', false, true)['total'];
try {
    $wpdb->update($p.'video_lesson', ['is_placeholder' => 1], ['id' => $lessonId]);
    $withoutLesson = $lessonTotal();
    $wpdb->update($p.'video_lesson', ['is_placeholder' => 0, 'video_url' => 'https://videos.example.test/lektion.mp4'], ['id' => $lessonId]);
    [, $real] = $req('GET', '/courses/SBF-SEE/videos/'.$lessonId);
    $check($real['placeholder'] === false && $real['video_url'] !== null, 'echte Lektion liefert ihre Video-URL');
    $withLesson = $lessonTotal();
    $wpdb->update($p.'video_lesson', ['status' => 'source_archived'], ['id' => $lessonId]);
    $check($withLesson === $withoutLesson + 1 && $lessonTotal() === $withoutLesson, 'Garantie-Nenner ohne Platzhalter- und archivierte Lektionen');
} finally {
    $wpdb->update($p.'video_lesson', $lessonBefore, ['id' => $lessonId]);
}
$vq = $lesson['quiz'][0];
$vright = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $vq['revision_id']));
[$s, $r] = $req('POST', '/answers', ['token' => $vq['token'], 'answer_ids' => [$vright], 'idempotency_key' => wp_generate_uuid4()]);
$check($s === 200 && $r['correct'] === true && $wpdb->get_var("SELECT context FROM {$p}attempt ORDER BY id DESC LIMIT 1") === 'video', 'Videoquiz ausgewertet (Kontext video)');
$req('PUT', '/courses/SBF-SEE/videos/'.$lessonId.'/progress', ['position' => 30, 'completed' => true]);
[, $videos] = $req('GET', '/courses/SBF-SEE/videos');
$check($videos['completed'] === 1, 'Lektion abgeschlossen');
[, $radio] = $req('GET', '/courses/SRC/radio');
$check(count($radio['texts'][0]['items']) === 27 && count($radio['vocabulary']) > 0, 'Funktraining SRC: 27 Texte und Vokabeln');
[, $text] = $req('GET', '/courses/SRC/radio/texts/TXT-SRC-01');
[$s, $ev] = $req('POST', '/courses/SRC/radio/texts/TXT-SRC-01/evaluate', ['direction' => 'de-en', 'transcript' => $text['text_de'], 'translation' => $text['text_en']]);
$check($s === 200 && $ev['transcript']['percent'] === 100 && $ev['translation']['percent'] === 100, 'Funktext: vollständige Mitschrift und Übersetzung = 100 %');
foreach ([true, true, true] as $i => $known) {
    // angezeigte Serie mitschicken (sonst gilt eine zweite Antwort binnen Sekunden als Doppelklick)
    [, $va] = $req('POST', '/courses/UBI/radio/vocabulary/VOC-UBI-B', ['known' => $known, 'streak' => $i]);
}
$check($va['learned'] === true && $va['streak'] === 3, 'Buchstabiertafel: nach 3 × gewusst gelernt');
[$s] = $req('GET', '/courses/SBF-SEE/radio/texts/TXT-SRC-01');
$check($s === 404, 'Funktext außerhalb des passenden Kurses nicht erreichbar');

echo "Funkpraxis und Gerätesimulation\n";
$jsonReq = static function (string $method, string $path, array $body): array {
    $r = new WP_REST_Request($method, '/bootspruefung/v1'.$path);
    $r->set_header('Content-Type', 'application/json');
    $r->set_body((string) wp_json_encode($body));
    $res = rest_do_request($r);

    return [$res->get_status(), $res->get_data()];
};
[, $task] = $req('GET', '/courses/SRC/radio/practice/SRC-01');
$check(count($task['fields']) === 4 && ! str_contains((string) wp_json_encode($task), 'pattern'), 'Funkpraxis-Aufgabe ohne Prüfmuster ausgeliefert');
$fields = json_decode((string) $wpdb->get_var("SELECT checkpoints FROM {$p}radio_scenario WHERE source_id = 'SRC-01'"), true);
$model = array_column($fields, 'solution', 'name');
[$s, $ev] = $jsonReq('POST', '/courses/SRC/radio/practice/SRC-01/evaluate', ['answers' => $model]);
$check($s === 200 && $ev['passed'] === $ev['total'] && $ev['total'] > 0, "Musterlösungen erfüllen alle {$ev['total']} Prüfpunkte");
[, $ev] = $jsonReq('POST', '/courses/SRC/radio/practice/SRC-01/evaluate', ['answers' => ['message' => 'Hallo']]);
$check($ev['passed'] < $ev['total'], 'unvollständiger Funkspruch fällt durch');
[, $config] = $req('GET', '/courses/SRC/device-sim');
$check(count($config['lessons']) > 0 && $config['catalogs'] === ['SRC'] && str_contains($config['urls']['evaluate'], '_wpnonce='), 'Gerätesimulation: Konfiguration für SRC mit Endpunkten');
$sim = json_decode((string) $wpdb->get_var("SELECT data FROM {$p}device WHERE source_id = 'geraetesimulation'"), true);
$speechLesson = null;
foreach ($sim['lessons'] as $lesson) {
    foreach ($lesson['steps'] as $i => $st) {
        if (isset($st['speech']) && ($lesson['catalog'] ?? 'SRC') === 'SRC' && $speechLesson === null) {
            $speechLesson = [$lesson['key'], $i, $st['speech']['solution']];
        }
    }
}
[$s, $ev] = $jsonReq('POST', '/courses/SRC/device-sim/evaluate', ['lesson' => $speechLesson[0], 'step' => $speechLesson[1], 'text' => $speechLesson[2]]);
$check($s === 200 && $ev['passed'] === true, 'Gerät: Musterfunkspruch besteht');
[$s, $pr] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => $speechLesson[0], 'level' => 3, 'device' => 'ic-m505']);
[, $pr2] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => $speechLesson[0], 'level' => 1, 'device' => 'ic-m505']);
$check($s === 200 && $pr['level'] === 3 && $pr2['level'] === 3, 'Gerät: Stufe gespeichert, höchste bleibt');
$series = array_key_first(array_filter($sim['exam_meta'], static fn ($m) => $m['catalog'] === 'SRC'));
// Prüfungsaufgaben zählen nur mit bestandenen Funksprüchen (serverseitiger Beleg).
$simSpeak = static function (string $lessonKey) use ($sim, $jsonReq): void {
    foreach ($sim['lessons'] as $l) {
        if ($l['key'] === $lessonKey) {
            foreach ($l['steps'] as $i => $st) {
                if (isset($st['speech'])) {
                    $jsonReq('POST', '/courses/SRC/device-sim/evaluate', ['lesson' => $lessonKey, 'step' => $i, 'text' => $st['speech']['solution']]);
                }
            }
        }
    }
};
array_map($simSpeak, $sim['exams'][$series]);
[$s, $ex] = $jsonReq('POST', '/courses/SRC/device-sim/exam', ['series' => $series, 'device' => 'ic-m505', 'passed' => $sim['exams'][$series]]);
$check($s === 200 && $ex['passed'] === $ex['total'] && $ex['best'] === $ex['total'] && count((array) $ex['solutions']) === $ex['total'], 'Gerät: Prüfungsreihe vollständig bestanden, Musterlösungen geliefert');
[$s] = $req('GET', '/courses/SBF-SEE/device-sim');
$check($s === 403, 'Gerätesimulation in Kursen ohne Funk gesperrt');

echo "Zugriffsschutz\n";
$other = wp_insert_user(['user_login' => $login.'b', 'user_email' => $login.'b@example.invalid', 'user_pass' => wp_generate_password()]);
wp_set_current_user($other);
[$s] = $req('GET', "/exam-sessions/{$sid}");
$check($s === 404, 'fremde Prüfung nicht sichtbar');
[$s] = $req('POST', '/answers', ['token' => $q['token'], 'answer_ids' => [$right], 'idempotency_key' => wp_generate_uuid4()]);
$check($s === 422, 'fremdes Frage-Token abgewiesen');
update_option('bp_learning_settings', ['default_access' => 'entitled_only']);
[$s] = $req('GET', '/courses/SBF-SEE/next-question');
$check($s === 403, 'ohne Freischaltung (Einstellung „nur Freischaltungen“) gesperrt');
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank', 'access_type' => 'free']);
[$s] = $req('GET', '/courses/SBF-SEE/next-question');
$check($s === 200, 'kostenlose Regel gibt den Fragenkatalog frei');
$wpdb->query("DELETE FROM {$p}access_rule WHERE course_code = 'SBF-SEE'");
// Funkgerät-Simulator ohne Freischaltung: Testversion (Geräte-Lektionen + ein Routineanruf).
[$s, $free] = $req('GET', '/courses/SRC/device-sim');
$freeKeys = array_column($free['lessons'] ?? [], 'key');
$check($s === 200 && ($free['tier'] ?? '') === 'free' && in_array('einzelanruf', $freeKeys, true) && ! in_array('notalarm-senden', $freeKeys, true) && (array) $free['exams'] === [], 'Simulator ohne Freischaltung: Testversion ohne Notverkehr und Prüfung');
$check($free['locked']['tasks'] > 0 && $free['locked']['lessons'] > 0 && count($freeKeys) === count(array_intersect($freeKeys, \Bootspruefung\Learning\Trainers\DeviceSimService::FREE_LESSONS)), 'gesperrte Inhalte nur als Übersicht');
[$s] = $jsonReq('POST', '/courses/SRC/device-sim/exam', ['series' => $series, 'device' => 'ic-m505', 'passed' => []]);
[$s2] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => 'notalarm-senden', 'level' => 1, 'device' => 'ic-m505']);
$simSpeak('einzelanruf');
[$s3] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => 'einzelanruf', 'level' => 1, 'device' => 'ic-m505']);
$check($s === 403 && $s2 === 403 && $s3 === 200, 'Testversion: Prüfung und gesperrte Lektion 403, freie Lektion speicherbar');
(new \Bootspruefung\Learning\Commerce\EntitlementService($wpdb))->grantManual($other, 'SRC', ['device_simulation'], null, 'Test Simulator-Pass');
[, $full] = $req('GET', '/courses/SRC/device-sim');
$check(($full['tier'] ?? '') === 'premium' && count((array) $full['exams']) > 0 && $full['locked'] === null, 'mit Simulator-Zugang: Vollversion');
delete_option('bp_learning_settings');
wp_set_current_user(0);
[$s] = $req('GET', '/me/courses');
$check($s === 401, 'ohne Anmeldung 401');

echo "Review-Funde (Prüfung, Lernen, Trainer, Dateien)\n";
$rv = wp_insert_user(['user_login' => $login.'rv', 'user_email' => $login.'rv@example.invalid', 'user_pass' => wp_generate_password()]);
wp_set_current_user($rv);
$see = (int) $wpdb->get_var("SELECT id FROM {$p}course WHERE code = 'SBF-SEE'");
// Drip gilt auch für feste Bögen (Übersicht markiert, Start 403).
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => 'question_bank', 'module_code' => 'SBF_SEE', 'access_type' => 'drip', 'limit_value' => 30]);
$dripRule = (int) $wpdb->insert_id;
[, $o] = $req('GET', '/courses/SBF-SEE/exam');
$paper0 = $o['papers'][0];
[$s] = $req('POST', '/courses/SBF-SEE/exam-sessions', ['paper_id' => $paper0['id']]);
$check($paper0['locked'] === true && $paper0['lock_reason'] === 'drip' && $paper0['unlock_at'] > current_time('mysql', true) && $s === 403, 'Drip: fester Bogen gesperrt (locked/unlock_at), Start 403');
$wpdb->delete($p.'access_rule', ['id' => $dripRule]);
[, $o] = $req('GET', '/courses/SBF-SEE/exam');
$check($o['papers'][0]['locked'] === false && $o['papers'][0]['unlock_at'] === null, 'ohne Drip: Bogen frei');
// Teilzuordnung aus dem Snapshot; Teil ohne Fragen erzwingt kein „nicht bestanden“.
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$rvSid = (int) $d['session_id'];
$snap = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT rule_snapshot FROM {$p}exam_session WHERE id = %d", $rvSid)), true);
$partCount = array_count_values(array_values((array) ($snap['question_parts'] ?? [])));
ksort($partCount);
$check($s === 201 && $d['live'] === true && $d['reduced'] === false && $partCount === ['SBF_BASIS' => 7, 'SBF_SEE' => 23], 'Start: Teilzuordnung 7/23 im Snapshot, live, nicht verkürzt');
$snap['question_parts'] = array_map(static fn () => 'SBF_SEE', (array) $snap['question_parts']);
$wpdb->update($p.'exam_session', ['rule_snapshot' => wp_json_encode($snap)], ['id' => $rvSid]);
foreach ($wpdb->get_results($wpdb->prepare("SELECT position, revision_id FROM {$p}exam_session_question WHERE session_id = %d", $rvSid)) as $sq) {
    $req('PUT', "/exam-sessions/{$rvSid}/answers/{$sq->position}", ['answer_ids' => array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $sq->revision_id)))]);
}
[, $e] = $req('POST', "/exam-sessions/{$rvSid}/submit");
$parts = $e['result']['parts'];
$check($parts[0]['total'] === 0 && $parts[0]['empty'] === true && $parts[0]['passed'] === true && $parts[1]['total'] === 30 && $e['result']['passed'] === true, 'Auswertung nach Snapshot-Zuordnung, leerer Teil nicht automatisch durchgefallen');
// Zu kleiner Pool: verkürzt und nur Übung.
$ruleId = (int) $wpdb->get_var($wpdb->prepare("SELECT rule_id FROM {$p}exam_session WHERE id = %d", $rvSid));
$bpRow = $wpdb->get_row($wpdb->prepare("SELECT b.id, b.question_count FROM {$p}exam_blueprint b JOIN {$p}module m ON m.id = b.module_id WHERE b.rule_id = %d AND m.code = 'SBF_BASIS'", $ruleId));
$wpdb->update($p.'exam_blueprint', ['question_count' => 100000], ['id' => $bpRow->id]);
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$wpdb->update($p.'exam_blueprint', ['question_count' => $bpRow->question_count], ['id' => $bpRow->id]);
[, $e] = $req('GET', '/exam-sessions/'.$d['session_id']);
$check($s === 201 && $d['reduced'] === true && $d['live'] === false && $e['reduced'] === true && $e['live'] === false, 'zu kleiner Fragenpool: reduced, kein offizieller Modus');
// Laufende Prüfung ohne Zeitlimit sperrt ihre Fragen nur 24 Stunden.
$wpdb->update($p.'exam_session', ['deadline_at' => null, 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $d['session_id']]);
$blockedNow = \Bootspruefung\Learning\Learning\Services\LearningService::blockedByRunningExam($rv);
$wpdb->update($p.'exam_session', ['started_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)], ['id' => $d['session_id']]);
$check($blockedNow !== [] && \Bootspruefung\Learning\Learning\Services\LearningService::blockedByRunningExam($rv) === [], 'Prüfung ohne Zeitlimit: Fragen gesperrt, nach 24 h nicht mehr');
$req('POST', '/exam-sessions/'.$d['session_id'].'/submit');
// Startsperre belegt (zweite Verbindung): freundliche Meldung statt Doppelstart.
[$lockHost, $lockPort] = array_pad(explode(':', DB_HOST, 2), 2, null);
$lockDb = new mysqli($lockHost, DB_USER, DB_PASSWORD, DB_NAME, $lockPort ? (int) $lockPort : 3306);
$lockDb->query("SELECT GET_LOCK('bp_exam_start_{$rv}', 30)");
[$s, $d] = $req('POST', '/courses/SBF-SEE/exam-sessions');
$lockDb->query("SELECT RELEASE_LOCK('bp_exam_start_{$rv}')");
$lockDb->close();
$check($s === 422 && str_contains((string) ($d['message'] ?? ''), 'erneut'), 'Startsperre belegt: 422 „Bitte erneut versuchen“');
// Lern-Token: TTL 2 h, Zugriff und Auslieferbarkeit bei Antwort/Aufdecken.
$check(\Bootspruefung\Learning\Learning\Services\QuestionPresenter::TTL === 2 * HOUR_IN_SECONDS, 'Frage-Token gilt 2 Stunden');
[, $d] = $req('GET', '/courses/SBF-SEE/next-question');
$tq = $d['question'];
$tqRight = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $tq['revision_id'])));
update_option('bp_learning_settings', ['default_access' => 'entitled_only']);
[$s] = $req('POST', '/answers', ['token' => $tq['token'], 'answer_ids' => $tqRight, 'idempotency_key' => wp_generate_uuid4()]);
[$s2] = $req('POST', '/answers/reveal', ['token' => $tq['token']]);
delete_option('bp_learning_settings');
$check($s === 403 && $s2 === 403, 'Token nach Zugangsende: Antwort und Aufdecken 403');
wp_set_current_user(1);
Overrides::set('question', [$tq['question_id']], 'rights_status', 'restricted', 'Integrationstest Token');
wp_set_current_user($rv);
[$s] = $req('POST', '/answers', ['token' => $tq['token'], 'answer_ids' => $tqRight, 'idempotency_key' => wp_generate_uuid4()]);
wp_set_current_user(1);
Overrides::set('question', [$tq['question_id']], 'rights_status', null, 'Integrationstest Token zurück');
wp_set_current_user($rv);
$check($s === 404, 'Token einer nicht mehr auslieferbaren Frage: 404');
// Smarttrainer: zwei Tabs mit derselben Frage zählen die Serie nur einmal; Frage kommt nicht sofort wieder.
$module = (int) $tq['module_id'];
[, $t1] = $req('GET', '/courses/SBF-SEE/next-question', ['module' => $module]);
[, $t2] = $req('GET', '/courses/SBF-SEE/next-question', ['module' => $module]);
$sq1 = $t1['question'];
$sqRight = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}answer WHERE revision_id = %d AND is_correct = 1", $sq1['revision_id'])));
[, $a1] = $req('POST', '/answers', ['token' => $sq1['token'], 'answer_ids' => $sqRight, 'idempotency_key' => wp_generate_uuid4()]);
[, $a2] = $req('POST', '/answers', ['token' => $t2['question']['token'], 'answer_ids' => $sqRight, 'idempotency_key' => wp_generate_uuid4()]);
[, $n] = $req('GET', '/courses/SBF-SEE/next-question', ['module' => $module]);
$check($t2['question']['question_id'] === $sq1['question_id'] && $a1['progress']['streak'] === 1 && $a1['streak_counted'] === true && $a2['progress']['streak'] === 1 && $a2['streak_counted'] === false, 'zweiter Tab: richtige Antwort ohne Abstand erhöht die Serie nicht');
$check($n['done'] === false && $n['question']['question_id'] !== $sq1['question_id'], 'eben beantwortete Frage kommt nicht sofort wieder');
// Kursliste: schlanke Gesamtsumme wie in den Kursdaten.
[, $list] = $req('GET', '/me/courses');
[, $cd] = $req('GET', '/courses/SBF-SEE');
$check(current(array_filter($list, static fn ($c) => $c['code'] === 'SBF-SEE'))['progress'] === $cd['progress']['total'], 'Kursliste: Fortschritt wie Kursdaten');
// Vokabeln: Doppelklick (ohne Serie) zählt einmal.
[, $v1] = $req('POST', '/courses/UBI/radio/vocabulary/VOC-UBI-C', ['known' => true]);
[, $v2] = $req('POST', '/courses/UBI/radio/vocabulary/VOC-UBI-C', ['known' => true]);
[, $v3] = $req('POST', '/courses/UBI/radio/vocabulary/VOC-UBI-C', ['known' => true, 'streak' => 0]);
$check($v1['streak'] === 1 && $v2['repeated'] === true && $v2['streak'] === 1 && $v3['repeated'] === true, 'Vokabel: doppelte Antwort binnen Sekunden bzw. mit veralteter Serie ignoriert');
// Navigation: Nachbarn aus der sichtbaren Liste, gesperrte markiert, unbekannte Aufgabe 404.
update_option('bp_learning_settings', ['default_access' => 'entitled_only']);
$wpdb->insert($p.'access_rule', ['course_code' => 'SBF-SEE', 'feature_code' => 'navigation_trainer', 'access_type' => 'free_limited', 'limit_value' => 1]);
$navRule = (int) $wpdb->insert_id;
[, $tasks] = $req('GET', '/courses/SBF-SEE/navigation');
[, $t0] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[0]['id']);
[$sLocked] = $req('GET', '/courses/SBF-SEE/navigation/'.$tasks[1]['id']);
[$sUnknown] = $req('GET', '/courses/SBF-SEE/navigation/999999999');
$check($t0['next'] === $tasks[1]['id'] && $t0['next_locked'] === true && $t0['previous'] === null && $sLocked === 403 && $sUnknown === 404, 'Navigation: next_locked, gesperrte Aufgabe 403, unbekannte 404');
$navQ = $t0['questions'][0]['id'];
$req('POST', "/courses/SBF-SEE/navigation-questions/{$navQ}", ['answer' => 'Kurs 090°']);
[, $na] = $req('POST', "/courses/SBF-SEE/navigation-questions/{$navQ}", ['answer' => '']);
[, $nb] = $req('POST', "/courses/SBF-SEE/navigation-questions/{$navQ}", ['self_assessment' => 'partly']);
$check($na['my_answer'] === '' && $nb['my_answer'] === '', 'Navigation: leere Antwort leert, fehlendes Feld lässt sie stehen');
$wpdb->delete($p.'access_rule', ['id' => $navRule]);
delete_option('bp_learning_settings');
// Simulator: höhere Stufe nur mit Beleg, Beleg wird verbraucht; „gemeistert“ über alle Geräte.
[$s1] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => 'einzelanruf', 'level' => 2, 'device' => 'ic-m505']);
$simSpeak('einzelanruf');
[$s2, $pl] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => 'einzelanruf', 'level' => 2, 'device' => 'ic-m505']);
[$s3] = $jsonReq('POST', '/courses/SRC/device-sim/progress', ['lesson' => 'einzelanruf', 'level' => 3, 'device' => 'ic-m505']);
$check($s1 === 422 && $s2 === 200 && $pl['level'] === 2 && $s3 === 422, 'Simulator: Stufe nur mit bestandenem Funkspruch, Beleg verbraucht');
[, $radioBefore] = $req('GET', '/courses/SRC/radio');
$otherDevice = 'hx891bte'; // Handfunkgerät (SRC), nicht das Standardgerät
$wpdb->insert($p.'radio_progress', ['user_id' => $rv, 'item_type' => 'geraet', 'item_source_id' => $otherDevice.':einzelanruf', 'data' => wp_json_encode(['level' => 3]), 'done' => 1]);
[, $radioAfter] = $req('GET', '/courses/SRC/radio');
$check($radioAfter['device']['mastered'] === $radioBefore['device']['mastered'] + 1, 'Simulator-Übersicht: gemeistert auf anderem Gerät zählt');
// Geschützte Dateien: Verschieben schlägt fehl → kein Schutzfeld; Seekarte nicht freigebbar.
wp_set_current_user(1);
$up = wp_upload_dir();
$pf = $up['path'].'/bp-rv-'.wp_generate_password(6, false).'.pdf';
file_put_contents($pf, "%PDF-1.4\n% Review\n");
$att = wp_insert_attachment(['post_title' => 'Review-PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $pf);
update_post_meta($att, '_wp_attached_file', _wp_relative_upload_path($pf));
$blocker = \Bootspruefung\Learning\Content\Media\MediaStorage::protectedDir().'/'.$att;
file_put_contents($blocker, 'blockiert'); // Datei statt Ordner → Verschieben scheitert
$okProtect = \Bootspruefung\Learning\Gate\ProtectedFiles::protect((int) $att, 'SBF-SEE', 'downloads');
$check($okProtect === false && \Bootspruefung\Learning\Gate\ProtectedFiles::requirement((int) $att) === null && is_file($pf) && get_post_meta($att, \Bootspruefung\Learning\Gate\ProtectedFiles::ERROR_META, true) !== '', 'Verschieben fehlgeschlagen: kein Schutzfeld, Hinweis gespeichert');
@unlink($blocker);
$check(\Bootspruefung\Learning\Gate\ProtectedFiles::protect((int) $att, 'SBF-SEE', 'downloads') && get_post_meta($att, \Bootspruefung\Learning\Gate\ProtectedFiles::ERROR_META, true) === '', 'erneuter Versuch: geschützt, Hinweis entfernt');
update_post_meta($att, '_bp_chart', 'D49');
(new \Bootspruefung\Learning\Gate\ProtectedFiles())->saveFields(['ID' => $att], ['bp_required_access' => '']);
$check(\Bootspruefung\Learning\Gate\ProtectedFiles::requirement((int) $att) !== null && ! \Bootspruefung\Learning\Gate\ProtectedFiles::unprotect((int) $att), 'Seekarte lässt sich nicht frei zugänglich machen');
delete_post_meta($att, '_bp_chart');
wp_delete_attachment((int) $att, true);
$gone = wp_insert_attachment(['post_title' => 'D49 ohne Datei', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $up['path'].'/fehlt-'.wp_generate_password(6, false).'.png');
$threw = false;
try {
    \Bootspruefung\Learning\Gate\Charts::attach('D49', (int) $gone);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
$check($threw && get_post((int) $gone) === null && \Bootspruefung\Learning\Gate\Charts::state('D49') === null, 'Seekarte nicht schützbar: Upload verworfen, keine Karte hinterlegt');
delete_option(\Bootspruefung\Learning\Gate\Charts::OPTION);
wp_set_current_user(0);

echo $failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n";
exit($failures === 0 ? 0 : 1);
