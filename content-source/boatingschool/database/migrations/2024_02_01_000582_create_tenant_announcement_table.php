<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mitteilungen der Bootsschule an ihre Lernenden (alle oder ein Kurs):
 * Anzeige im Lern-Dashboard bis zum Ablaufdatum, optional per Mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_announcement', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id')->nullable();
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->date('visible_until')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->timestampTz('emailed_at')->nullable();
            $table->unsignedInteger('emailed_count')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
            $table->index('course_id');
            $table->index('created_by_user_id');
        });

        DB::statement('ALTER TABLE tenant_announcement ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_announcement_isolation ON tenant_announcement
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_announcement TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_announcement');
    }
};
