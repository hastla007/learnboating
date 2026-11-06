<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jede Bootsschule kann selbst festlegen, ab welchem Kursfortschritt ein
 * Schüler die Kachel "Praxis & Prüfung" (Buchung von Prüfung/Praxis bei der
 * Schule) freigeschaltet bekommt. Voreinstellung laut Vorgabe: 50%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->unsignedTinyInteger('exam_readiness_threshold_percent')->default(50)->after('secondary_color');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn('exam_readiness_threshold_percent');
        });
    }
};
