<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manche amtlichen Navigationsaufgaben tragen eine nachträgliche Korrektur
 * (z. B. Verkehrsblatt-Berichtigung), die für alle Teilaufgaben der Aufgabe
 * gleichermaßen gilt -- daher ein Hinweisfeld auf Aufgaben-, nicht auf
 * Teilaufgaben-Ebene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_task', function (Blueprint $table) {
            $table->text('hint')->nullable()->after('scenario_text');
        });
    }

    public function down(): void
    {
        Schema::table('navigation_task', function (Blueprint $table) {
            $table->dropColumn('hint');
        });
    }
};
