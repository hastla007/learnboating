<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvitation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_invitation';

    const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'email', 'role', 'invited_by_user_id', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
