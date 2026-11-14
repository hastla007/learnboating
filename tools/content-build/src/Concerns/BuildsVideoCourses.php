<?php

declare(strict_types=1);

namespace ContentBuild\Concerns;

use ContentBuild\SourceLiterals;
use ContentBuild\Text;

/**
 * Videokurse wie die Video-Seeder der Quelle, in der Reihenfolge von
 * DatabaseSeeder: VideoCourse → Seemannschaft → VideoLessonQuestion → Combo →
 * Binnen → BinnenSegeln → BinnenSegelnAddon → FknSkn → Funk → BinnenKnoten →
 * *LessonQuestion → AddSeeToBin → AddBinToSee. Daten werden als Literale aus
 * den Seeder-Dateien gelesen; nur die Logik ist hier nachgebildet.
 *
 * Quizfragen hängen wie in der Quelle nur an Lektionen, die noch keine haben
 * (SeedsLessonQuizOnce); Kopien übernehmen Schritte, Fragen und Reihenfolge.
 */
trait BuildsVideoCourses
{
    /** @var array<int,array{course:string,title:string,sort_order:int}> */
    private array $vModules = [];

    /** @var array<int,array{module:int,title:string,video_url:?string,duration:?int,sort_order:int,steps:list<array{title:string,sort_order:int}>,questions:array<string,int>,quiz_seeded:bool}> */
    private array $vLessons = [];

    private int $vNextId = 1;

    private function seederFile(string $class): string
    {
        return $this->snapshotDir.'/database/seeders/'.$class.'.php';
    }

    private function supportFile(string $class): string
    {
        return $this->snapshotDir.'/app/Support/'.$class.'.php';
    }

