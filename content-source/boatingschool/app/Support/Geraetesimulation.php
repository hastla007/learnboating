<?php

namespace App\Support;

use App\Models\CourseDefinition;
use App\Models\User;

/**
 * Gerätesimulation Funk, Stufen 1–3 (Seefunk und Binnenfunk): Lektionen, Prüfungsreihen und Übungsschiff aus
 * database/data/geraetesimulation.php. Der Browser bekommt die Lektionen ohne
 * die Prüfpunkte für Gesprochenes; die prüft der Server (evaluate) mit den
 * Regeln der Praxis-Prüfungssimulation (FunkPraxis).
 */
class Geraetesimulation
{
    public const DEVICE = 'ic-m505';

    public const DEVICE_NAME = 'Icom IC-M505';

    /** @return array{ship: array, address_book: list<array>, directory: list<array>, exams: array<string, list<string>>, exam_meta: array<string, array>, lessons: list<array>} */
    public static function data(): array
    {
        static $data = null;

        return $data ??= include database_path('data/geraetesimulation.php');
    }

    /** Funkscheine des Kurses, die die Gerätesimulation abdeckt (SRC, UBI). */
    public static function catalogsFor(CourseDefinition $course): array
    {
        return array_values(array_intersect(['SRC', 'UBI'], FunkTraining::catalogsFor($course)));
    }

    public static function catalogOf(array $lesson): string
    {
        return $lesson['catalog'] ?? 'SRC';
    }

    /** Sichtbar für diesen Nutzer und Kurs? Seefunk (SRC) und Binnenfunk (UBI). */
    public static function availableFor(?User $user, CourseDefinition $course): bool
    {
        if (! self::catalogsFor($course)) {
            return false;
        }

        return match (config('funk.geraetesimulation')) {
            'all' => true,
            'superadmin' => (bool) $user?->is_superadmin,
            default => false,
        };
    }

    public static function lesson(string $key): ?array
    {
        foreach (self::data()['lessons'] as $lesson) {
            if ($lesson['key'] === $key) {
                return $lesson;
            }
        }

        return null;
    }

    /**
     * Lektionen für den Browser: Gesprochenes nur als Kennzeichen, ohne
     * Prüfpunkte; mit $catalogs nur die Lektionen dieser Funkscheine.
     */
    public static function lessonsForBrowser(?array $catalogs = null): array
    {
        return array_map(function (array $lesson) {
            $lesson['steps'] = array_map(function (array $step) {
                $step['speech'] = isset($step['speech']);

                return $step;
            }, $lesson['steps']);

            return $lesson;
        }, array_values(array_filter(self::data()['lessons'], fn ($lesson) => $catalogs === null || in_array(self::catalogOf($lesson), $catalogs, true))));
    }

    /**
     * Prüfungsreihen der Funkscheine mit Titel, Lektionen und ggf. eigener
     * Ausgangslage des Geräts.
     *
     * @return array<string, array{title: string, catalog: string, lessons: list<string>, start: ?array, substitutes: array}>
     */
    public static function exams(?array $catalogs = null): array
    {
        $data = self::data();
        $exams = [];

        foreach ($data['exams'] as $series => $lessons) {
            $meta = $data['exam_meta'][$series];

            if ($catalogs === null || in_array($meta['catalog'], $catalogs, true)) {
                $exams[$series] = ['title' => $meta['title'], 'catalog' => $meta['catalog'], 'lessons' => $lessons, 'start' => $meta['start'] ?? null, 'substitutes' => $meta['substitutes'] ?? []];
            }
        }

        return $exams;
    }

    /**
     * Prüft einen Funkspruch gegen die Prüfpunkte des Lektionsschritts.
     *
     * @return array{checks: list<array{label: string, ok: bool}>, passed: bool}|null
     */
    public static function evaluate(string $lessonKey, int $stepIndex, string $text): ?array
    {
        $lesson = self::lesson($lessonKey);
        $speech = $lesson['steps'][$stepIndex]['speech'] ?? null;

        if (! $speech) {
            return null;
        }

        $checks = $speech['checks'] ?? null;

        if (isset($speech['scenario'])) {
            $message = self::scenarioMessage($lesson, $speech);
            $checks = $message['checks'];
        }

        return FunkPraxis::checkText($checks, $text);
    }

    /**
     * Meldung (Wortlaut und Prüfpunkte) aus einem Szenario des Praxistrainers;
     * mit "part" nur ein Teil, z. B. bei SECURITE die Ankündigung auf Kanal 16
     * ("announcement") oder die Meldung auf dem Arbeitskanal ("message").
     *
     * @return array{solution: string, checks: list<array{0: string, 1: string, 2: int}>}
     */
    private static function scenarioMessage(array $lesson, array $speech): array
    {
        $task = FunkPraxis::scenario(self::catalogOf($lesson), $speech['scenario']);
        $message = collect($task['fields'])->firstWhere('name', 'message');

        return isset($speech['part']) ? $message['parts'][$speech['part']] : $message;
    }

