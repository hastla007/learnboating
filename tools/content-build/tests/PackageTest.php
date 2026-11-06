<?php

declare(strict_types=1);

namespace ContentBuild\Tests;

use ContentBuild\Json;
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
}
