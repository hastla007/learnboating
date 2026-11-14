<?php

namespace Database\Seeders;

use App\Models\CourseDefinition;
use App\Models\PraxisTask;
use Illuminate\Database\Seeder;

/**
 * Praxis-Prüfungstrainer für den Kombikurs "SBF See & Binnen", die
 * Ergänzungen (Binnen -> See, Binnen -> Segeln, FKN -> SKN) sowie die
 * gemeinsamen Karten von SBF Binnen unter Segel und SKN: wie Video- und
 * Navigationsinhalte hängen
 * Praxisaufgaben am course_id des jeweiligen Kurses (siehe
 * ComboVideoCourseSeeder/ComboNavigationTaskSeeder). Die Aufgaben von
 * SBF See und SBF Binnen (Antriebsmaschine) werden daher unter der eigenen
 * Kurs-ID gespiegelt, inklusive Verweis auf bereits vorhandene Bild-Assets
 * (keine Dateiduplikate nötig). Inhaber des SBF Binnen (Antriebsmaschine)
 * fahren für SBF See praktisch nur die Pflichtmanöver (SpFV Anlage 4) --
 * die Ergänzung bekommt nur diese.
 *
 * Idempotent: fehlende Spiegelkarten werden ergänzt, vorhandene bleiben
 * unverändert (ElwisPraxisImageSeeder setzt deren Bilder danach über das
 * content_id-Präfix, z. B. "PRAXIS-BIN-0001-%").
 */
class ComboPraxisTrainerSeeder extends Seeder
{
    /**
     * Kurs-Code => Liste von [Quellkurs-Code, Suffix der content_id, Filter],
     * in Anzeigereihenfolge. Filter: null = alle Karten, sonst
     * 'nur_kategorien' und/oder 'ohne_kategorien' bzw. 'ohne_karten'
     * (Unterkategorien).
     *
     * Bug-Review (Kombikurs ohne Binnen-Praxiskarten): SBF-SEE-BIN bekam
     * bisher nur die 108 SBF-See-Karten, die 116 SBF-Binnen-Karten
     * (PRAXIS-BIN-*) fehlten, obwohl der Kombikurs auch auf die praktische
     * Binnen-Prüfung vorbereitet. Außerdem brach run() ab, sobald der
     * Zielkurs überhaupt Karten hatte -- bestehende Installationen hätten
     * die Binnen-Karten so nie erhalten.
     *
     * SBF Binnen unter Segel und SKN haben eigene Karten (PraxisTrainerSeeder);
     * sie stehen als erste "Quelle" mit leerem Suffix vorn, damit die
     * gespiegelten Karten dahinter einsortiert werden. Unter Segel entfallen
     * die Manöver unter Antriebsmaschine und die Motorboot-Karten
     * (Quickstop, Propeller, Sog und Wellenschlag); die Ergänzungen bekommen
     * nur, was zur bereits vorhandenen Fahrerlaubnis bzw. zum FKN hinzukommt.
     */
    private const TARGETS = [
        'SBF-SEE-BIN' => [
            ['SBF-SEE', '-SEE-BIN', null],
            ['SBF-BIN-MOTOR', '-SEE-BIN', null],
        ],
        'ADD-BIN-TO-SEE' => [
            ['SBF-SEE', '-BIN-SEE', ['nur_kategorien' => ['Pflichtmanöver']]],
        ],
        'SBF-BIN-SEGEL' => [
            ['SBF-BIN-SEGEL', '', null],
            ['SBF-BIN-MOTOR', '-SEGEL', [
                'ohne_kategorien' => ['Pflichtmanöver', 'Sonstige Manöver'],
                'ohne_karten' => ['Quickstop/Notstopp', 'Propellergefahr', 'Sog und Wellenschlag'],
            ]],
        ],
        'ADD-BIN-SEGEL' => [
            ['SBF-BIN-SEGEL', '-ADD-SEGEL', ['nur_kategorien' => self::SEGEL_KATEGORIEN]],
        ],
        'SKN' => [
            ['SKN', '', null],
            ['FKN', '-SKN', null],
        ],
        'ADD-FKN-TO-SKN' => [
            ['SKN', '-ADD-SKN', ['nur_kategorien' => self::SKN_KATEGORIEN]],
        ],
    ];

    /** Kategorien aus sbf_binnen_segeln_praxistrainer.csv. */
    private const SEGEL_KATEGORIEN = ['Manöver unter Segel', 'Kurse zum Wind', 'Wind und Trimm', 'Boot und Rigg', 'Notfälle unter Segel', 'Ausweichregeln unter Segel'];

