<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;

/**
 * Strukturierte Lösungswerte der Navigationsaufgaben (eigene Pflegedatei data/navigation_answer_values.json,
 * nicht Teil der Quelle). Der Konverter übernimmt sie als optionales `answer_values` je Teilaufgabe; das
 * Plugin prüft damit Eingaben der Lernenden als Hilfe (die Selbsteinschätzung bleibt maßgeblich).
 *
 * Toleranzen laut Festlegung des Betreibers: Kurs/Peilung/Winkel ±2°, Distanz ±0,2 sm, Position ±0,3'
 * (je Breite/Länge), Uhrzeit ±3 min, Fahrt ±0,3 kn, Dauer ±3 min.
 */
final class NavigationAnswerValues
{
    /** Größe => [Einheit, Toleranz] */
    public const QUANTITIES = [
        'course' => ['°', 2],
        'bearing' => ['°', 2],
        'angle' => ['°', 2],
        'distance' => ['sm', 0.2],
        'time' => ['Uhr', 3],
        'speed' => ['kn', 0.3],
        'duration' => ['min', 3],
        'position' => ["'", 0.3],
    ];

    private const KEYS = ['label', 'quantity', 'value', 'unit', 'tolerance', 'aliases'];

    /** @return array<string,list<array<string,mixed>>> Teilaufgaben-ID => Werte */
    public static function load(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("Pflegedatei fehlt: {$file}");
        }
        $data = Json::decodeFile($file);
        if (! is_array($data) || ! isset($data['values']) || ! is_array($data['values'])) {
            throw new RuntimeException("{$file}: Objekt mit „values“ erwartet.");
        }

