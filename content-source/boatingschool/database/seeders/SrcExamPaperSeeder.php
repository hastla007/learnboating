<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 12 amtlichen Prüfungsbögen für den SRC Vollkurs (SRC), je 24 Fragen
 * (Bestehensgrenze 19/24, Zeitlimit 30 Minuten -- siehe ExamRuleSetSeeder).
 * Quelle: Fachstelle der WSV für Verkehrstechniken, Koblenz, 22.08.2011,
 * "Zusammenstellung der Fragen für die Fragebögen für die SRC-Prüfung"
 * (Anlage 2a). Die Prüfung umfasst zusätzlich ein englisches Diktat und
 * eine schriftliche Übersetzung, die nicht Teil dieser Online-Simulation
 * sind.
 */
class SrcExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/src_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Pruefungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);

            $question = ContentQuestion::where('official_number', $officialNumber)
                ->where('content_role', 'src_official')
                ->first();

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
