<?php

/**
 * Bug-Review N26: dieser Katalog deckt NUR das Kapitel "Pyrotechnische
 * Not- und Seenotsignalmittel / Waffenrecht" des FKN-Fragenkatalogs ab
 * (62 Fragen) -- nicht die eigentlichen Funkverkehr-Inhalte (Sprechfunk-
 * verfahren, Seenot-/Dringlichkeits-/Sicherheitsmeldungen, Buchstabier-
 * alphabet etc.), die dem Funkbetriebszeugnis für den Nahbereich seinen
 * Namen geben. Für diese fehlen aktuell keine eigenen Datendateien unter
 * database/data/ -- wer dieses Kapitel um den eigentlichen Funkverkehr
 * ergänzen will, muss die amtlichen Funkverkehr-Fragen zusätzlich
 * importieren.
 *
 * Amtlicher FKN-Fragenkatalog (Funkbetriebszeugnis für den Nahbereich,
 * Stand 01/2020, "internen FKN-Katalog des PANord"), Kapitel
 * "Pyrotechnische Not- und Seenotsignalmittel / Waffenrecht". Fragen ohne
 * Antwortoptionen (nur Musterantwort im Original) werden als 'open_text'
 * importiert; Fragen mit Buchstaben-Optionen (teils mehrere korrekt) als
 * 'multi_choice'. Die offiziellen Prüfungsbögen mischen pro Bogen-Position
 * teils dieselbe Frage als offene Frage, teils als Multiple-Choice (siehe
 * "Fragenzusammenstellungen in den Fragebögen 1-3"); diese Platform bildet
 * jede Frage konsistent in ihrer im Katalog dokumentierten reichhaltigsten
 * Form ab (mit Optionen, wo vorhanden), statt die Darstellung je nach
 * Bogen-Position zu wechseln.
 */
