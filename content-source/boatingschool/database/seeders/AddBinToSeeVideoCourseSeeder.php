<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\VideoLesson;
use App\Models\VideoLessonStep;
use App\Models\VideoModule;
use Database\Seeders\Concerns\SeedsLessonQuizOnce;
use Illuminate\Database\Seeder;

/**
 * Videokurs für den Ergänzungskurs "Binnen → See" (ADD-BIN-TO-SEE): die
 * SBF-See-spezifischen Kapitel aus SBF-SEE werden unter der eigenen
 * Kurs-ID des Ergänzungskurses gespiegelt (Video-/Fortschrittsdaten
 * hängen an course_id, nicht an course_module), inklusive der bereits
 * zugeordneten Quizfragen (video_lesson_question). Das Kapitel "Knoten"
 * entfällt, da es bereits Teil von SBF Binnen ist, das Voraussetzung für
 * diesen Ergänzungskurs ist.
 *
 * Bug-Review (Ergänzungskurs stellt Basisfragen außerhalb des Prüfungs-
 * umfangs): die Bestehensgrenze für "Ergänzung Binnen → See" prüft laut
 * Prüfungsordnung nur See (18/23) und Navigation (7/9) -- die Basis wurde
 * bereits bei SBF Binnen geprüft und ist hier kein Prüfungsgegenstand
 * mehr. Fragen aus dem Modul SBF_BASIS (z. B. in "Einführung in den
 * Sportbootführerschein"/"Seemannschaft") wurden trotzdem mitgespiegelt,
 * da Fragen hier pro Lektion wortgleich mit SBF-SEE kopiert wurden, ohne
 * ihr Modul zu berücksichtigen -- sie werden jetzt beim Spiegeln
 * ausgeschlossen.
 */
class AddBinToSeeVideoCourseSeeder extends Seeder
{
    use SeedsLessonQuizOnce;

    private const EXCLUDED_CHAPTERS = ['Knoten'];

    private const EXCLUDED_QUESTION_MODULE = 'SBF_BASIS';

    public function run(): void
    {
        $addon = CourseDefinition::withoutGlobalScopes()->where('code', 'ADD-BIN-TO-SEE')->whereNull('tenant_id')->first();
        $sbfSee = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $addon || ! $sbfSee || $addon->videoModules()->exists()) {
            return;
        }

        $sortOrder = 0;

        $sourceModules = $sbfSee->videoModules()
            ->whereNotIn('title', self::EXCLUDED_CHAPTERS)
            ->with(['lessons.steps', 'lessons.questions.modules'])
            ->get();

        foreach ($sourceModules as $sourceModule) {
            $sortOrder++;
            $module = VideoModule::create([
                'course_id' => $addon->id,
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
                        'image_path' => $sourceStep->image_path,
                        'sort_order' => $sourceStep->sort_order,
                    ]);
                }

                foreach ($sourceLesson->questions as $sourceQuestion) {
                    if ($sourceQuestion->modules->pluck('code')->contains(self::EXCLUDED_QUESTION_MODULE)) {
                        continue;
                    }

                    $lesson->questions()->attach($sourceQuestion->id, [
                        'sort_order' => $sourceQuestion->pivot->sort_order,
                    ]);
                }

                // Bug-Review (Seeder-Idempotenz Videoquiz): kopiertes Quiz
                // gilt als geseedet, damit kein *LessonQuestionSeeder es nach
                // einem Leeren durch den Superadmin erneut befüllt.
                if ($lesson->questions()->exists()) {
                    self::markQuizSeeded($lesson);
                }
            }
        }
    }
}
