<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plattformweiter Ein/Aus-Schalter des Superadmins für einen Kurs: ist ein
 * Kurs sitewide deaktiviert, kann ihn keine Bootsschule mehr anbieten und
 * kein Lernender mehr sehen oder nutzen -- unabhängig von der Mandanten-
 * eigenen Auswahl (siehe tenant_course_disabled) oder vorhandenen
 * Entitlements (EntitlementService prüft beides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_definition', function (Blueprint $table) {
            $table->boolean('site_enabled')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('course_definition', function (Blueprint $table) {
            $table->dropColumn('site_enabled');
        });
    }
};
