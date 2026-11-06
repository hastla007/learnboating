<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (AA18: SKN-Fragen 49/50 nennen veraltete Klassen): skn_catalog.php
 * verwendete für die Zulassungsklassen pyrotechnischer Notsignale noch die
 * Bezeichnungen "BAM-PT1/PT2" und "Klasse T" aus der 1. SprengV vor der
 * Reform 2010 (Umsetzung der EU-Pyrotechnik-Richtlinie 2007/23/EG) --
 * seitdem gelten die Klassen "P1/P2". Das inhaltlich identische
 * FKN-Gegenstück (FKN-0033/0034) verwendet dafür bereits korrekt "BAM-P 1
 * oder BAM-P 2" bzw. "Klasse P". FknSknContentSeeder legt pro Frage nur
 * einmal eine Revision an (firstOrCreate + exists()-Guard), eine Korrektur
 * der Katalogquelle allein erreicht eine bereits gesäte Installation daher
 * nicht erneut. Diese Migration zieht die Korrektur für bereits vorhandene
 * SKN-0049-/SKN-0050-Revisionen nach, strikt nur dort, wo der Text noch
 * exakt dem bekannten, veralteten Original entspricht.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0050')
            ->where('content_question_revision.question_text', 'Wer darf pyrotechnische Notsignale der Klasse T verwenden?')
            ->update(['content_question_revision.question_text' => 'Wer darf pyrotechnische Notsignale der Klasse P verwenden?']);

        $revisionIds = DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0049')
            ->pluck('content_question_revision.id');

        DB::table('content_answer')
            ->whereIn('revision_id', $revisionIds)
            ->where('answer_text', 'Am Zulassungszeichen: BAM-PT1 oder BAM-PT2.')
            ->update(['answer_text' => 'Am Zulassungszeichen: BAM-P 1 oder BAM-P 2.']);

        // SKN-0035, Antwort C (falsch): ebenfalls die alte Unterklasse "T2";
        // das FKN-Gegenstück (FKN-0022) sagt bereits "P2".
        DB::table('content_answer')
            ->whereIn('revision_id', DB::table('content_question_revision')
                ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
                ->where('content_question.content_id', 'SKN-0035')
                ->pluck('content_question_revision.id'))
            ->where('answer_text', 'Die der Unterklasse T2, d.h. „Signalraketen rot", „Fallschirmsignalraketen rot" und bestimmte Rauchsignale.')
            ->update(['answer_text' => 'Die der Unterklasse P2, d.h. „Signalraketen rot", „Fallschirmsignalraketen rot" und bestimmte Rauchsignale.']);
    }

    public function down(): void
    {
        DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0050')
            ->where('content_question_revision.question_text', 'Wer darf pyrotechnische Notsignale der Klasse P verwenden?')
            ->update(['content_question_revision.question_text' => 'Wer darf pyrotechnische Notsignale der Klasse T verwenden?']);

        $revisionIds = DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0049')
            ->pluck('content_question_revision.id');

        DB::table('content_answer')
            ->whereIn('revision_id', $revisionIds)
            ->where('answer_text', 'Am Zulassungszeichen: BAM-P 1 oder BAM-P 2.')
            ->update(['answer_text' => 'Am Zulassungszeichen: BAM-PT1 oder BAM-PT2.']);
    }
};
