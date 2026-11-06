<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erinnerung vor dem Ablauf eines Kurszugangs: einmal je Ablaufdatum (bei
 * einer Verlängerung wird der Zeitstempel zurückgesetzt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlement', function (Blueprint $table) {
            $table->timestampTz('expiry_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entitlement', fn (Blueprint $table) => $table->dropColumn('expiry_reminded_at'));
    }
};
