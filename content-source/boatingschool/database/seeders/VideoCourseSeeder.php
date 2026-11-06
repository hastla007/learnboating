<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Beispiel-Videokurs für SBF See. Die Video-URL ist bewusst ein öffentliches
 * Platzhaltervideo -- vor Produktivbetrieb durch echtes, lizenziertes
 * Kursmaterial der jeweiligen Bootsschule ersetzen.
 */
class VideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapters = [
            'Einführung in den Sportbootführerschein' => [
                ['Alles zum Führerschein', 267],
                ['Motor, Bootsführer und Fahrer', 164],
                ['Begriffe', 143],
                ['Motorbootfahren', 372],
                ['Radeffekt', 163],
                ['Lebensgefahr im Wasser', 168],
                ['Tanken', 148],
                ['Umweltschutz', 175],
                ['Jetskis', 74],
            ],
            'Seemannschaft' => [
                ['Anlegemanöver', 245],
            ],
            'Knoten' => [
                ['Achtknoten', 34],
                ['Kreuzknoten', 34],
                ['Einfacher Schotstek', 40],
                ['Doppelter Schotstek', 52],
                ['Palstek', 65],
                ['Webeleinsteg', 31],
                ['Webeleinsteg auf Slip', 38],
                ['Stopperstek', 46],
                ['1½ Rundtörn mit zwei halben Schlägen', 58],
                ['Klampe belegen mit Kopfschlag', 71],
            ],
            'Praxisvideos (Motor)' => [
                ['Motor starten und abstellen', 58],
                ['Ölstand und Kühlwasser prüfen', 64],
                ['Kraftstoffsystem entlüften', 72],
                ['Propeller und Antrieb kontrollieren', 51],
                ['Verhalten bei Startproblemen', 69],
            ],
            'Navigation' => [
                ['Geografische Koordinaten', 182],
                ['Position bestimmen', 291],
                ['Entfernungen messen', 206],
                ['Kurs', 172],
                ['Parallelverschiebung', 114],
                ['Kurs einer Seekarte entnehmen', 74],
                ['Kursbeschickung: Missweisung', 267],
                ['Kursbeschickung: Ablenkung', 222],
                ['Theorie und Praxis', 175],
                ['Koppeln und Peilen', 173],
                ['Besteckversetzung', 182],
            ],
            'Wetterkunde' => [
                ['Wolken und Wettervorhersage', 198],
                ['Wind und Seegang', 187],
            ],
            'Ausweichregeln' => [
                ['Vorfahrtsregeln auf dem Wasser', 220],
            ],
            'Lichter und Signale' => [
                ['Positionslaternen', 190],
                ['Schallsignale', 132],
            ],
        ];

        // Schritt-für-Schritt-Galerie je Knoten (analog zur
        // "Der Achtknoten"-Ansicht im Referenzprodukt). Ohne echtes
        // Bildmaterial werden hier nur benannte Platzhalter-Schritte
        // angelegt -- vor Produktivbetrieb durch echte Aufnahmen ersetzen.
        $knotSteps = [
            'Achtknoten' => [
                'Ende zur Schlaufe legen',
                'Ende um das stehende Part führen',
                'Ende durch die Schlaufe fädeln',
                'Knoten festziehen',
                'Fertigen Knoten prüfen',
            ],
            'Kreuzknoten' => [
                'Enden überkreuzen',
                'Erste Schlaufe formen',
                'Enden erneut überkreuzen',
                'Zweite Schlaufe formen',
                'Knoten festziehen',
            ],
            'Einfacher Schotstek' => [
                'Bucht der dickeren Leine formen',
                'Ende der dünneren Leine durchführen',
                'Ende um die Bucht herumführen',
                'Ende unter sich selbst durchstecken',
                'Knoten festziehen',
            ],
            'Doppelter Schotstek' => [
                'Bucht der dickeren Leine formen',
                'Ende der dünneren Leine durchführen',
                'Ende um die Bucht führen',
                'Zweite Windung um die Bucht legen',
                'Ende unter sich selbst durchstecken',
                'Knoten festziehen',
            ],
            'Palstek' => [
                'Kleine Schlaufe ins stehende Part legen',
                'Ende von unten durch die Schlaufe führen',
                'Ende um das stehende Part führen',
                'Ende zurück durch die Schlaufe führen',
                'Knoten festziehen',
            ],
            'Webeleinsteg' => [
                'Erste Törn um das Rundholz legen',
                'Zweite Törn darüberlegen',
                'Ende unter die letzte Windung führen',
                'Knoten festziehen',
            ],
            'Webeleinsteg auf Slip' => [
                'Webeleinsteg wie gewohnt legen',
                'Ende als Bucht statt fest durchziehen',
                'Bucht unter die Windung stecken',
                'Knoten zum schnellen Lösen festziehen',
            ],
            'Stopperstek' => [
                'Erste Törn um die Leine legen',
                'Zweite Törn in Zugrichtung legen',
                'Dritte Törn kreuzend darüberlegen',
                'Ende unter die letzte Windung führen',
                'Knoten festziehen',
            ],
            '1½ Rundtörn mit zwei halben Schlägen' => [
                'Anderthalb Rundtörns um Poller/Ring legen',
                'Ersten halben Schlag legen',
                'Zweiten halben Schlag legen',
                'Knoten festziehen',
            ],
            'Klampe belegen mit Kopfschlag' => [
                'Leine unter dem ersten Horn durchführen',
                'Erste Achterschlaufe um die Klampe legen',
                'Weitere Achterschlaufen legen',
                'Kopfschlag als Sicherung legen',
                'Leine festziehen',
            ],
        ];

        $chapterIndex = 0;
        foreach ($chapters as $title => $lessons) {
            $module = VideoModule::create([
                'course_id' => $course->id,
                'title' => $title,
                'sort_order' => ++$chapterIndex,
            ]);

            $lessonIndex = 0;
            foreach ($lessons as [$lessonTitle, $duration]) {
                $lesson = VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $lessonTitle,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => ++$lessonIndex,
                ]);

                if (isset($knotSteps[$lessonTitle])) {
                    foreach ($knotSteps[$lessonTitle] as $stepIndex => $stepTitle) {
                        VideoLessonStep::create([
                            'video_lesson_id' => $lesson->id,
                            'title' => $stepTitle,
                            'sort_order' => $stepIndex + 1,
                        ]);
                    }
                }
            }
        }
    }
}
