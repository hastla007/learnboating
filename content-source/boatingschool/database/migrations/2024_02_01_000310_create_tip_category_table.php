<?php

use App\Support\AppRoleGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Löst tip.category (freies Textfeld) durch eine eigene Tabelle ab, damit
 * Kategorien im Superadmin unabhängig von einzelnen Beiträgen angelegt,
 * umbenannt und gelöscht werden können. Bestehende category-Werte werden
 * dabei in tip_category-Zeilen überführt, bevor die alte Spalte entfällt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tip_category', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name')->unique();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        $appRole = AppRoleGrants::quotedRole();

        if (AppRoleGrants::roleExistsOrWarn()) {
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON tip_category TO {$appRole}");
        }

        Schema::table('tip', function (Blueprint $table) {
            $table->uuid('category_id')->nullable()->after('id');
            $table->foreign('category_id')->references('id')->on('tip_category')->restrictOnDelete();
        });

        foreach (DB::table('tip')->select('category')->distinct()->pluck('category') as $index => $categoryName) {
            $categoryId = (string) DB::selectOne('SELECT gen_random_uuid() AS id')->id;
            DB::table('tip_category')->insert([
                'id' => $categoryId,
                'name' => $categoryName,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('tip')->where('category', $categoryName)->update(['category_id' => $categoryId]);
        }

        Schema::table('tip', function (Blueprint $table) {
            $table->uuid('category_id')->nullable(false)->change();
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('tip', function (Blueprint $table) {
            $table->string('category')->nullable();
        });

        DB::table('tip')->update([
            'category' => DB::raw('(SELECT name FROM tip_category WHERE tip_category.id = tip.category_id)'),
        ]);

        Schema::table('tip', function (Blueprint $table) {
            $table->string('category')->nullable(false)->change();
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });

        Schema::dropIfExists('tip_category');
    }
};
