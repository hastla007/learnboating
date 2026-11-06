<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SRC und UBI hatten keine smartmodus_kategorie -- das Smart-Learning
 * gruppierte dadurch nach topic, und das ist bei allen 180 SRC- bzw. 130
 * UBI-Fragen derselbe Wert ("SRC"/"UBI"): eine einzige Kachel statt der
 * Katalogkapitel. zentrale_fragenbasis.xlsx trägt jetzt das Kapitel
 * (subtopic) als smartmodus_kategorie ein; diese Migration setzt denselben
 * Wert auf den bereits vorhandenen Revisionen bestehender Installationen.
 * Reine Metadaten (keine neue Revision, kein Fortschritts-Reset) -- und
 * nötig, damit content:import die Zeilen danach als unverändert erkennt,
 * statt für jede Frage eine neue Review-Revision anzulegen.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_question_revision')
            ->whereIn('question_id', function ($query) {
                $query->select('id')->from('content_question')
                    ->where('content_id', 'like', 'SRC-%')
                    ->orWhere('content_id', 'like', 'UBI-%');
            })
            ->where(fn ($query) => $query->whereNull('smartmodus_kategorie')->orWhere('smartmodus_kategorie', ''))
            ->whereNotNull('subtopic')
            ->update(['smartmodus_kategorie' => DB::raw('subtopic')]);
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: die Kategorie entspricht dem Kapitel
        // aus der Fragenbasis.
    }
};
