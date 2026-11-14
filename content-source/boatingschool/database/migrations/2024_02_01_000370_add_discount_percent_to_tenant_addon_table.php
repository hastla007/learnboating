<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rabatt in % auf den in price_label hinterlegten Normalpreis -- der
 * rabattierte VK wird daraus nicht gespeichert, sondern bei der Anzeige
 * aus price_label + discount_percent berechnet (siehe
 * TenantAddon::discountedPriceLabel()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->smallInteger('discount_percent')->nullable()->after('price_label');
        });

        DB::statement('ALTER TABLE tenant_addon ADD CONSTRAINT tenant_addon_discount_percent_check CHECK (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))');
    }

    public function down(): void
    {
        Schema::table('tenant_addon', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
