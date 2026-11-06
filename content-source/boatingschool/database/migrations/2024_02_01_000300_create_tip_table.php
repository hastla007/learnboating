<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plattformweite "Tipps & Tricks"-Beiträge, redaktionell im Superadmin
 * gepflegt und für alle Bootsschulen sichtbar (kein tenant_id, analog zu
 * product/content_question). category ist bewusst ein freies Textfeld statt
 * einer eigenen Tabelle -- Kategorien entstehen/verschwinden einfach mit den
 * Beiträgen, die sie verwenden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tip', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('category');
            $table->string('title');
            $table->text('body');
            $table->uuid('pdf_asset_id')->nullable();
            $table->foreign('pdf_asset_id')->references('id')->on('media_asset')->nullOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tip TO {$appRole}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tip');
    }
};
