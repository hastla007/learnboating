<?php

declare(strict_types=1);

namespace ContentBuild\Concerns;

use ContentBuild\SourceLiterals;
use ContentBuild\Text;

/**
 * Funktexte und Vokabeln wie App\Support\FunkTraining der Quelle:
 * - SRC-Seefunktexte (Mitschrift + Übersetzung), Schlüssel TXT-SRC-01 …
 * - UBI-Aufnahme (Diktat) UBI-AUF-01 … und Abgabe (Buchstabieren) UBI-ABG-01 …
 * - SRC-Vokabeln VOC-SRC-<md5(de|key)>, UBI-Buchstabiertafel VOC-UBI-<Buchstabe>.
 * Die Schlüssel sind die Fortschrittsschlüssel der Quelle (Positionen bzw.
 * Hash), daher unverändert übernommen.
 */
trait BuildsRadioTraining
{
    /** @return array<string,list<string>> Katalog => Kurscodes (FunkTraining::COURSES umgekehrt) */
    private function radioCourses(): array
    {
        $byCatalog = [];
        foreach (SourceLiterals::constant($this->supportFile('FunkTraining'), 'COURSES') as $course => $catalogs) {
            foreach ($catalogs as $catalog) {
                $byCatalog[$catalog][] = $course;
            }
        }

        return $byCatalog;
    }

    /** @return list<array<string,mixed>> */
    private function radioTextRecords(): array
    {
        $courses = $this->radioCourses();
        $out = [];
        $source = 'Eigene Übungstexte der Quelle im Stil des amtlichen Katalogs (siehe Dateikopf)';
        foreach ($this->includeData('funk_seefunktexte.php') as $catalog => $texts) {
            foreach (array_values($texts) as $i => $text) {
                $out[] = $this->finalize([
                    'source_id' => sprintf('TXT-%s-%02d', $catalog, $i + 1),
                    'catalog' => $catalog,
                    'exercise_type' => 'translation',
                    'number' => $i + 1,
                    'direction' => $text['richtung'] ?? null,
                    'text_de' => Text::clean($text['de']),
                    'text_en' => Text::clean($text['en']),
                    'lines' => null,
                    'course_codes' => $courses[$catalog] ?? [],
                    'sort_order' => $i + 1,
                    'source' => $source,
                    'rights_status' => 'review',
                    'source_file' => 'database/data/funk_seefunktexte.php',
                ]);
            }
        }
        foreach ($this->includeData('funk_ubi_texte.php') as $modul => $items) {
            $prefix = $modul === 'abgabe' ? 'ABG' : 'AUF';
            foreach (array_values($items) as $i => $item) {
                $out[] = $this->finalize([
                    'source_id' => sprintf('UBI-%s-%02d', $prefix, $i + 1),
                    'catalog' => 'UBI',
                    'exercise_type' => $modul === 'abgabe' ? 'spelling' : 'dictation',
                    'number' => $i + 1,
                    'direction' => null,
                    'text_de' => is_array($item) ? null : Text::clean($item),
                    'text_en' => null,
                    'lines' => is_array($item) ? array_map([Text::class, 'clean'], $item) : null,
                    'course_codes' => $courses['UBI'] ?? [],
                    'sort_order' => $i + 1,
                    'source' => $source,
                    'rights_status' => 'review',
                    'source_file' => 'database/data/funk_ubi_texte.php',
                ]);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function radioVocabularyRecords(): array
    {
        $courses = $this->radioCourses();
        $out = [];
        $sort = 0;
        foreach ($this->includeData('funk_vokabeln.php') as $catalog => $categories) {
            foreach ($categories as $category => $entries) {
                foreach ($entries as $entry) {
                    $out[] = $this->finalize([
                        'source_id' => sprintf('VOC-%s-%s', $catalog, substr(md5($entry[0].'|'.($entry[2] ?? $entry[1])), 0, 12)),
                        'catalog' => $catalog,
                        'category' => Text::clean($category),
                        'term_de' => Text::clean($entry[0]),
                        'term_en' => Text::clean($entry[1]),
                        'hint' => null,
                        'course_codes' => $courses[$catalog] ?? [],
                        'sort_order' => ++$sort,
                        'rights_status' => 'review',
                        'source_file' => 'database/data/funk_vokabeln.php',
                    ]);
                }
            }
        }
        foreach (SourceLiterals::constant($this->supportFile('FunkTraining'), 'ALPHABET') as $letter => [$word, $pronunciation]) {
            $out[] = $this->finalize([
                'source_id' => 'VOC-UBI-'.$letter,
                'catalog' => 'UBI',
                'category' => 'Buchstabiertafel',
                'term_de' => (string) $letter,
                'term_en' => $word,
                'hint' => $pronunciation,
                'course_codes' => $courses['UBI'] ?? [],
                'sort_order' => ++$sort,
                'rights_status' => 'public',
                'source_file' => 'app/Support/FunkTraining.php',
            ]);
        }
        $ids = array_column($out, 'source_id');
        if (count($ids) !== count(array_unique($ids))) {
            $this->report->warn('vocabulary_duplicate_key', 'radio_vocabulary', null, 'Doppelte Vokabelschlüssel in funk_vokabeln.php.');
        }

        return $out;
    }
}
