<?php

use Database\Seeders\SrcUbiComboExamPaperSeeder;
use Database\Seeders\UbiExamPaperSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Bug-Review (SRC & UBI Kombikurs: UBI-Prüfungsbögen fehlen auf bereits
 * provisionierten Installationen): UbiExamPaperSeeder und
 * SrcUbiComboExamPaperSeeder kamen erst nachträglich zu DatabaseSeeder
 * hinzu und erreichen eine bestehende Installation nur über einen
 * erneuten db:seed-Lauf. Diese Migration ruft beide Seeder direkt auf --
 * mit denselben Daten wie db:seed (die zwölf amtlichen UBI-Übungsbögen aus
 * ubi_uebungsboegen.csv, die UBI-Ergänzungsbögen und die Spiegelung in den
 * Kombikurs). Eine frühere Fassung legte hier fest kodierte, vorläufige
 * Bögen (6 x 22 Fragen in Katalogreihenfolge) an; die Seeder hätten danach
 * wegen ihres exists()-Guards die amtlichen Bögen nie mehr eingespielt.
 *
 * Auf einer Neuinstallation (noch keine Kurse) und auf Installationen, die
 * die Bögen schon haben, tun beide Seeder nichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new UbiExamPaperSeeder)->run();
        (new SrcUbiComboExamPaperSeeder)->run();
    }

    public function down(): void {}
};
