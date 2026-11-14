<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plattformweiter Produktkatalog für Einzelleistungen ohne Kursinhalt
 * (Module/Fragen/Prüfungen), z. B. Fahrstunden -- im Unterschied zu
 * course_definition, das für vollwertige Lernkurse gedacht ist. Produkte
 * werden wie Kurse über Coupons an Nutzer vergeben (siehe coupon.product_id
 * und product_purchase), teilen sich aber keine der Kurs-spezifischen
 * Tabellen (Module, Entitlement, Fortschritt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::table('product')->insert([
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'FAHRSTUNDE-1-EH', 'name' => 'Fahrstunde 1 EH', 'active' => true, 'created_at' => now()],
            ['id' => DB::raw('gen_random_uuid()'), 'code' => 'FAHRSTUNDE-2-EH', 'name' => 'Fahrstunde 2 EH', 'active' => true, 'created_at' => now()],
        ]);

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON product TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product');
    }
};
