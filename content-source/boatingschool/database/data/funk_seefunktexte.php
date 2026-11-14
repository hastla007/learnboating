<?php

/**
 * Seefunktexte für die mündliche SRC-Prüfung: der Prüfer liest einen Text vor,
 * der Prüfling schreibt mit und übersetzt (Deutsch -> Englisch bzw.
 * Englisch -> Deutsch).
 *
 * Eigene Übungstexte im Stil des amtlichen "Katalog der Seefunktexte"
 * (Anlage 7, Fachstelle der WSV, 27 Texte): dieselben Meldungsarten (Seenot,
 * Dringlichkeit, Sicherheit, Weiterleitung) und Formulierungen, aber andere
 * Fahrzeuge, Positionen und Lagen; Fahrzeugnamen und Rufzeichen sind erfunden.
 * Den amtlichen Katalog erlaubt die WSV nur zur privaten Verwendung -- nicht
 * als Teil einer Schulungsunterlage. Liegt eine Erlaubnis vor, ersetzen die
 * amtlichen Texte diese hier; dann den Fortschrittsschlüssel in
 * App\Support\FunkTraining::texts() ändern, sonst gilt "erledigt" eines
 * Übungstexts für den amtlichen Text mit derselben Nummer.
 *
 * Format je Text: ['richtung' => 'land' (Land -> See) | 'see' (See -> Land,
 * Schiff -> Schiff), 'de' => 'Deutscher Text', 'en' => 'English text'].
 * Die Nummer ist die Position in der Liste (Fortschritt "TXT-SRC-01" …) --
 * neue Texte nur hinten anhängen.
 */
