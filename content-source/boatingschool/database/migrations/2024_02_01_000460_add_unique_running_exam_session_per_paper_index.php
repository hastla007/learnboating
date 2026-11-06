<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review B4: ExamController::startPaper() prüfte bisher per Lesen-dann-
 * Schreiben, ob für diesen Bogen bereits eine laufende Session existiert --
 * ein Doppelklick auf "Jetzt starten" konnte dadurch zwei "running"-Sessions
 * für denselben Bogen anlegen. Der eigentliche Fix sperrt den Vorgang jetzt
 * per Advisory-Lock in startPaper(); dieser Unique-Index ist zusätzliche
 * Verteidigung auf Datenbankebene (gleiches Muster wie H1 bei den
 * Coupon-Unique-Indizes).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bug-Review R4-G (Folgefehler R4-5): identisches Doppelklick-Problem
        // wie bei der zufällig zusammengestellten Prüfung (siehe Migration
        // 000466) -- CREATE UNIQUE INDEX scheitert hart, sobald bereits zwei
        // "running"-Sessions für denselben Bogen/Nutzer existieren, genau das
        // Problem, gegen das dieser Index erst absichert, kann auf
        // Produktionsdaten also schon vor dieser Migration zugeschlagen
        // haben. Von jeder Dopplung bleibt nur die zuletzt gestartete Session
        // "running" (die, mit der der Lerner zuletzt tatsächlich interagiert
        // hat); jede ältere wird auf "submitted" gesetzt -- keine Zeile wird
        // gelöscht, eine spätere Interaktion wertet eine solche Session
        // ohnehin ganz regulär aus (siehe ExamController,
        // ExpireAbandonedExamSessions).
        DB::statement(<<<'SQL'
            WITH duplicates AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY tenant_id, user_id, paper_id
                    ORDER BY started_at DESC, id DESC
                ) AS rn
                FROM exam_session
                WHERE status = 'running' AND paper_id IS NOT NULL
            )
            UPDATE exam_session
            SET status = 'submitted'
            FROM duplicates
            WHERE exam_session.id = duplicates.id AND duplicates.rn > 1
        SQL);

        DB::statement("CREATE UNIQUE INDEX exam_session_running_paper_unique ON exam_session (tenant_id, user_id, paper_id)
            WHERE status = 'running' AND paper_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS exam_session_running_paper_unique');
    }
};
