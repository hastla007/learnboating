<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use Illuminate\Database\Migrations\Migration;

/**
 * Hinweis auf MEOSAR in den Erklärungen zu SRC-0166 und SRC-0167 (nicht an
 * den amtlichen Fragen und Antworten). Die Quelle (fragen_erklaerungen.csv)
 * ist ebenfalls ergänzt; diese Migration holt es für bestehende Installationen
 * nach. Steht der Hinweis schon da, passiert nichts. Wie bei Migration 570
 * entsteht eine neue Revision.
 */
return new class extends Migration
{
    public const NOTE = 'Hinweis: COSPAS-SARSAT wurde inzwischen um MEOSAR erweitert (Such- und Rettungsempfänger auf Satelliten mittlerer Umlaufbahn wie Galileo und GPS). Damit wird eine Bake meist schon nach wenigen Minuten weltweit geortet, auch ohne eigenes GPS. In der Prüfung gilt weiterhin die Antwort des amtlichen Fragenkatalogs.';

    public function up(): void
    {
        foreach (['SRC-0166', 'SRC-0167'] as $contentId) {
            $question = ContentQuestion::where('content_id', $contentId)->first();
            $published = $question?->publishedRevision();
            if (! $published) {
                continue;
            }

            $fix = fn (string $text): string => str_contains($text, 'MEOSAR') || trim($text) === '' ? $text : rtrim($text).' '.self::NOTE;
            $correct = $fix((string) $published->feedback_correct);
            $incorrect = $fix((string) $published->feedback_incorrect);
            if ($correct === (string) $published->feedback_correct && $incorrect === (string) $published->feedback_incorrect) {
                continue;
            }

            $revision = $published->replicate();
            $revision->revision_no = $question->revisions()->max('revision_no') + 1;
            $revision->feedback_correct = $correct;
            $revision->feedback_incorrect = $incorrect;
            $revision->editorial_status = 'published';

            $question->revisions()->where('editorial_status', 'published')->update(['editorial_status' => 'deprecated']);
            $revision->save();

            foreach ($published->answers()->orderBy('sort_order')->get() as $answer) {
                ContentAnswer::create([
                    'revision_id' => $revision->id,
                    'answer_key' => $answer->answer_key,
                    'answer_text' => $answer->answer_text,
                    'is_correct' => $answer->is_correct,
                    'sort_order' => $answer->sort_order,
                ]);
            }
            foreach ($published->media as $media) {
                $revision->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
            }
        }
    }

    /** Datenkorrektur ohne Rückweg (bereits beantwortete Revisionen). */
    public function down(): void {}
};
