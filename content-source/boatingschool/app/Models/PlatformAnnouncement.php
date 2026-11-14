<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mitteilung des Plattformbetreibers an die Bootsschulen. tenant_ids = null
 * heißt alle Schulen; audience 'team' nur Admin-Bereich, 'all' zusätzlich
 * im Lern-Dashboard.
 */
class PlatformAnnouncement extends Model
{
    use HasUuids;

    protected $table = 'platform_announcement';

    protected $fillable = ['title', 'body', 'audience', 'tenant_ids', 'visible_until', 'created_by_user_id', 'emailed_at', 'emailed_count'];

    protected function casts(): array
    {
        return ['tenant_ids' => 'array', 'visible_until' => 'date', 'emailed_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Noch sichtbar (ohne Ablaufdatum oder bis einschließlich heute, Europe/Berlin). */
    public function scopeVisible(Builder $query): Builder
    {
        $today = now('Europe/Berlin')->toDateString();

        return $query->where(fn ($q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', $today));
    }

    /** An alle Schulen oder an diese Schule gerichtet. */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_ids')->orWhereJsonContains('tenant_ids', $tenantId));
    }

    public function isVisible(): bool
    {
        return $this->visible_until === null || $this->visible_until->toDateString() >= now('Europe/Berlin')->toDateString();
    }
}
