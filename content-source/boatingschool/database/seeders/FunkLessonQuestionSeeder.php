<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * "Zu den Fragen"-Quiz der Funk-Videokurse (siehe video_lesson_question):
 * jede amtliche SRC- und UBI-Frage hängt an genau der Lektion, zu deren
 * Stoff sie gehört (database/data/funk_video_quiz.php). Die UBI-Lektionen
 * der UBI-Ergänzung und des Kombikurses SRC & UBI zeigen nur die Fragen des
 * Ergänzungskatalogs (Modul UBI_ERG), die diese Kurse auch lernen.
 * Idempotent: eine Lektion, deren Quiz schon einmal geseedet wurde
 * (quiz_seeded_at) oder die schon Fragen hat, bleibt unverändert.
 */
class FunkLessonQuestionSeeder extends Seeder
{
    // Bug-Review (Seeder-Idempotenz Videoquiz): vorher nur "hat noch keine
    // Fragen" -- vom Superadmin geleerte Quizze kamen zurück, siehe
    // SeedsLessonQuizOnce.
    use SeedsLessonQuizOnce;

    /** Kurs => [[Katalog in funk_video_quiz.php, nur Fragen dieses Moduls (oder null)], ...]. */
    private const COURSES = [
        'SRC' => [['SRC', null]],
        'UBI' => [['UBI', null]],
        'ADD-UBI-TO-SRC' => [['UBI', 'UBI_ERG']],
        'SRC-UBI' => [['SRC', null], ['UBI', 'UBI_ERG']],
    ];

    public function run(): void
    {
        $quiz = include database_path('data/funk_video_quiz.php');
        $questionIds = ContentQuestion::where(fn ($q) => $q->where('content_id', 'like', 'SRC-%')->orWhere('content_id', 'like', 'UBI-%'))
            ->pluck('id', 'content_id');

        foreach (self::COURSES as $code => $parts) {
            $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();

            if (! $course) {
                continue;
            }

            foreach ($parts as [$catalog, $onlyModule]) {
                $allowed = $onlyModule ? Module::where('code', $onlyModule)->first()?->questions()->pluck('content_question.id')->flip() : null;

                foreach ($quiz[$catalog] as $chapterTitle => $lessons) {
                    $chapter = $course->videoModules()->where('title', $chapterTitle)->first();

                    foreach ($lessons as $lessonTitle => $contentIds) {
                        $lesson = $chapter?->lessons()->where('title', $lessonTitle)->first();

                        $ids = collect($contentIds)->map(fn ($id) => $questionIds[$id] ?? null)->filter()
                            ->filter(fn ($id) => $allowed === null || isset($allowed[$id]))->values();

                        $this->seedQuizOnce($lesson, fn (VideoLesson $lesson) => $lesson->questions()->sync(
                            $ids->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i + 1]])->all()
                        ));
                    }
                }
            }
        }
    }
}
