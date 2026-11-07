<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;

/**
 * Baut aus dem Snapshot unter content-source/boatingschool/ die Datensätze
 * des Content-Pakets. Die Regeln bilden das Ergebnis eines frischen
 * `php artisan db:seed` der Quelle nach (Reihenfolge wie DatabaseSeeder),
 * ohne Laravel oder eine Datenbank zu benötigen.
 *
 * Abweichungen von der Quelle sind bewusst und im Manifest dokumentiert:
 * - freie Kartenaufgaben ohne A–D-Antworten werden als Fragetyp
 *   `chart_task` (Status `draft`) übernommen statt verworfen;
 * - alle Texte werden einheitlich getrimmt und Unicode-NFC-normalisiert.
 */
final class PackageBuilder
{
    use Concerns\BuildsVideoCourses;
    use Concerns\BuildsRadioTraining;
    use Concerns\BuildsRadioPractice;
    use Concerns\BuildsExamRegistration;

    public const CONVERTER_VERSION = '0.3.0';
    public const SCHEMA_VERSION = '1.3.0';

    private const XLSX_MODULE_MAP = [
        'Basis' => 'SBF_BASIS',
        'SBF See' => 'SBF_SEE',
        'SBF Binnen' => 'SBF_BINNEN',
        'SBF Binnen Segeln' => 'SBF_BINNEN_SEGELN',
        'SRC' => 'SRC',
        'UBI' => 'UBI',
    ];

    private const MODULES = [
        ['code' => 'SBF_BASIS', 'name' => 'SBF Basis (gemeinsame Grundlagen)'],
        ['code' => 'SBF_SEE', 'name' => 'SBF See (spezifisch)'],
        ['code' => 'SBF_BINNEN', 'name' => 'SBF Binnen (spezifisch)'],
        ['code' => 'SBF_BINNEN_SEGELN', 'name' => 'SBF Binnen Segeln (Zusatzmodul)'],
        ['code' => 'SRC', 'name' => 'SRC Seefunk'],
        ['code' => 'UBI', 'name' => 'UBI Binnenfunk'],
        ['code' => 'UBI_ERG', 'name' => 'UBI Ergänzung (für Inhaber eines Seefunkzeugnisses)'],
        ['code' => 'SKN', 'name' => 'SKN'],
        ['code' => 'FKN', 'name' => 'FKN'],
    ];

