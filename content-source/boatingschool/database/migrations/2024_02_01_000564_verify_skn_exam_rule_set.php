<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SKN: Prüfungsablauf laut Prüfungsausschuss Nord -- Fragebogen mit 18
 * Fragen aus dem Katalog, 60 Minuten, je Frage bis zu 2 Punkte, bestanden
 * ab 26 von 36 Punkten. Die Plattform wertet Fragen binär (wie beim FKN,
 * siehe ExamRuleSetSeeder), also 13 von 18. Feste Bögen gibt es für den
 * SKN nicht; freigegeben wird die Zufallsprüfung mit 18 Fragen.
 *
 * Die Ergänzung FKN -> SKN bleibt im Entwurf: wie weit der FKN angerechnet
 * wird, legt der Prüfungsausschuss fest.
 *
 * Wie 000561 stellt die Migration nur den Platzhalter (draft) bestehender
 * Installationen um; ein inzwischen von Hand freigegebenes Regelwerk bleibt
 * unberührt. Auf einer frischen Datenbank
 * existiert noch nichts, dann ist sie ein No-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $courseId = DB::table('course_definition')->where('code', 'SKN')->whereNull('tenant_id')->value('id');

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
            'passing_rule' => json_encode(['min_correct_ratio' => 26 / 36]),
            'status' => 'published',
            'verified_by' => DB::table('app_user')->where('email', 'content-admin@plattform.example')->value('id'),
            'verified_at' => now(),
        ]);

        foreach (['SKN' => 18] as $moduleCode => $count) {
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
