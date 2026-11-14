<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Globale, mandantenunabhängige Website-Einstellungen des Superadmins.
 * Bewusst eine Singleton-Zeile (id = 1) statt einer generischen
 * Key-Value-Tabelle, weil die aktuell benötigten Felder fest umrissen sind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_setting', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('Bootsführerschein-Lernplattform');
            $table->string('support_email')->nullable();
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::table('platform_setting')->insert([
            'id' => 1,
            'site_name' => 'Bootsführerschein-Lernplattform',
            'updated_at' => now(),
        ]);

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON platform_setting TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_setting');
    }
};
