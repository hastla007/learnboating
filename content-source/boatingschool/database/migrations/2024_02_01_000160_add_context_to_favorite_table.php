<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Favoriten sind pro Bereich getrennt: dieselbe Frage kann unabhängig
 * voneinander im Smart-Learning UND in der Prüfungssimulation gespeichert
 * werden, daher wird "context" Teil des zusammengesetzten Primärschlüssels
 * statt eines einfachen Zusatzfeldes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favorite', function (Blueprint $table) {
            $table->string('context')->default('smart_learning')->after('question_id');
        });

        DB::statement('ALTER TABLE favorite DROP CONSTRAINT favorite_pkey');
        DB::statement('ALTER TABLE favorite ADD PRIMARY KEY (tenant_id, user_id, question_id, context)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE favorite DROP CONSTRAINT favorite_pkey');
        DB::statement('DELETE FROM favorite a USING favorite b
            WHERE a.tenant_id = b.tenant_id AND a.user_id = b.user_id AND a.question_id = b.question_id
            AND a.context > b.context');
        DB::statement('ALTER TABLE favorite ADD PRIMARY KEY (tenant_id, user_id, question_id)');

        Schema::table('favorite', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
