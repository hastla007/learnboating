<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Trainers;

/**
 * Textvergleich und Buchstabieren für das Funktraining, fachlich wie
 * App\Support\FunkTraining::wordMatch()/spellGerman() und FunkPraxis::normalize()
 * der Quelle: Großschreibung, Umlaute als AE/OE/UE/SS, Satzzeichen weg,
 * Zahlwörter (DE/EN/Funk) als Ziffern, Ziffern einzeln gezählt.
 */
final class RadioText
{
    public const ALPHABET = [
        'A' => 'Alfa', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta', 'E' => 'Echo', 'F' => 'Foxtrot', 'G' => 'Golf', 'H' => 'Hotel',
        'I' => 'India', 'J' => 'Juliett', 'K' => 'Kilo', 'L' => 'Lima', 'M' => 'Mike', 'N' => 'November', 'O' => 'Oscar', 'P' => 'Papa',
        'Q' => 'Quebec', 'R' => 'Romeo', 'S' => 'Sierra', 'T' => 'Tango', 'U' => 'Uniform', 'V' => 'Victor', 'W' => 'Whiskey', 'X' => 'X-ray',
        'Y' => 'Yankee', 'Z' => 'Zulu',
    ];
    public const DIGITS = ['Null', 'Eins', 'Zwo', 'Drei', 'Vier', 'Fünf', 'Sechs', 'Sieben', 'Acht', 'Neun'];

    private const DIGIT_WORDS = [
        ['ZERO', 'NULL'], ['ONE', 'EINS', 'EIN'], ['TWO', 'ZWO', 'ZWEI'], ['THREE', 'DREI'], ['FOUR', 'FOWER', 'VIER'],
        ['FIVE', 'FIFE', 'FUENF'], ['SIX', 'SECHS'], ['SEVEN', 'SIEBEN'], ['EIGHT', 'ACHT'], ['NINE', 'NINER', 'NEUN'],
    ];

    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtoupper($text), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ẞ' => 'SS', 'ß' => 'SS']);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return ' '.trim($text).' ';
    }

    public static function spellGerman(string $word): string
    {
        $chars = str_split(strtr(mb_strtoupper($word), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ß' => 'SS']));

        return implode(' ', array_filter(array_map(static fn ($c) => ctype_digit($c) ? self::DIGITS[(int) $c] : (self::ALPHABET[$c] ?? null), $chars)));
    }

    /** @return array{percent:int,missing:list<string>} */
    public static function wordMatch(string $original, string $input): array
    {
        $expected = self::words($original);
        $display = [];
        foreach (self::words($original, true, false) as $unit) {
            array_push($display, ...array_fill(0, ctype_digit($unit) ? strlen($unit) : 1, $unit));
        }
        $givenWords = self::words($input);
        $given = array_count_values($givenWords);
        $hits = 0;
        $missing = [];
        foreach ($expected as $i => $word) {
            if (($given[$word] ?? 0) > 0) {
                $given[$word]--;
                $hits++;
            } else {
                $missing[] = $display[$i] ?? $word;
            }
        }

        return [
            'percent' => $expected ? (int) round($hits / max(count($expected), count($givenWords)) * 100) : 0,
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /** @return list<string> */
    private static function words(string $text, bool $forDisplay = false, bool $splitDigits = true): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::DIGIT_WORDS as $digit => $names) {
                foreach ($names as $name) {
                    $map[$name] = (string) $digit;
                }
            }
        }
        $text = (string) preg_replace('/(?<=\p{L})-(?=\p{L})/u', '', $text);
        $text = $forDisplay ? ' '.trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper($text))).' ' : self::normalize($text);
        $text = (string) preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $text);
        if ($splitDigits) {
            $text = (string) preg_replace('/(?<=\d)(?=\d)/', ' ', $text);
        }
        $words = array_values(array_filter(explode(' ', trim($text)), static fn ($w) => $w !== ''));

        return $forDisplay ? $words : array_map(static fn ($w) => $map[$w] ?? $w, $words);
    }
}
