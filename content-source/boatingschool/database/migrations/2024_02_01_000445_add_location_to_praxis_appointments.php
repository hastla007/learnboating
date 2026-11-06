<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jede Terminvorschlagsrunde kann jetzt optional einem der für den Kurs
 * hinterlegten Orte (siehe TenantCourseLocation, inzwischen mit Praxis-,
 * Prüfungs- UND Theorieort) zugeordnet werden -- bei mehreren
 * Standorten (z. B. Hauptstandort vs. Trainingsgewässer) kann die
 * Bootsschule so je Termin wählen, wo er stattfindet, statt sich auf
 * einen festen Kursort verlassen zu müssen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->uuid('tenant_course_location_id')->nullable()->after('praxis_form_submission_id');
            $table->foreign('tenant_course_location_id')->references('id')->on('tenant_course_location')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->dropForeign(['tenant_course_location_id']);
            $table->dropColumn('tenant_course_location_id');
        });
    }
};
