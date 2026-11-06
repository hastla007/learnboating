<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\ExamBlueprint;
use App\Models\ExamRuleSet;
use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ergänzung SRC → UBI (ADD-UBI-TO-SRC) und Kombikurs SRC & UBI auf den
 * amtlichen Ergänzungskatalog beschränken: die 79 Fragen aus
 * ubi_ergaenzung_katalog.php bilden das Modul UBI_ERG (dieselben
 * content_question-Zeilen wie im Modul UBI, keine Kopien -- Lernfortschritt
 * und Revisionen bleiben gemeinsam).
 *
 * Bis Oktober 2026 hing der Kurs am vollständigen Modul UBI (130 Fragen),
 * weil keine amtliche Abgrenzung vorlag (siehe Blatt "Ergänzungskurs
 * Entscheidung" in zentrale_fragenbasis.xlsx: "bleibt der UBI-
 * Ergänzungskurs prüfungsorientiert vollständig"). Auf bestehenden
 * Installationen tauscht dieser Seeder deshalb das Modul UBI gegen UBI_ERG
 * und entfernt den Zufallsprüfungs-Blueprint auf UBI, damit
 * ExamRuleSetSeeder (läuft danach) ihn für UBI_ERG neu anlegt.
 *
 * Idempotent; muss nach content:import und CourseSeeder laufen.
 */
class UbiErgaenzungSeeder extends Seeder
{
    /**
     * Kurse, deren UBI-Teil auf den Ergänzungskatalog beschränkt ist: die
     * Ergänzung selbst und der Kombikurs SRC & UBI -- wer beide Scheine in
     * einem Prüfungsdurchlauf ablegt, schreibt SRC plus UBI-Ergänzungsbogen
     * (die vollen UBI-Bögen bleiben in dessen Prüfungsbögen zum Üben).
     */
    private const COURSES = ['ADD-UBI-TO-SRC', 'SRC-UBI'];

    public function run(): void
    {
        $catalog = include database_path('data/ubi_ergaenzung_katalog.php');
        $questionIds = ContentQuestion::where('content_role', 'ubi_official')->pluck('id', 'official_number');

        $missing = array_diff(array_map('strval', $catalog), $questionIds->keys()->all());
        if ($missing !== []) {
            $this->command?->warn('UBI-Ergänzungskatalog übersprungen: Katalogfragen '.implode(', ', $missing).' fehlen.');

            return;
        }

        DB::transaction(function () use ($catalog, $questionIds) {
            $module = Module::firstOrCreate(
                ['code' => 'UBI_ERG'],
                ['name' => 'UBI Ergänzung (für Inhaber eines Seefunkzeugnisses)', 'status' => 'published']
            );

            $pivot = [];
            foreach ($catalog as $ergNumber => $officialNumber) {
                $pivot[$questionIds[(string) $officialNumber]] = ['sort_order' => $ergNumber, 'required' => true];
            }
            $module->questions()->syncWithoutDetaching($pivot);

            $fullModule = Module::where('code', 'UBI')->first();

            foreach (self::COURSES as $code) {
                $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();

                if (! $course || ! $fullModule || ! $course->modules()->whereKey($fullModule->id)->exists()) {
                    continue;
                }

                $sortOrder = $course->modules()->whereKey($fullModule->id)->first()->pivot->sort_order;
                $course->modules()->detach($fullModule->id);
                $course->modules()->syncWithoutDetaching([$module->id => ['sort_order' => $sortOrder, 'required' => true]]);

                ExamBlueprint::whereIn('rule_set_id', ExamRuleSet::where('course_id', $course->id)->pluck('id'))
                    ->where('module_id', $fullModule->id)
                    ->delete();
            }
        });
    }
}
