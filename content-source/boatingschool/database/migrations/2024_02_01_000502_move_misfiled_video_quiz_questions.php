<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review (Quizfragen in falscher Lektion): mehrere Videoquiz-Fragen
 * hingen an einer Lektion, zu deren Stoff sie nicht gehören (Details in
 * BinnenSegelnLessonQuestionSeeder, BinnenLessonQuestionSeeder und
 * database/data/funk_video_quiz.php, die frische Installationen bereits
 * korrekt bestücken). Die Seeder sind create-once (quiz_seeded_at, siehe
 * Migration 2024_02_01_000500) -- die Korrektur der Quellen allein erreicht
 * bereits gesäte Installationen nicht. Diese Migration korrigiert
 * video_lesson_question der globalen Kurse direkt:
 *
 * - die falsche Zuordnung wird nur entfernt, wenn sie noch besteht (sonst
 *   hat der Superadmin die Lektion bereits selbst gepflegt -- dann bleibt
 *   auch die Ziel-Lektion unangetastet);
 * - danach werden die Zielfragen an die passende Lektion gehängt, jede nur,
 *   wenn sie im Kurs noch an keiner Lektion hängt (jede Frage einmal je
 *   Kurs);
 * - beide Lektionen werden als geseedet markiert, damit kein Seeder eine
 *   dadurch geleerte Lektion ("Mensch über Bord", "Ablauf der
 *   SRC-Prüfung" ...) wieder befüllt.
 *
 * Idempotent: ein zweiter Lauf findet die falsche Zuordnung nicht mehr.
 */
return new class extends Migration
{
    private const SRC_COURSES = ['SRC', 'SRC-UBI'];

    private const UBI_COURSES = ['UBI', 'ADD-UBI-TO-SRC', 'SRC-UBI'];

    private const BINNEN_MOTOR_COURSES = ['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN'];

    /**
     * Korrekturen; from/to = [Kapitel, Lektion].
     *
     * @return array<int, array{courses: string[], from: array{0: string, 1: string}, remove: string, to: ?array{0: string, 1: string}, attach: string[]}>
     */
    private function corrections(): array
    {
        $segelVerkehr = ['Verkehrsregeln und Ausweichen', 'Segelfahrzeuge im Verkehr'];
        $segelteile = ['Segeln: Boot, Rigg und Stabilität', 'Segelteile und Tauwerk'];
        $segeltrimm = ['Segeln: Wind, Kurse und Trimm', 'Segeltrimm bei leichtem und starkem Wind'];
        $notfallsignale = ['Lichter, Zeichen und Töne', 'Notfallsignale'];
        $srcRecht = ['Routineverkehr im Alltag', 'Rechtliche Regeln im Seefunk'];
        $ubiErlaubt = ['Einführung in das UBI', 'Binnenfunk: wer funkt, was ist erlaubt?'];
        $ubiSprechregeln = ['Notverkehr im Binnenfunk – MAYDAY', 'Sprechregeln, Buchstabiertafel und Anruf'];

        $corrections = [
            // SBF-BIN-SEGEL: Quickstopp (Motortechnik) ist keine MOB-Frage; der
            // Katalog hat keine passende -- die Lektion bleibt ohne Quiz.
            ['courses' => ['SBF-BIN-SEGEL'], 'from' => ['Seemannschaft auf Binnengewässern', 'Mensch über Bord'], 'remove' => 'SBF-BASIS-0042', 'to' => null, 'attach' => []],
            // Segler gegen Segler (Lee vor Luv) samt den bisher ungenutzten
            // Ausweichfragen Segler gegen Segler.
            ['courses' => ['SBF-BIN-SEGEL'], 'from' => ['Verkehrsregeln und Ausweichen', 'Berufsschifffahrt und Kleinfahrzeuge'], 'remove' => 'SBF-BIN-0188', 'to' => $segelVerkehr,
                'attach' => ['SBF-BIN-0185', 'SBF-BIN-0186', 'SBF-BIN-0187', 'SBF-BIN-0188', 'SBF-BIN-0189']],
            ['courses' => ['SBF-BIN-SEGEL'], 'from' => ['Seemannschaft auf Binnengewässern', 'Tauwerk und Ausrüstung'], 'remove' => 'SBF-BASIS-0060', 'to' => ['Seemannschaft auf Binnengewässern', 'An- und Ablegen'], 'attach' => ['SBF-BASIS-0060']],
            ['courses' => ['SBF-BIN-SEGEL'], 'from' => $segeltrimm, 'remove' => 'SBF-SEGEL-0272', 'to' => $segelteile, 'attach' => ['SBF-SEGEL-0272']],
            ['courses' => ['SBF-BIN-SEGEL'], 'from' => $segeltrimm, 'remove' => 'SBF-SEGEL-0273', 'to' => $segelteile, 'attach' => ['SBF-SEGEL-0273']],
            // SBF Binnen (Motor), Kombikurs und Ergänzung See -> Binnen: die
            // gekenterte Jolle durch die Not-/Manövrierunfähigkeitszeichen
            // ersetzen.
            ['courses' => self::BINNEN_MOTOR_COURSES, 'from' => $notfallsignale, 'remove' => 'SBF-BIN-0190', 'to' => $notfallsignale,
                'attach' => ['SBF-BIN-0170', 'SBF-BIN-0250', 'SBF-BIN-0251', 'SBF-BIN-0210', 'SBF-BIN-0211']],
            // SRC (auch SRC-Teil des Kombikurses SRC & UBI).
            ['courses' => self::SRC_COURSES, 'from' => ['Routineverkehr im Alltag', 'Sprechregeln und Buchstabiertafel'], 'remove' => 'SRC-0059', 'to' => ['Technik und Funkgerät', 'MMSI und der DSC-Controller'], 'attach' => ['SRC-0059']],
            ['courses' => self::SRC_COURSES, 'from' => ['Dringlichkeit und Sicherheit – PAN PAN und SECURITE', 'Rangfolge im Seefunk'], 'remove' => 'SRC-0086', 'to' => $srcRecht, 'attach' => ['SRC-0086']],
            ['courses' => self::SRC_COURSES, 'from' => ['Zulassung und Prüfungsvorbereitung', 'Ablauf der SRC-Prüfung'], 'remove' => 'SRC-0006', 'to' => $srcRecht, 'attach' => ['SRC-0006']],
        ];

        // UBI (auch UBI-Ergänzung und UBI-Teil des Kombikurses -- dort hängen
        // nur die Fragen des Ergänzungskatalogs; was nicht hängt, wird auch
        // nicht verschoben).
        foreach (['UBI-0018', 'UBI-0019', 'UBI-0020', 'UBI-0021'] as $contentId) {
            $corrections[] = ['courses' => self::UBI_COURSES, 'from' => $ubiSprechregeln, 'remove' => $contentId, 'to' => $ubiErlaubt, 'attach' => [$contentId]];
        }

        $corrections[] = ['courses' => self::UBI_COURSES, 'from' => ['Notverkehr im Binnenfunk – MAYDAY', 'Notverkehr offiziell beenden'], 'remove' => 'UBI-0120',
            'to' => ['Notverkehr im Binnenfunk – MAYDAY', 'Notfall auf dem Binnenrevier: die Notmeldung (MAYDAY)'], 'attach' => ['UBI-0120']];

        return $corrections;
    }

    public function up(): void
    {
        foreach ($this->corrections() as $correction) {
            foreach ($correction['courses'] as $courseCode) {
                $this->apply($courseCode, $correction);
            }
        }
    }

    public function down(): void
    {
        // Datenkorrektur ohne sinnvolle Umkehrung -- die alte Zuordnung war
        // fachlich falsch.
    }

    private function apply(string $courseCode, array $correction): void
    {
        $courseId = DB::table('course_definition')->where('code', $courseCode)->whereNull('tenant_id')->value('id');
        $wrongQuestionId = $this->questionId($correction['remove']);

        if (! $courseId || ! $wrongQuestionId) {
            return;
        }

        $fromLessonId = $this->lessonId($courseId, ...$correction['from']);

        if (! $fromLessonId) {
            return;
        }

        $removed = DB::table('video_lesson_question')
            ->where('video_lesson_id', $fromLessonId)
            ->where('question_id', $wrongQuestionId)
            ->delete();

        if ($removed === 0) {
            return;
        }

        $this->markQuizSeeded($fromLessonId);

        $toLessonId = $correction['to'] ? $this->lessonId($courseId, ...$correction['to']) : null;

        if (! $toLessonId) {
            return;
        }

        $courseLessonIds = DB::table('video_lesson')
            ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
            ->where('video_module.course_id', $courseId)
            ->pluck('video_lesson.id');

        $sortOrder = (int) DB::table('video_lesson_question')->where('video_lesson_id', $toLessonId)->max('sort_order');

        foreach ($correction['attach'] as $contentId) {
            $questionId = $this->questionId($contentId);

            if (! $questionId || DB::table('video_lesson_question')->whereIn('video_lesson_id', $courseLessonIds)->where('question_id', $questionId)->exists()) {
                continue;
            }

            DB::table('video_lesson_question')->insert([
                'video_lesson_id' => $toLessonId,
                'question_id' => $questionId,
                'sort_order' => ++$sortOrder,
            ]);
        }

        $this->markQuizSeeded($toLessonId);
    }

    private function questionId(string $contentId): ?string
    {
        return DB::table('content_question')->where('content_id', $contentId)->value('id');
    }

    private function lessonId(string $courseId, string $chapterTitle, string $lessonTitle): ?string
    {
        return DB::table('video_lesson')
            ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
            ->where('video_module.course_id', $courseId)
            ->where('video_module.title', $chapterTitle)
            ->where('video_lesson.title', $lessonTitle)
            ->value('video_lesson.id');
    }

    private function markQuizSeeded(string $lessonId): void
    {
        DB::table('video_lesson')->where('id', $lessonId)->whereNull('quiz_seeded_at')->update(['quiz_seeded_at' => now()]);
    }
};
