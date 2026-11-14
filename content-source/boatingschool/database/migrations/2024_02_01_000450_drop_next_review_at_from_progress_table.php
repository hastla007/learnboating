<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_progress_next_review');

        Schema::table('progress', function (Blueprint $table) {
            $table->dropColumn('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::table('progress', function (Blueprint $table) {
            $table->timestampTz('next_review_at')->nullable();
        });

        DB::statement('CREATE INDEX idx_progress_next_review ON progress(tenant_id, user_id, next_review_at)');
    }
};
