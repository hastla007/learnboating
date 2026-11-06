<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Favoriten-Collections (siehe FavoriteCollection): pro Kurs kann ein
 * Lerner seine Favoriten in frei benannte Gruppen einteilen (z. B. "Vor
 * der Prüfung") statt nur eine einzige Liste zu haben -- jeder Kurs
 * bekommt dabei lazy eine nicht löschbare Default-Collection (siehe
 * FavoriteCollectionService::defaultFor). Eine Frage kann gleichzeitig in
 * mehreren Collections liegen (favorite_collection_item ist ein
 * Many-to-many-Pivot), getrennt nach Smart-Learning/Prüfungsfragen
 * (context), analog zu favorite selbst -- die Zuordnung zu einer
 * Collection setzt keine bestehende favorite-Zeile voraus (auch ein
 * bisher nur als "Fehler" erkannter Frage kann direkt einsortiert
 * werden), legt aber bei Bedarf automatisch eine an.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_collection', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('app_user')->cascadeOnDelete();
            $table->uuid('course_id');
            $table->foreign('course_id')->references('id')->on('course_definition');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'course_id', 'name']);
        });

        Schema::create('favorite_collection_item', function (Blueprint $table) {
            $table->uuid('favorite_collection_id');
            $table->foreign('favorite_collection_id')->references('id')->on('favorite_collection')->cascadeOnDelete();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->foreign('question_id')->references('id')->on('content_question');
            $table->string('context', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['favorite_collection_id', 'question_id', 'context']);
        });

        foreach (['favorite_collection', 'favorite_collection_item'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_isolation ON {$table}
                USING (tenant_id = current_setting('app.current_tenant_id', true)::uuid)
                WITH CHECK (tenant_id = current_setting('app.current_tenant_id', true)::uuid)");

            $appRole = AppRoleGrants::quotedRole();

            if (AppRoleGrants::roleExistsOrWarn()) {
                DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO {$appRole}");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_collection_item');
        Schema::dropIfExists('favorite_collection');
    }
};
