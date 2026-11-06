<?php

use App\Models\CourseDefinition;
use Illuminate\Database\Migrations\Migration;

/**
 * Review-Runde 3 (Daten): auf aktualisierten Installationen blieben im
 * Kombikurs SBF See & Binnen gespiegelte Lektionen ohne Quizfragen (z. B.
 * „Berufsschifffahrt › Schallsignale“), weil der Spiegel-Seeder sie zu einem
 * Zeitpunkt kopierte, an dem die SBF-See-Lektion selbst noch leer war, und
 * später nie wieder läuft (ComboVideoCourseSeeder bricht ab, sobald der
 * Kombikurs Module hat). Gefüllt werden nur Lektionen, deren Quiz noch nie
 * geseedet wurde und die keine Fragen haben -- vom Superadmin gepflegte
 * oder bewusst geleerte Quizze (quiz_seeded_at gesetzt) bleiben unberührt.
 * Auf einer frischen Installation (noch keine Kurse) tut sie nichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $combo = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE-BIN')->whereNull('tenant_id')->first();
        $see = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();
        if (! $combo || ! $see) {
            return;
        }

        $sourceModules = $see->videoModules()->with('lessons.questions')->get()->keyBy('title');

        foreach ($combo->videoModules()->with('lessons')->get() as $module) {
            $source = $sourceModules->get($module->title);
            if (! $source) {
                continue;
            }

            foreach ($module->lessons as $lesson) {
                if ($lesson->quiz_seeded_at !== null || $lesson->questions()->exists()) {
                    continue;
                }
                $sourceLesson = $source->lessons->firstWhere('title', $lesson->title);
                if (! $sourceLesson || $sourceLesson->questions->isEmpty()) {
                    continue;
                }

                foreach ($sourceLesson->questions as $question) {
                    $lesson->questions()->attach($question->id, ['sort_order' => $question->pivot->sort_order]);
                }
                $lesson->forceFill(['quiz_seeded_at' => now()])->save();
            }
        }
    }

    public function down(): void
    {
        // Datenkorrektur, kein Zurückrollen.
    }
};
