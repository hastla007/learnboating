<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;

/**
 * Gemeinsame Videobibliothek für FKN, SKN und den neuen Ergänzungskurs
 * "FKN → SKN" (ADD-FKN-TO-SKN): nur 8 Videos produzieren, die je nach
 * Kurs in unterschiedlichen Kapiteln und Teilmengen eingesetzt werden,
 * statt separates Material je Kurs zu drehen. Jeder Kurs bekommt
 * trotzdem eigene video_module/video_lesson-Datensätze (Lektionen
 * gehören schematisch zu genau einem Kurs), aber alle referenzieren
 * dieselben 8 Videoinhalte/-URLs -- bei echtem Produktivbetrieb reicht
 * es, die PLACEHOLDER_VIDEO-URL je Video einmal durch das tatsächliche
 * Video zu ersetzen.
 *
 * Kurswege:
 * - FKN Vollkurs: Videos 1-6.
 * - SKN Vollkurs: Videos 1-8.
 * - Ergänzung FKN → SKN: Videos 7-8 (Pflicht) + Videos 2, 5, 6 als
 *   freiwillige Auffrischung in einem eigenen, klar als optional
 *   gekennzeichneten Kapitel.
 */
class FknSknVideoCourseSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<int, array{0: string, 1: int}> Video-Nr. => [Titel, Dauer in Sekunden]. */
    private const VIDEOS = [
        1 => ['Überblick: FKN, SKN und Prüfungswege', 145],
        2 => ['Rechtliche Grundlagen und Begriffe', 168],
        3 => ['Signalmittel erkennen und unterscheiden', 192],
        4 => ['Sicherer Einsatz von Raketen, Handfackeln und Rauchsignalen', 221],
        5 => ['Kennzeichnung, Haltbarkeit, Aufbewahrung und Entsorgung', 176],
        6 => ['Versager und praktische Prüfung mit FKN-Signalmitteln', 203],
        7 => ['SKN-Zusatz: Waffenrecht, Signalpistole und Munition', 234],
        8 => ['SKN-Zusatz: praktische Prüfungsvorbereitung Signalpistole', 198],
    ];

    public function run(): void
    {
        $this->buildCourse('FKN', [
            'Grundlagen' => [1, 2],
            'Signalmittel in der Praxis' => [3, 4, 5, 6],
        ]);

        $this->buildCourse('SKN', [
            'Grundlagen' => [1, 2],
            'Signalmittel in der Praxis' => [3, 4, 5, 6],
            'SKN: Waffenrecht und Signalpistole' => [7, 8],
        ]);

        $this->buildCourse('ADD-FKN-TO-SKN', [
            'SKN: Waffenrecht und Signalpistole' => [7, 8],
            'Freiwillige Auffrischung (optional)' => [2, 5, 6],
        ]);
    }

    /** @param array<string, int[]> $chapters Kapiteltitel => Video-Nummern. */
    private function buildCourse(string $courseCode, array $chapters): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', $courseCode)->whereNull('tenant_id')->first();

        if (! $course || $course->videoModules()->exists()) {
            return;
        }

        $chapterIndex = 0;
        foreach ($chapters as $chapterTitle => $videoNumbers) {
            $module = VideoModule::create([
                'course_id' => $course->id,
                'title' => $chapterTitle,
                'sort_order' => ++$chapterIndex,
            ]);

            $lessonIndex = 0;
            foreach ($videoNumbers as $videoNumber) {
                [$title, $duration] = self::VIDEOS[$videoNumber];

                VideoLesson::create([
                    'video_module_id' => $module->id,
                    'title' => $title,
                    'video_url' => self::PLACEHOLDER_VIDEO,
                    'duration_seconds' => $duration,
                    'sort_order' => ++$lessonIndex,
                ]);
            }
        }
    }
}
