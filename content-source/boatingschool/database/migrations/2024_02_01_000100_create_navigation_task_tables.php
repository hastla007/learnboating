<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Navigationsaufgaben-Trainer: die amtlichen Navigationsaufgaben (SBF See)
 * als Übungsmaterial mit sofort einsehbarer Musterlösung -- bewusst kein
 * Teil der strengen Prüfungssimulation (dort gilt "keine Sofortauflösung"),
 * sondern wie der Videokurs zentraler, globaler Content ohne Fortschritt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_task', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->integer('task_number');
            $table->text('scenario_text');
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('navigation_question', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('navigation_task_id');
            $table->foreign('navigation_task_id')->references('id')->on('navigation_task')->cascadeOnDelete();
            $table->integer('question_number');
            $table->text('question_text');
            $table->text('answer_text');
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON navigation_task, navigation_question TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_question');
        Schema::dropIfExists('navigation_task');
    }
};
