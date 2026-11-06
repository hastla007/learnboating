<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Löst die feste, vom Superadmin pro Kurs vorgegebene Anzahl zusätzlicher
 * Praxistermine wieder ab: die Bootsschule legt zusätzliche Termine
 * (slot >= 1) jetzt frei an, inklusive eigener Bezeichnung (z. B. "Kleine
 * Praxisstunde (1h)", "Navigationsstunde", "Onlinekurs") und optionalem
 * Zeitraum (Beginn/Ende, z. B. 16:00-18:00) statt nur eines Zeitpunkts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->string('label')->nullable()->after('slot');
            $table->timestampTz('ends_at')->nullable()->after('exam_date');
        });

        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->dropColumn('practice_appointment_count');
        });
    }

    public function down(): void
    {
        Schema::table('praxis_form_template', function (Blueprint $table) {
            $table->unsignedTinyInteger('practice_appointment_count')->default(0)->after('requires_tauglichkeit');
        });

        Schema::table('praxis_exam_appointment', function (Blueprint $table) {
            $table->dropColumn(['label', 'ends_at']);
        });
    }
};
