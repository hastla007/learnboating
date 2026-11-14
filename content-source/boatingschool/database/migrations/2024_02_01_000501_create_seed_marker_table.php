<?php

use Database\Seeders\SeemannschaftVideoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug-Review (Seeder-Idempotenz Videolektionen): SeemannschaftVideoSeeder
 * ergänzt SBF See um Kapitel/Lektionen und erkannte sie bei jedem
 * db:seed-Lauf nur am TITEL wieder -- eine vom Superadmin umbenannte oder
 * gelöschte Lektion wurde dadurch erneut angelegt (samt Quiz). seed_marker
 * merkt sich dauerhaft, welche Einträge ein Seeder schon einmal angelegt
 * hat (Schlüssel siehe SeemannschaftVideoSeeder::chapterKey()/lessonKey()).
 * Nur die Owner-Verbindung (Migrationen/Seeder) liest und schreibt die
 * Tabelle, die App-Rolle bekommt bewusst keine Rechte.
 *
 * Backfill: hat SeemannschaftVideoSeeder auf dieser Installation schon
 * einmal gelaufen (mindestens eines seiner Kapitel/eine seiner Lektionen
 * existiert), legte er damals ALLE unten eingefrorenen Einträge in einem
 * Lauf an -- alle gelten als geseedet, auch die inzwischen umbenannten
 * oder gelöschten, die sich am Titel nicht mehr finden lassen.
 */
return new class extends Migration
{
    /** Stand von SeemannschaftVideoSeeder bei Einführung dieser Migration (eingefroren). */
    private const NEW_CHAPTERS = ['Berufsschifffahrt', 'Leuchtfeuer und Tonnen'];

    /** @var array<string, string[]> Kapitel => Lektionen (eingefroren). */
    private const EXTRA_LESSONS = [
        'Seemannschaft' => ['Ankern', 'Feuer an Bord', 'Nebel', 'Schleppen und Schleusen', 'Seeunfall und Seenotsignale'],
        'Wetterkunde' => ['Entstehung des Wetters', 'Der Wind', 'Wetterkarten'],
        'Ausweichregeln' => ['Ausweichregeln für alle Fahrzeuge', 'Ausweichregeln für Maschinenfahrzeuge', 'Ausweichregeln für Segelfahrzeuge'],
        'Berufsschifffahrt' => ['Seeverkehrsrecht', 'Seefunk', 'Verkehrstrennungsgebiete', 'Schallsignale'],
        'Lichter und Signale' => ['Spezialfälle', 'Signalkörper und Flaggen'],
        'Leuchtfeuer und Tonnen' => ['Feuerarten und Kennungen', 'Sektorenfeuer', 'Fahrwasser', 'Besondere Tonnen'],
    ];

    public function up(): void
    {
        Schema::create('seed_marker', function (Blueprint $table) {
            $table->string('key', 255)->primary();
            $table->timestampTz('seeded_at')->useCurrent();
        });

        if ($this->seemannschaftSeederHasRun()) {
            $keys = array_map(fn (string $chapter) => SeemannschaftVideoSeeder::chapterKey($chapter), self::NEW_CHAPTERS);

            foreach (self::EXTRA_LESSONS as $chapter => $lessons) {
                foreach ($lessons as $lesson) {
                    $keys[] = SeemannschaftVideoSeeder::lessonKey($chapter, $lesson);
                }
            }

            DB::table('seed_marker')->insertOrIgnore(array_map(fn (string $key) => ['key' => $key, 'seeded_at' => now()], $keys));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_marker');
    }

    private function seemannschaftSeederHasRun(): bool
    {
        $courseId = DB::table('course_definition')->where('code', 'SBF-SEE')->whereNull('tenant_id')->value('id');

        if (! $courseId) {
            return false;
        }

        if (DB::table('video_module')->where('course_id', $courseId)->whereIn('title', self::NEW_CHAPTERS)->exists()) {
            return true;
        }

        foreach (self::EXTRA_LESSONS as $chapter => $lessons) {
            $found = DB::table('video_lesson')
                ->join('video_module', 'video_module.id', '=', 'video_lesson.video_module_id')
                ->where('video_module.course_id', $courseId)
                ->where('video_module.title', $chapter)
                ->whereIn('video_lesson.title', $lessons)
                ->exists();

            if ($found) {
                return true;
            }
        }

        return false;
    }
};
