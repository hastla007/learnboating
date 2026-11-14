<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Anwendungsseitige Tenant-Filterung als zweite Schutzschicht neben der
 * DB-RLS-Policy ("Ein UI-Filter allein gilt nicht als Schutz" heißt auch
 * umgekehrt: RLS allein ersetzt keine bewusste Query-Filterung).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->has()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->id());
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            if ($context->has() && empty($model->tenant_id)) {
                $model->tenant_id = $context->id();
            }
        });
    }
}
