<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verknüpft Videolektionen mit Fragen aus der zentralen Fragenbasis ("Zu den
 * Fragen"-Quiz nach dem Video, siehe VideoQuizController). Wie module_content
 * globaler, mandantenunabhängiger Content -- keine RLS nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_lesson_question', function (Blueprint $table) {
            $table->uuid('video_lesson_id');
            $table->foreign('video_lesson_id')->references('id')->on('video_lesson')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->integer('sort_order')->default(1);
            $table->primary(['video_lesson_id', 'question_id']);
        });
        DB::statement('CREATE INDEX idx_video_lesson_question_question ON video_lesson_question(question_id)');

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON video_lesson_question TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('video_lesson_question');
    }
};
