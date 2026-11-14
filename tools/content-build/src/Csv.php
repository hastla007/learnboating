<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;

/** Liest die Semikolon-CSV-Dateien der Quelle (UTF-8, ggf. mit BOM) wie deren Seeder. */
final class Csv
{
    /** @return list<array<string,string>> */
    public static function read(string $path, string $delimiter = ';'): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("CSV kann nicht geöffnet werden: {$path}");
        }
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($header === false) {
            fclose($handle);

            return [];
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map('trim', $header);
        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($data === [null]) {
                continue;
            }
            if (count($data) !== count($header)) {
                fclose($handle);
                throw new RuntimeException(sprintf('%s Zeile %d: %d statt %d Spalten', basename($path), $line, count($data), count($header)));
            }
            $rows[] = array_combine($header, array_map(static fn ($v) => (string) $v, $data));
        }
        fclose($handle);

        return $rows;
    }
}
