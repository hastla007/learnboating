<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\NavigationQuestion;
use App\Models\NavigationTask;
use Illuminate\Database\Seeder;

/**
 * Die 15 amtlichen Navigationsaufgaben für SBF See, importiert aus
 * database/data/sbf_see_navigationsaufgaben.csv (Quelle: ELWIS, jeweils
 * Fragenkatalog-See/Navigationsaufgaben/Navigationsaufgabe-XX). Jede Aufgabe
 * hat genau 9 Teilaufgaben mit amtlichem Ergebnis; einzelne Aufgaben tragen
 * zusätzlich einen Hinweis auf eine spätere amtliche Korrektur (z. B.
 * Verkehrsblatt-Berichtigung), der für alle ihre Teilaufgaben gleichermaßen
 * gilt.
 */
class NavigationTaskSeeder extends Seeder
{
    public function run(): void
    {
        $course = CourseDefinition::withoutGlobalScopes()->where('code', 'SBF-SEE')->whereNull('tenant_id')->first();

        if (! $course || $course->navigationTasks()->exists()) {
            return;
        }

        $csvPath = database_path('data/sbf_see_navigationsaufgaben.csv');
        if (! file_exists($csvPath)) {
            return;
        }

        $tasks = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $taskNumber = (int) $row['Navigationsaufgabe'];
            $questionNumber = (int) $row['Teilaufgabe'];

            $task = $tasks[$taskNumber] ??= NavigationTask::create([
                'course_id' => $course->id,
                'task_number' => $taskNumber,
                'scenario_text' => trim($row['Szenario']),
                'hint' => trim($row['Hinweis']) !== '' ? trim($row['Hinweis']) : null,
                'sort_order' => $taskNumber,
            ]);

            NavigationQuestion::create([
                'navigation_task_id' => $task->id,
                'question_number' => $questionNumber,
                'question_text' => trim($row['Aufgabenstellung']),
                'answer_text' => trim($row['Amtliches Ergebnis']),
                'sort_order' => $questionNumber,
            ]);
        }
    }

    /** @return iterable<array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            yield array_combine($header, $data);
        }

        fclose($handle);
    }
}
