<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Content\Package;

use RuntimeException;

/**
 * Kleiner JSON-Schema-Validator (Draft 2020-12) für genau die Schlüsselwörter,
 * die schema/v1 verwendet: type, enum, const, required, properties,
 * additionalProperties:false, items, minItems, maxItems, contains/minContains/
 * maxContains, pattern, minLength, maxLength, minimum, maximum,
 * exclusiveMinimum, allOf, if/then, not und $ref (lokal und auf andere
 * Schemadateien). Unbekannte Schlüsselwörter führen zu einer Exception,
 * damit das Schema nicht stillschweigend schwächer geprüft wird.
 */
final class SchemaValidator
{
    private const ANNOTATIONS = ['$schema', '$id', 'title', 'description', '$defs', 'format', 'default'];
    private const KEYWORDS = [
        'type', 'enum', 'const', 'required', 'properties', 'additionalProperties', 'items', 'minItems', 'maxItems',
        'contains', 'minContains', 'maxContains', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'exclusiveMinimum', 'allOf', 'if', 'then', 'not', '$ref',
    ];

    /** @var array<string,array<string,mixed>> Dateiname => Schema */
    private array $schemas = [];

    /** @var list<string> */
    private array $errors = [];

    private int $maxErrors = 20;

    public function __construct(private readonly string $schemaDir)
    {
    }

    /**
     * @param mixed $data mit json_decode(..., true) dekodierte Daten
     * @return list<string> Fehler (leer = gültig)
     */
    public function validate(mixed $data, string $schemaFile): array
    {
        $this->errors = [];
        $this->check($data, $this->load($schemaFile), $schemaFile, '$');

        return $this->errors;
    }

    /** @return array<string,mixed> */
    private function load(string $file): array
    {
        if (! isset($this->schemas[$file])) {
            $path = $this->schemaDir.'/'.basename($file);
            $json = is_file($path) ? file_get_contents($path) : false;
            $schema = $json === false ? null : json_decode($json, true);
            if (! is_array($schema)) {
                throw new RuntimeException("Schema nicht lesbar: {$file}");
            }
            $this->schemas[$file] = $schema;
        }

        return $this->schemas[$file];
    }

