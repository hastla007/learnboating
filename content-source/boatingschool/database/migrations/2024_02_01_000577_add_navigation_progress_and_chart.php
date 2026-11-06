<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Navigationsaufgaben: Lernstand je Teilfrage (eigene Antwort, richtig/
 * falsch) und ein optionaler Kartenausschnitt je Aufgabe, den der
 * Superadmin hochlädt (lizenziertes Material -- die amtliche
 * Übungsseekarte ist urheberrechtlich geschützt und nicht Teil der App).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_progress', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('navigation_question_id');
            $table->foreign('navigation_question_id')->references('id')->on('navigation_question')->cascadeOnDelete();
            $table->text('answer')->nullable();
            $table->boolean('correct')->nullable(); // null: Lösung angesehen, Selbstbewertung offen
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'navigation_question_id']);
            $table->index('user_id');
            $table->index('navigation_question_id');
        });

        DB::statement('ALTER TABLE navigation_progress ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY navigation_progress_isolation ON navigation_progress
            USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid)");

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON navigation_progress TO '.AppRoleGrants::quotedRole());
        }

        Schema::table('navigation_task', function (Blueprint $table) {
            $table->string('chart_image_path')->nullable();
            $table->string('chart_caption', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_progress');
        Schema::table('navigation_task', fn (Blueprint $table) => $table->dropColumn(['chart_image_path', 'chart_caption']));
    }
};