return [
    1 => ['type' => 'open_text', 'q' => 'Was versteht man unter pyrotechnischen Seenotsignalen?', 'a' => 'Notsignale, die mit Hilfe explosionsgefährlicher Stoffe ausgelöst werden.'],
    2 => ['type' => 'open_text', 'q' => 'Was sind explosionsgefährliche Stoffe?', 'a' => 'Feste oder flüssige Stoffe und Zubereitungen, die durch eine nicht außergewöhnliche Beanspruchung (thermisch, mechanisch oder andere) zur Explosion gebracht werden können.'],
    3 => ['type' => 'multi_choice', 'q' => 'Was darf zur pyrotechnischen Notsignalgebung verwendet werden?', 'options' => [
        ['t' => 'Die Signalpistole Kaliber 4 (26,5 mm)', 'c' => true],
        ['t' => 'Signalwaffen mit Zulassung der Physikalisch-Technischen Bundesanstalt (PTB)', 'c' => true],
        ['t' => 'die von der Bundesanstalt für Materialforschung und -prüfung (BAM) zugelassenen sonstigen Notsignale.', 'c' => true],
    ]],
    4 => ['type' => 'multi_choice', 'q' => 'Welche Arten von Zündern werden bei Not-Handfackeln gewöhnlich verwendet und wie funktionieren sie?', 'options' => [
        ['t' => 'Reibkopf-Zündung - funktioniert wie ein Streichholz, zündet mit einer Verzögerung direkt den Leuchtsatz (nicht mehr im deutschen Handel)', 'c' => true],
        ['t' => 'Luntenzünder - durch das Entzünden einer Lunte wird nach gewisser Zeit der eigentliche Signalsatz gezündet.', 'c' => false],
        ['t' => 'Reißzünder - ein Draht im Inneren wird durch einen reibempfindlichen pyrotechnischen Anzündsatz gezogen, der dann den eigentlichen Signalsatz zündet.', 'c' => true],
    ]],
    5 => ['type' => 'open_text', 'q' => 'Wann dürfen pyrotechnische Notsignale verwendet werden?', 'a' => 'Nur im Notfall, d.h. unter anderem, wenn angezeigt werden soll, dass Gefahr für Leib und Leben besteht und Hilfe erforderlich ist.'],
    6 => ['type' => 'multi_choice', 'q' => 'Was ist sicherheitstechnisch bei der Verwendung einer Seenot-Handfackel zu beachten?', 'options' => [
        ['t' => 'Die brennende Fackel nach Lee waagerecht so halten, dass versprühter Abbrand keine Verletzungen (Hand, Augen) verursacht oder das Boot/Schiff beschädigt.', 'c' => true],
        ['t' => 'Die brennende Fackel nach Luv waagerecht so halten, dass versprühter Abbrand keine Verletzungen (Hand, Augen) verursacht oder das Boot/Schiff beschädigt.', 'c' => false],
        ['t' => 'Gebrauchsanweisung beachten.', 'c' => true],
    ]],
    7 => ['type' => 'multi_choice', 'q' => 'Was ist sicherheitstechnisch bei der Verwendung eines Seenot-Rauchsignals zu beachten?', 'options' => [
        ['t' => 'Rauchsignale nur am Tage und bei geringen Windstärken verwenden.', 'c' => true],
        ['t' => 'Die Anzündung erfolgt durch eine Reißschnur, die unter einer abschraubbaren Schutzkappe liegt.', 'c' => true],
        ['t' => 'Nach der Zündung ist das Rauchsignal zur Leeseite außenbords zu werfen.', 'c' => true],
    ]],
    8 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Seenot-Signalmittel werden an Bord verwendet?', 'options' => [
        ['t' => 'Signalraketen und Fallschirmraketen.', 'c' => true],
        ['t' => 'Rauchtöpfe und Bengalische Feuer.', 'c' => false],
        ['t' => 'Handfackeln und Rauchsignale.', 'c' => true],
    ]],
    9 => ['type' => 'open_text', 'q' => 'Wann sind Rauchsignale zu verwenden?', 'a' => 'Nur am Tag und erst wenn Hilfe gesichtet worden ist.'],
    10 => ['type' => 'multi_choice', 'q' => 'Welche Farbe ist bei Signalraketen international als Notsignal zu verwenden?', 'options' => [
        ['t' => 'Rot.', 'c' => true],
        ['t' => 'Weiß.', 'c' => false],
        ['t' => 'Grün.', 'c' => false],
    ]],
    11 => ['type' => 'multi_choice', 'q' => 'Wann dürfen Notsignale verwendet werden?', 'options' => [
        ['t' => 'In Notfällen, wenn unter anderem Leib und Leben von Personen in Gefahr sind und dringend fremde Hilfe benötigt wird.', 'c' => true],
        ['t' => 'In Notfällen, wenn bedeutende Sachwerte in Gefahr sind und dringend fremde Hilfe benötigt wird.', 'c' => true],
        ['t' => 'Ausschließlich wenn alle anderen Kommunikationsmittel ausgefallen sind.', 'c' => false],
    ]],
    12 => ['type' => 'multi_choice', 'q' => 'Wie lang ist die Verbrauchsdauer pyrotechnischer Notsignale bei sachgemäßer Lagerung?', 'options' => [
        ['t' => 'Die Verbrauchsdauer ist unbegrenzt.', 'c' => false],
        ['t' => 'Soweit auf dem einzelnen Gegenstand nichts anderes vermerkt ist, max. 3 Jahre', 'c' => true],
        ['t' => 'Maximal 10 Jahre.', 'c' => false],
    ]],
    13 => ['type' => 'multi_choice', 'q' => 'Was verkürzt die durch den Hersteller vorgegebene Verbrauchsdauer pyrotechnischer Notsignale oder beeinträchtigt ihre sichere Verwendung?', 'options' => [
        ['t' => 'Feuchtigkeit und Korrosion', 'c' => true],
        ['t' => 'hohe Lagertemperaturen', 'c' => true],
        ['t' => 'mechanische Beschädigung', 'c' => true],
    ]],
    14 => ['type' => 'multi_choice', 'q' => 'Was machen Sie mit überlagerten pyrotechnischen Notsignalen?', 'options' => [
        ['t' => 'Können über den Hausmüll entsorgt werden', 'c' => false],
        ['t' => 'Über den Munitionshandel zurückgeben oder Delaborierbetrieben übergeben.', 'c' => true],
        ['t' => 'Können auch als Feuerwerkskörper verwendet werden', 'c' => false],
    ]],
    15 => ['type' => 'multi_choice', 'q' => 'Wie ist pyrotechnische Munition während der Liegezeit im Hafen zu lagern?', 'options' => [
        ['t' => 'Keine bestimmte Lagerung erforderlich', 'c' => false],
        ['t' => 'Möglichst originalverpackt, kühl und trocken in einem Metallbehältnis mit Schwenkriegelschloss.', 'c' => true],
        ['t' => 'Die Munition darf keinesfalls an Bord gelagert werden.', 'c' => false],
    ]],
    16 => ['type' => 'open_text', 'q' => 'Warum sollte pyrotechnische Munition möglichst original verpackt gelagert werden?', 'a' => 'Diese Verpackungen sind in der Regel wasserdicht und schwimmfähig.'],
    17 => ['type' => 'multi_choice', 'q' => 'Wozu berechtigt eine Waffenbesitzkarte (ausgestellt für das Bedürfnis als Inhaber eines seegehenden Schiffes)?', 'options' => [
        ['t' => 'Mit entsprechendem Voreintrag zum Erwerb, sowie zum Besitz einer Signalpistole', 'c' => true],
        ['t' => 'Zum Führen der Waffe an Land.', 'c' => false],
        ['t' => 'Zum Erwerb der zur Signalpistole gehörigen Munition bei entsprechendem Eintrag.', 'c' => true],
    ]],
    18 => ['type' => 'open_text', 'q' => 'Wo ist eine Waffenbesitzkarte für Inhaber von seegehenden Schiffen zu beantragen?', 'a' => 'Bei der zuständigen Behörde des Wohnortes (nicht des Liegeplatzes).'],
    19 => ['type' => 'multi_choice', 'q' => 'Welche Signalwaffen darf der Inhaber eines „Kleinen Waffenscheins" führen?', 'options' => [
        ['t' => 'Alle', 'c' => false],
        ['t' => 'Nur amtlich beschossene Signalwaffen im Kaliber unter 12 mm.', 'c' => false],
        ['t' => 'Nur Signalwaffen mit dem Zulassungszeichen „PTB im Kreis".', 'c' => true],
    ]],
    20 => ['type' => 'open_text', 'q' => 'Welche Signalwaffen können frei erworben und an Bord mitgeführt werden?', 'a' => 'Signalwaffen (SRS-Waffen) mit dem Bauartzulassungszeichen der Physikalisch-Technischen Bundesanstalt („PTB im Kreis").'],
    21 => ['type' => 'multi_choice', 'q' => 'Wie kann ein Wassersportler nachweisen, dass ein Bedürfnis für den Erwerb einer Signalpistole vorliegt?', 'options' => [
        ['t' => 'Durch Vorlage von Unterlagen, aus denen der Besitz eines seegängigen Wasserfahrzeugs (Kaufvertrag, Chartervertrag, Versicherungspolice, Standerschein, Internationaler Bootsschein usw.)', 'c' => true],
        ['t' => 'Durch Vorlage eines Sportbootführerscheins.', 'c' => false],
        ['t' => 'Durch Vorlage von Unterlagen, aus denen die Notwendigkeit für Lehr- und Prüfungszwecke hervorgeht.', 'c' => true],
    ]],
    22 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Seenotsignale können erlaubnisfrei erworben, aufbewahrt und verwendet werden von Personen, die das 18. Lebensjahr vollendet haben?', 'options' => [
        ['t' => 'Die der Unterklasse P1, d.h. „Handfackeln rot" und bestimmte Rauchsignale, Abschussgeräte ohne Schusswaffeneigenschaft.', 'c' => true],
        ['t' => 'Seenotsignalgeber mit einer Steighöhe von bis etwa 60 Metern (Signalgeber und ihre Munition, die nicht dem WaffG unterliegen)', 'c' => true],
        ['t' => 'Die der Unterklasse P2, d.h. „Signalraketen rot", „Fallschirmsignalraketen rot" und bestimmte Rauchsignale.', 'c' => false],
    ]],
    23 => ['type' => 'open_text', 'q' => 'Welche erlaubnispflichtigen pyrotechnischen Seenotsignale dürfen Wassersportler mit einem im Führerschein eingedruckten Befreiungsvermerk bzw. Sachkundenachweis erwerben?', 'a' => 'Die der Unterklasse P2, d.h. „Signalraketen rot", „Fallschirmsignalraketen rot" und bestimmte Rauchsignale.'],
    24 => ['type' => 'multi_choice', 'q' => 'Darf mit einem Bootsführerschein mit eingetragenem Befreiungsvermerk nach dem Waffen- und Sprengstoffgesetz Seenotsignalmunition im Kaliber 4 erworben werden?', 'options' => [
        ['t' => 'Nein, hierfür ist eine Waffenbesitzkarte mit eingetragener Munitionserwerbsberechtigung für das Kaliber 4 erforderlich.', 'c' => true],
        ['t' => 'Nein, hierfür ist eine Waffenbesitzkarte mit einer eingetragenen Signalwaffe im Kaliber 4 erforderlich.', 'c' => false],
        ['t' => 'Ja, die Waffenbesitzkarte wird nur für den Waffenerwerb benötigt und dient als Legitimation für den Waffenbesitz.', 'c' => false],
    ]],
    25 => ['type' => 'multi_choice', 'q' => 'Welches ist das Zulassungszeichen für pyrotechnische Munition nach § 10 BeschG?', 'options' => [
        ['t' => 'PTB im Kreis (Physikalisch-Technische Bundesanstalt)', 'c' => false],
        ['t' => 'BAM-Zeichen (Bundesanstalt für Materialforschung und –prüfung)', 'c' => true],
        ['t' => 'Bundesadler, B (Böllerbeschuss)', 'c' => false],
    ]],
    26 => ['type' => 'multi_choice', 'q' => 'Neben dem Zulassungszeichen (BAM) folgt zusätzlich eine Klasseneinteilung. Welche Klassen gibt es?', 'options' => [
        ['t' => 'P 1 (Bsp.: Handfackeln)', 'c' => true],
        ['t' => 'SM 1 (Signalmunition der Klasse 1)', 'c' => false],
        ['t' => 'P 2 (Bsp.: Signalraketen bzw. Fallschirmsignalraketen mit einer Steighöhe von bis zu 300 Metern)', 'c' => true],
    ]],
    27 => ['type' => 'open_text', 'q' => 'Nennen Sie sechs pyrotechnische Notsignale!', 'a' => "1. Signalraketen, rot\n2. Fallschirmsignalraketen, rot\n3. Handfackeln, rot\n4. Rauchsignale, orange\n5. Lichtrauchsignale\n6. Blitz-Knall-Patronen"],
    28 => ['type' => 'multi_choice', 'q' => 'Welche Farben haben pyrotechnische Notsignale?', 'options' => [
        ['t' => 'Leuchtsignale rot.', 'c' => true],
        ['t' => 'Rauchsignale orange', 'c' => true],
        ['t' => 'Leuchtsignal gelb', 'c' => false],
    ]],
    29 => ['type' => 'open_text', 'q' => 'Was ist bei allen steigenden Seenotsignalen unbedingt zu beachten?', 'a' => "1. Auf freies Schussfeld achten (z.B. Mast und Segel),\n2. Signalgerät senkrecht (ggf. in den Wind geneigt) nach oben halten,\n3. beim Handhaben und Abfeuern nicht auf Personen richten und selbst nicht mit Körperteilen oder Kleidung vor die Mündung kommen,\n4. nicht an Versagern hantieren, sondern diese über Bord werfen."],
    30 => ['type' => 'multi_choice', 'q' => 'Was ist bei steigenden Notsignalen zu beachten?', 'options' => [
        ['t' => 'freies Schussfeld', 'c' => true],
        ['t' => 'Windrichtung und Abschusswinkel', 'c' => true],
        ['t' => 'keine entflammbaren Gegenstände im Gefahrenbereich', 'c' => true],
    ]],
    31 => ['type' => 'open_text', 'q' => 'Welche Vorteile haben Signalraketen bzw. Signalpatronen, die mit Fallschirmen ausgerüstet sind, gegenüber Signalsternen?', 'a' => 'Auf Grund geringerer Sinkgeschwindigkeit (5 m/s) ist eine längere Sichtbarkeit / Brenndauer möglich; dadurch haben sie einen höheren Aufmerksamkeitswert.'],
    32 => ['type' => 'multi_choice', 'q' => 'Woraufhin sind pyrotechnische Seenotsignale ständig zu überwachen, damit die Funktionsfähigkeit gewährleistet ist?', 'options' => [
        ['t' => 'Verbrauchsdauer / Verfallsdatum beachten.', 'c' => true],
        ['t' => 'Auf Korrosion oder Beschädigung prüfen', 'c' => true],
    ]],
    33 => ['type' => 'open_text', 'q' => 'Woran erkennen Sie an einem pyrotechnischen Notsignal, um welche Unterklasse es sich handelt?', 'a' => 'Am Zulassungszeichen: BAM-P 1 oder BAM-P 2.'],
    34 => ['type' => 'multi_choice', 'q' => 'Wer darf pyrotechnische Notsignale der Klasse P verwenden?', 'options' => [
        ['t' => 'Jeder, der damit anzeigen will, dass ein Seenotfall vorliegt, d. h. unter anderem, dass Gefahr für Leib oder Leben der Besatzung und daher die Notwendigkeit zur Hilfe besteht.', 'c' => true],
        ['t' => 'An Silvester dürfen die Signale uneingeschränkt verwendet werden.', 'c' => false],
        ['t' => 'Jeder, der damit anzeigen will, dass ein Seenotfall vorliegt, obwohl keine Notwendigkeit zur Hilfe mehr besteht.', 'c' => false],
    ]],
    35 => ['type' => 'multi_choice', 'q' => 'Wie lang ist die Brenndauer einer Seenot-Handfackel?', 'options' => [
        ['t' => '5-10 Sekunden', 'c' => false],
        ['t' => '5 Minuten', 'c' => false],
        ['t' => '30 bis 60 Sekunden', 'c' => true],
    ]],
    36 => ['type' => 'open_text', 'q' => 'Beschreiben Sie den allgemeinen Aufbau eines Seenot-Rauchsignals!', 'a' => 'In einem Behälter befindet sich ein Anzünder (meist Reißzünder) mit Verzögerung, der einen pyrotechnischen Satz anzündet, der dann bis zu 4 Minuten lang orangefarbenen Rauch abgibt.'],
    37 => ['type' => 'multi_choice', 'q' => 'Fallschirmsignalraketen und Handfackeln sind bei klarem Wetter unterschiedlich weit zu sehen. Welche Aussage/n ist/sind richtig?', 'options' => [
        ['t' => 'Fallschirmsignalraketen werden verwendet, um weit entfernte Helfer auf eine Notlage aufmerksam zu machen und grob in die Richtung einzuweisen.', 'c' => true],
        ['t' => 'Handfackeln werden verwendet, um die genaue Position bei Annäherung kenntlich zu machen.', 'c' => true],
        ['t' => 'Handfackeln sind ausschließlich Nachts zu verwenden.', 'c' => false],
    ]],
    38 => ['type' => 'multi_choice', 'q' => 'Wie hoch steigen Seenotsignalraketen bei senkrechtem Abschusswinkel?', 'options' => [
        ['t' => 'bis zu 50 Metern', 'c' => false],
        ['t' => 'bis zu 300 Metern', 'c' => true],
        ['t' => 'bis zu 200 Metern', 'c' => false],
    ]],
    39 => ['type' => 'multi_choice', 'q' => 'Brennen pyrotechnische Geschosse weiter, wenn sie ins Wasser fallen?', 'options' => [
        ['t' => 'Nein', 'c' => false],
        ['t' => 'Nur, wenn es sich um militärische Munition handelt.', 'c' => false],
        ['t' => 'Ja', 'c' => true],
    ]],
    40 => ['type' => 'multi_choice', 'q' => 'Dürfen Sie pyrotechnische Gegenstände selbst herstellen und bearbeiten?', 'options' => [
        ['t' => 'Personen mit bestandener Sachkundeprüfung dürfen pyrotechnische Gegenstände bearbeiten', 'c' => false],
        ['t' => 'Nein, nur als Inhaber einer entsprechenden Erlaubnis nach dem Sprengstoffgesetz.', 'c' => true],
        ['t' => 'Jeder darf herstellen und bearbeiten.', 'c' => false],
    ]],
    41 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Notsignale unterliegen dem Waffengesetz?', 'options' => [
        ['t' => 'Die Signalpistole (Kaliber 4) und die hierfür bestimmte Munition (nur mit WBK).', 'c' => true],
        ['t' => 'Seenotsignalgeber mit einer Steighöhe von bis etwa 60 Metern (frei erwerblich ab 18 Jahre)', 'c' => false],
        ['t' => 'Handfackeln (frei erwerblich ab 18 Jahre)', 'c' => false],
    ]],
    42 => ['type' => 'multi_choice', 'q' => 'Was kann die missbräuchliche Verwendung von Notsignalmitteln für Folgen haben?', 'options' => [
        ['t' => 'Die missbräuchliche Verwendung stellt in Deutschland nur eine Ordnungswidrigkeit dar.', 'c' => false],
        ['t' => 'Die missbräuchliche Verwendung stellt in Deutschland ein Vergehen dar (Missbrauch von Notzeichen gemäß § 145 StGB und/oder Verstoß gemäß Waffengesetz).', 'c' => true],
        ['t' => 'Der Missbrauch kann auch weltweit enorme Schadenersatzforderungen nach sich ziehen.', 'c' => true],
    ]],
    43 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Seenotsignale unterliegen dem Sprengstoffgesetz?', 'options' => [
        ['t' => 'Alle pyrotechnischen Seenotsignale, die nicht aus einer Signalpistole abgefeuert werden, wie Signalraketen, Handsignalraketen mit Fallschirm, Handfackeln und Rauchsignale.', 'c' => true],
        ['t' => 'Nur die Signale, in denen explosive Stoffe verwendet wurden', 'c' => false],
        ['t' => 'Signalmunition im Kaliber 4', 'c' => false],
    ]],
    44 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Gegenstände (Zulassungsklasse) unterliegen dem Waffengesetz?', 'options' => [
        ['t' => 'BAM P I', 'c' => false],
        ['t' => 'BAM PM I', 'c' => true],
        ['t' => 'BAM PM II', 'c' => true],
    ]],
    45 => ['type' => 'open_text', 'q' => 'Was regelt das Sprengstoffgesetz?', 'a' => 'Den Umgang und den Verkehr mit sowie die Einfuhr und die Durchfuhr von explosionsgefährlichen Stoffen und Sprengzubehör.'],
    46 => ['type' => 'open_text', 'q' => 'Welche Bestimmung regelt den Einsatz von Notsignalen auf See?', 'a' => 'Kollisionsverhütungsregeln (KVR; Regel 37).'],
    47 => ['type' => 'open_text', 'q' => 'Welche Vorschrift regelt die Pflicht zur Hilfeleistung in Seenotfällen?', 'a' => 'Verordnung über die Sicherung der Seefahrt.'],
    48 => ['type' => 'open_text', 'q' => 'Welche nautische Veröffentlichung (Broschüre) beschreibt die seemännische Sorgfaltspflicht für Wassersportler, auch für den Seenotfall? Wer gibt sie heraus?', 'a' => 'Die Broschüre „Sicherheit auf dem Wasser", herausgegeben vom Bundesamt für Seeschifffahrt und Hydrographie (BSH).'],
    49 => ['type' => 'open_text', 'q' => 'Welche nautische Veröffentlichung (Handbuch) für die Sport- und Kleinschifffahrt enthält Anleitungen zur Bewältigung von Notlagen auf See? Wer gibt sie heraus?', 'a' => 'Das Handbuch „Suche und Rettung", herausgegeben vom Bundesamt für Seeschifffahrt und Hydrographie (BSH).'],
    50 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Signalmittel unterliegen dem Waffengesetz?', 'options' => [
        ['t' => 'Alle Leuchtraketen, die einen eigenen Treibsatz beinhalten.', 'c' => false],
        ['t' => 'Alle Signalpistolen und Abschussvorrichtungen, sowie die für diese bestimmte Munition.', 'c' => true],
        ['t' => 'Alle steigenden Signale, die einen Durchmesser von mehr als 12 mm aufweisen.', 'c' => false],
    ]],
    51 => ['type' => 'multi_choice', 'q' => 'Welche pyrotechnischen Signalmittel unterliegen dem Sprengstoffgesetz?', 'options' => [
        ['t' => 'Handfackeln und Rauchkörper.', 'c' => true],
        ['t' => 'Handsignalraketen mit Fallschirm.', 'c' => true],
        ['t' => 'Blitz-Knall-Patronen im Kaliber 4.', 'c' => false],
    ]],
    52 => ['type' => 'multi_choice', 'q' => 'Was machen Sie mit Munition, die beim versuchten Verschießen aus der Signalwaffe nicht gezündet hat (Versager)?', 'options' => [
        ['t' => 'Wieder in die Originalverpackung zurücklegen.', 'c' => false],
        ['t' => 'An einer freien Stelle über Deck lagern.', 'c' => false],
        ['t' => 'Über Bord werfen.', 'c' => true],
    ]],
    53 => ['type' => 'open_text', 'q' => 'Dürfen Sie Seenotsignalmittel in öffentlichen Verkehrsmitteln befördern?', 'a' => 'Nein'],
    54 => ['type' => 'multi_choice', 'q' => 'Wem dürfen Seenotsignalmittel dauerhaft überlassen werden?', 'options' => [
        ['t' => 'Nur berechtigten Personen im Sinne des Waffen- oder Sprengstoffrechts.', 'c' => true],
        ['t' => 'Nur Waffenhändlern.', 'c' => false],
        ['t' => 'Jeder natürlichen Person.', 'c' => false],
    ]],
    55 => ['type' => 'multi_choice', 'q' => 'Wem dürfen Sie die Signalpistole im Kaliber 4 (26,5 mm) nebst Munition ohne Erlaubnis vorübergehend überlassen?', 'options' => [
        ['t' => 'Volljährigen Personen meines Vertrauens zur sicheren Aufbewahrung.', 'c' => false],
        ['t' => 'Feuerwehrleute.', 'c' => false],
        ['t' => 'Charterern von seegehenden Wasserfahrzeugen, sofern der Besitz über die Waffe nach den Weisungen des berechtigten Überlassers erfolgt.', 'c' => true],
    ]],
    56 => ['type' => 'multi_choice', 'q' => 'Wer darf während der Fahrt auf einem Gewässer oder See die Signalwaffe am Körper tragen (führen)?', 'options' => [
        ['t' => 'Jedes Besatzungsmitglied.', 'c' => false],
        ['t' => 'Alle Inhaber einer Waffensachkundeprüfung.', 'c' => false],
        ['t' => 'Der verantwortliche Führer des Wasserfahrzeugs.', 'c' => true],
    ]],
    57 => ['type' => 'multi_choice', 'q' => 'Wer darf in Seenotfällen mit einer Signalwaffe schießen?', 'options' => [
        ['t' => 'Jeder', 'c' => true],
        ['t' => 'Nur Inhaber einer Waffensachkundeprüfung.', 'c' => false],
        ['t' => 'Nur der verantwortliche Führer des betroffenen Wasserfahrzeuges.', 'c' => false],
    ]],
    58 => ['type' => 'open_text', 'q' => 'Was müssen Sie tun, wenn Ihnen Signalmittel oder Waffen abhanden kommen?', 'a' => 'Der Verlust ist der zuständigen Behörde unverzüglich anzuzeigen.'],
    59 => ['type' => 'multi_choice', 'q' => 'Für den Erwerb und Besitz welcher Signalwaffe benötigen Sie eine Erlaubnis der zuständigen Behörde?', 'options' => [
        ['t' => 'Signalpistole Kaliber 4 (26,5 mm).', 'c' => true],
        ['t' => 'Für Signalwaffen (SRS-Waffen), die kein Bauartzulassungszeichen „PTB im Kreis" (Physikalisch-Technische Bundesanstalt) tragen.', 'c' => true],
        ['t' => 'Signalwaffen (SRS-Waffen), die das Bauartzulassungszeichen „PTB im Kreis" (Physikalisch-Technische Bundesanstalt) tragen.', 'c' => false],
    ]],
    60 => ['type' => 'open_text', 'q' => 'Welche Ausnahme von den waffenrechtlichen Erlaubnispflichten für Waffen und Munition betrifft den Charterer einer seegehenden Yacht?', 'a' => 'Der Charterer darf ohne waffenrechtliche Erlaubnis die tatsächliche Gewalt über eine an Bord befindliche Signalpistole im Kaliber 4 (26,5 mm) und die dazugehörige Munition ausüben.'],
    // Rauchtöpfe gibt es seit Langem nur noch als P1; der amtliche Katalog
    // (Stand 01/2020) nennt hier noch „Rauchsignale orange" unter P2, eine
    // Überarbeitung durch das Bundesverwaltungsamt ist angekündigt.
    61 => ['type' => 'open_text', 'q' => 'Welche pyrotechnischen Handsignale für den Seenotfall sind erlaubnispflichtig?', 'a' => 'Handsignale der Unterklasse P2 („Signalraketen rot", „Fallschirmsignalraketen rot"). Rauchsignale (Rauchtöpfe) gibt es heute nur noch als P1; sie sind erlaubnisfrei.'],
    62 => ['type' => 'multi_choice', 'q' => 'Welche Behörde prüft pyrotechnische Seenotsignale und lässt sie zu?', 'options' => [
        ['t' => 'Die Physikalisch-Technische Bundesanstalt (PTB).', 'c' => false],
        ['t' => 'Die Bundesanstalt für Materialforschung und -prüfung (BAM).', 'c' => true],
        ['t' => 'Der Technische Überwachungsverein (TÜV).', 'c' => false],
    ]],
];