    /** Kategorien aus skn_praxistrainer.csv (ohne die gespiegelten FKN-Karten). */
    private const SKN_KATEGORIEN = ['Signalpistole handhaben', 'Munition Kaliber 4', 'Aufbewahrung und Transport', 'Waffenrecht'];

    public function run(): void
    {
        foreach (self::TARGETS as $code => $sources) {
            $target = $this->globalCourse($code);

            if (! $target) {
                continue;
            }

            // Karten eines späteren Quellkurses folgen hinter denen des
            // vorherigen, statt sich über sort_order mit ihnen zu mischen.
            $sortOffset = 0;
            $coveredKarten = [];

            foreach ($sources as [$sourceCode, $suffix, $filter]) {
                $source = $this->globalCourse($sourceCode);

                if (! $source) {
                    continue;
                }

                // Karten, die eine frühere Quelle schon liefert (gleiche
                // Kategorie und Unterkategorie: Pflichtmanöver, Knoten,
                // sonstige Manöver, Sicherheit), nicht ein zweites Mal: im
                // Kombikurs stünden "Anlegen unter Maschine" usw. sonst
                // doppelt. Von SBF Binnen kommen damit die Binnen-eigenen
                // Kategorien und Binnen-eigene Karten wie "Sog und
                // Wellenschlag" oder "Großschifffahrt".
                $this->mirror($source, $target, $suffix, $filter, $sortOffset, $coveredKarten);
                foreach ($source->allPraxisTasks()->get(['kategorie', 'unterkategorie']) as $task) {
                    $coveredKarten[self::cardKey($task->kategorie, $task->unterkategorie)] = true;
                }
                $sortOffset += (int) $source->allPraxisTasks()->max('sort_order');
            }
        }
    }

    private function globalCourse(string $code): ?CourseDefinition
    {
        return CourseDefinition::withoutGlobalScopes()->where('code', $code)->whereNull('tenant_id')->first();
    }

    /**
     * Gleiche Karte trotz anderer Wortstellung ("Wind und Strom" / "Strom
     * und Wind"): Vergleich über die sortierten Wörter.
     */
    private static function cardKey(string $kategorie, ?string $unterkategorie): string
    {
        $words = preg_split('/\s+/u', mb_strtolower((string) $unterkategorie), -1, PREG_SPLIT_NO_EMPTY);
        sort($words);

        return $kategorie.'|'.implode(' ', $words);
    }

    /**
     * @param  array{nur_kategorien?: list<string>, ohne_kategorien?: list<string>, ohne_karten?: list<string>}|null  $filter
     * @param  array<string, true>  $skipKarten
     */
    private function mirror(CourseDefinition $source, CourseDefinition $target, string $suffix, ?array $filter, int $sortOffset, array $skipKarten): void
    {
        $existing = $target->allPraxisTasks()->pluck('content_id')->flip();

        $tasks = $source->allPraxisTasks()
            ->when($filter['nur_kategorien'] ?? null, fn ($q, $kategorien) => $q->whereIn('kategorie', $kategorien))
            ->when($filter['ohne_kategorien'] ?? null, fn ($q, $kategorien) => $q->whereNotIn('kategorie', $kategorien))
            ->when($filter['ohne_karten'] ?? null, fn ($q, $karten) => $q->whereNotIn('unterkategorie', $karten))
            ->get()
            ->reject(fn (PraxisTask $task) => isset($skipKarten[self::cardKey($task->kategorie, $task->unterkategorie)]));

        foreach ($tasks as $sourceTask) {
            if ($existing->has($sourceTask->content_id.$suffix)) {
                continue;
            }

            PraxisTask::create([
                'course_id' => $target->id,
                'content_id' => $sourceTask->content_id.$suffix,
                'kategorie' => $sourceTask->kategorie,
                'unterkategorie' => $sourceTask->unterkategorie,
                'pruefungsbezug' => $sourceTask->pruefungsbezug,
                'aufgabentyp' => $sourceTask->aufgabentyp,
                'frage' => $sourceTask->frage,
                'antwort' => $sourceTask->antwort,
                'erklaerung' => $sourceTask->erklaerung,
                'media_asset_id' => $sourceTask->media_asset_id,
                'quelle' => $sourceTask->quelle,
                'quellen_url' => $sourceTask->quellen_url,
                'sort_order' => $sourceTask->sort_order + $sortOffset,
            ]);
        }
    }
}
