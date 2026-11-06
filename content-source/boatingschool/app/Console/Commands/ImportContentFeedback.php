<?php

namespace App\Console\Commands;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill von Richtig-/Falsch-Feedback für bereits importierte Fragen
 * (siehe database/data/sbf_see_binnen_fragen_feedback.csv). Bug-Review B27:
 * schrieb die Feedback-Texte bisher direkt auf die veröffentlichte
 * Revision statt -- konsistent mit Superadmin\QuestionController::update()
 * -- eine neue Revision anzulegen. Das verletzte die an anderer Stelle
 * dieser App durchgehend eingehaltene Unveränderlichkeit veröffentlichter
 * Revisionen: exam_session_question/attempt verweisen per revision_id auf
 * den exakten Stand, der einem Lerner zu diesem Zeitpunkt angezeigt wurde,
 * und ein nachträgliches Umschreiben ließ diesen historischen Bezug
 * rückwirkend "falsch" aussehen. Frage und Antworten ändern sich inhaltlich
 * nicht -- nur die erklärenden Feedback-Texte werden ergänzt, aber über
 * eine neue, unveränderliche Revision statt eines direkten Updates. Die
 * Feedback-Spalten der Quelldatei beginnen redundant mit "Richtig."/"Falsch." --
 * das steht in der Oberfläche bereits als eigene Überschrift, deshalb wird
 * diese erste Zeile beim Import abgeschnitten. "Falsch."-Texte wiederholen
 * zusätzlich noch die richtige Antwort als eigene Zeile ("Richtig ist:
 * „...“") -- das steht in der Oberfläche bereits als "Richtige Antwort:
 * ..." darüber, deshalb wird auch diese Zeile beim Import entfernt.
 */
class ImportContentFeedback extends Command
{
    protected $signature = 'content:import-feedback {path=database/data/sbf_see_binnen_fragen_feedback.csv}';

    protected $description = 'Ergänzt Richtig-/Falsch-Feedback auf bereits importierten Fragen aus einer CSV-Datei.';

    private int $updated = 0;

    private int $unchanged = 0;

    private int $skipped = 0;

    /** @var array<int,string> */
    private array $errors = [];

    public function handle(): int
    {
        // Sicherheitsreview M7 hat INSERT/UPDATE auf content_question_revision
        // (u. a.) der App-Rolle entzogen, da dieses Schreibrecht nirgends in
        // der App selbst genutzt wird -- Import-Befehle laufen deshalb wie
        // ImportContentQuestions über die Owner-Verbindung.
        config(['database.default' => 'pgsql_admin']);

        // Der DatabaseSeeder ruft den Befehl für mehrere Dateien auf derselben
        // Instanz auf -- Zähler und Fehler je Lauf neu beginnen.
        $this->updated = $this->unchanged = $this->skipped = 0;
        $this->errors = [];

        $path = base_path($this->argument('path'));
        if (! is_file($path)) {
            $this->error("Datei nicht gefunden: {$path}");

            return self::FAILURE;
        }

        $rows = iterator_to_array($this->readCsv($path));

        $this->withProgressBar($rows, function (array $row, $bar, int $rowIndex) {
            $this->importRow($row, $rowIndex + 2);
        });
        $this->newLine(2);

        $this->info("Feedback aktualisiert: {$this->updated}");
        $this->info("Unverändert (bereits identisch): {$this->unchanged}");
        $this->warn("Übersprungen/Fehler: {$this->skipped}");

        foreach ($this->errors as $line => $message) {
            $this->line("  Zeile {$line}: {$message}");
        }

        return self::SUCCESS;
    }

    private function importRow(array $row, int $lineNumber): void
    {
        $contentId = trim((string) ($row['content_id'] ?? ''));
        if ($contentId === '') {
            $this->recordSkip($lineNumber, 'content_id fehlt.');

            return;
        }

        $feedbackCorrect = $this->stripLeadingVerdict(trim((string) ($row['feedback_correct'] ?? '')), 'Richtig.');
        $feedbackIncorrect = $this->stripLeadingVerdict(trim((string) ($row['feedback_incorrect'] ?? '')), 'Falsch.');
        $feedbackIncorrect = $this->stripLeadingCorrectAnswerLine($feedbackIncorrect);

        if ($feedbackCorrect === '' && $feedbackIncorrect === '') {
            $this->recordSkip($lineNumber, "Kein Feedback für '{$contentId}' in der Quelldatei.");

            return;
        }

        $question = ContentQuestion::where('content_id', $contentId)->first();
        if (! $question) {
            $this->recordSkip($lineNumber, "Keine Frage mit content_id '{$contentId}' gefunden.");

            return;
        }

        $revision = $question->publishedRevision();
        if (! $revision) {
            $this->recordSkip($lineNumber, "Keine veröffentlichte Revision für '{$contentId}' gefunden.");

            return;
        }

        $feedbackCorrect = $feedbackCorrect ?: null;
        $feedbackIncorrect = $feedbackIncorrect ?: null;

        // Bug-Review (Seed überschreibt Feedback-Text aus dem Editor): die
        // veröffentlichte Revision kann -- genau wie bei ImportContentQuestions
        // (siehe edited_by_superadmin) -- von einem Superadmin über den
        // Fragen-Editor angelegt worden sein, der dort auch eigene Feedback-
        // Texte eingetragen hat. Ein Reimport dieser CSV verglich bisher nur
        // gegen den aktuellen Wert und überschrieb dabei auch eine schon
        // gesetzte Editor-Fassung mit dem (ggf. veralteten) CSV-Text. Bei
        // einer Editor-Revision wird deshalb nur noch befüllt, was dort noch
        // LEER ist -- ein bereits vom Superadmin eingetragener Text bleibt
        // unangetastet.
        if ($revision->edited_by_superadmin) {
            $feedbackCorrect = $revision->feedback_correct ?? $feedbackCorrect;
            $feedbackIncorrect = $revision->feedback_incorrect ?? $feedbackIncorrect;
        }

        if ($revision->feedback_correct === $feedbackCorrect && $revision->feedback_incorrect === $feedbackIncorrect) {
            $this->unchanged++;

            return;
        }

        DB::transaction(function () use ($question, $revision, $feedbackCorrect, $feedbackIncorrect) {
            $revision->update(['editorial_status' => 'deprecated']);

            // Bug-Review F8: revision_no + 1 der veröffentlichten Revision
            // kollidierte mit dem Unique-Index (question_id, revision_no),
            // sobald bereits eine neuere, noch unveröffentlichte Revision im
            // Status "review" existierte (siehe ImportContentQuestions) --
            // die dadurch ausgelöste, unabgefangene QueryException brach
            // den gesamten Feedback-Import ab, statt nur diese eine Zeile
            // zu überspringen. max() über ALLE Revisionen der Frage
            // vermeidet die Kollision.
            $nextRevisionNo = $question->revisions()->max('revision_no') + 1;

            $newRevision = ContentQuestionRevision::create([
                'question_id' => $revision->question_id,
                'revision_no' => $nextRevisionNo,
                'question_type' => $revision->question_type,
                'question_text' => $revision->question_text,
                'feedback_correct' => $feedbackCorrect,
                'feedback_incorrect' => $feedbackIncorrect,
                'topic' => $revision->topic,
                'subtopic' => $revision->subtopic,
                'competency' => $revision->competency,
                'smartmodus_kategorie' => $revision->smartmodus_kategorie,
                'image_required' => $revision->image_required,
                'source_catalog' => $revision->source_catalog,
                'source_version' => $revision->source_version,
                'source_page' => $revision->source_page,
                'source_question_id' => $revision->source_question_id,
                'editorial_status' => 'published',
                'rights_status' => $revision->rights_status,
                'rights_owner' => $revision->rights_owner,
                'license_reference' => $revision->license_reference,
                'valid_from' => $revision->valid_from,
                'valid_to' => $revision->valid_to,
                'reviewed_by' => $revision->reviewed_by,
                'reviewed_at' => $revision->reviewed_at,
                // Bug-Review (AA8: Feedback-Import löscht die Markierung
                // "vom Superadmin korrigiert"): ohne dieses Feld fiel die
                // neue Revision beim Spaltendefault wieder auf false zurück
                // -- selbst wenn ihr question_text wortgleich von einer per
                // Fragen-Editor korrigierten Revision übernommen wurde
                // (siehe $question_text oben). ImportContentQuestions wählt
                // als "zuletzt importierte Fassung" (Diff-Basis für den
                // nächsten Excel-Reimport) ausdrücklich NUR Revisionen mit
                // edited_by_superadmin=false (siehe dortiger Bug-Review
                // "Seed überschreibt UBI-Korrekturen aus dem Editor") -- die
                // hier neu angelegte Revision wurde also fälschlich wieder
                // als unkorrigiert betrachtet, der nächste Excel-Import
                // verglich erneut gegen den unveränderten (alten, falschen)
                // Excel-Text und legte eine neue Review-Revision mit genau
                // diesem Text an.
                'edited_by_superadmin' => $revision->edited_by_superadmin,
            ]);

            foreach ($revision->answers as $answer) {
                ContentAnswer::create([
                    'revision_id' => $newRevision->id,
                    'answer_key' => $answer->answer_key,
                    'answer_text' => $answer->answer_text,
                    'is_correct' => $answer->is_correct,
                    'sort_order' => $answer->sort_order,
                ]);
            }

            // Bug-Review B13: Medienzuordnungen der Vorgänger-Revision
            // übernehmen, sonst würde das zugehörige Fragenbild verschwinden.
            foreach ($revision->media as $media) {
                $newRevision->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
            }
        });

        $this->updated++;
    }

    private function stripLeadingVerdict(string $text, string $verdict): string
    {
        if (str_starts_with($text, $verdict)) {
            $text = substr($text, strlen($verdict));
        }

        return trim($text);
    }

    /**
     * Entfernt die führende "Richtig ist: „...“"-Zeile, die die App bereits als
     * "Richtige Antwort: ..." anzeigt. Die Zeile endet am Zeilenende, nicht am
     * ersten „““ -- Antworten mit eigenen Anführungszeichen (z. B. „An alle
     * Funkstellen“) ließen sonst einen Antwortrest vor der Erklärung stehen.
     */
    private function stripLeadingCorrectAnswerLine(string $text): string
    {
        return trim(preg_replace('/^Richtig ist: „.*?“[ \t]*(?:\R|$)/su', '', $text, 1));
    }

    /** @return iterable<int, array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }

    private function recordSkip(int $lineNumber, string $message): void
    {
        $this->skipped++;
        $this->errors[$lineNumber] = $message;
    }
}
