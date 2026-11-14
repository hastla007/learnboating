<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\GermanTime;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisExamAppointment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'praxis_exam_appointment';

    protected $fillable = [
        'tenant_id', 'praxis_form_submission_id', 'tenant_course_location_id', 'is_online', 'online_meeting_url',
        'slot', 'label', 'round', 'exam_date', 'ends_at', 'description', 'proposed_by_user_id', 'response',
        'response_comment', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'datetime',
            'ends_at' => 'datetime',
            'responded_at' => 'datetime',
            'is_online' => 'boolean',
        ];
    }

    public function isPending(): bool
    {
        return $this->response === 'pending';
    }

    /**
     * "15.07.2026 16:00" oder, mit Ende, "15.07.2026 16:00-18:00". Bug-Review
     * B30: ein Termin wird dem Teilnehmer in deutscher Ortszeit angekündigt,
     * nicht in der intern für Speicherung/Berechnung genutzten UTC-Zeit.
     */
    public function formattedRange(): string
    {
        $range = GermanTime::format($this->exam_date, 'd.m.Y H:i');

        return $this->ends_at ? $range.'–'.GermanTime::format($this->ends_at, 'H:i') : $range;
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PraxisFormSubmission::class, 'praxis_form_submission_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantCourseLocation::class, 'tenant_course_location_id');
    }

    /** "Online" oder der Praxisort-Name des gewählten Standorts, sonst null. */
    public function locationLabel(): ?string
    {
        if ($this->is_online) {
            return 'Online';
        }

        return $this->location?->praxis_ort;
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }
}
