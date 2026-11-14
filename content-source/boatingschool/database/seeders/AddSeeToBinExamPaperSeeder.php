<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 15 amtlichen Ergänzungsbögen für den Kurs "Ergänzung See → Binnen"
 * (ADD-SEE-TO-BIN), importiert aus
 * database/data/sbf_binnen_ergaenzung_pruefungsboegen.csv (vom Betreiber
 * bereitgestellt, Quelle: seeundbinnen.de, Profil "binnen-motor-with-see").
 * Jeder Bogen enthält 23 Binnen-spezifische Fragen; die 7 Basisfragen
 * entfallen, da diese bei vorhandenem SBF See bereits geprüft wurden.
 * Jede Zeile referenziert eine Frage über ihre Fragenkatalog-Nr.
 * (= content_question.official_number), eindeutig in Kombination mit
 * content_role = binnen_specific.
 */
class AddSeeToBinExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-SEE-TO-BIN')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_binnen_ergaenzung_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Pruefungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);

            $question = ContentQuestion::where('official_number', $officialNumber)
                ->where('content_role', 'binnen_specific')
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
