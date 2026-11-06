<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für den Ergänzungskurs
 * "Binnen → Segeln" (ADD-BIN-SEGEL): verknüpft die 47 spezifischen
 * Segelfragen (ELWIS-Nr. 254-300, content_role=segel_specific) mit den
 * 19 Videolektionen, jede Frage genau einmal über alle Lektionen
 * verteilt. Zuordnung über official_number statt question_text (siehe
 * BinnenSegelnLessonQuestionSeeder für die Begründung).
 */
class BinnenSegelnAddonLessonQuestionSeeder extends Seeder
{
    // Bug-Review (Seeder-Idempotenz Videoquiz): einmal geseedete Lektionen
    // (quiz_seeded_at) bleiben unangetastet, siehe SeedsLessonQuizOnce.
    use SeedsLessonQuizOnce;

    /** @var array<string, array<string, array<int, int>>> Kapitel => Lektion => [official_number]. */
    private const LESSON_QUESTIONS = [
        'Segelboot und Stabilität' => [
            'Bootstypen und Kimmkieler' => [254, 255, 256],
            'Form- und Gewichtsstabilität' => [258, 259, 260, 261, 262],
            'Jolle und Kielyacht' => [257, 263, 264],
            'Ruderbruch und Pinnenausleger' => [265, 266],
        ],
        'Rigg, Segel und Bordpraxis' => [
            'Tauwerk und Fallen' => [267, 268],
            'Ecken und Kanten des Segels' => [269, 270],
            'Segellatten' => [271],
            'Segelpflege und Killen' => [272, 273],
        ],
        'Wind und Segelkurse' => [
            'Wahrer und scheinbarer Wind' => [274, 275, 276],
            'Wind auf Amwind- und Vorwindkursen' => [277, 278, 279],
            'Böen und Winddreher' => [280, 281],
        ],
        'Schwert, Krängung und Segeltrimm' => [
            'Schwertstellung und Abdrift' => [282, 283],
            'Krängung und Luvgierigkeit' => [284, 293, 294],
            'Segeltrimm bei leichtem und starkem Wind' => [285, 288, 289],
            'Holepunkt und Unterliekstrecker' => [286, 287, 290, 291, 292],
        ],
        'Manöver und Notfälle unter Segel' => [
            'Wende, Halse und Q-Wende' => [295],
            'Boot an der Boje' => [296],
            'Patenthalse' => [297],
            'Want- oder Vorstagbruch' => [298, 299, 300],
        ],
    ];

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-BIN-SEGEL')->whereNull('tenant_id')->first();

        if (! $course) {
            return;
        }

        foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
            $chapter = $course->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            foreach ($lessons as $lessonTitle => $officialNumbers) {
                $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();
                $this->seedQuizOnce($lesson, fn (VideoLesson $lesson) => $this->attachQuestions($lesson, $officialNumbers));
            }
        }
    }

    /** @param int[] $officialNumbers */
    private function attachQuestions(VideoLesson $lesson, array $officialNumbers): void
    {
        foreach ($officialNumbers as $sortOrder => $officialNumber) {
            $question = ContentQuestion::where('official_number', (string) $officialNumber)
                ->where('content_role', 'segel_specific')
                ->first();

            if ($question) {
                $lesson->questions()->syncWithoutDetaching([$question->id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