    private const COURSES = [
        ['code' => 'SBF-SEE', 'name' => 'SBF See', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_SEE']],
        ['code' => 'SBF-BIN-MOTOR', 'name' => 'SBF Binnen', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN']],
        ['code' => 'SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN']],
        ['code' => 'SBF-SEE-BIN', 'name' => 'SBF See & Binnen', 'type' => 'combo', 'modules' => ['SBF_BASIS', 'SBF_SEE', 'SBF_BINNEN']],
        ['code' => 'ADD-SEE-TO-BIN', 'name' => 'Ergänzung See → Binnen', 'type' => 'addon', 'modules' => ['SBF_BINNEN']],
        ['code' => 'ADD-BIN-TO-SEE', 'name' => 'Ergänzung Binnen → See', 'type' => 'addon', 'modules' => ['SBF_SEE']],
        ['code' => 'ADD-BIN-SEGEL', 'name' => 'Ergänzung Binnen → Segeln', 'type' => 'addon', 'modules' => ['SBF_BINNEN_SEGELN']],
        ['code' => 'SRC', 'name' => 'SRC Vollkurs', 'type' => 'full', 'modules' => ['SRC']],
        ['code' => 'UBI', 'name' => 'UBI Vollkurs', 'type' => 'full', 'modules' => ['UBI']],
        ['code' => 'SRC-UBI', 'name' => 'SRC & UBI Kombikurs', 'type' => 'combo', 'modules' => ['SRC', 'UBI']],
        ['code' => 'ADD-UBI-TO-SRC', 'name' => 'Ergänzung SRC → UBI', 'type' => 'addon', 'modules' => ['UBI_ERG']],
        ['code' => 'SKN', 'name' => 'SKN Vollkurs', 'type' => 'full', 'modules' => ['SKN']],
        ['code' => 'FKN', 'name' => 'FKN Vollkurs', 'type' => 'full', 'modules' => ['FKN']],
        ['code' => 'ADD-FKN-TO-SKN', 'name' => 'Ergänzung FKN → SKN', 'type' => 'addon', 'modules' => ['SKN']],
    ];

    /** Inhalte, die diese Konverter-Version noch nicht überführt (Kap. 11, Schritte 8–8b). */
    private const NOT_CONVERTED = [
        ['entity' => 'operations', 'source' => 'PraxisFormTemplateSeeder, praxis_exam_appointment, course_exam_registration', 'reason' => 'Anmeldung/Terminvergabe durch eine Bootsschule: nicht übernommen (Lernende melden sich selbst an, siehe exam_guides).'],
    ];

    private string $data;

    /** @var array<string,array<string,mixed>> content_id => Frage */
    private array $questions = [];

    /** @var array<string,string> "content_role#official_number" => content_id */
    private array $byRoleNumber = [];

    /** @var array<string,list<array{question_source_id:string,sort_order:?int,required:bool}>> */
    private array $moduleQuestions = [];

    /** @var array<string,list<array{module_code:string,sort_order:int,required:bool}>> */
    private array $courseModules = [];

    /** @var array<string,array<string,mixed>> asset_key => Medium (inkl. _file) */
    private array $media = [];

    /** @var array<string,list<array<string,mixed>>> course_code => Bögen */
    private array $papers = [];

    /** @var array<string,list<array<string,mixed>>> course_code => Praxiskarten */
    private array $praxis = [];

    public function __construct(private readonly string $snapshotDir, private readonly Report $report)
    {
        $this->data = rtrim($snapshotDir, '/').'/database/data';
        if (! is_dir($this->data)) {
            throw new RuntimeException("Snapshot nicht gefunden: {$this->data}");
        }
    }

    /** @return array<string,list<array<string,mixed>>> Entität => Datensätze */
    public function build(): array
    {
        foreach (self::MODULES as $module) {
            $this->moduleQuestions[$module['code']] = [];
        }
        foreach (self::COURSES as $course) {
            foreach ($course['modules'] as $index => $moduleCode) {
                $this->courseModules[$course['code']][] = ['module_code' => $moduleCode, 'sort_order' => $index + 1, 'required' => true];
            }
        }

        // Reihenfolge wie database/seeders/DatabaseSeeder.php
        $this->importQuestionPool();
        $this->importFeedback('sbf_see_binnen_fragen_feedback.csv');
        $this->importOwnCatalog('fkn_catalog.php', 'fkn_official', 'FKN', 'FKN', 'FKN-Fragenkatalog mit Antworten (PANord), Stand 01/2020', '2020-01');
        $this->importOwnCatalog('skn_catalog.php', 'skn_official', 'SKN', 'SKN', 'Fragenkatalog für die Sachkundeprüfung gem. § 7 WaffG (Bundesverwaltungsamt), Kapitel IV, Stand 13.07.2018', '2018-07');
        $this->importFeedback('fragen_erklaerungen.csv');
        $this->importUbiErgaenzung();
        $this->importExamPapers();
        $rules = $this->buildExamRules();
        $this->importQuestionImages();
        $navigation = $this->importNavigation();
        $this->importPraxis();
        $this->applyElwisPraxisImages();
        $this->buildVideoCourses();

        return [
            'courses' => $this->buildCourses(),
            'modules' => $this->buildModules(),
            'questions' => array_values(array_map(fn ($q) => $this->finalize($q), $this->questions)),
            'media' => $this->buildMedia(),
            'exam_rules' => $rules,
            'exam_papers' => $this->buildPapers(),
            'navigation_tasks' => $navigation,
            'praxis_tasks' => $this->buildPraxis(),
            'video_modules' => $this->videoRecords(),
            'radio_texts' => $this->radioTextRecords(),
            'radio_vocabulary' => $this->radioVocabularyRecords(),
            'radio_practice' => $this->radioPracticeRecords(),
            'device_simulation' => $this->deviceSimulationRecords(),
            'exam_committees' => $this->examCommitteeRecords(),
            'exam_guides' => $this->examGuideRecords(),
        ];
    }

    /** @return list<array{entity:string,source:string,reason:string}> */
    public function notConverted(): array
    {
        return self::NOT_CONVERTED;
    }

    /** @return array<string,string> media source_id => absoluter Dateipfad */
    public function mediaFiles(): array
    {
        $files = [];
        foreach ($this->media as $key => $media) {
            if ($media['_used']) {
                $files[$key] = $media['_file'];
            }
        }

        return $files;
    }

    /** Katalog-/Versionsstände aller Fragen. @return list<array{catalog:?string,version:?string,questions:int}> */
    public function catalogs(): array
    {
        $catalogs = [];
        foreach ($this->questions as $q) {
            $key = ($q['revision']['source']['catalog'] ?? '').'#'.($q['revision']['source']['version'] ?? '');
            $catalogs[$key] ??= ['catalog' => $q['revision']['source']['catalog'], 'version' => $q['revision']['source']['version'], 'questions' => 0];
            $catalogs[$key]['questions']++;
        }
        ksort($catalogs);

        return array_values($catalogs);
    }

    // ------------------------------------------------------------------
    // Fragen
    // ------------------------------------------------------------------

    private function importQuestionPool(): void
    {
        $file = 'zentrale_fragenbasis.xlsx';
        $reader = new XlsxReader($this->data.'/'.$file);
        $line = 1;
        foreach ($reader->rowsWithHeader('Central Question Pool') as $row) {
            $line++;
            $contentId = Text::clean($row['content_id'] ?? '');
            $questionText = Text::clean($row['question'] ?? '');
            $moduleValue = Text::clean($row['module'] ?? '');

            if ($contentId === '' || $questionText === '') {
                $this->report->skip('question', $file, $line, $contentId ?: null, 'content_id oder question fehlt.');
                continue;
            }
            $moduleCode = self::XLSX_MODULE_MAP[$moduleValue] ?? null;
            if ($moduleCode === null) {
                $this->report->skip('question', $file, $line, $contentId, "Unbekannter Modulwert '{$moduleValue}'.");
                continue;
            }
            if (isset($this->questions[$contentId])) {
                $this->report->skip('question', $file, $line, $contentId, 'content_id doppelt.');
                continue;
            }

            $answers = [];
            foreach (['A', 'B', 'C', 'D'] as $key) {
                $answers[$key] = Text::clean($row['answer_'.strtolower($key)] ?? '');
            }
            $correct = strtoupper(Text::clean($row['correct_answer'] ?? ''));
            $filled = array_filter($answers, static fn ($a) => $a !== '');

            if ($filled === [] && $correct === '') {
                $type = 'chart_task';
                $editorial = 'draft';
                $answerRecords = [];
                $this->report->warn('chart_task_without_solution', 'question', $contentId, 'Freie Kartenaufgabe ohne A–D-Antworten und ohne Musterlösung; als chart_task (draft) übernommen.');
            } elseif (count($filled) !== 4) {
                $this->report->skip('question', $file, $line, $contentId, 'Nicht alle vier Antworten (A–D) vorhanden.');
                continue;
            } elseif (! in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                $this->report->skip('question', $file, $line, $contentId, "correct_answer '{$correct}' ist nicht A–D.");
                continue;
            } else {
                $type = 'single_choice';
                $editorial = 'published';
                $answerRecords = [];
                foreach ($answers as $key => $text) {
                    $answerRecords[] = ['answer_key' => $key, 'answer_text' => $text, 'is_correct' => $key === $correct, 'sort_order' => ord($key) - 64];
                }
            }

            $officialNumber = isset($row['official_number']) && $row['official_number'] !== '' ? (string) $row['official_number'] : null;
            $role = Text::nullable($row['content_role'] ?? null);

            $this->addQuestion([
                'source_id' => $contentId,
                'official_number' => $officialNumber,
                'content_role' => $role,
                'language' => 'de-DE',
                'active' => true,
                'source_module' => $moduleCode,
                'revision' => [
                    'question_type' => $type,
                    'question_text' => $questionText,
                    'topic' => Text::nullable($row['topic'] ?? null),
                    'subtopic' => Text::nullable($row['subtopic'] ?? null),
                    'competency' => Text::nullable($row['competency'] ?? null),
                    'smart_category' => Text::nullable($row['smartmodus_kategorie'] ?? null),
                    'scope' => Text::nullable($row['scope'] ?? null),
                    'image_required' => Text::bool($row['image_required'] ?? false),
                    'feedback_correct' => null,
                    'feedback_incorrect' => null,
                    'source' => [
                        'catalog' => Text::nullable($row['source_catalog'] ?? null) ?? 'Zentrale Fragenbasis SBF/SRC/UBI',
                        'version' => Text::nullable($row['source_version'] ?? null) ?? '2026-09',
                        'question_id' => Text::nullable($row['source_question_id'] ?? null),
                        'page' => null,
                        'file' => 'database/data/'.$file,
                    ],
                    'editorial_status' => $editorial,
                    'rights_status' => 'review',
                    'rights_owner' => null,
                    'license_reference' => null,
                    'valid_from' => null,
                    'valid_to' => null,
                    'answers' => $answerRecords,
                    'media' => [],
                ],
            ]);
            $this->moduleQuestions[$moduleCode][] = ['question_source_id' => $contentId, 'sort_order' => null, 'required' => true];
        }
    }

    private function importOwnCatalog(string $file, string $role, string $prefix, string $moduleCode, string $catalog, string $version): void
    {
        $entries = $this->includeData($file);
        foreach ($entries as $number => $entry) {
            $contentId = sprintf('%s-%04d', $prefix, $number);
            if (! isset($this->questions[$contentId])) {
                $answers = [];
                if ($entry['type'] === 'open_text') {
                    $answers[] = ['answer_key' => 'MODEL', 'answer_text' => Text::clean($entry['a']), 'is_correct' => true, 'sort_order' => 1];
                } else {
                    foreach ($entry['options'] as $i => $option) {
                        $answers[] = ['answer_key' => chr(65 + $i), 'answer_text' => Text::clean($option['t']), 'is_correct' => (bool) $option['c'], 'sort_order' => $i + 1];
                    }
                }
                $this->addQuestion([
                    'source_id' => $contentId,
                    'official_number' => (string) $number,
                    'content_role' => $role,
                    'language' => 'de-DE',
                    'active' => true,
                    'source_module' => $moduleCode,
                    'revision' => [
                        'question_type' => $entry['type'],
                        'question_text' => Text::clean($entry['q']),
                        'topic' => null,
                        'subtopic' => null,
                        'competency' => null,
                        'smart_category' => null,
                        'scope' => null,
                        'image_required' => false,
                        'feedback_correct' => null,
                        'feedback_incorrect' => null,
                        'source' => ['catalog' => $catalog, 'version' => $version, 'question_id' => null, 'page' => null, 'file' => 'database/data/'.$file],
                        'editorial_status' => 'published',
                        'rights_status' => 'review',
                        'rights_owner' => null,
                        'license_reference' => null,
                        'valid_from' => null,
                        'valid_to' => null,
                        'answers' => $answers,
                        'media' => [],
                    ],
                ]);
            }
            $this->moduleQuestions[$moduleCode][] = ['question_source_id' => $contentId, 'sort_order' => null, 'required' => true];
        }
    }

    /** @param array<string,mixed> $question */
    private function addQuestion(array $question): void
    {
        $this->questions[$question['source_id']] = $question;
        if ($question['content_role'] !== null && $question['official_number'] !== null) {
            $key = $question['content_role'].'#'.$question['official_number'];
            $this->byRoleNumber[$key] ??= $question['source_id'];
        }
    }

    /** Wie ImportContentFeedback: führendes „Richtig.“/„Falsch.“ und „Richtig ist: …“ entfernen. */
    private function importFeedback(string $file): void
    {
        $line = 1;
        foreach (Csv::read($this->data.'/'.$file) as $row) {
            $line++;
            $contentId = trim($row['content_id'] ?? '');
            if ($contentId === '') {
                $this->report->skip('feedback', $file, $line, null, 'content_id fehlt.');
                continue;
            }
            $correct = self::stripVerdict(trim($row['feedback_correct'] ?? ''), 'Richtig.');
            $incorrect = self::stripVerdict(trim($row['feedback_incorrect'] ?? ''), 'Falsch.');
            $incorrect = trim((string) preg_replace('/^Richtig ist: „.*?“[ \t]*(?:\R|$)/su', '', $incorrect, 1));
            if ($correct === '' && $incorrect === '') {
                $this->report->skip('feedback', $file, $line, $contentId, 'Kein Feedback in der Quelldatei.');
                continue;
            }
            if (! isset($this->questions[$contentId])) {
                // Wie in der Quelle: Feedback zu (noch) nicht angelegten Fragen
                // wird übersprungen; FKN/SKN folgen im zweiten Lauf.
                if ($file === 'fragen_erklaerungen.csv' || ! preg_match('/^(FKN|SKN)-/', $contentId)) {
                    $this->report->skip('feedback', $file, $line, $contentId, 'Keine Frage mit dieser content_id.');
                }
                continue;
            }
            $this->questions[$contentId]['revision']['feedback_correct'] = Text::nullable($correct);
            $this->questions[$contentId]['revision']['feedback_incorrect'] = Text::nullable($incorrect);
        }
    }

    private static function stripVerdict(string $text, string $verdict): string
    {
        if (str_starts_with($text, $verdict)) {
            $text = substr($text, strlen($verdict));
        }

        return trim($text);
    }

    private function importUbiErgaenzung(): void
    {
        $catalog = $this->includeData('ubi_ergaenzung_katalog.php');
        $members = [];
        foreach ($catalog as $ergNumber => $officialNumber) {
            $id = $this->byRoleNumber['ubi_official#'.$officialNumber] ?? null;
            if ($id === null) {
                $this->report->warn('ubi_erg_missing', 'module', 'UBI_ERG', "UBI-Ergänzungskatalog übersprungen: Katalogfrage {$officialNumber} fehlt.");

                return;
            }
            $members[] = ['question_source_id' => $id, 'sort_order' => (int) $ergNumber, 'required' => true];
        }
        $this->moduleQuestions['UBI_ERG'] = $members;

        // Kurse mit dem vollen UBI-Modul bekommen stattdessen UBI_ERG (UbiErgaenzungSeeder).
        foreach (['ADD-UBI-TO-SRC', 'SRC-UBI'] as $code) {
            foreach ($this->courseModules[$code] as $i => $link) {
                if ($link['module_code'] === 'UBI') {
                    $this->courseModules[$code][$i]['module_code'] = 'UBI_ERG';
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Prüfungsbögen und Regelwerke
    // ------------------------------------------------------------------

    private function importExamPapers(): void
    {
        $byTag = static fn (array $map, bool $contains) => static function (array $row) use ($map, $contains): ?string {
            $tags = $row['Tags'] ?? '';
            foreach ($map as $tag => $role) {
                if ($contains ? str_contains($tags, $tag) : $tags === $tag) {
                    return $role;
                }
            }

            return null;
        };
        $fixed = static fn (string $role) => static fn (array $row): string => $role;

        $this->papersFromCsv('SBF-SEE', 'sbf_see_pruefungsboegen.csv', 'Prüfungsbogen', $byTag(['Basisfragen' => 'shared_basis', 'Spezifische Fragen See' => 'see_specific'], true));
        $this->papersFromCsv('SBF-BIN-MOTOR', 'sbf_binnen_pruefungsboegen.csv', 'Pruefungsbogen', $byTag(['Basisfragen' => 'shared_basis', 'Spezifische Fragen Binnen' => 'binnen_specific'], false));
        $this->papersFromCsv('ADD-SEE-TO-BIN', 'sbf_binnen_ergaenzung_pruefungsboegen.csv', 'Pruefungsbogen', $fixed('binnen_specific'));
        $this->papersFromCsv('ADD-BIN-TO-SEE', 'sbf_see_ergaenzung_pruefungsboegen.csv', 'Pruefungsbogen', $fixed('see_specific'));
        $this->papersFromCopies('SBF-SEE-BIN', [['SBF-SEE', 0], ['ADD-SEE-TO-BIN', 100]]);
        $this->papersFromCsv('SBF-BIN-SEGEL', 'sbf_binnen_segeln_pruefungsboegen.csv', 'Pruefungsbogen', static function (array $row): ?string {
            $n = (int) trim($row['Fragenkatalog-Nr.']);

            return match (true) {
                $n >= 1 && $n <= 72 => 'shared_basis',
                $n >= 73 && $n <= 253 => 'binnen_specific',
                $n >= 254 && $n <= 300 => 'segel_specific',
                default => null,
            };
        });
        $this->papersFromCsv('ADD-BIN-SEGEL', 'sbf_binnen_ergaenzung_segeln_pruefungsboegen.csv', 'Pruefungsbogen', $fixed('segel_specific'));
        $this->papersFromCsv('SRC', 'src_pruefungsboegen.csv', 'Pruefungsbogen', $fixed('src_official'));
        $this->ubiPapers('UBI', 'ubi_uebungsboegen.csv', 'UBI');
        $this->ubiPapers('ADD-UBI-TO-SRC', 'ubi_ergaenzung_uebungsboegen.csv', 'UBI_ERG');
        $this->papersFromCopies('SRC-UBI', [['SRC', 0], ['UBI', 100], ['ADD-UBI-TO-SRC', 200]]);
        $this->fknPapers();
    }

    /** @param callable(array<string,string>):?string $roleFor */
    private function papersFromCsv(string $course, string $file, string $paperColumn, callable $roleFor): void
    {
        $papers = [];
        $line = 1;
        foreach (Csv::read($this->data.'/'.$file) as $row) {
            $line++;
            $paperNumber = (int) $row[$paperColumn];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);
            $role = $roleFor($row);
            $id = $role !== null ? ($this->byRoleNumber[$role.'#'.$officialNumber] ?? null) : null;
            if ($id === null) {
                $this->report->skip('exam_paper_question', $file, $line, sprintf('%s#%02d', $course, $paperNumber), "Frage Nr. {$officialNumber} (".($role ?? 'ohne Fragenfamilie').') nicht auflösbar.');
                continue;
            }
            $papers[$paperNumber] ??= [];
            $papers[$paperNumber][] = ['position' => (int) $row['Position im Bogen'], 'question_source_id' => $id];
        }
        $this->storePapers($course, $papers, 'database/data/'.$file);
    }

    private function ubiPapers(string $course, string $file, string $moduleCode): void
    {
        $inModule = [];
        foreach ($this->moduleQuestions[$moduleCode] as $link) {
            $q = $this->questions[$link['question_source_id']];
            if ($q['content_role'] === 'ubi_official') {
                $inModule[$q['official_number']] = $q['source_id'];
            }
        }
        $papers = [];
        foreach (Csv::read($this->data.'/'.$file) as $row) {
            $officialNumber = trim($row['Fragenkatalog-Nr.']);
            if (! isset($inModule[$officialNumber])) {
                $this->report->warn('exam_papers_skipped', 'exam_paper', $course, "Übungsbögen übersprungen: Katalogfrage {$officialNumber} fehlt im Modul {$moduleCode}.");

                return;
            }
            $papers[(int) $row['Pruefungsbogen']][] = ['position' => (int) $row['Position im Bogen'], 'question_source_id' => $inModule[$officialNumber]];
        }
        $this->storePapers($course, $papers, 'database/data/'.$file);
    }

    private function fknPapers(): void
    {
        $papers = [];
        foreach ($this->includeData('fkn_pruefungsboegen.php') as $paperNumber => $numbers) {
            $papers[$paperNumber] = [];
            foreach ($numbers as $position => $officialNumber) {
                $id = $this->byRoleNumber['fkn_official#'.$officialNumber] ?? null;
                if ($id === null) {
                    $this->report->skip('exam_paper_question', 'fkn_pruefungsboegen.php', null, sprintf('FKN#%02d', $paperNumber), "FKN-Frage {$officialNumber} nicht auflösbar.");
                    continue;
                }
                $papers[$paperNumber][] = ['position' => $position + 1, 'question_source_id' => $id];
            }
        }
        $this->storePapers('FKN', $papers, 'database/data/fkn_pruefungsboegen.php');
    }

    /** @param list<array{0:string,1:int}> $sources */
    private function papersFromCopies(string $course, array $sources): void
    {
        foreach ($sources as [$sourceCourse, $offset]) {
            foreach ($this->papers[$sourceCourse] ?? [] as $paper) {
                $number = $paper['paper_number'] + $offset;
                $this->papers[$course][] = [
                    'source_id' => sprintf('%s#%02d', $course, $number),
                    'course_code' => $course,
                    'paper_number' => $number,
                    'sort_order' => $number,
                    'copied_from' => $paper['source_id'],
                    'source_file' => $paper['source_file'],
                    'questions' => $paper['questions'],
                ];
            }
        }
    }

    /** @param array<int,list<array{position:int,question_source_id:string}>> $papers */
    private function storePapers(string $course, array $papers, string $sourceFile): void
    {
        ksort($papers);
        foreach ($papers as $number => $questions) {
            usort($questions, static fn ($a, $b) => $a['position'] <=> $b['position']);
            $this->papers[$course][] = [
                'source_id' => sprintf('%s#%02d', $course, $number),
                'course_code' => $course,
                'paper_number' => $number,
                'sort_order' => $number,
                'copied_from' => null,
                'source_file' => $sourceFile,
                'questions' => $questions,
            ];
        }
    }

    /**
     * Korrekturen gegenüber der Quelle (Review 07.11.2026): dort fehlt die Fragenzahl der Zufallsprüfung,
     * sodass 10 Fragen (statt wie in den amtlichen Bögen 7 bzw. 24) gezogen und die Bestehensgrenze verfälscht wurde.
     * Der Golden-Master-Vergleich wendet dieselben Korrekturen auf die Laravel-Seite an.
     */
    public const EXAM_COUNT_CORRECTIONS = ['ADD-BIN-SEGEL' => 7, 'SRC' => 24];

    /** Wie ExamRuleSetSeeder (Version draft-2026-09). @return list<array<string,mixed>> */
    private function buildExamRules(): array
    {
        $verified = ['ADD-BIN-SEGEL', 'SRC', 'FKN', 'UBI', 'SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-BIN-SEGEL', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'ADD-UBI-TO-SRC', 'SKN'];
        $counts = [
            'ADD-SEE-TO-BIN' => 23, 'ADD-BIN-TO-SEE' => 23, 'UBI' => 22, 'ADD-UBI-TO-SRC' => 10, 'SKN' => 18,
            'SBF-SEE' => ['SBF_BASIS' => 7, 'SBF_SEE' => 23],
            'SBF-BIN-MOTOR' => ['SBF_BASIS' => 7, 'SBF_BINNEN' => 23],
            'SBF-BIN-SEGEL' => ['SBF_BASIS' => 7, 'SBF_BINNEN' => 23, 'SBF_BINNEN_SEGELN' => 7],
        ] + self::EXAM_COUNT_CORRECTIONS;
        $timeLimits = [
            'ADD-BIN-SEGEL' => 900, 'SRC' => 1800, 'FKN' => 1800, 'UBI' => 3600,
            'SBF-SEE' => 3600, 'SBF-BIN-MOTOR' => 2700, 'SBF-BIN-SEGEL' => 3600, 'SKN' => 3600, 'ADD-SEE-TO-BIN' => 2100, 'ADD-BIN-TO-SEE' => 3000, 'ADD-UBI-TO-SRC' => 1800,
        ];
        $navNote = 'Zur amtlichen Prüfung gehört zusätzlich die Navigationsaufgabe (mindestens 7 von 9 Punkten); sie wird hier nicht automatisch bewertet.';
        $passing = [
            'ADD-BIN-SEGEL' => ['min_correct' => 5, 'of' => 7],
            'SRC' => ['min_correct' => 19, 'of' => 24],
            'FKN' => ['min_correct' => 24, 'of' => 30],
            'UBI' => ['min_correct' => 17, 'of' => 22],
            'SBF-SEE' => ['min_correct' => 23, 'of' => 30, 'parts' => [
                ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                ['module' => 'SBF_SEE', 'label' => 'See', 'min_correct' => 18, 'of' => 23],
            ], 'note' => $navNote],
            'SBF-BIN-MOTOR' => ['min_correct' => 23, 'of' => 30, 'parts' => [
                ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                ['module' => 'SBF_BINNEN', 'label' => 'Binnen', 'min_correct' => 18, 'of' => 23],
            ]],
            'SBF-BIN-SEGEL' => ['min_correct' => 28, 'of' => 37, 'parts' => [
                ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                ['module' => 'SBF_BINNEN', 'label' => 'Binnen', 'min_correct' => 18, 'of' => 23],
                ['module' => 'SBF_BINNEN_SEGELN', 'label' => 'Segeln', 'min_correct' => 5, 'of' => 7],
            ]],
            'ADD-SEE-TO-BIN' => ['min_correct' => 18, 'of' => 23],
            'ADD-BIN-TO-SEE' => ['min_correct' => 18, 'of' => 23, 'note' => $navNote],
            'ADD-UBI-TO-SRC' => ['min_correct' => 8, 'of' => 10],
            'SKN' => ['min_correct' => 26, 'of' => 36],
        ];

        $rules = [];
        foreach (self::COURSES as $course) {
            $code = $course['code'];
            $rule = $passing[$code] ?? null;
            $passingRule = $rule === null
                ? ['min_correct_ratio' => 0.75]
                : array_merge(['min_correct_ratio' => $rule['min_correct'] / $rule['of']], array_diff_key($rule, ['min_correct' => 1, 'of' => 1]));
            $isVerified = in_array($code, $verified, true);
            $blueprints = [];
            foreach ($this->courseModules[$code] as $link) {
                $override = $counts[$code] ?? 10;
                $count = is_array($override) ? ($override[$link['module_code']] ?? 10) : $override;
                $available = count(array_filter(
                    $this->moduleQuestions[$link['module_code']],
                    fn ($m) => $this->questions[$m['question_source_id']]['revision']['editorial_status'] === 'published'
                ));
                $blueprints[] = ['module_code' => $link['module_code'], 'question_count' => min($count, max(1, $available))];
            }
            if (! $isVerified) {
                $this->report->warn('exam_rule_unverified', 'exam_rule', $code.'@draft-2026-09', 'Regelwerk nicht verifiziert; nur als Übungsmodus nutzbar.');
            }
            $rules[] = $this->finalize([
                'source_id' => $code.'@draft-2026-09',
                'course_code' => $code,
                'version' => 'draft-2026-09',
                'valid_from' => null,
                'valid_until' => null,
                'time_limit_seconds' => $timeLimits[$code] ?? 3600,
                'passing_rule' => $passingRule,
                'status' => $isVerified ? 'published' : 'draft',
                'verified' => $isVerified,
                'blueprints' => $blueprints,
                'source_file' => 'database/seeders/ExamRuleSetSeeder.php',
            ]);
        }

        return $rules;
    }

    // ------------------------------------------------------------------
    // Medien
    // ------------------------------------------------------------------

    /** Wie ElwisQuestionImageSeeder: Bilder je Modul + Katalognummer. */
    private function importQuestionImages(): void
    {
        $groups = [];
        foreach (Csv::read($this->data.'/elwis_bilder.csv') as $row) {
            $groups[$row['modul'].'#'.$row['fragen_nr']][(int) $row['reihenfolge']] = $row;
        }
        foreach ($groups as $key => $rows) {
            ksort($rows);
            [$moduleCode, $number] = explode('#', $key);
            $questionId = null;
            foreach ($this->moduleQuestions[$moduleCode] ?? [] as $link) {
                if ($this->questions[$link['question_source_id']]['official_number'] === $number) {
                    $questionId = $link['question_source_id'];
                    break;
                }
            }
            if ($questionId === null || $this->questions[$questionId]['revision']['editorial_status'] !== 'published') {
                $this->report->warn('image_unassigned', 'media', $key, "ELWIS-Bild übersprungen: {$moduleCode} Frage {$number} ohne veröffentlichte Fassung.");
                continue;
            }
            $links = [];
            foreach (array_values($rows) as $i => $row) {
                $assetKey = $this->elwisAsset($row);
                $this->media[$assetKey]['_used'] = true;
                $links[] = ['media_source_id' => $assetKey, 'role' => 'question', 'sort_order' => $i + 1];
            }
            $this->questions[$questionId]['revision']['media'] = $links;
        }
        foreach ($this->questions as $id => $q) {
            if ($q['revision']['image_required'] && $q['revision']['media'] === [] && $q['revision']['editorial_status'] === 'published') {
                $this->report->warn('image_missing', 'question', $id, 'image_required ist gesetzt, aber kein Bild zugeordnet.');
            }
        }
    }

    /** @param array<string,string> $row */
    private function elwisAsset(array $row): string
    {
        $key = 'elwis/'.$row['datei'];
        if (! isset($this->media[$key])) {
            $this->media[$key] = [
                'source_id' => $key,
                'path' => 'media/'.$key,
                'media_type' => 'image',
                'alt_text' => 'Abbildung zur Frage',
                'caption' => null,
                'description' => Text::nullable($row['elwis_beschreibung'] ?? null),
                'source' => 'ELWIS (Wasserstraßen- und Schifffahrtsverwaltung des Bundes): '.$row['quelle'],
                'source_url' => Text::nullable($row['quelle'] ?? null),
                'rights_owner' => null,
                'license_reference' => null,
                'rights_status' => 'review',
                '_file' => $this->data.'/elwis_bilder/'.$row['datei'],
                '_used' => false,
            ];
        }

        return $key;
    }

    // ------------------------------------------------------------------
    // Navigation und Praxis
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function importNavigation(): array
    {
        $file = 'sbf_see_navigationsaufgaben.csv';
        $tasks = [];
        foreach (Csv::read($this->data.'/'.$file) as $row) {
            $number = (int) $row['Navigationsaufgabe'];
            $taskId = sprintf('NAV-%02d', $number);
            $tasks[$taskId] ??= [
                'source_id' => $taskId,
                'task_number' => $number,
                'course_codes' => ['SBF-SEE', 'SBF-SEE-BIN', 'ADD-BIN-TO-SEE'],
                'scenario_text' => Text::clean($row['Szenario']),
                'hint' => Text::nullable($row['Hinweis']),
                'sort_order' => $number,
                'source' => Text::nullable($row['Quelle'] ?? null),
                'source_version' => Text::nullable($row['Stand'] ?? null),
                'rights_status' => 'unknown',
                'status' => 'published',
                'source_file' => 'database/data/'.$file,
                'questions' => [],
            ];
            $tasks[$taskId]['questions'][] = [
                'source_id' => Text::clean($row['Aufgabe-ID']),
                'question_number' => (int) $row['Teilaufgabe'],
                'question_text' => Text::clean($row['Aufgabenstellung']),
                'answer_text' => Text::clean($row['Amtliches Ergebnis']),
                'sort_order' => (int) $row['Teilaufgabe'],
            ];
        }
        ksort($tasks);

        return array_values(array_map(fn ($t) => $this->finalize($t), $tasks));
    }

    private function importPraxis(): void
    {
        $own = [
            'SBF-SEE' => 'sbf_see_praxistrainer.csv',
            'SBF-BIN-MOTOR' => 'sbf_binnen_praxistrainer.csv',
            'SBF-BIN-SEGEL' => 'sbf_binnen_segeln_praxistrainer.csv',
            'FKN' => 'fkn_praxistrainer.csv',
            'SKN' => 'skn_praxistrainer.csv',
        ];
        foreach ($own as $course => $file) {
            $sort = 0;
            foreach (Csv::read($this->data.'/'.$file) as $row) {
                $sort++;
                $this->praxis[$course][] = [
                    'source_id' => Text::clean($row['id']),
                    'course_code' => $course,
                    'category' => Text::clean($row['kategorie']),
                    'subcategory' => Text::nullable($row['unterkategorie']),
                    'exam_reference' => Text::nullable($row['pruefungsbezug']),
                    'task_type' => Text::nullable($row['aufgabentyp']),
                    'question' => Text::clean($row['frage']),
                    'answer' => Text::clean($row['antwort']),
                    'explanation' => Text::nullable($row['erklaerung']),
                    'media_source_id' => $this->praxisAsset($row['bilddatei'] ?? ''),
                    'source' => Text::nullable($row['quelle']),
                    'source_url' => Text::nullable($row['quellen_url']),
                    'sort_order' => $sort,
                    'active' => true,
                    'rights_status' => 'unknown',
                    'copied_from' => null,
                    'source_file' => 'database/data/'.$file,
                ];
            }
        }

        // ComboPraxisTrainerSeeder: Spiegelung mit Suffix, Filtern und Dublettenschutz je Karte.
        $segel = ['Manöver unter Segel', 'Kurse zum Wind', 'Wind und Trimm', 'Boot und Rigg', 'Notfälle unter Segel', 'Ausweichregeln unter Segel'];
        $skn = ['Signalpistole handhaben', 'Munition Kaliber 4', 'Aufbewahrung und Transport', 'Waffenrecht'];
        $targets = [
            'SBF-SEE-BIN' => [['SBF-SEE', '-SEE-BIN', null], ['SBF-BIN-MOTOR', '-SEE-BIN', null]],
            'ADD-BIN-TO-SEE' => [['SBF-SEE', '-BIN-SEE', ['only' => ['Pflichtmanöver']]]],
            'SBF-BIN-SEGEL' => [['SBF-BIN-SEGEL', '', null], ['SBF-BIN-MOTOR', '-SEGEL', [
                'without' => ['Pflichtmanöver', 'Sonstige Manöver'],
                'without_cards' => ['Quickstop/Notstopp', 'Propellergefahr', 'Sog und Wellenschlag'],
            ]]],
            'ADD-BIN-SEGEL' => [['SBF-BIN-SEGEL', '-ADD-SEGEL', ['only' => $segel]]],
            'SKN' => [['SKN', '', null], ['FKN', '-SKN', null]],
            'ADD-FKN-TO-SKN' => [['SKN', '-ADD-SKN', ['only' => $skn]]],
        ];
        foreach ($targets as $target => $sources) {
            $offset = 0;
            $covered = [];
            foreach ($sources as [$source, $suffix, $filter]) {
                $sourceTasks = $this->praxis[$source] ?? [];
                $existing = array_flip(array_column($this->praxis[$target] ?? [], 'source_id'));
                $skip = $covered;
                foreach ($sourceTasks as $task) {
                    if (isset($filter['only']) && ! in_array($task['category'], $filter['only'], true)) {
                        continue;
                    }
                    if (isset($filter['without']) && in_array($task['category'], $filter['without'], true)) {
                        continue;
                    }
                    if (isset($filter['without_cards']) && in_array($task['subcategory'], $filter['without_cards'], true)) {
                        continue;
                    }
                    if (isset($skip[self::cardKey($task['category'], $task['subcategory'])])) {
                        continue;
                    }
                    $newId = $task['source_id'].$suffix;
                    if (isset($existing[$newId])) {
                        continue;
                    }
                    $this->praxis[$target][] = array_merge($task, [
                        'source_id' => $newId,
                        'course_code' => $target,
                        'sort_order' => $task['sort_order'] + $offset,
                        'copied_from' => $task['source_id'],
                    ]);
                }
                foreach ($sourceTasks as $task) {
                    $covered[self::cardKey($task['category'], $task['subcategory'])] = true;
                }
                $offset += $sourceTasks === [] ? 0 : max(array_column($sourceTasks, 'sort_order'));
            }
        }
    }

    private static function cardKey(string $category, ?string $subcategory): string
    {
        $words = preg_split('/\s+/u', mb_strtolower((string) $subcategory), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return $category.'|'.implode(' ', $words);
    }

    private function praxisAsset(string $imagePath): ?string
    {
        $filename = basename(trim($imagePath));
        if ($filename === '') {
            return null;
        }
        $file = $this->data.'/praxistrainer_bilder/'.$filename;
        if (! is_file($file)) {
            $this->report->warn('praxis_image_missing', 'media', $filename, "Praxistrainer-Bild fehlt im Snapshot: {$filename}");

            return null;
        }
        $key = 'praxistrainer/'.$filename;
        $svg = $this->data.'/praxistrainer_bilder_svg/'.preg_replace('/\.png$/', '.svg', $filename);
        $this->media[$key] ??= [
            'source_id' => $key,
            'path' => 'media/'.$key,
            'media_type' => 'image',
            'alt_text' => null,
            'caption' => null,
            'description' => null,
            'source' => 'SBF See Praxistrainer',
            'source_url' => null,
            'rights_owner' => null,
            'license_reference' => null,
            'rights_status' => 'unknown',
            'vector_source' => is_file($svg) ? 'database/data/praxistrainer_bilder_svg/'.basename($svg) : null,
            '_file' => $file,
            '_used' => false,
        ];

        return $key;
    }

    /** Wie ElwisPraxisImageSeeder: ELWIS-Bilder ersetzen gezeichnete Praxisbilder. */
    private function applyElwisPraxisImages(): void
    {
        foreach (Csv::read($this->data.'/praxistrainer_elwis_bilder.csv') as $row) {
            $key = 'elwis/'.$row['datei'];
            if (! isset($this->media[$key])) {
                $this->report->warn('praxis_elwis_missing', 'praxis_task', $row['praxis_id'], "ELWIS-Bild fehlt: {$row['datei']}");
                continue;
            }
            foreach ($this->praxis as $course => $tasks) {
                foreach ($tasks as $i => $task) {
                    if ($task['source_id'] === $row['praxis_id'] || str_starts_with($task['source_id'], $row['praxis_id'].'-')) {
                        $this->praxis[$course][$i]['media_source_id'] = $key;
                    }
                }
            }
        }
        foreach ($this->praxis as $tasks) {
            foreach ($tasks as $task) {
                if ($task['media_source_id'] !== null) {
                    $this->media[$task['media_source_id']]['_used'] = true;
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Ausgabe
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function buildCourses(): array
    {
        $out = [];
        foreach (self::COURSES as $i => $course) {
            $out[] = $this->finalize([
                'source_id' => $course['code'],
                'code' => $course['code'],
                'name' => $course['name'],
                'course_type' => $course['type'],
                'language' => 'de-DE',
                'status' => 'published',
                'sort_order' => $i + 1,
                'modules' => $this->courseModules[$course['code']],
                'source_file' => 'database/seeders/CourseSeeder.php',
            ]);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function buildModules(): array
    {
        $out = [];
        foreach (self::MODULES as $i => $module) {
            $out[] = $this->finalize([
                'source_id' => $module['code'],
                'code' => $module['code'],
                'name' => $module['name'],
                'status' => 'published',
                'sort_order' => $i + 1,
                'questions' => $this->moduleQuestions[$module['code']],
                'source_file' => 'database/seeders/ModuleSeeder.php',
            ]);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function buildMedia(): array
    {
        $out = [];
        ksort($this->media);
        foreach ($this->media as $media) {
            if (! $media['_used']) {
                continue;
            }
            $file = $media['_file'];
            $record = array_diff_key($media, ['_file' => 1, '_used' => 1]);
            $record['mime_type'] = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
            $record['size'] = filesize($file);
            $record['sha256'] = hash_file('sha256', $file);
            $record['vector_source'] ??= null;
            $out[] = $this->finalize($record);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function buildPapers(): array
    {
        $out = [];
        foreach (self::COURSES as $course) {
            foreach ($this->papers[$course['code']] ?? [] as $paper) {
                $out[] = $this->finalize($paper);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function buildPraxis(): array
    {
        $out = [];
        foreach (self::COURSES as $course) {
            $tasks = $this->praxis[$course['code']] ?? [];
            usort($tasks, static fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
            foreach ($tasks as $task) {
                $out[] = $this->finalize($task);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function finalize(array $record): array
    {
        unset($record['hash']);
        $record['hash'] = 'sha256:'.hash('sha256', Json::encode($record));

        return $record;
    }

    /** @return array<int|string,mixed> */
    private function includeData(string $file): array
    {
        $path = $this->data.'/'.$file;
        if (! is_file($path)) {
            throw new RuntimeException("Datendatei fehlt: {$path}");
        }
        $data = (static fn (string $p) => include $p)($path);
        if (! is_array($data)) {
            throw new RuntimeException("Datendatei liefert kein Array: {$file}");
        }

        return $data;
    }
}
