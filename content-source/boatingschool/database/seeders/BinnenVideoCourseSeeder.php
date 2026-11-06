<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use App\Support\ComboVideoGroups;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den eigenständigen Vollkurs "SBF Binnen" (SBF-BIN-MOTOR):
 * die SBF-Binnen-Kapitel wurden bisher nur unter der Kurs-ID des Kombikurses
 * "SBF See & Binnen" gepflegt (siehe ComboVideoCourseSeeder). Hier werden
 * dieselben Kapitel unter der eigenen Kurs-ID des Binnen-Vollkurses
 * gespiegelt, damit der Kurs auch einzeln buchbar denselben Videokurs zeigt.
 */
class BinnenVideoCourseSeeder extends Seeder
{
    public function run(): void
    {
        $binnen = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-BIN-MOTOR')->whereNull('tenant_id')->first();
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();

        if (! $binnen || ! $combo || $binnen->videoModules()->exists()) {
            return;
        }

        $binnenChapterTitles = collect(ComboVideoGroups::GROUPS['SBF-SEE-BIN'] ?? [])
            ->firstWhere('label', 'SBF Binnen')['chapters'] ?? [];

        $sourceModules = $combo->videoModules()->with('lessons.steps')
            ->whereIn('title', $binnenChapterTitles)
            ->orderBy('sort_order')
            ->get();

        $sortOrder = 0;
        foreach ($sourceModules as $sourceModule) {
            $sortOrder++;
            $module = VideoModule::create([
                'course_id' => $binnen->id,
                'title' => $sourceModule->title,
                'sort_order' => $sortOrder,
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
        }
    }
}
