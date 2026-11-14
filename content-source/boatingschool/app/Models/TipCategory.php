<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipCategory extends Model
{
    use HasUuids;

    protected $table = 'tip_category';

    protected $fillable = ['name', 'sort_order'];

    public function tips(): HasMany
    {
        return $this->hasMany(Tip::class, 'category_id');
    }
}
