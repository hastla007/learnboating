<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nach der Fixierung des Prüfungstermins (slot 0) kann die Bootsschule je
 * nach Kurs weitere, unabhängige Terminrunden für zusätzliche
 * Praxiseinheiten vorschlagen (slot 1, 2, ... -- Anzahl pro Kurs über
 * praxis_form_template.practice_appointment_count gepflegt, z. B. 2 für
 * eine "Große Praxiseinheit"). Jeder Slot durchläuft dieselbe
 * Vorschlag-/Rückmelde-Logik wie der Prüfungstermin, nur unabhängig
 * voneinander und mit eigener Rundenzählung (siehe
 * PraxisExamAppointmentService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->unsignedTinyInteger('practice_appointment_count')->default(0)->after('requires_tauglichkeit');
        });

        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot')->default(0)->after('praxis_form_submission_id');
            $table->dropUnique(['praxis_form_submission_id', 'round']);
            $table->unique(['praxis_form_submission_id', 'slot', 'round']);
        });
    }

    public function down(): void
    {
        // Bug-Review N15: vor dieser Migration gab es nur slot 0 (den
        // Prüfungstermin). Weitere, unabhängige Praxiseinheiten-Runden
        // (slot 1, 2, ...) können unter der alten Eindeutigkeit
        // (praxis_form_submission_id, round) nicht erhalten bleiben --
        // ohne dieses Aufräumen scheiterte migrate:reset/rollback bei
        // bereits vorhandenen Mehrfach-Slot-Daten am neuen Unique-Index.
        DB::table('praxis_exam_appointment')->where('slot', '!=', 0)->delete();

        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->dropUnique(['praxis_form_submission_id', 'slot', 'round']);
            $table->unique(['praxis_form_submission_id', 'round']);
            $table->dropColumn('slot');
        });

        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->dropColumn('practice_appointment_count');
        });
    }
};
