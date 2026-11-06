<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Prüfungsausschuss-Adresse (ursprünglich aus der DMYV-Liste importiert,
 * siehe PruefungsausschussSeeder), plattformweit gültig -- keine
 * Tenant-Bindung, keine RLS. Im Superadmin verwaltet (Superadmin\
 * PruefungsausschussController), für die Bootsschule nur lesend als
 * Auswahl je Praxisort (siehe tenant_course_location.pruefungsausschuss_id).
 */
class Pruefungsausschuss extends Model
{
    use HasUuids;

    protected $table = 'pruefungsausschuss';

    protected $fillable = [
        'kommission', 'standorttyp', 'kontaktperson', 'strasse', 'plz', 'stadt',
        'telefon', 'fax', 'email', 'website', 'sprechzeiten', 'hinweis', 'quelle', 'sort_order',
        'course_ids',
    ];

    protected function casts(): array
    {
        return ['course_ids' => 'array'];
    }

    /** "Kommission — Standorttyp" oder nur "Kommission", wenn kein Standorttyp gepflegt ist. */
    public function label(): string
    {
        return $this->standorttyp ? "{$this->kommission} — {$this->standorttyp}" : $this->kommission;
    }

    /** "Straße, PLZ Stadt" -- null, wenn keines der Adressfelder gepflegt ist. */
    public function adresse(): ?string
    {
        $plzStadt = trim(implode(' ', array_filter([$this->plz, $this->stadt])));
        $parts = array_filter([$this->strasse, $plzStadt]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
