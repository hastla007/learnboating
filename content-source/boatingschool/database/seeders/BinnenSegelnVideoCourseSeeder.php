<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurs für die Kombiprüfung "SBF Binnen unter Segel" (SBF-BIN-SEGEL):
 * eigenständige Kapitelstruktur statt Spiegelung von SBF-BIN-MOTOR, da
 * Gliederung und Kapitelnamen bewusst anders aufgebaut sind (u.a. die drei
 * Segeln-spezifischen Kapitel 7-9). Die Video-URL ist bewusst ein
 * öffentliches Platzhaltervideo -- vor Produktivbetrieb durch echtes,
 * lizenziertes Kursmaterial der jeweiligen Bootsschule ersetzen.
 */
class BinnenSegelnVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-SEGEL')->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapters = [
            'Einstieg und Prüfungsüberblick' => [
                ['Was umfasst der SBF Binnen?', 142],
                ['Motor- und Segelprüfung', 156],
                ['So lernst du mit dem Kurs', 98],
            ],
            'Grundlagen und Sicherheit' => [
                ['Verantwortlicher Schiffsführer', 134],
                ['Begriffe und Fahrzustände', 178],
                ['Alkohol und Fahrtüchtigkeit', 121],
                ['Verhalten in Notfällen', 165],
            ],
            'Seemannschaft auf Binnengewässern' => [
                ['An- und Ablegen', 187],
                ['Ankern', 143],
                ['Schleusen', 211],
                ['Mensch über Bord', 176],
                ['Tauwerk und Ausrüstung', 152],
            ],
            'Verkehrsregeln und Ausweichen' => [
                ['Grundregeln', 168],
                ['Begegnung und Überholen', 194],
                ['Segelfahrzeuge im Verkehr', 149],
                ['Berufsschifffahrt und Kleinfahrzeuge', 171],
            ],
            'Schifffahrtszeichen und Signale' => [
                ['Tafeln und Zeichen', 203],
                ['Lichterführung', 189],
                ['Schallsignale', 132],
                ['Tonnen und Fahrwasser', 161],
            ],
            'Wetter und Umwelt' => [
                ['Wind und Wetterzeichen', 174],
                ['Gewitter und Starkwind', 158],
                ['Umweltschutz', 112],
                ['Verhalten bei schlechter Sicht', 139],
            ],
            'Segeln: Boot, Rigg und Stabilität' => [
                ['Jolle, Kielboot und Kimmkieler', 183],
                ['Form- und Gewichtsstabilität', 196],
                ['Segelteile und Tauwerk', 167],
                ['Ruder und Schwert', 144],
            ],
            'Segeln: Wind, Kurse und Trimm' => [
                ['Wahrer und scheinbarer Wind', 192],
                ['Amwind-, Halbwind- und Vorwindkurs', 205],
                ['Segeltrimm bei leichtem und starkem Wind', 178],
                ['Luv- und Leegierigkeit', 136],
            ],
            'Segelmanöver und besondere Situationen' => [
                ['Wenden und Halsen', 219],
                ['Böen und Krängung', 153],
                ['Patenthalse', 128],
                ['Ruderbruch', 117],
                ['Sicherheitsmaßnahmen bei Starkwind', 162],
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
                VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $lessonTitle,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => ++$lessonIndex,
                ]);
            }
        }
    }
}
