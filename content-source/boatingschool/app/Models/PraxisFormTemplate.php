<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PraxisFormTemplate extends Model
{
    use HasUuids;

    protected $table = 'praxis_form_template';

    protected $fillable = [
        'title', 'min_progress_percent', 'requires_tauglichkeit', 'fields', 'course_ids', 'active',
    ];

    protected function casts(): array
    {
        return [
            'min_progress_percent' => 'integer',
            'requires_tauglichkeit' => 'boolean',
            'fields' => 'array',
            'course_ids' => 'array',
            'active' => 'boolean',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(PraxisFormSubmission::class, 'praxis_form_template_id');
    }

    /** Feld-Definition anhand ihres Keys, oder null. */
    public function field(string $key): ?array
    {
        return collect($this->fields)->firstWhere('key', $key);
    }
}
