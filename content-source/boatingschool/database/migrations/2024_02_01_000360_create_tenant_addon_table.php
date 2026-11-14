<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pro-Bootsschule-Konfiguration einer Addon-Vorlage (siehe
 * addon_template): ob aktiviert, Gutscheincode des externen Webshops
 * (reines Anzeigefeld -- es gibt kein internes Couponsystem, alle
 * Produkte verkauft die Bootsschule selbst extern), Normalpreis als
 * Anzeigetext (kein Rabatt-Mechanismus), der Kurs + Prozent-Schwelle
 * des Kursfortschritts, ab dem der Banner auf der Seite dieses Kurses
 * erscheint, sowie der externe Link zur Angebotsseite der Bootsschule.
 * Eine Vorlage kann pro Bootsschule nur einmal konfiguriert sein
 * (zusammengesetzter Primärschlüssel), analog zu course_webshop_link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_addon', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('addon_template_id');
            $table->foreign('addon_template_id')->references('id')->on('addon_template')->cascadeOnDelete();
            $table->boolean('active')->default(false);
            $table->string('coupon_code')->nullable();
            $table->string('price_label')->nullable();
            $table->uuid('trigger_course_id')->nullable();
            $table->foreign('trigger_course_id')->references('id')->on('course_definition');
            $table->smallInteger('trigger_percent')->default(0);
            $table->text('offer_url')->nullable();
            $table->timestampsTz();
            $table->primary(['tenant_id', 'addon_template_id']);
        });
        DB::statement('ALTER TABLE tenant_addon ADD CONSTRAINT tenant_addon_trigger_percent_check CHECK (trigger_percent >= 0 AND trigger_percent <= 100)');

        DB::statement('ALTER TABLE tenant_addon ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_addon_isolation ON tenant_addon
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_addon TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_addon');
    }
};
