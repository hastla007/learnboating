<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->string('smartmodus_kategorie')->nullable()->after('competency');
        });
    }

    public function down(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->dropColumn('smartmodus_kategorie');
        });
    }
};
