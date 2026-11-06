<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Praxistrainer für die praktische SBF-See-Prüfung: Handlungsaufgaben
 * (Manöver, Knoten, Zeichen/Lichter/Feuer erkennen) mit sofort einsehbarer
 * Musterlösung und optionalem Bild -- wie der Videokurs zentraler, globaler
 * Content (an course_definition gehängt), aber -- anders als die
 * Navigationsaufgaben -- mit mandantengebundenem Sichtungsfortschritt
 * (praxis_progress), da der Smartmodus dafür eine Fortschrittsanzeige je
 * Kategorie benötigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('praxis_task', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition')->cascadeOnDelete();
            $table->string('content_id', 40)->unique();
            $table->string('kategorie');
            $table->string('unterkategorie')->nullable();
            $table->string('pruefungsbezug')->nullable();
            $table->string('aufgabentyp')->nullable();
            $table->text('frage');
            $table->text('antwort');
            $table->text('erklaerung')->nullable();
            $table->uuid('media_asset_id')->nullable();
            $table->foreign('media_asset_id')->references('id')->on('media_asset');
            $table->string('quelle')->nullable();
            $table->text('quellen_url')->nullable();
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('praxis_progress', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('praxis_task_id');
            $table->foreign('praxis_task_id')->references('id')->on('praxis_task')->cascadeOnDelete();
            $table->boolean('completed')->default(false);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'praxis_task_id']);
        });

        DB::statement('ALTER TABLE praxis_progress ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY praxis_progress_isolation ON praxis_progress
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON praxis_task, praxis_progress TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('praxis_progress');
        Schema::dropIfExists('praxis_task');
    }
};
