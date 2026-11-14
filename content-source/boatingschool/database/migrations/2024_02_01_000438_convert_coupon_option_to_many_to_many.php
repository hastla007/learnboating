<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Kurs-Coupon konnte bislang genau ein Produkt bündeln
 * (coupon.option_product_id, siehe 2024_02_01_000436). Jetzt sind mehrere
 * gleichzeitig möglich (z. B. "Lernplattform inkl. Willkommensbox" + "Kleine
 * Praxiseinheit" in einem Code) -- dafür eine n:n-Pivot-Tabelle statt der
 * einzelnen Spalte. Bestehende Werte werden vor dem Spaltenabbau übernommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_option_product', function (Blueprint $table) {
            $table->uuid('coupon_id');
            $table->foreign('coupon_id')->references('id')->on('coupon')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('product')->cascadeOnDelete();
            $table->primary(['coupon_id', 'product_id']);
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON coupon_option_product TO {$appRole}");
        }

        DB::statement('INSERT INTO coupon_option_product (coupon_id, product_id)
            SELECT id, option_product_id FROM coupon WHERE option_product_id IS NOT NULL');

        DB::statement('ALTER TABLE coupon DROP CONSTRAINT IF EXISTS coupon_option_only_with_course_check');

        Schema::table('coupon', function (Blueprint $table) {
            $table->dropForeign(['option_product_id']);
            $table->dropColumn('option_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->uuid('option_product_id')->nullable()->after('product_id');
            $table->foreign('option_product_id')->references('id')->on('product')->nullOnDelete();
        });

        DB::statement('ALTER TABLE coupon ADD CONSTRAINT coupon_option_only_with_course_check
            CHECK (option_product_id IS NULL OR course_id IS NOT NULL)');

        // Bei mehreren Optionen pro Coupon geht beim Rückbau auf die
        // Einzelspalte zwangsläufig Information verloren -- nur die erste
        // (nach product_id sortierte) Zuordnung je Coupon bleibt erhalten.
        DB::statement('UPDATE coupon SET option_product_id = sub.product_id
            FROM (SELECT DISTINCT ON (coupon_id) coupon_id, product_id FROM coupon_option_product ORDER BY coupon_id, product_id) sub
            WHERE coupon.id = sub.coupon_id');

        Schema::dropIfExists('coupon_option_product');
    }
};