return [
    'SRC' => [
        [
            'richtung' => 'land',
            'de' => 'Nautische Warnung: Die Leuchttonne Elbe 3 ist vertrieben und treibt auf Position 53-59N 008-14E. Die Schifffahrt wird gebeten, in diesem Gebiet vorsichtig zu navigieren.',
            'en' => 'Navigational warning: light buoy Elbe 3 is off station and adrift in position 53-59N 008-14E. Shipping is requested to navigate with caution in this area.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Motoryacht Seeadler/DH4718 auf Position 54-26N 010-48E, Feuer im Maschinenraum, drei Personen an Bord, benötigen sofortige Hilfe.',
            'en' => 'Motor yacht Seeadler/DH4718 in position 54-26N 010-48E, fire in engine room, three persons on board, require immediate assistance.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Nordlicht/DFRK auf Position 55-30N 007-38E, Wassereinbruch nach Grundberührung, Schiff hat Schlagseite nach Steuerbord, benötigen sofortige Hilfe.',
            'en' => 'M/V Nordlicht/DFRK in position 55-30N 007-38E, flooding after grounding, vessel has a list to starboard, require immediate assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Sturmwarnung für die Deutsche Bucht: Südwest 7 bis 8, zunehmend 9, später rückdrehend Süd, raue See, Regen, schlechte Sicht.',
            'en' => 'Gale warning for German Bight: southwest force 7 to 8, increasing to force 9, backing south later, rough sea, rain, poor visibility.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Segelyacht Möwe/DK7731 meldet Mensch über Bord auf Position 54-31N 013-42E um 1415 UTC. Alle Schiffe in der Nähe werden gebeten, scharf Ausschau zu halten und bei der Suche zu helfen.',
            'en' => 'Sailing yacht Möwe/DK7731 reports person overboard in position 54-31N 013-42E at 1415 UTC. All ships in vicinity are requested to keep sharp lookout and to assist in search.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Orangefarbenes Rauchsignal von Position 54-50N 009-58E in rechtweisender Peilung 120 Grad beobachtet, Schiffe in der Nähe bitte scharf Ausschau halten und an MRCC Bremen berichten.',
            'en' => 'Orange smoke signal observed from position 54-50N 009-58E in true bearing 120 degrees, ships in vicinity please keep sharp lookout and report to MRCC Bremen.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Segelyacht Albatros/DJ5520 auf Position 12 sm westlich Helgoland, ein Besatzungsmitglied hat eine schwere Kopfverletzung, benötigen dringend ärztliche Beratung.',
            'en' => 'Sailing yacht Albatros/DJ5520 in position 12 nm west of Helgoland, a crew member has a severe head injury, require urgent medical advice.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Weserstrom/DGTA auf Position 53-48N 007-12E, Maschinenausfall, Schiff treibt manövrierunfähig auf das Verkehrstrennungsgebiet zu, benötigen Schlepperhilfe.',
            'en' => 'M/V Weserstrom/DGTA in position 53-48N 007-12E, engine failure, vessel is not under command and drifting towards the traffic separation scheme, require tug assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Um 1022 UTC folgendes auf UKW-Kanal 16 empfangen: „MAYDAY Segelyacht Kormoran/DD9104 auf Position 54-09N 011-36E, Kollision mit Treibgut, Yacht sinkt, vier Personen gehen in die Rettungsinsel“.',
            'en' => 'Following received at 1022 UTC on VHF channel 16: „MAYDAY sailing yacht Kormoran/DD9104 in position 54-09N 011-36E, in collision with floating object, yacht is sinking, four persons are taking to the life raft“.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Auf Position 54-58N 006-40E treiben mehrere blaue 20-Fuß-Container. Schiffe in diesem Gebiet werden gebeten, vorsichtig zu navigieren und Sichtungen an Bremen Rescue zu melden.',
            'en' => 'In position 54-58N 006-40E several blue 20-foot containers are adrift. Ships in this area are requested to navigate with caution and to report sightings to Bremen Rescue.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Fischkutter Hering/DIQZ auf Position 55-40N 004-55E, Feuer an Bord nicht unter Kontrolle, wir verlassen das Schiff, fünf Personen in der Rettungsinsel, benötigen sofortige Hilfe.',
            'en' => 'Fishing vessel Hering/DIQZ in position 55-40N 004-55E, fire on board not under control, we are abandoning the vessel, five persons in the life raft, require immediate assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Wettervorhersage für die westliche Ostsee: Nordwest 5 bis 6, abnehmend 4, später rückdrehend West, Seegang 2 Meter, Schauer, gute Sicht.',
            'en' => 'Weather forecast for the western Baltic: northwest force 5 to 6, decreasing to force 4, backing west later, sea 2 metres, showers, good visibility.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Rügenland/DLBK auf Position 54-37N 013-30E, Kollision mit einer Segelyacht, die Yacht hat schweren Schaden am Bug, zwei Personen leicht verletzt, benötigen Hilfe.',
            'en' => 'M/V Rügenland/DLBK in position 54-37N 013-30E, in collision with a sailing yacht, the yacht has heavy damage to the bow, two persons slightly injured, require assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Motorboot Seestern/DC3348, Länge 9 m, weißer Rumpf, blaue Persenning, eine Person an Bord, verließ Grömitz am 4. August um 0900 Ortszeit mit Bestimmungshafen Burgtiefe und ist dort bisher nicht eingetroffen. Die Schifffahrt wird gebeten, scharf Ausschau zu halten und an MRCC Bremen zu berichten.',
            'en' => 'Motor boat Seestern/DC3348, length 9 m, white hull, blue canopy, one person on board, left Grömitz on August 4th at 0900 local time bound for Burgtiefe and has not yet arrived there. Shipping is requested to keep sharp lookout and to report to MRCC Bremen.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Lotsenhorn/DKLZ auf Position 56-15N 006-02E, ein Besatzungsmitglied, 34 Jahre alt, hat schwere Verbrennungen an beiden Armen, benötigen dringend medizinische Hilfe per Hubschrauber.',
            'en' => 'M/V Lotsenhorn/DKLZ in position 56-15N 006-02E, a crew member, 34 years old, has severe burns on both arms, require urgent medical assistance by helicopter.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Das Molenfeuer von Warnemünde ist als verlöscht gemeldet. Die Schifffahrt wird gebeten, bei der Ansteuerung vorsichtig zu navigieren.',
            'en' => 'The pier head light of Warnemünde is reported unlit. Shipping is requested to navigate with caution when approaching.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Segelyacht Wanderer/DF6647 auf Position 54-03N 008-20E, Ruderblatt verloren, Schiff ist manövrierunfähig und treibt in rauer See auf die Küste zu, benötigen dringend Schlepperhilfe.',
            'en' => 'Sailing yacht Wanderer/DF6647 in position 54-03N 008-20E, rudder blade lost, vessel is not under command and drifting in rough sea towards the coast, require urgent tug assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Schießübungen im Schießgebiet Todendorf vom 12. bis 14. Juni, täglich von 0800 bis 1600 Ortszeit. Die Schifffahrt wird gebeten, das Schießgebiet zu meiden.',
            'en' => 'Firing exercises in firing area Todendorf from June 12th to 14th, daily from 0800 to 1600 local time. Shipping is requested to avoid the firing area.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Motoryacht Delfin/DB1209 auf Position 3 sm nördlich Kap Arkona, der Skipper ist bewusstlos, Verdacht auf Schlaganfall, niemand an Bord kann das Boot führen, benötigen sofortige Hilfe.',
            'en' => 'Motor yacht Delfin/DB1209 in position 3 nm north of Cape Arkona, the skipper is unconscious, suspected stroke, nobody on board is able to handle the boat, require immediate assistance.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Auf Position 54-17N 007-05E liegt ein gesunkenes Fischereifahrzeug, Wassertiefe über dem Wrack 20 Meter, das Wrack ist durch eine Wracktonne gekennzeichnet. Die Schifffahrt wird gebeten, mindestens 1 sm Abstand zu halten.',
            'en' => 'In position 54-17N 007-05E there is a sunken fishing vessel, depth of water over the wreck 20 metres, the wreck is marked by a wreck buoy. Shipping is requested to keep a distance of at least 1 nm.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Segelyacht Nordstern/DE2285 auf Position 57-24N 008-12E, Mast gebrochen, ein Besatzungsmitglied hat ein gebrochenes Bein, Maschine läuft, laufen mit 5 Knoten nach Hanstholm, erbitten Begleitung.',
            'en' => 'Sailing yacht Nordstern/DE2285 in position 57-24N 008-12E, mast broken, a crew member has a broken leg, engine running, proceeding at 5 knots to Hanstholm, request escort.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Nebelwarnung für Fehmarnbelt und Kieler Bucht: Sicht unter 500 Meter, später zeitweise unter 100 Meter, Wind Ost 2 bis 3.',
            'en' => 'Fog warning for Fehmarn Belt and Kiel Bay: visibility less than 500 metres, later at times less than 100 metres, wind east force 2 to 3.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Baltic Trader/DBTX hat auf Position 55-33N 003-50E bei schwerem Seegang vier Container verloren. Schiffe in dem Gebiet werden gebeten, vorsichtig zu navigieren.',
            'en' => 'M/V Baltic Trader/DBTX has lost four containers in heavy seas in position 55-33N 003-50E. Ships in this area are requested to navigate with caution.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Baggerarbeiten im Fahrwasser der Jade zwischen Tonne 33 und Tonne 37 bis zum 30. September. Das Baggerschiff Jadeland hört UKW-Kanal 20. Die Schifffahrt wird gebeten, mit geringer Geschwindigkeit zu passieren.',
            'en' => 'Dredging operations in the Jade fairway between buoy 33 and buoy 37 until September 30th. Dredger Jadeland is listening on VHF channel 20. Shipping is requested to pass at slow speed.',
        ],
        [
            'richtung' => 'see',
            'de' => 'M/S Lühe/DHBM auf Position 54-12N 007-48E hat eine leere Rettungsinsel gesichtet, keine Personen zu sehen, wir bleiben vor Ort und erbitten Anweisungen.',
            'en' => 'M/V Lühe/DHBM in position 54-12N 007-48E has sighted an empty life raft, no persons to be seen, we are standing by and request instructions.',
        ],
        [
            'richtung' => 'land',
            'de' => 'Nautische Warnung. Kieler Förde: Das Feuer der Ansteuerungstonne ist ausgefallen, die Tonne ist bis auf Weiteres unbefeuert. Die Schifffahrt wird gebeten, vorsichtig zu navigieren.',
            'en' => 'Navigational warning. Kiel Fjord: the light of the landfall buoy has failed, the buoy is unlit until further notice. Shipping is requested to navigate with caution.',
        ],
        [
            'richtung' => 'see',
            'de' => 'Motoryacht Lummenfelsen/DG4472 auf Position 53-42N 007-58E, bei Niedrigwasser auf Grund gelaufen, keine Gefahr für die Besatzung, erbitten Schlepperhilfe beim nächsten Hochwasser um 1830 UTC.',
            'en' => 'Motor yacht Lummenfelsen/DG4472 in position 53-42N 007-58E, aground at low water, no danger to the crew, request tug assistance at next high water at 1830 UTC.',
        ],
    ],
];
