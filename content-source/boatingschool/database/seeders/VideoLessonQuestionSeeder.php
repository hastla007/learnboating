<?php

namespace Database\Seeders;

use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Demo-Bestückung des "Zu den Fragen"-Quiz (siehe video_lesson_question,
 * VideoLessonController/VideoQuizController): verknüpft ausgewählte, bereits
 * veröffentlichte Fragen aus der zentralen Fragenbasis mit einzelnen
 * Videolektionen, passend zum jeweiligen Lektionsinhalt.
 */
class VideoLessonQuestionSeeder extends Seeder
{
    // Bug-Review (Seeder-Idempotenz Videoquiz): einmal geseedete Lektionen
    // (quiz_seeded_at) bleiben unangetastet, siehe SeedsLessonQuizOnce.
    use SeedsLessonQuizOnce;

    /** @var array<string, array<int, string>> Kapitel => Lektion => Fragetexte, je Teilkurs. */
    private const LESSON_QUESTIONS = [
        'Einführung in den Sportbootführerschein' => [
            'Motor, Bootsführer und Fahrer' => [
                'Was ist zu tun, wenn vor Antritt der Fahrt nicht feststeht, wer Schiffsführer ist?',
                'In welchen Fällen darf weder ein Sportboot geführt noch dessen Kurs oder Geschwindigkeit selbstständig bestimmt werden?',
                'Unter welchen Voraussetzungen darf ein Sportboot mit Elektromotor ohne Fahrerlaubnis geführt werden?',
                'Welche Sportboote sind von der Fahrerlaubnispflicht auf den Seeschifffahrtsstraßen ausgenommen?',
            ],
            'Motorbootfahren' => [
                'Welche Gefahren können entstehen, wenn ein kleines von einem größeren Fahrzeug überholt wird?',
                'Welches ist der günstigste Anlaufwinkel beim Anlegen?',
                'Wodurch wird bei einem Fahrzeug mit Außenbordmotor und ohne Ruderanlage die Ruderwirkung erzielt?',
                'Während der Fahrt sollte die Maschinenanlage ständig überwacht werden. Worauf muss besonders geachtet werden?',
                'Die Temperatur der Antriebsmaschine überschreitet die zulässigen Grenzwerte. Was könnte die mögliche Ursache sein?',
                'Die Ladekontrolllampe erlischt nach dem Starten nicht. Was könnte die mögliche Ursache sein?',
                'Die Ölkontrollleuchte leuchtet nach dem Starten weiter. Was könnte die mögliche Ursache sein?',
                'Der Motor ist gestartet worden. Was kann die Ursache sein, wenn nach dem Einkuppeln der Antriebswelle der Motor stehenbleibt?',
                'Ein Außenborder mit gefülltem Tank bleibt während der Fahrt stehen. Was könnten die Ursachen sein?',
            ],
            'Radeffekt' => [
                'Was ist unter einem rechtsdrehenden Propeller zu verstehen?',
                'Was ist unter einem linksdrehenden Propeller zu verstehen?',
                'Was ist unter der indirekten Ruderwirkung (Radeffekt) des Propellers zu verstehen?',
                'Weshalb ist die Kenntnis der Propellerdrehrichtung von Bedeutung?',
                'Welche Anlegeseite ist mit rechtsdrehendem Propeller empfehlenswert und warum?',
            ],
            'Lebensgefahr im Wasser' => [
                'In welcher Situation dürfen Notsignale gegeben werden?',
            ],
            'Tanken' => [
                'Was muss beim Tanken beachtet werden?',
            ],
            'Umweltschutz' => [
                'Welches Merkblatt enthält Hinweise für das Verhalten zum Schutz seltener Tiere und Pflanzen sowie zur Reinhaltung der Gewässer?',
                'Wie kann mitgeholfen werden, die Lebensmöglichkeiten der Pflanzen- und Tierwelt in Gewässern und Feuchtgebieten zu bewahren und zu fördern?',
                'Warum sollte man sich von Schilf- und Röhrichtzonen sowie von dicht bewachsenen Uferzonen möglichst weit fernhalten?',
                'Wo finden Sie Informationen über umweltfreundliche Farben, Lacke und Antifouling-Beschichtungen für Ihr Boot?',
                'Welche Veröffentlichungen enthalten wichtige Regeln und Tipps für Wassersportler, Empfehlungen zur Ausrüstung von Sportbooten sowie Hinweise zu umweltgerechtem Verhalten auf dem Wasser?',
            ],
            'Jetskis' => [
                'Was bewirkt der Quickstopp?',
            ],
        ],
        'Seemannschaft' => [
            'Ankern' => [
                'Woran kann man erkennen, ob der Anker hält?',
            ],
            'Feuer an Bord' => [
                'Welcher Feuerlöscher ist für Sportboote zweckmäßig und wie oft muss man einen Feuerlöscher überprüfen lassen?',
                'Welche Maßnahmen muss man ergreifen, um einen Brand mit dem Feuerlöscher wirksam zu bekämpfen?',
            ],
            'Nebel' => [
                'Welche besonderen Maßnahmen sind bei verminderter Sicht zu treffen?',
            ],
        ],
        'Wetterkunde' => [
            'Entstehung des Wetters' => [
                'Welche Faktoren sind hauptsächlich für das Wettergeschehen, also für Wind und Niederschläge, ausschlaggebend?',
            ],
            'Der Wind' => [
                'Für welche Windstärken wird eine Starkwindwarnung herausgegeben?',
                'Für welche Windstärken wird eine Sturmwarnung herausgegeben?',
            ],
            'Wetterkarten' => [
                'Wie werden Orte gleichen Luftdrucks in der Wetterkarte dargestellt und in welcher Maßeinheit wird der Luftdruck angegeben?',
                'Was bedeuten die in der Wetterkarte abgebildeten Isobaren?',
            ],
        ],
        'Ausweichregeln' => [
            'Ausweichregeln für alle Fahrzeuge' => [
                'Wie weichen zwei Motorboote aus, die sich auf entgegengesetzten Kursen nähern?',
                'Was bedeutet das "Manöver des letzten Augenblicks"?',
                'Wie müssen sich zwei in Sicht befindliche Maschinenfahrzeuge verhalten, die sich einander auf entgegengesetzten oder fast entgegengesetzten Kursen nähern, um die Möglichkeit der Gefahr eines Zusammenstoßes zu vermeiden?',
                'Wie verhält sich der Kurshaltepflichtige vor Einleitung des Manövers des letzten Augenblicks gegenüber einem ausweichpflichtigen Fahrzeug?',
                'Wie muss sich ein Ausweichpflichtiger gegenüber einem Kurshalter verhalten?',
                'Wie verhält man sich als Kurshalter, wenn man feststellt, dass ein anderes Fahrzeug seiner Ausweichpflicht nicht nachkommt und die Gefahr einer unmittelbaren Kollision bevorsteht?',
            ],
            'Ausweichregeln für Maschinenfahrzeuge' => [
                'Zwei Motorboote nähern sich auf kreuzenden Kursen. Es besteht die Gefahr eines Zusammenstoßes. Wer ist ausweichpflichtig?',
            ],
            'Ausweichregeln für Segelfahrzeuge' => [
                'Welche Seite wird als Luvseite bezeichnet?',
                'Welche Seite wird als Leeseite bezeichnet?',
            ],
        ],
        'Berufsschifffahrt' => [
            'Seeverkehrsrecht' => [
                'Wann ist ein Fahrzeug in Fahrt?',
                'Wo gelten die Kollisionsverhütungsregeln (KVR)?',
                'Was gilt, wenn eine Bestimmung der Seeschifffahrtsstraßen-Ordnung (SeeSchStrO) mit den Kollisionsverhütungsregeln (KVR) im Widerspruch steht?',
            ],
            'Seefunk' => [
                'Wozu dient primär das Automatische Identifikationssystem (AIS)?',
                'Welche Informationen können über das Automatische Identifikationssystem (AIS) ausgetauscht werden?',
            ],
            'Verkehrstrennungsgebiete' => [
                'Was sind "Verkehrstrennungsgebiete"?',
                'Wie sind Verkehrstrennungsgebiete zu befahren?',
            ],
            // Per Katalognummer statt Fragetext: der Text hat sich mit der
            // Korrektur der leeren Klammern (BB10) geändert, die Suche nach
            // dem alten Text fand auf einer Neuinstallation nichts mehr.
            'Schallsignale' => [
                ['shared_basis' => '4'],
                ['shared_basis' => '5'],
            ],
        ],
        'Lichter und Signale' => [
            'Positionslaternen' => [
                'Was für eine Laterne kann ein Segelfahrzeug von weniger als 20 m Länge anstelle der Seitenlichter und des Hecklichtes führen?',
            ],
            'Spezialfälle' => [
                ['see_specific' => '93'],
                ['see_specific' => '107'],
                ['see_specific' => '105'],
                ['see_specific' => '108'],
                ['see_specific' => '110'],
                ['see_specific' => '111'],
            ],
            'Signalkörper und Flaggen' => [
                'Was bedeutet es, wenn jedes Fahrzeug eines Schleppverbandes einen schwarzen Rhombus führt?',
                ['see_specific' => '99'],
                ['see_specific' => '104'],
                ['see_specific' => '106'],
                ['see_specific' => '109'],
                ['see_specific' => '112'],
            ],
        ],
        'Leuchtfeuer und Tonnen' => [
            'Feuerarten und Kennungen' => [
                'Was versteht man unter einem unterbrochenen Feuer?',
                'Was versteht man unter einem Blinkfeuer?',
                'Was versteht man unter einem Blitzfeuer?',
                'Was versteht man unter einem Funkelfeuer?',
                'Was versteht man unter einem Gleichtaktfeuer?',
            ],
            'Sektorenfeuer' => [
                'Was versteht man unter einem Leitfeuer?',
                'Wie navigiert man mittels eines Leitfeuers?',
                'Was versteht man unter einem Richtfeuer?',
                'Was versteht man unter einem Quermarkenfeuer?',
                'Wie navigiert man mittels eines Quermarkenfeuers?',
            ],
            'Fahrwasser' => [
                'Welche Kennung und Farbe haben die Feuer der Leuchttonnen an der Steuerbordseite des Fahrwassers?',
                'Welche Kennung und Farbe haben die Feuer der Leuchttonnen an der Backbordseite des Fahrwassers?',
            ],
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        foreach (self::LESSON_QUESTIONS as $chapterTitle => $lessons) {
            $chapter = $sbfSee->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                // Bug-Review Betrieb B5: Kapitel/Lektionen für SBF See
                // kommen aus mehreren, unabhängig voneinander gepflegten
                // Seedern (VideoCourseSeeder, SeemannschaftVideoSeeder) --
                // ein stiller continue() hier verschleierte zuvor jede
                // künftige Drift zwischen diesen Titeln und den hier
                // erwarteten, bis jemand die angehängten Fragen von Hand
                // nachzählt (siehe DatabaseSeeder für den konkreten Fall:
                // SeemannschaftVideoSeeder lief bisher NACH diesem Seeder).
                $this->command?->warn("VideoLessonQuestionSeeder: Kapitel '{$chapterTitle}' existiert (noch) nicht bei SBF See -- Fragen dieses Kapitels wurden NICHT angehängt.");

                continue;
            }

            foreach ($lessons as $lessonTitle => $questionTexts) {
                $lesson = $chapter->lessons()->where('title', $lessonTitle)->first();

                if (! $lesson) {
                    $this->command?->warn("VideoLessonQuestionSeeder: Lektion '{$lessonTitle}' existiert (noch) nicht im Kapitel '{$chapterTitle}' -- Fragen dieser Lektion wurden NICHT angehängt.");

                    continue;
                }

                $this->seedQuizOnce($lesson, fn (VideoLesson $lesson) => $this->attachQuestions($lesson, $questionTexts));
            }
        }
    }

