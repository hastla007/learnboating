<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Jede Zeile hier ist eine technische Protokollnotiz (action + entity_type/
 * -id + before/after). description() übersetzt das für "Letzte Aktivitäten"
 * im Bootsschul-Dashboard (admin/tabs/dashboard.blade.php) in einen
 * lesbaren deutschen Satz mit handelnder Person und -- wo zutreffend --
 * dem betroffenen Teilnehmer, statt des rohen Aktionscodes.
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(
        ?string $tenantId,
        ?string $actorUserId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before = null,
        ?array $after = null,
    ): self {
        return static::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before,
            'after_data' => $after,
        ]);
    }

    public function description(): string
    {
        $actorName = ($this->actor_user_id ? User::find($this->actor_user_id)?->name : null) ?? 'System';

        return match (true) {
            $this->action === 'coupon.redeem' => $this->describeCouponRedeem($actorName),
            $this->action === 'willkommensbox-order.ship' => $this->describeWillkommensboxShip($actorName),
            str_starts_with($this->action, 'entitlement.') => $this->describeEntitlementChange($actorName),
            $this->action === 'praxis-exam-appointment.propose' => $this->describePraxisAppointmentPropose($actorName),
            $this->action === 'participant.update' => $this->describeParticipantUpdate($actorName),
            $this->action === 'participant.invite' => $this->describeParticipantInvite($actorName),
            $this->action === 'participant.invite_pending' => "{$actorName} hat eine Einladung an eine bereits bestehende E-Mail-Adresse verschickt.",
            $this->action === 'participant.membership_update' => $this->describeParticipantMembershipUpdate($actorName),
            $this->action === 'branding.update' => "{$actorName} hat das Branding aktualisiert.",
            $this->action === 'webshop-links.update' => "{$actorName} hat die Webshop-Links aktualisiert.",
            $this->action === 'course-selection.update' => "{$actorName} hat die Kursauswahl aktualisiert.",
            $this->action === 'addons.update' => "{$actorName} hat die Zusatzleistungen aktualisiert.",
            $this->action === 'course-promo-banners.create' => "{$actorName} hat ein Kursempfehlungs-Banner angelegt.",
            $this->action === 'course-promo-banners.update' => "{$actorName} hat ein Kursempfehlungs-Banner aktualisiert.",
            $this->action === 'course-promo-banners.delete' => "{$actorName} hat ein Kursempfehlungs-Banner gelöscht.",
            $this->action === 'tenant-course-locations.create' => "{$actorName} hat einen Praxis-/Prüfungsort angelegt.",
            $this->action === 'tenant-course-locations.update' => "{$actorName} hat einen Praxis-/Prüfungsort aktualisiert.",
            $this->action === 'tenant-course-locations.delete' => "{$actorName} hat einen Praxis-/Prüfungsort gelöscht.",
            // Review-Runde 3: bisher als roher Schlüssel angezeigt.
            $this->action === 'coupon.redeem_renewal' => "{$actorName} hat einen Verlängerungscode eingelöst.",
            $this->action === 'exam-registration.update' => "{$actorName} hat die Prüfungsanmeldung je Kurs aktualisiert.",
            $this->action === 'invitation.accept' => "{$actorName} hat eine Einladung angenommen.",
            $this->action === 'praxis-exam-appointment.withdraw' => "{$actorName} hat einen Terminvorschlag zurückgezogen.",
            $this->action === 'praxis-exam-appointment.cancel' => "{$actorName} hat einen bestätigten Termin abgesagt.",
            $this->action === 'praxis-form-submission.update' => "{$actorName} hat eine Praxis-Anmeldung bearbeitet.",
            $this->action === 'praxis-form-submission.delete' => "{$actorName} hat eine Praxis-Anmeldung gelöscht.",
            $this->action === 'superadmin.coupon.create' => "{$actorName} hat Codes angelegt.",
            $this->action === 'superadmin.coupon.export' => "{$actorName} hat Codes exportiert.",
            $this->action === 'superadmin.settings.update' => "{$actorName} hat Plattform-Einstellungen geändert.",
            $this->action === 'superadmin.tenant.create' => "{$actorName} hat eine Bootsschule angelegt.",
            $this->action === 'superadmin.tenant.update' => "{$actorName} hat eine Bootsschule geändert.",
            $this->action === 'superadmin.user.create' => "{$actorName} hat ein Nutzerkonto angelegt.",
            $this->action === 'superadmin.user.update' => "{$actorName} hat ein Nutzerkonto geändert.",
            $this->action === 'superadmin.user.membership_update' => "{$actorName} hat eine Mitgliedschaft geändert.",
            default => "{$actorName}: {$this->action}",
        };
    }

    /**
     * Bug-Review (Codes im Klartext im Log): zeigte den eingelösten Code
     * bisher im Klartext an -- dieser Satz landet direkt im "Letzte
     * Aktivitäten"-Feed der Bootsschule (admin/tabs/dashboard.blade.php),
     * sichtbar für jeden Admin/Owner. Der Kurs-/Produktname reicht aus,
     * um die Aktion verständlich zu beschreiben, ohne den Code selbst
     * preiszugeben.
     */
    private function describeCouponRedeem(string $actorName): string
    {
        $coupon = Coupon::find($this->entity_id);
        $learnerId = $this->after_data['user_id'] ?? null;
        $learnerName = ($learnerId ? User::find($learnerId)?->name : null) ?? 'einem Teilnehmer';
        $courseOrProductName = $coupon?->course?->name ?? $coupon?->product?->name;
        $what = $courseOrProductName
            ? 'einen Code ('.$courseOrProductName.')'
            : 'einen Code';

        // Beim Selbst-Einlösen (/coupons/redeem) ist der Teilnehmer selbst
        // der Akteur -- dann braucht es kein "für sich selbst" im Satz.
        if ($learnerId && $learnerId === $this->actor_user_id) {
            return "{$actorName} hat {$what} eingelöst.";
        }

        return "{$actorName} hat {$what} für {$learnerName} eingelöst.";
    }

    private function describeWillkommensboxShip(string $actorName): string
    {
        $learnerId = $this->after_data['user_id'] ?? null;
        $learnerName = ($learnerId ? User::find($learnerId)?->name : null) ?? 'einem Teilnehmer';

        return "{$actorName} hat die Willkommensbox von {$learnerName} als versendet markiert.";
    }

    private function describeEntitlementChange(string $actorName): string
    {
        $data = $this->after_data ?? $this->before_data ?? [];
        $learnerId = $data['user_id'] ?? null;
        $courseId = $data['course_id'] ?? null;
        $learnerName = ($learnerId ? User::find($learnerId)?->name : null) ?? 'einem Teilnehmer';
        $courseName = ($courseId ? CourseDefinition::withoutGlobalScopes()->find($courseId)?->name : null) ?? 'einen Kurs';

        $verb = match ($this->action) {
            'entitlement.create' => 'freigeschaltet',
            'entitlement.extend' => 'verlängert',
            'entitlement.suspend' => 'gesperrt',
            'entitlement.revoke' => 'widerrufen',
            'entitlement.reactivate' => 'wieder aktiviert',
            default => 'geändert',
        };

        return "{$actorName} hat den Kurs \"{$courseName}\" für {$learnerName} {$verb}.";
    }

    private function describePraxisAppointmentPropose(string $actorName): string
    {
        $submissionId = $this->after_data['praxis_form_submission_id'] ?? null;
        $learnerName = ($submissionId ? PraxisFormSubmission::with('user')->find($submissionId)?->user?->name : null) ?? 'einem Teilnehmer';
        $label = $this->after_data['label'] ?? 'einen Termin';

        return "{$actorName} hat {$learnerName} \"{$label}\" vorgeschlagen.";
    }

    private function describeParticipantUpdate(string $actorName): string
    {
        $learnerName = ($this->entity_id ? User::find($this->entity_id)?->name : null) ?? 'einem Teilnehmer';

        return "{$actorName} hat die Adresse von {$learnerName} aktualisiert.";
    }

    private function describeParticipantInvite(string $actorName): string
    {
        $learnerName = ($this->entity_id ? User::find($this->entity_id)?->name : null) ?? ($this->after_data['email'] ?? 'einen Teilnehmer');

        return "{$actorName} hat {$learnerName} eingeladen.";
    }

    private function describeParticipantMembershipUpdate(string $actorName): string
    {
        $learnerName = ($this->entity_id ? User::find($this->entity_id)?->name : null) ?? 'einem Teilnehmer';
        $status = $this->after_data['status'] ?? null;

        $verb = match ($status) {
            'active' => 'entsperrt',
            'suspended' => 'gesperrt',
            'closed' => 'entfernt',
            default => 'geändert',
        };

        return "{$actorName} hat die Mitgliedschaft von {$learnerName} {$verb}.";
    }
}
