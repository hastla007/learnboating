<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Review-Runde 4 (Inhalt): vier SRC-Quizfragen hingen an der falschen
 * Lektion -- SRC-0171/0172 (EPIRB-Wartung) unter „Fehlalarm richtig
 * aufheben“, SRC-0113/0114 (Anruf einer Küstenfunkstelle) unter „Funkprobe
 * und Testanruf“. Gleiche Mechanik wie 000502; was nicht (mehr) an der
 * alten Lektion hängt, z. B. weil der Superadmin das Quiz gepflegt hat,
 * bleibt unberührt.
 */
return new class extends Migration
{
    private const SRC_COURSES = ['SRC', 'SRC-UBI'];

    /**
     * Korrekturen; from/to = [Kapitel, Lektion].
     *
     * @return array<int, array{courses: string[], from: array{0: string, 1: string}, remove: string, to: ?array{0: string, 1: string}, attach: string[]}>
     */
    private function corrections(): array
    {
        $routine = 'Routineverkehr im Alltag';
        $not = 'Notverkehr – MAYDAY';
        $corrections = [];
        foreach (['SRC-0171', 'SRC-0172'] as $contentId) {
            $corrections[] = ['courses' => self::SRC_COURSES, 'from' => [$not, 'Fehlalarm richtig aufheben'], 'remove' => $contentId, 'to' => [$not, 'EPIRB: Notalarm über Satellit'], 'attach' => [$contentId]];
        }
        foreach (['SRC-0113', 'SRC-0114'] as $contentId) {
            $corrections[] = ['courses' => self::SRC_COURSES, 'from' => [$routine, 'Funkprobe und Testanruf'], 'remove' => $contentId, 'to' => [$routine, 'Revier- und Hafenfunk'], 'attach' => [$contentId]];
        }

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