    /** @return list<string> Gerätekennungen, z. B. ic-m505, ic-m323. */
    public static function deviceKeys(): array
    {
        return array_column(self::data()['devices'], 'key');
    }

    /**
     * Funkscheine, die ein Gerät abdeckt (ohne Angabe „catalogs“ Seefunk
     * und Binnenfunk; die Handfunkgeräte nur Seefunk).
     *
     * @return list<string>
     */
    public static function deviceCatalogs(string $device): array
    {
        return collect(self::data()['devices'])->firstWhere('key', $device)['catalogs'] ?? ['SRC', 'UBI'];
    }

    /**
     * Geräte, die mindestens einen der Funkscheine abdecken; mit
     * $withHandhelds auch Handfunkgeräte, die keinen abdecken (der Browser
     * zeigt sie gesperrt und erklärt, warum Binnenfunk damit verboten ist).
     *
     * @return list<array>
     */
    public static function devicesFor(array $catalogs, bool $withHandhelds = false): array
    {
        return array_values(array_filter(self::data()['devices'], fn ($device) => array_intersect($catalogs, self::deviceCatalogs($device['key'])) !== []
            || ($withHandhelds && $catalogs !== [] && ($device['handheld'] ?? false))));
    }

    /**
     * Das Gerät und seine Vorbilder ("like", auch über mehrere Stufen), wie
     * lineage() in resources/js/funkgeraet/devices/index.js.
     *
     * @return list<string>
     */
    public static function lineage(string $device): array
    {
        $devices = collect(self::data()['devices'])->keyBy('key');
        $chain = [];

        for ($key = $device; $key !== null && $devices->has($key) && ! in_array($key, $chain, true); $key = $devices[$key]['like'] ?? null) {
            $chain[] = $key;
        }

        return $chain;
    }

    public static function progressKey(string $lessonKey, string $device = self::DEVICE): string
    {
        return $device.':'.$lessonKey;
    }

    public static function examKey(string $series, string $device = self::DEVICE): string
    {
        return $device.':pruefung-'.$series;
    }

    /**
     * Lektionen einer Prüfungsreihe für ein Gerät; Aufgaben, die das Gerät
     * nicht kann, sind ersetzt ("substitutes").
     *
     * @return list<string>
     */
    /**
     * Lektion auf diesem Gerät sichtbar (Feld "devices", mit der
     * "like"-Regel wie in resources/js/funkgeraet/devices/index.js).
     */
    public static function lessonAvailable(array $lesson, string $device = self::DEVICE): bool
    {
        if (! in_array(self::catalogOf($lesson), self::deviceCatalogs($device), true)) {
            return false;
        }
        if (! isset($lesson['devices'])) {
            return true;
        }

        return array_intersect(self::lineage($device), $lesson['devices']) !== [];
    }

    /**
     * Schlüssel der Lektionen, die ein Kurs mit diesen Funkscheinen sieht.
     *
     * @return list<string>
     */
    public static function lessonKeys(array $catalogs): array
    {
        return array_column(self::lessonsForBrowser($catalogs), 'key');
    }

    public static function examLessons(string $series, string $device = self::DEVICE): array
    {
        $exam = self::exams()[$series];
        // Ein Gerät mit "like" übernimmt die Ersatzaufgaben des Vorbilds.
        $source = collect(self::lineage($device))->first(fn ($key) => isset($exam['substitutes'][$key]));
        $substitutes = $source ? $exam['substitutes'][$source] : [];

        return array_map(fn ($lesson) => $substitutes[$lesson] ?? $lesson, $exam['lessons']);
    }

    /**
     * Musterlösungen der Funksprüche einer Lektion, in Schrittfolge. Bei
     * Szenarien die Meldung aus FunkPraxis, sonst "solution" des Schritts.
     *
     * @return list<string>
     */
    public static function solutions(string $lessonKey): array
    {
        $solutions = [];
        $lesson = self::lesson($lessonKey);

        foreach ($lesson['steps'] ?? [] as $step) {
            $speech = $step['speech'] ?? null;

            if (! $speech) {
                continue;
            }

            if (isset($speech['scenario'])) {
                $solutions[] = self::scenarioMessage($lesson, $speech)['solution'];
            } else {
                $solutions[] = $speech['solution'];
            }
        }

        return $solutions;
    }
}