    private function buildVideoCourses(): void
    {
        $placeholder = SourceLiterals::constant($this->seederFile('VideoCourseSeeder'), 'PLACEHOLDER_VIDEO');

        // VideoCourseSeeder (SBF-SEE)
        $knotSteps = SourceLiterals::variable($this->seederFile('VideoCourseSeeder'), 'knotSteps');
        $this->vCreateChapters('SBF-SEE', SourceLiterals::variable($this->seederFile('VideoCourseSeeder'), 'chapters'), $placeholder, $knotSteps);

        // SeemannschaftVideoSeeder: neue Kapitel einschieben, Lektionen anhängen
        $file = $this->seederFile('SeemannschaftVideoSeeder');
        foreach (SourceLiterals::constant($file, 'NEW_CHAPTERS') as [$title, $beforeTitle]) {
            if ($this->vModule('SBF-SEE', $title) !== null) {
                continue;
            }
            $before = $this->vModule('SBF-SEE', $beforeTitle);
            if ($before === null) {
                continue;
            }
            $beforeSort = $this->vModules[$before]['sort_order'];
            foreach ($this->vModules as $id => $m) {
                if ($m['course'] === 'SBF-SEE' && $m['sort_order'] > $beforeSort) {
                    $this->vModules[$id]['sort_order']++;
                }
            }
            $this->vAddModule('SBF-SEE', $title, $beforeSort + 1);
        }
        foreach (SourceLiterals::constant($file, 'EXTRA_LESSONS') as $chapterTitle => $lessons) {
            $module = $this->vModule('SBF-SEE', $chapterTitle);
            if ($module === null) {
                continue;
            }
            $next = max([0, ...array_column($this->vLessonsOf($module), 'sort_order')]);
            foreach ($lessons as [$title, $duration]) {
                if ($this->vLesson($module, $title) === null) {
                    $this->vAddLesson($module, $title, $placeholder, $duration, ++$next);
                }
            }
        }

        // VideoLessonQuestionSeeder (SBF-SEE)
        foreach (SourceLiterals::constant($this->seederFile('VideoLessonQuestionSeeder'), 'LESSON_QUESTIONS') as $chapterTitle => $lessons) {
            $module = $this->vModule('SBF-SEE', $chapterTitle);
            if ($module === null) {
                $this->report->warn('video_chapter_missing', 'video_modules', 'SBF-SEE::'.$chapterTitle, 'Kapitel für Quizfragen fehlt.');
                continue;
            }
            foreach ($lessons as $lessonTitle => $questions) {
                $this->vSeedQuiz($this->vLesson($module, $lessonTitle), fn (int $lesson) => $this->vAttachMatched($lesson, $questions));
            }
        }

        // ComboVideoCourseSeeder (SBF-SEE-BIN)
        $this->vCopyCourse('SBF-SEE', 'SBF-SEE-BIN', null, true, null);
        $sort = count($this->vModulesOf('SBF-SEE-BIN'));
        foreach (SourceLiterals::variable($this->seederFile('ComboVideoCourseSeeder'), 'binnenChapters') as $title => $lessons) {
            $module = $this->vAddModule('SBF-SEE-BIN', $title, ++$sort);
            $i = 0;
            foreach ($lessons as [$lessonTitle, $duration]) {
                $this->vAddLesson($module, $lessonTitle, $placeholder, $duration, ++$i);
            }
        }

        // BinnenVideoCourseSeeder (SBF-BIN-MOTOR): Binnen-Kapitel des Kombikurses ohne Quiz
        $groups = SourceLiterals::constant($this->supportFile('ComboVideoGroups'), 'GROUPS');
        $binnenTitles = [];
        foreach ($groups['SBF-SEE-BIN'] ?? [] as $group) {
            if ($group['label'] === 'SBF Binnen') {
                $binnenTitles = $group['chapters'];
            }
        }
        $this->vCopyCourse('SBF-SEE-BIN', 'SBF-BIN-MOTOR', static fn (string $t) => in_array($t, $binnenTitles, true), false, null);

        // BinnenSegeln- und BinnenSegelnAddon-VideoCourseSeeder
        $this->vCreateChapters('SBF-BIN-SEGEL', SourceLiterals::variable($this->seederFile('BinnenSegelnVideoCourseSeeder'), 'chapters'), $placeholder, []);
        $this->vCreateChapters('ADD-BIN-SEGEL', SourceLiterals::variable($this->seederFile('BinnenSegelnAddonVideoCourseSeeder'), 'chapters'), $placeholder, []);

        // FknSknVideoCourseSeeder
        $file = $this->seederFile('FknSknVideoCourseSeeder');
        $videos = SourceLiterals::constant($file, 'VIDEOS');
        foreach (['FKN', 'SKN', 'ADD-FKN-TO-SKN'] as $code) {
            $chapters = [];
            foreach (SourceLiterals::arrayAfter($file, "buildCourse('{$code}',") as $title => $numbers) {
                $chapters[$title] = array_map(static fn (int $n) => $videos[$n], $numbers);
            }
            $this->vCreateChapters($code, $chapters, $placeholder, []);
        }

        // FunkVideoCourseSeeder
        $file = $this->seederFile('FunkVideoCourseSeeder');
        $src = SourceLiterals::constant($file, 'SRC_CHAPTERS');
        $ubi = SourceLiterals::constant($file, 'UBI_CHAPTERS');
        foreach (['SRC' => $src, 'UBI' => $ubi, 'ADD-UBI-TO-SRC' => $ubi, 'SRC-UBI' => $src + $ubi] as $code => $chapters) {
            $this->vCreateChapters($code, $chapters, $placeholder, []);
        }

        // BinnenKnotenVideoSeeder: Knoten-Kapitel von SBF-SEE an SBF-BIN-MOTOR anhängen
        if ($this->vModule('SBF-BIN-MOTOR', 'Knoten') === null && ($source = $this->vModule('SBF-SEE', 'Knoten')) !== null) {
            $next = max([0, ...array_column($this->vModulesOf('SBF-BIN-MOTOR'), 'sort_order')]) + 1;
            $target = $this->vAddModule('SBF-BIN-MOTOR', 'Knoten', $next);
            $this->vCopyLessons($source, $target, false, null);
        }

        // BinnenLessonQuestionSeeder
        foreach (['SBF-SEE-BIN', 'SBF-BIN-MOTOR'] as $code) {
            foreach (SourceLiterals::constant($this->seederFile('BinnenLessonQuestionSeeder'), 'LESSON_QUESTIONS') as $chapterTitle => $lessons) {
                $module = $this->vModule($code, $chapterTitle);
                if ($module === null) {
                    continue;
                }
                foreach ($lessons as $lessonTitle => $questions) {
                    $this->vSeedQuiz($this->vLesson($module, $lessonTitle), fn (int $lesson) => $this->vAttachMatched($lesson, $questions));
                }
            }
        }

        // BinnenSegeln(Addon)LessonQuestionSeeder: amtliche Nummern
        $segelRole = static fn (int $n): string => $n <= 72 ? 'shared_basis' : ($n <= 253 ? 'binnen_specific' : 'segel_specific');
        foreach ([['BinnenSegelnLessonQuestionSeeder', 'SBF-BIN-SEGEL', $segelRole], ['BinnenSegelnAddonLessonQuestionSeeder', 'ADD-BIN-SEGEL', static fn (int $n): string => 'segel_specific']] as [$class, $code, $role]) {
            foreach (SourceLiterals::constant($this->seederFile($class), 'LESSON_QUESTIONS') as $chapterTitle => $lessons) {
                $module = $this->vModule($code, $chapterTitle);
                if ($module === null) {
                    continue;
                }
                foreach ($lessons as $lessonTitle => $numbers) {
                    $this->vSeedQuiz($this->vLesson($module, $lessonTitle), function (int $lesson) use ($numbers, $role): void {
                        foreach ($numbers as $i => $n) {
                            $id = $this->byRoleNumber[$role((int) $n).'#'.$n] ?? null;
                            if ($id !== null) {
                                $this->vLessons[$lesson]['questions'][$id] = $i + 1;
                            }
                        }
                    });
                }
            }
        }

        // FknSknLessonQuestionSeeder: nach Lektionstitel über alle Kapitel
        $file = $this->seederFile('FknSknLessonQuestionSeeder');
        $fkn = SourceLiterals::constant($file, 'FKN_QUESTIONS');
        $skn = SourceLiterals::constant($file, 'SKN_QUESTIONS');
        foreach ([['FKN', 'fkn_official', $fkn], ['SKN', 'skn_official', $skn], ['ADD-FKN-TO-SKN', 'skn_official', $skn]] as [$code, $role, $map]) {
            foreach ($this->vModulesOf($code) as $moduleId => $module) {
                foreach ($this->vLessonsOf($moduleId) as $lessonId => $lesson) {
                    $numbers = $map[$lesson['title']] ?? null;
                    if ($numbers) {
                        $this->vSeedQuiz($lessonId, function (int $l) use ($numbers, $role): void {
                            foreach ($numbers as $i => $n) {
                                $id = $this->byRoleNumber[$role.'#'.$n] ?? null;
                                if ($id !== null) {
                                    $this->vLessons[$l]['questions'][$id] = $i + 1;
                                }
                            }
                        });
                    }
                }
            }
        }

        // FunkLessonQuestionSeeder: Zuordnung per content_id aus funk_video_quiz.php
        $quiz = $this->includeData('funk_video_quiz.php');
        foreach (SourceLiterals::constant($this->seederFile('FunkLessonQuestionSeeder'), 'COURSES') as $code => $parts) {
            foreach ($parts as [$catalog, $onlyModule]) {
                $allowed = $onlyModule === null ? null : array_flip(array_column($this->moduleQuestions[$onlyModule] ?? [], 'question_source_id'));
                foreach ($quiz[$catalog] as $chapterTitle => $lessons) {
                    $module = $this->vModule($code, $chapterTitle);
                    foreach ($lessons as $lessonTitle => $contentIds) {
                        $lesson = $module === null ? null : $this->vLesson($module, $lessonTitle);
                        $ids = array_values(array_filter($contentIds, fn (string $id) => isset($this->questions[$id]) && preg_match('/^(SRC|UBI)-/', $id) && ($allowed === null || isset($allowed[$id]))));
                        $this->vSeedQuiz($lesson, function (int $l) use ($ids): void {
                            $this->vLessons[$l]['questions'] = [];
                            foreach ($ids as $i => $id) {
                                $this->vLessons[$l]['questions'][$id] = $i + 1;
                            }
                        });
                    }
                }
            }
        }

        // AddSeeToBin / AddBinToSee: Kopie ohne Knoten, Quiz ohne SBF_BASIS-Fragen
        $basis = array_flip(array_column($this->moduleQuestions['SBF_BASIS'] ?? [], 'question_source_id'));
        $notKnots = static fn (string $t) => $t !== 'Knoten';
        $this->vCopyCourse('SBF-BIN-MOTOR', 'ADD-SEE-TO-BIN', $notKnots, true, $basis);
        $this->vCopyCourse('SBF-SEE', 'ADD-BIN-TO-SEE', $notKnots, true, $basis);
    }

