<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Commerce;

use Bootspruefung\Learning\Learning\Services\AccessService;

/**
 * Kursempfehlungen und Ausblenden von Doppeltem (Festlegung 10.11.2026).
 *
 * Ein Kurs gilt als „besessen“, wenn ein gültiger Zugang zum Fragenkatalog besteht
 * (Kauf, manuell oder Gruppe; Feature question_bank oder all). Der kostenlose Teilumfang
 * zählt nicht. Für alle anderen Kurse gilt je besessenem Kurs:
 *  - hide: Kurs nicht mehr anbieten (Inhalt schon vorhanden),
 *  - recommend: passende Ergänzung vorschlagen (wenn nicht besessen und nicht ausgeblendet).
 * Die Tabelle ist über den Filter `bootspruefung_course_relations` änderbar.
 */
final class CourseRelations
{
    /** @var array<string,array{hide:list<string>,recommend:list<string>}> besessener Kurs => Regeln */
    public const RULES = [
        'SBF-BIN-MOTOR' => ['hide' => ['SBF-SEE-BIN', 'ADD-SEE-TO-BIN'], 'recommend' => ['ADD-BIN-TO-SEE', 'ADD-BIN-SEGEL']],
        'SBF-SEE' => ['hide' => ['SBF-SEE-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-SEE-TO-BIN']],
        'SBF-SEE-BIN' => ['hide' => ['SBF-SEE', 'SBF-BIN-MOTOR', 'ADD-SEE-TO-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-BIN-SEGEL']],
        'SBF-BIN-SEGEL' => ['hide' => ['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-BIN-SEGEL', 'ADD-SEE-TO-BIN'], 'recommend' => ['ADD-BIN-TO-SEE']],
        'ADD-BIN-TO-SEE' => ['hide' => ['SBF-SEE', 'SBF-SEE-BIN', 'ADD-SEE-TO-BIN'], 'recommend' => []],
        'ADD-SEE-TO-BIN' => ['hide' => ['SBF-BIN-MOTOR', 'SBF-SEE-BIN', 'ADD-BIN-TO-SEE'], 'recommend' => ['ADD-BIN-SEGEL']],
        'ADD-BIN-SEGEL' => ['hide' => ['SBF-BIN-SEGEL'], 'recommend' => []],
        'SRC' => ['hide' => ['SRC-UBI'], 'recommend' => ['ADD-UBI-TO-SRC']],
        'UBI' => ['hide' => ['SRC-UBI', 'ADD-UBI-TO-SRC'], 'recommend' => ['SRC']],
        'SRC-UBI' => ['hide' => ['SRC', 'UBI', 'ADD-UBI-TO-SRC'], 'recommend' => []],
        'ADD-UBI-TO-SRC' => ['hide' => ['UBI', 'SRC-UBI'], 'recommend' => []],
        // Ergänzung FKN → SKN vorerst nicht im Verkauf (ShopCatalog::DISABLED_COURSES) – keine Empfehlung.
        'FKN' => ['hide' => [], 'recommend' => []],
        'SKN' => ['hide' => ['ADD-FKN-TO-SKN'], 'recommend' => []],
    ];

    /** @return array<string,array{hide:list<string>,recommend:list<string>}> Tabelle nach Filter, bereinigt */
    public static function rules(): array
    {
        /** Filter: Empfehlungs-/Ausblende-Tabelle ändern (besessener Kurs => ['hide' => [...], 'recommend' => [...]]). */
        $raw = apply_filters('bootspruefung_course_relations', self::RULES);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $owned => $rule) {
            if (! is_string($owned) || ! is_array($rule)) {
                continue;
            }
            $out[$owned] = [
                'hide' => array_values(array_filter((array) ($rule['hide'] ?? []), 'is_string')),
                'recommend' => array_values(array_filter((array) ($rule['recommend'] ?? []), 'is_string')),
            ];
        }

        return $out;
    }

    /**
     * Besessene Kurse: gültiger Zugang zum Fragenkatalog (Kauf, manuell, Gruppe) – eine Abfrage für alle Kurse.
     * Video-/Simulator-Upgrades zählen nicht (eigene Features), der Teilumfang steht nicht in den Zugängen.
     * @return list<string>
     */
    public static function ownedCourses(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        global $wpdb;
        $now = current_time('mysql', true);

        return array_map('strval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT course_code FROM {$wpdb->prefix}bp_entitlement WHERE user_id = %d AND status = 'active' AND feature_code IN (%s, 'all')
             AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s)",
            $userId, AccessService::QUESTION_BANK, $now, $now
        )));
    }

    /**
     * Wertet die Tabelle aus (ohne Datenbank).
     *
     * @param list<string> $owned besessene Kurse
     * @param list<string> $courseCodes Kurse, für die das Ergebnis gebraucht wird (Reihenfolge = Anzeige)
     * @param list<string> $unlocked Kurse mit vollem Fragenkatalog aus anderen Quellen (z. B. kostenlos für alle):
     *                               nie ausgeblendet oder empfohlen, lösen aber selbst nichts aus
     * @return array<string,array{hidden:bool,recommended_for:list<string>}>
     */
    public static function evaluate(array $owned, array $courseCodes, array $unlocked = []): array
    {
        $rules = self::rules();
        $ownedSet = array_flip($owned);
        $unlockedSet = array_flip($unlocked);
        $hide = [];
        $recommend = [];
        // Auslöser in Anzeigereihenfolge (stabile Ausgabe), dann übrige besessene Kurse.
        $triggers = array_values(array_unique(array_merge(array_values(array_intersect($courseCodes, $owned)), $owned)));
        foreach ($triggers as $code) {
            foreach ($rules[$code]['hide'] ?? [] as $target) {
                $hide[$target] = true;
            }
            foreach ($rules[$code]['recommend'] ?? [] as $target) {
                $recommend[$target][] = $code;
            }
        }
        $out = [];
        foreach ($courseCodes as $code) {
            $free = isset($ownedSet[$code]) || isset($unlockedSet[$code]);
            $hidden = ! $free && isset($hide[$code]);
            $out[$code] = [
                'hidden' => $hidden,
                'recommended_for' => $free || $hidden ? [] : array_values(array_unique($recommend[$code] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * Ergebnis je Kurs für einen Nutzer (eine Abfrage für alle Zugänge).
     *
     * @param list<string> $courseCodes
     * @param list<string> $unlocked siehe evaluate()
     * @return array<string,array{hidden:bool,recommended_for:list<string>}>
     */
    public static function forUser(int $userId, array $courseCodes, array $unlocked = []): array
    {
        return self::evaluate(self::ownedCourses($userId), $courseCodes, $unlocked);
    }
}
