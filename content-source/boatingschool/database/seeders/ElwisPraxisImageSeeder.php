<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Praxistrainer (SBF See, der gespiegelte Kombikurs und SBF Binnen, Karten
 * "PRAXIS-BIN-…"): die zugeordneten Karten zeigen
 * statt der eigenen, schematischen Grafik das ELWIS-Bild, das dasselbe
 * Zeichen, Licht oder Schallsignal im amtlichen Fragenkatalog zeigt
 * (database/data/praxistrainer_elwis_bilder.csv). Es ist dasselbe Asset wie
 * an der Katalogfrage (ElwisQuestionImageSeeder, muss vorher laufen).
 *
 * Bewusst nicht ersetzt sind Karten, deren ELWIS-Gegenstück nur ähnlich
 * ist (z. B. PRAXIS-0047: ELWIS zeigt Tafeln, die Karte fragt nach roten
 * Lichtern) und alle Karten ohne Gegenstück (Manöver, Knoten, Sicherheit,
 * Feuerarten); sie haben neu gezeichnete Grafiken im selben Stil (siehe
 * refreshDrawnImages()). Die ersetzten Grafiken sind aus
 * database/data/praxistrainer_bilder/ entfernt; nicht mehr verwendete
 * Assets werden samt Datei gelöscht.
 *
 * Idempotent.
 */
class ElwisPraxisImageSeeder extends Seeder
{
    public function run(): void
    {
        $handle = fopen(database_path('data/praxistrainer_elwis_bilder.csv'), 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $row = array_combine($header, $data);
            $asset = MediaAsset::where('asset_key', 'elwis/'.$row['datei'])->first();

            if (! $asset) {
                $this->command?->warn("ELWIS-Bild für {$row['praxis_id']} fehlt: {$row['datei']}");

                continue;
            }

            // Kombikurs-Spiegel: content_id mit Suffix, z. B. "PRAXIS-0035-SEE-BIN".
            PraxisTask::where(fn ($q) => $q->where('content_id', $row['praxis_id'])->orWhere('content_id', 'like', $row['praxis_id'].'-%'))
                ->update(['media_asset_id' => $asset->id]);
        }

        fclose($handle);

        $this->refreshDrawnImages();

        $unused = MediaAsset::where('source', 'SBF See Praxistrainer')
            ->whereNotIn('id', PraxisTask::whereNotNull('media_asset_id')->select('media_asset_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('question_media')->whereColumn('question_media.media_asset_id', 'media_asset.id'))
            ->get();

        foreach ($unused as $asset) {
            Storage::disk('public')->delete($asset->getRawOriginal('storage_path'));
            $asset->delete();
        }
    }

    /**
     * Die übrigen Karten haben neu gezeichnete Grafiken im ELWIS-Stil
     * (Quellen als SVG in database/data/praxistrainer_bilder_svg/) unter
     * denselben Dateinamen wie die früheren dunkelblauen Karten.
     * PraxisTrainerSeeder legt eine Datei nur an, wenn sie fehlt -- auf
     * einer bestehenden Installation läge sonst weiter die alte Grafik im
     * Storage.
     */
    private function refreshDrawnImages(): void
    {
        foreach (MediaAsset::where('source', 'SBF See Praxistrainer')->get() as $asset) {
            // basename(): ältere Installationen speicherten hier eine volle URL.
            $filename = basename($asset->getRawOriginal('storage_path'));
            $storagePath = 'question-media/praxistrainer/'.$filename;
            $sourcePath = database_path('data/praxistrainer_bilder/'.$filename);

            if (! file_exists($sourcePath)) {
                continue;
            }

            $contents = file_get_contents($sourcePath);
            if (Storage::disk('public')->get($storagePath) !== $contents) {
                Storage::disk('public')->put($storagePath, $contents);
            }
        }
    }
}
