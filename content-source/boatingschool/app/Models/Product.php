<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasUuids;

    protected $table = 'product';

    public $timestamps = false;

    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'product_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ProductPurchase::class, 'product_id');
    }
}
