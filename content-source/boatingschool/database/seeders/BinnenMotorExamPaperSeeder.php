<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 15 amtlichen Prüfungsbögen für SBF Binnen Motor (SBF-BIN-MOTOR),
 * importiert aus database/data/sbf_binnen_pruefungsboegen.csv (vom
 * Betreiber bereitgestellt, Quelle: seeundbinnen.de). Jeder Bogen enthält
 * 30 Multiple-Choice-Fragen -- 7 allgemeine Basisfragen und 23 spezifische
 * Fragen Binnen. Jede Zeile referenziert eine Frage über ihre
 * Fragenkatalog-Nr. (= content_question.official_number), eindeutig erst
 * in Kombination mit der Fragenfamilie aus der Tags-Spalte, analog zu
 * ExamPaperSeeder (SBF See).
 */
class BinnenMotorExamPaperSeeder extends Seeder
{
    private const CONTENT_ROLE_BY_TAG = [
        'Basisfragen' => 'shared_basis',
        'Spezifische Fragen Binnen' => 'binnen_specific',
    ];

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_binnen_pruefungsboegen.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $papers = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $paperNumber = (int) $row['Pruefungsbogen'];
            $position = (int) $row['Position im Bogen'];
            $officialNumber = trim($row['Fragenkatalog-Nr.']);
            $contentRole = self::CONTENT_ROLE_BY_TAG[$row['Tags']] ?? null;

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
