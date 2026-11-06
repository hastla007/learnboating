<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tip extends Model
{
    use HasUuids;

    protected $table = 'tip';

    protected $fillable = ['category_id', 'title', 'body', 'pdf_asset_id', 'sort_order', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TipCategory::class, 'category_id');
    }

    public function pdfAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'pdf_asset_id');
    }
}
