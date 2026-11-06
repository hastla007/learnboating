<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Kurs-Coupon kann zusätzlich ein Produkt "bündeln" (z. B. "Lernplattform
 * (inkl. Willkommensbox)" oder eine Praxiseinheit) -- bei Einlösung entsteht
 * dann neben dem Kurs-Entitlement zusätzlich ein ProductPurchase dafür
 * (siehe CouponRedemptionService::redeemForCourse). Nur bei Kurs-Coupons
 * sinnvoll, daher per CHECK an course_id IS NOT NULL gekoppelt -- ein
 * Produkt-Coupon kann kein weiteres Produkt bündeln.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->uuid('option_product_id')->nullable()->after('product_id');
            $table->foreign('option_product_id')->references('id')->on('product')->nullOnDelete();
        });

        DB::statement('ALTER TABLE coupon ADD CONSTRAINT coupon_option_only_with_course_check
            CHECK (option_product_id IS NULL OR course_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE coupon DROP CONSTRAINT IF EXISTS coupon_option_only_with_course_check');

        Schema::table('coupon', function (Blueprint $table) {
            $table->dropForeign(['option_product_id']);
            $table->dropColumn('option_product_id');
        });
    }
};
