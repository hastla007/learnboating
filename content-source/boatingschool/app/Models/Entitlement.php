<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'entitlement';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'valid_from', 'valid_until',
        'status', 'source_type', 'source_reference', 'expiry_reminded_at',
    ];

    /** So viele Tage vor dem Ablauf warnen Kursübersicht und Erinnerungsmail. */
    public const EXPIRY_WARNING_DAYS = 14;

    protected static function booted(): void
    {
        // Neues Ablaufdatum (Verlängerung): wieder an den Ablauf erinnern.
        static::saving(function (self $entitlement) {
            if ($entitlement->isDirty('valid_until') && ! $entitlement->isDirty('expiry_reminded_at')) {
                $entitlement->expiry_reminded_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'expiry_reminded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Aktiv und läuft innerhalb von EXPIRY_WARNING_DAYS ab. */
    public function expiresSoon(): bool
    {
        return $this->isCurrentlyActive() && $this->valid_until !== null
            && $this->valid_until <= now()->addDays(self::EXPIRY_WARNING_DAYS);
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $now = now();

        return $this->valid_from <= $now && ($this->valid_until === null || $this->valid_until > $now);
    }

    /**
     * Bug-Review B15: status wechselt nie automatisch auf "expired" (dafür
     * gibt es einen eigenen Scheduler-Befehl, siehe ExpireEntitlements),
     * daher reicht ein reines status=active für "aktiv" nicht aus -- diese
     * Scope prüft zusätzlich valid_from/valid_until, genauso wie
     * EntitlementService::activeEntitlements() und isCurrentlyActive().
     */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', 'active')
            ->where('valid_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now));
    }
}
