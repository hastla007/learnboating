<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den Ergänzungskurs "Binnen → Segeln" (ADD-BIN-SEGEL): nur
 * der Segelteil für Inhaber eines SBF Binnen unter Motor, passend zu den
 * 47 spezifischen Segelfragen (ELWIS-Nr. 254-300) -- Basis- und
 * Binnen-Themen werden nicht erneut behandelt. Eigenständige, kürzere
 * Kapitelstruktur statt Spiegelung des SBF-BIN-SEGEL-Videokurses, da
 * dessen Kapitel 1-6 (Einstieg, Grundlagen, Seemannschaft,
 * Verkehrsregeln, Zeichen, Wetter) für diese Zielgruppe bereits
 * abgedeckt sind. Die Video-URL ist bewusst ein öffentliches
 * Platzhaltervideo -- vor Produktivbetrieb durch echtes, lizenziertes
 * Kursmaterial der jeweiligen Bootsschule ersetzen.
 */
class BinnenSegelnAddonVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-BIN-SEGEL')->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapters = [
            'Segelboot und Stabilität' => [
                ['Bootstypen und Kimmkieler', 158],
                ['Form- und Gewichtsstabilität', 189],
                ['Jolle und Kielyacht', 146],
                ['Ruderbruch und Pinnenausleger', 124],
            ],
            'Rigg, Segel und Bordpraxis' => [
                ['Tauwerk und Fallen', 137],
                ['Ecken und Kanten des Segels', 112],
                ['Segellatten', 94],
                ['Segelpflege und Killen', 128],
            ],
            'Wind und Segelkurse' => [
                ['Wahrer und scheinbarer Wind', 192],
                ['Wind auf Amwind- und Vorwindkursen', 174],
                ['Böen und Winddreher', 141],
            ],
            'Schwert, Krängung und Segeltrimm' => [
                ['Schwertstellung und Abdrift', 163],
                ['Krängung und Luvgierigkeit', 178],
                ['Segeltrimm bei leichtem und starkem Wind', 181],
                ['Holepunkt und Unterliekstrecker', 149],
            ],
            'Manöver und Notfälle unter Segel' => [
                ['Wende, Halse und Q-Wende', 206],
                ['Boot an der Boje', 118],
                ['Patenthalse', 128],
                ['Want- oder Vorstagbruch', 135],
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
