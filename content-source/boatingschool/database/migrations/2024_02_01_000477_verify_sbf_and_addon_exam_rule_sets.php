<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amtliche Bestehensgrenzen für SBF See, SBF Binnen (Motor), die
 * Ergänzungen See -> Binnen und Binnen -> See sowie die UBI-Ergänzung für
 * SRC-Inhaber (siehe ExamRuleSetSeeder). ExamRuleSetSeeder legt das für
 * neue Installationen so an, lässt vorhandene Regelwerke aber bewusst
 * unberührt (firstOrCreate) -- diese Migration stellt die bisherigen
 * Platzhalter (draft, 75%, 10 Fragen je Modul) bestehender Installationen
 * um, analog zu 000474 für UBI.
 *
 * Außerdem Kombikurs SBF See & Binnen: werden beide Bereiche am selben
 * Tag geprüft, kommen die Basisfragen nur einmal dran. Die Binnen-Bögen
 * des Kombikurses (101-115) spiegeln deshalb jetzt die 23 Binnen-Fragen
 * der Ergänzung See -> Binnen statt der vollen SBF-Binnen-Bögen (siehe
 * ComboExamPaperSeeder, ComboExamGroups). Die Bogen-IDs bleiben, bereits
 * gelaufene Sessions behalten ihre eigenen Fragen.
 *
 * Auf einer frischen Datenbank existiert zur Migrationszeit noch nichts,
 * dann ist sie ein No-op.
 */
return new class extends Migration
{
    private const NAVIGATION_NOTE = 'Zur amtlichen Prüfung gehört zusätzlich die Navigationsaufgabe (mindestens 7 von 9 Punkten); sie wird hier nicht automatisch bewertet.';

    public function up(): void
    {
        $rules = [
            'SBF-SEE' => [
                'time' => 3600,
                'rule' => [
                    'min_correct_ratio' => 23 / 30,
                    'parts' => [
                        ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                        ['module' => 'SBF_SEE', 'label' => 'See', 'min_correct' => 18, 'of' => 23],
                    ],
                    'note' => self::NAVIGATION_NOTE,
                ],
                'questions' => ['SBF_BASIS' => 7, 'SBF_SEE' => 23],
            ],
            'SBF-BIN-MOTOR' => [
                'time' => 2700,
                'rule' => [
                    'min_correct_ratio' => 23 / 30,
                    'parts' => [
                        ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                        ['module' => 'SBF_BINNEN', 'label' => 'Binnen', 'min_correct' => 18, 'of' => 23],
                    ],
                ],
                'questions' => ['SBF_BASIS' => 7, 'SBF_BINNEN' => 23],
            ],
            'ADD-SEE-TO-BIN' => ['time' => 2100, 'rule' => ['min_correct_ratio' => 18 / 23], 'questions' => ['SBF_BINNEN' => 23]],
            'ADD-BIN-TO-SEE' => ['time' => 3000, 'rule' => ['min_correct_ratio' => 18 / 23, 'note' => self::NAVIGATION_NOTE], 'questions' => ['SBF_SEE' => 23]],
            'ADD-UBI-TO-SRC' => ['time' => 1800, 'rule' => ['min_correct_ratio' => 8 / 10], 'questions' => ['UBI_ERG' => 10]],
        ];

        $reviewerId = DB::table('app_user')->where('email', 'content-admin@plattform.example')->value('id');

        foreach ($rules as $code => $values) {
            $courseId = $this->globalCourseId($code);
            $ruleSetIds = DB::table('exam_rule_set')->where('version', 'draft-2026-09')->where('course_id', $courseId)->pluck('id');

            if ($ruleSetIds->isEmpty()) {
                continue;
            }

            DB::table('exam_rule_set')->whereIn('id', $ruleSetIds)->update([
                'time_limit_seconds' => $values['time'],
                'passing_rule' => json_encode($values['rule']),
                'status' => 'published',
                'verified_by' => $reviewerId,
                'verified_at' => now(),
            ]);

            foreach ($values['questions'] as $moduleCode => $count) {
                $moduleId = DB::table('module')->where('code', $moduleCode)->value('id');
                DB::table('exam_blueprint')->whereIn('rule_set_id', $ruleSetIds)->where('module_id', $moduleId)->update(['question_count' => $count]);
            }
        }

        $this->mirrorAddSeeToBinPapersIntoCombo();
    }

    private function mirrorAddSeeToBinPapersIntoCombo(): void
    {
        $comboId = $this->globalCourseId('SBF-SEE-BIN');
        $sourceId = $this->globalCourseId('ADD-SEE-TO-BIN');

        if (! $comboId || ! $sourceId) {
            return;
        }

        $sourcePapers = DB::table('exam_paper')->where('course_id', $sourceId)->get(['id', 'paper_number']);

        foreach ($sourcePapers as $sourcePaper) {
            $comboPaperId = DB::table('exam_paper')->where('course_id', $comboId)->where('paper_number', $sourcePaper->paper_number + 100)->value('id');

            if (! $comboPaperId) {
                continue;
            }

            DB::table('exam_paper_question')->where('exam_paper_id', $comboPaperId)->delete();
            DB::table('exam_paper_question')->insert(
                DB::table('exam_paper_question')->where('exam_paper_id', $sourcePaper->id)->orderBy('position')->get(['question_id', 'position'])
                    ->map(fn ($row) => ['exam_paper_id' => $comboPaperId, 'question_id' => $row->question_id, 'position' => $row->position])
                    ->all()
            );
        }
    }

    private function globalCourseId(string $code): ?string
    {
        return DB::table('course_definition')->where('code', $code)->whereNull('tenant_id')->value('id');
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: die vorherigen Werte waren reine
        // Platzhalter (siehe 000465/000474).
    }
};
