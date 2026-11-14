<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\Concerns\HasDiscountedPriceLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAddon extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey, HasDiscountedPriceLabel;

    protected $table = 'tenant_addon';

    protected array $compositeKey = ['tenant_id', 'addon_template_id'];

    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'addon_template_id', 'active', 'coupon_code', 'price_label', 'discount_percent', 'bullets',
        'trigger_course_id', 'trigger_percent', 'offer_url',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trigger_percent' => 'integer',
            'discount_percent' => 'integer',
            'bullets' => 'array',
        ];
    }

    public function addonTemplate(): BelongsTo
    {
        return $this->belongsTo(AddonTemplate::class, 'addon_template_id');
    }

    public function triggerCourse(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'trigger_course_id');
    }

    /**
     * Eigene Bullets der Bootsschule, falls hinterlegt, sonst die
     * Vorlagen-Bullets (addon_template.bullets).
     */
    public function effectiveBullets(): array
    {
        return $this->bullets ?: ($this->addonTemplate->bullets ?? []);
    }
}
