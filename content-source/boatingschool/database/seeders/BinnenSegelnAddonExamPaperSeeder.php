<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 15 amtlichen Prüfungsbögen für den Ergänzungskurs "Binnen → Segeln"
 * (ADD-BIN-SEGEL): für Inhaber eines SBF Binnen entfallen der allgemeine
 * Teil und die Basisfragen, sodass jeder Bogen nur noch die 7
 * spezifischen Segelfragen enthält (Bestehensgrenze 5/7, Zeitlimit 15
 * Minuten -- siehe ExamRuleSetSeeder). Die Bogen-Zusammenstellung
 * entspricht exakt den Segelfragen (Position 31-37) der gleichnamigen
 * Bögen aus BinnenSegelnExamPaperSeeder (SBF-BIN-SEGEL), da die amtlichen
 * Bögen für die Kombiprüfung und diese Ergänzungsprüfung denselben
 * Segelfragen-Block je Bogen-Nummer verwenden.
 */
class BinnenSegelnAddonExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-BIN-SEGEL')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_binnen_ergaenzung_segeln_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Pruefungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);

            $question = ContentQuestion::where('official_number', $officialNumber)
                ->where('content_role', 'segel_specific')
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
