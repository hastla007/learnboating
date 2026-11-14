<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $table = 'app_user';

    protected $fillable = [
        'display_name',
        'name',
        'first_name',
        'last_name',
        'email',
        'pending_email',
        'password',
        'locale',
        'status',
        'external_identity',
        'email_verified_at',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Zwei-Faktor-Anmeldung eingerichtet und bestätigt. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Ob Adresse und Geburtsdatum für DIESE Bootsschule vollständig im Profil
     * hinterlegt sind (siehe Praxisformular-Gate).
     *
     * Sicherheitsreview S3: diese Felder standen bisher direkt auf app_user
     * -- EINE einzige, kontoweite Kopie, unabhängig davon, bei wie vielen
     * Bootsschulen das Konto Mitglied ist. Schule A sah und änderte damit
     * dieselbe Adresse/dasselbe Geburtsdatum, die Schule B für ihre eigene
     * Prüfungsanmeldung verwendet. Die Felder liegen deshalb jetzt auf
     * tenant_user (siehe Migration 000469) -- je Mitgliedschaft eine eigene
     * Kopie.
     */
    public function hasCompleteRegistrationProfile(Tenant $tenant): bool
    {
        $membership = $this->tenantMemberships()->where('tenant_id', $tenant->id)->first();

        return (bool) ($membership
            && $membership->street
            && $membership->postal_code
            && $membership->city
            && $membership->country
            && $membership->birthdate);
    }

    /** Ob dieser Nutzer bei dieser Bootsschule "Lernplattform (inkl. Willkommensbox)" eingelöst hat. */
    public function hasWillkommensboxAccess(Tenant $tenant): bool
    {
        return ProductPurchase::where('tenant_id', $tenant->id)
            ->where('user_id', $this->id)
            ->whereHas('product', fn ($q) => $q->where('code', 'LERNPLATTFORM-WB'))
            ->exists();
    }

    /**
     * Laravel Breeze/Auth-Views und -Requests arbeiten mit `name`; die
     * Spalte heißt laut schema.sql aber `display_name`.
     */
    public function getNameAttribute(): string
    {
        return $this->display_name ?? $this->email;
    }

    public function setNameAttribute(string $value): void
    {
        $this->attributes['display_name'] = $value;
    }

    /**
     * Bug-Review (Superadmin-Nutzerseite zeigt leere Namensfelder):
     * first_name/last_name (Migration 000170) wurden bislang nur gesetzt,
     * wenn ein Superadmin einen Nutzer direkt anlegt/bearbeitet, eine
     * Bootsschule einen Admin anlegt (Superadmin\TenantController::store())
     * oder der Nutzer selbst sein Profil ausfüllt (ProfileController) --
     * Selbstregistrierung (RegisteredUserController) und Einladung
     * (Admin\ParticipantController::store()) fragen aber nur EIN
     * "Name"-Feld ab und setzten bisher ausschließlich display_name.
     * first_name/last_name blieben dadurch für praktisch jeden so
     * angelegten Nutzer leer -- die Superadmin-Bearbeitungsseite zeigte
     * diese (dort als required geführten) Felder entsprechend leer an, der
     * Superadmin musste vor jeder anderen Änderung erst den ihm unbekannten
     * Namen selbst eintippen. Beide Stellen leiten first_name/last_name
     * jetzt beim Anlegen aus demselben einzelnen Namensfeld ab.
     *
     * @return array{first_name: string, last_name: ?string}
     */
    public static function splitDisplayName(string $name): array
    {
        $name = trim($name);
        $lastSpace = strrpos($name, ' ');

        if ($lastSpace === false) {
            return ['first_name' => $name, 'last_name' => null];
        }

        return [
            'first_name' => trim(substr($name, 0, $lastSpace)),
            'last_name' => trim(substr($name, $lastSpace + 1)),
        ];
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'user_id');
    }

    public function roleForTenant(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        // 'status' => 'active' ignoriert eine gesperrte Mitgliedschaft, selbst
        // wenn das Konto selbst aktiv ist (Sicherheitsreview M1) -- sonst
        // behielte ein von einer Bootsschule entfernter/gesperrter
        // Teilnehmer dort weiter Zugang.
        return $this->tenantMemberships()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->value('role');
    }

    public function isAdminFor(?Tenant $tenant): bool
    {
        return in_array($this->roleForTenant($tenant), ['owner', 'admin'], true);
    }
}
