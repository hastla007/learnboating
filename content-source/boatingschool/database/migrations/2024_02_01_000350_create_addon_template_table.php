<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plattformweite Upsell-Banner-Vorlagen ("Addons"), redaktionell im
 * Superadmin gepflegt (kein tenant_id, analog zu tip): Titel, Badge,
 * Statzeile, Headline und Haken-Bullets sind feste Werbetexte, die jede
 * Bootsschule bei Bedarf für sich aktiviert (siehe tenant_addon) --
 * Gutscheincode, Preis, Fortschritts-Trigger und Angebotslink sind
 * bewusst NICHT hier, da die stellen pro Bootsschule unterschiedlich
 * sind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_template', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title');
            $table->string('badge_text')->nullable();
            $table->string('stat_text')->nullable();
            $table->string('headline');
            $table->jsonb('bullets')->default('[]');
            $table->string('cta_label')->default('Jetzt sichern');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON addon_template TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_template');
    }
};
