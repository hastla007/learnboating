<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review BB11: Beim ursprünglichen Import des zentralen Fragenkatalogs
 * blieben an mehreren Stellen zwei (an einer Stelle drei) Leerzeichen
 * hintereinander stehen, meist dort, wo im Quell-PDF ein Zeilenumbruch
 * oder ein nicht übernommenes Zeichen lag. Diese Migration führt dafür
 * eine automatisierte, verifizierbar risikofreie Bereinigung durch:
 * mehrere aufeinanderfolgende Leerzeichen/Tabs (keine Zeilenumbrüche)
 * werden auf genau eines reduziert. Das ändert nie die Bedeutung eines
 * deutschen Fließtexts und betrifft -- geprüft per Sweep über den
 * gesamten veröffentlichten Katalog -- ausschließlich SRC-0003 sowie 19
 * UBI-Fragen.
 *
 * Wie bei BB8/BB9/BB10 legt die Migration pro betroffener Frage eine
 * neue Revision an -- beschränkt auf genau die Fragen, die der Sweep
 * tatsächlich als betroffen identifiziert hat.
 *
 * Bug-Review (Korrektur nur per Migration): Migrationen laufen vor
 * db:seed -- auf einer Neuinstallation fanden sie keine Fragen vor, und der
 * anschließende Import brachte die fehlerhaften Texte zurück. Die
 * Korrektur steht deshalb jetzt auch in der Quelle (zentrale_fragenbasis.xlsx,
 * fkn_catalog.php, Erklärungs-CSVs); diese Migration holt sie nur für
 * bestehende Installationen nach. Die neue Revision ist NICHT als
 * edited_by_superadmin markiert: ihr Text entspricht der korrigierten
 * Quelle, ein späterer content:import sieht sie als unverändert, und
 * amtliche Katalogkorrekturen (CatalogUpdateSeeder, z. B. SRC/UBI
 * "korr2") werden nicht mehr blockiert.
 */
return new class extends Migration
{
    private function affectedContentIds(): array
    {
        return [
            'SRC-0003',
            'UBI-0016', 'UBI-0046', 'UBI-0047', 'UBI-0052', 'UBI-0057',
            'UBI-0059', 'UBI-0063', 'UBI-0064', 'UBI-0067', 'UBI-0068',
            'UBI-0070', 'UBI-0074', 'UBI-0075', 'UBI-0079', 'UBI-0088',
            'UBI-0099', 'UBI-0101', 'UBI-0106', 'UBI-0115',
        ];
    }

    private function collapseSpaces(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace('/[^\S\n]{2,}/u', ' ', $text);
    }

    public function up(): void
    {
        foreach (ContentQuestion::whereIn('content_id', $this->affectedContentIds())->get() as $question) {
            $published = $question->publishedRevision();

            if (! $published) {
                continue;
            }

            $newQuestionText = $this->collapseSpaces($published->question_text);
            $newFeedbackCorrect = $this->collapseSpaces($published->feedback_correct);
            $newFeedbackIncorrect = $this->collapseSpaces($published->feedback_incorrect);

            $answers = $published->answers()->orderBy('sort_order')->get();
            $answerTexts = [];
            $needsFix = $newQuestionText !== $published->question_text
                || $newFeedbackCorrect !== $published->feedback_correct
                || $newFeedbackIncorrect !== $published->feedback_incorrect;

            foreach ($answers as $answer) {
                $newText = $this->collapseSpaces($answer->answer_text);
                $answerTexts[$answer->id] = $newText;

                if ($newText !== $answer->answer_text) {
                    $needsFix = true;
                }
            }

            if (! $needsFix) {
                continue;
            }

            $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);

            $latestRevisionNo = $question->revisions()->max('revision_no');

            $revision = ContentQuestionRevision::create([
                'question_id' => $question->id,
                'revision_no' => $latestRevisionNo + 1,
                'question_type' => $published->question_type,
                'question_text' => $newQuestionText,
                'feedback_correct' => $newFeedbackCorrect,
                'feedback_incorrect' => $newFeedbackIncorrect,
                'topic' => $published->topic,
                'subtopic' => $published->subtopic,
                'smartmodus_kategorie' => $published->smartmodus_kategorie,
                'competency' => $published->competency,
                'image_required' => $published->image_required,
                'source_catalog' => $published->source_catalog,
                'source_version' => $published->source_version,
                'source_page' => $published->source_page,
                'source_question_id' => $published->source_question_id,
                'valid_from' => $published->valid_from,
                'valid_to' => $published->valid_to,
                'editorial_status' => 'published',
                'rights_status' => $published->rights_status,
                'edited_by_superadmin' => false,
            ]);

            foreach ($answers as $answer) {
                ContentAnswer::create([
                    'revision_id' => $revision->id,
                    'answer_key' => $answer->answer_key,
                    'answer_text' => $answerTexts[$answer->id],
                    'is_correct' => $answer->is_correct,
                    'sort_order' => $answer->sort_order,
                ]);
            }

            foreach ($published->media as $media) {
                $revision->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
            }
        }
    }

    /**
     * Datenkorrektur ohne Rückweg: die korrigierte Revision kann bereits
     * beantwortet sein (attempt.revision_id), ein Zurückdrehen würde
     * Lernfortschritt verwerfen.
     */
    public function down(): void {}
};
