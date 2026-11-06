<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ContentQuestion extends Model
{
    use HasUuids;

    protected $table = 'content_question';

    public $timestamps = false;

    protected $fillable = [
        'content_id', 'official_number', 'content_role', 'language', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ContentQuestionRevision::class, 'question_id');
    }

    public function publishedRevision(): ?ContentQuestionRevision
    {
        return $this->revisions()
            ->where('editorial_status', 'published')
            ->orderByDesc('revision_no')
            ->first();
    }

    /**
     * Bug-Review F8: ImportContentQuestions legt bei einem geänderten
     * Reimport eine neue Revision im Status "review" an (siehe dort), statt
     * sie wie Superadmin\QuestionController::update() direkt zu
     * veröffentlichen -- bisher gab es aber keine Stelle, die eine solche
     * Revision überhaupt je veröffentlicht.
     *
     * Bug-Review (AA9: Wartende Import-Änderung verschwindet): früher wurde
     * dafür angenommen, eine "review"-Revision sei -- da revision_no stets
     * aufsteigend vergeben wird -- immer zugleich die insgesamt letzte
     * Revision der Frage. Speichert der Superadmin aber über den Editor
     * (QuestionController::update()), während eine solche Revision noch auf
     * Freigabe wartet, legt das eine NEUE, bereits veröffentlichte Revision
     * mit einer noch höheren revision_no an, ohne die wartende Review-
     * Revision anzutasten (siehe dortiger Bug-Review T3) -- diese Annahme
     * galt dann nicht mehr, und die wartende Revision verschwand dadurch
     * unbemerkt und dauerhaft aus der Vorschau sowie aus der Fragenliste.
     * Gesucht wird deshalb gezielt nach dem Status, nicht nach der Position.
     */
    public function pendingReviewRevision(): ?ContentQuestionRevision
    {
        return $this->revisions()
            ->where('editorial_status', 'review')
            ->orderByDesc('revision_no')
            ->first();
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_content', 'question_id', 'module_id')
            ->withPivot(['sort_order', 'required']);
    }

    /**
     * Bug-Review BB16: "Gültig ab"/"Gültig bis" (valid_from/valid_to auf der
     * veröffentlichten Revision) ließen sich im Fragen-Editor setzen, wurden
     * aber bei keiner der Stellen geprüft, die Fragen für Lernende
     * tatsächlich auswählen (Smarttrainer, Zufallsprüfung, Video-Quiz,
     * Fortschritts-/Favoriten-Zähler) -- die Felder waren dadurch rein
     * kosmetisch. valid_from/valid_to sind reine 'date'-Spalten (kein
     * Zeitanteil), ein Superadmin trägt sie als deutsches Kalenderdatum ein
     * -- dieselbe Umrechnung auf deutsche Zeit wie bei
     * CourseDefinition::activeExamRuleSet() (siehe dort).
     *
     * Fehlt eine veröffentlichte Revision ganz, trifft diese Methode absichtlich
     * keine Aussage über das Gültigkeitsfenster (es gibt keines zu prüfen) --
     * das bereits an den Aufrufstellen etablierte active-Flag (Bug-Review F7/
     * B12) bleibt dafür allein maßgeblich, wie zuvor.
     */
    public function isCurrentlyValid(): bool
    {
        if (! $this->active) {
            return false;
        }

        $revision = $this->publishedRevision();

        if (! $revision) {
            return true;
        }

        $today = now()->setTimezone('Europe/Berlin')->toDateString();

        if ($revision->valid_from && $revision->valid_from->toDateString() > $today) {
            return false;
        }

        if ($revision->valid_to && $revision->valid_to->toDateString() < $today) {
            return false;
        }

        return true;
    }

    /**
     * Query-Pendant zu isCurrentlyValid() für Stellen, die Fragen direkt per
     * Eloquent-Query (statt aus einer bereits geladenen Collection) für die
     * Auswahl eines Lernenden auswählen, siehe Bug-Review BB16 oben.
     */
    public function scopeCurrentlyValid(Builder $query): Builder
    {
        $today = now()->setTimezone('Europe/Berlin')->toDateString();

        return $query->where('active', true)
            ->whereHas('revisions', function ($q) use ($today) {
                $q->where('editorial_status', 'published')
                    ->where(fn ($q2) => $q2->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                    ->where(fn ($q2) => $q2->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
            });
    }

    /**
     * Bug-Review (Betrieb B6, siehe AdminAndTenantShowPerformanceTest): ein
     * isCurrentlyValid()-Aufruf PRO Frage (über publishedRevision()) ist für
     * eine bereits geladene Collection unproblematisch -- in einer Schleife
     * über viele Lernende mit demselben Kurs (AdminDashboardController,
     * Superadmin\TenantController) summiert sich das aber zu einem N+1 pro
     * Lernendem. Dieses Pendant berechnet die gültigen IDs einer Fragen-
     * Collection in GENAU EINER Query (statt einer pro Frage) und eignet
     * sich deshalb zum Memoisieren über mehrere Lernende mit demselben Kurs
     * hinweg (siehe Aufrufstellen).
     *
     * @param  Collection<int, ContentQuestion>  $questions
     * @return Collection<int, string>
     */
    public static function currentlyValidIds(Collection $questions): Collection
    {
        return self::filterCurrentlyValid($questions)->pluck('id')->unique();
    }

    /**
     * Bug-Review (BB16-Folgefehler, Seiten mit hunderten Abfragen):
     * isCurrentlyValid() lädt pro Frage die veröffentlichte Revision -- in
     * den Fragen-Collections eines Kurses (Dashboard, Kursseite,
     * Lernübersicht, Smarttrainer ...) waren das bei SBF See & Binnen über
     * 1.000 Abfragen pro Seitenaufruf. Diese Variante filtert eine bereits
     * geladene Collection mit genau einer Abfrage.
     *
     * @param  Collection<int, ContentQuestion>  $questions
     * @return Collection<int, ContentQuestion>
     */
    public static function filterCurrentlyValid(Collection $questions): Collection
    {
        $active = $questions->where('active', true)->unique('id');
        $today = now()->setTimezone('Europe/Berlin')->toDateString();

        $revisionsByQuestionId = ContentQuestionRevision::where('editorial_status', 'published')
            ->whereIn('question_id', $active->pluck('id'))
            ->get(['question_id', 'valid_from', 'valid_to'])
            ->keyBy('question_id');

        return $active->filter(function (self $question) use ($revisionsByQuestionId, $today) {
            $revision = $revisionsByQuestionId->get($question->id);

            if (! $revision) {
                return true;
            }

            if ($revision->valid_from && $revision->valid_from->toDateString() > $today) {
                return false;
            }

            if ($revision->valid_to && $revision->valid_to->toDateString() < $today) {
                return false;
            }

            return true;
        })->values();
    }
}
