<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * O2: BrandingController/TipController speicherten in storage_path bislang
 * die zur Upload-Zeit per Storage::disk('public')->url() aufgelöste volle
 * URL (inkl. Host, in lokaler Entwicklung also "http://localhost:8000/...")
 * statt des relativen Pfads -- siehe MediaAsset::storagePath() für den
 * eigentlichen Fix (Accessor löst die URL jetzt immer frisch aus der
 * aktuellen Konfiguration auf, Mutator speichert nur noch relativ). Diese
 * Migration normalisiert den zum Zeitpunkt des Deploys noch erreichbaren
 * Altbestand einmalig: nur solange der hier bekannte Host noch derselbe
 * ist wie beim ursprünglichen Upload, lässt sich der Präfix zuverlässig
 * abschneiden.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Storage::disk('public')->url('');

        DB::table('media_asset')
            ->where('storage_path', 'like', $prefix.'%')
            ->get(['id', 'storage_path'])
            ->each(function ($row) use ($prefix) {
                DB::table('media_asset')
                    ->where('id', $row->id)
                    ->update(['storage_path' => substr($row->storage_path, strlen($prefix))]);
            });
    }

    public function down(): void
    {
        // Absichtlich kein Rollback: welche Zeilen vor "up" bereits relativ
        // waren (und welche durch "up" umgeschrieben wurden) ist danach
        // nicht mehr unterscheidbar, ein pauschales Zurück-Voranstellen des
        // Präfixes würde ursprünglich schon relative Pfade kaputt machen.
    }
};
