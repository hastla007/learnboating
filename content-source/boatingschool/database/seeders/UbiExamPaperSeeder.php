<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Die amtlichen Übungsfragebögen des ABVT Koblenz, Stand Oktober 2026,
 * jeweils abgeglichen mit den Lösungsbögen derselben Ausgabe:
 *
 * - UBI Vollkurs (UBI): 12 Bögen à 22 Fragen laut "Fragenverteilung /
 *   Prüfung UKW-Sprechfunkzeugnis (UBI)" (ubi_uebungsboegen.csv), je 5
 *   Fragen aus Teil I, 5 aus II, 4 aus III, 4 aus IV und 4 aus V;
 *   Bestehensgrenze 17/22, 60 Minuten (siehe ExamRuleSetSeeder).
 * - Ergänzung SRC → UBI (ADD-UBI-TO-SRC): 12 Bögen à 10 Fragen laut
 *   "Fragenverteilung / Ergänzungsprüfung Seefunkzeugnisse auf UBI"
 *   (ubi_ergaenzung_uebungsboegen.csv), je 2 Fragen pro Teil, alle aus dem
 *   Ergänzungskatalog (Modul UBI_ERG). Der Lösungsbogen 7 nennt für Frage
 *   3 die Katalognummer [26] -- der Übungsbogen selbst, die
 *   Fragenverteilung und die angegebene Lösung a) gehören aber zu Frage
 *   36 (26 ist auch nicht Teil des Ergänzungskatalogs); übernommen ist 36.
 *
 * Die CSVs nennen Nummern des UBI-Gesamtfragenkatalogs. Die
 * Antwortreihenfolge der gedruckten Bögen wird nicht übernommen, die
 * Prüfungssimulation mischt die Antworten selbst. Die praktische Prüfung
 * (Sprechfunkverkehr am Gerät) ist nicht Teil dieser Online-Simulation.
 *
 * Die Bögen eines Kurses entstehen in einer Transaktion und nur, wenn
 * alle Katalognummern auflösbar sind -- ein unvollständiger Bogen würde
 * die Bestehensgrenze verfälschen.
 */
class UbiExamPaperSeeder extends Seeder
{
    private const PAPERS = [
        'UBI' => ['file' => 'ubi_uebungsboegen.csv', 'module' => 'UBI'],
        'ADD-UBI-TO-SRC' => ['file' => 'ubi_ergaenzung_uebungsboegen.csv', 'module' => 'UBI_ERG'],
    ];

    public function run(): void
    {
        foreach (self::PAPERS as $courseCode => $config) {
            $this->seedCourse($courseCode, $config['file'], $config['module']);
        }
    }

    private function seedCourse(string $courseCode, string $file, string $moduleCode): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/'.$file);
        if (! file_exists($csvPath)) {
            return;
        }

        // Nur Fragen des Kursmoduls: eine Katalognummer außerhalb des
        // Ergänzungskatalogs wäre ein Datenfehler, kein Bogeninhalt.
        $module = Module::where('code', $moduleCode)->first();
        $questionIds = $module
            ? $module->questions()->where('content_role', 'ubi_official')->pluck('content_question.id', 'official_number')
            : collect();
        $rows = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $officialNumber = trim($row['Fragenkatalog-Nr.']);

            if (! isset($questionIds[$officialNumber])) {
                $this->command?->warn("Übungsbögen für {$courseCode} übersprungen: Katalogfrage {$officialNumber} fehlt im Modul {$moduleCode}.");

                return;
            }

            $rows[] = [
                'paper_number' => (int) $row['Pruefungsbogen'],
                'position' => (int) $row['Position im Bogen'],
                'question_id' => $questionIds[$officialNumber],
            ];
        }

        DB::transaction(function () use ($course, $rows) {
            $papers = [];

            foreach ($rows as $row) {
                $paper = $papers[$row['paper_number']] ??= ExamPaper::create([
                    'course_id' => $course->id,
                    'paper_number' => $row['paper_number'],
                    'sort_order' => $row['paper_number'],
                ]);

                ExamPaperQuestion::create([
                    'exam_paper_id' => $paper->id,
                    'question_id' => $row['question_id'],
                    'position' => $row['position'],
                ]);
            }
        });
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
