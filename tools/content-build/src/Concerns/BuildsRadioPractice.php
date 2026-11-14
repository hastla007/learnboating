<?php

declare(strict_types=1);

namespace ContentBuild\Concerns;

use RuntimeException;

/**
 * Funkpraxis (Schritt 8a) und Gerätesimulation (Schritt 8b).
 *
 * Die Szenarien entstehen in der Quelle mit App\Support\FunkPraxis aus
 * kompakten Parametern (database/data/funk_praxis_aufgaben.php). Diese Klasse
 * ist reines PHP ohne Framework-Abhängigkeit (nur tasks() liest über
 * database_path(); hier wird scenario() verwendet). Der Konverter lädt sie
 * unverändert aus dem Snapshot und schreibt fertige Lagen, Musterlösungen
 * und Prüfpunkte ins Paket – so muss das Plugin nichts aus Rohparametern
 * erzeugen, und die Ausgabe ist mit der Quelle identisch.
 *
 * Für die Gerätesimulation (database/data/geraetesimulation.php) werden
 * Funkspruch-Schritte mit „scenario“ ebenso in Prüfpunkte + Musterlösung
 * aufgelöst (wie Geraetesimulation::evaluate()/solutions()).
 */
trait BuildsRadioPractice
{
    private function loadFunkPraxis(): string
    {
        $class = 'App\\Support\\FunkPraxis';
        if (! class_exists($class, false)) {
            $file = $this->supportFile('FunkPraxis');
            $source = (string) file_get_contents($file);
            if (preg_match('/^use\s/m', $source) || ! str_contains($source, 'namespace App\\Support;')) {
                throw new RuntimeException('FunkPraxis.php hat unerwartete Abhängigkeiten; Konverter prüfen.');
            }
            require_once $file;
        }

        return $class;
    }

    /** @return list<array<string,mixed>> */
    private function radioPracticeRecords(): array
    {
        $praxis = $this->loadFunkPraxis();
        $courses = $this->radioCourses();
        $out = [];
        foreach ($this->includeData('funk_praxis_aufgaben.php') as $catalog => $specs) {
            foreach (array_values($specs) as $i => $spec) {
                $task = $praxis::scenario($catalog, $spec);
                $out[] = $this->finalize([
                    'source_id' => sprintf('%s-%02d', $catalog, $i + 1),
                    'catalog' => $catalog,
                    'number' => $i + 1,
                    'kind' => $spec['kind'],
                    'situation_de' => $task['situation_de'] ?? $task['situation'] ?? '',
                    'situation_en' => $task['situation_en'] ?? null,
                    'fields' => array_map(static fn (array $f) => [
                        'name' => $f['name'],
                        'label' => $f['label'],
                        'placeholder' => $f['placeholder'],
                        'multiline' => (bool) $f['multiline'],
                        'solution' => $f['solution'],
                        'checks' => array_map(static fn ($c) => ['label' => $c[0], 'pattern' => $c[1], 'min' => (int) $c[2]], $f['checks']),
                        'parts' => isset($f['parts']) ? array_map(static fn ($p) => ['solution' => $p['solution'], 'checks' => array_map(static fn ($c) => ['label' => $c[0], 'pattern' => $c[1], 'min' => (int) $c[2]], $p['checks'])], $f['parts']) : null,
                    ], $task['fields']),
                    'course_codes' => $courses[$catalog] ?? [],
                    'sort_order' => $i + 1,
                    'source' => 'Eigene Übungsszenarien der Quelle (erfundene Fahrzeuge, Rufzeichen, MMSI, Positionen)',
                    'rights_status' => 'review',
                    'source_file' => 'database/data/funk_praxis_aufgaben.php',
                ]);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> genau ein Datensatz mit der kompletten Simulation */
    private function deviceSimulationRecords(): array
    {
        $praxis = $this->loadFunkPraxis();
        $data = $this->includeData('geraetesimulation.php');
        $lessons = [];
        foreach ($data['lessons'] as $lesson) {
            $catalog = $lesson['catalog'] ?? 'SRC';
            foreach ($lesson['steps'] as $i => $step) {
                if (! isset($step['speech'])) {
                    continue;
                }
                $speech = $step['speech'];
                if (isset($speech['scenario'])) {
                    $task = $praxis::scenario($catalog, $speech['scenario']);
                    $message = null;
                    foreach ($task['fields'] as $field) {
                        if ($field['name'] === 'message') {
                            $message = $field;
                        }
                    }
                    if (isset($speech['part'])) {
                        $message = $message['parts'][$speech['part']];
                    }
                    $speech = ['checks' => $message['checks'], 'solution' => $message['solution'], 'scenario_kind' => $speech['scenario']['kind']];
                }
                $lesson['steps'][$i]['speech'] = [
                    'checks' => array_map(static fn ($c) => ['label' => $c[0], 'pattern' => $c[1], 'min' => (int) $c[2]], $speech['checks']),
                    'solution' => $speech['solution'],
                ];
            }
            $lessons[] = $lesson;
        }

        return [$this->finalize([
            'source_id' => 'geraetesimulation',
            'ship' => $data['ship'],
            'address_book' => $data['address_book'],
            'directory' => $data['directory'],
            'devices' => $data['devices'],
            'exams' => $data['exams'],
            'exam_meta' => $data['exam_meta'],
            'lessons' => $lessons,
            'course_codes' => array_values(array_unique(array_merge(...array_values($this->radioCourses())))),
            'rights_status' => 'review',
            'source_file' => 'database/data/geraetesimulation.php',
        ])];
    }
}
