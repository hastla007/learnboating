<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PraxisFormSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'praxis_form_submission';

    protected $fillable = [
        'tenant_id', 'course_id', 'user_id', 'praxis_form_template_id', 'data', 'confirmed_exam_date',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'confirmed_exam_date' => 'datetime',
        ];
    }

    /**
     * Alle Terminvorschlagsrunden über alle Slots hinweg -- slot 0 ist der
     * Prüfungstermin selbst, slot 1, 2, ... sind unabhängige, zusätzliche
     * Termine, die die Bootsschule frei benannt und terminiert anlegt
     * (siehe nextPracticeSlotNumber()). Für einen einzelnen Slot immer
     * appointmentsForSlot() verwenden, nicht direkt filtern.
     */
    public function examAppointments(): HasMany
    {
        return $this->hasMany(PraxisExamAppointment::class)->orderBy('slot')->orderBy('round');
    }

    /** @return Collection<int, PraxisExamAppointment> */
    public function appointmentsForSlot(int $slot): Collection
    {
        return $this->examAppointments->where('slot', $slot)->values();
    }

    public function latestAppointmentForSlot(int $slot): ?PraxisExamAppointment
    {
        return $this->appointmentsForSlot($slot)->last();
    }

    public function latestExamAppointment(): ?PraxisExamAppointment
    {
        return $this->latestAppointmentForSlot(0);
    }

    public function isExamDateConfirmed(): bool
    {
        return $this->confirmed_exam_date !== null;
    }

    /**
     * Die vom Teilnehmer bereits angelegten zusätzlichen Terminreihen
     * (slot >= 1), aufsteigend sortiert -- ihre Anzahl und Bezeichnung
     * bestimmt die Bootsschule frei, es gibt kein festes Kontingent.
     *
     * @return array<int, int>
     */
    public function practiceSlotNumbers(): array
    {
        return $this->examAppointments->where('slot', '>', 0)->pluck('slot')->unique()->sort()->values()->all();
    }

    public function nextPracticeSlotNumber(): int
    {
        $existing = $this->practiceSlotNumbers();

        return $existing === [] ? 1 : max($existing) + 1;
    }

    /** Die von der Bootsschule vergebene Bezeichnung dieses Termins (z. B. "Kleine Praxisstunde (1h)", "Navigationsstunde"). */
    public function labelForSlot(int $slot): ?string
    {
        return $this->latestAppointmentForSlot($slot)?->label;
    }

    public function isPracticeAppointmentConfirmed(int $slot): bool
    {
        return $this->appointmentsForSlot($slot)->firstWhere('response', 'accepted') !== null;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PraxisFormTemplate::class, 'praxis_form_template_id');
    }

    /**
     * Kompakter Status für die Anmeldungsliste: was als Nächstes ansteht.
     * Deckt sich mit der Reihenfolge, in der ParticipantController die
     * Benachrichtigungsbox befüllt -- hier aber als eine einzelne Zeile
     * statt einer Liste, für die Übersichtstabelle.
     *
     * Bug-Review (Zurückgezogene/abgesagte Termine erscheinen als "fix"):
     * hier wurde bisher nur auf response === 'declined' geprüft, nicht auf
     * 'withdrawn' (PraxisExamAppointmentService::withdraw()) oder
     * 'cancelled' (::cancel()) -- beide fielen damit durch alle Prüfungen
     * hindurch bis zum letzten return und erschienen fälschlich als fix
     * zugesagt, obwohl in Wahrheit kein gültiger Termin mehr vorlag.
     *
     * @return array{label: string, action_needed: bool}
     */
    public function nextStep(): array
    {
        $exam = $this->latestExamAppointment();

        if (! $exam) {
            return ['label' => 'Prüfungstermin vorschlagen', 'action_needed' => true];
        }

        if (in_array($exam->response, ['declined', 'withdrawn', 'cancelled'], true)) {
            return ['label' => 'Prüfungstermin erneut vorschlagen', 'action_needed' => true];
        }

        if ($exam->isPending()) {
            return ['label' => 'Wartet auf Rückmeldung (Prüfungstermin)', 'action_needed' => false];
        }

        foreach ($this->practiceSlotNumbers() as $slot) {
            $latest = $this->latestAppointmentForSlot($slot);

            if ($latest && in_array($latest->response, ['declined', 'withdrawn', 'cancelled'], true)) {
                return ['label' => 'Termin erneut vorschlagen ('.$this->labelForSlot($slot).')', 'action_needed' => true];
            }

            if ($latest && $latest->isPending()) {
                return ['label' => 'Wartet auf Rückmeldung ('.$this->labelForSlot($slot).')', 'action_needed' => false];
            }
        }

        return ['label' => 'Prüfungstermin fix: '.$exam->formattedRange(), 'action_needed' => false];
    }
}
