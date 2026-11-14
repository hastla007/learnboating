<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review N20: dasselbe Doppelklick-Problem wie B4 (siehe Migration
 * 000460), nur für die zufällig zusammengestellte Prüfung ohne Bogen
 * (ExamController::start(), paper_id ist dort immer NULL) -- dafür gab es
 * bisher weder einen Advisory-Lock noch diesen Unique-Index. Der
 * eigentliche Fix sperrt den Vorgang jetzt per Advisory-Lock in start();
 * dieser Unique-Index ist zusätzliche Verteidigung auf Datenbankebene.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bug-Review R4-5: CREATE UNIQUE INDEX scheitert hart, sobald bereits
        // zwei "running"-Sessions ohne Bogen für denselben Kurs/Nutzer
        // existieren -- genau das Doppelklick-Problem, gegen das dieser
        // Index erst absichert, kann auf Produktionsdaten schon vor dieser
        // Migration zugeschlagen haben. Von jeder Dopplung bleibt nur die
        // zuletzt gestartete Session "running" (die, mit der der Lerner
        // zuletzt tatsächlich interagiert hat); jede ältere wird auf
        // "submitted" gesetzt -- keine Zeile wird gelöscht, eine spätere
        // Interaktion wertet eine solche Session ohnehin ganz regulär aus
        // (siehe ExamController, Bug-Review B32/ExpireAbandonedExamSessions).
        DB::statement(<<<'SQL'
            WITH duplicates AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY tenant_id, user_id, course_id
                    ORDER BY started_at DESC, id DESC
                ) AS rn
                FROM exam_session
                WHERE status = 'running' AND paper_id IS NULL
            )
            UPDATE exam_session
            SET status = 'submitted'
            FROM duplicates
            WHERE exam_session.id = duplicates.id AND duplicates.rn > 1
        SQL);

        DB::statement("CREATE UNIQUE INDEX exam_session_running_random_unique ON exam_session (tenant_id, user_id, course_id)
            WHERE status = 'running' AND paper_id IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_session_running_random_unique');
    }
};
