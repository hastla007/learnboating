<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schritt-für-Schritt-Galerie unter einem Video (z. B. einzelne Handgriffe
 * beim Knotenbinden). Wie video_module/video_lesson zentraler, globaler
 * Content ohne eigenen Fortschritt -- der Sichtungsfortschritt bleibt an
 * der übergeordneten video_lesson erfasst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_lesson_step', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('video_lesson_id');
            $table->foreign('video_lesson_id')->references('id')->on('video_lesson')->cascadeOnDelete();
            $table->string('title');
            $table->text('image_path')->nullable();
            $table->integer('sort_order')->default(1);
            $table->timestampTz('created_at')->useCurrent();
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON video_lesson_step TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('video_lesson_step');
    }
};
