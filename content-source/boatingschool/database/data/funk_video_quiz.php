<?php

/**
 * Quiz der Funk-Videokurse (FunkLessonQuestionSeeder): Katalog => Kapitel
 * => Lektion => amtliche Fragen (content_id) in Lernreihenfolge. Jede der
 * 180 SRC- und 130 UBI-Fragen steht genau einmal, bei der Lektion, zu deren
 * Stoff sie gehört; Kapitel- und Lektionstitel wie in FunkVideoCourseSeeder.
 * Lektionen ohne passende Katalogfrage (z. B. „Praktische Prüfung am
 * Funkgerät“) fehlen hier und haben kein Quiz.
 *
 * Bug-Review (Quizfragen in falscher Lektion): SRC-0059 (Kennzeichnung
 * einer DSC-Seefunkstelle: Name, Rufzeichen, MMSI) stand unter „Sprechregeln
 * und Buchstabiertafel“, SRC-0086 (Schiffstagebuch) unter „Rangfolge im
 * Seefunk“, SRC-0006 (BSH-Veröffentlichung „Funkdienst für die Klein- und
 * Sportschifffahrt“) unter „Ablauf der SRC-Prüfung“; UBI-0018…0021
 * (Abhörverbot/Fernmeldegeheimnis) unter „Sprechregeln, Buchstabiertafel
 * und Anruf“, UBI-0120 (SILENCE MAYDAY = Funkstille gebieten) unter
 * „Notverkehr offiziell beenden“. Bestehende Installationen korrigiert
 * die Migration 2024_02_01_000502.
 *
 * Review-Runde 4: SRC-0171/0172 (EPIRB-Wartung) standen unter „Fehlalarm
 * richtig aufheben“, SRC-0113/0114 (Anruf einer Küstenfunkstelle) unter
 * „Funkprobe und Testanruf“ -- korrigiert, für bestehende Installationen per
 * Migration 2024_02_01_000571.
 */