    /**
     * @param  array<int, string|array<string, string>>  $questions  Fragetext, oder
     *                                                               [content_role => 'Nr.'] (z. B. ['see_specific' => '93']),
     *                                                               um eine Frage über ihre Katalognummer zu identifizieren
     *                                                               (bei mehrdeutigem oder später korrigiertem Fragetext). Früher über den
     *                                                               Dateinamen des Bildes, der seit den ELWIS-Bildern
     *                                                               (ElwisQuestionImageSeeder) nicht mehr existiert.
     */
    private function attachQuestions(VideoLesson $lesson, array $questions): void
    {
        foreach ($questions as $sortOrder => $question) {
            $query = ContentQuestionRevision::where('editorial_status', 'published');

            $revision = is_array($question)
                ? $query->whereHas('question', fn ($q) => $q->where('content_role', array_key_first($question))->where('official_number', reset($question)))->first()
                : $query->where('question_text', $question)->first();

            if ($revision) {
                $lesson->questions()->syncWithoutDetaching([$revision->question_id => ['sort_order' => $sortOrder + 1]]);

                continue;
            }

            $identifier = is_array($question) ? "SBF-See-Frage {$question['see_specific']}" : "Text '{$question}'";
            $this->command?->warn("VideoLessonQuestionSeeder: Keine veröffentlichte Frage für {$identifier} (Lektion '{$lesson->title}') gefunden -- nicht angehängt.");
        }
    }
}
