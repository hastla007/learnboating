<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analog zu exam_readiness_threshold_percent (siehe
 * 2024_02_01_000150): jede Bootsschule kann selbst festlegen, ab welchem
 * Kursfortschritt ein Schüler die Willkommensbox bestellen darf. 0 (Default)
 * bedeutet keine zusätzliche Voraussetzung -- nur der Produktkauf
 * "Lernplattform (inkl. Willkommensbox)" zählt dann wie bisher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->unsignedTinyInteger('willkommensbox_threshold_percent')->default(0)->after('exam_readiness_threshold_percent');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn('willkommensbox_threshold_percent');
        });
    }
};
