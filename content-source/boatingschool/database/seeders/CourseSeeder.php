<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\Module;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    /**
     * Die elf globalen Kursdefinitionen laut "Course Logic"-Blatt der
     * zentralen Fragenbasis. Global = tenant_id NULL: jede Bootsschule kann
     * daraus ihre Angebote zusammenstellen, ohne Content zu duplizieren.
     *
     * Bug-Review (Hoch): dieser Seeder läuft nicht nur einmal auf einer
     * frischen Installation, sondern wird in Produktion erneut ausgeführt,
     * sobald ein neues Kursprodukt im Code dazukommt (die einzige Art, wie
     * ein globaler Kurs hier entsteht). updateOrCreate() überschrieb dabei
     * bisher BEI JEDEM Lauf name/course_type/status JEDES bereits
     * existierenden Kurses auf genau diese festen Werte -- ein Kursstatus,
     * den ein Superadmin über CourseEditorController::update() z. B. auf
     * "archiviert" gesetzt hatte, sprang beim nächsten Reseed lautlos
     * zurück auf "published". syncWithoutDetaching() für die Module lief
     * ebenfalls immer erneut und FÜGTE jedes in der Liste oben genannte
     * Modul wieder an -- ein per detachModule() bewusst entferntes Modul
     * kam beim nächsten Reseed also ebenso lautlos zurück. firstOrCreate()
     * lässt einen bereits existierenden Kurs (und seine Modulzuordnungen)
     * vollständig unberührt und erzeugt nur wirklich NEUE Kurse mit den
     * Startwerten -- genau das, was ein Reseed für neue Kursprodukte
     * braucht, ohne spätere Superadmin-Änderungen an bereits vorhandenen
     * Kursen zu überschreiben.
     */
    public function run(): void
    {
        $courses = [
            ['code' => 'SBF-SEE', 'name' => 'SBF See', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_SEE']],
            ['code' => 'SBF-BIN-MOTOR', 'name' => 'SBF Binnen', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN']],
            ['code' => 'SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'type' => 'full', 'modules' => ['SBF_BASIS', 'SBF_BINNEN', 'SBF_BINNEN_SEGELN']],
            ['code' => 'SBF-SEE-BIN', 'name' => 'SBF See & Binnen', 'type' => 'combo', 'modules' => ['SBF_BASIS', 'SBF_SEE', 'SBF_BINNEN']],
            ['code' => 'ADD-SEE-TO-BIN', 'name' => 'Ergänzung See → Binnen', 'type' => 'addon', 'modules' => ['SBF_BINNEN']],
            ['code' => 'ADD-BIN-TO-SEE', 'name' => 'Ergänzung Binnen → See', 'type' => 'addon', 'modules' => ['SBF_SEE']],
            ['code' => 'ADD-BIN-SEGEL', 'name' => 'Ergänzung Binnen → Segeln', 'type' => 'addon', 'modules' => ['SBF_BINNEN_SEGELN']],
            ['code' => 'SRC', 'name' => 'SRC Vollkurs', 'type' => 'full', 'modules' => ['SRC']],
            ['code' => 'UBI', 'name' => 'UBI Vollkurs', 'type' => 'full', 'modules' => ['UBI']],
            ['code' => 'SRC-UBI', 'name' => 'SRC & UBI Kombikurs', 'type' => 'combo', 'modules' => ['SRC', 'UBI']],
            ['code' => 'ADD-UBI-TO-SRC', 'name' => 'Ergänzung SRC → UBI', 'type' => 'addon', 'modules' => ['UBI_ERG']],
            ['code' => 'SKN', 'name' => 'SKN Vollkurs', 'type' => 'full', 'modules' => ['SKN']],
            ['code' => 'FKN', 'name' => 'FKN Vollkurs', 'type' => 'full', 'modules' => ['FKN']],
            ['code' => 'ADD-FKN-TO-SKN', 'name' => 'Ergänzung FKN → SKN', 'type' => 'addon', 'modules' => ['SKN']],
        ];

        foreach ($courses as $definition) {
            $course = CourseDefinition::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => null, 'code' => $definition['code']],
                ['name' => $definition['name'], 'course_type' => $definition['type'], 'status' => 'published']
            );

            if (! $course->wasRecentlyCreated) {
                continue;
            }

            foreach (array_values($definition['modules']) as $index => $moduleCode) {
                $module = Module::where('code', $moduleCode)->firstOrFail();
                $course->modules()->syncWithoutDetaching([
                    $module->id => ['sort_order' => $index + 1, 'required' => true],
                ]);
            }
        }
    }
}
