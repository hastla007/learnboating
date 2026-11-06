<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

/**
 * Prüfpunkte für Funksprüche wie FunkPraxis::evaluate()/checkText() der Quelle:
 * Text und Muster werden gleich gefaltet (Großschreibung, Ä→AE, Ö→OE, Ü→UE, ß→SS),
 * Satzzeichen entfallen; ein Prüfpunkt besteht, wenn sein Muster mindestens
 * „min“-mal vorkommt.
 */
final class RadioChecks
{
    public static function fold(string $text): string
    {
        return strtr($text, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ẞ' => 'SS', 'ß' => 'SS']);
    }

    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::fold(mb_strtoupper($text)));

        return ' '.trim($text).' ';
    }

    /**
     * @param list<array{label:string,pattern:string,min:int}> $checks
     * @return list<array{label:string,ok:bool}>
     */
    public static function run(array $checks, string $text): array
    {
        $normalized = self::normalize($text);

        return array_map(static fn (array $c) => [
            'label' => $c['label'],
            'ok' => (int) @preg_match_all('/'.self::fold($c['pattern']).'/u', $normalized) >= $c['min'],
        ], $checks);
    }

    /** @param list<array{label:string,pattern:string,min:int}> $checks @return array{checks:list<array{label:string,ok:bool}>,passed:bool} */
    public static function checkText(array $checks, string $text): array
    {
        $results = self::run($checks, $text);

        return ['checks' => $results, 'passed' => $results !== [] && ! in_array(false, array_column($results, 'ok'), true)];
    }
}
