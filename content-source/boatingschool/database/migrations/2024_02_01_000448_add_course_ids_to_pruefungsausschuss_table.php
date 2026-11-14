<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Kurse eine Prüfungskommission abdeckt (z. B. hat "Mannheim-
 * Darmstadt" zwei Adressen: eine Geschäftsstelle für SBF-Scheine und eine
 * separate Postanschrift nur für UBI/SRC/FKN) -- vom Superadmin gepflegt,
 * analog zu course_ids auf praxis_form_template/course_promo_banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pruefungsausschuss', function (Blueprint $table) {
            $table->jsonb('course_ids')->default('[]')->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('pruefungsausschuss', function (Blueprint $table) {
            $table->dropColumn('course_ids');
        });
    }
};
