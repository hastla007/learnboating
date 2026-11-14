<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei-Faktor-Anmeldung (TOTP, z. B. Google Authenticator): Geheimnis und
 * Wiederherstellungscodes verschlüsselt (Laravel-Cast "encrypted"), aktiv
 * erst nach Bestätigung mit einem ersten Code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_user', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
