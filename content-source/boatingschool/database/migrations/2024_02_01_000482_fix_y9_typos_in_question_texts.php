<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Y9: Tippfehler): drei Tippfehler in bereits gesäten Fragen.
 * FknSknContentSeeder und der zentrale Fragen-Import legen Revisionen nur
 * EINMAL an (create-once), die Korrektur der Katalogquelle
 * (skn_catalog.php) allein erreicht eine bereits gesäte Installation daher
 * nicht erneut -- diese Migration zieht alle drei Korrekturen nach, strikt
 * nur dort, wo der Text noch exakt dem bekannten, fehlerhaften Original
 * entspricht.
 *
 * 1. SKN-0053: fehlendes Fragezeichen ("...richtig." statt "...richtig?")
 *    -- die wortgleiche FKN-0037-Frage hat bereits korrekt ein Fragezeichen.
 * 2. SKN-0059: "wenn Sie ins Wasser fallen?" -- das großgeschriebene "Sie"
 *    liest sich wie die förmliche Anrede des Lerners, bezieht sich aber
 *    auf "pyrotechnische Geschosse" (sie = sie, Plural) und muss daher
 *    klein geschrieben werden, wie bereits korrekt in der wortgleichen
 *    FKN-0039-Frage.
 * 3. SBF-SEGEL-0274: "wärend" statt "während".
 */
return new class extends Migration
{
    private const FIXES = [
        [
            'content_id' => 'SKN-0053',
            'old' => 'Fallschirmsignalraketen und Handfackeln sind bei klarem Wetter unterschiedlich weit zu sehen. Welche Aussage/n ist/sind richtig.',
            'new' => 'Fallschirmsignalraketen und Handfackeln sind bei klarem Wetter unterschiedlich weit zu sehen. Welche Aussage/n ist/sind richtig?',
        ],
        [
            'content_id' => 'SKN-0059',
            'old' => 'Brennen pyrotechnische Geschosse weiter, wenn Sie ins Wasser fallen?',
            'new' => 'Brennen pyrotechnische Geschosse weiter, wenn sie ins Wasser fallen?',
        ],
        [
            'content_id' => 'SBF-SEGEL-0274',
            'old' => 'Woran kann wärend der Fahrt die Richtung des wahren Windes erkannt werden?',
            'new' => 'Woran kann während der Fahrt die Richtung des wahren Windes erkannt werden?',
        ],
    ];

    public function up(): void
    {
        foreach (self::FIXES as $fix) {
            DB::table('content_question_revision')
                ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
                ->where('content_question.content_id', $fix['content_id'])
                ->where('content_question_revision.question_text', $fix['old'])
                ->update(['content_question_revision.question_text' => $fix['new']]);
        }
    }

    public function down(): void
    {
        foreach (self::FIXES as $fix) {
            DB::table('content_question_revision')
                ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
                ->where('content_question.content_id', $fix['content_id'])
                ->where('content_question_revision.question_text', $fix['new'])
                ->update(['content_question_revision.question_text' => $fix['old']]);
        }
    }
};
