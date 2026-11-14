<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Kursempfehlungs-Banner soll gleichzeitig auf mehreren Kursseiten
 * erscheinen können, nicht nur auf einer -- deshalb wird course_id (eine
 * feste Kursseite pro Zeile) durch course_ids (jsonb-Array, auf welchen
 * Kursseiten der Banner erscheint) ersetzt, jede dort gelistete Kursseite
 * gated durch ihren eigenen Kursfortschritt gegen trigger_percent. Damit
 * entfällt auch das separate trigger_course_id -- course_ids übernimmt
 * dessen Rolle gleich mit. Aus einer Zeile pro (Tenant, Kurs) wird eine
 * Zeile pro Banner mit eigenem UUID-Primärschlüssel, ein Tenant kann jetzt
 * mehrere unabhängige Kursempfehlungs-Banner anlegen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->uuid('id')->nullable()->default(DB::raw('gen_random_uuid()'));
            $table->jsonb('course_ids')->default('[]');
        });

        DB::statement('UPDATE course_promo_banner SET course_ids = jsonb_build_array(course_id) WHERE course_id IS NOT NULL');

        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropForeign(['trigger_course_id']);
            $table->dropPrimary('course_promo_banner_pkey');
            $table->dropColumn(['course_id', 'trigger_course_id']);
        });

        DB::statement('ALTER TABLE course_promo_banner ALTER COLUMN id SET NOT NULL');
        DB::statement('ALTER TABLE course_promo_banner ADD PRIMARY KEY (id)');
        DB::statement('CREATE INDEX course_promo_banner_tenant_id_index ON course_promo_banner (tenant_id)');
    }

    public function down(): void
    {
        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->uuid('course_id')->nullable();
            $table->uuid('trigger_course_id')->nullable();
        });

        DB::statement('UPDATE course_promo_banner SET course_id = (course_ids->>0)::uuid WHERE jsonb_array_length(course_ids) > 0');

        Schema::table('course_promo_banner', function (Blueprint $table) {
            $table->dropPrimary('course_promo_banner_pkey');
            $table->dropIndex('course_promo_banner_tenant_id_index');
            $table->dropColumn(['id', 'course_ids']);
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->foreign('trigger_course_id')->references('id')->on('course_definition');
            $table->primary(['tenant_id', 'course_id']);
        });
    }
};
