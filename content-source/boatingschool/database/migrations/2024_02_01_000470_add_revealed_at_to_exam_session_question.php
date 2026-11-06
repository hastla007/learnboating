<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug-Review Oberfläche U9: bei offenen Prüfungsfragen entschied bisher
 * allein der vom Client mitgeschickte "known"-Wert (self_assessed_known)
 * darüber, ob die Frage als bestanden zählt -- ein per Devtools auf
 * known=1 manipulierter Request zählte jede offene Frage automatisch als
 * gewusst, unabhängig davon, ob die Musterantwort überhaupt abgerufen
 * wurde. revealed_at hält fest, wann die Musterantwort über den neuen,
 * separaten Reveal-Endpoint tatsächlich ausgeliefert wurde (siehe
 * ExamController::reveal()) -- answer() verlangt diesen Zeitstempel jetzt
 * als Voraussetzung für die Selbsteinschätzung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->timestamp('revealed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->dropColumn('revealed_at');
        });
    }
};
