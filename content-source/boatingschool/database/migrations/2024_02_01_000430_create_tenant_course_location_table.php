<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Praxis-/Prüfungsort-Paar einer Bootsschule (siehe PraxisFormTemplate --
 * der Feldtyp "location" zieht seine Optionen von hier statt von manuell
 * gepflegten Werten). Eine Bootsschule kann mehrere solcher Paare pro Kurs
 * anlegen (z. B. zwei Standorte); der Kunde wählt im Formular immer den
 * Praxisort, der zugehörige Prüfungsort ist fix daran gekoppelt (kann mit
 * dem Praxisort identisch sein). course_ids (jsonb) erlaubt, ein Paar
 * mehreren Kursen gleichzeitig zuzuweisen, analog zu course_promo_banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_course_location', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->string('praxis_ort');
            $table->string('pruefung_ort');
            $table->jsonb('course_ids')->default('[]');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE tenant_course_location ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_course_location_isolation ON tenant_course_location
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_course_location TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_course_location');
    }
};
