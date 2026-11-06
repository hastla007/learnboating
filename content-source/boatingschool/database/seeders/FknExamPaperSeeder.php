<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Die 3 amtlichen Prüfungsbögen für den FKN Vollkurs, je 15 Fragen
 * (siehe fkn_pruefungsboegen.php, Quelle: PANord). Jede Frage wird in
 * ihrer im Katalog dokumentierten Form abgebildet (multi_choice mit
 * Optionen oder open_text als Lernkarte) -- siehe fkn_catalog.php für
 * die Begründung, warum die Plattform nicht pro Bogen-Position zwischen
 * offener und Multiple-Choice-Darstellung derselben Frage wechselt.
 */
class FknExamPaperSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'FKN')->whereNull('tenant_id')->first();

        if (! $course || $course->examPapers()->exists()) {
            return;
        }

        $boegen = include database_path('data/fkn_pruefungsboegen.php');

        foreach ($boegen as $paperNumber => $questionNumbers) {
            $paper = ExamPaper::create([
                'course_id' => $course->id,
                'paper_number' => $paperNumber,
                'sort_order' => $paperNumber,
            ]);

            foreach ($questionNumbers as $position => $officialNumber) {
                $question = ContentQuestion::where('official_number', (string) $officialNumber)
                    ->where('content_role', 'fkn_official')
                    ->first();

                if (! $question) {
                    continue;
                }

                ExamPaperQuestion::create([
                    'exam_paper_id' => $paper->id,
                    'question_id' => $question->id,
                    'position' => $position + 1,
                ]);
            }
        }
    }
}
