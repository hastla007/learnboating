<?php

/**
 * Mündliche UBI-Prüfung: Aufnahme (ein vorgelesener Funktext wird wortgenau
 * mitgeschrieben) und Abgabe (ein vorgelegter Funktext wird vorgelesen, die
 * markierten Wörter werden mit der internationalen Buchstabiertafel
 * buchstabiert). Eigene Übungstexte im Stil von Meldungen auf den
 * Binnenschifffahrtsstraßen; Fahrzeugnamen sind erfunden.
 *
 * Abgabe: eine Zeile je Meldungsteil, *Wort* = zu buchstabieren (Ziffern
 * einzeln: "zwo", siehe App\Support\FunkTraining::spellGerman()).
 * Die Nummer ist die Position in der Liste (Fortschritt "UBI-AUF-01" …) --
 * neue Texte nur hinten anhängen.
 */
return [
    'aufnahme' => [
        'Revierzentrale Duisburg, hier ist Motorboot Seeschwalbe. Ich befinde mich bei Rhein-Kilometer 781 in der Talfahrt. Ich habe Maschinenausfall und liege sicher vor Anker. Ich erbitte Schlepphilfe. Bitte kommen.',
        'An alle Funkstellen, hier ist Sportboot Lachs. Bei Mosel-Kilometer 128 treibt eine vertriebene Tonne in der Fahrrinne. Bitte vorsichtig fahren. Ende.',
        'Schleuse, hier ist Motorboot Kranich. Ich komme zu Berg und möchte mich zur nächsten Schleusung anmelden. Länge acht Meter, Breite drei Meter. Bitte kommen.',
        // Bug-Review (Begegnung): ein Bergfahrer am rechten Ufer begegnet
        // Steuerbord an Steuerbord; für "Backbord an Backbord" hält er sich
        // am linken Ufer (vorher widersprüchlich "rechten Ufer").
        'Motorschiff Hansestadt, hier ist Sportboot Eule. Ich bin Bergfahrer bei Rhein-Kilometer 554 und halte mich am linken Ufer. Begegnung Backbord an Backbord. Bitte kommen.',
        'Marina Rheinbogen, hier ist Motoryacht Biber. Ich erbitte einen Liegeplatz für zwei Nächte. Länge zwölf Meter, Tiefgang ein Meter zwanzig. Ankunft gegen siebzehn Uhr. Bitte kommen.',
        'An alle Funkstellen, hier ist Motorboot Falke. Bei Main-Kilometer 210 ist ein Sportboot gesunken und ragt am linken Ufer in die Fahrrinne. Bitte Abstand halten. Ende.',
        'Revierzentrale Oberwesel, hier ist Segelboot Libelle. Ich erbitte den aktuellen Wasserstand am Pegel Kaub. Bitte kommen.',
        'An alle Funkstellen, hier ist Motorboot Otter. Meine Dringlichkeitsmeldung von vierzehn Uhr zehn ist aufgehoben. Die Maschine läuft wieder. Ende.',
    ],
    'abgabe' => [
        ['HIER IST MOTORBOOT *KORMORAN*', 'BEI RHEIN KILOMETER *712*', 'MASCHINENAUSFALL, ICH LIEGE VOR ANKER', 'ERBITTE SCHLEPPHILFE', 'BITTE KOMMEN'],
        ['HIER IST SPORTBOOT *EIDER*', 'BEI MAIN KILOMETER *96*', 'TREIBENDER BAUMSTAMM IN DER FAHRRINNE', 'GEFAHR FÜR DIE SCHIFFFAHRT', 'ENDE'],
        ['HIER IST MOTORYACHT *ZANDER*', 'AN DER SCHLEUSE *IFFEZHEIM*', 'ERBITTE SCHLEUSUNG ZU BERG', 'LÄNGE NEUN METER', 'BITTE KOMMEN'],
        ['HIER IST SEGELBOOT *QUELLE*', 'BEI NECKAR KILOMETER *58*', 'LEINE IN DER SCHRAUBE, ICH TREIBE AM UFER ENTLANG', 'ERBITTE SCHLEPPHILFE', 'BITTE KOMMEN'],
        ['HIER IST SPORTBOOT *XAVER*', 'BEI ELBE KILOMETER *487*', 'FEUER IM MASCHINENRAUM', 'DREI PERSONEN AN BORD', 'ERBITTE SOFORTIGE HILFE', 'BITTE KOMMEN'],
        ['HIER IST MOTORBOOT *WIKING*', 'BEI MOSEL KILOMETER *20* ORTSLAGE *KOBERN*', 'VERTRIEBENE TONNE AM RECHTEN UFER', 'ENDE'],
        ['HIER IST MOTORBOOT *VIOLA*', 'BEI WESER KILOMETER *204* ORTSLAGE *MINDEN*', 'PERSON ÜBER BORD', 'ERBITTE SOFORTIGE HILFE', 'BITTE KOMMEN'],
        ['HIER IST SEGELBOOT *PFEIL*', 'BEI RHEIN KILOMETER *546* ORTSLAGE *KAUB*', 'ERBITTE DEN AKTUELLEN WASSERSTAND', 'BITTE KOMMEN'],
    ],
];
