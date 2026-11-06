<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terminvorschlagsrunde für die Prüfung zu einer Praxis-Anmeldung (siehe
 * praxis_form_submission): die Bootsschule schlägt einen Termin vor
 * (round 1), der Lerner nimmt an oder lehnt ab -- bei Ablehnung schlägt
 * die Bootsschule eine neue Runde vor (round 2, 3, ...). Nimmt der Lerner
 * an, wird der Termin auf praxis_form_submission.confirmed_exam_date
 * fixiert (siehe PraxisExamAppointmentService). Tenant-gescoppt und
 * RLS-isoliert wie praxis_form_submission selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('praxis_exam_appointment', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('praxis_form_submission_id');
            $table->foreign('praxis_form_submission_id')->references('id')->on('praxis_form_submission')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->timestampTz('exam_date');
            $table->uuid('proposed_by_user_id');
            $table->foreign('proposed_by_user_id')->references('id')->on('app_user');
            $table->string('response', 20)->default('pending');
            $table->text('response_comment')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['praxis_form_submission_id', 'round']);
        });

        DB::statement('ALTER TABLE praxis_exam_appointment ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY praxis_exam_appointment_isolation ON praxis_exam_appointment
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON praxis_exam_appointment TO {$appRole}");
        }

        Schema::table('praxis_form_submission', function (Blueprint $table) {
            $table->timestampTz('confirmed_exam_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('praxis_form_submission', function (Blueprint $table) {
            $table->dropColumn('confirmed_exam_date');
        });

        Schema::dropIfExists('praxis_exam_appointment');
    }
};
