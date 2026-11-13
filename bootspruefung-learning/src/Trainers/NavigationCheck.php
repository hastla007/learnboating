<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

defined('ABSPATH') || exit;

/**
 * Automatische Prüfung eigener Antworten im Navigationstrainer – als Hilfe, nicht als Bewertung:
 * Die Selbsteinschätzung der Lernenden bleibt maßgeblich und wird wie bisher gespeichert.
 *
 * Grundlage sind die strukturierten Lösungswerte (`answer_values` aus dem Content-Paket, Spalte
 * bp_navigation_question.answer_values): je Wert label, quantity, value, unit, tolerance, optional aliases.
 * Die Eingabe wird tolerant gelesen (Dezimalkomma/-punkt, „079°“/„79“, „rwK = 79°“, „0,8 sm“, „15:12“,
 * „15.12 Uhr“, Positionen wie „53°55,8'N 7°51,3'E“ oder „53 55.8 N 007 51.3 E“). Mehrere Werte werden
 * über die Beschriftung zugeordnet, wenn die Eingabe welche enthält, sonst in der Reihenfolge.
 * Außerdem (Fachprüfung FACH-15): Füllwörter in Positionen („Breite … Länge …“, „φ = … λ = …“), die Schreibweise
 * „53-55,8N 007-51,3E“, Bruchteile von Stunden („3/4 Stunde“, „¾ h“), „Fahrzeit 1:42 h“ als Dauer, Rechenwege
 * („MgP 168 + 2 = rwP 170“: nur das Ergebnis zählt) und Werte mit fremder Beschriftung („74° (rwK 79°)“ für den MgK).
 */
final class NavigationCheck
{
    /** Größe => Art der erkannten Eingabe */
    private const FAMILY = [
        'course' => 'angle', 'bearing' => 'angle', 'angle' => 'angle',
        'distance' => 'distance', 'speed' => 'speed', 'duration' => 'duration',
        'time' => 'clock', 'position' => 'position',
    ];

    private const UNITS = [
        '°' => 'angle', 'grad' => 'angle',
        'sm' => 'distance', 'nm' => 'distance', 'seemeile' => 'distance', 'seemeilen' => 'distance',
        'kn' => 'speed', 'kt' => 'speed', 'kts' => 'speed', 'knoten' => 'speed',
        'min' => 'duration', 'min.' => 'duration', 'minute' => 'duration', 'minuten' => 'duration',
        'h' => 'hours', 'std' => 'hours', 'std.' => 'hours', 'stunde' => 'hours', 'stunden' => 'hours',
    ];

    /**
     * Wie viele Navigations-Teilaufgaben haben Lösungswerte? Ein Paket vor Schema 1.4 hat keine – dann ist die automatische
     * Prüfung überall aus (Fachprüfung FACH-14: Vorschau mit altem Paket). Für die Statusseite im Admin.
     *
     * @return array{with:int,total:int}
     */
    public static function coverage(): array
    {
        global $wpdb;
        $row = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(answer_values IS NOT NULL AND answer_values <> '') AS with_values FROM {$wpdb->prefix}bp_navigation_question WHERE status <> 'source_archived'");

        return ['with' => (int) ($row->with_values ?? 0), 'total' => (int) ($row->total ?? 0)];
    }

    /** Gespeicherte Werte (JSON) einer Teilaufgabe; ungültig/leer → []. @return list<array<string,mixed>> */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $values = json_decode($json, true);
        if (! is_array($values) || ! array_is_list($values)) {
            return [];
        }

