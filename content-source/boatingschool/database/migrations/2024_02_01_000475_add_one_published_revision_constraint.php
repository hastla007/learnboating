<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Doppelklick auf "Veröffentlichen" verursacht Fehler 500):
 * zusätzliche Absicherung neben dem Row-Lock in
 * Superadmin\QuestionController::publishRevision() -- die Datenbank lässt
 * ab jetzt für dieselbe Frage nie mehr als eine Revision im Status
 * "published" zu, unabhängig davon, über welchen Code-Pfad eine Revision
 * jemals veröffentlicht wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX content_question_revision_one_published_idx ON content_question_revision (question_id) WHERE (editorial_status = 'published')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS content_question_revision_one_published_idx');
    }
};
