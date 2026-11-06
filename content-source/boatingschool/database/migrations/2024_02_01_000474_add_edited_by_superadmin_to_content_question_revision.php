<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug-Review (Seed überschreibt UBI-Korrekturen aus dem Editor): content:import
 * (ImportContentQuestions) verglich den Fragetext aus der Excel-Quelle bisher
 * gegen die REVISION MIT DER HÖCHSTEN revision_no einer Frage -- unabhängig
 * davon, ob diese vom Import selbst oder von einem Superadmin über den
 * Fragen-Editor angelegt wurde. Hat ein Superadmin eine Frage korrigiert
 * (z. B. eine fehlerhafte UBI-Frage), seitdem aber niemand die zentrale
 * Fragenbasis (zentrale_fragenbasis.xlsx) entsprechend aktualisiert, wich
 * der unveränderte Excel-Text zwangsläufig von der korrigierten Editor-
 * Fassung ab. Ein erneuter `content:import`-Lauf legte dafür eine neue
 * "review"-Revision mit dem alten, falschen Excel-Text an -- eine
 * Veröffentlichung dieser Revision (versehentlich oder durch einen
 * künftigen automatisierten Schritt) hätte die Korrektur des Superadmins
 * stillschweigend wieder verworfen. edited_by_superadmin unterscheidet
 * Editor-Revisionen von Import-Revisionen, damit der Import gezielt gegen
 * die zuletzt IMPORTIERTE Fassung diffen kann (siehe
 * ImportContentQuestions::importRow()) statt gegen eine neuere, aber
 * fachlich bereits korrigierte Editor-Revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->boolean('edited_by_superadmin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('content_question_revision', function (Blueprint $table) {
            $table->dropColumn('edited_by_superadmin');
        });
    }
};
