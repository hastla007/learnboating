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

        return [
            __('Zugang', 'bootspruefung-learning') => __('Recht einer Person, eine Funktion eines Kurses zu nutzen – mit Status, Laufzeit und Quelle (Bestellung, manuell, Gruppe, Kursgutschein).', 'bootspruefung-learning'),
            __('Funktion', 'bootspruefung-learning') => __('Ein Lernbereich eines Kurses, z. B. Fragen lernen, Prüfungssimulation, Videokurs, Navigationstrainer.', 'bootspruefung-learning'),
            __('Umfang', 'bootspruefung-learning') => __('Was genau freigeschaltet ist: bei Produkten und Gruppenlizenzen die Kurse mit ihren Funktionen, bei kostenlosen Bereichen „ganz“ oder ein Teilumfang.', 'bootspruefung-learning'),
            __('Teilumfang', 'bootspruefung-learning') => __('Begrenzter kostenloser Teil, z. B. die ersten 10 Fragen je Modul oder der erste Prüfungsbogen.', 'bootspruefung-learning'),
            __('Laufzeit', 'bootspruefung-learning') => __('Wie lange ein Zugang ab Beginn gilt. Wird vorbelegt; „ohne Ablauf (dauerhaft)“ nur ausdrücklich.', 'bootspruefung-learning'),
            __('Freischalten', 'bootspruefung-learning') => __('Einer Person einen Zugang geben – durch Kauf, Kursgutschein, Gruppenplatz oder manuell.', 'bootspruefung-learning'),
            __('Zeitversetzte Freischaltung', 'bootspruefung-learning') => __('Ein Modul oder Videokapitel öffnet sich erst eine bestimmte Zahl von Tagen nach Beginn des Zugangs.', 'bootspruefung-learning'),
            __('Aussetzen', 'bootspruefung-learning') => __('Zugang vorübergehend sperren, z. B. bei einem Zahlungskonflikt. Lässt sich wieder aktivieren.', 'bootspruefung-learning'),
            __('Widerrufen', 'bootspruefung-learning') => __('Zugang endgültig beenden (Status „beendet“), z. B. nach einer Erstattung.', 'bootspruefung-learning'),
            __('Freigeben', 'bootspruefung-learning') => __('Inhalte, Regelwerke oder einen Import für die Auslieferung an Lernende zulassen – nach Prüfung der Rechte bzw. der Vorschau.', 'bootspruefung-learning'),
            __('Rechte-Status', 'bootspruefung-learning') => __('Ob Inhalte gezeigt werden dürfen: lizenziert, frei (gemeinfrei), in Prüfung, ungeklärt oder gesperrt. „Ungeklärt“ und „gesperrt“ werden nicht ausgeliefert.', 'bootspruefung-learning'),
            __('Lokale Abweichung', 'bootspruefung-learning') => __('Von Hand gesetzter Wert (mit Begründung), der den importierten Wert ersetzt und bei neuen Importen erhalten bleibt.', 'bootspruefung-learning'),
            __('Regelwerk', 'bootspruefung-learning') => __('Prüfungsregeln eines Kurses: Zeit, Fragen je Modul, Bestehensgrenze.', 'bootspruefung-learning'),
            __('Prüfungsmodus / Übungsmodus', 'bootspruefung-learning') => __('Mit freigegebenem, geprüftem Regelwerk läuft die Prüfungssimulation im Prüfungsmodus, sonst als Übung.', 'bootspruefung-learning'),
            __('Inhaltspaket', 'bootspruefung-learning') => __('Die importierte Sammlung aller Kursinhalte (Fragen, Medien, Aufgaben, Videos, Regelwerke) in einer Version.', 'bootspruefung-learning'),
            __('Gruppenlizenz', 'bootspruefung-learning') => __('Ein Kauf mit mehreren Plätzen; die verwaltende Person lädt Teilnehmende ein. Jeder belegte Platz ist ein Zugang.', 'bootspruefung-learning'),
            __('Protokoll', 'bootspruefung-learning') => __('Liste aller Änderungen an Zugängen, Gruppen, Inhalten, Importen und Einstellungen – wer, wann, warum.', 'bootspruefung-learning'),
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
