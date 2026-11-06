<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon-Codes, die der Superadmin für einen Kurs erzeugt und optional
 * einer bestimmten Bootsschule zuordnet (z. B. beim Verkauf eines
 * Kontingents). tenant_id = NULL bedeutet "noch keiner Bootsschule
 * zugeordnet / frei einlösbar"; ist eine Bootsschule gesetzt, kann der Code
 * ausschließlich dort eingelöst werden (RLS-Policy analog zu
 * course_definition_isolation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 32)->unique();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition');
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenant')->nullOnDelete();
            $table->string('batch_label')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->uuid('redeemed_by_user_id')->nullable();
            $table->foreign('redeemed_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->uuid('redeemed_tenant_id')->nullable();
            $table->foreign('redeemed_tenant_id')->references('id')->on('tenant')->nullOnDelete();
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE coupon ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY coupon_isolation ON coupon
            USING (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id IS NULL OR tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON coupon TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon');
    }
};
