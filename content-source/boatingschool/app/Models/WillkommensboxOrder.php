<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WillkommensboxOrder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'willkommensbox_order';

    protected $fillable = [
        'tenant_id', 'user_id', 'street', 'postal_code', 'city', 'country', 'note',
        'shipped_at', 'tracking_number',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
        ];
    }

    public function isShipped(): bool
    {
        return $this->shipped_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
