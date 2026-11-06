<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bestellung der Willkommensbox (siehe WillkommensboxController) -- einmal
 * pro Nutzer und Bootsschule, erreichbar sobald der Nutzer ein
 * ProductPurchase für "Lernplattform (inkl. Willkommensbox)" hat (siehe
 * CouponRedemptionService). Eigene Lieferadresse statt der Profil-Adresse,
 * da das Paket an eine andere Anschrift gehen kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('willkommensbox_order', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('street');
            $table->string('postal_code', 20);
            $table->string('city');
            $table->string('country', 60);
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
        });

        DB::statement('ALTER TABLE willkommensbox_order ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY willkommensbox_order_isolation ON willkommensbox_order
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON willkommensbox_order TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('willkommensbox_order');
    }
};
