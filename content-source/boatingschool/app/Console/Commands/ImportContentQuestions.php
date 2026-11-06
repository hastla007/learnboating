<?php

namespace App\Console\Commands;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Normalizer;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Setzt content_import_mapping.md um: Excel -> Parse -> Validate -> Diff -> Publish.
 * Die Staging/Review-UI aus dem Technikpaket ist für V1 dieses Prototyps nicht
 * gebaut; der Import validiert und veröffentlicht in einem Schritt, protokolliert
 * aber jede Zeile nachvollziehbar (Abnahmekriterium: "838 Quellzeilen sind
 * erklärbar: importiert, bewusst zurückgestellt oder als Fehler ausgewiesen").
 */
class ImportContentQuestions extends Command
{
    protected $signature = 'content:import {path=database/data/zentrale_fragenbasis.xlsx} {--sheet=Central Question Pool}';

    protected $description = 'Importiert die zentrale Fragenbasis aus der Excel-Arbeitsmappe.';

    /** @var array<string,string> */
    private array $moduleMap = [
        'Basis' => 'SBF_BASIS',
        'SBF See' => 'SBF_SEE',
        'SBF Binnen' => 'SBF_BINNEN',
        'SBF Binnen Segeln' => 'SBF_BINNEN_SEGELN',
        'SRC' => 'SRC',
        'UBI' => 'UBI',
    ];

    /** @var Collection<int, string>|null */
    private ?Collection $importModuleIds = null;

    private int $imported = 0;

    private int $unchanged = 0;

    private int $revised = 0;

    private int $skipped = 0;

    /** @var array<int,string> */
    private array $errors = [];

    public function handle(): int
    {
        // Schreibt in den zentralen, mandantenunabhängigen Fragenkatalog --
        // dieselbe administrative Operation wie Migrationen/Seeder, daher
        // über die Owner-Verbindung statt der least-privilege App-Rolle, die
        // seit Sicherheitsreview M7 dort nur noch SELECT hat.
        config(['database.default' => 'pgsql_admin']);

        // Mehrere Läufe auf derselben Instanz (Upload im Superadmin, Seeder):
        // Zähler und Fehler je Lauf neu beginnen.
        $this->imported = $this->unchanged = $this->revised = $this->skipped = 0;
        $this->errors = [];

        $path = base_path($this->argument('path'));
        if (! is_file($path)) {
            $this->error("Datei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $spreadsheet = IOFactory::load($path);
        $sheetName = $this->option('sheet');
        if (! $spreadsheet->sheetNameExists($sheetName)) {
            $this->error("Blatt '{$sheetName}' existiert nicht.");

            return self::FAILURE;
        }

        $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, true, false);
        $header = array_map('trim', array_shift($rows));

        $modules = Module::all()->keyBy('code');
        if ($modules->isEmpty()) {
            $this->error('Keine Module gefunden. Bitte zuerst `php artisan db:seed --class=ModuleSeeder` ausführen.');

            return self::FAILURE;
        }

        $this->withProgressBar($rows, function ($row, $bar, $rowIndex) use ($header) {
            $this->importRow(array_combine($header, $row), $rowIndex + 2);
        });
        $this->newLine(2);

        $this->info("Importiert (neue Fragen): {$this->imported}");
        $this->info("Unverändert (Reimport ohne Änderung): {$this->unchanged}");
        $this->info("Neue Revision (Inhalt geändert): {$this->revised}");
        $this->warn("Übersprungen/Fehler: {$this->skipped}");

        foreach ($this->errors as $line => $message) {
            $this->line("  Zeile {$line}: {$message}");
        }

        return self::SUCCESS;
    }

    private function importRow(array $row, int $lineNumber): void
    {
        $contentId = trim((string) ($row['content_id'] ?? ''));
        $questionText = $this->text($row['question'] ?? '');
        $moduleValue = trim((string) ($row['module'] ?? ''));

        if ($contentId === '' || $questionText === '') {
            $this->recordSkip($lineNumber, 'content_id oder question fehlt.');

            return;
        }

        $moduleCode = $this->moduleMap[$moduleValue] ?? null;
        if (! $moduleCode) {
            $this->recordSkip($lineNumber, "Unbekannter Modulwert '{$moduleValue}'.");

            return;
        }

        $module = Module::where('code', $moduleCode)->first();
        if (! $module) {
            $this->recordSkip($lineNumber, "Modul '{$moduleCode}' ist nicht angelegt.");

            return;
        }

        $answers = [
            'A' => $this->text($row['answer_a'] ?? ''),
            'B' => $this->text($row['answer_b'] ?? ''),
            'C' => $this->text($row['answer_c'] ?? ''),
            'D' => $this->text($row['answer_d'] ?? ''),
        ];
        $correctKey = strtoupper(trim((string) ($row['correct_answer'] ?? '')));

        if (in_array('', $answers, true)) {
            $this->recordSkip($lineNumber, 'Nicht alle vier Antworten (A-D) vorhanden.');

            return;
        }
        if (! in_array($correctKey, ['A', 'B', 'C', 'D'], true)) {
            $this->recordSkip($lineNumber, "correct_answer '{$correctKey}' ist nicht A-D.");

            return;
        }

        $imageRequired = $this->parseBool($row['image_required'] ?? false);

        $canonical = [
            'question_text' => $questionText,
            'topic' => trim((string) ($row['topic'] ?? '')),
            'subtopic' => trim((string) ($row['subtopic'] ?? '')),
            'competency' => trim((string) ($row['competency'] ?? '')),
            'smartmodus_kategorie' => trim((string) ($row['smartmodus_kategorie'] ?? '')),
            'image_required' => $imageRequired,
            'answers' => $answers,
            'correct' => $correctKey,
            'source_question_id' => trim((string) ($row['source_question_id'] ?? '')),
        ];
        $canonicalHash = md5(json_encode($canonical));

        DB::transaction(function () use ($row, $contentId, $canonical, $canonicalHash, $module, $answers, $correctKey) {
            $question = ContentQuestion::firstOrCreate(
                ['content_id' => $contentId],
                [
                    'official_number' => $row['official_number'] !== null ? (string) $row['official_number'] : null,
                    'content_role' => trim((string) ($row['content_role'] ?? '')) ?: null,
                    'language' => 'de-DE',
                    'active' => true,
                ]
            );

            $latestRevision = $question->revisions()->orderByDesc('revision_no')->first();
            // Bug-Review (Seed überschreibt UBI-Korrekturen aus dem Editor):
            // der Diff gegen "die letzte Revision" verglich bisher auch
            // gegen eine vom Fragen-Editor angelegte Revision (siehe
            // Superadmin\QuestionController::update()) -- hat ein
            // Superadmin eine fehlerhafte Frage korrigiert, ohne dass die
            // Excel-Quelle seitdem denselben Fehler behoben hätte, wich der
            // unveränderte Excel-Text zwangsläufig von der korrigierten
            // Editor-Fassung ab und legte bei jedem Reimport erneut eine
            // "review"-Revision mit dem alten, falschen Text an. Diese
            // Revision wartete dann als Draft, dessen Veröffentlichung die
            // Korrektur des Superadmins wieder verworfen hätte. Der Diff
            // läuft deshalb jetzt gezielt gegen die zuletzt tatsächlich
            // IMPORTIERTE Fassung (edited_by_superadmin = false) -- eine
            // seitdem vom Editor vorgenommene Korrektur bleibt davon
            // unberührt, solange die Excel-Quelle selbst unverändert ist.
            $lastImportedRevision = $question->revisions()
                ->where('edited_by_superadmin', false)
                ->orderByDesc('revision_no')
                ->first();

            if ($lastImportedRevision && $this->hashRevision($lastImportedRevision) === $canonicalHash) {
                $this->unchanged++;
                $revisionForModule = $latestRevision;
            } else {
                $revisionForModule = ContentQuestionRevision::create([
                    'question_id' => $question->id,
                    'revision_no' => $latestRevision ? $latestRevision->revision_no + 1 : 1,
                    'question_type' => 'single_choice',
                    'question_text' => $canonical['question_text'],
                    'topic' => $canonical['topic'] ?: null,
                    'subtopic' => $canonical['subtopic'] ?: null,
                    'competency' => $canonical['competency'] ?: null,
                    'smartmodus_kategorie' => $canonical['smartmodus_kategorie'] ?: null,
                    'image_required' => $canonical['image_required'],
                    // Optionale Spalten source_catalog/source_version: eine
                    // aus einem neueren amtlichen Katalog aktualisierte Zeile
                    // (z. B. UBI, Stand Oktober 2026) belegt ihre Herkunft
                    // selbst, statt pauschal als Stand der Fragenbasis 09/2026
                    // zu gelten.
                    'source_catalog' => trim((string) ($row['source_catalog'] ?? '')) ?: 'Zentrale Fragenbasis SBF/SRC/UBI',
                    'source_version' => trim((string) ($row['source_version'] ?? '')) ?: '2026-09',
                    'source_question_id' => $canonical['source_question_id'] ?: null,
                    // Publish direkt für den Prototyp-Bootstrap; produktiv würde
                    // eine neue Revision im Status "review" auf fachliche
                    // Freigabe warten (siehe content_import_mapping.md Abschnitt 4).
                    'editorial_status' => $latestRevision ? 'review' : 'published',
                    'rights_status' => 'review',
                ]);

                foreach ($answers as $key => $text) {
                    ContentAnswer::create([
                        'revision_id' => $revisionForModule->id,
                        'answer_key' => $key,
                        'answer_text' => $text,
                        'is_correct' => $key === $correctKey,
                        'sort_order' => ord($key) - ord('A') + 1,
                    ]);
                }

                $latestRevision ? $this->revised++ : $this->imported++;
            }

            // Bug-Review BB18: syncWithoutDetaching() auf $module->questions()
            // hängte die Frage zwar an das in dieser Zeile genannte Modul an,
            // ließ eine Zuordnung zu einem FRÜHEREN Modul (geänderter
            // Modulwert bei gleichbleibender content_id) aber unberührt
            // stehen -- die Frage erschien danach doppelt. sync() von der
            // FRAGE aus (statt vom Modul aus) ersetzt alle ihre
            // Modulzuordnungen durch genau dieses eine Modul, ohne andere
            // Fragen im alten Modul anzutasten.
            //
            // Ersetzt werden aber nur Zuordnungen zu Modulen, die dieser
            // Import selbst verwaltet ($moduleMap): Module anderer Seeder
            // (z. B. UBI_ERG aus UbiErgaenzungSeeder, das 79 UBI-Fragen
            // zusätzlich trägt) bleiben unangetastet -- ein reiner
            // Excel-Reimport hätte den UBI-Ergänzungskurs sonst geleert.
            $otherImportModuleIds = $this->importModuleIds()->reject(fn ($id) => $id === $module->id);
            $question->modules()->detach($otherImportModuleIds->all());
            $question->modules()->syncWithoutDetaching([$module->id => ['required' => true]]);
        });
    }

    private function hashRevision(ContentQuestionRevision $revision): string
    {
        $answers = $revision->answers()->orderBy('answer_key')->get(['answer_key', 'answer_text', 'is_correct']);

        $canonical = [
            'question_text' => $revision->question_text,
            'topic' => (string) $revision->topic,
            'subtopic' => (string) $revision->subtopic,
            'competency' => (string) $revision->competency,
            'smartmodus_kategorie' => (string) $revision->smartmodus_kategorie,
            'image_required' => $revision->image_required,
            'answers' => $answers->pluck('answer_text', 'answer_key')->toArray(),
            'correct' => optional($answers->firstWhere('is_correct', true))->answer_key,
            'source_question_id' => (string) $revision->source_question_id,
        ];

        return md5(json_encode($canonical));
    }

    /** @return Collection<int, string> IDs der Module aus $moduleMap. */
    private function importModuleIds(): Collection
    {
        return $this->importModuleIds ??= Module::whereIn('code', array_values($this->moduleMap))->pluck('id');
    }

    /**
     * Aus PDFs kopierte Texte enthalten Umlaute teils zerlegt ("u" +
     * kombinierendes Trema U+0308 statt "ü", so im UBI-Katalog 10/2026) --
     * sieht gleich aus, findet bei einer Textsuche nach "ü" aber nichts.
     * NFC setzt sie wieder zu einem Zeichen zusammen.
     */
    private function text(mixed $value): string
    {
        $text = trim((string) $value);

        return Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'wahr', 'ja', 'x'], true);
    }

    private function recordSkip(int $lineNumber, string $message): void
    {
        $this->skipped++;
        $this->errors[$lineNumber] = $message;
    }
}
