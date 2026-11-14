<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Kurse X Tage für Teilnehmer verfügbar": wie lange ein neu vergebenes
 * Entitlement gültig bleibt (siehe CouponRedemptionService), bevor der
 * Teilnehmer einen Verlängerungs-Code benötigt. renewal_webshop_link ist der
 * optionale Kauf-Link für genau diese Codes (Kursauswahl-Seite, siehe
 * CourseSelectionController) -- ohne Link sieht der Teilnehmer stattdessen
 * nur den Hinweis, sich an die Bootsschule zu wenden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->unsignedSmallInteger('course_validity_days')->default(365)->after('willkommensbox_threshold_percent');
            $table->string('renewal_webshop_link', 2048)->nullable()->after('course_validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn(['course_validity_days', 'renewal_webshop_link']);
        });
    }
};
