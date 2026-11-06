<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentQuestionRevision extends Model
{
    use HasUuids;

    protected $table = 'content_question_revision';

    public $timestamps = false;

    protected $fillable = [
        'question_id', 'revision_no', 'question_type', 'question_text', 'feedback_correct', 'feedback_incorrect', 'topic',
        'subtopic', 'competency', 'smartmodus_kategorie', 'image_required', 'source_catalog', 'source_version',
        'source_page', 'source_question_id', 'editorial_status', 'rights_status',
        'rights_owner', 'license_reference', 'valid_from', 'valid_to',
        'reviewed_by', 'reviewed_at', 'edited_by_superadmin',
    ];

    protected function casts(): array
    {
        return [
            'image_required' => 'boolean',
            'edited_by_superadmin' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ContentAnswer::class, 'revision_id')->orderBy('sort_order');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'question_media', 'revision_id', 'media_asset_id')
            ->withPivot(['role', 'sort_order']);
    }

    public function correctAnswer(): ?ContentAnswer
    {
        return $this->answers->firstWhere('is_correct', true);
    }

    /**
     * Bug-Review (Antwortreihenfolge bei Einfachauswahl-Fragen immer
     * identisch): answers() behält unverändert die sort_order aus dem
     * Import bei -- und im Quellkatalog steht die richtige Antwort fast
     * immer an erster Stelle (823 von 838 Einfachauswahl-Fragen haben
     * correct_answer=A in zentrale_fragenbasis.xlsx). Lernende merken sich
     * dadurch "immer die erste Antwort" statt des Inhalts, und die
     * Prüfungssimulation verliert jede Aussagekraft. Mischt die
     * Darstellungsreihenfolge pro Lernendem und Antwort deterministisch
     * (Hash aus Nutzer-ID + Antwort-ID) -- stabil bei jedem Neuladen und
     * während der laufenden Prüfung, aber unterschiedlich zwischen
     * Lernenden, sodass sich keine feste Reihenfolge einprägen lässt.
     * sort_order selbst (und damit answers()) bleibt unverändert, da es
     * u. a. vom Fragen-Editor und von hashRevision() für den
     * Import-Vergleich genutzt wird.
     */
    public function answersInDisplayOrderFor(?string $userId): Collection
    {
        if (! $userId) {
            return $this->answers;
        }

        return $this->answers
            ->sortBy(fn (ContentAnswer $answer) => md5($userId.'|'.$answer->id))
            ->values();
    }

    /** @return Collection<int, ContentAnswer> */
    public function correctAnswers(): Collection
    {
        return $this->answers->where('is_correct', true)->values();
    }

    /**
     * Veröffentlicht diese Revision und setzt die bisher veröffentlichte
     * Fassung derselben Frage auf "deprecated".
     *
     * Bug-Review (Veröffentlichen einer Import-Revision verliert
     * Feedback/Bilder): ImportContentQuestions setzt auf einer neuen
     * "review"-Revision nie feedback_correct/feedback_incorrect und hängt
     * auch kein Bild an (beides sind reine Editor-Felder) -- ohne das hier
     * genau wie QuestionController::update() (Bug-Review B13) von der
     * bisher veröffentlichten Fassung zu übernehmen, würde das
     * Veröffentlichen einer solchen Import-Revision beides stillschweigend
     * löschen. $inheritEditorialContent = false überspringt das bewusst,
     * wenn die neue Fassung inhaltlich eine andere Frage sein kann (neuer
     * amtlicher Katalog, siehe CatalogUpdateSeeder) -- dort würde eine
     * zur alten Antwort geschriebene Erklärung bzw. ein altes Bild falsch.
     */
    public function publish(bool $inheritEditorialContent = true, bool $resetProgressOnContentChange = true): void
    {
        DB::transaction(function () use ($inheritEditorialContent, $resetProgressOnContentChange) {
            // Bug-Review (Doppelklick auf "Veröffentlichen" verursacht
            // Fehler 500): zwei nahezu gleichzeitige Aufrufe bestanden beide
            // die Prüfungen des Aufrufers (editorial_status war für beide
            // noch "review", da keiner committet hatte) und liefen parallel
            // in die Medien-Übernahme unten -- derselbe (revision_id,
            // media_asset_id, role)-Primärschlüssel wurde zweimal eingefügt
            // (HTTP 500). Die Zeile der Frage wird deshalb gesperrt; danach
            // wird der tatsächliche Stand erneut gelesen, und eine inzwischen
            // schon veröffentlichte Fassung wird hier klaglos übergangen.
            ContentQuestion::whereKey($this->question_id)->lockForUpdate()->first();
            $this->refresh();

            if ($this->editorial_status !== 'review') {
                return;
            }

            $published = self::where('question_id', $this->question_id)
                ->where('editorial_status', 'published')
                ->first();

            if ($published) {
                if ($inheritEditorialContent) {
                    $this->update([
                        'feedback_correct' => $this->feedback_correct ?? $published->feedback_correct,
                        'feedback_incorrect' => $this->feedback_incorrect ?? $published->feedback_incorrect,
                    ]);

                    foreach ($published->media as $media) {
                        $this->media()->attach($media->id, ['role' => $media->pivot->role, 'sort_order' => $media->pivot->sort_order]);
                    }
                }

                $published->update(['editorial_status' => 'deprecated']);
            }

            if ($resetProgressOnContentChange) {
                $this->resetProgressIfContentChangedFrom($published);
            }

            $this->update(['editorial_status' => 'published']);
        });
    }

    /**
     * Bug-Review (Lernfortschritt bei ersetzten Fragen): wird eine neue
     * Fassung veröffentlicht, deren Fragetext oder Antworten sich
     * tatsächlich geändert haben (z. B. eine korrigierte Frage im Editor
     * oder eine der 16 im UBI-Katalog 10/2026 ersetzten Fragen, siehe
     * CatalogUpdateSeeder), blieb der Mastery-Fortschritt
     * (progress.learning_state) jedes Lerners auf dem Stand der ALTEN Frage
     * stehen -- "gefestigt" anhand von Antworten, die mit der neuen Fassung
     * nichts mehr zu tun haben. Bereits gemachte Attempt-Datensätze bleiben
     * als historischer Beleg unangetastet. Reine Metadaten-/Feedback-
     * Änderungen lösen das bewusst NICHT aus.
     *
     * Löscht über alle Bootsschulen hinweg und muss deshalb auf der
     * Owner-Verbindung laufen (Superadmin-Bereich, Seeder).
     */
    public function resetProgressIfContentChangedFrom(?self $old): void
    {
        if (! $old || ! $this->contentDiffersFrom($old)) {
            return;
        }

        Progress::where('question_id', $this->question_id)->delete();
    }

    private function contentDiffersFrom(self $old): bool
    {
        if ($old->question_text !== $this->question_text) {
            return true;
        }

        $normalize = fn (self $revision) => $revision->answers()
            ->reorder()
            ->orderBy('answer_key')
            ->get(['answer_key', 'answer_text', 'is_correct'])
            ->map(fn ($answer) => [$answer->answer_key, $answer->answer_text, $answer->is_correct])
            ->all();

        return $normalize($old) !== $normalize($this);
    }
}
