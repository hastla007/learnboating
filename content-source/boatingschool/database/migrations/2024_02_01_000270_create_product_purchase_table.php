<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kaufnachweis für ein Produkt (z. B. eine Fahrstunde), analog zu
 * "entitlement" für Kurse -- aber ohne Gültigkeitszeitraum/Status, da ein
 * Produktkauf ein einmaliges Ereignis ist, kein laufender Zugang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('product');
            $table->string('source_type', 40)->default('manual');
            $table->string('source_reference')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE product_purchase ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY product_purchase_isolation ON product_purchase
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON product_purchase TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase');
    }
};
