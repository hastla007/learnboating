<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kurs-Empfehlungsbanner: anders als tenant_addon (vorgefertigte,
 * Superadmin-gepflegte Vorlage) gehört dieser Banner komplett der
 * Bootsschule -- sie schreibt Überschrift und Beschreibungstext selbst,
 * um auf diesem Kurs für einen anderen/weiterführenden Kurs (z. B. einen
 * Ergänzungskurs) zu werben. Ein Eintrag pro (Bootsschule, Kurs), auf
 * dessen Seite der Banner erscheinen soll -- analog zu
 * course_webshop_link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_promo_banner', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->boolean('active')->default(false);
            $table->string('headline')->nullable();
            $table->text('description')->nullable();
            $table->string('cta_label')->default('Jetzt entdecken');
            $table->string('price_label')->nullable();
            $table->smallInteger('discount_percent')->nullable();
            $table->string('coupon_code')->nullable();
            $table->text('offer_url')->nullable();
            $table->timestampsTz();
            $table->primary(['tenant_id', 'course_id']);
        });

        DB::statement('ALTER TABLE course_promo_banner ADD CONSTRAINT course_promo_banner_discount_percent_check CHECK (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))');

        DB::statement('ALTER TABLE course_promo_banner ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY course_promo_banner_isolation ON course_promo_banner
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON course_promo_banner TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_promo_banner');
    }
};
