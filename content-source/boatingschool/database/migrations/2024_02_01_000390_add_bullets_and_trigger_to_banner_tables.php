<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - tenant_addon.bullets: pro Bootsschule editierbare Haken-Bullets, die
 *   die Vorlagen-Bullets (addon_template.bullets) überschreiben -- null
 *   bedeutet "keine eigene Anpassung", dann gelten weiter die Vorlagen-
 *   Bullets (siehe TenantAddon::effectiveBullets()).
 * - course_promo_banner.bullets: optionale, frei editierbare Haken-Bullets
 *   (wie beim Addon-Banner), hier direkt auf dem Banner selbst, da er
 *   ohnehin komplett der Bootsschule gehört.
 * - course_promo_banner.trigger_course_id/trigger_percent: wie bei
 *   tenant_addon -- der Banner erscheint erst ab einem wählbaren
 *   Kursfortschritt eines (frei wählbaren) Kurses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->jsonb('bullets')->nullable()->after('discount_percent');
        });

        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->jsonb('bullets')->default('[]')->after('description');
            $table->uuid('trigger_course_id')->nullable()->after('offer_url');
            $table->foreign('trigger_course_id')->references('id')->on('course_definition');
            $table->smallInteger('trigger_percent')->default(0)->after('trigger_course_id');
        });

        DB::statement('ALTER TABLE course_promo_banner ADD CONSTRAINT course_promo_banner_trigger_percent_check CHECK (trigger_percent >= 0 AND trigger_percent <= 100)');
    }

    public function down(): void
    {
        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->dropColumn('bullets');
        });

        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->dropForeign(['trigger_course_id']);
            $table->dropColumn(['bullets', 'trigger_course_id', 'trigger_percent']);
        });
    }
};
