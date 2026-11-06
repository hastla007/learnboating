<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (SKN-Frage 8 fälschlich "Seenot-Handfackel"): FknSknContentSeeder
 * legt pro Frage nur EINMAL eine Revision an (firstOrCreate + `continue`,
 * wenn bereits eine existiert) -- eine Korrektur der Katalogquelle
 * (skn_catalog.php) allein erreicht eine bereits gesäte Installation
 * dadurch nicht erneut. Diese Migration zieht die Korrektur für bereits
 * vorhandene SKN-0008-Revisionen nach, strikt nur dort, wo der Text noch
 * exakt dem bekannten, fehlerhaften Original entspricht (keine
 * versehentliche Überschreibung einer inzwischen anderweitig bearbeiteten
 * Revision).
 */
return new class extends Migration
{
    private const OLD_TEXT = 'Was ist sicherheitstechnisch bei der Verwendung einer Seenot-Handfackel zu beachten?';

    private const NEW_TEXT = 'Was ist sicherheitstechnisch bei der Verwendung eines Seenot-Rauchsignals zu beachten?';

    public function up(): void
    {
        DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0008')
            ->where('content_question_revision.question_text', self::OLD_TEXT)
            ->update(['content_question_revision.question_text' => self::NEW_TEXT]);
    }

    public function down(): void
    {
        DB::table('content_question_revision')
            ->join('content_question', 'content_question.id', '=', 'content_question_revision.question_id')
            ->where('content_question.content_id', 'SKN-0008')
            ->where('content_question_revision.question_text', self::NEW_TEXT)
            ->update(['content_question_revision.question_text' => self::OLD_TEXT]);
    }
};
