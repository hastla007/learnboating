<?php

declare(strict_types=1);

namespace ContentBuild;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimaler XLSX-Leser (nur Werte, keine Formatierung) ohne Fremdbibliothek.
 * Liefert Zeilen eines Blatts als Liste von Arrays mit Spaltenindex => Wert.
 */
final class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<string,string> Blattname => Pfad im Archiv */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("XLSX kann nicht geöffnet werden: {$path}");
        }
        $this->loadSharedStrings();
        $this->loadSheets();
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * @return list<array<string,mixed>> Zeilen als Header => Wert; leere Zeilen entfallen.
     */
    public function rowsWithHeader(string $sheet): array
    {
        $rows = $this->rows($sheet);
        if ($rows === []) {
            return [];
        }
        $headerRow = array_shift($rows);
        $header = [];
        foreach ($headerRow as $index => $name) {
            $header[$index] = trim((string) $name);
        }
        $result = [];
        foreach ($rows as $row) {
            $assoc = [];
            $hasValue = false;
            foreach ($header as $index => $name) {
                if ($name === '') {
                    continue;
                }
                $value = $row[$index] ?? null;
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
                $assoc[$name] = $value;
            }
            if ($hasValue) {
                $result[] = $assoc;
            }
        }

        return $result;
    }

    /** @return list<array<int,mixed>> */
    public function rows(string $sheet): array
    {
        if (! isset($this->sheets[$sheet])) {
            throw new RuntimeException("Blatt '{$sheet}' existiert nicht.");
        }
        $xml = $this->xml($this->sheets[$sheet]);
        $xml->registerXPathNamespace('m', self::NS_MAIN);
        $rows = [];
        foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $rowEl) {
            $row = [];
            foreach ($rowEl->children(self::NS_MAIN)->c as $cell) {
                $ref = self::attr($cell, 'r');
                $col = self::columnIndex(preg_replace('/\d+/', '', $ref));
                $row[$col] = $this->cellValue($cell);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function cellValue(SimpleXMLElement $cell): mixed
    {
        $type = self::attr($cell, 't');
        $children = $cell->children(self::NS_MAIN);
        $v = isset($children->v) ? (string) $children->v : null;

        return match ($type) {
            's' => $v === null ? null : ($this->sharedStrings[(int) $v] ?? ''),
            'b' => $v === null ? null : $v === '1',
            'inlineStr' => $this->richText($children->is),
            'str', 'e' => $v,
            default => $v === null ? null : self::number($v),
        };
    }

    private static function number(string $v): int|float|string
    {
        if (preg_match('/^-?\d+$/', $v)) {
            return (int) $v;
        }
        if (is_numeric($v)) {
            $f = (float) $v;

            return floor($f) === $f && abs($f) < PHP_INT_MAX ? (int) $f : $f;
        }

        return $v;
    }

    private function richText(?SimpleXMLElement $el): string
    {
        if ($el === null) {
            return '';
        }
        $children = $el->children(self::NS_MAIN);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::NS_MAIN)->t;
        }

        return $text;
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        $xml = $this->xml('xl/sharedStrings.xml');
        foreach ($xml->children(self::NS_MAIN)->si as $si) {
            $this->sharedStrings[] = $this->richText($si);
        }
    }

    private function loadSheets(): void
    {
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($rels->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
            $target = self::attr($rel, 'Target');
            $targets[self::attr($rel, 'Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }
        $workbook = $this->xml('xl/workbook.xml');
        foreach ($workbook->children(self::NS_MAIN)->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::NS_REL)['id'];
            $this->sheets[self::attr($sheet, 'name')] = $targets[$id];
        }
    }

    private function xml(string $name): SimpleXMLElement
    {
        $content = $this->zip->getFromName($name);
        if ($content === false) {
            throw new RuntimeException("Eintrag fehlt im XLSX: {$name}");
        }

        return new SimpleXMLElement($content, LIBXML_NONET);
    }

    private static function attr(SimpleXMLElement $el, string $name): string
    {
        return (string) ($el->attributes()[$name] ?? '');
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
