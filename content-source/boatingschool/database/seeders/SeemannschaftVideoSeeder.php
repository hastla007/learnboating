<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Einzelne Kapitel von SBF See hatten bisher weniger Lektionen, als es für
 * das "Zu den Fragen"-Quiz (siehe VideoLessonQuestionSeeder) amtliche Fragen
 * in der zentralen Fragenbasis gibt. Ergänzt die fehlenden Lektionen mit
 * einem öffentlichen Platzhaltervideo, bis echtes, lizenziertes
 * Kursmaterial vorliegt -- wie bei den übrigen Kapiteln. NEW_CHAPTERS legt
 * dafür ggf. fehlende Kapitel direkt vor einem bestehenden Referenzkapitel an.
 *
 * Bug-Review (Seeder-Idempotenz Videolektionen): db:seed läuft auf
 * bestehenden Installationen erneut. Kapitel und Lektionen wurden bisher
 * nur über ihren TITEL wiedererkannt -- eine vom Superadmin umbenannte
 * (oder gelöschte) Lektion legte der nächste Lauf deshalb erneut an, samt
 * Quiz durch VideoLessonQuestionSeeder. Jetzt merkt sich seed_marker
 * (Migration 2024_02_01_000501) dauerhaft je Kapitel/Lektion, dass dieser
 * Seeder sie schon einmal angelegt hat; danach fasst er sie nie wieder an,
 * egal wie sie inzwischen heißen. Neu hinzugefügte Einträge in
 * NEW_CHAPTERS/EXTRA_LESSONS werden weiterhin einmalig angelegt.
 */
class SeemannschaftVideoSeeder extends Seeder
{
    private const PLACEHOLDER_VIDEO = 'https://www.w3schools.com/html/mov_bbb.mp4';

    /** @var array<int, array{0: string, 1: string}> [Neues Kapitel, Titel des Kapitels davor]. */
    private const NEW_CHAPTERS = [
        ['Berufsschifffahrt', 'Ausweichregeln'],
        ['Leuchtfeuer und Tonnen', 'Lichter und Signale'],
    ];

    /** @var array<string, array<int, array{0: string, 1: int}>> Kapitel => [Lektion, Dauer in Sekunden]. */
    private const EXTRA_LESSONS = [
        'Seemannschaft' => [
            ['Ankern', 139],
            ['Feuer an Bord', 76],
            ['Nebel', 86],
            ['Schleppen und Schleusen', 244],
            ['Seeunfall und Seenotsignale', 207],
        ],
        'Wetterkunde' => [
            ['Entstehung des Wetters', 152],
            ['Der Wind', 123],
            ['Wetterkarten', 187],
        ],
        'Ausweichregeln' => [
            ['Ausweichregeln für alle Fahrzeuge', 378],
            ['Ausweichregeln für Maschinenfahrzeuge', 87],
            ['Ausweichregeln für Segelfahrzeuge', 124],
        ],
        'Berufsschifffahrt' => [
            ['Seeverkehrsrecht', 289],
            ['Seefunk', 194],
            ['Verkehrstrennungsgebiete', 87],
            ['Schallsignale', 110],
        ],
        'Lichter und Signale' => [
            ['Spezialfälle', 454],
            ['Signalkörper und Flaggen', 206],
        ],
        'Leuchtfeuer und Tonnen' => [
            ['Feuerarten und Kennungen', 249],
            ['Sektorenfeuer', 101],
            ['Fahrwasser', 224],
            ['Besondere Tonnen', 158],
        ],
    ];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        $this->createMissingChapters($sbfSee);

        foreach (self::EXTRA_LESSONS as $chapterTitle => $lessons) {
            $pending = array_filter($lessons, fn (array $lesson) => ! self::isSeeded(self::lessonKey($chapterTitle, $lesson[0])));

            if ($pending === []) {
                continue;
            }

            $chapter = $sbfSee->videoModules()->where('title', $chapterTitle)->first();

            if (! $chapter) {
                continue;
            }

            $nextSortOrder = (int) $chapter->lessons()->max('sort_order');

            foreach ($pending as [$title, $duration]) {
                if (! $chapter->lessons()->where('title', $title)->exists()) {
                    $nextSortOrder++;
                    VideoLesson::create([
                        'video_module_id' => $chapter->id,
                        'title' => $title,
                        'video_url' => self::PLACEHOLDER_VIDEO,
                        'duration_seconds' => $duration,
                        'sort_order' => $nextSortOrder,
                    ]);
                }

                self::markSeeded(self::lessonKey($chapterTitle, $title));
            }
        }
    }

    public static function chapterKey(string $chapterTitle): string
    {
        return "SeemannschaftVideoSeeder|SBF-SEE|Kapitel|{$chapterTitle}";
    }

    public static function lessonKey(string $chapterTitle, string $lessonTitle): string
    {
        return "SeemannschaftVideoSeeder|SBF-SEE|Lektion|{$chapterTitle}|{$lessonTitle}";
    }

    private static function isSeeded(string $key): bool
    {
        return DB::table('seed_marker')->where('key', $key)->exists();
    }

    private static function markSeeded(string $key): void
    {
        DB::table('seed_marker')->insertOrIgnore(['key' => $key, 'seeded_at' => now()]);
    }

    private function createMissingChapters(CourseDefinition $sbfSee): void
    {
        foreach (self::NEW_CHAPTERS as [$chapterTitle, $beforeTitle]) {
            if (self::isSeeded(self::chapterKey($chapterTitle))) {
                continue;
            }

            if (! $sbfSee->videoModules()->where('title', $chapterTitle)->exists()) {
                $before = $sbfSee->videoModules()->where('title', $beforeTitle)->first();

                if (! $before) {
                    continue;
                }

                $sbfSee->videoModules()
                    ->where('sort_order', '>', $before->sort_order)
                    ->increment('sort_order');

                VideoModule::create([
                    'course_id' => $sbfSee->id,
                    'title' => $chapterTitle,
                    'sort_order' => $before->sort_order + 1,
                ]);
            }

            self::markSeeded(self::chapterKey($chapterTitle));
        }
    }
}
