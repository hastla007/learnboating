<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adressfelder für beide Orte eines TenantCourseLocation-Paares, damit die
 * Bootsschule der Prüfungskommission eine vollständige Anschrift (nicht nur
 * einen Namen) für Praxis- und Prüfungsort mitgeben kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->string('praxis_strasse')->nullable()->after('praxis_ort');
            $table->string('praxis_plz')->nullable()->after('praxis_strasse');
            $table->string('praxis_stadt')->nullable()->after('praxis_plz');
            $table->string('praxis_land')->nullable()->after('praxis_stadt');

            $table->string('pruefung_strasse')->nullable()->after('pruefung_ort');
            $table->string('pruefung_plz')->nullable()->after('pruefung_strasse');
            $table->string('pruefung_stadt')->nullable()->after('pruefung_plz');
            $table->string('pruefung_land')->nullable()->after('pruefung_stadt');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->dropColumn([
                'praxis_strasse', 'praxis_plz', 'praxis_stadt', 'praxis_land',
                'pruefung_strasse', 'pruefung_plz', 'pruefung_stadt', 'pruefung_land',
            ]);
        });
    }
};