    /** @param array<string,mixed> $schema */
    private function check(mixed $data, array $schema, string $file, string $path): bool
    {
        if (count($this->errors) >= $this->maxErrors) {
            return false;
        }
        foreach (array_keys($schema) as $key) {
            if (! in_array($key, self::KEYWORDS, true) && ! in_array($key, self::ANNOTATIONS, true)) {
                throw new RuntimeException("Nicht unterstütztes Schema-Schlüsselwort '{$key}' in {$file}");
            }
        }
        $ok = true;
        $fail = function (string $message) use ($path, &$ok): void {
            $ok = false;
            if (count($this->errors) < $this->maxErrors) {
                $this->errors[] = "{$path}: {$message}";
            }
        };

        if (isset($schema['$ref'])) {
            [$refFile, $pointer] = array_pad(explode('#', (string) $schema['$ref'], 2), 2, '');
            $refFile = $refFile === '' ? $file : $refFile;
            $target = $this->load($refFile);
            foreach (array_filter(explode('/', ltrim($pointer, '/')), 'strlen') as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (! is_array($target) || ! array_key_exists($part, $target)) {
                    throw new RuntimeException("Ungültige Referenz {$schema['$ref']}");
                }
                $target = $target[$part];
            }
            if (! $this->check($data, $target, $refFile, $path)) {
                $ok = false;
            }
        }

        if (isset($schema['type']) && ! $this->typeMatches($data, (array) $schema['type'])) {
            $fail('erwartet Typ '.implode('|', (array) $schema['type']).', gefunden '.get_debug_type($data));

            return false;
        }
        if (array_key_exists('enum', $schema) && ! in_array($data, $schema['enum'], true)) {
            $fail('Wert nicht erlaubt: '.json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $fail('erwartet '.json_encode($schema['const']));
        }

        if (is_string($data)) {
            $length = mb_strlen($data);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $fail("zu kurz (min. {$schema['minLength']})");
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $fail("zu lang (max. {$schema['maxLength']})");
            }
            if (isset($schema['pattern']) && ! preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $data)) {
                $fail("passt nicht zum Muster {$schema['pattern']}");
            }
        }
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $fail("kleiner als {$schema['minimum']}");
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $fail("größer als {$schema['maximum']}");
            }
            if (isset($schema['exclusiveMinimum']) && $data <= $schema['exclusiveMinimum']) {
                $fail("muss größer als {$schema['exclusiveMinimum']} sein");
            }
        }

        if (is_array($data) && array_is_list($data) && $this->isArrayContext($schema, $data)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                $fail("mindestens {$schema['minItems']} Einträge");
            }
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                $fail("höchstens {$schema['maxItems']} Einträge");
            }
            if (isset($schema['items'])) {
                foreach ($data as $i => $item) {
                    if (! $this->check($item, $schema['items'], $file, "{$path}[{$i}]")) {
                        $ok = false;
                    }
                }
            }
            if (isset($schema['contains'])) {
                $matches = 0;
                foreach ($data as $item) {
                    if ($this->quietly($item, $schema['contains'], $file)) {
                        $matches++;
                    }
                }
                $min = $schema['minContains'] ?? 1;
                if ($matches < $min) {
                    $fail("mindestens {$min} passende Einträge erwartet, gefunden {$matches}");
                }
                if (isset($schema['maxContains']) && $matches > $schema['maxContains']) {
                    $fail("höchstens {$schema['maxContains']} passende Einträge erlaubt, gefunden {$matches}");
                }
            }
        }

        if (is_array($data) && (! array_is_list($data) || $data === []) && $this->isObjectContext($schema)) {
            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $data)) {
                    $fail("Pflichtfeld '{$required}' fehlt");
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach ($data as $key => $value) {
                if (isset($properties[$key])) {
                    if (! $this->check($value, $properties[$key], $file, "{$path}.{$key}")) {
                        $ok = false;
                    }
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $fail("unbekanntes Feld '{$key}'");
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    if (! $this->check($value, $schema['additionalProperties'], $file, "{$path}.{$key}")) {
                        $ok = false;
                    }
                }
            }
        }

        foreach ($schema['allOf'] ?? [] as $sub) {
            if (! $this->check($data, $sub, $file, $path)) {
                $ok = false;
            }
        }
        if (isset($schema['if']) && $this->quietly($data, $schema['if'], $file) && isset($schema['then'])) {
            if (! $this->check($data, $schema['then'], $file, $path)) {
                $ok = false;
            }
        }
        if (isset($schema['not']) && $this->quietly($data, $schema['not'], $file)) {
            $fail('darf dem ausgeschlossenen Schema nicht entsprechen');
        }

        return $ok;
    }

    /** Prüft ohne Fehler zu sammeln (für if/contains/not). @param array<string,mixed> $schema */
    private function quietly(mixed $data, array $schema, string $file): bool
    {
        $saved = $this->errors;
        $savedMax = $this->maxErrors;
        $this->errors = [];
        $this->maxErrors = PHP_INT_MAX;
        $ok = $this->check($data, $schema, $file, '$');
        $this->errors = $saved;
        $this->maxErrors = $savedMax;

        return $ok;
    }

    /** @param list<string> $types */
    private function typeMatches(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            $match = match ($type) {
                'null' => $data === null,
                'boolean' => is_bool($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'string' => is_string($data),
                'array' => is_array($data) && array_is_list($data),
                'object' => is_array($data) && (! array_is_list($data) || $data === []),
                default => throw new RuntimeException("Unbekannter Typ {$type}"),
            };
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $schema @param list<mixed> $data */
    private function isArrayContext(array $schema, array $data): bool
    {
        $types = (array) ($schema['type'] ?? []);

        return in_array('array', $types, true) || isset($schema['items']) || isset($schema['contains']) || isset($schema['minItems']) || ($types === [] && $data !== []);
    }

    /** @param array<string,mixed> $schema */
    private function isObjectContext(array $schema): bool
    {
        $types = (array) ($schema['type'] ?? []);

        return in_array('object', $types, true) || isset($schema['properties']) || isset($schema['required']);
    }
}
