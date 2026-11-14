<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mitteilungen des Plattformbetreibers an die Bootsschulen (alle oder
 * ausgewählte): Anzeige im Admin-Bereich der Schulen, wahlweise auch im
 * Lern-Dashboard, optional per Mail an Inhaber und Admins. Plattformweit,
 * daher ohne tenant_id/RLS; die App-Rolle darf nur lesen, geschrieben wird
 * ausschließlich im Superadmin-Bereich über die Owner-Verbindung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcement', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('title', 150);
            $table->text('body');
            $table->string('audience', 10)->default('team');
            $table->jsonb('tenant_ids')->nullable();
            $table->date('visible_until')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->timestampTz('emailed_at')->nullable();
            $table->unsignedInteger('emailed_count')->default(0);
            $table->timestampsTz();
            $table->index('created_at');
            $table->index('created_by_user_id');
        });
        DB::statement("ALTER TABLE platform_announcement ADD CONSTRAINT platform_announcement_audience_check CHECK (audience IN ('team', 'all'))");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT ON platform_announcement TO '.AppRoleGrants::quotedRole());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcement');
    }
};
