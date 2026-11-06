<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    /**
     * Die sechs zentralen Inhaltsmodule laut Umsetzungskonzept, aus denen
     * sich alle elf Kursprodukte zusammensetzen.
     *
     * Bug-Review (Hoch, Folgefehler von CourseSeeder): dieser Seeder läuft
     * in Produktion erneut, sobald ein neues Modul im Code dazukommt --
     * updateOrCreate() überschrieb dabei bisher BEI JEDEM Lauf name/status
     * JEDES bereits existierenden Moduls. firstOrCreate() lässt ein
     * bereits existierendes Modul unberührt und erzeugt nur wirklich NEUE
     * Module mit den Startwerten.
     */
    public function run(): void
    {
        $modules = [
            ['code' => 'SBF_BASIS', 'name' => 'SBF Basis (gemeinsame Grundlagen)'],
            ['code' => 'SBF_SEE', 'name' => 'SBF See (spezifisch)'],
            ['code' => 'SBF_BINNEN', 'name' => 'SBF Binnen (spezifisch)'],
            ['code' => 'SBF_BINNEN_SEGELN', 'name' => 'SBF Binnen Segeln (Zusatzmodul)'],
            ['code' => 'SRC', 'name' => 'SRC Seefunk'],
            ['code' => 'UBI', 'name' => 'UBI Binnenfunk'],
            // Teilmenge von UBI (79 Fragen, amtlicher Ergänzungskatalog),
            // befüllt von UbiErgaenzungSeeder.
            ['code' => 'UBI_ERG', 'name' => 'UBI Ergänzung (für Inhaber eines Seefunkzeugnisses)'],
            ['code' => 'SKN', 'name' => 'SKN'],
            ['code' => 'FKN', 'name' => 'FKN'],
        ];

        foreach ($modules as $module) {
            Module::firstOrCreate(
                ['code' => $module['code']],
                ['name' => $module['name'], 'status' => 'published']
            );
        }
    }
}
