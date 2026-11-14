<?php

namespace App\Support;

/**
 * Eigene Antwort bei Navigationsaufgaben prüfen.
 *
 * Besteht die Musterlösung nur aus Messwerten (Kurse/Peilungen in Grad,
 * Positionen, Distanzen in sm, Fahrt in kn, Uhrzeiten, Minuten) plus
 * Beschriftungen wie „rwK =“ oder Tonnennamen, wird automatisch mit
 * Toleranz verglichen. Alles andere (Beschreibungen, „Siehe Karte“)
 * vergleicht der Lernende selbst mit der Musterlösung.
 */
class NavigationAnswer
{
    /** Toleranzen je Messwert. */
    public const TOLERANCE = ['angle' => 2.0, 'position' => 0.3, 'distance' => 0.2, 'speed' => 0.3, 'time' => 3.0, 'minutes' => 3.0];

    /** Wörter, die neben Messwerten in automatisch prüfbaren Lösungen vorkommen dürfen. */
    private const LABELS = ['rwk', 'mgk', 'rwp', 'mgp', 'kdw', 'kük', 'kuk', 'küg', 'kug', 'bv', 'füg', 'fug', 'fdw', 'uhr', 'gegen', 'um', 'ca', 'tonne', 'leuchttonne', 'n', 's', 'e', 'w', 'o'];

    /**
     * @return array{auto: bool, correct: ?bool, expected: list<array{type: string, value: float|array}>}
     */
    public static function evaluate(string $model, string $given): array
    {
        $expected = self::measurements($model);
        if ($expected === [] || ! self::onlyLabelsLeft($model)) {
            return ['auto' => false, 'correct' => null, 'expected' => $expected];
        }

        $actual = self::measurements($given, lenient: true);

        return ['auto' => true, 'correct' => self::matches($expected, $actual), 'expected' => $expected];
    }

    /** @return list<array{type: string, value: float|array}> */
    public static function measurements(string $text, bool $lenient = false): array
    {
        $text = str_replace(['’', '′', '´', '`'], "'", $text);
        $found = [];
        $mask = function (array $m) use (&$text) {
            $text = substr_replace($text, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
        };

        // Positionen: 54° 03,5' N 008° 06,9' E
        if (preg_match_all("/(\d{1,2})(?:\s*°\s*|\s+)(\d{1,2}(?:[.,]\d+)?)\s*'?\s*([NS])\W{0,3}(\d{1,3})(?:\s*°\s*|\s+)(\d{1,2}(?:[.,]\d+)?)\s*'?\s*([EWO])/iu", $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $lat = ((int) $m[1][0] * 60 + self::num($m[2][0])) * (strtoupper($m[3][0]) === 'S' ? -1 : 1);
                $lon = ((int) $m[4][0] * 60 + self::num($m[5][0])) * (strtoupper($m[6][0]) === 'W' ? -1 : 1);
                $found[] = ['pos' => $m[0][1], 'type' => 'position', 'value' => [$lat, $lon]];
                $mask($m);
            }
        }
        $patterns = [
            'time' => '/\b(\d{1,2})[:.](\d{2})\s*(?:uhr)?\b/iu',
            'angle' => '/(\d{1,3}(?:[.,]\d+)?)\s*°/u',
            'distance' => '/(\d+(?:[.,]\d+)?)\s*sm\b/iu',
            'speed' => '/(\d+(?:[.,]\d+)?)\s*(?:kn|knoten)\b/iu',
            'minutes' => '/(\d+(?:[.,]\d+)?)\s*min\b\.?/iu',
        ];
        foreach ($patterns as $type => $pattern) {
            if ($type === 'time' && ! $lenient && ! preg_match('/uhr|\d{1,2}:\d{2}/iu', $text)) {
                continue;
            }
            if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    $value = $type === 'time' ? (int) $m[1][0] * 60 + (int) $m[2][0] : self::num($m[1][0]);
                    $found[] = ['pos' => $m[0][1], 'type' => $type, 'value' => $value];
                    $mask($m);
                }
            }
        }
        // Eigene Antwort ohne Einheit („074“, „6,1“): als Zahl ohne Typ.
        if ($lenient && preg_match_all('/(?<![\w,.])(\d+(?:[.,]\d+)?)(?![\w,.])/u', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $found[] = ['pos' => $m[0][1], 'type' => 'number', 'value' => self::num($m[1][0])];
            }
        }
        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return array_map(fn ($f) => ['type' => $f['type'], 'value' => $f['value']], $found);
    }

    private static function onlyLabelsLeft(string $model): bool
    {
        $rest = preg_replace('/"[^"]*"|„[^“]*“/u', ' ', $model);
        $rest = preg_replace("/\d+(?:[.,]\d+)?\s*(?:°|'|sm\b|kn\b|knoten\b|min\b\.?|uhr\b)?|\d{1,2}[:.]\d{2}/iu", ' ', $rest);
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($rest), -1, PREG_SPLIT_NO_EMPTY);

        return collect($words)->every(fn ($w) => in_array($w, self::LABELS, true));
    }

    /**
     * Jeder erwartete Messwert braucht in derselben Reihenfolge einen
     * passenden Wert in der Antwort (gleicher Typ oder Zahl ohne Einheit).
     */
    private static function matches(array $expected, array $actual): bool
    {
        $i = 0;
        foreach ($expected as $e) {
            $hit = false;
            for (; $i < count($actual); $i++) {
                $a = $actual[$i];
                if (($a['type'] === $e['type'] || ($a['type'] === 'number' && $e['type'] !== 'position')) && self::close($e, $a['value'])) {
                    $hit = true;
                    $i++;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private static function close(array $expected, float|int|array $value): bool
    {
        $tolerance = self::TOLERANCE[$expected['type']];
        if ($expected['type'] === 'position') {
            return is_array($value) && abs($expected['value'][0] - $value[0]) <= $tolerance && abs($expected['value'][1] - $value[1]) <= $tolerance;
        }
        if (is_array($value)) {
            return false;
        }
        if ($expected['type'] === 'angle') {
            $diff = fmod(abs($expected['value'] - $value), 360);

            return min($diff, 360 - $diff) <= $tolerance;
        }

        return abs($expected['value'] - $value) <= $tolerance + 1e-9;
    }

    private static function num(string $text): float
    {
        return (float) str_replace(',', '.', $text);
    }
}
