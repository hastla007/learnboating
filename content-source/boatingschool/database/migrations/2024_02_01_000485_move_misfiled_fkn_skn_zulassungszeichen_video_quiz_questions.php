<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (AA17: Quizfragen in falscher Lektion): FknSknLessonQuestionSeeder
 * ordnete die Fragen zum Zulassungszeichen (BAM) nach § 10 BeschG bisher der
 * falschen Lektion zu -- FKN-0025 und FKN-0026 standen unter "Signalmittel
 * erkennen und unterscheiden", SKN-0039 unter "SKN-Zusatz: Waffenrecht,
 * Signalpistole und Munition". Inhaltlich drehen sich alle drei um die
 * amtliche Kennzeichnung pyrotechnischer Munition -- FKN-0033 bzw. SKN-0049
 * zum exakt selben Thema stehen bereits zutreffend unter "Kennzeichnung,
 * Haltbarkeit, Aufbewahrung und Entsorgung". Der Seeder selbst ist
 * create-once (lesson->questions()->exists()-Guard), die Korrektur der
 * Quelle allein erreicht also bereits gesäte Installationen nicht. Diese
 * Migration verschiebt die drei Zuordnungen direkt in video_lesson_question,
 * für FKN, SKN und den Ergänzungskurs ADD-FKN-TO-SKN (der dieselbe
 * SKN-Lektion/-Fragen spiegelt).
 */
return new class extends Migration
{
    private const TARGET_LESSON_TITLE = 'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung';

    /** @var array<int, array{0: string, 1: string, 2: string}> [Kurscode, content_role, official_number] */
    private const MOVES = [
        ['FKN', 'fkn_official', '25'],
        ['FKN', 'fkn_official', '26'],
        ['SKN', 'skn_official', '39'],
        ['ADD-FKN-TO-SKN', 'skn_official', '39'],
    ];

    public function up(): void
    {
        foreach (self::MOVES as [$courseCode, $contentRole, $officialNumber]) {
            $this->moveQuestion($courseCode, $contentRole, $officialNumber);
        }
    }

    public function down(): void
    {
        // Datenkorrektur ohne sinnvolle Umkehrung -- der Seeder würde die
        // alte (falsche) Zuordnung bei Bedarf ohnehin nicht erneut anlegen
        // (create-once).
    }

    private function moveQuestion(string $courseCode, string $contentRole, string $officialNumber): void
    {
        $questionId = DB::table('content_question')
            ->where('content_role', $contentRole)
            ->where('official_number', $officialNumber)
            ->value('id');

        if (! $questionId) {
            return;
        }

        $lessonIds = DB::table('video_lesson')
            ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
            ->join('course_definition', 'course_definition.id', '=', 'video_module.course_id')
            ->where('course_definition.code', $courseCode)
            ->pluck('video_lesson.id', 'video_lesson.title');

        $targetLessonId = $lessonIds[self::TARGET_LESSON_TITLE] ?? null;

        if (! $targetLessonId) {
            return;
        }

        $existing = DB::table('video_lesson_question')
            ->whereIn('video_lesson_id', $lessonIds->values())
            ->where('question_id', $questionId)
            ->first();

        if (! $existing || $existing->video_lesson_id === $targetLessonId) {
            return;
        }

        $nextSortOrder = (int) DB::table('video_lesson_question')
            ->where('video_lesson_id', $targetLessonId)
            ->max('sort_order') + 1;

        DB::table('video_lesson_question')
            ->where('video_lesson_id', $existing->video_lesson_id)
            ->where('question_id', $questionId)
            ->update([
                'video_lesson_id' => $targetLessonId,
                'sort_order' => $nextSortOrder,
            ]);
    }
};
