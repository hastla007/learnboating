<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Das Knotenpaket (siehe VideoCourseSeeder) ist keine SBF-See-spezifische
 * Fertigkeit -- dieselben Knoten gehören auch zur praktischen Ausbildung für
 * SBF Binnen. Dieser Seeder spiegelt das bestehende "Knoten"-Kapitel von
 * SBF See (inkl. Schritt-für-Schritt-Galerien) unter der eigenen Kurs-ID des
 * Binnen-Vollkurses, damit die "Knoten"-Kachel auch dort erscheint.
 */
class BinnenKnotenVideoSeeder extends Seeder
{
    private const MARKER = 'BinnenKnotenVideoSeeder:knoten';

    public function run(): void
    {
        $binnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $binnen || ! $sbfSee || DB::table('seed_marker')->where('key', self::MARKER)->exists()) {
            return;
        }

        // Bug-Review (Seeder legt umbenannte Kapitel erneut an): das Kapitel
        // wurde nur am Titel "Knoten" wiedererkannt -- ein vom Superadmin
        // umbenanntes oder gelöschtes Kapitel kopierte der nächste db:seed
        // erneut. Wie SeemannschaftVideoSeeder merkt sich der Seeder jetzt
        // dauerhaft (seed_marker), dass er gelaufen ist.
        if ($binnen->videoModules()->where('title', 'Knoten')->exists()) {
            DB::table('seed_marker')->insertOrIgnore(['key' => self::MARKER, 'seeded_at' => now()]);

            return;
        }

        $sourceModule = $sbfSee->videoModules()->with('lessons.steps')->where('title', 'Knoten')->first();

        if (! $sourceModule) {
            return;
        }

        $nextSortOrder = (int) $binnen->videoModules()->max('sort_order') + 1;

        $module = VideoModule::create([
            'course_id' => $binnen->id,
            'title' => $sourceModule->title,
            'sort_order' => $nextSortOrder,
        ]);

        foreach ($sourceModule->lessons as $sourceLesson) {
            $lesson = VideoLesson::create([
                'video_module_id' => $module->id,
                'title' => $sourceLesson->title,
                'video_url' => $sourceLesson->video_url,
                'duration_seconds' => $sourceLesson->duration_seconds,
                'sort_order' => $sourceLesson->sort_order,
            ]);

            foreach ($sourceLesson->steps as $sourceStep) {
                VideoLessonStep::create([
                    'video_lesson_id' => $lesson->id,
                    'title' => $sourceStep->title,
                    'sort_order' => $sourceStep->sort_order,
                ]);
            }
        }

        DB::table('seed_marker')->insertOrIgnore(['key' => self::MARKER, 'seeded_at' => now()]);
    }
}
