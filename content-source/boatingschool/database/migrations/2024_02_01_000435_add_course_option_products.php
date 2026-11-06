<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wählbare "Optionen" für Kurs-Coupons (siehe coupon.option_product_id,
 * nächste Migration): ein Kurs-Code kann optional eines dieser Produkte
 * mitbündeln, das bei Einlösung zusätzlich zum Kurszugang als
 * ProductPurchase angelegt wird (siehe CouponRedemptionService). Gleiches
 * Muster wie die bereits bestehenden "Fahrstunde"-Produkte (siehe
 * 2024_02_01_000250_create_product_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product')->insert([
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'LERNPLATTFORM', 'name' => 'Lernplattform', 'active' => true, 'created_at' => now()],
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'LERNPLATTFORM-WB', 'name' => 'Lernplattform (inkl. Willkommensbox)', 'active' => true, 'created_at' => now()],
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'PRAXISEINHEIT-KLEIN', 'name' => 'Kleine Praxiseinheit', 'active' => true, 'created_at' => now()],
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'PRAXISEINHEIT-GROSS', 'name' => 'Große Praxiseinheit', 'active' => true, 'created_at' => now()],
        ]);
    }

    public function down(): void
    {
        $productIds = DB::table('product')->whereIn('code', [
            'LERNPLATTFORM', 'LERNPLATTFORM-WB', 'PRAXISEINHEIT-KLEIN', 'PRAXISEINHEIT-GROSS',
        ])->pluck('id');

        // Bug-Review migrate:reset: product_purchase.product_id hat keinen
        // ON DELETE CASCADE/SET NULL -- ein Löschversuch bei bereits
        // vorhandenen Käufen dieser Optionen (echte Coupon-Einlösungen)
        // verletzte den FK-Constraint und ließ migrate:reset/rollback auf
        // einer befüllten Datenbank an dieser Migration scheitern.
        DB::table('product_purchase')->whereIn('product_id', $productIds)->delete();

        DB::table('product')->whereIn('id', $productIds)->delete();
    }
};
