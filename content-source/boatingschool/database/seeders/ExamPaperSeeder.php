<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Die 15 amtlichen Prüfungsbögen für SBF See, importiert aus
 * database/data/sbf_see_pruefungsboegen.csv (Quelle: die einzelnen Bögen
 * unter bootsfuehrerscheinpruefung.de/sbfsee/pruefungsboegen/, vom Betreiber
 * bereitgestellt). Jede Zeile referenziert eine Frage über ihre
 * Fragenkatalog-Nr. (= content_question.official_number), eindeutig erst in
 * Kombination mit der Fragenfamilie ("Basisfragen" vs. "Spezifische Fragen
 * See" aus der Tags-Spalte), da dieselbe Katalognummer je nach Familie
 * mehrfach vorkommt (z. B. Basis-Frage 8 und SRC-Frage 8 sind verschiedene
 * Fragen). Die Spalte "Bilddateien im ZIP" wird nicht mehr ausgewertet: die
 * Fragenbilder kommen von ELWIS (ElwisQuestionImageSeeder), die früher von
 * hier angehängten Bilder von bootsfuehrerscheinpruefung.de sind entfernt.
 */
class ExamPaperSeeder extends Seeder
{
    private const CONTENT_ROLE_BY_TAG = [
        'Basisfragen' => 'shared_basis',
        'Spezifische Fragen See' => 'see_specific',
    ];

    public function run(): void
    {
        $course = $this->resolveCourse();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = $this->csvPath();
        if (! file_exists($csvPath)) {
            return;
        }

        // Bug-Review (Seeder nicht atomar): ohne Transaktion hinterließ ein
        // Abbruch mitten im CSV-Durchlauf (z. B. eine fehlerhafte Zeile,
        // ein DB-Verbindungsabbruch, ein abgebrochener Deploy) dauerhaft
        // nur die bis dahin angelegten Bögen (z. B. 7 statt 15) -- die
        // Guard-Klausel oben (examPapers()->exists()) hielt einen erneuten
        // Seed-Lauf danach für immer davon ab, die fehlenden Bögen
        // nachzuholen. Die gesamte Verarbeitung läuft jetzt in einer
        // Transaktion: schlägt sie irgendwo fehl, bleibt kein einziger
        // Bogen zurück, und der nächste Seed-Lauf beginnt wieder von vorn.
        DB::transaction(function () use ($course, $csvPath) {
            $papers = [];

            foreach ($this->readCsv($csvPath) as $row) {
                $paperNumber = (int) $row['Prüfungsbogen'];
                $position = (int) $row['Position im Bogen'];
                $officialNumber = trim($row['Fragenkatalog-Nr.']);
                $contentRole = $this->resolveContentRole($row['Tags'] ?? '');

                $question = $contentRole
                    ? ContentQuestion::where('official_number', $officialNumber)->where('content_role', $contentRole)->first()
                    : null;

                if (! $question) {
                    continue;
                }

                $paper = $papers[$paperNumber] ??= ExamPaper::create([
                    'course_id' => $course->id,
                    'paper_number' => $paperNumber,
                    'sort_order' => $paperNumber,
                ]);

                ExamPaperQuestion::create([
                    'exam_paper_id' => $paper->id,
                    'question_id' => $question->id,
                    'position' => $position,
                ]);

            }
        });
    }

    /**
     * In einer eigenen, überschreibbaren Methode, damit ein Test die
     * Atomicität (Bug-Review Seeder nicht atomar) gegen einen eigenen,
     * isolierten Testkurs statt der echten SBF-SEE-Daten prüfen kann.
     */
    protected function resolveCourse(): ?CourseDefinition
    {
        return CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();
    }

    /** Siehe resolveCourse() -- aus demselben Grund überschreibbar. */
    protected function csvPath(): string
    {
        return database_path('data/sbf_see_pruefungsboegen.csv');
    }

    private function resolveContentRole(string $tagsCell): ?string
    {
        foreach (self::CONTENT_ROLE_BY_TAG as $tag => $role) {
            if (str_contains($tagsCell, $tag)) {
                return $role;
            }
        }

        return null;
    }

    /** @return iterable<array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }
}
