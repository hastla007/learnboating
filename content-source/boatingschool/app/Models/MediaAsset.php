<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaAsset extends Model
{
    use HasUuids;

    protected $table = 'media_asset';

    protected $fillable = [
        'asset_key', 'media_type', 'storage_path', 'mime_type', 'alt_text',
        'source', 'rights_status', 'rights_owner', 'license_reference', 'status',
    ];

    /**
     * O2: BrandingController/TipController schrieben hier bislang die
     * ZUR UPLOAD-ZEIT per Storage::disk('public')->url() aufgelöste, volle
     * URL (inkl. Host, in lokaler Entwicklung also "http://localhost:8000/
     * ...") in die Datenbank -- zöge die Installation später auf eine
     * andere Domain oder einen CDN-Host um, blieben alle vorher
     * hochgeladenen Logos/PDFs auf den alten Host verweisen. Die Spalte
     * speichert jetzt nur noch den relativen Storage-Pfad; die volle URL
     * wird beim Lesen stets frisch aus der AKTUELLEN App-Konfiguration
     * aufgelöst. Bereits vor diesem Fix gespeicherte volle URLs (alter
     * Host) werden beim Lesen unverändert durchgereicht, damit bestehende
     * Links nicht plötzlich brechen -- Migration 000463 normalisiert den
     * noch erreichbaren Altbestand zusätzlich auf relative Pfade.
     */
    protected function storagePath(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null || Str::startsWith($value, ['http://', 'https://'])) {
                    return $value;
                }

                return Storage::disk('public')->url($value);
            },
            set: function (?string $value) {
                if ($value === null) {
                    return $value;
                }

                $prefix = Storage::disk('public')->url('');

                return Str::startsWith($value, $prefix) ? substr($value, strlen($prefix)) : $value;
            },
        );
    }
}
