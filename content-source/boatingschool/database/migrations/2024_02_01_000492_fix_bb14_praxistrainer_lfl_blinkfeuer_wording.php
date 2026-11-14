<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug-Review BB14: Die Praxistrainer-Karte PRAXIS-0088 ("Feuerarten auf
 * Seekarten / Blinkfeuer") beschreibt "LFl / Blk." fachlich ungenau als
 * "Lichterscheinung länger als zwei Sekunden, danach Dunkelphase" -- das
 * nennt nur die Mindestdauer des einzelnen Blinks, lässt aber das
 * entscheidende Unterscheidungsmerkmal weg: Beim Blinkfeuer ist die
 * Lichtphase KÜRZER als die Dunkelphase (sonst wäre es ein
 * Verdunkelungsfeuer). Dieselbe Definition ist im zentralen Fragenkatalog
 * bereits korrekt hinterlegt (SBF-SEE-0220: "Lichterscheinung kürzer als
 * Verdunkelung, Blink mindestens 2 s lang").
 *
 * PraxisTrainerSeeder/ComboPraxisTrainerSeeder sind guard-once-Seeder
 * (kein Reimport-Diffing wie beim zentralen Fragenkatalog), eine
 * Korrektur der CSV-Quelle allein erreicht eine bereits gesäte
 * Installation daher nicht erneut. Diese Migration zieht die Korrektur
 * für bereits vorhandene PraxisTask-Zeilen (SBF-SEE: PRAXIS-0088, sowie
 * deren Spiegelung PRAXIS-0088-SEE-BIN im Kombikurs) nach.
 */
return new class extends Migration
{
    private function oldText(): string
    {
        return 'LFl / Blk.: Blinkfeuer. Lichterscheinung länger als zwei Sekunden, danach Dunkelphase.';
    }

    private function newText(): string
    {
        return 'LFl / Blk.: Blinkfeuer (Langes Blitzfeuer). Lichterscheinung kürzer als die Dunkelphase, der einzelne Blink dauert mindestens zwei Sekunden.';
    }

    public function up(): void
    {
        DB::table('praxis_task')
            ->whereIn('content_id', ['PRAXIS-0088', 'PRAXIS-0088-SEE-BIN'])
            ->where('antwort', $this->oldText())
            ->update(['antwort' => $this->newText()]);
    }

    public function down(): void
    {
        DB::table('praxis_task')
            ->whereIn('content_id', ['PRAXIS-0088', 'PRAXIS-0088-SEE-BIN'])
            ->where('antwort', $this->newText())
            ->update(['antwort' => $this->oldText()]);
    }
};
