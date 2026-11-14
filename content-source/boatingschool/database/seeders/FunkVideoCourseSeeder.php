<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Videokurse für die Funkscheine: SRC, UBI, die UBI-Ergänzung für
 * SRC-Inhaber und der Kombikurs SRC & UBI (Teilkurs-Kacheln über
 * App\Support\ComboVideoGroups). Wie bei FknSknVideoCourseSeeder eine
 * gemeinsame Videobibliothek, die je Kurs in eigenen
 * video_module/video_lesson-Datensätzen eingesetzt wird; bis zur
 * Produktion zeigen alle Lektionen PLACEHOLDER_VIDEO. Die Kapitel sind
 * didaktisch gegliedert (Einführung, Technik, Routine-, Not-, Dringlichkeits-
 * und Sicherheitsverkehr, Prüfung); die Kapiteltitel sind über SRC und UBI
 * hinweg eindeutig, damit ComboVideoGroups sie dem richtigen Teilkurs
 * zuordnen kann.
 */
class FunkVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapitel => [[Lektion, Dauer in Sekunden], ...]. */
    public const SRC_CHAPTERS = [
        'Einführung in das SRC' => [
            ['Willkommen zum Short Range Certificate', 74],
            ['GMDSS: das weltweite Seenot- und Sicherheitsfunksystem', 412],
            ['Seegebiete A1 bis A4', 268],
            ['Funkzeugnisse: wer braucht welches?', 305],
            ['Warum das Handy auf See nicht reicht', 196],
        ],
        'Technik und Funkgerät' => [
            ['UKW-Seefunk: Reichweite und Ausbreitung', 347],
            ['Kanäle und Frequenzen', 421],
            ['Kanal 16, 70 und Arbeitskanäle', 289],
            ['Bedienelemente der UKW-Seefunkanlage', 503],
            ['Antenne, Stromversorgung und Sendeleistung', 318],
            ['MMSI und der DSC-Controller', 446],
        ],
        'Routineverkehr im Alltag' => [
            ['Sprechregeln und Buchstabiertafel', 392],
            ['Rechtliche Regeln im Seefunk', 274],
            ['DSC-Routineanruf von Schiff zu Schiff', 461],
            ['Revier- und Hafenfunk', 337],
            ['Funkprobe und Testanruf', 182],
        ],
        'Notverkehr – MAYDAY' => [
            ['Wann ist es ein Notfall? Die MAYDAY-Meldung', 498],
            ['DSC-Notalarm auslösen', 367],
            ['Zeitangaben in UTC', 162],
            ['Wer hilft? MRCC und Küstenfunkstellen', 374],
            ['Notmeldung empfangen – was jetzt?', 326],
            ['MAYDAY RELAY: Notmeldung weiterleiten', 281],
            ['SEELONCE MAYDAY: Funkstille im Notverkehr', 438],
            ['SAR: Rettung aus der Luft', 157],
            ['SEELONCE FEENEE: Ende des Notverkehrs', 229],
            ['Fehlalarm richtig aufheben', 244],
            ['EPIRB: Notalarm über Satellit', 316],
            ['SART und AIS-SART: gefunden werden', 223],
        ],
        'Dringlichkeit und Sicherheit – PAN PAN und SECURITE' => [
            ['PAN PAN: die Dringlichkeitsmeldung', 583],
            ['Funkärztliche Beratung (MEDICO)', 297],
            ['Rangfolge im Seefunk', 248],
            ['SECURITE: Gefahren richtig melden', 412],
            ['Vorrang: wann du trotz Funkstille senden darfst', 263],
            ['MSI: nautische Warnnachrichten für Skipper', 476],
            ['NAVTEX: Warnungen ohne Internet', 618],
        ],
        'Zulassung und Prüfungsvorbereitung' => [
            ['Zulassung und Anmeldung zur Prüfung', 232],
            ['Nach der Prüfung: Zuteilungsurkunde und MMSI', 268],
            ['Ablauf der SRC-Prüfung', 214],
            ['Praktische Prüfung am Funkgerät', 387],
            ['Englische Seefunktexte und Vokabeln', 341],
        ],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapiteltitel eindeutig gegenüber SRC_CHAPTERS. */
    public const UBI_CHAPTERS = [
        'Einführung in das UBI' => [
            ['Willkommen zum UKW-Sprechfunkzeugnis für den Binnenschifffahrtsfunk', 61],
            ['Die Binnenschifffahrtsstraßen und das RAINWAT', 503],
            ['Binnenfunk: wer funkt, was ist erlaubt?', 527],
        ],
        'Technik: Kanäle und Funkanlage' => [
            ['Kanäle im Binnenschifffahrtsfunk', 417],
            ['Die Funkanlage: ATIS, Sendeleistung und Bedienung', 391],
        ],
        'Verkehrskreise und Reviere im Alltag' => [
            ['Die Verkehrskreise im Binnenfunk', 514],
            ['Funkstellen: wer gehört in welchen Verkehrskreis?', 287],
            ['Nautische Information im Revieralltag', 421],
            ['Rangfolge der Meldungen', 473],
        ],
        'Notverkehr im Binnenfunk – MAYDAY' => [
            ['Sprechregeln, Buchstabiertafel und Anruf', 588],
            ['Notfall auf dem Binnenrevier: die Notmeldung (MAYDAY)', 451],
            ['Notmeldungen richtig bestätigen', 309],
            ['MAYDAY RELAY: die Rettungskette weitergeben', 357],
            ['Notverkehr offiziell beenden', 201],
        ],
        'Dringlichkeit und Sicherheit im Binnenfunk' => [
            ['PAN PAN: Dringlichkeitsverkehr sicher abwickeln', 398],
            ['SECURITE: Gefahren im Funk richtig melden', 373],
        ],
        'UBI-Abschluss und Prüfungsvorbereitung' => [
            ['Zusammenfassung: alles Wichtige für die Prüfung', 280],
            ['Funkpraxis: Textaufnahme, Textabgabe und Funkgespräche', 711],
        ],
    ];

    public function run(): void
    {
        $this->buildCourse('SRC', self::SRC_CHAPTERS);
        $this->buildCourse('UBI', self::UBI_CHAPTERS);
        $this->buildCourse('ADD-UBI-TO-SRC', self::UBI_CHAPTERS);
        $this->buildCourse('SRC-UBI', self::SRC_CHAPTERS + self::UBI_CHAPTERS);
    }

    /** @param array<string, array<int, array{0: string, 1: int}>> $chapters */
    private function buildCourse(string $courseCode, array $chapters): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapterIndex = 0;
        foreach ($chapters as $chapterTitle => $lessons) {
            $module = VideoModule::create([
                'course_id' => $course->id,
                'title' => $chapterTitle,
                'sort_order' => ++$chapterIndex,
            ]);

            foreach ($lessons as $lessonIndex => [$title, $duration]) {
                VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $title,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => $lessonIndex + 1,
                ]);
            }
        }
    }
}
