<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz für SBF Binnen unter Segel
 * (SBF-BIN-SEGEL): verknüpft passende Fragen aus dem Kurskatalog (72
 * Basis- + 181 Binnen- + 47 Segel-spezifische Fragen) mit den 37
 * Videolektionen. Anders als BinnenLessonQuestionSeeder (Zuordnung über
 * question_text) wird hier über content_role + official_number
 * zugeordnet, da viele Fragen im Binnen-Katalog denselben Text teilen
 * (z.B. "Was bedeutet dieses Tafelzeichen?" kommt über ein Dutzend Mal
 * vor) und daher über den Text allein nicht eindeutig identifizierbar
 * wären. Einstiegslektionen (Kapitel "Einstieg und Prüfungsüberblick")
 * sowie "Alkohol und Fahrtüchtigkeit" (kein passender Fragenkatalog-
 * Eintrag) bleiben bewusst ohne Quiz.
 *
 * Bug-Review (Quizfragen in falscher Lektion): "Mensch über Bord" zeigte
 * nur SBF-BASIS-0042 (Quickstopp = Unterbrechung von Zündung/Kraftstoff,
 * also Motortechnik) -- der Binnen-Katalog hat keine MOB-Frage, die
 * Lektion bleibt daher ohne Quiz. 188 (Segler gegen Segler, Lee vor Luv)
 * stand unter "Berufsschifffahrt und Kleinfahrzeuge" und gehört wie die
 * bisher unbenutzten 185/186/187/189 (Ausweichregeln Segler gegen Segler)
 * zu "Segelfahrzeuge im Verkehr". 60 (Seeventile schließen/Hauptschalter
 * aus beim Verlassen des Fahrzeugs) passt zum Festmachen unter "An- und
 * Ablegen" statt zu "Tauwerk und Ausrüstung"; 272/273 (Killen schädigt
 * das Tuch, Segelschäden sofort reparieren) zur Segelpflege unter
 * "Segelteile und Tauwerk" statt zum Trimm. Bestehende Installationen
 * korrigiert die Migration 2024_02_01_000502.
 */
class BinnenSegelnLessonQuestionSeeder extends Seeder
{
    use SeedsLessonQuizOnce;

    /** @var array<string, array<string, array<int, int>>> Kapitel => Lektion => [official_number, content_role]. */
    private const LESSON_QUESTIONS = [
        'Grundlagen und Sicherheit' => [
            'Verantwortlicher Schiffsführer' => [76, 80, 82, 214],
            'Begriffe und Fahrzustände' => [3, 6, 8, 9, 248],
            'Verhalten in Notfällen' => [190, 210, 211, 212],
        ],
        'Seemannschaft auf Binnengewässern' => [
            'An- und Ablegen' => [35, 40, 48, 61, 60],
            'Ankern' => [39],
            'Schleusen' => [117, 118, 119, 245],
        ],
        'Verkehrsregeln und Ausweichen' => [
            'Grundregeln' => [144, 174, 175, 176],
            'Begegnung und Überholen' => [36, 37, 180],
            'Segelfahrzeuge im Verkehr' => [140, 143, 179, 184, 185, 186, 187, 188, 189],
            'Berufsschifffahrt und Kleinfahrzeuge' => [34, 177, 181],
        ],
        'Schifffahrtszeichen und Signale' => [
            'Tafeln und Zeichen' => [17, 18, 107, 156],
            'Lichterführung' => [10, 11, 12, 13, 138],
            'Schallsignale' => [159, 160, 161, 168],
            'Tonnen und Fahrwasser' => [97, 98, 100, 239],
        ],
        'Wetter und Umwelt' => [
            'Wind und Wetterzeichen' => [71, 219, 220],
            'Gewitter und Starkwind' => [217],
            'Umweltschutz' => [31, 32, 33, 213],
            'Verhalten bei schlechter Sicht' => [205, 206, 207, 208],
        ],
        'Segeln: Boot, Rigg und Stabilität' => [
            'Jolle, Kielboot und Kimmkieler' => [254, 255, 256],
            'Form- und Gewichtsstabilität' => [258, 259, 260, 261, 262],
            'Segelteile und Tauwerk' => [267, 268, 269, 270, 271, 272, 273],
            'Ruder und Schwert' => [257, 282, 283, 296],
        ],
        'Segeln: Wind, Kurse und Trimm' => [
            'Wahrer und scheinbarer Wind' => [274, 275, 276],
            'Amwind-, Halbwind- und Vorwindkurs' => [277, 278, 279, 285],
            'Segeltrimm bei leichtem und starkem Wind' => [286, 287, 288, 289, 290],
            'Luv- und Leegierigkeit' => [291, 292, 293],
        ],
        'Segelmanöver und besondere Situationen' => [
            'Wenden und Halsen' => [295],
            'Böen und Krängung' => [263, 264, 280, 281, 284, 294],
            'Patenthalse' => [297],
            'Ruderbruch' => [265, 266],
            'Sicherheitsmaßnahmen bei Starkwind' => [298, 299, 300],
        ],
    ];

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-SEGEL')->whereNull('tenant_id')->first();

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
            $contentRole = $officialNumber <= 72 ? 'shared_basis' : ($officialNumber <= 253 ? 'binnen_specific' : 'segel_specific');

            $question = ContentQuestion::where('official_number', (string) $officialNumber)
                ->where('content_role', $contentRole)
                ->first();

            if ($question) {
                $lesson->questions()->syncWithoutDetaching([$question->id => ['sort_order' => $sortOrder + 1]]);
            }
        }
    }
}
