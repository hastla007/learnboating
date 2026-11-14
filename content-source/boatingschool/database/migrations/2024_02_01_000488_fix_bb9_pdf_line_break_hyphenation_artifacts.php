<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review BB9: Beim ursprünglichen Import des zentralen Fragenkatalogs
 * (zentrale_fragenbasis.xlsx) wurden PDF-Zeilenumbrüche mitten in einem
 * Wort gelegentlich als Trennstrich plus Leerzeichen mit übernommen, z. B.
 * "Dringlichkeits- meldung" statt "Dringlichkeitsmeldung". Ein manueller
 * Abgleich bestätigte u. a. genau dieses im Bug-Review genannte Beispiel.
 *
 * Eine automatisierte Suche nach dem Muster "Wort- wort" liefert viele
 * falsche Treffer: im Deutschen korrekte Aufzählungs-Ellipsen wie
 * "Seenot- und Sicherheitsfunksystem", "Revier- oder Hafenfunkdienst" oder
 * "revier- beziehungsweise hafenbezogene" dürfen NICHT verändert werden.
 * Die unten aufgeführten Fundstellen wurden deshalb einzeln im Kontext
 * geprüft -- jede ist tatsächlich ein mitten im Wort oder mitten in einem
 * mehrfach gekoppelten Kompositum ("rot-weiß-rote", "Schiff-Schiff-Kanal",
 * "COSPAS-SARSAT") eingefügtes, überflüssiges Leerzeichen.
 *
 * Analog zu BB8 (siehe Migration fix_bb8_sbf_see_0075_pdf_footer_artifact)
 * legt diese Migration pro betroffener Frage eine neue Revision an, statt
 * die bestehende Revision direkt zu verändern. Mit erfasst: das Leerzeichen
 * vor dem Satzpunkt in den Schallsignal-Antworten von SBF-SEE-0121/0124
 * ("lang, kurz, kurz .").
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
            'SBF-BIN-0151' => [
                'answers' => [
                    'A' => ['rot-weiß- rote' => 'rot-weiß-rote'],
                    'B' => ['rot- weiß-rote' => 'rot-weiß-rote'],
                    'C' => ['rot-weiß- rote' => 'rot-weiß-rote'],
                    'D' => ['grün- weiß-grüne' => 'grün-weiß-grüne'],
                ],
            ],
            'SRC-0026' => [
                'answers' => [
                    'D' => ['funkausrüstungs- pflichtigen' => 'funkausrüstungspflichtigen'],
                ],
            ],
            'SRC-0099' => [
                'question_text' => ['Dringlichkeits- meldung' => 'Dringlichkeitsmeldung'],
            ],
            'SRC-0104' => [
                'question_text' => ['vorzugs- weise' => 'vorzugsweise'],
            ],
            'SRC-0156' => [
                'answers' => [
                    'A' => ['Schiff- Schiff-Kanal' => 'Schiff-Schiff-Kanal'],
                ],
            ],
            'SRC-0165' => [
                'question_text' => ['COSPAS- SARSAT- Satelliten-Seenotfunkbake' => 'COSPAS-SARSAT-Satelliten-Seenotfunkbake'],
            ],
            'SRC-0166' => [
                'question_text' => ['COSPAS- SARSAT-Satelliten-Seenotfunkbake' => 'COSPAS-SARSAT-Satelliten-Seenotfunkbake'],
            ],
            'SRC-0180' => [
                'answers' => [
                    'B' => ['SAR- Fahrzeugen' => 'SAR-Fahrzeugen'],
                ],
            ],
            'UBI-0001' => [
                'answers' => [
                    'A' => ['UKW/VHF- Sprechfunkdienst' => 'UKW/VHF-Sprechfunkdienst'],
                ],
            ],
            'UBI-0009' => [
                'question_text' => ['UKW-Sprechfunk- zeugnisses' => 'UKW-Sprechfunkzeugnisses'],
            ],
            'UBI-0103' => [
                'question_text' => ['Schiffsfunk- stellen' => 'Schiffsfunkstellen'],
            ],
            'SBF-SEE-0121' => [
                'answers' => [
                    'B' => [' kurz .' => ' kurz.'],
                    'C' => [' kurz .' => ' kurz.'],
                    'D' => [' kurz .' => ' kurz.'],
                ],
            ],
            'SBF-SEE-0124' => [
                'answers' => [
                    'A' => [' kurz .' => ' kurz.'],
                    'C' => [' kurz .' => ' kurz.'],
                    'D' => [' kurz .' => ' kurz.'],
                ],
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
        // Die Erklärungen zitieren die richtige Antwort ("Richtig ist: …") --
        // dieselben Ersetzungen gelten dort mit.
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
