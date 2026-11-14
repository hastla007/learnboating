<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDiscountedPriceLabel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CoursePromoBanner extends Model
{
    use BelongsToTenant, HasDiscountedPriceLabel, HasUuids;

    protected $table = 'course_promo_banner';

    protected $fillable = [
        'tenant_id', 'active', 'headline', 'description', 'bullets', 'cta_label',
        'price_label', 'discount_percent', 'coupon_code', 'offer_url',
        'course_ids', 'trigger_percent',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'discount_percent' => 'integer',
            'bullets' => 'array',
            'course_ids' => 'array',
            'trigger_percent' => 'integer',
        ];
    }
}
