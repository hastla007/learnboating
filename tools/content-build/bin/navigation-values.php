<?php

declare(strict_types=1);

/*
 * Pflegewerkzeug für data/navigation_answer_values.json (strukturierte Lösungswerte der Navigationsaufgaben).
 *
 *   php tools/content-build/bin/navigation-values.php draft    # Vorschlag aus answer_text (JSON auf stdout)
 *   php tools/content-build/bin/navigation-values.php review   # Prüftabelle: Overlay je Teilaufgabe neben dem amtlichen Ergebnis
 *
 * Der Entwurf wurde einmalig erzeugt und danach Teilaufgabe für Teilaufgabe von Hand geprüft
 * (Beschriftungen, Korrekturen wie NAV-01-02). Maßgeblich ist die gepflegte Datei, nicht der Entwurf:
 * Mehrdeutiges lässt der Entwurf bewusst weg.
 */

use ContentBuild\Csv;
use ContentBuild\NavigationAnswerValues;
use ContentBuild\Text;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ContentBuild\\')) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, 13)).'.php';
    }
});

$root = dirname(__DIR__, 3);
$csv = $root.'/content-source/boatingschool/database/data/sbf_see_navigationsaufgaben.csv';
$mode = $argv[1] ?? 'review';

$parts = [];
foreach (Csv::read($csv) as $row) {
    $parts[Text::clean($row['Aufgabe-ID'])] = ['question' => Text::clean($row['Aufgabenstellung']), 'answer' => Text::clean($row['Amtliches Ergebnis'])];
}

if ($mode === 'draft') {
    $out = [];
    foreach ($parts as $id => $p) {
        $values = draft($p['answer']);
        if ($values !== []) {
            $out[$id] = $values;
        }
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
    exit(0);
}

$overlay = NavigationAnswerValues::load(dirname(__DIR__).'/data/navigation_answer_values.json');
$withValues = 0;
$valueCount = 0;
$byQuantity = [];
$skipped = [];
foreach ($parts as $id => $p) {
    $values = $overlay[$id] ?? [];
    $answer = str_replace("\n", ' / ', $p['answer']);
    if ($values === []) {
        $skipped[] = sprintf('%-10s  %s', $id, $answer);
        continue;
    }
    $withValues++;
    $cells = [];
    foreach ($values as $v) {
        $valueCount++;
        $byQuantity[$v['quantity']] = ($byQuantity[$v['quantity']] ?? 0) + 1;
        $cells[] = sprintf('%s [%s] = %s (±%s)', $v['label'], $v['quantity'], NavigationAnswerValues::format($v), NavigationAnswerValues::formatNumber($v['tolerance']).' '.NavigationAnswerValues::toleranceUnit($v['quantity']));
    }
    printf("%-10s  %-60s  →  %s\n", $id, mb_strimwidth($answer, 0, 60, '…'), implode(' | ', $cells));
}
ksort($byQuantity);
printf("\nTeilaufgaben mit Werten: %d von %d, Werte: %d (%s)\n", $withValues, count($parts), $valueCount, implode(', ', array_map(static fn ($k, $n) => "{$k} {$n}", array_keys($byQuantity), $byQuantity)));
printf("Ohne Werte (%d):\n  %s\n", count($skipped), implode("\n  ", $skipped));

/** Entwurf: nur eindeutige Muster; alles andere bleibt leer (Handprüfung). @return list<array<string,mixed>> */
function draft(string $answer): array
{
    $a = str_replace(["\u{2019}", '′'], "'", $answer);
    if (preg_match('/^siehe karte$/iu', $a)) {
        return [];
    }
    if (preg_match('/(\d{1,2})°\s*(\d{1,2},\d)\'\s*([NS])\s*(\d{1,3})°\s*(\d{1,2},\d)\'\s*([EW])/u', $a, $m)) {
        return [['label' => 'Position', 'quantity' => 'position', 'value' => [
            'lat' => ['deg' => (int) $m[1], 'min' => (float) str_replace(',', '.', $m[2]), 'hemisphere' => $m[3]],
            'lon' => ['deg' => (int) $m[4], 'min' => (float) str_replace(',', '.', $m[5]), 'hemisphere' => $m[6]],
        ], 'unit' => "'", 'tolerance' => 0.3]];
    }
    if (preg_match('/^BV\s*=\s*(\d{3})°\s*-\s*(\d+,\d)\s*sm$/u', $a, $m)) {
        return [
            ['label' => 'BV Richtung', 'quantity' => 'angle', 'value' => (int) $m[1], 'unit' => '°', 'tolerance' => 2],
            ['label' => 'BV Distanz', 'quantity' => 'distance', 'value' => (float) str_replace(',', '.', $m[2]), 'unit' => 'sm', 'tolerance' => 0.2],
        ];
    }
    if (preg_match_all('/\b(rwK|rwk|MgK|rwP|rwp)\s*=\s*(\d{3})°/u', $a, $all, PREG_SET_ORDER)) {
        $out = [];
        foreach ($all as $m) {
            $label = match (strtolower($m[1])) { 'rwk' => 'rwK', 'mgk' => 'MgK', default => 'rwP' };
            $out[] = ['label' => $label, 'quantity' => $label === 'rwP' ? 'bearing' : 'course', 'value' => (int) $m[2], 'unit' => '°', 'tolerance' => 2];
        }

        return $out;
    }
    if (preg_match('/^(\d+,\d)\s*sm$/u', $a, $m)) {
        return [['label' => 'Distanz', 'quantity' => 'distance', 'value' => (float) str_replace(',', '.', $m[1]), 'unit' => 'sm', 'tolerance' => 0.2]];
    }
    if (preg_match('/^(?:(?:um|gegen)\s+)?(\d{2}:\d{2})\s*Uhr$/iu', $a, $m)) {
        return [['label' => 'Uhrzeit', 'quantity' => 'time', 'value' => $m[1], 'unit' => 'Uhr', 'tolerance' => 3]];
    }
    if (preg_match('/^(?:FüG\s*=\s*)?(\d+(?:,\d)?)\s*kn$/u', $a, $m)) {
        return [['label' => 'Fahrt über Grund', 'quantity' => 'speed', 'value' => (float) str_replace(',', '.', $m[1]), 'unit' => 'kn', 'tolerance' => 0.3]];
    }
    if (preg_match('/^(?:Zeitraum\s*=\s*)?(\d+)\s*min\.?$/u', $a, $m)) {
        return [['label' => 'Dauer', 'quantity' => 'duration', 'value' => (int) $m[1], 'unit' => 'min', 'tolerance' => 3]];
    }

    return [];
}
