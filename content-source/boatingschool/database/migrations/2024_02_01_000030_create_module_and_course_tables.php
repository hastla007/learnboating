<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE course_status AS ENUM ('draft','published','archived')");

        Schema::create('module', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->string('version', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE module ADD COLUMN status content_status NOT NULL DEFAULT 'published'");

        Schema::create('module_content', function (Blueprint $table) {
            $table->uuid('module_id');
            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->integer('sort_order')->nullable();
            $table->boolean('required')->default(true);
            $table->primary(['module_id', 'question_id']);
        });
        DB::statement('CREATE INDEX idx_module_content_question ON module_content(question_id)');

        Schema::create('course_definition', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->string('course_type', 60)->default('full');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE course_definition ADD COLUMN status course_status NOT NULL DEFAULT 'draft'");

        Schema::create('course_module', function (Blueprint $table) {
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->uuid('module_id');
            $table->foreign('module_id')->references('id')->on('module');
            $table->integer('sort_order')->default(1);
            $table->boolean('required')->default(true);
            $table->jsonb('rules')->default('{}');
            $table->primary(['course_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module');
        Schema::dropIfExists('course_definition');
        Schema::dropIfExists('module_content');
        Schema::dropIfExists('module');
        DB::statement('DROP TYPE IF EXISTS course_status');
    }
};
