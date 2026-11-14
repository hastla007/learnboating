<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Database\Seeder;

/**
 * Navigationsaufgaben für den Kombikurs "SBF See & Binnen" und die
 * Ergänzung Binnen -> See (zur Ergänzungsprüfung gehört die amtliche
 * Navigationsaufgabe wie beim SBF See): wie beim Videokurs (siehe
 * ComboVideoCourseSeeder) hängen Navigationsaufgaben am course_id des
 * jeweiligen Kurses, nicht an den Kursmodulen. Damit die
 * "Navigationsaufgaben"-Kachel dort erscheint, werden SBF-Sees amtliche
 * Aufgaben unter der eigenen Kurs-ID gespiegelt.
 */
class ComboNavigationTaskSeeder extends Seeder
{
    private const TARGETS = ['SBF-SEE-BIN', 'ADD-BIN-TO-SEE'];

    public function run(): void
    {
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $sbfSee) {
            return;
        }

        foreach (self::TARGETS as $code) {
            $target = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();

            if ($target && ! $target->navigationTasks()->exists()) {
                $this->mirror($sbfSee, $target);
            }
        }
    }

    private function mirror(CourseDefinition $sbfSee, CourseDefinition $target): void
    {
        foreach ($sbfSee->navigationTasks()->with('questions')->get() as $sourceTask) {
            $task = NavigationTask::create([
                'course_id' => $target->id,
                'task_number' => $sourceTask->task_number,
                'scenario_text' => $sourceTask->scenario_text,
                'hint' => $sourceTask->hint,
                'sort_order' => $sourceTask->sort_order,
            ]);

            foreach ($sourceTask->questions as $sourceQuestion) {
                NavigationQuestion::create([
                    'navigation_task_id' => $task->id,
                    'question_number' => $sourceQuestion->question_number,
                    'question_text' => $sourceQuestion->question_text,
                    'answer_text' => $sourceQuestion->answer_text,
                    'sort_order' => $sourceQuestion->sort_order,
                ]);
            }
        }
    }
}
