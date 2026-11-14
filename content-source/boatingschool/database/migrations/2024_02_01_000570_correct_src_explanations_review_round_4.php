<?php

use App\Models\ContentAnswer;
use App\Models\ContentQuestion;
use Illuminate\Database\Migrations\Migration;

/**
 * Review-Runde 4 (Inhalt): fachliche Korrekturen in vier SRC-Erklärungen
 * (nicht an den amtlichen Fragen und Antworten). Die Quelle
 * (fragen_erklaerungen.csv) ist ebenfalls korrigiert; diese Migration holt
 * es für bestehende Installationen nach. Ersetzt wird nur, wo der alte
 * Wortlaut noch steht -- eine vom Superadmin umformulierte Erklärung bleibt
 * unberührt. Wie bei BB8–BB11 entsteht eine neue Revision.
 */
return new class extends Migration
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function corrections(): array
    {
        return [
            // TKG → seit 2021 TTDSG, seit 2024 TDDDG (amtliche Antwort bleibt TKG).
            'SRC-0042' => [
                'Die VO Funk regelt internationale Frequenz- und Betriebsfragen, die SchSV die Schiffssicherheit.',
                'Die VO Funk regelt internationale Frequenz- und Betriebsfragen, die SchSV die Schiffssicherheit. Hinweis: Der amtliche Katalog nennt noch das TKG; heute stehen Fernmeldegeheimnis und Abhörverbot in §§ 3 und 5 des Telekommunikation-Digitale-Dienste-Datenschutz-Gesetzes (TDDDG).',
            ],
            // manövrierbehindert (KVR Regel 3 g) ≠ manövrierunfähig (Regel 3 f).
            'SRC-0135' => [
                'Ist das Schiff nur manövrierbehindert oder braucht',
                'Ist das Schiff nur manövrierunfähig ohne unmittelbare Gefahr oder braucht',
            ],
            // Dual Watch tastet abwechselnd ab, beim Senden wird nichts empfangen.
            'SRC-0154' => [
                'und verpasst trotzdem keine Meldung auf Kanal 16.',
                'und hört Kanal 16 trotzdem mit Vorrang mit; während des eigenen Sendens oder langer Aussendungen auf dem zweiten Kanal kann allerdings etwas verloren gehen.',
            ],
            // Seegebiet A3: seit 1.1.2024 anerkannter Satellitenfunkdienst (SOLAS IV).
            'SRC-0013' => [
                'A3 die Abdeckung durch Inmarsat-Satelliten',
                'A3 die Abdeckung durch einen anerkannten Satellitenfunkdienst (Inmarsat, inzwischen auch Iridium)',
            ],
        ];
    }

    public function up(): void
    {
        foreach (self::corrections() as $contentId => [$old, $new]) {
            $question = ContentQuestion::where('content_id', $contentId)->first();
            $published = $question?->publishedRevision();
            if (! $published) {
                continue;
            }

            // Steht der neue Wortlaut schon da (Import aus der korrigierten
            // CSV, zweiter Lauf), nichts tun: Bei SRC-0042 enthält der neue
            // Text den alten, der Hinweis käme sonst doppelt.
            $fix = fn (string $text): string => str_contains($text, $new) ? $text : str_replace($old, $new, $text);
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
