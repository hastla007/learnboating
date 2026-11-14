<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_question', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('content_id', 80)->unique();
            $table->string('official_number', 40)->nullable();
            $table->string('content_role', 60)->nullable();
            $table->string('language', 16)->default('de-DE');
            $table->boolean('active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('content_question_revision', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question')->cascadeOnDelete();
            $table->integer('revision_no');
            $table->string('question_type', 40)->default('single_choice');
            $table->text('question_text');
            $table->string('topic')->nullable();
            $table->string('subtopic')->nullable();
            $table->string('competency')->nullable();
            $table->boolean('image_required')->default(false);
            $table->string('source_catalog', 120)->nullable();
            $table->string('source_version', 80)->nullable();
            $table->string('source_page', 40)->nullable();
            $table->string('source_question_id')->nullable();
            $table->string('rights_owner')->nullable();
            $table->text('license_reference')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('app_user');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['question_id', 'revision_no']);
        });
        DB::statement('ALTER TABLE content_question_revision ADD CONSTRAINT content_question_revision_revision_no_check CHECK (revision_no > 0)');
        DB::statement('ALTER TABLE content_question_revision ADD CONSTRAINT content_question_revision_valid_range_check CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');
        DB::statement("ALTER TABLE content_question_revision ADD COLUMN editorial_status content_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE content_question_revision ADD COLUMN rights_status rights_status NOT NULL DEFAULT 'unknown'");
        DB::statement('CREATE INDEX idx_question_revision_publish ON content_question_revision(question_id, editorial_status, valid_from, valid_to)');

        Schema::create('content_answer', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('revision_id');
            $table->foreign('revision_id')->references('id')->on('content_question_revision')->cascadeOnDelete();
            $table->string('answer_key', 8);
            $table->text('answer_text');
            $table->boolean('is_correct')->default(false);
            $table->smallInteger('sort_order');
            $table->unique(['revision_id', 'answer_key']);
            $table->unique(['revision_id', 'sort_order']);
        });

        Schema::create('question_media', function (Blueprint $table) {
            $table->uuid('revision_id');
            $table->foreign('revision_id')->references('id')->on('content_question_revision')->cascadeOnDelete();
            $table->uuid('media_asset_id');
            $table->foreign('media_asset_id')->references('id')->on('media_asset');
            $table->string('role', 40)->default('question');
            $table->smallInteger('sort_order')->default(1);
            $table->primary(['revision_id', 'media_asset_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_media');
        Schema::dropIfExists('content_answer');
        Schema::dropIfExists('content_question_revision');
        Schema::dropIfExists('content_question');
    }
};
