<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorname/Nachname ergänzen den bestehenden "display_name" (der weiterhin
 * als Anzeigename genutzt wird, siehe User::setNameAttribute), zusätzlich
 * Adress- und Telefonfelder fürs Nutzerprofil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('display_name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('street')->nullable()->after('phone');
            $table->string('postal_code', 20)->nullable()->after('street');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country', 60)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone', 'street', 'postal_code', 'city', 'country']);
        });
    }
};
