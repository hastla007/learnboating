<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trägt den neuen Fragetypen "multi_choice" (mehrere Antworten korrekt,
 * z.B. FKN/SKN-Kataloge) und "open_text" (offene Frage ohne Antwortoptionen,
 * nur Musterantwort, selbst eingeschätzt als Lernkarte) Rechnung.
 * selected_answer_ids hält die Auswahl bei multi_choice (statt des
 * einzelnen selected_answer_id); self_assessed_known hält die
 * Selbsteinschätzung bei open_text -- nur auf exam_session_question, da
 * dort die Bewertung laut Prüfungsengine bewusst erst bei finish()
 * erfolgt (keine Sofortauflösung), während attempt (Smart-Learning/
 * Video-Quiz) sofort bewertet wird und correct bereits beim Schreiben
 * feststeht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->jsonb('selected_answer_ids')->nullable();
            $table->boolean('self_assessed_known')->nullable();
        });

        Schema::table('attempt', function (Blueprint $table) {
            $table->jsonb('selected_answer_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_question', function (Blueprint $table) {
            $table->dropColumn(['selected_answer_ids', 'self_assessed_known']);
        });

        Schema::table('attempt', function (Blueprint $table) {
            $table->dropColumn('selected_answer_ids');
        });
    }
};
