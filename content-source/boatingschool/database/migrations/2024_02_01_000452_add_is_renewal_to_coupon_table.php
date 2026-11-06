<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlängerungscodes sind technisch ganz normale Kurs-Coupons (course_id
 * gesetzt, product_id leer) -- is_renewal unterscheidet nur, ob das
 * Einlösen ein neues Entitlement anlegt (false, bisheriges Verhalten) oder
 * ein bestehendes um course_validity_days verlängert (true, siehe
 * CouponRedemptionService::redeemForRenewal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->boolean('is_renewal')->default(false)->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupon', function (Blueprint $table) {
            $table->dropColumn('is_renewal');
        });
    }
};
