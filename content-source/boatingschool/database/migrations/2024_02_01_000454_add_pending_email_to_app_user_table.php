<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sicherheitsreview H2: eine im Profil geänderte E-Mail-Adresse wird erst
 * live geschaltet (app_user.email), wenn sie über den zugeschickten Link
 * bestätigt wurde (siehe ProfileController::update/confirmEmail) -- bis
 * dahin steht sie nur hier. Sonst könnte sich jemand eine fremde Adresse
 * eintragen und sie sofort für sein Konto nutzen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->string('pending_email', 320)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn('pending_email');
        });
    }
};
