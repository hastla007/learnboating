<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formular für die Anmeldung zu "Praxis & Prüfung", redaktionell im
 * Superadmin gepflegt (kein tenant_id, analog zu addon_template/tip) --
 * die Prüfungsbuchung ist amtlich/kursgebunden, nicht bootsschulspezifisch.
 * course_ids (jsonb) listet, auf welchen Kursseiten dieses Formular
 * erscheint; ein Kurs darf laut Fachkonzept nur in genau einem aktiven
 * Formular vorkommen (Exklusivität wird im Controller geprüft, nicht per
 * DB-Constraint, da jsonb-Array-Überschneidungen sich nicht sauber per
 * CHECK abbilden lassen). fields (jsonb) listet die abgefragten Felder:
 * [{key, label, type, required, options?, help_text?}, ...].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('praxis_form_template', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->smallInteger('min_progress_percent')->default(50);
            $table->jsonb('fields')->default('[]');
            $table->jsonb('course_ids')->default('[]');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE praxis_form_template ADD CONSTRAINT praxis_form_template_min_progress_percent_check CHECK (min_progress_percent >= 0 AND min_progress_percent <= 100)');

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON praxis_form_template TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('praxis_form_template');
    }
};
