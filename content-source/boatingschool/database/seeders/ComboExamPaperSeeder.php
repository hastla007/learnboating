<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Prüfungsbögen für den Kombikurs "SBF See & Binnen" (SBF-SEE-BIN):
 * die bestehenden, bereits amtlich geprüften 15 Bögen von SBF-SEE und
 * ADD-SEE-TO-BIN werden unter der eigenen Kurs-ID des Kombikurses
 * gespiegelt. Für den Binnen-Teil die Ergänzungsbögen (23 Binnen-Fragen)
 * statt der vollen SBF-Binnen-Bögen: werden beide Bereiche am selben Tag
 * geprüft, kommen die Basisfragen nur einmal dran (im See-Teil). Bestehende
 * Installationen stellt die Migration 000477 um. Gespiegelt wird, weil
 * exam_paper an course_id hängt (nicht an course_module) und ein einzelnes
 * Entitlement auf den Kombikurs für beide Teilprüfungen reichen soll. Da paper_number je Kurs eindeutig sein muss, bekommen
 * SBF-Binnen-Bögen einen Offset von 100 (Bogen 1 → paper_number 101);
 * siehe App\Support\ComboExamGroups für die Rückübersetzung in der UI.
 */
class ComboExamPaperSeeder extends Seeder
{
    private const SOURCES = [
        ['course' => 'SBF-SEE', 'offset' => 0],
        ['course' => 'ADD-SEE-TO-BIN', 'offset' => 100],
    ];

    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();

        if (! $combo || $combo->examPapers()->exists()) {
            return;
        }

        foreach (self::SOURCES as $source) {
            $sourceCourse = CourseDefinition::withoutGlobalScopes()->where('code', $source['course'])->whereNull('tenant_id')->first();

            if (! $sourceCourse) {
                continue;
            }

            foreach ($sourceCourse->examPapers()->with('paperQuestions')->get() as $sourcePaper) {
                $paper = ExamPaper::create([
                    'course_id' => $combo->id,
                    'paper_number' => $sourcePaper->paper_number + $source['offset'],
                    'sort_order' => $sourcePaper->paper_number + $source['offset'],
                ]);

                foreach ($sourcePaper->paperQuestions as $sourceQuestion) {
                    ExamPaperQuestion::create([
                        'exam_paper_id' => $paper->id,
                        'question_id' => $sourceQuestion->question_id,
                        'position' => $sourceQuestion->position,
                    ]);
                }
            }
        }
    }
}
