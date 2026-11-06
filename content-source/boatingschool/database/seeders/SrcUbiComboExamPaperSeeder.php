<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\ExamPaper;
use App\Models\ExamPaperQuestion;
use Illuminate\Database\Seeder;

/**
 * Prüfungsbögen für den Kombikurs "SRC & UBI" (SRC-UBI): analog zu
 * ComboExamPaperSeeder (SBF-SEE-BIN) werden die bereits vorhandenen Bögen
 * der beiden Teilkurse unter der eigenen Kurs-ID des Kombikurses gespiegelt,
 * damit ein einzelnes Entitlement auf den Kombikurs für beide
 * Teilprüfungen reicht. SRC bekommt Offset 0 (Bogen 1-12, amtlich, siehe
 * SrcExamPaperSeeder), UBI Offset 100 (Bogen 101-112, amtliche
 * Übungsfragebögen 10/2026, siehe UbiExamPaperSeeder) und die
 * UBI-Ergänzungsbögen Offset 200 (Bogen 201-212) -- wer beide Scheine in
 * einem Prüfungsdurchlauf ablegt, schreibt SRC plus UBI-Ergänzungsbogen.
 * Rückübersetzung in der UI über App\Support\ComboExamGroups.
 *
 * Das eigene Regelwerk des Kombikurses bleibt draft: es hat nur eine
 * passing_rule und ein Zeitlimit, die beiden Teilprüfungen haben aber
 * verschiedene amtliche Grenzen (SRC 19/24 in 30 Minuten, UBI 17/22 in 60
 * Minuten). Die Bögen selbst laufen deshalb nach dem freigegebenen
 * Regelwerk ihres Teilkurses (siehe CourseDefinition::examRuleSetForPaper()).
 *
 * Muss nach SrcExamPaperSeeder UND UbiExamPaperSeeder laufen (siehe
 * DatabaseSeeder), da beide Quell-Bögen bereits existieren müssen.
 */
class SrcUbiComboExamPaperSeeder extends Seeder
{
    private const SOURCES = [
        ['course' => 'SRC', 'offset' => 0],
        ['course' => 'UBI', 'offset' => 100],
        ['course' => 'ADD-UBI-TO-SRC', 'offset' => 200],
    ];

    public function run(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SRC-UBI')->whereNull('tenant_id')->first();

        if (! $combo) {
            return;
        }

        // Je Bogen statt je Kurs prüfen: auf bestehenden Installationen
        // kommt so die später ergänzte Gruppe UBI-Ergänzung (201-212) dazu,
        // ohne die vorhandenen Bögen anzufassen.
        $existing = $combo->examPapers()->pluck('paper_number')->all();

        foreach (self::SOURCES as $source) {
            $sourceCourse = CourseDefinition::withoutGlobalScopes()->where('code', $source['course'])->whereNull('tenant_id')->first();

            if (! $sourceCourse) {
                continue;
            }

            foreach ($sourceCourse->examPapers()->with('paperQuestions')->get() as $sourcePaper) {
                if (in_array($sourcePaper->paper_number + $source['offset'], $existing, true)) {
                    continue;
                }

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
