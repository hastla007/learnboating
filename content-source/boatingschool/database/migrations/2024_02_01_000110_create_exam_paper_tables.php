<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfungsbögen: feste, wiederholbare Fragensets (z. B. "Bogen 1" .. "Bogen
 * 15"), im Gegensatz zum bestehenden exam_rule_set/exam_blueprint, das pro
 * Versuch zufällig aus den Modulen auswählt. Wie video_module/navigation_task
 * zentraler, globaler Content ohne eigenen Fortschritt -- der tatsächliche
 * Prüfungsversuch bleibt weiterhin ein ganz normaler exam_session-Datensatz
 * (Zeitlimit/Bestehensgrenze/keine Sofortauflösung unverändert), nur mit
 * einem optionalen Verweis auf den gewählten Bogen statt zufälliger Auswahl.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_paper', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->integer('paper_number');
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['course_id', 'paper_number']);
        });

        Schema::create('exam_paper_question', function (Blueprint $table) {
            $table->uuid('exam_paper_id');
            $table->foreign('exam_paper_id')->references('id')->on('exam_paper')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->integer('position');
            $table->primary(['exam_paper_id', 'question_id']);
            $table->unique(['exam_paper_id', 'position']);
        });

        Schema::table('exam_session', function (Blueprint $table) {
            $table->uuid('paper_id')->nullable()->after('rule_set_id');
            $table->foreign('paper_id')->references('id')->on('exam_paper');
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON exam_paper, exam_paper_question TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::table('exam_session', function (Blueprint $table) {
            $table->dropForeign(['paper_id']);
            $table->dropColumn('paper_id');
        });
        Schema::dropIfExists('exam_paper_question');
        Schema::dropIfExists('exam_paper');
    }
};
