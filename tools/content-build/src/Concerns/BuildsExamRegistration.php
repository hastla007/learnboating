<?php

declare(strict_types=1);

namespace ContentBuild\Concerns;

use ContentBuild\Csv;
use RuntimeException;

/**
 * Prüfungsausschüsse und Anleitungen zur Prüfungsanmeldung (Selbstanmeldung der Lernenden).
 *
 * - Prüfungsausschüsse: database/data/pruefungsausschuesse.csv (DMYV-Liste),
 *   wie PruefungsausschussSeeder (leere Werte → null, Reihenfolge = sort_order,
 *   Spalte „ort“ → Stadt). course_ids pflegt die Quelle im Superadmin; im
 *   Snapshot sind sie leer.
 * - Anleitungen „Prüfung buchen“: database/data/pruefung_buchen.php (siehe examGuideRecords()).
 */
trait BuildsExamRegistration
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

    /**
     * Anleitungen „Prüfung buchen“ (App\Support\ExamBooking, database/data/pruefung_buchen.php):
     * eine Seite je Prüfung mit Gebühr, Fristen, Unterlagen, Varianten, Prüfungsteilen und FAQ.
     * Die Datei der Quelle wird wie von ExamBooking eingebunden (FAQ-Bausteine sind dort
     * Closures); die Kurszuordnung („Seite => Variante, die zuerst steht“) wird je Seite mitgeschrieben.
     *
     * @return list<array<string,mixed>>
     */
    private function examGuideRecords(): array
    {
        $data = $this->includeData('pruefung_buchen.php');
        $courses = [];
        foreach ($data['courses'] as $course => $pages) {
            $i = 0;
            foreach ($pages as $page => $first) {
                $courses[$page][] = ['course_code' => (string) $course, 'first_variant' => (string) $first, 'tab_order' => $i++];
            }
        }
        $out = [];
        $sort = 0;
        foreach ($data['pages'] as $key => $page) {
            $variants = [];
            foreach ($page['variants'] as $vKey => $v) {
                $variants[] = [
                    'key' => (string) $vKey,
                    'label' => (string) $v['label'],
                    'course_code' => $v['course'] ?? null,
                    'parts' => array_map(static fn (array $p) => [
                        'module' => (string) $p['module'],
                        'label' => $p['label'] ?? null,
                        'of' => isset($p['of']) ? (int) $p['of'] : null,
                        'min' => isset($p['min']) ? (int) $p['min'] : null,
                    ], $v['parts']),
                    'minutes' => isset($v['minutes']) ? (int) $v['minutes'] : null,
                    'pass' => $v['pass'] ?? null,
                    'extra' => $v['extra'] ?? null,
                    'note' => $v['note'] ?? null,
                ];
            }
            $faq = [];
            foreach ($page['faq'] as $question => $answer) {
                $faq[] = ['question' => (string) $question, 'answer' => (string) $answer];
            }
            $out[] = $this->finalize([
                'source_id' => (string) $key,
                'tab' => (string) $page['tab'],
                'title' => (string) $page['title'],
                'intro' => (string) $page['intro'],
                'fee' => (string) $page['fee'],
                'lead_time' => $page['lead_time'] ?? null,
                'min_age' => (string) $page['min_age'],
                'apply' => (string) $page['apply'],
                'deadline' => (string) $page['deadline'],
                'documents' => array_values(array_map('strval', $page['documents'])),
                'variants' => $variants,
                'exam_parts' => array_map(static fn (array $e) => ['key' => (string) $e['key'], 'title' => (string) $e['title'], 'link' => $e['link'] ?? null, 'text' => (string) $e['text']], $page['exam_parts']),
                'faq' => $faq,
                'providers' => array_map(static fn (array $p) => ['name' => (string) $p['name'], 'full' => (string) $p['full'], 'url' => (string) $p['url'], 'text' => (string) $p['text']], $data['providers']),
                'courses' => $courses[$key] ?? [],
                'sort_order' => $sort++,
                'rights_status' => 'review',
                'source_file' => 'database/data/pruefung_buchen.php',
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
