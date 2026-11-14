<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Erlaubt einer Bootsschule, die per eigenem Webshop verkauft, pro Kurs
 * einen Kauf-Link zu hinterlegen. Ist für einen Kurs ein Link gesetzt,
 * ersetzt er auf der Kursübersicht den Standardhinweis "Kein Zugang --
 * bitte bei deiner Bootsschule anfragen" durch einen "Jetzt kaufen"-Button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_webshop_link', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->text('url');
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'course_id']);
        });

        DB::statement('ALTER TABLE course_webshop_link ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY course_webshop_link_isolation ON course_webshop_link
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON course_webshop_link TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_webshop_link');
    }
};
