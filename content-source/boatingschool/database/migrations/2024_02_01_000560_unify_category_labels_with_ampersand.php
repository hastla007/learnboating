<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Themen mal mit "und", mal mit "&"): die Smartmodus-
 * Kategorien der SBF-See-Fragen hießen "Vorschriften und Pflichten",
 * "Lichter, Zeichen und Töne" und "Funk und Kommunikation", alle übrigen
 * Themen schreiben "&" -- im Kombikurs SBF See & Binnen standen dadurch
 * z. B. "Vorschriften und Pflichten" und "Vorschriften & Pflichten" als
 * zwei Kacheln nebeneinander. Die Quelle (zentrale_fragenbasis.xlsx) nutzt
 * jetzt einheitlich "&"; diese Migration zieht die Bezeichnungen auf
 * bestehenden Installationen nach. Reine Metadaten in allen Revisionen,
 * damit content:import sie als unverändert erkennt.
 */
return new class extends Migration
{
    private const LABELS = [
        'Vorschriften und Pflichten' => 'Vorschriften & Pflichten',
        'Lichter, Zeichen und Töne' => 'Lichter, Zeichen & Töne',
        'Funk und Kommunikation' => 'Funk & Kommunikation',
    ];

    public function up(): void
    {
        foreach (self::LABELS as $old => $new) {
            foreach (['topic', 'smartmodus_kategorie'] as $column) {
                DB::table('content_question_revision')->where($column, $old)->update([$column => $new]);
            }
        }
    }

    public function down(): void {}
};
