<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mitteilung einer Bootsschule an ihre Lernenden (alle oder ein Kurs). */
class TenantAnnouncement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_announcement';

    protected $fillable = ['tenant_id', 'course_id', 'title', 'body', 'visible_until', 'created_by_user_id', 'emailed_at', 'emailed_count'];

    protected function casts(): array
    {
        return ['visible_until' => 'date', 'emailed_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
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

    public function isVisible(): bool
    {
        return $this->visible_until === null || $this->visible_until->toDateString() >= now('Europe/Berlin')->toDateString();
    }
}
