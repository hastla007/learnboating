<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Praxistrainer-Editor im Superadmin: Karten lassen sich deaktivieren statt
 * löschen (Lernstand bleibt erhalten, die Seeder erkennen die Karte weiter
 * und legen sie nicht neu an).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('praxis_task', 'active')) {
            Schema::table('praxis_task', fn (Blueprint $table) => $table->boolean('active')->default(true));
        }
    }

    public function down(): void
    {
        Schema::table('praxis_task', fn (Blueprint $table) => $table->dropColumn('active'));
    }
};
