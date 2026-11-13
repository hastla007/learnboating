<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

/**
 * Vorbelegte Laufzeiten für neue Zugänge (T101): Produkte und manuelle Freischaltungen bekommen eine
 * Laufzeit; „ohne Ablauf“ gibt es nur ausdrücklich. Kennt der Shop-Katalog den Kurs bzw. die SKU, gilt
 * dessen Laufzeit (Vollkurse 365 Tage, SKN/FKN und Ergänzungen 182 Tage). Bestehende Werte bleiben unberührt.
 */
final class DefaultDuration
{
    /** Produkte ohne Katalog-Eintrag: 12 Monate. */
    public const PRODUCT_DAYS = 365;

    /** Manuelle Freischaltung ohne Katalog-Eintrag des Kurses: 6 Monate. */
    public const MANUAL_DAYS = 182;

    /** Laufzeit des Einzelkurs-Produkts im Katalog (nur Kurs-Produkte, keine Upgrades/Pässe/Gruppen), sonst $fallback. */
    public static function forCourse(string $courseCode, int $fallback = self::MANUAL_DAYS): int
    {
        foreach (self::catalog() as $item) {
            if (($item['courses'] ?? []) === [$courseCode] && empty($item['seats']) && empty($item['hidden'])
                && ! in_array($item['group'] ?? '', ['simulator', 'videoupgrade'], true) && (int) ($item['days'] ?? 0) > 0) {
                return (int) $item['days'];
            }
        }

        return $fallback;
    }

    /** Laufzeit für das Lernzugang-Feld eines Produkts: Katalog-Laufzeit der SKU, sonst 365 Tage. */
    public static function forSku(string $sku): int
    {
        foreach (self::catalog() as $item) {
            if ($sku !== '' && ($item['sku'] ?? '') === $sku && (int) ($item['days'] ?? 0) > 0) {
                return (int) $item['days'];
            }
        }

        return self::PRODUCT_DAYS;
    }

    /** @return array<string,int> Kurscode => Tage (für die Vorbelegung im Formular „Zugang manuell freischalten“) */
    public static function forCourses(array $courseCodes): array
    {
        $out = [];
        foreach ($courseCodes as $code) {
            $out[(string) $code] = self::forCourse((string) $code);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function catalog(): array
    {
        try {
            return ShopCatalog::products();
        } catch (\Throwable) {
            return [];
        }
    }
}