    /** @return list<array<string,mixed>> */
    private function videoRecords(): array
    {
        $groups = SourceLiterals::constant($this->supportFile('ComboVideoGroups'), 'GROUPS');
        $placeholder = SourceLiterals::constant($this->seederFile('VideoCourseSeeder'), 'PLACEHOLDER_VIDEO');
        $realUrls = self::videoUrls();
        $out = [];
        foreach (self::COURSES as $course) {
            $code = $course['code'];
            foreach ($this->vModulesOf($code) as $moduleId => $module) {
                $label = null;
                foreach ($groups[$code] ?? [] as $group) {
                    if (in_array($module['title'], $group['chapters'], true)) {
                        $label = $group['label'];
                    }
                }
                $moduleKey = $code.'::'.$module['title'];
                $lessons = [];
                foreach ($this->vLessonsOf($moduleId) as $lesson) {
                    $lessonKey = $moduleKey.'::'.$lesson['title'];
                    // Echte Videos (T62): eigene Pflegedatei data/video_urls.json ersetzt das Platzhaltervideo der Quelle.
                    if (isset($realUrls[$lessonKey])) {
                        $lesson['video_url'] = $realUrls[$lessonKey];
                        unset($realUrls[$lessonKey]);
                    }
                    $questions = $lesson['questions'];
                    asort($questions);
                    $lessons[] = [
                        'source_id' => $lessonKey,
                        'title' => $lesson['title'],
                        'video_url' => $lesson['video_url'],
                        'provider' => 'url',
                        'is_placeholder' => $lesson['video_url'] === null || $lesson['video_url'] === $placeholder,
                        'duration_seconds' => $lesson['duration'],
                        'sort_order' => $lesson['sort_order'],
                        'steps' => array_map(static fn ($s) => ['source_id' => $lessonKey.'::'.$s['sort_order'], 'title' => $s['title'], 'sort_order' => $s['sort_order'], 'media_source_id' => null], $lesson['steps']),
                        'questions' => array_map(static fn ($id, $sort) => ['question_source_id' => $id, 'sort_order' => $sort], array_keys($questions), $questions),
                    ];
                }
                $out[] = $this->finalize([
                    'source_id' => $moduleKey,
                    'course_code' => $code,
                    'title' => $module['title'],
                    'group_label' => $label,
                    'sort_order' => $module['sort_order'],
                    'status' => 'published',
                    'rights_status' => 'unknown',
                    'source_file' => 'database/seeders',
                    'lessons' => $lessons,
                ]);
                if (array_filter($lessons, static fn ($l) => $l['is_placeholder']) !== []) {
                    $this->placeholderLessons += count(array_filter($lessons, static fn ($l) => $l['is_placeholder']));
                }
            }
        }
        foreach (array_keys($realUrls) as $unknown) {
            $this->report->warn('video_url_unknown_lesson', 'video_modules', (string) $unknown, 'data/video_urls.json nennt eine Lektion, die es nicht gibt (Schreibweise „Kurs::Kapitel::Lektion“ prüfen).');
        }
        if ($this->placeholderLessons > 0) {
            $this->report->warn('video_placeholder', 'video_modules', null, "{$this->placeholderLessons} Lektionen verweisen auf das Platzhaltervideo der Quelle und müssen vor dem Livegang ersetzt werden.");
        }

        return $out;
    }

