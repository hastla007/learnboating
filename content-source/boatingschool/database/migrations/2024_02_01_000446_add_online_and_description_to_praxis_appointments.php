<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergänzt den Terminvorschlag (siehe PraxisExamAppointment) um einen
 * "Online"-Ort für Onlinekurse (unabhängig von den echten, physischen
 * Orten aus TenantCourseLocation -- daher is_online als eigenes Flag statt
 * ein Fake-Datensatz in tenant_course_location) samt Meeting-Link sowie
 * eine freie, optionale Beschreibung, mit der die Bootsschule dem Lerner
 * zusätzliche Infos zum Termin mitgeben kann (z. B. "Bitte Segelzeug
 * mitbringen" oder "Treffpunkt am Steg 3").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->text('description')->nullable()->after('ends_at');
            $table->boolean('is_online')->default(false)->after('tenant_course_location_id');
            $table->string('online_meeting_url')->nullable()->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_online', 'online_meeting_url']);
        });
    }
};
