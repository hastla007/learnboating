<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bilder aus den Fragenkatalogen auf ELWIS (Fragenkatalog Binnen:
 * Basisfragen, Spezifische Fragen Binnen, Segeln; Fragenkatalog See als
 * PDF, Stand August 2023), siehe database/data/elwis_bilder.csv:
 *
 * - SBF See (72 spezifische Fragen, 75 verschiedene Bilder): ersetzt die
 *   früheren Bilder von bootsfuehrerscheinpruefung.de. Alle 85
 *   bisherigen Zuordnungen waren pixelgleich mit einem Bild aus dem
 *   ELWIS-PDF und sind darüber eindeutig zugeordnet. Drei 4x-Fassungen
 *   (PDF-Bild 25, 69, 80) waren im Paket leer und sind aus dem Original
 *   genauso (4x LANCZOS) neu erzeugt; die Schallsignale zu 116, 117 und
 *   140 sind neu gezeichnet (auch vierfach unter 64 px hoch).
 * - SBF Binnen (56 Fragen) und SBF Binnen Segeln 295: bisher ohne Bild,
 *   obwohl image_required -- die Binnen-Prüfungsbogen-CSVs haben keine
 *   Bilderspalte (siehe AuditMissingRequiredImages).
 * - Basisfragen 4, 5, 16-30: ersetzt die früheren Bilder aus den
 *   SBF-See-Prüfungsbögen (ExamPaperSeeder). Die waren pixelgleich mit den
 *   ELWIS-Originalen, aber nur 12-115 px groß.
 *
 * Verwendet werden die PNG-Neufassungen mit vierfacher Kantenlänge aus
 * dem ELWIS-Bilderpaket (Inhalt unverändert). Die Schallsignale zu
 * Basisfrage 4 und 5 (ein Punkt bzw. ein Balken, im Original 13x12 und
 * 27x8 px) sind auch vierfach noch zu klein und wurden in 64 px Höhe neu
 * gezeichnet. Die Fragen 192 und 193 nutzen auf ELWIS dieselbe Grafik.
 *
 * Der Alternativtext ist bewusst neutral ("Abbildung zur Frage"): die
 * ELWIS-Bildbeschreibungen nennen oft die richtige Antwort.
 *
 * Danach werden die nicht mehr verwendeten Bilder von
 * bootsfuehrerscheinpruefung.de samt Datei entfernt (siehe
 * removeUnusedLegacyAssets()); ExamPaperSeeder hängt keine mehr an.
 *
 * Die Bilder hängen an der jeweils veröffentlichten Revision; eine neue
 * Revision übernimmt sie (ContentQuestionRevision::publish(),
 * QuestionController::update()). Idempotent: eine Frage, deren Bilder
 * bereits genau dieser Liste entsprechen, bleibt unverändert.
 */
class ElwisQuestionImageSeeder extends Seeder
{
    public const LEGACY_SOURCE = 'bootsfuehrerscheinpruefung.de';

    public function run(): void
    {
        foreach ($this->groupedRows() as $key => $rows) {
            [$moduleCode, $number] = explode('#', $key);

            $module = Module::where('code', $moduleCode)->first();
            $question = $module?->questions()->where('official_number', $number)->first();
            $revision = $question?->publishedRevision();

            if (! $revision) {
                $this->command?->warn("ELWIS-Bild übersprungen: {$moduleCode} Frage {$number} ohne veröffentlichte Fassung.");

                continue;
            }

            $assetIds = array_map(fn (array $row) => $this->asset($row)->id, $rows);

            if ($revision->media()->orderBy('question_media.sort_order')->pluck('media_asset.id')->all() === $assetIds) {
                continue;
            }

            DB::transaction(function () use ($revision, $assetIds) {
                $revision->media()->detach();

                foreach ($assetIds as $index => $assetId) {
                    $revision->media()->attach($assetId, ['role' => 'question', 'sort_order' => $index + 1]);
                }
            });
        }

        $this->removeUnusedLegacyAssets();
    }

    /**
     * Die von ExamPaperSeeder früher angehängten Bilder von
     * bootsfuehrerscheinpruefung.de: nach dem Austausch hängen sie an keiner
     * Revision mehr und werden samt Datei entfernt. Ein Asset, das noch an
     * irgendeiner (auch alten) Revision hängt, bleibt -- sonst fehlte das
     * Bild in der Historie eines damaligen Versuchs.
     */
    private function removeUnusedLegacyAssets(): void
    {
        $unused = MediaAsset::where('source', self::LEGACY_SOURCE)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('question_media')->whereColumn('question_media.media_asset_id', 'media_asset.id'))
            ->get();

        foreach ($unused as $asset) {
            Storage::disk('public')->delete($asset->getRawOriginal('storage_path'));
            $asset->delete();
        }
    }

    /** @return array<string, list<array<string, string>>> */
    private function groupedRows(): array
    {
        $handle = fopen(database_path('data/elwis_bilder.csv'), 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $groups = [];

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $row = array_combine($header, $data);
            $groups[$row['modul'].'#'.$row['fragen_nr']][(int) $row['reihenfolge']] = $row;
        }

        fclose($handle);

        return array_map(function (array $rows) {
            ksort($rows);

            return array_values($rows);
        }, $groups);
    }

    /** @param array<string, string> $row */
    private function asset(array $row): MediaAsset
    {
        $storagePath = 'question-media/elwis/'.$row['datei'];
        $contents = file_get_contents(database_path('data/elwis_bilder/'.$row['datei']));

        if (Storage::disk('public')->get($storagePath) !== $contents) {
            Storage::disk('public')->put($storagePath, $contents);
        }

        // Eine Datei kann mehreren Fragen gehören (z. B. dasselbe
        // Schallsignal in SBF See 118-121) -- daher ein gemeinsames Asset
        // und ein Alternativtext ohne Fragennummer. updateOrCreate: die
        // Assets gehören ausschließlich diesem Seeder, Metadaten sollen bei
        // einer Korrektur der CSV nachgezogen werden.
        return MediaAsset::updateOrCreate(
            ['asset_key' => 'elwis/'.$row['datei']],
            [
                'media_type' => 'image',
                'storage_path' => $storagePath,
                'mime_type' => 'image/png',
                'alt_text' => 'Abbildung zur Frage',
                'source' => 'ELWIS (Wasserstraßen- und Schifffahrtsverwaltung des Bundes): '.$row['quelle'],
                'rights_status' => 'review',
            ]
        );
    }
}
