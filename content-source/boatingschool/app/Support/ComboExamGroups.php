<?php

namespace App\Support;

/**
 * Kombikurse spiegeln die Prüfungsbögen mehrerer Teilkurse unter einer
 * gemeinsamen Kurs-ID (siehe ComboExamPaperSeeder). Da exam_paper je Kurs
 * eindeutige paper_number braucht, bekommt der zweite Teilkurs einen
 * Nummernblock mit Offset (siehe GROUPS); diese Zuordnung von
 * paper_number zu Teilkurs-Label und Anzeigenummer wird für die
 * Prüfungsbögen-Übersicht gebraucht, analog zu ComboVideoGroups.
 *
 * 'course' nennt den Teilkurs, aus dem die Bögen einer Gruppe stammen:
 * ein Versuch mit einem solchen Bogen läuft nach dem Regelwerk dieses
 * Teilkurses (Bestehensgrenze und Zeitlimit, siehe
 * CourseDefinition::examRuleSetForPaper()), da die Teilprüfungen
 * verschiedene amtliche Grenzen haben (z. B. SRC 19/24 in 30 Minuten,
 * UBI 17/22 in 60 Minuten). Im Kombikurs SBF See & Binnen kommen die
 * Basisfragen nur einmal dran (im See-Teil), die Binnen-Bögen sind
 * deshalb die 23 Binnen-Fragen der Ergänzung See -> Binnen.
 */
class ComboExamGroups
{
    public const GROUPS = [
        'SBF-SEE-BIN' => [
            ['label' => 'SBF See', 'course' => 'SBF-SEE', 'min' => 1, 'max' => 15, 'offset' => 0],
            ['label' => 'SBF Binnen', 'course' => 'ADD-SEE-TO-BIN', 'min' => 101, 'max' => 115, 'offset' => 100],
        ],
        'SRC-UBI' => [
            ['label' => 'SRC', 'course' => 'SRC', 'min' => 1, 'max' => 12, 'offset' => 0],
            ['label' => 'UBI', 'course' => 'UBI', 'min' => 101, 'max' => 112, 'offset' => 100],
            ['label' => 'UBI-Ergänzung', 'course' => 'ADD-UBI-TO-SRC', 'min' => 201, 'max' => 212, 'offset' => 200],
        ],
    ];

    /** Gruppeninfo (label, Anzeigenummer) für einen gegebenen Prüfungsbogen, oder null außerhalb eines Kombikurses. */
    public static function infoFor(string $courseCode, int $paperNumber): ?array
    {
        $group = self::groupFor($courseCode, $paperNumber);

        return $group ? ['label' => $group['label'], 'displayNumber' => $paperNumber - $group['offset']] : null;
    }

    /** Kurs-Code des Teilkurses, aus dem ein Prüfungsbogen stammt, oder null außerhalb eines Kombikurses. */
    public static function sourceCourseFor(string $courseCode, int $paperNumber): ?string
    {
        return self::groupFor($courseCode, $paperNumber)['course'] ?? null;
    }

    private static function groupFor(string $courseCode, int $paperNumber): ?array
    {
        foreach (self::GROUPS[$courseCode] ?? [] as $group) {
            if ($paperNumber >= $group['min'] && $paperNumber <= $group['max']) {
                return $group;
            }
        }

        return null;
    }
}
