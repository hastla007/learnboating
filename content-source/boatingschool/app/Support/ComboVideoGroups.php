<?php

namespace App\Support;

/**
 * Kombikurse bündeln die Videokapitel mehrerer Teilkurse unter einer
 * gemeinsamen Kurs-ID (siehe ComboVideoCourseSeeder). Diese Zuordnung von
 * Kapitelnamen zu Teilkurs-Label wird sowohl für die Fortschrittskarten auf
 * der Kursdetailseite (CourseController) als auch für den Videoplayer
 * (VideoCourseController) gebraucht, daher zentral an einer Stelle.
 * 'motif' wählt die Hintergrundgrafik der Teilkurs-Karte.
 *
 * Bug-Review (SBF See & Binnen: Videokapitel "Berufsschifffahrt" und
 * "Leuchtfeuer und Tonnen" fehlen): SeemannschaftVideoSeeder legt diese
 * beiden Kapitel erst NACH der ursprünglichen Erstfassung dieser Liste
 * für SBF SEE an (siehe dort); ComboVideoCourseSeeder spiegelt zwar
 * bereits alle tatsächlichen SBF-SEE-Kapitel dynamisch in den
 * Kombikurs, die Fortschrittskarte/Kapitelfilterung in CourseController
 * (`$comboVideoGroups`) kennt aber nur die hier HARTCODIERT gelisteten
 * Kapitel -- ein hier fehlendes Kapitel landet dadurch nicht in der
 * "SBF See"-Kapitelübersicht (CourseController baut `moduleIds` aus
 * genau dieser Liste, der Tile-Link filtert video.index via ?kapitel=
 * strikt auf diese IDs) und zählt auch nicht im Fortschritts-Nenner,
 * sodass die Kachel 100% zeigen konnte, obwohl zwei echte Kapitel nie
 * angesehen wurden. "Knoten" fehlt hier bewusst weiterhin -- es hat
 * (wie "Praxisvideos (Motor)" und "Navigation") eine eigene,
 * eigenständige Kachel/Seite außerhalb dieser Gruppen-Logik.
 */
class ComboVideoGroups
{
    public const GROUPS = [
        'SBF-SEE-BIN' => [
            ['label' => 'SBF See', 'motif' => 'compass', 'chapters' => [
                'Einführung in den Sportbootführerschein', 'Seemannschaft',
                'Praxisvideos (Motor)', 'Navigation', 'Wetterkunde', 'Ausweichregeln',
                'Berufsschifffahrt', 'Lichter und Signale', 'Leuchtfeuer und Tonnen',
            ]],
            ['label' => 'SBF Binnen', 'motif' => 'anchor', 'chapters' => [
                'Grundlagen', 'Lichter, Zeichen und Töne', 'Bootsführung und Bordtechnik',
            ]],
        ],
        // Kapitel aus FunkVideoCourseSeeder.
        'SRC-UBI' => [
            ['label' => 'SRC', 'motif' => 'antenna', 'chapters' => [
                'Einführung in das SRC', 'Technik und Funkgerät', 'Routineverkehr im Alltag', 'Notverkehr – MAYDAY',
                'Dringlichkeit und Sicherheit – PAN PAN und SECURITE', 'Zulassung und Prüfungsvorbereitung',
            ]],
            ['label' => 'UBI', 'motif' => 'radio', 'chapters' => [
                'Einführung in das UBI', 'Technik: Kanäle und Funkanlage', 'Verkehrskreise und Reviere im Alltag',
                'Notverkehr im Binnenfunk – MAYDAY', 'Dringlichkeit und Sicherheit im Binnenfunk', 'UBI-Abschluss und Prüfungsvorbereitung',
            ]],
        ],
    ];

    /** Teilkurs-Label für ein gegebenes Videokapitel, oder null außerhalb eines Kombikurses. */
    public static function labelForChapter(string $courseCode, string $chapterTitle): ?string
    {
        foreach (self::GROUPS[$courseCode] ?? [] as $group) {
            if (in_array($chapterTitle, $group['chapters'], true)) {
                return $group['label'];
            }
        }

        return null;
    }
}
