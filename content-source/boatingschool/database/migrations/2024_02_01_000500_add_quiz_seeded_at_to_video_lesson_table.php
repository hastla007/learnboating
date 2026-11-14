<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug-Review (Seeder-Idempotenz Videoquiz): die *LessonQuestionSeeder
 * hängten Quizfragen an jede Lektion, die (noch) keine Fragen hatte -- und
 * db:seed läuft auf bestehenden Installationen erneut. Ein Superadmin, der
 * ALLE Quizfragen einer Lektion entfernt hatte, bekam sie beim nächsten
 * Seed-Lauf zurück. quiz_seeded_at hält dauerhaft fest, dass eine Lektion
 * ihr Quiz schon einmal bekommen hat (siehe
 * Database\Seeders\Concerns\SeedsLessonQuizOnce); markierte Lektionen
 * fassen die Seeder nicht mehr an.
 *
 * Backfill: jede Lektion, die heute Quizfragen hat, wurde bereits geseedet
 * (oder vom Superadmin bestückt) und wird markiert. Lektionen ohne Fragen
 * bleiben unmarkiert -- ob sie nie geseedet oder bereits geleert wurden,
 * lässt sich nachträglich nicht unterscheiden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_lesson', function (Blueprint $table) {
            $table->timestampTz('quiz_seeded_at')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE video_lesson
            SET quiz_seeded_at = now()
            WHERE quiz_seeded_at IS NULL
              AND EXISTS (SELECT 1 FROM video_lesson_question q WHERE q.video_lesson_id = video_lesson.id)
        SQL);
    }

    public function down(): void
    {
        Schema::table('video_lesson', function (Blueprint $table) {
            $table->dropColumn('quiz_seeded_at');
        });
    }
};
