<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 15 amtlichen Prüfungsbögen für SBF Binnen unter Segel (SBF-BIN-SEGEL),
 * importiert aus database/data/sbf_binnen_segeln_pruefungsboegen.csv (vom
 * Betreiber bereitgestellt, Quelle: seeundbinnen.de). Jeder Bogen enthält
 * 37 Multiple-Choice-Fragen -- 7 allgemeine Basisfragen, 23 spezifische
 * Fragen Binnen und 7 spezifische Segelfragen. Die Fragenfamilie ergibt
 * sich eindeutig aus dem Nummernbereich der Fragenkatalog-Nr. (1-72 =
 * shared_basis, 73-253 = binnen_specific, 254-300 = segel_specific).
 */
class BinnenSegelnExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-SEGEL')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_binnen_segeln_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Pruefungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);
            $contentRole = $this->contentRoleFor((int) $officialNumber);

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
    }

    private function contentRoleFor(int $officialNumber): ?string
    {
        return match (true) {
            $officialNumber >= 1 && $officialNumber <= 72 => 'shared_basis',
            $officialNumber >= 73 && $officialNumber <= 253 => 'binnen_specific',
            $officialNumber >= 254 && $officialNumber <= 300 => 'segel_specific',
            default => null,
        };
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
