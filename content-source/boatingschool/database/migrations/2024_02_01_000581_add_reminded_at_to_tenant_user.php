<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bootsschul-Admin: wann ein Teilnehmer zuletzt ans Lernen erinnert wurde (Sperrfrist gegen Mehrfachmails). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_user', 'reminded_at')) {
            Schema::table('tenant_user', fn (Blueprint $table) => $table->timestampTz('reminded_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropColumn('reminded_at'));
    }
};
