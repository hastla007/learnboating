<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koordinaten für beide Orte eines TenantCourseLocation-Paares, damit das
 * Praxisformular dem Lerner links vom Formular eine Karte mit den
 * verfügbaren Prüfungsorten anzeigen kann (siehe praxis-pruefung/index.blade.php).
 * Optional/manuell gepflegt (z. B. per Rechtsklick auf Google Maps kopiert) --
 * es gibt keine serverseitige Geokodierung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->decimal('praxis_lat', 10, 7)->nullable()->after('praxis_land');
            $table->decimal('praxis_lng', 10, 7)->nullable()->after('praxis_lat');

            $table->decimal('pruefung_lat', 10, 7)->nullable()->after('pruefung_land');
            $table->decimal('pruefung_lng', 10, 7)->nullable()->after('pruefung_lat');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->dropColumn(['praxis_lat', 'praxis_lng', 'pruefung_lat', 'pruefung_lng']);
        });
    }
};
