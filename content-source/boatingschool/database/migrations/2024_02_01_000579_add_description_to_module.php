<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Superadmin: interne Beschreibung je Modul (Inhalt, Quelle, Stand). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('module', 'description')) {
            Schema::table('module', fn (Blueprint $table) => $table->text('description')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('module', fn (Blueprint $table) => $table->dropColumn('description'));
    }
};
