<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfungsanmeldung je Bootsschule und Kurs (siehe App\Support\
 * ExamRegistration): "school" = die Bootsschule meldet den Teilnehmer an
 * (Termintool "Praxis & Prüfung"), "self" = der Teilnehmer meldet sich
 * selbst beim Prüfungsausschuss an und sieht dafür die Seite "Prüfung
 * buchen". Ohne Zeile gilt "school" -- das bisherige Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_exam_registration', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'course_id']);
            $table->index('course_id');
        });

        DB::statement("ALTER TABLE course_exam_registration ADD CONSTRAINT course_exam_registration_mode_check CHECK (mode IN ('school', 'self'))");
        DB::statement('ALTER TABLE course_exam_registration ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY course_exam_registration_isolation ON course_exam_registration
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON course_exam_registration TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_exam_registration');
    }
};
