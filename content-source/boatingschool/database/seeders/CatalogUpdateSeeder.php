<?php

namespace Database\Seeders;

use App\Models\ContentQuestion;
use Illuminate\Database\Seeder;

/**
 * Schaltet Aktualisierungen aus amtlichen Katalogen auf bestehenden
 * Installationen frei.
 *
 * Eine in zentrale_fragenbasis.xlsx geänderte Zeile legt content:import
 * auf einer frischen Installation direkt als veröffentlichte erste
 * Revision an; auf einer bestehenden Installation erzeugt es dagegen eine
 * neue Revision im Status "review", die Lernende erst nach Freigabe sehen
 * (siehe ImportContentQuestions). Für den Text eines amtlich
 * veröffentlichten Katalogs gibt es nichts redaktionell freizugeben --
 * dieser Seeder veröffentlicht genau die Revisionen mit der unten
 * genannten source_version, die bisherige Fassung bleibt als "deprecated"
 * erhalten (Attempts und Prüfungssitzungen verweisen weiterhin auf die
 * damals gezeigte Revision).
 *
 * - UBI-Gesamtfragenkatalog Stand Oktober 2026 (ABVT Koblenz, gültig ab
 *   01.10.2026): 65 geänderte Fragen. Feedback-Texte und Bilder der alten
 *   Fassung werden NICHT übernommen -- unter 16 Nummern steht eine andere
 *   Frage bzw. eine andere richtige Antwort.
 * - SBF See 279 (ELWIS Fragenkatalog See, Stand August 2023): im PDF
 *   stehen Morsezeichen als Bild mitten in den Antworten (a-c SOS, d
 *   umgekehrt). Ohne sie waren die richtige Antwort A und die falsche D
 *   wortgleich; die Zeichen stehen jetzt als Text (mit geschützten
 *   Leerzeichen, damit sie nicht umbrechen) in den Antworten. Feedback
 *   bleibt gültig und wird übernommen.
 * - UBI-Korrekturen zum Katalog 10/2026 (source_version "2026-10-korr"),
 *   abgeglichen mit den amtlichen Übungsfragebögen derselben Ausgabe:
 *   Nr. 127 d) "eindringende" -> "eine dringende" medizinische Meldung,
 *   Nr. 11 fehlender zweiter Gedankenstrich ("des Bundes - das"), und in
 *   Nr. 88, 93 und 104 zerlegte Umlaute (u + U+0308) als ein Zeichen
 *   (NFC). Der Sinn bleibt gleich: Feedback und Bilder werden
 *   übernommen, der Lernfortschritt bleibt erhalten -- aber nur, wenn
 *   gerade die importierte Fassung 10/2026 veröffentlicht ist
 *   ('correction_of'). Wartet 10/2026 auf einer Installation noch als
 *   "review" (Nr. 93 ist eine der dort ersetzten Fragen), ersetzt die
 *   Korrektur direkt die ältere Ausgabe und wird wie 10/2026 selbst
 *   veröffentlicht; die übersprungene 10/2026-Revision wird "deprecated".
 *   Hat ein Superadmin die Frage im Editor korrigiert, bleibt die
 *   Korrektur zur Freigabe im Status "review" liegen.
 * - SRC-Korrekturen (source_version "2026-09-korr"): an Antwort D von 60
 *   SRC-Fragen hing die Fußzeile des amtlichen PDFs ("Gesamtfragenkatalog
 *   für das ... (SRC, Short Range Certificate)", einmal samt
 *   Kapitelüberschrift). Der Sinn bleibt gleich: Feedback, Bilder und
 *   Lernfortschritt werden übernommen, solange die importierte Fassung
 *   2026-09 veröffentlicht ist; eine Editor-Korrektur bleibt unberührt.
 * - Zweite Korrektur SRC und UBI (source_version "2026-09-korr2" bzw.
 *   "2026-10-korr2"): die amtliche Nummer am Ende jeder Frage ("… ? [53]")
 *   ist entfernt, sie stand als Rest des PDFs im Fragetext (bei Nr. 44 und
 *   57 zudem abgeschnitten). Feedback, Bilder und Lernfortschritt bleiben,
 *   wenn eine der früheren Fassungen derselben Ausgabe veröffentlicht ist
 *   (correction_of darf mehrere Fassungen nennen).
 *
 * Idempotent: ohne wartende Revision tut ein erneuter Lauf nichts.
 */
class CatalogUpdateSeeder extends Seeder
{
    public const UBI_2026_10 = '2026-10';

    public const SEE_2023_08_ELWIS = '2023-08-elwis';

    public const UBI_2026_10_KORR = '2026-10-korr';

    public const SRC_2026_09_KORR = '2026-09-korr';

    public const SRC_2026_09_KORR2 = '2026-09-korr2';

    public const UBI_2026_10_KORR2 = '2026-10-korr2';

    private const UPDATES = [
        ['content_role' => 'ubi_official', 'source_version' => self::UBI_2026_10, 'inherit_editorial_content' => false],
        ['content_role' => 'see_specific', 'source_version' => self::SEE_2023_08_ELWIS, 'inherit_editorial_content' => true],
        ['content_role' => 'ubi_official', 'source_version' => self::UBI_2026_10_KORR, 'inherit_editorial_content' => true, 'reset_progress' => false, 'correction_of' => self::UBI_2026_10],
        ['content_role' => 'src_official', 'source_version' => self::SRC_2026_09_KORR, 'inherit_editorial_content' => true, 'reset_progress' => false, 'correction_of' => '2026-09'],
        ['content_role' => 'src_official', 'source_version' => self::SRC_2026_09_KORR2, 'inherit_editorial_content' => true, 'reset_progress' => false, 'correction_of' => ['2026-09', self::SRC_2026_09_KORR]],
        ['content_role' => 'ubi_official', 'source_version' => self::UBI_2026_10_KORR2, 'inherit_editorial_content' => true, 'reset_progress' => false, 'correction_of' => [self::UBI_2026_10, self::UBI_2026_10_KORR]],
    ];

    public function run(): void
    {
        foreach (self::UPDATES as $update) {
            foreach (ContentQuestion::where('content_role', $update['content_role'])->get() as $question) {
                $latest = $question->revisions()->orderByDesc('revision_no')->first();

                if (! $latest || $latest->editorial_status !== 'review' || $latest->source_version !== $update['source_version']) {
                    continue;
                }

                $inherit = $update['inherit_editorial_content'];
                $resetProgress = $update['reset_progress'] ?? true;

                if (isset($update['correction_of'])) {
                    $correctionOf = (array) $update['correction_of'];
                    $published = $question->revisions()->where('editorial_status', 'published')->first();

                    if ($published?->edited_by_superadmin) {
                        continue;
                    }

                    if (! in_array($published?->source_version, $correctionOf, true)) {
                        $inherit = false;
                        $resetProgress = true;
                    }

                    // Ältere, noch wartende Fassungen derselben Ausgabe ersetzt die Korrektur.
                    $question->revisions()->where('editorial_status', 'review')
                        ->whereKeyNot($latest->id)
                        ->whereIn('source_version', $correctionOf)
                        ->update(['editorial_status' => 'deprecated']);
                }

                $latest->publish(inheritEditorialContent: $inherit, resetProgressOnContentChange: $resetProgress);
            }
        }
    }
}
