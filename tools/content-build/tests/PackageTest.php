<?php

declare(strict_types=1);

namespace ContentBuild\Tests;

use ContentBuild\Json;
use ContentBuild\NavigationAnswerValues;
use ContentBuild\PackageBuilder;
use ContentBuild\PackageWriter;
use ContentBuild\Report;
use ContentBuild\SourceInfo;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase
{
    private const SCHEMA_BASE = 'https://schema.learnboating.invalid/v1/';

    private static string $root;
    private static string $dir;
    /** @var array<string,mixed> */
    private static array $manifest;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 3);
        self::$dir = sys_get_temp_dir().'/content-build-test-'.getmypid();
        self::$manifest = self::buildInto(self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_dir(self::$dir)) {
            PackageWriter::removeDir(self::$dir);
        }
    }

    /** @return array<string,mixed> */
    private static function buildInto(string $dir, ?string $previous = null): array
    {
        $snapshot = self::$root.'/content-source/boatingschool';
        $report = new Report();
        $builder = new PackageBuilder($snapshot, $report);
        $entities = $builder->build();

        return (new PackageWriter())->write($dir, $entities, $builder->mediaFiles(), SourceInfo::read($snapshot), $builder, $report, $previous);
    }

    /** @return mixed */
    private static function data(string $entity): mixed
    {
        return Json::decodeFile(self::$dir.'/data/'.$entity.'.json');
    }

    public function testSnapshotMatchesChecksums(): void
    {
        self::assertSame([], SourceInfo::verify(self::$root.'/content-source/boatingschool'));
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function schemaFiles(): iterable
    {
        yield 'manifest' => ['manifest.json', 'manifest.schema.json'];
        foreach (PackageWriter::ENTITY_FILES as $entity) {
            yield $entity => ['data/'.$entity.'.json', $entity.'.schema.json'];
        }
    }

    #[DataProvider('schemaFiles')]
    public function testFileMatchesSchema(string $file, string $schema): void
    {
        $validator = new Validator();
        $validator->resolver()->registerPrefix(self::SCHEMA_BASE, self::$root.'/schema/v1/');
        $validator->setMaxErrors(5);
        $data = json_decode((string) file_get_contents(self::$dir.'/'.$file), false, 512, JSON_THROW_ON_ERROR);
        $result = $validator->validate($data, self::SCHEMA_BASE.$schema);
        $errors = $result->isValid() ? [] : (new ErrorFormatter())->format($result->error());
        self::assertTrue($result->isValid(), $file.': '.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function testQuestionCountsMatchSourceAtPinnedCommit(): void
    {
        // Prüfstand Commit 136a243 (Konzept Kap. 2.1). Ändert sich der Snapshot,
        // sind diese Zahlen bewusst neu zu bestätigen.
        $counts = self::$manifest['counts'];
        self::assertSame(989, $counts['questions']);
        self::assertSame(['chart_task' => 15, 'multi_choice' => 100, 'open_text' => 51, 'single_choice' => 823], $counts['question_types']);
        self::assertSame(14, $counts['courses']);
        self::assertSame(9, $counts['modules']);
        self::assertSame(15, $counts['navigation_tasks']);
        self::assertSame(135, $counts['navigation_questions']);
        self::assertSame(90, $counts['video_modules']);
        self::assertSame(449, $counts['video_lessons']);
        self::assertSame(1349, $counts['video_lesson_questions']);
        self::assertSame(43, $counts['radio_texts']);
        self::assertSame(410, $counts['radio_vocabulary']);
        self::assertSame(72, $counts['radio_practice']);
        self::assertSame(1, $counts['device_simulation']);
        self::assertSame(18, $counts['exam_committees']);
        self::assertSame(6, $counts['exam_guides']);
    }

    public function testExamGuidesCoverEveryCourse(): void
    {
        $seen = [];
        foreach (self::data('exam_guides') as $guide) {
            foreach ($guide['courses'] as $c) {
                $seen[$c['course_code']][] = $guide['source_id'];
                self::assertContains($c['first_variant'], array_column($guide['variants'], 'key'), $guide['source_id']);
            }
            self::assertNotEmpty($guide['faq']);
            self::assertCount(2, $guide['providers']);
        }
        self::assertCount(14, $seen, 'alle 14 Kurse inkl. FKN haben eine Anleitung');
        self::assertSame(['FKN'], $seen['FKN']);
        self::assertSame(['SBF-SEE', 'SBF-BIN'], $seen['SBF-SEE-BIN']);
    }

    public function testSourceIdsAreUnique(): void
    {
        foreach (PackageWriter::ENTITY_FILES as $entity) {
            $ids = array_column(self::data($entity), 'source_id');
            self::assertSame(count($ids), count(array_unique($ids)), "Doppelte source_id in {$entity}");
        }
    }

    public function testReferencesResolve(): void
    {
        $questions = array_column(self::data('questions'), null, 'source_id');
        $modules = array_column(self::data('modules'), null, 'code');
        $courses = array_column(self::data('courses'), null, 'code');
        $media = array_column(self::data('media'), null, 'source_id');
        $problems = [];
        $need = static function (bool $ok, string $message) use (&$problems): void {
            if (! $ok) {
                $problems[] = $message;
            }
        };

        foreach ($modules as $code => $module) {
            foreach ($module['questions'] as $link) {
                $need(isset($questions[$link['question_source_id']]), "Modul {$code}: Frage {$link['question_source_id']} fehlt");
            }
        }
        foreach ($courses as $code => $course) {
            foreach ($course['modules'] as $link) {
                $need(isset($modules[$link['module_code']]), "Kurs {$code}: Modul {$link['module_code']} fehlt");
            }
        }
        foreach (self::data('exam_papers') as $paper) {
            $need(isset($courses[$paper['course_code']]), "Bogen {$paper['source_id']}: Kurs fehlt");
            foreach ($paper['questions'] as $q) {
                $id = $q['question_source_id'];
                $need(isset($questions[$id]), "Bogen {$paper['source_id']}: Frage {$id} fehlt");
                $need(($questions[$id]['revision']['editorial_status'] ?? null) === 'published', "Bogen {$paper['source_id']}: Frage {$id} nicht veröffentlicht");
            }
        }
        foreach (self::data('exam_rules') as $rule) {
            $courseModules = array_column($courses[$rule['course_code']]['modules'] ?? [], 'module_code');
            $need($courseModules === array_column($rule['blueprints'], 'module_code'), "Regelwerk {$rule['source_id']}: Blueprints passen nicht zu den Kursmodulen");
        }
        foreach ($questions as $id => $q) {
            foreach ($q['revision']['media'] as $m) {
                $need(isset($media[$m['media_source_id']]), "Frage {$id}: Medium {$m['media_source_id']} fehlt");
            }
        }
        foreach (self::data('praxis_tasks') as $task) {
            $need(isset($courses[$task['course_code']]), "Praxis {$task['source_id']}: Kurs fehlt");
            $need($task['media_source_id'] === null || isset($media[$task['media_source_id']]), "Praxis {$task['source_id']}: Medium fehlt");
        }
        $lessonIds = [];
        foreach (self::data('video_modules') as $module) {
            $need(isset($courses[$module['course_code']]), "Videokapitel {$module['source_id']}: Kurs fehlt");
            foreach ($module['lessons'] as $lesson) {
                $lessonIds[] = $lesson['source_id'];
                foreach ($lesson['questions'] as $q) {
                    $need(($questions[$q['question_source_id']]['revision']['editorial_status'] ?? null) === 'published', "Lektion {$lesson['source_id']}: Frage {$q['question_source_id']} fehlt/unveröffentlicht");
                }
            }
        }
        $need(count($lessonIds) === count(array_unique($lessonIds)), 'Lektions-IDs eindeutig');
        foreach (array_merge(self::data('radio_texts'), self::data('radio_vocabulary')) as $item) {
            foreach ($item['course_codes'] as $code) {
                $need(isset($courses[$code]), "Funk {$item['source_id']}: Kurs {$code} fehlt");
            }
        }
        foreach ($media as $id => $m) {
            $file = self::$dir.'/'.$m['path'];
            $need(is_file($file) && hash_file('sha256', $file) === $m['sha256'], "Medium {$id}: Datei fehlt oder Prüfsumme falsch");
        }

        self::assertSame([], $problems);
    }

    public function testManifestListsEveryFileWithChecksum(): void
    {
        $listed = array_column(self::$manifest['files'], 'sha256', 'path');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen(self::$dir) + 1);
            if ($relative === 'manifest.json') {
                continue;
            }
            self::assertArrayHasKey($relative, $listed, "Nicht im Manifest: {$relative}");
            self::assertSame($listed[$relative], hash_file('sha256', $file->getPathname()));
        }
    }

    public function testBuildIsDeterministicAndDetectsNoRemovals(): void
    {
        $second = sys_get_temp_dir().'/content-build-test2-'.getmypid();
        try {
            $manifest = self::buildInto($second, self::$dir);
            self::assertSame(self::$manifest['package_id'], $manifest['package_id']);
            self::assertSame(self::$manifest['content_sha256'], $manifest['content_sha256']);
            self::assertSame(self::$manifest['package_id'], $manifest['previous_package_id']);
            self::assertSame([], array_filter($manifest['warnings'], static fn ($w) => $w['code'] === 'source_removed'));
        } finally {
            PackageWriter::removeDir($second);
        }
    }

    public function testChartTasksAreNotPublishedAndNotInPapers(): void
    {
        $chart = array_filter(self::data('questions'), static fn ($q) => $q['revision']['question_type'] === 'chart_task');
        self::assertCount(15, $chart);
        foreach ($chart as $q) {
            self::assertSame('draft', $q['revision']['editorial_status']);
            self::assertSame('SBF_SEE', $q['source_module']);
        }
    }

    public function testFeedbackIsStrippedLikeSource(): void
    {
        foreach (self::data('questions') as $q) {
            $r = $q['revision'];
            self::assertFalse(str_starts_with((string) $r['feedback_correct'], 'Richtig.'), $q['source_id']);
            self::assertFalse(str_starts_with((string) $r['feedback_incorrect'], 'Falsch.'), $q['source_id']);
            self::assertDoesNotMatchRegularExpression('/^Richtig ist: „/u', (string) $r['feedback_incorrect'], $q['source_id']);
        }
    }

    public function testModelSolutionsPassTheirOwnChecks(): void
    {
        // Wie FunkPraxis::evaluate(): Großschreibung, Umlaute gefaltet, Satzzeichen weg.
        $fold = static fn (string $t): string => strtr($t, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ẞ' => 'SS', 'ß' => 'SS']);
        $normalize = static fn (string $t): string => ' '.trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $fold(mb_strtoupper($t)))).' ';
        $failed = [];
        foreach (self::data('radio_practice') as $task) {
            foreach ($task['fields'] as $field) {
                if ($field['name'] !== 'message') {
                    continue;
                }
                foreach ($field['checks'] as $c) {
                    if (preg_match_all('/'.$fold($c['pattern']).'/u', $normalize($field['solution'])) < $c['min']) {
                        $failed[] = "{$task['source_id']}: {$c['label']}";
                    }
                }
            }
        }
        foreach (self::data('device_simulation')[0]['lessons'] as $lesson) {
            foreach ($lesson['steps'] as $i => $step) {
                foreach ($step['speech']['checks'] ?? [] as $c) {
                    if (preg_match_all('/'.$fold($c['pattern']).'/u', $normalize($step['speech']['solution'])) < $c['min']) {
                        $failed[] = "{$lesson['key']}#{$i}: {$c['label']}";
                    }
                }
            }
        }
        self::assertSame([], $failed);
    }

    public function testUbiErgaenzungReplacesUbiInCombinedCourses(): void
    {
        $courses = array_column(self::data('courses'), null, 'code');
        self::assertSame(['SRC', 'UBI_ERG'], array_column($courses['SRC-UBI']['modules'], 'module_code'));
        self::assertSame(['UBI'], array_column($courses['UBI']['modules'], 'module_code'));
        $modules = array_column(self::data('modules'), null, 'code');
        self::assertCount(79, $modules['UBI_ERG']['questions']);
    }

    public function testNavigationAnswerValuesFollowOfficialResults(): void
    {
        $parts = [];
        foreach (self::data('navigation_tasks') as $task) {
            foreach ($task['questions'] as $q) {
                $parts[$q['source_id']] = $q;
            }
        }
        $withValues = array_filter($parts, static fn ($q) => isset($q['answer_values']));
        self::assertCount(78, $withValues, 'Teilaufgaben mit prüfbaren Werten (Prüftabelle: bin/navigation-values.php review)');
        $byQuantity = [];
        foreach ($withValues as $q) {
            foreach ($q['answer_values'] as $v) {
                $byQuantity[$v['quantity']] = ($byQuantity[$v['quantity']] ?? 0) + 1;
            }
        }
        ksort($byQuantity);
        self::assertSame(['angle' => 4, 'bearing' => 26, 'course' => 27, 'distance' => 9, 'duration' => 3, 'position' => 17, 'speed' => 4, 'time' => 5], $byQuantity);
        // Berichtigung (Verkehrsblatt 15.01.2013): angezeigter MgK 074°.
        self::assertSame(74, $parts['NAV-01-02']['answer_values'][0]['value']);
        self::assertSame(['BV Richtung', 'BV Distanz'], array_column($parts['NAV-01-08']['answer_values'], 'label'));
        self::assertSame([103, 0.8], array_column($parts['NAV-01-08']['answer_values'], 'value'));
        self::assertSame(['lat' => ['deg' => 53, 'min' => 55.8, 'hemisphere' => 'N'], 'lon' => ['deg' => 7, 'min' => 51.3, 'hemisphere' => 'E']], $parts['NAV-01-05']['answer_values'][0]['value']);
        self::assertSame('15:12', $parts['NAV-03-05']['answer_values'][0]['value']);
        self::assertArrayNotHasKey('answer_values', $parts['NAV-01-07'], '„Siehe Karte“ ohne Werte');
        self::assertArrayNotHasKey('answer_values', $parts['NAV-01-09'], 'Beschreibung ohne Werte');
        // Feldreihenfolge: answer_values direkt nach answer_text.
        self::assertSame(['source_id', 'question_number', 'question_text', 'answer_text', 'answer_values', 'sort_order'], array_keys($parts['NAV-01-01']));
    }

    public function testNavigationAnswerValuesValidation(): void
    {
        $answers = ['NAV-01-01' => 'rwk = 079°', 'NAV-01-05' => "53° 55,8' N 007° 51,3' E", 'NAV-03-05' => '15:12 Uhr'];
        $ok = ['label' => 'rwK', 'quantity' => 'course', 'value' => 79, 'unit' => '°', 'tolerance' => 2];
        self::assertSame([], NavigationAnswerValues::validate(['NAV-01-01' => [$ok]], $answers));
        $errors = NavigationAnswerValues::validate([
            'NAV-99-01' => [$ok],
            'NAV-01-01' => [
                ['unit' => 'sm'] + $ok,
                ['value' => 80] + $ok,
                ['tolerance' => 5] + $ok,
                ['quantity' => 'depth'] + $ok,
                $ok + ['extra' => 1],
            ],
            'NAV-01-05' => [['label' => 'Position', 'quantity' => 'position', 'value' => ['lat' => ['deg' => 53, 'min' => 55.9, 'hemisphere' => 'N'], 'lon' => ['deg' => 7, 'min' => 51.3, 'hemisphere' => 'E']], 'unit' => "'", 'tolerance' => 0.3]],
            'NAV-03-05' => [['label' => 'Uhrzeit', 'quantity' => 'time', 'value' => '15:21', 'unit' => 'Uhr', 'tolerance' => 3]],
        ], $answers);
        self::assertCount(8, $errors, implode("\n", $errors));
        self::assertStringContainsString('NAV-99-01: Teilaufgabe gibt es nicht', $errors[0]);
        self::assertStringContainsString('Einheit „sm“ passt nicht zu course', $errors[1]);
        self::assertStringContainsString('Wert 80 steht nicht im amtlichen Ergebnis', $errors[2]);
        self::assertStringContainsString('Toleranz muss 2 sein', $errors[3]);
        self::assertStringContainsString('unbekannte Größe depth', $errors[4]);
        self::assertStringContainsString('Felder label', $errors[5]);
        self::assertStringContainsString('Position lat steht so nicht', $errors[6]);
        self::assertStringContainsString('Uhrzeit 15:21 steht nicht', $errors[7]);
    }

    public function testInvalidNavigationOverlayStopsTheBuild(): void
    {
        $file = sys_get_temp_dir().'/nav-values-'.getmypid().'.json';
        file_put_contents($file, json_encode(['values' => ['NAV-01-01' => [['label' => 'rwK', 'quantity' => 'course', 'value' => 80, 'unit' => '°', 'tolerance' => 2]]]]));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/navigation_answer_values\.json ungültig.*Wert 80/s');
            (new PackageBuilder(self::$root.'/content-source/boatingschool', new Report(), $file))->build();
        } finally {
            unlink($file);
        }
    }

    public function testCombosAreVerifiedBecauseTheyMatchTheirSingleRules(): void
    {
        $rules = array_column(self::data('exam_rules'), null, 'course_code');
        foreach (PackageBuilder::COMBO_VERIFIED_FROM as $combo => $singles) {
            self::assertSame('published', $rules[$combo]['status'], $combo);
            self::assertTrue($rules[$combo]['verified'], $combo);
            // Herleitung nachgerechnet: Teile und Zeit der Kombi = Teile und Zeiten der verifizierten Einzelregelwerke.
            $expected = [];
            $seconds = 0;
            foreach ($singles as $single) {
                self::assertTrue($rules[$single]['verified'], $single);
                $seconds += $rules[$single]['time_limit_seconds'];
                $expected = array_merge($expected, array_map(static fn ($p) => [$p['module'], $p['min_correct'], $p['of']], $rules[$single]['passing_rule']['parts'] ?? [[
                    'module' => $rules[$single]['blueprints'][0]['module_code'],
                    'min_correct' => (int) round($rules[$single]['passing_rule']['min_correct_ratio'] * $rules[$single]['blueprints'][0]['question_count']),
                    'of' => $rules[$single]['blueprints'][0]['question_count'],
                ]]));
            }
            $actual = array_map(static fn ($p) => [$p['module'], $p['min_correct'], $p['of']], $rules[$combo]['passing_rule']['parts']);
            sort($expected);
            sort($actual);
            self::assertSame($expected, $actual, $combo);
            self::assertSame($seconds, $rules[$combo]['time_limit_seconds'], $combo);
        }
        // Ergänzung FKN → SKN: Prüfungsform nicht belegt → volle SKN-Prüfung simulieren, aber Entwurf (Übungsmodus).
        $addon = $rules['ADD-FKN-TO-SKN'];
        self::assertSame('draft', $addon['status']);
        self::assertFalse($addon['verified']);
        self::assertSame($rules['SKN']['time_limit_seconds'], $addon['time_limit_seconds']);
        self::assertSame($rules['SKN']['blueprints'], $addon['blueprints']);
        self::assertSame($rules['SKN']['passing_rule']['min_correct_ratio'], $addon['passing_rule']['min_correct_ratio']);
        self::assertSame(PackageBuilder::ADDON_AS_FULL_NOTE, $addon['passing_rule']['note']);
    }

    public function testComboCheckRejectsDeviatingParts(): void
    {
        $singles = [
            'A' => ['parts' => [['module' => 'X', 'min_correct' => 19, 'of' => 24]], 'seconds' => 1800],
            'B' => ['parts' => [['module' => 'Y', 'min_correct' => 8, 'of' => 10]], 'seconds' => 1800],
        ];
        $combo = [
            ['module' => 'X', 'min_correct' => 19, 'of' => 24, 'seconds' => 1800],
            ['module' => 'Y', 'min_correct' => 8, 'of' => 10, 'seconds' => 1800],
        ];
        self::assertTrue(PackageBuilder::comboMatchesSingles($combo, $singles));
        $lower = $combo;
        $lower[1]['min_correct'] = 7;
        self::assertFalse(PackageBuilder::comboMatchesSingles($lower, $singles), 'andere Grenze');
        $longer = $combo;
        $longer[0]['seconds'] = 2400;
        self::assertFalse(PackageBuilder::comboMatchesSingles($longer, $singles), 'andere Zeit');
        self::assertFalse(PackageBuilder::comboMatchesSingles([$combo[0]], $singles), 'Teil fehlt');
    }

    public function testAddonCoursesGetTheRegionalCardsOfTheirTargetLicence(): void
    {
        $byCourse = [];
        foreach (self::data('praxis_tasks') as $task) {
            $byCourse[$task['course_code']][$task['category']][] = $task;
        }
        foreach (PackageBuilder::PRAXIS_ADDON_CARDS as $target => [$source, $suffix, $categories]) {
            foreach ($categories as $category) {
                self::assertArrayHasKey($category, $byCourse[$source], "{$source}: {$category} gibt es in der Quelle");
                self::assertCount(count($byCourse[$source][$category]), $byCourse[$target][$category] ?? [], "{$target}: {$category}");
                foreach ($byCourse[$target][$category] as $task) {
                    self::assertSame($task['copied_from'].$suffix, $task['source_id']);
                }
            }
        }
        self::assertArrayHasKey('Pflichtmanöver', $byCourse['ADD-BIN-TO-SEE'], 'Pflichtmanöver der Quelle bleiben');
        self::assertArrayNotHasKey('Pflichtmanöver', $byCourse['ADD-SEE-TO-BIN'], 'Praxis See → Binnen wird angerechnet');
        self::assertArrayNotHasKey('Seemannsknoten', $byCourse['ADD-SEE-TO-BIN']);
    }

    /**
     * FKN/SKN-Fragen, bei denen alle Optionen richtig sind, stehen so im amtlichen Katalog („Es können mehrere Antworten
     * richtig sein, mindestens eine ist immer richtig“, skn_catalog.php). Wer überall alles ankreuzt, darf damit trotzdem
     * keinen Bogen und keine Zufallsprüfung bestehen.
     */
    public function testNoExamCanBePassedByTickingEveryOption(): void
    {
        $allCorrect = [];
        foreach (self::data('questions') as $q) {
            $answers = $q['revision']['answers'];
            if (in_array($q['revision']['question_type'], ['single_choice', 'multi_choice'], true) && $answers !== [] && count(array_filter($answers, static fn ($a) => $a['is_correct'])) === count($answers)) {
                $allCorrect[$q['source_id']] = true;
            }
        }
        self::assertCount(11, $allCorrect, 'FKN 3, 7, 13, 30, 32 und SKN 4, 8, 15, 32, 44, 46 (wie im Katalog)');
        $rules = array_column(self::data('exam_rules'), null, 'course_code');
        foreach (self::data('exam_papers') as $paper) {
            $required = (int) ceil($rules[$paper['course_code']]['passing_rule']['min_correct_ratio'] * count($paper['questions']) - 1e-9);
            $free = count(array_filter($paper['questions'], static fn ($x) => isset($allCorrect[$x['question_source_id']])));
            self::assertLessThan($required, $free, $paper['source_id']);
        }
        $modules = array_column(self::data('modules'), null, 'code');
        foreach ($rules as $code => $rule) {
            $total = array_sum(array_column($rule['blueprints'], 'question_count'));
            $free = 0;
            foreach ($rule['blueprints'] as $bp) {
                $free += min($bp['question_count'], count(array_filter($modules[$bp['module_code']]['questions'], static fn ($m) => isset($allCorrect[$m['question_source_id']]))));
            }
            self::assertLessThan((int) ceil($rule['passing_rule']['min_correct_ratio'] * $total - 1e-9), $free, $code);
        }
    }
}
