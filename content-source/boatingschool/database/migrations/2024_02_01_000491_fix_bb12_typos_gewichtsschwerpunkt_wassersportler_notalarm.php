<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review BB12: Drei Tippfehler aus dem ursprünglichen Import des
 * zentralen Fragenkatalogs:
 * - SBF-SEGEL-0263, Antwort A: "Gewischtsschwerpunkt" statt "Gewichtsschwerpunkt".
 * - FKN-0023, Fragetext: "Wasersportler" statt "Wassersportler".
 * - SRC-0060, Fragetext: "Notarlarm" statt "Notalarm".
 *
 * Wie bei BB8-BB11 legt die Migration pro betroffener Frage eine neue
 * Revision an; der Tippfehler wird dabei auch in den Erklärungstexten
 * korrigiert.
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
    private function fixes(): array
    {
        return [
            'SBF-SEGEL-0263' => [
                'answers' => [
                    'A' => ['Gewischtsschwerpunkt' => 'Gewichtsschwerpunkt'],
                ],
            ],
            'FKN-0023' => [
                'question_text' => ['Wasersportler' => 'Wassersportler'],
            ],
            'SRC-0060' => [
                'question_text' => ['Notarlarm' => 'Notalarm'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->fixes() as $contentId => $fix) {
            $this->applyFix($contentId, $fix);
        }
    }

    private function applyFix(string $contentId, array $fix): void
    {
        $question = ContentQuestion::where('content_id', $contentId)->first();

        if (! $question) {
            return;
        }

        $published = $question->publishedRevision();

        if (! $published) {
            return;
        }

        $questionTextReplacements = $fix['question_text'] ?? [];
        $answerReplacements = $fix['answers'] ?? [];

        $newQuestionText = strtr($published->question_text, $questionTextReplacements);

        $answers = $published->answers()->orderBy('sort_order')->get();
        $answerTexts = [];
        // Derselbe Tippfehler steht auch in den Erklärungen (z. B.
        // SBF-SEGEL-0263) -- alle Ersetzungen dieser Frage gelten dort mit.
        $feedbackReplacements = array_merge($questionTextReplacements, ...array_values($answerReplacements));
        $newFeedbackCorrect = $published->feedback_correct === null ? null : strtr($published->feedback_correct, $feedbackReplacements);
        $newFeedbackIncorrect = $published->feedback_incorrect === null ? null : strtr($published->feedback_incorrect, $feedbackReplacements);
        $needsFix = $newQuestionText !== $published->question_text
            || $newFeedbackCorrect !== $published->feedback_correct
            || $newFeedbackIncorrect !== $published->feedback_incorrect;

        foreach ($answers as $answer) {
            $replacements = $answerReplacements[$answer->answer_key] ?? [];
            $newText = strtr($answer->answer_text, $replacements);
            $answerTexts[$answer->id] = $newText;

            if ($newText !== $answer->answer_text) {
                $needsFix = true;
            }
        }

        if (! $needsFix) {
            return;
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

    /**
     * Datenkorrektur ohne Rückweg: die korrigierte Revision kann bereits
     * beantwortet sein (attempt.revision_id), ein Zurückdrehen würde
     * Lernfortschritt verwerfen.
     */
    public function down(): void {}
};
