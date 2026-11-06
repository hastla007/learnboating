<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Praxistrainer FKN/SKN nach den Fragen zur praktischen Prüfung des
 * Prüfungsausschusses Nord: Die Signalpistole wird bei abwärts gerichteter
 * Mündung geladen und mit nach oben gerichtetem Lauf über Augenhöhe
 * abgefeuert (die Karten beschrieben das Laden bisher mit Mündung nach
 * oben); die vier amtlichen FKN-Praxisfragen und die SKN-Versagerfrage
 * nennen jetzt diese Quelle.
 *
 * PraxisTrainerSeeder/ComboPraxisTrainerSeeder legen Karten nur einmal an
 * (siehe 000492). Diese Migration übernimmt die geänderten Karten aus den
 * CSV-Quellen in bestehende Installationen, samt ihren Spiegelungen
 * (z. B. "PRAXIS-FKN-0006-SKN", "PRAXIS-SKN-0002-ADD-SKN"). Auf einer
 * frischen Datenbank gibt es noch keine Karten, dann ist sie ein No-op.
 */
return new class extends Migration
{
    private const CARDS = [
        'fkn_praxistrainer.csv' => ['PRAXIS-FKN-0006', 'PRAXIS-FKN-0007', 'PRAXIS-FKN-0008', 'PRAXIS-FKN-0009'],
        'skn_praxistrainer.csv' => ['PRAXIS-SKN-0002', 'PRAXIS-SKN-0003', 'PRAXIS-SKN-0004', 'PRAXIS-SKN-0005'],
    ];

    public function up(): void
    {
        foreach (self::CARDS as $file => $ids) {
            $rows = $this->readCsv(database_path('data/'.$file));

            foreach ($ids as $id) {
                $row = $rows[$id] ?? null;

                if (! $row) {
                    continue;
                }

                DB::table('praxis_task')
                    ->where(fn ($q) => $q->where('content_id', $id)->orWhere('content_id', 'like', $id.'-%'))
                    ->update([
                        'pruefungsbezug' => $row['pruefungsbezug'] ?: null,
                        'frage' => $row['frage'],
                        'antwort' => $row['antwort'],
                        'erklaerung' => $row['erklaerung'] ?: null,
                        'quelle' => $row['quelle'] ?: null,
                        'quellen_url' => $row['quellen_url'] ?: null,
                    ]);
            }
        }
    }

    /** @return array<string, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';', '"', '\\');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $rows = [];

        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            $row = array_combine($header, $data);
            $rows[$row['id']] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Kein Rückweg: die alte Ladeanleitung war fachlich falsch.
     */
    public function down(): void {}
};