        return array_values(array_filter($values, static fn ($v) => is_array($v) && isset($v['label'], $v['quantity'], $v['tolerance'], self::FAMILY[$v['quantity']]) && array_key_exists('value', $v)));
    }

    /**
     * Prüft eine Eingabe gegen die Lösungswerte.
     * @param list<array<string,mixed>> $values
     * @param bool $reveal Sollwerte mitliefern (nur wenn das amtliche Ergebnis ohnehin sichtbar ist)
     * @return array{status:string,message:string,values:list<array<string,mixed>>}|null null ohne Werte oder ohne Eingabe
     */
    public static function check(array $values, ?string $input, bool $reveal): ?array
    {
        if ($values === [] || $input === null || trim($input) === '') {
            return null;
        }
        $tokens = self::collapseCalculations(self::parse($input), mb_strtolower(self::normalize($input)));
        $assigned = self::assign($values, $tokens, $input);
        $out = [];
        $recognized = 0;
        $allOk = true;
        foreach ($values as $i => $v) {
            $token = $assigned[$i] ?? null;
            $given = $token !== null ? self::given($v['quantity'], $token) : null;
            $deviation = $given !== null ? self::deviation($v, $given) : null;
            $ok = $deviation !== null && abs($deviation) <= (float) $v['tolerance'] + 1e-9;
            if ($given !== null) {
                $recognized++;
            }
            $allOk = $allOk && $ok;
            $row = [
                'label' => (string) $v['label'],
                'quantity' => (string) $v['quantity'],
                'given' => $given !== null ? self::format($v['quantity'], $given) : null,
                'ok' => $ok,
                'deviation' => $deviation !== null ? round($deviation, 2) : null,
                'tolerance' => (float) $v['tolerance'],
                'tolerance_unit' => self::toleranceUnit($v['quantity']),
            ];
            if ($reveal) {
                $row['expected'] = self::format($v['quantity'], self::expected($v));
            }
            $out[] = $row;
        }
        $status = $recognized === 0 ? 'unrecognized' : ($allOk ? 'within' : 'deviates');
        $messages = [
            'within' => __('Deine Werte liegen im Toleranzbereich.', 'bootspruefung-learning'),
            'deviates' => __('Mindestens ein Wert weicht ab oder fehlt.', 'bootspruefung-learning'),
            'unrecognized' => __('In deiner Antwort wurden keine prüfbaren Werte erkannt.', 'bootspruefung-learning'),
        ];

        return ['status' => $status, 'message' => $messages[$status], 'values' => $out];
    }

    // ------------------------------------------------------------------ Eingabe lesen

    /**
     * Zerlegt eine Eingabe in erkannte Werte (Reihenfolge wie im Text).
     * kind: position|clock|angle|distance|speed|duration|number; value: Zahl, Minuten seit 0 Uhr (clock) oder
     * ['lat' => Bogenminuten, 'lon' => Bogenminuten] (position, Süd/West negativ).
     * @return list<array{kind:string,value:mixed,start:int,end:int}>
     */
    public static function parse(string $input): array
    {
        $text = self::normalize($input);
        $tokens = [];
        $mask = static function (int $start, int $length) use (&$text): void {
            $text = substr_replace($text, str_repeat(' ', $length), $start, $length);
        };
        $num = static fn (string $n): float => (float) str_replace(',', '.', $n);
        $deg = '(?:\s*°\s*|\s*grad\s+|\s+|-)';
        // Zwischen Breite und Länge: Trennzeichen und Füllwörter („Länge“, „λ =“).
        $between = '[\s,;\/]*(?:(?:länge|laenge|lon\.?|λ)\s*[=:]?\s*)?';
        $min = '(\d{1,2}(?:[.,]\d+)?)\s*\'?';

        // Positionen: Halbkugel nach (53° 55,8' N 007° 51,3' E) oder vor den Zahlen (N 53° 55,8' E 7° 51,3').
        $patterns = [
            '/(?<![\d.,])(\d{1,2})'.$deg.$min.'\s*(n|s)(?:ord|üd)?(?!\pL)'.$between.'(\d{1,3})'.$deg.$min.'\s*(e|w|o)(?:st|est)?(?!\pL)/iu' => [1, 2, 3, 4, 5, 6],
            '/(?<!\pL)(n|s)(?:ord|üd)?\s*(\d{1,2})'.$deg.$min.'[\s,;\/]*(e|w|o)(?:st|est)?\s*(\d{1,3})'.$deg.$min.'/iu' => [2, 3, 1, 5, 6, 4],
        ];
        foreach ($patterns as $re => $g) {
            if (preg_match_all($re, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    $latDeg = (int) $m[$g[0]][0];
                    $latMin = $num($m[$g[1]][0]);
                    $lonDeg = (int) $m[$g[3]][0];
                    $lonMin = $num($m[$g[4]][0]);
                    if ($latDeg > 90 || $lonDeg > 180 || $latMin >= 60 || $lonMin >= 60) {
                        continue;
                    }
                    $lat = ($latDeg * 60 + $latMin) * (strtolower($m[$g[2]][0]) === 's' ? -1 : 1);
                    $lon = ($lonDeg * 60 + $lonMin) * (strtolower($m[$g[5]][0]) === 'w' ? -1 : 1);
                    $tokens[] = ['kind' => 'position', 'value' => ['lat' => $lat, 'lon' => $lon], 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
                    $mask($m[0][1], strlen($m[0][0]));
                }
            }
        }

        // Uhrzeiten: 15:12 (auch „15:12 Uhr“), 15.12 Uhr, 1512 Uhr, 15 Uhr.
        $clock = [
            '/(?<![\d.,:])([01]?\d|2[0-3]):([0-5]\d)(?!\d|[.,:]\d)(?:\s*(?:uhr|h)(?!\pL))?/iu',
            '/(?<![\d.,:])([01]?\d|2[0-3])[.]([0-5]\d)\s*(?:uhr|h)(?!\pL)/iu',
            '/(?<![\d.,:])([01]\d|2[0-3])([0-5]\d)\s*uhr(?!\pL)/iu',
            '/(?<![\d.,:])([01]?\d|2[0-3])()\s*uhr(?!\pL)/iu',
        ];
        foreach ($clock as $re) {
            if (preg_match_all($re, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    // „1:42 h“ bzw. „Fahrzeit 1:42“ ist eine Dauer, keine Uhrzeit.
                    $isDuration = preg_match('/\dh$/iu', str_replace(' ', '', $m[0][0]))
                        || preg_match('/(?:fahrzeit|dauer|zeitbedarf|zeitraum)\s*[=:]?\s*$/iu', substr($text, max(0, $m[0][1] - 30), min(30, $m[0][1])));
                    $tokens[] = ['kind' => $isDuration ? 'duration' : 'clock', 'value' => (int) $m[1][0] * 60 + (int) ($m[2][0] === '' ? 0 : $m[2][0]), 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
                    $mask($m[0][1], strlen($m[0][0]));
                }
            }
        }

        // Bruchteile von Stunden: „3/4 Stunde“, „1/2 h“, „¾ h“.
        $fractions = ['½' => 0.5, '¼' => 0.25, '¾' => 0.75];
        if (preg_match_all('/(?<![\pL\d.,\/])(?:(\d)\s*\/\s*(\d)|(½|¼|¾))\s*(?:h|std\.?|stunden?)(?!\pL)/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $hours = isset($m[3]) && $m[3][1] >= 0 ? $fractions[$m[3][0]] : ((int) $m[2][0] > 0 ? (int) $m[1][0] / (int) $m[2][0] : 0.0);
                $tokens[] = ['kind' => 'duration', 'value' => $hours * 60, 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
                $mask($m[0][1], strlen($m[0][0]));
            }
        }

        // Zahlen mit oder ohne Einheit; Ziffern in Namen („TG15“, „4a“, „1b/Jade1“) und Brüche („3/4“) zählen nicht.
        $units = implode('|', array_map(static fn ($u) => preg_quote($u, '/'), array_keys(self::UNITS)));
        if (preg_match_all('/(?<![\pL\d.,\/])(\d+(?:[.,]\d+)?)(?:\s*('.$units.'))?(?![\pL\d\/])/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $unit = isset($m[2]) && $m[2][1] >= 0 ? strtolower($m[2][0]) : '';
                $kind = $unit === '' ? 'number' : self::UNITS[$unit];
                $value = $num($m[1][0]);
                if ($kind === 'hours') {
                    [$kind, $value] = ['duration', $value * 60];
                }
                $tokens[] = ['kind' => $kind, 'value' => $value, 'start' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
            }
        }
        usort($tokens, static fn ($a, $b) => $a['start'] <=> $b['start']);

        return $tokens;
    }

    /**
     * Rechenwege („MgP 168 + 2 = rwP 170“, „063 + 2 = 065“): Gradzahlen und Zahlen, die nur durch Rechenzeichen
     * (und höchstens eine Beschriftung nach dem „=“) verbunden sind, bilden eine Kette; enthält sie ein „=“, zählt
     * nur ihr letzter Wert.
     * @param list<array{kind:string,value:mixed,start:int,end:int}> $tokens
     * @return list<array{kind:string,value:mixed,start:int,end:int}>
     */
    private static function collapseCalculations(array $tokens, string $text): array
    {
        $drop = [];
        $chain = [];
        $hasEquals = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $calc = in_array($tokens[$i]['kind'], ['angle', 'number'], true);
            $chain[] = $i;
            $next = $tokens[$i + 1] ?? null;
            $gap = $next !== null ? substr($text, $tokens[$i]['end'], max(0, $next['start'] - $tokens[$i]['end'])) : '';
            $linked = $calc && $next !== null && in_array($next['kind'], ['angle', 'number'], true)
                && preg_match('/[+\-−=]/u', $gap) && preg_match('/^[\s°+\-−±=]*(?:=\s*\pL{2,4}\s*)?$/u', $gap);
            if ($linked) {
                $hasEquals = $hasEquals || str_contains($gap, '=');
                continue;
            }
            if ($hasEquals && count($chain) > 1) {
                foreach (array_slice($chain, 0, -1) as $j) {
                    $drop[$j] = true;
                }
            }
            $chain = [];
            $hasEquals = false;
        }

        return array_values(array_filter($tokens, static fn ($t, $i) => ! isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
    }

    private static function normalize(string $input): string
    {
        $text = str_replace(
            ["\u{2019}", "\u{2018}", "\u{2032}", "\u{00B4}", '`', "\u{201B}", "\u{00BA}", "\u{02DA}", "\u{00A0}", "\u{202F}", "\u{2033}", "\u{201D}", "\u{201C}", "\u{201E}"],
            ["'", "'", "'", "'", "'", "'", '°', '°', ' ', ' ', '"', '"', '"', '"'],
            $input
        );

        return mb_substr($text, 0, 2000);
    }

    // ------------------------------------------------------------------ Zuordnung

    /**
     * Ordnet jedem Sollwert höchstens einen erkannten Wert zu.
     * @param list<array<string,mixed>> $values
     * @param list<array{kind:string,value:mixed,start:int,end:int}> $tokens
     * @return array<int,array{kind:string,value:mixed,start:int,end:int}> Index des Sollwerts => Wert
     */
    private static function assign(array $values, array $tokens, string $input): array
    {
        $text = mb_strtolower(self::normalize($input));
        $used = [];
        $result = [];
        $families = [];
        foreach ($values as $i => $v) {
            $families[self::FAMILY[$v['quantity']]][] = $i;
        }
        $context = self::labelContexts($tokens, $text);
        // 1. Werte mit passender Einheit/Form, je Größe über Beschriftung oder Reihenfolge.
        foreach ($families as $family => $indexes) {
            $candidates = array_keys(array_filter($tokens, static fn ($t) => $t['kind'] === $family));
            if ($candidates === []) {
                continue;
            }
            $words = self::distinctiveWords($values, $indexes);
            // Beschriftung vor oder nach dem Wert? Die Schreibweise mit mehr Treffern gilt.
            $score = static function (int $vi, int $ti, string $side) use ($words, $context): int {
                $n = 0;
                foreach ($words[$vi] as $w) {
                    $n += preg_match('/(?<![\pL\d])'.preg_quote($w, '/').'(?![\pL\d])/u', $context[$ti][$side]) ? 1 : 0;
                }

                return $n;
            };
            $sum = ['before' => 0, 'after' => 0];
            foreach (['before', 'after'] as $side) {
                foreach ($indexes as $vi) {
                    foreach ($candidates as $ti) {
                        $sum[$side] += $score($vi, $ti, $side);
                    }
                }
            }
            $side = $sum['after'] > $sum['before'] ? 'after' : 'before';
            if (count($indexes) === 1) {
                // Ein Sollwert: beschrifteter Treffer, sonst der letzte Wert (z. B. nach einer Rechnung „… = 074°“).
                $best = null;
                $bestScore = 0;
                foreach ($candidates as $ti) {
                    $s = $score($indexes[0], $ti, $side);
                    if ($s > 0 && $s >= $bestScore) {
                        [$best, $bestScore] = [$ti, $s];
                    }
                }
                // Ohne passende Beschriftung: Werte mit fremder Beschriftung („74° (rwK 79°)“ für den MgK) übergehen.
                $unlabelled = $best === null ? array_values(array_filter($candidates, static fn ($ti) => ! self::foreignLabel($context[$ti][$side], $words[$indexes[0]]))) : [];
                $pick = $best ?? ($unlabelled !== [] ? end($unlabelled) : end($candidates));
                if ($best !== null) {
                    // Beschriftete Rechnung „MgK = 079° - 1° - 4° = 074°“: das Ergebnis nach dem letzten „=“ gilt.
                    $pick = self::resultOfCalculation($tokens, $context, $pick, $candidates);
                }
                $result[$indexes[0]] = $tokens[$pick];
                $used[$pick] = true;
                continue;
            }
            // Mehrere Sollwerte: zuerst eindeutige Beschriftungen (bester Treffer zuerst), Rest in Reihenfolge.
            $pairs = [];
            foreach ($indexes as $vi) {
                foreach ($candidates as $ti) {
                    $s = $score($vi, $ti, $side);
                    if ($s > 0) {
                        $pairs[] = [$s, $vi, $ti];
                    }
                }
            }
            usort($pairs, static fn ($a, $b) => [$b[0], $a[2], $a[1]] <=> [$a[0], $b[2], $b[1]]);
            foreach ($pairs as [, $vi, $ti]) {
                if (! isset($result[$vi]) && ! isset($used[$ti])) {
                    $result[$vi] = $tokens[$ti];
                    $used[$ti] = true;
                }
            }
            foreach ($indexes as $vi) {
                if (isset($result[$vi])) {
                    continue;
                }
                foreach ($candidates as $ti) {
                    if (! isset($used[$ti])) {
                        $result[$vi] = $tokens[$ti];
                        $used[$ti] = true;
                        break;
                    }
                }
            }
        }
        // 2. Übrige Sollwerte: Zahlen ohne Einheit in Reihenfolge („103 0,8“); Dauer auch als h:mm. Bei nur
        //    einem Sollwert die letzte Zahl („79“, „079 - 1 - 4 = 074“).
        foreach ($values as $i => $v) {
            if (isset($result[$i]) || $v['quantity'] === 'position' || $v['quantity'] === 'time') {
                continue;
            }
            $loose = array_keys(array_filter($tokens, static fn ($t, $ti) => ! isset($used[$ti]) && ($t['kind'] === 'number' || ($v['quantity'] === 'duration' && $t['kind'] === 'clock')), ARRAY_FILTER_USE_BOTH));
            if ($loose !== []) {
                $ti = count($values) === 1 ? end($loose) : $loose[0];
                $result[$i] = $tokens[$ti];
                $used[$ti] = true;
            }
        }

        return $result;
    }

    /** Nautische Kürzel, die einen Wert als etwas anderes ausweisen. */
    private const LABELS = ['rwk', 'mgk', 'kük', 'küg', 'kdw', 'kug', 'rwp', 'mgp', 'mw', 'abl', 'dev', 'füg', 'fdw', 'bv', 'bvk'];

    /** Steht im Kontext eines Werts ein Kürzel, das nicht zur Beschriftung des Sollwerts gehört? @param list<string> $own */
    private static function foreignLabel(string $context, array $own): bool
    {
        foreach (self::LABELS as $label) {
            if (! in_array($label, $own, true) && preg_match('/(?<![\pL\d])'.preg_quote($label, '/').'(?![\pL\d])/u', $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Folgen auf den gewählten Wert nur Rechenzeichen und Werte (keine neue Beschriftung), gilt der letzte
     * Wert hinter einem „=“.
     * @param list<array{kind:string,value:mixed,start:int,end:int}> $tokens
     * @param array<int,array{before:string,after:string}> $context
     * @param list<int> $candidates
     */
    private static function resultOfCalculation(array $tokens, array $context, int $pick, array $candidates): int
    {
        $result = $pick;
        for ($ti = $pick + 1; isset($tokens[$ti]); $ti++) {
            if (preg_match('/\pL/u', $context[$ti]['before'])) {
                break;
            }
            if (str_contains($context[$ti]['before'], '=') && in_array($ti, $candidates, true)) {
                $result = $ti;
            }
        }

        return $result;
    }

    /**
     * Unterscheidende Wörter je Sollwert (aus label und aliases): Wörter, die in allen Beschriftungen derselben
     * Größe vorkommen („rwP“, „Tonne“), zählen nicht; bei nur einem Sollwert zählen alle.
     * @param list<array<string,mixed>> $values
     * @param list<int> $indexes
     * @return array<int,list<string>>
     */
    private static function distinctiveWords(array $values, array $indexes): array
    {
        $split = static fn (string $s): array => array_values(array_unique(array_filter(
            preg_split('/[^\pL\d]+/u', mb_strtolower($s)) ?: [],
            static fn ($w) => mb_strlen($w) >= 2
        )));
        $words = [];
        foreach ($indexes as $i) {
            $words[$i] = $split((string) $values[$i]['label']);
        }
        if (count($indexes) > 1) {
            $common = array_intersect(...array_values($words));
            foreach ($words as $i => $w) {
                $words[$i] = array_values(array_diff($w, $common));
            }
        }
        foreach ($indexes as $i) {
            foreach ((array) ($values[$i]['aliases'] ?? []) as $alias) {
                $words[$i] = array_merge($words[$i], $split((string) $alias));
            }
        }

        return $words;
    }

    /**
     * Text vor und nach jedem erkannten Wert bis zum Nachbarwert (für die Zuordnung über Beschriftungen).
     * @param list<array{kind:string,value:mixed,start:int,end:int}> $tokens
     * @return array<int,array{before:string,after:string}>
     */
    private static function labelContexts(array $tokens, string $text): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            $from = $i > 0 ? $tokens[$i - 1]['end'] : 0;
            $to = isset($tokens[$i + 1]) ? $tokens[$i + 1]['start'] : strlen($text);
            $out[$i] = ['before' => substr($text, $from, max(0, $t['start'] - $from)), 'after' => substr($text, $t['end'], max(0, $to - $t['end']))];
        }

        return $out;
    }

    // ------------------------------------------------------------------ Vergleich und Ausgabe

    /** Erkannter Wert in der Einheit des Sollwerts. @param array{kind:string,value:mixed} $token */
    private static function given(string $quantity, array $token): mixed
    {
        if ($quantity === 'duration' && $token['kind'] === 'clock') {
            return (float) $token['value']; // h:mm als Dauer
        }
        if (self::FAMILY[$quantity] === 'angle') {
            return fmod((float) $token['value'], 360.0);
        }

        return is_array($token['value']) ? $token['value'] : ($quantity === 'time' ? (int) $token['value'] : (float) $token['value']);
    }

    /** Sollwert in derselben Darstellung wie given(). @param array<string,mixed> $v */
    private static function expected(array $v): mixed
    {
        return match ($v['quantity']) {
            'time' => (int) substr((string) $v['value'], 0, 2) * 60 + (int) substr((string) $v['value'], 3, 2),
            'position' => [
                'lat' => ((int) $v['value']['lat']['deg'] * 60 + (float) $v['value']['lat']['min']) * ($v['value']['lat']['hemisphere'] === 'S' ? -1 : 1),
                'lon' => ((int) $v['value']['lon']['deg'] * 60 + (float) $v['value']['lon']['min']) * ($v['value']['lon']['hemisphere'] === 'W' ? -1 : 1),
            ],
            default => (float) $v['value'],
        };
    }

    /** Abweichung in der Toleranzeinheit (Grad, sm, kn, min, Bogenminuten – Position: größere der beiden). @param array<string,mixed> $v */
    private static function deviation(array $v, mixed $given): float
    {
        $expected = self::expected($v);

        return match (self::FAMILY[$v['quantity']]) {
            'angle' => fmod(fmod($given - $expected, 360.0) + 540.0, 360.0) - 180.0,
            'clock' => (float) ((((int) $given - (int) $expected) % 1440 + 2160) % 1440 - 720),
            'position' => max(abs($given['lat'] - $expected['lat']), abs($given['lon'] - $expected['lon'])),
            default => $given - $expected,
        };
    }

    private static function toleranceUnit(string $quantity): string
    {
        return match ($quantity) {
            'course', 'bearing', 'angle' => '°',
            'distance' => 'sm',
            'speed' => 'kn',
            'position' => "'",
            default => 'min',
        };
    }

    private static function format(string $quantity, mixed $value): string
    {
        $decimal = static fn (float $n, int $digits): string => str_replace('.', ',', number_format($n, $digits, '.', ''));
        $coord = static function (float $minutes, int $degDigits, string $pos, string $neg) use ($decimal): string {
            $abs = round(abs($minutes), 1);
            $d = (int) floor($abs / 60);

            return sprintf('%0'.$degDigits.'d° %s\' %s', $d, str_pad($decimal($abs - $d * 60, 1), 4, '0', STR_PAD_LEFT), $minutes < 0 ? $neg : $pos);
        };

        return match ($quantity) {
            'course', 'bearing', 'angle' => (abs($value - round($value)) < 1e-9 ? sprintf('%03d', (int) round($value)) : $decimal((float) $value, 1)).'°',
            'distance' => $decimal((float) $value, 1).' sm',
            'speed' => $decimal((float) $value, 1).' kn',
            'duration' => (abs($value - round($value)) < 1e-9 ? (string) (int) round($value) : $decimal((float) $value, 1)).' min',
            'time' => sprintf('%02d:%02d Uhr', intdiv((int) $value, 60), (int) $value % 60),
            'position' => $coord((float) $value['lat'], 2, 'N', 'S').' '.$coord((float) $value['lon'], 3, 'E', 'W'),
            default => (string) $value,
        };
    }
}
