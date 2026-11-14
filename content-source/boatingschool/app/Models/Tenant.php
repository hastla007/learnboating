<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenant';

    public $timestamps = true;

    protected $fillable = [
        'slug', 'name', 'status', 'default_locale',
    ];

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class, 'tenant_id');
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    public function courseDefinitions(): HasMany
    {
        return $this->hasMany(CourseDefinition::class, 'tenant_id');
    }

    /**
     * Bug-Review F5: für Benachrichtigungen an die Bootsschule (neue
     * Praxis-Anmeldung, Rückmeldung auf einen Terminvorschlag, neue
     * Willkommensbox-Bestellung) gab es bisher keine zentrale Stelle, die
     * owner/admin dieser Bootsschule auflöst -- nur Prüfungen für EINEN
     * gegebenen Nutzer (User::roleForTenant()/isAdminFor()).
     *
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return $this->tenantUsers()
            ->whereIn('role', ['owner', 'admin'])
            ->where('status', 'active')
            ->with('user')
            ->get()
            ->pluck('user');
    }
}
