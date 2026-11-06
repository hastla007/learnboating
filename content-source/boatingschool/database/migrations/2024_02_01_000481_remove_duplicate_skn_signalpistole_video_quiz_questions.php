<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Y6: zwei SKN-Fragen im Video-Quiz doppelt zugeordnet):
 * FknSknLessonQuestionSeeder::SKN_QUESTIONS listete die Fragen SKN-0057
 * und SKN-0073 versehentlich ein zweites Mal unter der Lektion
 * "SKN-Zusatz: praktische Prüfungsvorbereitung Signalpistole", obwohl sie
 * bereits unter "Sicherer Einsatz von Raketen, Handfackeln und
 * Rauchsignalen" (0057) bzw. "Versager und praktische Prüfung mit
 * FKN-Signalmitteln" (0073) zugeordnet sind -- dort passen sie inhaltlich
 * auch eindeutig besser hin. Der Seeder selbst ist create-once
 * (lesson->questions()->exists()-Guard), die Korrektur der Quelle allein
 * erreicht also bereits gesäte Installationen nicht. Diese Migration
 * entfernt die doppelte Zuordnung direkt aus video_lesson_question, für
 * SKN und den Ergänzungskurs ADD-FKN-TO-SKN (der dieselbe Lektion/Fragen
 * spiegelt).
 */
return new class extends Migration
{
    private const DUPLICATE_LESSON_TITLE = 'SKN-Zusatz: praktische Prüfungsvorbereitung Signalpistole';

    private const DUPLICATE_OFFICIAL_NUMBERS = ['57', '73'];

    public function up(): void
    {
        $this->removeDuplicates();
    }

    public function down(): void
    {
        // Datenkorrektur ohne sinnvolle Umkehrung -- der Seeder würde die
        // Zuordnung bei Bedarf ohnehin nicht erneut anlegen (create-once).
    }

    private function removeDuplicates(): void
    {
        $questionIds = DB::table('content_question')
            ->where('content_role', 'skn_official')
            ->whereIn('official_number', self::DUPLICATE_OFFICIAL_NUMBERS)
            ->pluck('id');

        if ($questionIds->isEmpty()) {
            return;
        }

        $lessonIds = DB::table('video_lesson')
            ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
            ->join('course_definition', 'course_definition.id', '=', 'video_module.course_id')
            ->whereIn('course_definition.code', ['SKN', 'ADD-FKN-TO-SKN'])
            ->where('video_lesson.title', self::DUPLICATE_LESSON_TITLE)
            ->pluck('video_lesson.id');

        if ($lessonIds->isEmpty()) {
            return;
        }

        DB::table('video_lesson_question')
            ->whereIn('video_lesson_id', $lessonIds)
            ->whereIn('question_id', $questionIds)
            ->delete();
    }
};
