<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE entitlement_status AS ENUM ('pending','active','expired','suspended','revoked')");

        Schema::create('entitlement', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition');
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_until')->nullable();
            $table->string('source_type', 40)->default('manual');
            $table->string('source_reference')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE entitlement ADD CONSTRAINT entitlement_valid_range_check CHECK (valid_until IS NULL OR valid_until > valid_from)');
        DB::statement("ALTER TABLE entitlement ADD COLUMN status entitlement_status NOT NULL DEFAULT 'active'");
        DB::statement('CREATE INDEX idx_entitlement_access ON entitlement(tenant_id, user_id, status, valid_from, valid_until)');

        Schema::create('attempt', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->uuid('revision_id');
            $table->foreign('revision_id')->references('id')->on('content_question_revision');
            $table->uuid('course_id')->nullable();
            $table->foreign('course_id')->references('id')->on('course_definition');
            $table->uuid('selected_answer_id')->nullable();
            $table->foreign('selected_answer_id')->references('id')->on('content_answer');
            $table->boolean('correct');
            $table->string('context', 40);
            $table->integer('response_time_ms')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE attempt ADD CONSTRAINT attempt_response_time_check CHECK (response_time_ms IS NULL OR response_time_ms >= 0)');
        DB::statement('CREATE INDEX idx_attempt_user_question_time ON attempt(tenant_id, user_id, question_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_attempt_course_time ON attempt(tenant_id, course_id, created_at DESC)');

        Schema::create('progress', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->integer('attempt_count')->default(0);
            $table->integer('correct_count')->default(0);
            $table->integer('incorrect_count')->default(0);
            $table->integer('current_streak')->default(0);
            $table->decimal('mastery_score', 5, 4)->default(0);
            $table->string('learning_state', 30)->default('neu');
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_correct_at')->nullable();
            $table->timestampTz('next_review_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'question_id']);
        });
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_attempt_count_check CHECK (attempt_count >= 0)');
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_correct_count_check CHECK (correct_count >= 0)');
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_incorrect_count_check CHECK (incorrect_count >= 0)');
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_current_streak_check CHECK (current_streak >= 0)');
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_mastery_score_check CHECK (mastery_score BETWEEN 0 AND 1)');
        DB::statement('ALTER TABLE progress ADD CONSTRAINT progress_counts_consistency_check CHECK (correct_count + incorrect_count <= attempt_count)');
        DB::statement('CREATE INDEX idx_progress_next_review ON progress(tenant_id, user_id, next_review_at)');
        DB::statement('CREATE INDEX idx_progress_mastery ON progress(tenant_id, user_id, mastery_score)');

        Schema::create('favorite', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite');
        Schema::dropIfExists('progress');
        Schema::dropIfExists('attempt');
        Schema::dropIfExists('entitlement');
        DB::statement('DROP TYPE IF EXISTS entitlement_status');
    }
};
