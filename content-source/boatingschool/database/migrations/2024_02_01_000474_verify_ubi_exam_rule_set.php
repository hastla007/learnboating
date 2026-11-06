<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UBI Vollkurs: amtliche Bestehensgrenze 17 von 22 Fragen, 60 Minuten
 * (BinSchSprFunkV), Prüfungssimulation mit den 12 Übungsfragebögen des
 * ABVT (Stand Oktober 2026, siehe UbiExamPaperSeeder). ExamRuleSetSeeder
 * legt das für neue Installationen so an, lässt ein bereits vorhandenes
 * Regelwerk aber bewusst unberührt (firstOrCreate) -- diese Migration
 * stellt das bisherige Platzhalter-Regelwerk (draft, 75%, 10 Fragen)
 * bestehender Installationen um. Auf einer frischen Datenbank existiert
 * es zur Migrationszeit noch nicht, dann ist sie ein No-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ruleSetIds = DB::table('exam_rule_set')
            ->where('version', 'draft-2026-09')
            ->whereIn('course_id', function ($query) {
                $query->select('id')->from('course_definition')->where('code', 'UBI')->whereNull('tenant_id');
            })
            ->pluck('id');

        if ($ruleSetIds->isEmpty()) {
            return;
        }

        $reviewerId = DB::table('app_user')->where('email', 'content-admin@plattform.example')->value('id');

        DB::table('exam_rule_set')->whereIn('id', $ruleSetIds)->update([
            'time_limit_seconds' => 3600,
            'passing_rule' => json_encode(['min_correct_ratio' => 17 / 22]),
            'status' => 'published',
            'verified_by' => $reviewerId,
            'verified_at' => now(),
        ]);

        DB::table('exam_blueprint')->whereIn('rule_set_id', $ruleSetIds)->update(['question_count' => 22]);
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: die vorherigen Werte waren reine
        // Platzhalter (siehe 000465), ein Zurücksetzen würde die amtliche
        // Bestehensgrenze wieder durch 75% ersetzen.
    }
};
