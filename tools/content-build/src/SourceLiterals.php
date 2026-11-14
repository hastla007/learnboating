<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;

/**
 * Liest reine PHP-Array-Literale (Konstanten, lokale Variablen, Aufrufargumente)
 * per Tokenizer aus Quelldateien des Snapshots, ohne die Dateien auszuführen.
 * Nur Literale sind erlaubt (Strings ohne Interpolation, Zahlen, true/false/null,
 * Arrays); alles andere führt zu einer Exception.
 */
final class SourceLiterals
{
    private const ALLOWED_STRINGS = ['true', 'false', 'null', 'TRUE', 'FALSE', 'NULL'];

    /** Wert einer Klassenkonstante: `const NAME = [...]`. */
    public static function constant(string $file, string $name): mixed
    {
        $tokens = self::tokens($file);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CONST) {
                $j = self::skip($tokens, $i + 1);
                if (is_array($tokens[$j]) && $tokens[$j][1] === $name) {
                    $j = self::skip($tokens, $j + 1);
                    if ($tokens[$j] === '=') {
                        return self::evaluate($tokens, $j + 1, ';', $file, "const {$name}");
                    }
                }
            }
        }
        throw new RuntimeException("Konstante {$name} nicht gefunden in ".basename($file));
    }

    /** Erste Zuweisung `$name = [...];` in der Datei. */
    public static function variable(string $file, string $name): mixed
    {
        $tokens = self::tokens($file);
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$'.$name) {
                $j = self::skip($tokens, $i + 1);
                if ($tokens[$j] === '=') {
                    return self::evaluate($tokens, $j + 1, ';', $file, '$'.$name);
                }
            }
        }
        throw new RuntimeException("Variable \${$name} nicht gefunden in ".basename($file));
    }

    /** Erstes Array-Literal nach einer Codestelle, z. B. "buildCourse('FKN',". */
    public static function arrayAfter(string $file, string $needle): mixed
    {
        $source = (string) file_get_contents($file);
        $pos = strpos($source, $needle);
        if ($pos === false) {
            throw new RuntimeException("Stelle {$needle} nicht gefunden in ".basename($file));
        }
        $tokens = token_get_all('<?php '.substr($source, $pos + strlen($needle)));
        $start = self::skip($tokens, 1);
        if ($tokens[$start] !== '[') {
            throw new RuntimeException("Nach {$needle} folgt kein Array-Literal.");
        }

        return self::evaluate($tokens, $start, null, $file, $needle);
    }

    /** @return list<array{0:int,1:string,2:int}|string> */
    private static function tokens(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException("Datei fehlt: {$file}");
        }

        return token_get_all($source);
    }

    /** @param list<mixed> $tokens */
    private static function skip(array $tokens, int $i): int
    {
        while (isset($tokens[$i]) && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
        }

        return $i;
    }

    /**
     * Sammelt Tokens ab $start bis zum Terminator (auf Tiefe 0) bzw. bis das
     * erste Array geschlossen ist ($terminator === null) und wertet das Literal aus.
     * @param list<mixed> $tokens
     */
    private static function evaluate(array $tokens, int $start, ?string $terminator, string $file, string $what): mixed
    {
        $code = '';
        $depth = 0;
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_string($t)) {
                if ($terminator !== null && $t === $terminator && $depth === 0) {
                    break;
                }
                if (! in_array($t, ['[', ']', ',', '-', '(', ')'], true)) {
                    throw new RuntimeException("Unerlaubtes Zeichen '{$t}' in {$what} (".basename($file).')');
                }
                if ($t === '[' || $t === '(') {
                    $depth++;
                }
                if ($t === ']' || $t === ')') {
                    $depth--;
                }
                $code .= $t;
                if ($terminator === null && $depth === 0) {
                    break;
                }
                continue;
            }
            [$id, $text] = $t;
            $ok = in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW], true)
                || ($id === T_STRING && in_array($text, self::ALLOWED_STRINGS, true));
            if (! $ok) {
                throw new RuntimeException('Nicht-literaler Ausdruck ('.token_name($id)." '{$text}') in {$what} (".basename($file).')');
            }
            $code .= in_array($id, [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $text;
        }

        return eval('return '.$code.';');
    }
}
