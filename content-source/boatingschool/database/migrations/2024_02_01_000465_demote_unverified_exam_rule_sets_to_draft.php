<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B5: ExamRuleSetSeeder markierte SBF-SEE, SBF-BIN-MOTOR,
 * SBF-SEE-BIN, ADD-SEE-TO-BIN, ADD-BIN-TO-SEE und SBF-BIN-SEGEL bislang
 * als "verified"/status=published -- das schaltete über
 * ExamRuleSet::isVerified() echte, zählende Prüfungsversuche
 * (ExamController::startPaper) für sie frei, obwohl ihre passing_rule
 * weiterhin nur den generischen 75%-Platzhalter nutzte, statt einer
 * tatsächlich amtlichen, aus der Prüfungsordnung belegten
 * Bestehensgrenze (anders als ADD-BIN-SEGEL/SRC/FKN, die eine
 * dokumentierte, kursspezifische Bestehensgrenze haben). Diese
 * Migration stuft bereits vorhandene Zeilen für die betroffenen Kurse
 * zurück auf draft; der Seeder selbst markiert sie ab jetzt auch bei
 * einem Neu-Seed nicht mehr als verifiziert (siehe $verifiedCourses).
 */
return new class extends Migration
{
    private const AFFECTED_COURSE_CODES = [
        'SBF-SEE', 'SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE', 'SBF-BIN-SEGEL',
    ];

    public function up(): void
    {
        DB::table('exam_rule_set')
            ->whereIn('course_id', function ($query) {
                $query->select('id')
                    ->from('course_definition')
                    ->whereIn('code', self::AFFECTED_COURSE_CODES)
                    ->whereNull('tenant_id');
            })
            ->where('status', 'published')
            ->update(['status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: welche Zeilen vor "up" schon draft
        // waren (und welche durch "up" umgeschrieben wurden) ist danach
        // nicht mehr unterscheidbar, ein pauschales Zurücksetzen auf
        // published würde ursprünglich schon draft-Zeilen fälschlich
        // wieder als verifiziert markieren.
    }
};
