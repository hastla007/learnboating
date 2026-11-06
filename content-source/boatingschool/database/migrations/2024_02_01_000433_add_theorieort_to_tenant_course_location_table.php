<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optionaler dritter Ort pro TenantCourseLocation-Paar -- im Gegensatz zum
 * Prüfungsort (siehe 2024_02_01_000430) gibt es für den Theorieort keinen
 * Fallback auf den Praxisort: er bleibt leer, wenn die Bootsschule ihn nicht
 * pflegt. Gedacht für Navigationskurse, deren Theorieteil an einem anderen
 * Ort als der Praxisteil stattfindet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->string('theorie_ort')->nullable()->after('pruefung_lng');
            $table->string('theorie_strasse')->nullable()->after('theorie_ort');
            $table->string('theorie_plz', 20)->nullable()->after('theorie_strasse');
            $table->string('theorie_stadt')->nullable()->after('theorie_plz');
            $table->string('theorie_land')->nullable()->after('theorie_stadt');
            $table->decimal('theorie_lat', 10, 7)->nullable()->after('theorie_land');
            $table->decimal('theorie_lng', 10, 7)->nullable()->after('theorie_lat');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_course_location', function (Blueprint $table) {
            $table->dropColumn(['theorie_ort', 'theorie_strasse', 'theorie_plz', 'theorie_stadt', 'theorie_land', 'theorie_lat', 'theorie_lng']);
        });
    }
};
