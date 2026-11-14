<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bootsschul-Admin: 2FA-Pflicht fürs Team (Schalter je Schule) und
 * Ausbilder auf bestimmte Kurse beschränken (ohne Zuordnung: alle Kurse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_branding', 'require_team_two_factor')) {
            Schema::table('tenant_branding', fn (Blueprint $table) => $table->boolean('require_team_two_factor')->default(false));
        }

        Schema::create('instructor_course', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->primary(['tenant_id', 'user_id', 'course_id']);
            $table->index('user_id');
            $table->index('course_id');
        });

        DB::statement('ALTER TABLE instructor_course ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY instructor_course_isolation ON instructor_course
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON instructor_course TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_course');
        Schema::table('tenant_branding', fn (Blueprint $table) => $table->dropColumn('require_team_two_factor'));
    }
};
