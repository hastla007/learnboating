<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisTask extends Model
{
    use HasUuids;

    protected $table = 'praxis_task';

    public $timestamps = false;

    protected $fillable = [
        'course_id', 'content_id', 'kategorie', 'unterkategorie', 'pruefungsbezug',
        'aufgabentyp', 'frage', 'antwort', 'erklaerung', 'media_asset_id',
        'quelle', 'quellen_url', 'sort_order', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Kategorien, in denen die Aufgabe den Namen des Knotens/Manövers bereits
     * in der Frage nennt ("Führe den Knoten X vor ...") und das Bild die
     * korrekt ausgeführte Lösung zeigt -- im Gegensatz zu "Was bedeutet das
     * dargestellte Zeichen/Signal/Feuer?", wo das Bild zur Frage gehört und
     * zum Beantworten benötigt wird.
     */
    private const CATEGORIES_WHERE_IMAGE_IS_THE_SOLUTION = [
        'Pflichtmanöver', 'Sonstige Manöver', 'Seemannsknoten', 'Sicherheit und Crew',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function imageBelongsToSolution(): bool
    {
        return in_array($this->kategorie, self::CATEGORIES_WHERE_IMAGE_IS_THE_SOLUTION, true);
    }

    /**
     * Bug-Review (Hoch): Seitentitel, Aufgabenliste und Bild-Alt-Text fielen
     * bisher auf unterkategorie zurück, sobald es gesetzt war -- in genau
     * den Kategorien, in denen imageBelongsToSolution() false liefert ("Was
     * bedeutet das dargestellte Zeichen/Signal/Feuer?"), IST unterkategorie
     * aber die Lösung selbst (z. B. "Überholverbot", "Festfeuer",
     * "Maschinenfahrzeug unter 50 m, von vorn") -- sie stand damit VOR dem
     * Klick auf "Musterlösung anzeigen" bereits sichtbar da. In den übrigen
     * Kategorien ("Führe den Knoten/das Manöver X vor ...") nennt die Frage
     * unterkategorie bereits selbst, ein Anzeigen dort verrät nichts
     * Zusätzliches.
     */
    public function unterkategorieIsSafeToDisplayBeforeReveal(): bool
    {
        return $this->imageBelongsToSolution();
    }
}
