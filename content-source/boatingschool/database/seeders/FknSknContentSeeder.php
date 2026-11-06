<?php

namespace Database\Seeders;

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Importiert die FKN- und SKN-Fragenkataloge (siehe fkn_catalog.php /
 * skn_catalog.php) -- anders als die zentrale Fragenbasis (SBF/SRC/UBI,
 * content:import aus zentrale_fragenbasis.xlsx) liegen diese beiden
 * Kataloge nicht im xlsx-Format vor, da sie multi_choice- und
 * open_text-Fragen mischen, die das xlsx-Schema (ein correct_answer pro
 * Zeile) nicht abbildet.
 */
class FknSknContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->importCatalog('fkn_catalog.php', 'fkn_official', 'FKN', 'FKN');
        $this->importCatalog('skn_catalog.php', 'skn_official', 'SKN', 'SKN');
    }

    private function importCatalog(string $file, string $contentRole, string $idPrefix, string $moduleCode): void
    {
        $catalog = include database_path('data/'.$file);
        $module = Module::where('code', $moduleCode)->firstOrFail();

        foreach ($catalog as $number => $entry) {
            $contentId = sprintf('%s-%04d', $idPrefix, $number);

            $question = ContentQuestion::firstOrCreate(
                ['content_id' => $contentId],
                [
                    'official_number' => (string) $number,
                    'content_role' => $contentRole,
                    'language' => 'de-DE',
                    'active' => true,
                ]
            );

            if ($question->revisions()->exists()) {
                $module->questions()->syncWithoutDetaching([$question->id => ['required' => true]]);

                continue;
            }

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => 1,
                'question_type' => $entry['type'],
                'question_text' => $entry['q'],
                'source_catalog' => $idPrefix === 'FKN'
                    ? 'FKN-Fragenkatalog mit Antworten (PANord), Stand 01/2020'
                    : 'Fragenkatalog für die Sachkundeprüfung gem. § 7 WaffG (Bundesverwaltungsamt), Kapitel IV, Stand 13.07.2018',
                'source_version' => $idPrefix === 'FKN' ? '2020-01' : '2018-07',
                'editorial_status' => 'published',
                'rights_status' => 'review',
            ]);

            if ($entry['type'] === 'open_text') {
                ContentAnswer::create([
                    'revision_id' => $revision->id,
                    'answer_key' => 'MODEL',
                    'answer_text' => $entry['a'],
                    'is_correct' => true,
                    'sort_order' => 1,
                ]);
            } else {
                foreach ($entry['options'] as $i => $option) {
                    ContentAnswer::create([
                        'revision_id' => $revision->id,
                        'answer_key' => chr(ord('A') + $i),
                        'answer_text' => $option['t'],
                        'is_correct' => $option['c'],
                        'sort_order' => $i + 1,
                    ]);
                }
            }

            $module->questions()->syncWithoutDetaching([$question->id => ['required' => true]]);
        }
    }
}
