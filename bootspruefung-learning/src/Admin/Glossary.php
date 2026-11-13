<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Admin;

/**
 * Feste Begriffe der Adminseiten (T100). Dieselbe Liste steht in docs/glossar.md; Admin-Texte verwenden nur
 * diese Wörter (keine englischen Fachwörter, keine Kapitelverweise).
 */
final class Glossary
{
    /** @return array<string,string> Begriff => Erklärung */
    public static function terms(): array
    {
        $d = 'bootspruefung-learning';

        return [
            __('Zugang', $d) => __('Recht einer Person, eine Funktion eines Kurses zu nutzen – mit Status, Laufzeit und Quelle (Bestellung, manuell, Gruppe, Kursgutschein).', $d),
            __('Funktion', $d) => __('Ein Lernbereich eines Kurses, z. B. Fragen lernen, Prüfungssimulation, Videokurs, Navigationstrainer.', $d),
            __('Umfang', $d) => __('Was genau freigeschaltet ist: bei Produkten und Gruppenlizenzen die Kurse mit ihren Funktionen, bei kostenlosen Bereichen „ganz“ oder ein Teilumfang.', $d),
            __('Teilumfang', $d) => __('Begrenzter kostenloser Teil, z. B. die ersten 10 Fragen je Modul oder der erste Prüfungsbogen.', $d),
            __('Laufzeit', $d) => __('Wie lange ein Zugang ab Beginn gilt. Wird vorbelegt; „ohne Ablauf (dauerhaft)“ nur ausdrücklich.', $d),
            __('Freischalten', $d) => __('Einer Person einen Zugang geben – durch Kauf, Kursgutschein, Gruppenplatz oder manuell.', $d),
            __('Zeitversetzte Freischaltung', $d) => __('Ein Modul oder Videokapitel öffnet sich erst eine bestimmte Zahl von Tagen nach Beginn des Zugangs.', $d),
            __('Aussetzen', $d) => __('Zugang vorübergehend sperren, z. B. bei einem Zahlungskonflikt. Lässt sich wieder aktivieren.', $d),
            __('Widerrufen', $d) => __('Zugang endgültig beenden (Status „beendet“), z. B. nach einer Erstattung.', $d),
            __('Freigeben', $d) => __('Inhalte, Regelwerke oder einen Import für die Auslieferung an Lernende zulassen – nach Prüfung der Rechte bzw. der Vorschau.', $d),
            __('Rechte-Status', $d) => __('Ob Inhalte gezeigt werden dürfen: lizenziert, frei (gemeinfrei), in Prüfung, ungeklärt oder gesperrt. „Ungeklärt“ und „gesperrt“ werden nicht ausgeliefert.', $d),
            __('Lokale Abweichung', $d) => __('Von Hand gesetzter Wert (mit Begründung), der den importierten Wert ersetzt und bei neuen Importen erhalten bleibt.', $d),
            __('Regelwerk', $d) => __('Prüfungsregeln eines Kurses: Zeit, Fragen je Modul, Bestehensgrenze.', $d),
            __('Prüfungsmodus / Übungsmodus', $d) => __('Mit freigegebenem, geprüftem Regelwerk läuft die Prüfungssimulation im Prüfungsmodus, sonst als Übung.', $d),
            __('Inhaltspaket', $d) => __('Die importierte Sammlung aller Kursinhalte (Fragen, Medien, Aufgaben, Videos, Regelwerke) in einer Version.', $d),
            __('Gruppenlizenz', $d) => __('Ein Kauf mit mehreren Plätzen; die verwaltende Person lädt Teilnehmende ein. Jeder belegte Platz ist ein Zugang.', $d),
            __('Protokoll', $d) => __('Liste aller Änderungen an Zugängen, Gruppen, Inhalten, Importen und Einstellungen – wer, wann, warum.', $d),
        ];
    }

    /** Aufklappbares Glossar (Übersichtsseite). */
    public static function render(): void
    {
        echo '<details class="bp-glossary"><summary><strong>'.esc_html__('Begriffe (Glossar)', 'bootspruefung-learning').'</strong></summary><dl>';
        foreach (self::terms() as $term => $text) {
            echo '<dt>'.esc_html($term).'</dt><dd>'.esc_html($text).'</dd>';
        }
        echo '</dl></details>';
    }
}
