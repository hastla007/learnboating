<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vom Lerner ausgefülltes Praxisformular (siehe praxis_form_template) --
 * tenant-gescoppt und RLS-isoliert, da die Daten der jeweiligen
 * Bootsschule gehören (die sie an die Prüfungskommission weiterreicht).
 * data (jsonb) speichert die Antworten, keyed nach dem field.key der
 * Formular-Vorlage zum Zeitpunkt der Einreichung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('praxis_form_submission', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('praxis_form_template_id');
            $table->foreign('praxis_form_template_id')->references('id')->on('praxis_form_template');
            $table->jsonb('data')->default('{}');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'course_id']);
        });

        DB::statement('ALTER TABLE praxis_form_submission ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY praxis_form_submission_isolation ON praxis_form_submission
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON praxis_form_submission TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('praxis_form_submission');
    }
};
