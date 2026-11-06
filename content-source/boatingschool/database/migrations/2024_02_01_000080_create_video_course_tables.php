<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Videokurs als zweite, eigenständige Content-Art neben dem Fragenpool.
 * Kapitel/Lektionen sind wie Module/Fragen zentraler, globaler Content
 * (an course_definition gehängt); der Sichtungsfortschritt ist wie attempt/
 * progress mandantengebunden und RLS-geschützt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_module', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('title');
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('video_lesson', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('video_module_id');
            $table->foreign('video_module_id')->references('id')->on('video_module')->cascadeOnDelete();
            $table->string('title');
            $table->text('video_url');
            $table->integer('duration_seconds')->nullable();
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('video_progress', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('video_lesson_id');
            $table->foreign('video_lesson_id')->references('id')->on('video_lesson')->cascadeOnDelete();
            $table->boolean('completed')->default(false);
            $table->integer('last_position_seconds')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'video_lesson_id']);
        });

        DB::statement('ALTER TABLE video_progress ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY video_progress_isolation ON video_progress
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        // Die App-Rolle wurde in einer früheren Migration per
        // GRANT ... ON ALL TABLES angelegt; für danach neu erstellte Tabellen
        // muss das explizit nachgezogen werden.
        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON video_module, video_lesson, video_progress TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('video_progress');
        Schema::dropIfExists('video_lesson');
        Schema::dropIfExists('video_module');
    }
};
