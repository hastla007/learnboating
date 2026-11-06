<?php

namespace Database\Seeders\Concerns;

use App\Models\VideoLesson;

/**
 * Bug-Review (Seeder-Idempotenz Videoquiz): die *LessonQuestionSeeder
 * hängten Quizfragen bisher an, sobald eine Lektion KEINE Fragen hatte
 * ("lesson->questions()->exists()"-Guard). db:seed läuft auf bestehenden
 * Installationen erneut -- ein Superadmin, der ALLE Quizfragen einer
 * Lektion entfernt hatte, bekam sie dadurch beim nächsten Seed-Lauf
 * kommentarlos zurück. Jetzt hält video_lesson.quiz_seeded_at (Migration
 * 2024_02_01_000500) dauerhaft fest, dass eine Lektion ihr Quiz schon
 * einmal bekommen hat; eine so markierte Lektion fasst kein Seeder mehr an.
 */
trait SeedsLessonQuizOnce
{
    /**
     * Ruft $attach nur für eine Lektion auf, deren Quiz noch nie geseedet
     * wurde, und markiert sie danach. Eine Lektion, die (ohne Markierung)
     * bereits Fragen hat -- vom Superadmin gepflegt oder von einem
     * Spiegel-Seeder kopiert --, wird nur markiert, nicht verändert.
     *
     * @param  callable(VideoLesson): void  $attach
     */
    protected function seedQuizOnce(?VideoLesson $lesson, callable $attach): void
    {
        if (! $lesson || $lesson->quiz_seeded_at !== null) {
            return;
        }

        if (! $lesson->questions()->exists()) {
            $attach($lesson);
        }

        // Nur markieren, wenn tatsächlich Fragen hängen: fehlen sie (z. B.
        // Fragenkatalog noch nicht importiert), darf ein späterer Lauf
        // es erneut versuchen.
        if ($lesson->questions()->exists()) {
            self::markQuizSeeded($lesson);
        }
    }

    public static function markQuizSeeded(VideoLesson $lesson): void
    {
        $lesson->forceFill(['quiz_seeded_at' => now()])->save();
    }
}
