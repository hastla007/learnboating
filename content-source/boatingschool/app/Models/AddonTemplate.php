<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddonTemplate extends Model
{
    use HasUuids;

    protected $table = 'addon_template';

    protected $fillable = [
        'title', 'badge_text', 'stat_text', 'headline', 'bullets', 'cta_label', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'bullets' => 'array',
            'active' => 'boolean',
        ];
    }

    public function tenantAddons(): HasMany
    {
        return $this->hasMany(TenantAddon::class, 'addon_template_id');
    }
}
