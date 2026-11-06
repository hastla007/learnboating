<?php

declare(strict_types=1);

/*
 * Golden-Master-Abgleich: vergleicht ein Content-Paket mit der Datenbank
 * einer frisch geseedeten Laravel-Installation von hastla007/boatingschool
 * (gleicher Commit wie der Snapshot). Das Quellrepository wird dabei nicht
 * verändert; die Laravel-Kopie läuft lokal (siehe README.md daneben).
 *
 *   BS_DSN="pgsql:host=127.0.0.1;dbname=boatingschool" BS_USER=… BS_PASSWORD=… \
 *     php tools/content-build/golden-master/compare.php build/<paket>
 *
 * Exit-Code 0 = keine Abweichungen außer den dokumentierten.
 */

$package = rtrim($argv[1] ?? '', '/');
if ($package === '' || ! is_file($package.'/manifest.json')) {
    fwrite(STDERR, "Paketordner angeben.\n");
    exit(2);
}
$pdo = new PDO((string) getenv('BS_DSN'), (string) getenv('BS_USER'), (string) getenv('BS_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$n = static function (mixed $v): mixed {
    if ($v === null || is_bool($v) || is_int($v) || is_float($v)) {
        return $v;
    }
    $t = trim((string) $v);

    return $t === '' ? null : (Normalizer::normalize($t, Normalizer::FORM_C) ?: $t);
};
$data = static fn (string $e): array => json_decode((string) file_get_contents($package.'/data/'.$e.'.json'), true, 512, JSON_THROW_ON_ERROR);
$q = static fn (string $sql): array => $pdo->query($sql)->fetchAll();
$sortKeys = static function (?array $rows): ?array {
    if ($rows === null) {
        return null;
    }
    foreach ($rows as $i => $row) {
        ksort($row);
        $rows[$i] = $row;
    }

    return $rows;
};
$bool = static fn (mixed $v): bool => $v === true || $v === 't' || $v === 1 || $v === '1';

$diffs = [];
$compare = static function (string $entity, array $expected, array $actual) use (&$diffs): void {
    foreach (array_diff_key($expected, $actual) as $id => $_) {
        $diffs[] = "{$entity} {$id}: nur in Laravel";
    }
    foreach (array_diff_key($actual, $expected) as $id => $_) {
        $diffs[] = "{$entity} {$id}: nur im Paket";
    }
    foreach (array_intersect_key($expected, $actual) as $id => $exp) {
        if ($exp !== $actual[$id]) {
            foreach ($exp as $field => $value) {
                if (($actual[$id][$field] ?? null) !== $value) {
                    $diffs[] = sprintf("%s %s.%s:\n    Laravel: %s\n    Paket:   %s", $entity, $id, $field, json_encode($value, JSON_UNESCAPED_UNICODE), json_encode($actual[$id][$field] ?? null, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
};

// Kurse und Modulzuordnung
$lv = [];
foreach ($q("SELECT c.code, c.name, c.course_type, m.code AS module, cm.sort_order, cm.required FROM course_definition c LEFT JOIN course_module cm ON cm.course_id = c.id LEFT JOIN module m ON m.id = cm.module_id WHERE c.tenant_id IS NULL ORDER BY c.code, cm.sort_order") as $r) {
    $lv[$r['code']] ??= ['name' => $n($r['name']), 'course_type' => $r['course_type'], 'modules' => []];
    if ($r['module'] !== null) {
        $lv[$r['code']]['modules'][] = [$r['module'], (int) $r['sort_order'], $bool($r['required'])];
    }
}
$pk = [];
foreach ($data('courses') as $c) {
    $pk[$c['code']] = ['name' => $c['name'], 'course_type' => $c['course_type'], 'modules' => array_map(static fn ($m) => [$m['module_code'], $m['sort_order'], $m['required']], $c['modules'])];
}
$compare('course', $lv, $pk);

// Module und Fragenzuordnung
$lv = [];
foreach ($q('SELECT m.code, m.name FROM module m') as $r) {
    $lv[$r['code']] = ['name' => $n($r['name']), 'questions' => []];
}
foreach ($q('SELECT m.code, q.content_id, mc.sort_order FROM module_content mc JOIN module m ON m.id = mc.module_id JOIN content_question q ON q.id = mc.question_id') as $r) {
    $lv[$r['code']]['questions'][$r['content_id']] = $r['sort_order'] === null ? null : (int) $r['sort_order'];
}
$pk = [];
foreach ($data('modules') as $m) {
    $questions = [];
    foreach ($m['questions'] as $link) {
        if ($link['question_source_id'] !== null) {
            $questions[$link['question_source_id']] = $link['sort_order'];
        }
    }
    $pk[$m['code']] = ['name' => $m['name'], 'questions' => $questions];
}
$chartTasks = [];
foreach ($data('questions') as $question) {
    if ($question['revision']['question_type'] === 'chart_task') {
        $chartTasks[$question['source_id']] = true;
    }
}
foreach ($lv as $code => $m) {
    ksort($lv[$code]['questions']);
}
foreach ($pk as $code => $m) {
    $pk[$code]['questions'] = array_diff_key($m['questions'], $chartTasks);
    ksort($pk[$code]['questions']);
}
$compare('module', $lv, $pk);

// Fragen (veröffentlichte Revision)
$answers = [];
foreach ($q('SELECT revision_id, answer_key, answer_text, is_correct, sort_order FROM content_answer ORDER BY revision_id, sort_order, answer_key') as $r) {
    $answers[$r['revision_id']][] = [$r['answer_key'], $n($r['answer_text']), $bool($r['is_correct']), (int) $r['sort_order']];
}
$media = [];
foreach ($q('SELECT qm.revision_id, ma.asset_key, qm.role, qm.sort_order FROM question_media qm JOIN media_asset ma ON ma.id = qm.media_asset_id ORDER BY qm.revision_id, qm.sort_order') as $r) {
    $media[$r['revision_id']][] = [$r['asset_key'], $r['role'], (int) $r['sort_order']];
}
$lv = [];
foreach ($q("SELECT q.content_id, q.official_number, q.content_role, q.language, q.active, r.* FROM content_question q JOIN content_question_revision r ON r.question_id = q.id AND r.editorial_status = 'published'") as $r) {
    $lv[$r['content_id']] = [
        'official_number' => $r['official_number'],
        'content_role' => $r['content_role'],
        'question_type' => $r['question_type'],
        'question_text' => $n($r['question_text']),
        'topic' => $n($r['topic']),
        'subtopic' => $n($r['subtopic']),
        'competency' => $n($r['competency']),
        'smart_category' => $n($r['smartmodus_kategorie']),
        'image_required' => $bool($r['image_required']),
        'feedback_correct' => $n($r['feedback_correct']),
        'feedback_incorrect' => $n($r['feedback_incorrect']),
        'source_catalog' => $n($r['source_catalog']),
        'source_version' => $n($r['source_version']),
        'source_question_id' => $n($r['source_question_id']),
        'rights_status' => $r['rights_status'],
        'answers' => $answers[$r['id']] ?? [],
        'media' => $media[$r['id']] ?? [],
    ];
}
$pk = [];
foreach ($data('questions') as $question) {
    $rev = $question['revision'];
    if ($rev['editorial_status'] !== 'published') {
        continue;
    }
    $pk[$question['source_id']] = [
        'official_number' => $question['official_number'],
        'content_role' => $question['content_role'],
        'question_type' => $rev['question_type'],
        'question_text' => $rev['question_text'],
        'topic' => $rev['topic'],
        'subtopic' => $rev['subtopic'],
        'competency' => $rev['competency'],
        'smart_category' => $rev['smart_category'],
        'image_required' => $rev['image_required'],
        'feedback_correct' => $rev['feedback_correct'],
        'feedback_incorrect' => $rev['feedback_incorrect'],
        'source_catalog' => $rev['source']['catalog'],
        'source_version' => $rev['source']['version'],
        'source_question_id' => $rev['source']['question_id'],
        'rights_status' => $rev['rights_status'],
        'answers' => array_map(static fn ($a) => [$a['answer_key'], $a['answer_text'], $a['is_correct'], $a['sort_order']], $rev['answers']),
        'media' => array_map(static fn ($m) => [$m['media_source_id'], $m['role'], $m['sort_order']], $rev['media']),
    ];
}
$compare('question', $lv, $pk);

// Regelwerke
$blueprints = [];
foreach ($q('SELECT b.rule_set_id, m.code, b.question_count FROM exam_blueprint b JOIN module m ON m.id = b.module_id') as $r) {
    $blueprints[$r['rule_set_id']][$r['code']] = (int) $r['question_count'];
}
$lv = [];
foreach ($q('SELECT r.*, c.code FROM exam_rule_set r JOIN course_definition c ON c.id = r.course_id WHERE c.tenant_id IS NULL') as $r) {
    $rule = json_decode((string) $r['passing_rule'], true);
    $bp = $blueprints[$r['id']] ?? [];
    ksort($bp);
    $lv[$r['code'].'@'.$r['version']] = [
        'time_limit_seconds' => (int) $r['time_limit_seconds'],
        'min_correct_ratio' => round((float) $rule['min_correct_ratio'], 6),
        'parts' => $sortKeys($rule['parts'] ?? null),
        'note' => $rule['note'] ?? null,
        'status' => $r['status'],
        'verified' => $r['verified_at'] !== null,
        'blueprints' => $bp,
    ];
}
$pk = [];
foreach ($data('exam_rules') as $r) {
    $bp = array_column($r['blueprints'], 'question_count', 'module_code');
    ksort($bp);
    $pk[$r['source_id']] = [
        'time_limit_seconds' => $r['time_limit_seconds'],
        'min_correct_ratio' => round((float) $r['passing_rule']['min_correct_ratio'], 6),
        'parts' => $sortKeys($r['passing_rule']['parts'] ?? null),
        'note' => $r['passing_rule']['note'] ?? null,
        'status' => $r['status'],
        'verified' => $r['verified'],
        'blueprints' => $bp,
    ];
}
$compare('exam_rule', $lv, $pk);

// Prüfungsbögen
$lv = [];
foreach ($q('SELECT c.code, p.paper_number, pq.position, q.content_id FROM exam_paper p JOIN course_definition c ON c.id = p.course_id JOIN exam_paper_question pq ON pq.exam_paper_id = p.id JOIN content_question q ON q.id = pq.question_id WHERE c.tenant_id IS NULL ORDER BY c.code, p.paper_number, pq.position') as $r) {
    $lv[sprintf('%s#%02d', $r['code'], $r['paper_number'])]['questions'][] = [(int) $r['position'], $r['content_id']];
}
$pk = [];
foreach ($data('exam_papers') as $p) {
    $pk[$p['source_id']] = ['questions' => array_map(static fn ($x) => [$x['position'], $x['question_source_id']], $p['questions'])];
}
$compare('exam_paper', $lv, $pk);

// Navigationsaufgaben (Laravel: Kopie je Kurs; Paket: einmal mit course_codes)
$lv = [];
foreach ($q('SELECT c.code, t.task_number, t.scenario_text, t.hint, nq.question_number, nq.question_text, nq.answer_text FROM navigation_task t JOIN course_definition c ON c.id = t.course_id JOIN navigation_question nq ON nq.navigation_task_id = t.id WHERE c.tenant_id IS NULL ORDER BY c.code, t.task_number, nq.question_number') as $r) {
    $key = $r['code'].'/'.sprintf('NAV-%02d', $r['task_number']);
    $lv[$key] ??= ['scenario_text' => $n($r['scenario_text']), 'hint' => $n($r['hint']), 'questions' => []];
    $lv[$key]['questions'][] = [(int) $r['question_number'], $n($r['question_text']), $n($r['answer_text'])];
}
$pk = [];
foreach ($data('navigation_tasks') as $t) {
    foreach ($t['course_codes'] as $code) {
        $pk[$code.'/'.$t['source_id']] = ['scenario_text' => $t['scenario_text'], 'hint' => $t['hint'], 'questions' => array_map(static fn ($x) => [$x['question_number'], $x['question_text'], $x['answer_text']], $t['questions'])];
    }
}
$compare('navigation_task', $lv, $pk);

// Praxistrainer
$lv = [];
foreach ($q('SELECT c.code, p.*, ma.asset_key FROM praxis_task p JOIN course_definition c ON c.id = p.course_id LEFT JOIN media_asset ma ON ma.id = p.media_asset_id WHERE c.tenant_id IS NULL') as $r) {
    $asset = $r['asset_key'] === null ? null : preg_replace('#^praxistrainer_#', 'praxistrainer/', $r['asset_key']);
    $lv[$r['content_id']] = [
        'course_code' => $r['code'], 'category' => $n($r['kategorie']), 'subcategory' => $n($r['unterkategorie']),
        'exam_reference' => $n($r['pruefungsbezug']), 'task_type' => $n($r['aufgabentyp']), 'question' => $n($r['frage']),
        'answer' => $n($r['antwort']), 'explanation' => $n($r['erklaerung']), 'media' => $asset,
        'source' => $n($r['quelle']), 'source_url' => $n($r['quellen_url']), 'sort_order' => (int) $r['sort_order'],
    ];
}
$pk = [];
foreach ($data('praxis_tasks') as $p) {
    $pk[$p['source_id']] = [
        'course_code' => $p['course_code'], 'category' => $p['category'], 'subcategory' => $p['subcategory'],
        'exam_reference' => $p['exam_reference'], 'task_type' => $p['task_type'], 'question' => $p['question'],
        'answer' => $p['answer'], 'explanation' => $p['explanation'], 'media' => $p['media_source_id'],
        'source' => $p['source'], 'source_url' => $p['source_url'], 'sort_order' => $p['sort_order'],
    ];
}
$compare('praxis_task', $lv, $pk);

// Medien
$lv = [];
foreach ($q('SELECT asset_key FROM media_asset') as $r) {
    $lv[preg_replace('#^praxistrainer_#', 'praxistrainer/', $r['asset_key'])] = [];
}
$pk = array_fill_keys(array_column($data('media'), 'source_id'), []);
$compare('media', $lv, $pk);

$manifest = json_decode((string) file_get_contents($package.'/manifest.json'), true);
printf("Paket %s gegen Laravel-Datenbank\n", $manifest['package_id']);
printf("Bewusste Abweichung: %d freie Kartenaufgaben (chart_task) nur im Paket.\n", count($chartTasks));
if ($diffs === []) {
    echo "Keine Abweichungen.\n";
    exit(0);
}
printf("%d Abweichungen:\n", count($diffs));
foreach (array_slice($diffs, 0, 200) as $d) {
    echo '  '.$d."\n";
}
exit(1);
