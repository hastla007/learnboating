<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE exam_status AS ENUM ('created','running','submitted','evaluated')");

        Schema::create('exam_rule_set', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('version', 80);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->integer('time_limit_seconds')->nullable();
            $table->jsonb('passing_rule')->default('{}');
            $table->uuid('verified_by')->nullable();
            $table->foreign('verified_by')->references('id')->on('app_user');
            $table->timestampTz('verified_at')->nullable();
            $table->unique(['course_id', 'version']);
        });
        DB::statement('ALTER TABLE exam_rule_set ADD CONSTRAINT exam_rule_set_time_limit_check CHECK (time_limit_seconds IS NULL OR time_limit_seconds > 0)');
        DB::statement('ALTER TABLE exam_rule_set ADD CONSTRAINT exam_rule_set_valid_range_check CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)');
        DB::statement("ALTER TABLE exam_rule_set ADD COLUMN status content_status NOT NULL DEFAULT 'draft'");

        Schema::create('exam_blueprint', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('rule_set_id');
            $table->foreign('rule_set_id')->references('id')->on('exam_rule_set')->cascadeOnDelete();
            $table->uuid('module_id');
            $table->foreign('module_id')->references('id')->on('module');
            $table->integer('question_count');
            $table->jsonb('selection_rules')->default('{}');
            $table->unique(['rule_set_id', 'module_id']);
        });
        DB::statement('ALTER TABLE exam_blueprint ADD CONSTRAINT exam_blueprint_question_count_check CHECK (question_count > 0)');

        Schema::create('exam_session', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition');
            $table->uuid('rule_set_id');
            $table->foreign('rule_set_id')->references('id')->on('exam_rule_set');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->decimal('score', 8, 4)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE exam_session ADD COLUMN status exam_status NOT NULL DEFAULT 'created'");
        DB::statement('CREATE INDEX idx_exam_session_user ON exam_session(tenant_id, user_id, created_at DESC)');

        Schema::create('exam_session_question', function (Blueprint $table) {
            $table->uuid('exam_session_id');
            $table->foreign('exam_session_id')->references('id')->on('exam_session')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->uuid('revision_id');
            $table->foreign('revision_id')->references('id')->on('content_question_revision');
            $table->integer('position');
            $table->uuid('selected_answer_id')->nullable();
            $table->foreign('selected_answer_id')->references('id')->on('content_answer');
            $table->boolean('correct')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->primary(['exam_session_id', 'question_id']);
            $table->unique(['exam_session_id', 'position']);
        });
        DB::statement('ALTER TABLE exam_session_question ADD CONSTRAINT exam_session_question_position_check CHECK (position > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_session_question');
        Schema::dropIfExists('exam_session');
        Schema::dropIfExists('exam_blueprint');
        Schema::dropIfExists('exam_rule_set');
        DB::statement('DROP TYPE IF EXISTS exam_status');
    }
};
