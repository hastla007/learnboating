<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impressum und Datenschutzerklärung je Bootsschule: eine zentrale Vorlage
 * (Superadmin) mit Platzhaltern, in die die Daten der Schule eingesetzt
 * werden (siehe App\Support\LegalPages). Dazu die Angaben, die für ein
 * vollständiges Impressum im Branding noch fehlten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_setting', function (Blueprint $table) {
            // Leer: es gilt der mitgelieferte Entwurf (LegalPages::DEFAULT_*).
            $table->text('imprint_template')->nullable();
            $table->text('privacy_template')->nullable();
            // Angaben zum Betreiber der Plattform (Auftragsverarbeiter).
            $table->text('operator_info')->nullable();
        });

        Schema::table('tenant_branding', function (Blueprint $table) {
            $table->string('representative')->nullable();
            $table->string('register_entry')->nullable();
            $table->string('vat_id', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_setting', fn (Blueprint $table) => $table->dropColumn(['imprint_template', 'privacy_template', 'operator_info']));
        Schema::table('tenant_branding', fn (Blueprint $table) => $table->dropColumn(['representative', 'register_entry', 'vat_id']));
    }
};
