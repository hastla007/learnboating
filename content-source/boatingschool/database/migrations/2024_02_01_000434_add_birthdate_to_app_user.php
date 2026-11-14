<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geburtsdatum ergänzt die bestehenden Adressfelder (siehe
 * 2024_02_01_000170_add_profile_fields_to_app_user) im Nutzerprofil --
 * wird u. a. für die Anmeldung zu "Praxis & Prüfung" benötigt und muss
 * im Unterschied zur Adresse immer vom Lerner selbst angegeben werden,
 * nie von der Bootsschule (siehe ParticipantController::invite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });
    }
};
