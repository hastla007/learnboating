<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fortschritt im Funk-Training (SRC/UBI): Praxis-Prüfungssimulation,
 * Seefunktexte und Vokabeln der mündlichen Prüfung. Die Inhalte selbst sind
 * fester, globaler Lernstoff aus database/data (siehe App\Support\FunkTraining)
 * und werden über einen stabilen Schlüssel referenziert (z. B. "SRC-07",
 * "VOC-SRC-042") -- nur der Fortschritt ist mandantengebunden.
 *
 * - completed: als erledigt markiert (Praxisaufgabe, Seefunktext) bzw. als
 *   gelernt (Vokabel: 3x in Folge gewusst).
 * - streak: aktuelle Serie "gewusst" einer Vokabel.
 * - answers: zuletzt ausgewertete Eingaben einer Aufgabe bzw. Mitschrift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radio_training_progress', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->string('item_key', 40);
            $table->boolean('completed')->default(false);
            $table->unsignedSmallInteger('streak')->default(0);
            $table->jsonb('answers')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'user_id', 'item_type', 'item_key']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE radio_training_progress ADD CONSTRAINT radio_training_progress_item_type_check CHECK (item_type IN ('praxis', 'text', 'vocab'))");
        DB::statement('ALTER TABLE radio_training_progress ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY radio_training_progress_isolation ON radio_training_progress
            USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
            WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON radio_training_progress TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('radio_training_progress');
    }
};
