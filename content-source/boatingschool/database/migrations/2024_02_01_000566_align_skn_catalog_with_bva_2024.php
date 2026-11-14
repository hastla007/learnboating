<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SKN nach dem Fragenkatalog des Bundesverwaltungsamts, Kapitel IV
 * (Stand 2024, S. 103-119): Abgleich aller 89 Fragen, abweichend waren
 *
 * - SKN-0023 (Winterlager): a) und b) im amtlichen Wortlaut ("Eine
 *   Aufbewahrung an Bord ist nur mit einer speziellen behördlichen
 *   Erlaubnis möglich." / "Eine Aufbewahrung an Land in einem Behältnis ...").
 * - SKN-0079 (vorübergehend überlassen): b) "Feuerwehrleute." statt
 *   "Polizeibeamten.", c) "nach den Weisungen des berechtigten Überlassers".
 * - SKN-0080 (Dokumente an Bord): 3. EU- und Schengen-Staaten, 4. Drittstaaten.
 *
 * SKN-0088 bleibt bewusst abweichend (Rauchsignale nur noch P1, siehe 000562).
 * Neuinstallationen bekommen den Text aus skn_catalog.php; diese Migration
 * holt ihn wie 000562 für bestehende Installationen nach (neue Revision,
 * nicht als edited_by_superadmin markiert; vom Superadmin geänderte Texte
 * trifft strtr() nicht). Die Praxiskarte PRAXIS-SKN-0021 (Dokumente) folgt
 * Frage 80, wie 000563 samt ihren Spiegelungen.
 */
return new class extends Migration
{
    private function fixes(): array
    {
        $landStorage = 'In einem Behältnis der Sicherheitsstufe DIN/EN 1143-1 Widerstandsgrad 0 oder I.';

        return [
            'SKN-0023' => ['answers' => [
                'A' => ['Eine Aufbewahrung an Bord ist nur mit einer speziellen behördlichen Erlaubnis möglich, ein Behältnis das der Sicherheitsstufe B oder dem Widerstandsgrad 0 entspricht, reicht nicht aus.' => 'Eine Aufbewahrung an Bord ist nur mit einer speziellen behördlichen Erlaubnis möglich.'],
                'B' => [$landStorage => 'Eine Aufbewahrung an Land in einem Behältnis der Sicherheitsstufe DIN/EN 1143-1 Widerstandsgrad 0 oder I.'],
            ]],
            'SKN-0079' => ['answers' => [
                'B' => ['Polizeibeamten.' => 'Feuerwehrleute.'],
                'C' => ['nach Weisungen des Überlassers' => 'nach den Weisungen des berechtigten Überlassers'],
            ]],
            'SKN-0080' => ['answers' => [
                'MODEL' => [
                    '3. im europäischen Ausland: Europäischer Feuerwaffenpass' => '3. in EU-Mitgliedstaaten und Nicht-EU-Staaten, die zum Schengen-Raum gehören: Europäischer Feuerwaffenpass',
                    '4. andere Länder: ggf. Einfuhrgenehmigung.' => '4. Drittstaaten: ggf. Einfuhrgenehmigung.',
                ],
            ]],
        ];
    }

    public function up(): void
    {
        foreach ($this->fixes() as $contentId => $fix) {
            $this->applyFix($contentId, $fix);
        }

        $this->syncPraxisCard('PRAXIS-SKN-0021');
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

    private function syncPraxisCard(string $id): void
    {
        $handle = fopen(database_path('data/skn_praxistrainer.csv'), 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $row = null;

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $candidate = array_combine($header, $data);

            if ($candidate['id'] === $id) {
                $row = $candidate;
                break;
            }
        }

        fclose($handle);

        if (! $row) {
            return;
        }

        DB::table('praxis_task')
            ->where(fn ($q) => $q->where('content_id', $id)->orWhere('content_id', 'like', $id.'-%'))
            ->update(['antwort' => $row['antwort']]);
    }

    /**
     * Datenkorrektur ohne Rückweg: die korrigierte Revision kann bereits
     * beantwortet sein (attempt.revision_id).
     */
    public function down(): void {}
};
