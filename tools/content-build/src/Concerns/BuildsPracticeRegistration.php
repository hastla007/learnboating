<?php

declare(strict_types=1);

namespace ContentBuild\Concerns;

use ContentBuild\Csv;
use RuntimeException;

/**
 * Prüfungsausschüsse und Praxisformulare (Praxis-Anmeldung ohne Prüfungsbuchung).
 *
 * - Prüfungsausschüsse: database/data/pruefungsausschuesse.csv (DMYV-Liste),
 *   wie PruefungsausschussSeeder (leere Werte → null, Reihenfolge = sort_order,
 *   Spalte „ort“ → Stadt). course_ids pflegt die Quelle im Superadmin; im
 *   Snapshot sind sie leer.
 * - Praxisformulare: PraxisFormTemplateSeeder – Feldlisten aus den Konstanten
 *   PRAXIS_FIELDS/THEORIE_FIELDS (Tokenizer, ohne Ausführung), Formulare aus den
 *   Aufrufen $this->template(Titel, Schwelle, self::KONSTANTE, [Kurscodes], requiresTauglichkeit: …).
 */
trait BuildsPracticeRegistration
{
    /** @return list<array<string,mixed>> */
    private function examCommitteeRecords(): array
    {
        $out = [];
        $seen = [];
        foreach (Csv::read($this->data.'/pruefungsausschuesse.csv', ',') as $i => $row) {
            if (trim(implode('', $row)) === '') {
                continue;
            }
            $null = static fn (string $key) => ($row[$key] ?? '') === '' ? null : $row[$key];
            $id = self::slugId($row['kommission'].($row['standorttyp'] !== '' ? '-'.$row['standorttyp'] : ''));
            if (isset($seen[$id])) {
                throw new RuntimeException("Prüfungsausschuss doppelt: {$id}");
            }
            $seen[$id] = true;
            $out[] = $this->finalize([
                'source_id' => $id,
                'commission' => $row['kommission'],
                'location_type' => $null('standorttyp'),
                'contact_person' => $null('kontaktperson'),
                'street' => $null('strasse'),
                'postal_code' => $null('plz'),
                'city' => $null('ort'),
                'phone' => $null('telefon'),
                'fax' => $null('fax'),
                'email' => $null('email'),
                'website' => $null('website'),
                'office_hours' => $null('sprechzeiten'),
                'note' => $null('hinweis'),
                'source_url' => $null('quelle'),
                'course_codes' => [],
                'sort_order' => count($out),
                'rights_status' => 'review',
                'source_file' => 'database/data/pruefungsausschuesse.csv',
            ]);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function praxisFormRecords(): array
    {
        $file = $this->seederFile('PraxisFormTemplateSeeder');
        $source = (string) file_get_contents($file);
        preg_match_all("/\\\$this->template\\(\\s*'([^']+)'\\s*,\\s*(\\d+)\\s*,\\s*self::([A-Z_]+)\\s*,\\s*\\[([^\\]]*)\\](?:\\s*,\\s*requiresTauglichkeit:\\s*(true|false))?\\s*\\)/", $source, $calls, PREG_SET_ORDER);
        if ($calls === []) {
            throw new RuntimeException('PraxisFormTemplateSeeder: keine Formulare gefunden; Konverter prüfen.');
        }
        $out = [];
        foreach ($calls as $i => $call) {
            preg_match_all("/'([A-Z0-9-]+)'/", $call[4], $codes);
            $fields = array_map(static fn (array $f) => [
                'key' => (string) $f['key'],
                'label' => (string) $f['label'],
                'type' => (string) $f['type'],
                'required' => (bool) $f['required'],
                'options' => array_values(array_map('strval', $f['options'] ?? [])),
                'help_text' => $f['help_text'] ?? null,
            ], \ContentBuild\SourceLiterals::constant($file, $call[3]));
            $out[] = $this->finalize([
                'source_id' => self::slugId($call[1]),
                'title' => $call[1],
                'min_progress_percent' => (int) $call[2],
                'requires_fitness_certificate' => ($call[5] ?? 'false') === 'true',
                'fields' => $fields,
                'course_codes' => $codes[1],
                'active' => true,
                'sort_order' => $i,
                'rights_status' => 'review',
                'source_file' => 'database/seeders/PraxisFormTemplateSeeder.php',
            ]);
        }

        return $out;
    }

    private static function slugId(string $text): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss', '&' => 'und'];
        $slug = strtolower(strtr($text, $map));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

        return $slug;
    }
}
