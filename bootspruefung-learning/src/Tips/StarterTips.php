<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Tips;

/**
 * Starttexte für „Tipps & Tricks“ (neu geschrieben, 06.11.2026).
 *
 * Redaktionelle Regel: nur Aussagen über die Funktionen dieser Plattform (so wie sie
 * gebaut sind) und allgemeine Lernhinweise. Keine Gebühren, Fristen, Altersgrenzen
 * oder Rechtsvorschriften – die ändern sich und sind beim Prüfungsausschuss verbindlich.
 * Prüfungsinhalte nur so allgemein, wie sie in den Anleitungen der Quelle stehen.
 */
final class StarterTips
{
    /** @return array<string,list<array{0:string,1:string}>> Kategorie => [Titel, Text] */
    public static function all(): array
    {
        return [
            'Lernen mit der Plattform' => [
                ['Mit dem Smarttrainer starten',
                    'Der Smarttrainer stellt dir zuerst neue Fragen, danach falsch beantwortete und Fragen aus Themen, in denen du noch unsicher bist. Eine Frage gilt als gefestigt, wenn du sie dreimal hintereinander richtig beantwortet hast. So musst du nicht selbst planen, was als Nächstes dran ist.'],
                ['Falsch beantwortete Fragen gezielt wiederholen',
                    'Über „Falsch beantwortete“ übst du nur die Fragen, bei denen du zuletzt danebenlagst. Nimm dir diese Liste regelmäßig vor, bis sie leer ist – dort steckt der größte Lerngewinn.'],
                ['Merkliste für knifflige Fragen',
                    'Fragen, die du dir noch einmal in Ruhe ansehen willst, markierst du mit „Merken“. Unter „Merkliste“ findest du sie gesammelt wieder.'],
                ['Fortschritt im Blick behalten',
                    'Auf der Kursseite siehst du, wie viele Fragen du schon gefestigt hast. Dein Fortschritt wird in deinem Konto gespeichert, du kannst also am Rechner anfangen und am Smartphone weiterlernen.'],
            ],
            'Prüfungsvorbereitung' => [
                ['Lieber oft und kurz als selten und lang',
                    'Mehrere kurze Lerneinheiten über die Woche verteilt bleiben besser im Gedächtnis als eine lange Sitzung kurz vor der Prüfung. Schon 15 bis 20 Minuten am Tag mit dem Smarttrainer bringen dich weiter.'],
                ['Prüfungssimulation als Generalprobe',
                    'Die Prüfungssimulation läuft mit Zeitlimit und wertet dich wie in der Prüfung aus. Nutze sie, wenn du die meisten Fragen schon kennst, und wiederhole sie, bis du mehrmals hintereinander sicher bestehst. Die Auswertung zeigt dir, welche Fragen du noch üben solltest.'],
                ['Antworten verstehen statt auswendig lernen',
                    'Lies bei falschen Antworten die Erklärung. Wer den Grund für eine Regel kennt, erkennt die richtige Antwort auch dann, wenn die Frage etwas anders formuliert ist.'],
                ['Navigationsaufgaben mit Karte üben (SBF See)',
                    'Löse die Navigationsaufgaben mit Übungsseekarte, Kursdreieck und Zirkel, so wie in der Prüfung. Vergleiche danach deinen Lösungsweg mit der Musterlösung und schätze dich ehrlich ein.'],
                ['Die praktische Prüfung nicht vergessen',
                    'Zu vielen Scheinen gehört neben der Theorie ein praktischer Teil. Die Praxiskarten helfen dir, die Abläufe vorab zu verinnerlichen – das Üben am Boot oder Gerät ersetzen sie nicht.'],
                ['Funk: Englische Funktexte laut üben (SRC)',
                    'Zur SRC-Prüfung gehört das Mitschreiben und Übersetzen englischer Funktexte. Lies die Texte im Funktraining laut, nutze die Buchstabiertafel und übe die Abläufe in der Gerätesimulation, bis sie sitzen.'],
            ],
            'Anmeldung und Prüfungstag' => [
                ['Frühzeitig beim Prüfungsausschuss informieren',
                    'Anmeldefristen, Termine, Gebühren und benötigte Unterlagen legt der Prüfungsausschuss fest und sie können sich ändern. Informiere dich rechtzeitig direkt dort, damit du deinen Wunschtermin bekommst.'],
                ['Unterlagen vollständig vorbereiten',
                    'Stelle alle Unterlagen zusammen, die dein Prüfungsausschuss verlangt, und prüfe sie vor dem Absenden noch einmal. Unvollständige Anträge kosten oft Zeit.'],
                ['Ausgeruht in die Prüfung',
                    'Am Tag vor der Prüfung lieber nur noch eine kurze Wiederholung mit der Merkliste und den falsch beantworteten Fragen machen und dann ausschlafen. Plane für den Prüfungstag genug Zeit für die Anreise ein.'],
            ],
        ];
    }
}
