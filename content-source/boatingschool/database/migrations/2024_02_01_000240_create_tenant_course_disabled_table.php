<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mandanteneigene Kursauswahl: eine Zeile bedeutet, dass diese Bootsschule
 * diesen (global verfügbaren) Kurs bewusst NICHT anbietet -- fehlt eine
 * Zeile, gilt der Kurs für sie als angeboten (Opt-out statt Opt-in, damit
 * bestehende Bootsschulen/Kurse ohne Backfill unverändert sichtbar bleiben).
 * Ist ein Kurs zusätzlich sitewide deaktiviert (course_definition.site_enabled
 * = false), gewinnt das immer -- unabhängig vom Zustand dieser Tabelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_course_disabled', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_id', 'course_id']);
        });

        DB::statement('ALTER TABLE tenant_course_disabled ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_course_disabled_isolation ON tenant_course_disabled
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_course_disabled TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_course_disabled');
    }
};
