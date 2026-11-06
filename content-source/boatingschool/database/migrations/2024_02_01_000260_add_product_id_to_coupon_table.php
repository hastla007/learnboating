<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Coupon kann jetzt statt eines Kurses auch ein Produkt (z. B. eine
 * Fahrstunde) freischalten -- genau eines von beidem, nie beides und nie
 * keines, erzwungen per CHECK-Constraint statt nur Anwendungslogik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('course_id');
            $table->foreign('product_id')->references('id')->on('product')->nullOnDelete();
        });

        DB::statement('ALTER TABLE coupon ALTER COLUMN course_id DROP NOT NULL');
        DB::statement('ALTER TABLE coupon ADD CONSTRAINT coupon_exactly_one_target_check
            CHECK ((course_id IS NOT NULL) <> (product_id IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE coupon DROP CONSTRAINT IF EXISTS coupon_exactly_one_target_check');
        DB::statement('DELETE FROM coupon WHERE course_id IS NULL');
        DB::statement('ALTER TABLE coupon ALTER COLUMN course_id SET NOT NULL');

        Schema::table('coupon', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};
