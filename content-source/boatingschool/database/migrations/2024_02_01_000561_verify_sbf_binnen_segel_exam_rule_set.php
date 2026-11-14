<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SBF Binnen unter Segel: Bestehensgrenzen vom Betreiber festgelegt --
 * Basisfragen 5 von 7, Binnen 18 von 23 (wie SBF Binnen unter
 * Antriebsmaschine) und Segeln 5 von 7 (wie die Ergänzung Binnen ->
 * Segeln), jeder Teil für sich, 60 Minuten. Damit ist die
 * Prüfungssimulation mit den 15 amtlichen 37-Fragen-Bögen freigegeben
 * (siehe ExamRuleSetSeeder).
 *
 * Wie 000477 stellt die Migration den Platzhalter (draft, 75 %, 10 Fragen
 * je Modul) bestehender Installationen um; ein inzwischen von Hand
 * freigegebenes Regelwerk bleibt unberührt. Auf einer frischen Datenbank
 * existiert noch nichts, dann ist sie ein No-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $courseId = DB::table('course_definition')->where('code', 'SBF-BIN-SEGEL')->whereNull('tenant_id')->value('id');

        if (! $courseId) {
            return;
        }

        $ruleSetIds = DB::table('exam_rule_set')
            ->where('course_id', $courseId)
            ->where('version', 'draft-2026-09')
            ->where('status', 'draft')
            ->pluck('id');

        if ($ruleSetIds->isEmpty()) {
            return;
        }

        DB::table('exam_rule_set')->whereIn('id', $ruleSetIds)->update([
            'time_limit_seconds' => 3600,
            'passing_rule' => json_encode([
                'min_correct_ratio' => 28 / 37,
                'parts' => [
                    ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'min_correct' => 5, 'of' => 7],
                    ['module' => 'SBF_BINNEN', 'label' => 'Binnen', 'min_correct' => 18, 'of' => 23],
                    ['module' => 'SBF_BINNEN_SEGELN', 'label' => 'Segeln', 'min_correct' => 5, 'of' => 7],
                ],
            ]),
            'status' => 'published',
            'verified_by' => DB::table('app_user')->where('email', 'content-admin@plattform.example')->value('id'),
            'verified_at' => now(),
        ]);

        foreach (['SBF_BASIS' => 7, 'SBF_BINNEN' => 23, 'SBF_BINNEN_SEGELN' => 7] as $moduleCode => $count) {
            $moduleId = DB::table('module')->where('code', $moduleCode)->value('id');
            DB::table('exam_blueprint')->whereIn('rule_set_id', $ruleSetIds)->where('module_id', $moduleId)->update(['question_count' => $count]);
        }
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: der vorherige Wert war ein Platzhalter
        // (siehe 000477), gelaufene Prüfungen behalten ihr Ergebnis.
    }
};
