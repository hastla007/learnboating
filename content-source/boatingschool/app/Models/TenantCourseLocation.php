<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCourseLocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_course_location';

    protected $fillable = [
        'tenant_id',
        'praxis_ort', 'praxis_strasse', 'praxis_plz', 'praxis_stadt', 'praxis_land', 'praxis_lat', 'praxis_lng',
        'pruefung_ort', 'pruefung_strasse', 'pruefung_plz', 'pruefung_stadt', 'pruefung_land', 'pruefung_lat', 'pruefung_lng',
        'theorie_ort', 'theorie_strasse', 'theorie_plz', 'theorie_stadt', 'theorie_land', 'theorie_lat', 'theorie_lng',
        'pruefungsausschuss_id', 'course_ids', 'sort_order',
    ];

    public function pruefungsausschuss(): BelongsTo
    {
        return $this->belongsTo(Pruefungsausschuss::class);
    }

    /**
     * Formatierte Anschrift für Anzeige/Export (z. B. in der Anmeldungsübersicht
     * der Bootsschule) -- gibt null zurück, wenn keines der Adressfelder gepflegt ist.
     */
    public function praxisAdresse(): ?string
    {
        return self::formatAddress($this->praxis_strasse, $this->praxis_plz, $this->praxis_stadt, $this->praxis_land);
    }

    public function pruefungAdresse(): ?string
    {
        return self::formatAddress($this->pruefung_strasse, $this->pruefung_plz, $this->pruefung_stadt, $this->pruefung_land);
    }

    /**
     * Anders als Praxis- und Prüfungsort ist der Theorieort rein optional
     * und hat keinen Fallback -- gibt null zurück, wenn nicht gepflegt.
     */
    public function theorieAdresse(): ?string
    {
        return self::formatAddress($this->theorie_strasse, $this->theorie_plz, $this->theorie_stadt, $this->theorie_land);
    }

    public static function formatAddress(?string $strasse, ?string $plz, ?string $stadt, ?string $land): ?string
    {
        $plzStadt = trim(implode(' ', array_filter([$plz, $stadt])));
        $parts = array_filter([$strasse, $plzStadt, $land]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    protected function casts(): array
    {
        return [
            'course_ids' => 'array',
            'sort_order' => 'integer',
            'praxis_lat' => 'float',
            'praxis_lng' => 'float',
            'pruefung_lat' => 'float',
            'pruefung_lng' => 'float',
            'theorie_lat' => 'float',
            'theorie_lng' => 'float',
        ];
    }

    public function hasPruefungCoordinates(): bool
    {
        return $this->pruefung_lat !== null && $this->pruefung_lng !== null;
    }

    public function hasTheorieOrt(): bool
    {
        return $this->theorie_ort !== null && trim($this->theorie_ort) !== '';
    }
}
