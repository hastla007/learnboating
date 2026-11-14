<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für die gemeinsame FKN/SKN-
 * Videobibliothek (siehe FknSknVideoCourseSeeder): für FKN-Vollkurs-
 * Lektionen werden Fragen aus dem FKN-Katalog verknüpft, für SKN-
 * Vollkurs- und Ergänzungskurs-Lektionen aus dem SKN-Katalog -- auch
 * wenn dieselbe Videolektion (gleicher Titel/gleiche Video-URL) in
 * mehreren Kursen auftaucht, bekommt sie die zum jeweiligen Kurskatalog
 * passenden Fragen, da FKN und SKN getrennte Fragenkataloge sind.
 * Video 1 ("Überblick") bleibt wie die anderen Einstiegs-/Orientierungs-
 * lektionen in diesem Projekt bewusst ohne Quiz.
 */
class FknSknLessonQuestionSeeder extends Seeder
{
    // Bug-Review (Seeder-Idempotenz Videoquiz): einmal geseedete Lektionen
    // (quiz_seeded_at) bleiben unangetastet, siehe SeedsLessonQuizOnce.
    use SeedsLessonQuizOnce;

    /** @var array<string, int[]> Lektionstitel => FKN-Fragenkatalog-Nummern. */
    private const FKN_QUESTIONS = [
        'Rechtliche Grundlagen und Begriffe' => [1, 2, 45, 46, 47],
        // Bug-Review (AA17: Quizfragen in falscher Lektion): 25
        // ("Zulassungszeichen ... nach § 10 BeschG") und 26 ("... zusätzlich
        // eine Klasseneinteilung ...") drehen sich inhaltlich um die
        // amtliche KENNZEICHNUNG pyrotechnischer Munition, nicht um das
        // Erkennen/Unterscheiden der Signalmittel selbst -- 33 ("Woran
        // erkennen Sie ... um welche Unterklasse es sich handelt?") zum
        // exakt selben Thema steht bereits zutreffend unter "Kennzeichnung,
        // Haltbarkeit, Aufbewahrung und Entsorgung".
        'Signalmittel erkennen und unterscheiden' => [3, 8, 10, 22, 27, 28],
        'Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen' => [6, 7, 9, 30, 37, 38],
        'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung' => [12, 13, 14, 15, 16, 25, 26, 33],
        'Versager und praktische Prüfung mit FKN-Signalmitteln' => [39, 40, 52, 58, 60],
    ];

    /** @var array<string, int[]> Lektionstitel => SKN-Fragenkatalog-Nummern. */
    private const SKN_QUESTIONS = [
        'Rechtliche Grundlagen und Begriffe' => [1, 2, 3, 65, 66, 67],
        'Signalmittel erkennen und unterscheiden' => [4, 9, 12, 35, 42],
        'Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen' => [7, 8, 11, 44, 53, 57],
        // Bug-Review (AA17: Quizfragen in falscher Lektion): 39
        // ("Zulassungszeichen ... nach § 10 BeschG") gehört inhaltlich wie
        // das FKN-Gegenstück zur KENNZEICHNUNG pyrotechnischer Munition --
        // 49 ("Woran erkennen Sie ... um welche Unterklasse es sich
        // handelt?") zum exakt selben Thema steht bereits zutreffend hier,
        // nicht unter "Waffenrecht" (das sich um Erwerb/Besitz/Führen von
        // Signalpistole und Munition dreht, nicht um deren amtliche
        // Zulassungskennzeichnung).
        'Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung' => [14, 15, 16, 17, 19, 24, 25, 39, 49],
        'Versager und praktische Prüfung mit FKN-Signalmitteln' => [59, 60, 73, 74, 84, 87],
        'SKN-Zusatz: Waffenrecht, Signalpistole und Munition' => [20, 22, 23, 27, 29, 30, 32, 33, 34, 38],
        // Bug-Review (Y6: SKN-Fragen 57 und 73 doppelt zugeordnet): 57
        // ("Wie hoch steigen Seenotsignalraketen...") gehört bereits zu
        // "Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen",
        // 73 ("...Patrone in der Signalpistole...nicht gezündet...")
        // bereits zu "Versager und praktische Prüfung mit
        // FKN-Signalmitteln" -- beide standen hier zusätzlich ein zweites
        // Mal, wodurch dieselbe Frage im SKN-Videoquiz zweimal auftauchte.
        'SKN-Zusatz: praktische Prüfungsvorbereitung Signalpistole' => [48, 50, 51, 55, 56, 58, 77, 81, 82, 83, 85, 86],
    ];

    public function run(): void
    {
        $this->attachForCourse('FKN', 'fkn_official', self::FKN_QUESTIONS);
        $this->attachForCourse('SKN', 'skn_official', self::SKN_QUESTIONS);
        $this->attachForCourse('ADD-FKN-TO-SKN', 'skn_official', self::SKN_QUESTIONS);
    }

    /** @param array<string, int[]> $lessonQuestions */
    private function attachForCourse(string $courseCode, string $contentRole, array $lessonQuestions): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course) {
            return;
        }

        foreach ($course->videoModules as $module) {
            foreach ($module->lessons as $lesson) {
                $officialNumbers = $lessonQuestions[$lesson->title] ?? null;

                if ($officialNumbers) {
                    $this->seedQuizOnce($lesson, fn (VideoLesson $lesson) => $this->attachQuestions($lesson, $contentRole, $officialNumbers));
                }
            }
        }
    }

    /** @param int[] $officialNumbers */
    private function attachQuestions(VideoLesson $lesson, string $contentRole, array $officialNumbers): void
    {
        foreach ($officialNumbers as $sortOrder => $officialNumber) {
            $question = ContentQuestion::where('official_number', (string) $officialNumber)
                ->where('content_role', $contentRole)
                ->first();

            if ($question) {
                $lesson->questions()->syncWithoutDetaching([$question->id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