return [
    'SRC' => [
        'Einführung in das SRC' => [
            'Willkommen zum Short Range Certificate' => ['SRC-0001', 'SRC-0024'],
            'GMDSS: das weltweite Seenot- und Sicherheitsfunksystem' => ['SRC-0002', 'SRC-0003', 'SRC-0015'],
            'Seegebiete A1 bis A4' => ['SRC-0012', 'SRC-0013', 'SRC-0014'],
            'Funkzeugnisse: wer braucht welches?' => ['SRC-0025', 'SRC-0026', 'SRC-0027', 'SRC-0036', 'SRC-0035'],
            'Warum das Handy auf See nicht reicht' => ['SRC-0175', 'SRC-0176', 'SRC-0177'],
        ],
        'Technik und Funkgerät' => [
            'UKW-Seefunk: Reichweite und Ausbreitung' => ['SRC-0069', 'SRC-0078', 'SRC-0080', 'SRC-0077', 'SRC-0083', 'SRC-0056'],
            'Kanäle und Frequenzen' => ['SRC-0079', 'SRC-0073', 'SRC-0072', 'SRC-0074', 'SRC-0106', 'SRC-0076'],
            'Kanal 16, 70 und Arbeitskanäle' => ['SRC-0108', 'SRC-0107', 'SRC-0058', 'SRC-0132', 'SRC-0110', 'SRC-0109', 'SRC-0112'],
            'Bedienelemente der UKW-Seefunkanlage' => ['SRC-0051', 'SRC-0104', 'SRC-0034', 'SRC-0045', 'SRC-0046'],
            'Antenne, Stromversorgung und Sendeleistung' => ['SRC-0037', 'SRC-0081', 'SRC-0082', 'SRC-0049', 'SRC-0050', 'SRC-0048', 'SRC-0047'],
            'MMSI und der DSC-Controller' => ['SRC-0053', 'SRC-0054', 'SRC-0055', 'SRC-0061', 'SRC-0064', 'SRC-0067', 'SRC-0062', 'SRC-0065', 'SRC-0066', 'SRC-0059'],
        ],
        'Routineverkehr im Alltag' => [
            'Sprechregeln und Buchstabiertafel' => ['SRC-0115'],
            'Rechtliche Regeln im Seefunk' => ['SRC-0004', 'SRC-0005', 'SRC-0042', 'SRC-0043', 'SRC-0044', 'SRC-0084', 'SRC-0039', 'SRC-0086', 'SRC-0006'],
            'DSC-Routineanruf von Schiff zu Schiff' => ['SRC-0075', 'SRC-0103', 'SRC-0111'],
            'Revier- und Hafenfunk' => ['SRC-0008', 'SRC-0009', 'SRC-0087', 'SRC-0088', 'SRC-0089', 'SRC-0091', 'SRC-0092', 'SRC-0090', 'SRC-0010', 'SRC-0011', 'SRC-0113', 'SRC-0114'],
            'Funkprobe und Testanruf' => ['SRC-0105'],
        ],
        'Notverkehr – MAYDAY' => [
            'Wann ist es ein Notfall? Die MAYDAY-Meldung' => ['SRC-0133', 'SRC-0134', 'SRC-0135', 'SRC-0136', 'SRC-0131', 'SRC-0138', 'SRC-0137'],
            'DSC-Notalarm auslösen' => ['SRC-0068', 'SRC-0052', 'SRC-0140'],
            'Zeitangaben in UTC' => ['SRC-0021', 'SRC-0022'],
            'Wer hilft? MRCC und Küstenfunkstellen' => ['SRC-0007', 'SRC-0126', 'SRC-0127', 'SRC-0147', 'SRC-0148', 'SRC-0128', 'SRC-0130', 'SRC-0129'],
            'Notmeldung empfangen – was jetzt?' => ['SRC-0060', 'SRC-0144', 'SRC-0142', 'SRC-0143'],
            'MAYDAY RELAY: Notmeldung weiterleiten' => ['SRC-0141'],
            'SEELONCE MAYDAY: Funkstille im Notverkehr' => ['SRC-0145', 'SRC-0146'],
            'SAR: Rettung aus der Luft' => ['SRC-0125', 'SRC-0151', 'SRC-0149', 'SRC-0152', 'SRC-0153', 'SRC-0155', 'SRC-0154'],
            'SEELONCE FEENEE: Ende des Notverkehrs' => ['SRC-0178'],
            'Fehlalarm richtig aufheben' => ['SRC-0157'],
            'EPIRB: Notalarm über Satellit' => ['SRC-0180', 'SRC-0161', 'SRC-0160', 'SRC-0162', 'SRC-0163', 'SRC-0164', 'SRC-0169', 'SRC-0165', 'SRC-0166', 'SRC-0167', 'SRC-0168', 'SRC-0170', 'SRC-0171', 'SRC-0172'],
            'SART und AIS-SART: gefunden werden' => ['SRC-0159', 'SRC-0179', 'SRC-0174', 'SRC-0173', 'SRC-0019', 'SRC-0158'],
        ],
        'Dringlichkeit und Sicherheit – PAN PAN und SECURITE' => [
            'PAN PAN: die Dringlichkeitsmeldung' => ['SRC-0094', 'SRC-0095', 'SRC-0093', 'SRC-0098', 'SRC-0057', 'SRC-0097', 'SRC-0099'],
            'Funkärztliche Beratung (MEDICO)' => ['SRC-0023', 'SRC-0096'],
            'Rangfolge im Seefunk' => ['SRC-0085'],
            'SECURITE: Gefahren richtig melden' => ['SRC-0100', 'SRC-0101', 'SRC-0102'],
            'Vorrang: wann du trotz Funkstille senden darfst' => ['SRC-0139', 'SRC-0156'],
            'MSI: nautische Warnnachrichten für Skipper' => ['SRC-0016', 'SRC-0018', 'SRC-0017', 'SRC-0124'],
            'NAVTEX: Warnungen ohne Internet' => ['SRC-0116', 'SRC-0117', 'SRC-0118', 'SRC-0119', 'SRC-0120', 'SRC-0121', 'SRC-0122', 'SRC-0123'],
        ],
        'Zulassung und Prüfungsvorbereitung' => [
            'Zulassung und Anmeldung zur Prüfung' => ['SRC-0070', 'SRC-0071'],
            'Nach der Prüfung: Zuteilungsurkunde und MMSI' => ['SRC-0028', 'SRC-0030', 'SRC-0031', 'SRC-0063', 'SRC-0029', 'SRC-0032', 'SRC-0033', 'SRC-0038', 'SRC-0040', 'SRC-0041'],
            'Englische Seefunktexte und Vokabeln' => ['SRC-0020', 'SRC-0150'],
        ],
    ],
    'UBI' => [
        'Einführung in das UBI' => [
            'Willkommen zum UKW-Sprechfunkzeugnis für den Binnenschifffahrtsfunk' => ['UBI-0009', 'UBI-0011', 'UBI-0008', 'UBI-0010', 'UBI-0027', 'UBI-0030'],
            'Die Binnenschifffahrtsstraßen und das RAINWAT' => ['UBI-0001', 'UBI-0002', 'UBI-0003', 'UBI-0014', 'UBI-0080', 'UBI-0036', 'UBI-0083', 'UBI-0015', 'UBI-0016', 'UBI-0017'],
            'Binnenfunk: wer funkt, was ist erlaubt?' => ['UBI-0025', 'UBI-0026', 'UBI-0049', 'UBI-0028', 'UBI-0034', 'UBI-0031', 'UBI-0029', 'UBI-0033', 'UBI-0035', 'UBI-0037', 'UBI-0038', 'UBI-0018', 'UBI-0019', 'UBI-0020', 'UBI-0021'],
        ],
        'Technik: Kanäle und Funkanlage' => [
            'Kanäle im Binnenschifffahrtsfunk' => ['UBI-0022', 'UBI-0052', 'UBI-0023', 'UBI-0024', 'UBI-0091', 'UBI-0089', 'UBI-0090', 'UBI-0092', 'UBI-0084', 'UBI-0085', 'UBI-0086', 'UBI-0087'],
            'Die Funkanlage: ATIS, Sendeleistung und Bedienung' => ['UBI-0039', 'UBI-0040', 'UBI-0041', 'UBI-0042', 'UBI-0043', 'UBI-0044', 'UBI-0047', 'UBI-0048', 'UBI-0054', 'UBI-0050', 'UBI-0051', 'UBI-0053'],
        ],
        'Verkehrskreise und Reviere im Alltag' => [
            'Die Verkehrskreise im Binnenfunk' => ['UBI-0055', 'UBI-0056', 'UBI-0058', 'UBI-0059', 'UBI-0060', 'UBI-0063', 'UBI-0064', 'UBI-0068', 'UBI-0069', 'UBI-0073', 'UBI-0074', 'UBI-0075'],
            'Funkstellen: wer gehört in welchen Verkehrskreis?' => ['UBI-0005', 'UBI-0006', 'UBI-0071', 'UBI-0061', 'UBI-0066', 'UBI-0067', 'UBI-0070', 'UBI-0072', 'UBI-0062', 'UBI-0077', 'UBI-0076', 'UBI-0078'],
            'Nautische Information im Revieralltag' => ['UBI-0007', 'UBI-0057', 'UBI-0079', 'UBI-0088', 'UBI-0065', 'UBI-0095', 'UBI-0099', 'UBI-0106', 'UBI-0107', 'UBI-0108', 'UBI-0045', 'UBI-0046'],
            'Rangfolge der Meldungen' => ['UBI-0110', 'UBI-0130'],
        ],
        'Notverkehr im Binnenfunk – MAYDAY' => [
            'Sprechregeln, Buchstabiertafel und Anruf' => ['UBI-0082', 'UBI-0096', 'UBI-0097', 'UBI-0098', 'UBI-0004', 'UBI-0104', 'UBI-0105'],
            'Notfall auf dem Binnenrevier: die Notmeldung (MAYDAY)' => ['UBI-0112', 'UBI-0111', 'UBI-0113', 'UBI-0114', 'UBI-0115', 'UBI-0120'],
            'Notmeldungen richtig bestätigen' => ['UBI-0116', 'UBI-0117'],
            'MAYDAY RELAY: die Rettungskette weitergeben' => ['UBI-0118', 'UBI-0109'],
            'Notverkehr offiziell beenden' => ['UBI-0119'],
        ],
        'Dringlichkeit und Sicherheit im Binnenfunk' => [
            'PAN PAN: Dringlichkeitsverkehr sicher abwickeln' => ['UBI-0121', 'UBI-0122', 'UBI-0123', 'UBI-0124', 'UBI-0125'],
            'SECURITE: Gefahren im Funk richtig melden' => ['UBI-0126', 'UBI-0127', 'UBI-0128', 'UBI-0129'],
        ],
        'UBI-Abschluss und Prüfungsvorbereitung' => [
            'Zusammenfassung: alles Wichtige für die Prüfung' => ['UBI-0012', 'UBI-0013', 'UBI-0032', 'UBI-0100', 'UBI-0101', 'UBI-0102'],
            'Funkpraxis: Textaufnahme, Textabgabe und Funkgespräche' => ['UBI-0081', 'UBI-0103', 'UBI-0093', 'UBI-0094'],
        ],
    ],
];