        return $data['values'];
    }

    /**
     * Prüft die Pflegedatei gegen die Teilaufgaben: jede ID muss existieren, Größe/Einheit/Toleranz passen,
     * und jeder Wert muss im angezeigten amtlichen Ergebnis stehen (so folgt er auch Berichtigungen).
     * @param array<string,list<array<string,mixed>>> $overlay
     * @param array<string,string> $answers Teilaufgaben-ID => answer_text
     * @return list<string> Fehler
     */
    public static function validate(array $overlay, array $answers): array
    {
        $errors = [];
        foreach ($overlay as $id => $values) {
            $where = "navigation_answer_values {$id}";
            if (! isset($answers[$id])) {
                $errors[] = "{$where}: Teilaufgabe gibt es nicht.";
                continue;
            }
            if (! is_array($values) || $values === [] || ! array_is_list($values)) {
                $errors[] = "{$where}: nicht leere Liste erwartet.";
                continue;
            }
            $numbers = self::numbersIn($answers[$id]);
            foreach ($values as $i => $v) {
                $at = "{$where}[{$i}]";
                if (! is_array($v) || array_diff(array_keys($v), self::KEYS) !== [] || ! isset($v['label'], $v['quantity'], $v['unit'], $v['tolerance']) || ! array_key_exists('value', $v)) {
                    $errors[] = "{$at}: Felder label, quantity, value, unit, tolerance (optional aliases) erwartet.";
                    continue;
                }
                if (! is_string($v['label']) || trim($v['label']) === '') {
                    $errors[] = "{$at}: label fehlt.";
                }
                if (! isset(self::QUANTITIES[$v['quantity']])) {
                    $errors[] = "{$at}: unbekannte Größe {$v['quantity']}.";
                    continue;
                }
                [$unit, $tolerance] = self::QUANTITIES[$v['quantity']];
                if ($v['unit'] !== $unit) {
                    $errors[] = "{$at}: Einheit „{$v['unit']}“ passt nicht zu {$v['quantity']} (erwartet „{$unit}“).";
                }
                if ((! is_int($v['tolerance']) && ! is_float($v['tolerance'])) || abs($v['tolerance'] - $tolerance) > 1e-9) {
                    $errors[] = "{$at}: Toleranz muss {$tolerance} sein (Festlegung).";
                }
                if (isset($v['aliases']) && (! is_array($v['aliases']) || ! array_is_list($v['aliases']) || array_filter($v['aliases'], static fn ($a) => ! is_string($a) || trim($a) === '') !== [])) {
                    $errors[] = "{$at}: aliases muss eine Liste von Texten sein.";
                }
                $problem = self::valueProblem($v['quantity'], $v['value'], $answers[$id], $numbers);
                if ($problem !== null) {
                    $errors[] = "{$at}: {$problem}";
                }
            }
        }

        return $errors;
    }

    /** @param list<float> $numbers */
    private static function valueProblem(string $quantity, mixed $value, string $answer, array $numbers): ?string
    {
        $inAnswer = static fn (float $n): bool => array_filter($numbers, static fn ($x) => abs($x - $n) < 1e-9) !== [];
        switch ($quantity) {
            case 'position':
                foreach (['lat' => ['N', 'S', 90], 'lon' => ['E', 'W', 180]] as $axis => [$a, $b, $max]) {
                    $p = $value[$axis] ?? null;
                    if (! is_array($p) || array_keys($p) !== ['deg', 'min', 'hemisphere'] || ! is_int($p['deg']) || $p['deg'] < 0 || $p['deg'] > $max
                        || (! is_int($p['min']) && ! is_float($p['min'])) || $p['min'] < 0 || $p['min'] >= 60 || ! in_array($p['hemisphere'], [$a, $b], true)) {
                        return "Position {$axis} als {deg, min, hemisphere} erwartet.";
                    }
                    if (! $inAnswer((float) $p['deg']) || ! $inAnswer((float) $p['min']) || ! str_contains($answer, $p['hemisphere'])) {
                        return "Position {$axis} steht so nicht im amtlichen Ergebnis.";
                    }
                }

                return is_array($value) && count($value) === 2 ? null : 'Position mit genau lat und lon erwartet.';
            case 'time':
                if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    return 'Uhrzeit als „HH:MM“ erwartet.';
                }

                return str_contains($answer, $value) ? null : "Uhrzeit {$value} steht nicht im amtlichen Ergebnis.";
            default:
                if (! is_int($value) && ! is_float($value)) {
                    return 'Zahl erwartet.';
                }
                if (in_array($quantity, ['course', 'bearing', 'angle'], true) ? ($value < 0 || $value >= 360) : $value <= 0) {
                    return "Wert {$value} außerhalb des gültigen Bereichs.";
                }

                return $inAnswer((float) $value) ? null : "Wert {$value} steht nicht im amtlichen Ergebnis.";
        }
    }

    /** Alle Zahlen eines Textes (Dezimalkomma) – für den Abgleich mit dem amtlichen Ergebnis. @return list<float> */
    private static function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:,\d+)?/u', $text, $m);

        return array_map(static fn ($n) => (float) str_replace(',', '.', $n), $m[0]);
    }

    /** @param array<string,mixed> $v */
    public static function format(array $v): string
    {
        return match ($v['quantity']) {
            'position' => sprintf("%d° %s' %s %03d° %s' %s", $v['value']['lat']['deg'], self::minutes($v['value']['lat']['min']), $v['value']['lat']['hemisphere'], $v['value']['lon']['deg'], self::minutes($v['value']['lon']['min']), $v['value']['lon']['hemisphere']),
            'time' => $v['value'].' Uhr',
            'course', 'bearing', 'angle' => sprintf('%03d°', $v['value']),
            'duration' => self::formatNumber($v['value']).' '.$v['unit'],
            default => str_replace('.', ',', sprintf('%.1f', $v['value'])).' '.$v['unit'],
        };
    }

    public static function toleranceUnit(string $quantity): string
    {
        return match ($quantity) {
            'time', 'duration' => 'min',
            default => self::QUANTITIES[$quantity][0],
        };
    }

    public static function formatNumber(int|float $n): string
    {
        return str_replace('.', ',', (string) $n);
    }

    private static function minutes(int|float $m): string
    {
        return str_replace('.', ',', sprintf('%04.1f', $m));
    }
}