    private int $placeholderLessons = 0;

    /**
     * Eigene Pflegedatei data/video_urls.json: Lektion („Kurs::Kapitel::Lektion“) => HTTPS-Adresse des echten Videos
     * (T62). Schlüssel mit „_“ am Anfang sind Kommentare.
     *
     * @return array<string,string>
     */
    public static function videoUrls(string $file = __DIR__.'/../../data/video_urls.json'): array
    {
        if (! is_file($file)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $key => $url) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue; // Kommentare
            }
            if (! is_string($url) || ! preg_match('#^https://\S+$#', $url)) {
                throw new \InvalidArgumentException('data/video_urls.json: Adresse für „'.$key.'“ muss mit https:// beginnen.');
            }
            $out[(string) $key] = $url;
        }

        return $out;
    }

    // ------------------------------------------------------------------

    /** @param array<string,list<array{0:string,1:int}>> $chapters @param array<string,list<string>> $steps */
    private function vCreateChapters(string $course, array $chapters, ?string $url, array $steps): void
    {
        if ($this->vModulesOf($course) !== []) {
            return;
        }
        $m = 0;
        foreach ($chapters as $title => $lessons) {
            $module = $this->vAddModule($course, $title, ++$m);
            $l = 0;
            foreach ($lessons as [$lessonTitle, $duration]) {
                $lesson = $this->vAddLesson($module, $lessonTitle, $url, $duration, ++$l);
                foreach ($steps[$lessonTitle] ?? [] as $i => $stepTitle) {
                    $this->vLessons[$lesson]['steps'][] = ['title' => Text::clean($stepTitle), 'sort_order' => $i + 1];
                }
            }
        }
    }

    /**
     * Kopiert Kapitel eines Kurses (in Sortierung) als neue Kapitel 1..n.
     * @param (callable(string):bool)|null $filter
     * @param array<string,int>|null $excludeQuestions
     */
    private function vCopyCourse(string $from, string $to, ?callable $filter, bool $withQuestions, ?array $excludeQuestions): void
    {
        if ($this->vModulesOf($to) !== []) {
            return;
        }
        $sort = 0;
        foreach ($this->vModulesOf($from) as $moduleId => $module) {
            if ($filter !== null && ! $filter($module['title'])) {
                continue;
            }
            $target = $this->vAddModule($to, $module['title'], ++$sort);
            $this->vCopyLessons($moduleId, $target, $withQuestions, $excludeQuestions);
        }
    }

    /** @param array<string,int>|null $excludeQuestions */
    private function vCopyLessons(int $fromModule, int $toModule, bool $withQuestions, ?array $excludeQuestions): void
    {
        foreach ($this->vLessonsOf($fromModule) as $lesson) {
            $id = $this->vAddLesson($toModule, $lesson['title'], $lesson['video_url'], $lesson['duration'], $lesson['sort_order']);
            $this->vLessons[$id]['steps'] = $lesson['steps'];
            if ($withQuestions) {
                foreach ($lesson['questions'] as $questionId => $sortOrder) {
                    if ($excludeQuestions === null || ! isset($excludeQuestions[$questionId])) {
                        $this->vLessons[$id]['questions'][$questionId] = $sortOrder;
                    }
                }
                $this->vLessons[$id]['quiz_seeded'] = $this->vLessons[$id]['questions'] !== [];
            }
        }
    }

    /** Wie SeedsLessonQuizOnce::seedQuizOnce(). @param callable(int):void $attach */
    private function vSeedQuiz(?int $lesson, callable $attach): void
    {
        if ($lesson === null || $this->vLessons[$lesson]['quiz_seeded']) {
            return;
        }
        if ($this->vLessons[$lesson]['questions'] === []) {
            $attach($lesson);
        }
        if ($this->vLessons[$lesson]['questions'] !== []) {
            $this->vLessons[$lesson]['quiz_seeded'] = true;
        }
    }

    /**
     * Fragen wie die Lesson-Question-Seeder auflösen: Fragetext, Fragetext +
     * richtige Antwort, Bilddatei oder [content_role => amtliche Nummer].
     * @param list<string|array<string,string>> $questions
     */
    private function vAttachMatched(int $lesson, array $questions): void
    {
        foreach ($questions as $i => $question) {
            $id = $this->vMatchQuestion($question);
            if ($id !== null) {
                $this->vLessons[$lesson]['questions'][$id] = $i + 1;
            } else {
                $this->report->warn('video_quiz_unresolved', 'video_modules', $this->vModules[$this->vLessons[$lesson]['module']]['course'].'::'.$this->vLessons[$lesson]['title'], 'Quizfrage nicht auflösbar: '.json_encode($question, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /** @param string|array<string,string> $question */
    private function vMatchQuestion(string|array $question): ?string
    {
        if (is_array($question) && ! isset($question['question']) && ! isset($question['media'])) {
            $role = (string) array_key_first($question);

            return $this->byRoleNumber[$role.'#'.reset($question)] ?? null;
        }
        foreach ($this->questions as $id => $q) {
            $rev = $q['revision'];
            if ($rev['editorial_status'] !== 'published') {
                continue;
            }
            if (is_array($question) && isset($question['media'])) {
                foreach ($rev['media'] as $m) {
                    if (str_ends_with(strtolower($m['media_source_id']), strtolower($question['media']))) {
                        return $id;
                    }
                }
                continue;
            }
            $text = Text::clean(is_array($question) ? $question['question'] : $question);
            if ($rev['question_text'] !== $text) {
                continue;
            }
            if (is_array($question)) {
                $answer = Text::clean($question['correct_answer']);
                $hit = array_filter($rev['answers'], static fn ($a) => $a['is_correct'] && $a['answer_text'] === $answer);
                if ($hit === []) {
                    continue;
                }
            }

            return $id;
        }

        return null;
    }

    private function vAddModule(string $course, string $title, int $sort): int
    {
        $id = $this->vNextId++;
        $this->vModules[$id] = ['course' => $course, 'title' => Text::clean($title), 'sort_order' => $sort];

        return $id;
    }

    private function vAddLesson(int $module, string $title, ?string $url, ?int $duration, int $sort): int
    {
        $id = $this->vNextId++;
        $this->vLessons[$id] = ['module' => $module, 'title' => Text::clean($title), 'video_url' => $url, 'duration' => $duration, 'sort_order' => $sort, 'steps' => [], 'questions' => [], 'quiz_seeded' => false];

        return $id;
    }

    /** @return array<int,array{course:string,title:string,sort_order:int}> Kapitel eines Kurses nach Sortierung */
    private function vModulesOf(string $course): array
    {
        $out = array_filter($this->vModules, static fn ($m) => $m['course'] === $course);
        uasort($out, static fn ($a, $b) => [$a['sort_order']] <=> [$b['sort_order']]);

        return $out;
    }

    /** @return array<int,array<string,mixed>> Lektionen eines Kapitels nach Sortierung */
    private function vLessonsOf(int $module): array
    {
        $out = array_filter($this->vLessons, static fn ($l) => $l['module'] === $module);
        uasort($out, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $out;
    }

    private function vModule(string $course, string $title): ?int
    {
        foreach ($this->vModulesOf($course) as $id => $m) {
            if ($m['title'] === Text::clean($title)) {
                return $id;
            }
        }

        return null;
    }

    private function vLesson(int $module, string $title): ?int
    {
        foreach ($this->vLessonsOf($module) as $id => $l) {
            if ($l['title'] === Text::clean($title)) {
                return $id;
            }
        }

        return null;
    }
}
