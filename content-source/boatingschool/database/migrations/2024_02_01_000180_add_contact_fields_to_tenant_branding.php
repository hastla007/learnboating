<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stammdaten der Bootsschule: Ansprechpartner (Vorname/Nachname), Adresse,
 * Telefon und Website, plus ein Verifizierungs-Zeitstempel für die Support-
 * E-Mail (analog zur Nutzer-E-Mail-Verifizierung -- ändert sich die Adresse,
 * gilt sie bis zur erneuten Bestätigung als unverifiziert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->string('contact_first_name')->nullable()->after('legal_name');
            $table->string('contact_last_name')->nullable()->after('contact_first_name');
            $table->timestampTz('support_email_verified_at')->nullable()->after('support_email');
            $table->string('phone', 40)->nullable()->after('support_email_verified_at');
            $table->string('street')->nullable()->after('phone');
            $table->string('postal_code', 20)->nullable()->after('street');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country', 60)->nullable()->after('city');
            $table->string('website')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->dropColumn([
                'contact_first_name', 'contact_last_name', 'support_email_verified_at',
                'phone', 'street', 'postal_code', 'city', 'country', 'website',
            ]);
        });
    }
};
