<?php

/**
 * Seite "Prüfung buchen" (App\Support\ExamBooking): Anmeldung, Gebühren,
 * Ablauf und häufige Fragen je Prüfung, für Bootsschulen, deren
 * Teilnehmer sich selbst anmelden (App\Support\ExamRegistration::SELF).
 *
 * 'pages': eine Seite je Prüfung (Reiter im Kombikurs). 'variants' sind
 * die Prüfungsformen dieser Prüfung (Vollprüfung, Ergänzungen); 'courses'
 * legt je Kurs fest, welche Seiten er zeigt und welche Variante zuerst
 * steht. Je Variante: 'course' = Kurs mit dem amtlichen Regelwerk (null =
 * noch nicht verifiziert, dann ohne Bestehensgrenze und Dauer), 'parts' =
 * Fragen je Modul ('min' = Bestehensgrenze; die Katalogzahl kommt aus der
 * Datenbank). ExamBookingTest gleicht das mit den Regelwerken ab.
 *
 * Eigene Texte. Gebühren und Anmeldewege laut Prüfungsausschüssen und
 * Verwaltungsportalen (Stand 2024 bis 2026), deshalb immer "ca." und der
 * Hinweis, dass der Prüfungsausschuss maßgeblich ist.
 */
$providers = [
    [
        'name' => 'DSV',
        'full' => 'Deutscher Segler-Verband',
        'url' => 'https://www.dsv.org',
        'text' => 'Prüfungsausschüsse des DSV in ganz Deutschland; Termine und Orte findest du auf der Website des Verbands.',
    ],
    [
        'name' => 'DMYV',
        'full' => 'Deutscher Motoryachtverband',
        'url' => 'https://www.dmyv.de/fuehrerschein/-funk/pruefungen/pruefungsausschuesse/',
        'text' => 'Prüfungsausschüsse des DMYV mit Kontaktdaten und Sprechzeiten; den Termin stimmst du direkt mit dem Ausschuss ab.',
    ],
];

$faq = [
    'fee' => fn (string $name, string $fee) => ['Was kostet die Prüfung?' => "Die Prüfungsgebühr für den {$name} beträgt ca. {$fee}. Du zahlst sie direkt an den Prüfungsausschuss bzw. Verband, nicht an die Bootsschule. Für Sonderleistungen, z. B. eine Prüfung im Ausland, können Zusatzkosten anfallen."],
    'online' => ['Kann ich die Prüfung online ablegen?' => 'Nein. Online stellst du höchstens den Antrag; die Prüfung selbst legst du vor Ort vor einem Prüfungsausschuss ab.'],
];

$sbfDocuments = [
    'Ausgefüllter und unterschriebener Antrag auf Zulassung zur Prüfung',
    'Passbild 35 × 45 mm, auf der Rückseite Name und Geburtsdatum',
    'Ärztliches Zeugnis über Seh-, Farbunterscheidungs- und Hörvermögen, nicht älter als ein Jahr',
    'Kopie deines Kfz-Führerscheins oder, wenn du keinen hast, ein Führungszeugnis',
    'Falls vorhanden: Kopie eines anderen Sportbootführerscheins (ersetzt ggf. das ärztliche Zeugnis)',
    'Bei Minderjährigen: Einverständnis eines Erziehungsberechtigten auf dem Antrag (dann kein Führungszeugnis)',
];

$sbfFaq = [
    'Wann habe ich bestanden?' => 'Wenn du Theorie und Praxis bestehst. Die Bestehensgrenzen für den Fragebogen findest du oben bei der Theorie.',
    'Was passiert, wenn ich nicht bestehe?' => 'Theorie und Praxis werden getrennt bewertet. Einen nicht bestandenen Teil kannst du in der Regel innerhalb von 12 Monaten wiederholen; der bestandene Teil bleibt so lange gültig. Wartezeit und Frist nennt dir dein Prüfungsausschuss.',
    'Welche Unterlagen brauche ich zur Anmeldung?' => 'Antrag, Passbild, ärztliches Zeugnis und eine Kopie deines Kfz-Führerscheins (ohne Führerschein ein Führungszeugnis). Am Prüfungstag bringst du deinen Ausweis und den Führerschein im Original mit.',
];

return [
    'pages' => [
        'SRC' => [
            'tab' => 'SRC',
            'title' => 'SRC-Prüfung buchen',
            'intro' => 'Das Short Range Certificate (beschränkt gültiges Funkbetriebszeugnis) berechtigt dich, auf Sportbooten eine UKW-Seefunkanlage mit DSC zu bedienen. Abgenommen wird die Prüfung von den Prüfungsausschüssen des DSV und des DMYV.',
            'fee' => '127,88 €',
            'lead_time' => 'mind. 14 Tage vorher',
            'min_age' => '15 Jahre',
            'apply' => 'Den Antrag auf Zulassung zur Prüfung und Erteilung des SRC stellst du online im Bundesportal (verwaltung.bund.de) oder schriftlich beim DSV bzw. DMYV. Dabei wählst du auch Prüfungstermin und -ort.',
            'deadline' => 'Gebühr überweisen und Unterlagen einreichen: Beides muss spätestens 14 Tage vor dem Prüfungstermin beim Prüfungsausschuss vorliegen.',
            'documents' => [
                'Unterschriebener Antrag auf Zulassung zur Prüfung',
                'Aktuelles Passbild (per Post an den Verband)',
                'Nachweis über die bezahlte Prüfungsgebühr',
                'Falls vorhanden: Kopien bereits erworbener Funkzeugnisse',
                'Bei Minderjährigen: Einverständnis eines Erziehungsberechtigten',
            ],
            'variants' => [
                'full' => [
                    'label' => 'Vollprüfung',
                    'course' => 'SRC',
                    'parts' => [['module' => 'SRC', 'of' => 24, 'min' => 19]],
                    'minutes' => 30,
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen mit Antworten zur Auswahl aus dem amtlichen Fragenkatalog, je Frage genau eine richtige Antwort.'],
                ['key' => 'oral', 'title' => 'Englisch', 'icon' => 'microphone', 'link' => 'funk-oral', 'text' => 'Ein englischer Seefunktext wird dir vorgelesen – du schreibst ihn mit und übersetzt ihn ins Deutsche. Außerdem übersetzt du einen deutschen Funktext ins Englische.'],
                ['key' => 'praxis', 'title' => 'Praxis am Funkgerät', 'icon' => 'signal', 'link' => 'funk-praxis', 'text' => 'Bedienung einer UKW-Seefunkanlage mit DSC: Notalarm senden und aufheben, Not-, Dringlichkeits-, Sicherheits- und Routineverkehr per DSC und Sprechfunk abwickeln.'],
            ],
            'faq' => [
                'Wie läuft der Prüfungstag ab?' => 'Du legst den theoretischen Fragebogen ab, übersetzt die englischen Texte und zeigst im praktischen Teil am Funkgerät, dass du DSC-Anrufe und Sprechfunk sicher abwickeln kannst. Die genaue Reihenfolge legt dein Prüfungsausschuss fest.',
                'Wann habe ich bestanden?' => 'Wenn du alle Teile bestehst. Im Fragebogen brauchst du mindestens 19 von 24 richtigen Antworten (also höchstens 5 Fehler).',
            ] + $faq['fee']('SRC', '127,88 €') + $faq['online'] + [
                'Welche Unterlagen brauche ich zur Anmeldung?' => 'Den unterschriebenen Antrag, ein aktuelles Passbild, den Nachweis über die bezahlte Gebühr und, falls vorhanden, Kopien deiner bisherigen Funkzeugnisse. Am Prüfungstag bringst du deinen Personalausweis oder Reisepass mit.',
                'Ab welchem Alter darf ich die Prüfung machen?' => 'Das Mindestalter ist 15 Jahre. Bist du noch nicht volljährig, stellt ein Erziehungsberechtigter den Antrag für dich bzw. stimmt ihm zu.',
                'Was passiert, wenn ich nicht bestehe?' => 'Du kannst die Prüfung wiederholen. Ob du nur den nicht bestandenen Teil wiederholen musst und bis wann, sagt dir dein Prüfungsausschuss – frag am besten direkt nach der Prüfung nach.',
                'Was brauche ich nach der Prüfung für mein Boot?' => 'Für eine eigene Seefunkanlage an Bord beantragst du bei der Bundesnetzagentur die Frequenzzuteilung; dort bekommst du auch Rufzeichen und MMSI für den DSC-Controller.',
            ],
        ],

        'UBI' => [
            'tab' => 'UBI',
            'title' => 'UBI-Prüfung buchen',
            'intro' => 'Das UKW-Sprechfunkzeugnis für den Binnenschifffahrtsfunk (UBI) brauchst du, um auf Binnenschifffahrtsstraßen am Sprechfunk teilzunehmen. Auch diese Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab.',
            'fee' => '108,71 €',
            'lead_time' => 'mind. 14 Tage vorher',
            'min_age' => '15 Jahre',
            'apply' => 'Den Antrag auf Zulassung zur Prüfung und Erteilung des UBI stellst du online im Bundesportal (verwaltung.bund.de) oder schriftlich beim DSV bzw. DMYV. Dabei wählst du auch Prüfungstermin und -ort.',
            'deadline' => 'Gebühr überweisen und Unterlagen einreichen: Beides muss spätestens 14 Tage vor dem Prüfungstermin beim Prüfungsausschuss vorliegen.',
            'documents' => [
                'Unterschriebener Antrag auf Zulassung zur Prüfung',
                'Aktuelles Passbild (per Post an den Verband)',
                'Nachweis über die bezahlte Prüfungsgebühr',
                'Falls vorhanden: Kopien bereits erworbener Funkzeugnisse',
                'Bei Minderjährigen: Einverständnis eines Erziehungsberechtigten',
            ],
            'variants' => [
                'full' => [
                    'label' => 'Vollprüfung',
                    'course' => 'UBI',
                    'parts' => [['module' => 'UBI', 'of' => 22, 'min' => 17]],
                    'minutes' => 60,
                ],
                'addon' => [
                    'label' => 'Ergänzungsprüfung für SRC-Inhaber',
                    'course' => 'ADD-UBI-TO-SRC',
                    'parts' => [['module' => 'UBI_ERG', 'of' => 10, 'min' => 8]],
                    'minutes' => 30,
                    'note' => 'Du hast schon das SRC: Für dich gilt die verkürzte Ergänzungsprüfung mit 10 Fragen und einem kürzeren praktischen Teil.',
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen mit Antworten zur Auswahl aus dem amtlichen Fragenkatalog. Wer schon das SRC (oder LRC) hat, legt nur eine verkürzte Ergänzungsprüfung ab.'],
                ['key' => 'oral', 'title' => 'Textaufnahme und Textabgabe', 'icon' => 'microphone', 'link' => 'funk-oral', 'text' => 'Auf Deutsch: Textaufnahme (ein Funktext wird vorgelesen, du schreibst mit) und Textabgabe (du liest einen Funktext vor und buchstabierst die markierten Wörter mit der internationalen Buchstabiertafel).'],
                ['key' => 'praxis', 'title' => 'Praxis am Funkgerät', 'icon' => 'signal', 'link' => 'funk-praxis', 'text' => 'Bedienung einer Binnenfunkanlage: Verkehrskreise und Kanäle wählen, Not-, Dringlichkeits-, Sicherheits- und Routineverkehr abwickeln. Für SRC-Inhaber ist auch der praktische Teil verkürzt.'],
            ],
            'faq' => [
                'Ich habe schon das SRC – muss ich alles wiederholen?' => 'Nein. Mit SRC oder LRC legst du eine verkürzte Ergänzungsprüfung ab: 10 Fragen aus dem Ergänzungskatalog (mindestens 8 richtig) und einen kürzeren praktischen Teil.',
                'Wie läuft der Prüfungstag ab?' => 'Du legst den theoretischen Fragebogen ab, nimmst einen Funktext auf, gibst einen Funktext ab und wickelst im praktischen Teil Funkgespräche an der Binnenfunkanlage ab. Die genaue Reihenfolge legt dein Prüfungsausschuss fest.',
                'Wann habe ich bestanden?' => 'Wenn du alle Teile bestehst. Im Fragebogen brauchst du mindestens 17 von 22 richtigen Antworten (also höchstens 5 Fehler), in der Ergänzungsprüfung mindestens 8 von 10.',
            ] + $faq['fee']('UBI', '108,71 €') + $faq['online'] + [
                'Welche Unterlagen brauche ich zur Anmeldung?' => 'Den unterschriebenen Antrag, ein aktuelles Passbild, den Nachweis über die bezahlte Gebühr und, falls vorhanden, Kopien deiner bisherigen Funkzeugnisse. Am Prüfungstag bringst du deinen Personalausweis oder Reisepass mit.',
                'Ab welchem Alter darf ich die Prüfung machen?' => 'Das Mindestalter ist 15 Jahre. Bist du noch nicht volljährig, stellt ein Erziehungsberechtigter den Antrag für dich bzw. stimmt ihm zu.',
                'Was passiert, wenn ich nicht bestehe?' => 'Du kannst die Prüfung wiederholen. Ob du nur den nicht bestandenen Teil wiederholen musst und bis wann, sagt dir dein Prüfungsausschuss – frag am besten direkt nach der Prüfung nach.',
                'Was brauche ich nach der Prüfung für mein Boot?' => 'Für eine eigene Binnenfunkanlage an Bord beantragst du bei der Bundesnetzagentur die Frequenzzuteilung mit der ATIS-Kennung deines Fahrzeugs.',
            ],
        ],

        'SBF-SEE' => [
            'tab' => 'SBF See',
            'title' => 'SBF-See-Prüfung buchen',
            'intro' => 'Den Sportbootführerschein See brauchst du, um auf den Seeschifffahrtsstraßen ein Sportboot mit einem Motor von mehr als 11,03 kW (15 PS) zu führen. Die Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab.',
            'fee' => '148,38 €',
            'lead_time' => 'je nach Ausschuss 7–14 Tage vorher',
            'min_age' => '16 Jahre',
            'apply' => 'Den Antrag auf Zulassung zur Prüfung stellst du beim Prüfungsausschuss deiner Wahl; das Formular gibt es bei DSV, DMYV und auf deren gemeinsamer Website sportbootfuehrerscheine.org. Dort wählst du auch Termin und Ort.',
            'deadline' => 'Unterlagen und Gebühr müssen je nach Prüfungsausschuss spätestens 7 bis 14 Tage vor dem Prüfungstermin vorliegen.',
            'documents' => $sbfDocuments,
            'variants' => [
                'full' => [
                    'label' => 'Vollprüfung',
                    'course' => 'SBF-SEE',
                    'parts' => [
                        ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'of' => 7, 'min' => 5],
                        ['module' => 'SBF_SEE', 'label' => 'See-Fragen', 'of' => 23, 'min' => 18],
                    ],
                    'minutes' => 60,
                    'extra' => 'Dazu die Navigationsaufgabe in der Seekarte: mindestens 7 von 9 Punkten.',
                ],
                'addon' => [
                    'label' => 'Ergänzungsprüfung für SBF-Binnen-Inhaber',
                    'course' => 'ADD-BIN-TO-SEE',
                    'parts' => [['module' => 'SBF_SEE', 'of' => 23, 'min' => 18]],
                    'minutes' => 50,
                    'extra' => 'Dazu die Navigationsaufgabe in der Seekarte: mindestens 7 von 9 Punkten.',
                    'note' => 'Du hast schon den SBF Binnen: Die Basisfragen entfallen, und in der praktischen Prüfung fährst du nur die fünf Pflichtmanöver – Rettungsmanöver, An- und Ablegen, Steuern nach Kompass und Peilen.',
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen mit vier Antworten zur Auswahl aus dem amtlichen Fragenkatalog, je Frage genau eine richtige Antwort.'],
                ['key' => 'navigation', 'title' => 'Navigationsaufgabe', 'icon' => 'compass', 'link' => 'navigation', 'text' => 'Kartenaufgabe in der Übungsseekarte: Kurse und Positionen bestimmen, Gezeiten und Strom berücksichtigen.'],
                ['key' => 'praxis', 'title' => 'Praxis an Bord', 'icon' => 'ship', 'link' => null, 'text' => 'Prüfungsfahrt unter Motor mit Manövern wie Mensch-über-Bord, An- und Ablegen und Fahren nach Kompass, dazu Seemannsknoten. Welche Manöver drankommen, entscheidet der Prüfer.'],
            ],
            'faq' => [
                'Wie läuft der Prüfungstag ab?' => 'Du legst den Fragebogen und die Navigationsaufgabe ab und fährst die praktische Prüfung unter Motor. Theorie und Praxis können am selben Tag oder an verschiedenen Tagen stattfinden; das legt dein Prüfungsausschuss fest.',
            ] + $sbfFaq + $faq['fee']('SBF See', '148,38 €') + $faq['online'] + [
                'Ab welchem Alter darf ich die Prüfung machen?' => 'Das Mindestalter ist 16 Jahre. Zur Prüfung kannst du schon mit 15 Jahren und 9 Monaten antreten; den Führerschein bekommst du dann ab dem 16. Geburtstag.',
                'Ich habe schon den SBF Binnen – was muss ich noch machen?' => 'Eine Ergänzungsprüfung: 23 See-Fragen ohne Basisfragen und die Navigationsaufgabe. In der praktischen Prüfung fährst du nur die Pflichtmanöver; Knoten und die übrigen Manöver entfallen.',
            ],
        ],

        'SBF-BIN' => [
            'tab' => 'SBF Binnen',
            'title' => 'SBF-Binnen-Prüfung buchen',
            'intro' => 'Der Sportbootführerschein Binnen gilt auf den Binnenschifffahrtsstraßen, unter Antriebsmaschine (Motor über 11,03 kW bzw. 15 PS) und/oder unter Segel. Die Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab.',
            'fee' => '130 €',
            'lead_time' => 'je nach Ausschuss 7–14 Tage vorher',
            'min_age' => '16 Jahre (Segel: 14)',
            'apply' => 'Den Antrag auf Zulassung zur Prüfung stellst du beim Prüfungsausschuss deiner Wahl; das Formular gibt es bei DSV, DMYV und auf deren gemeinsamer Website sportbootfuehrerscheine.org. Dort wählst du auch Termin und Ort.',
            'deadline' => 'Unterlagen und Gebühr müssen je nach Prüfungsausschuss spätestens 7 bis 14 Tage vor dem Prüfungstermin vorliegen.',
            'documents' => $sbfDocuments,
            'variants' => [
                'motor' => [
                    'label' => 'Vollprüfung unter Antriebsmaschine',
                    'course' => 'SBF-BIN-MOTOR',
                    'parts' => [
                        ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'of' => 7, 'min' => 5],
                        ['module' => 'SBF_BINNEN', 'label' => 'Binnen-Fragen', 'of' => 23, 'min' => 18],
                    ],
                    'minutes' => 45,
                ],
                'segel' => [
                    'label' => 'Vollprüfung unter Segel',
                    'course' => 'SBF-BIN-SEGEL',
                    'parts' => [
                        ['module' => 'SBF_BASIS', 'label' => 'Basisfragen', 'of' => 7, 'min' => 5],
                        ['module' => 'SBF_BINNEN', 'label' => 'Binnen-Fragen', 'of' => 23, 'min' => 18],
                        ['module' => 'SBF_BINNEN_SEGELN', 'label' => 'Segelfragen', 'of' => 7, 'min' => 5],
                    ],
                    'minutes' => 60,
                    'note' => 'Für den SBF Binnen unter Segel kommen zum Fragebogen 7 Segelfragen hinzu, und die praktische Prüfung findet unter Segel statt. Das Mindestalter ist 14 Jahre.',
                ],
                'addon' => [
                    'label' => 'Ergänzungsprüfung für SBF-See-Inhaber',
                    'course' => 'ADD-SEE-TO-BIN',
                    'parts' => [['module' => 'SBF_BINNEN', 'of' => 23, 'min' => 18]],
                    'minutes' => 35,
                    'note' => 'Du hast schon den SBF See: Die Basisfragen entfallen, und die praktische Prüfung unter Antriebsmaschine wird in der Regel angerechnet.',
                ],
                'combo' => [
                    'label' => 'Binnen-Teil bei gemeinsamer Prüfung See und Binnen',
                    // Regelwerk der Binnen-Bögen des Kombikurses (wie die Ergänzung).
                    'course' => 'ADD-SEE-TO-BIN',
                    'parts' => [['module' => 'SBF_BINNEN', 'of' => 23, 'min' => 18]],
                    'minutes' => 35,
                    'note' => 'Legst du SBF See und SBF Binnen zusammen ab, kommen die Basisfragen nur einmal dran, im See-Teil. Für Binnen bleiben die 23 Binnen-Fragen.',
                ],
                'segel-addon' => [
                    'label' => 'Ergänzung Segeln für SBF-Binnen-Inhaber',
                    'course' => 'ADD-BIN-SEGEL',
                    'parts' => [['module' => 'SBF_BINNEN_SEGELN', 'of' => 7, 'min' => 5]],
                    'minutes' => 15,
                    'note' => 'Du hast schon den SBF Binnen unter Antriebsmaschine: Für „unter Segel“ legst du nur die 7 Segelfragen und eine praktische Prüfung unter Segel ab.',
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen mit vier Antworten zur Auswahl aus dem amtlichen Fragenkatalog, je Frage genau eine richtige Antwort.'],
                ['key' => 'praxis', 'title' => 'Praxis an Bord', 'icon' => 'ship', 'link' => null, 'text' => 'Prüfungsfahrt unter Antriebsmaschine bzw. unter Segel mit Manövern wie Mensch-über-Bord, An- und Ablegen, dazu Knoten. Welche Manöver drankommen, entscheidet der Prüfer.'],
            ],
            'faq' => [
                'Wie läuft der Prüfungstag ab?' => 'Du legst den Fragebogen ab und fährst die praktische Prüfung unter Antriebsmaschine bzw. unter Segel. Theorie und Praxis können am selben Tag oder an verschiedenen Tagen stattfinden; das legt dein Prüfungsausschuss fest.',
            ] + $sbfFaq + $faq['fee']('SBF Binnen', '130 €') + [
                'Woraus setzt sich die Gebühr zusammen?' => 'Aus Gebühren für Zulassung, theoretische Prüfung, praktische Prüfung und Erteilung des Führerscheins. Für eine Ergänzungsprüfung ohne praktischen Teil fällt dessen Gebühr weg.',
            ] + $faq['online'] + [
                'Ab welchem Alter darf ich die Prüfung machen?' => 'Unter Antriebsmaschine ab 16 Jahren, unter Segel ab 14 Jahren. Zur Prüfung kannst du jeweils schon 3 Monate vorher antreten; den Führerschein bekommst du ab dem Geburtstag.',
                'Ich habe schon den SBF See – was muss ich noch machen?' => 'Eine Ergänzungsprüfung mit 23 Binnen-Fragen ohne Basisfragen. Die praktische Prüfung unter Antriebsmaschine wird in der Regel angerechnet.',
            ],
        ],

        'FKN' => [
            'tab' => 'FKN',
            'title' => 'FKN-Prüfung buchen',
            'intro' => 'Der Fachkundenachweis für Seenotsignalmittel (FKN) berechtigt dich, pyrotechnische Seenotsignalmittel wie Handfackeln, Fallschirmsignalraketen und Rauchsignale zu erwerben und zu verwenden – ohne Signalpistole, dafür brauchst du den SKN. Die Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab, oft zusammen mit der Prüfung zum Sportbootführerschein.',
            'fee' => '29 €',
            'lead_time' => null,
            'min_age' => '16 Jahre',
            'apply' => 'Den Antrag stellst du beim Prüfungsausschuss deiner Wahl, häufig gleich zusammen mit der Anmeldung zum Sportbootführerschein. Termin und Ort stimmst du mit dem Ausschuss ab.',
            'deadline' => 'Unterlagen und Gebühr reichst du rechtzeitig vor dem Termin ein; die Frist nennt dir dein Prüfungsausschuss.',
            'documents' => [
                'Ausgefüllter und unterschriebener Antrag',
                'Kopie deines Sportbootführerscheins See oder Binnen (oder eines anderen anerkannten Befähigungsnachweises) – legst du die SBF-Prüfung gleichzeitig ab, gib das im Antrag an',
                'Bei Minderjährigen: Einverständnis eines Erziehungsberechtigten',
            ],
            'variants' => [
                'full' => [
                    'label' => 'Prüfung',
                    'course' => 'FKN',
                    'parts' => [['module' => 'FKN', 'of' => 15, 'min' => 12]],
                    'pass' => 'mind. 24 von 30 Punkten (2 Punkte je vollständig richtiger Antwort)',
                    'minutes' => 30,
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen aus dem amtlichen Katalog, teils mit mehreren richtigen Antworten, teils mit freier Antwort: Sprengstoffrecht, Arten der Seenotsignalmittel, Lagerung und sichere Verwendung.'],
                ['key' => 'praxis', 'title' => 'Praxis', 'icon' => 'flag', 'link' => null, 'text' => 'Vier Aufgaben: Fallschirmsignalrakete (rot), Rauchfackel (orange) oder Handfackel (rot) und Rauchsignal (orange) bestimmen und handhaben, dazu das Verhalten bei Versagern. 3 von 4 Aufgaben müssen richtig sein.'],
            ],
            'faq' => [
                'Brauche ich dafür einen Bootsführerschein?' => 'Ja: Entweder hast du schon einen Sportbootführerschein See oder Binnen (oder einen anderen anerkannten Befähigungsnachweis), oder du legst die SBF-Prüfung gleichzeitig mit der FKN-Prüfung ab. Am Prüfungstag musst du mindestens 16 Jahre alt sein.',
                'Wann habe ich bestanden?' => 'Im Fragebogen (15 Fragen, 30 Minuten) brauchst du mindestens 24 von 30 Punkten, in der Praxis 3 von 4 Aufgaben.',
            ] + $faq['fee']('FKN', '29 €') + $faq['online'] + [
                'FKN oder SKN – was brauche ich?' => 'Für Handfackeln, Raketen und Rauchsignale reicht der FKN. Für eine Signalpistole brauchst du den Sachkundenachweis (SKN).',
                'Was passiert, wenn ich nicht bestehe?' => 'Du kannst die Prüfung wiederholen. Wann und wie, sagt dir dein Prüfungsausschuss.',
            ],
        ],

        'SKN' => [
            'tab' => 'SKN',
            'title' => 'SKN-Prüfung buchen',
            'intro' => 'Der Sachkundenachweis für Seenotsignalmittel (SKN) ist eine Sachkundeprüfung nach dem Waffengesetz. Er schließt den FKN ein und ist Voraussetzung, um eine Signalpistole besitzen zu dürfen. Die Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab.',
            'fee' => '48 €',
            'lead_time' => null,
            'min_age' => '18 Jahre',
            'apply' => 'Den Antrag stellst du beim Prüfungsausschuss deiner Wahl. Termin und Ort stimmst du mit dem Ausschuss ab.',
            'deadline' => 'Unterlagen und Gebühr reichst du rechtzeitig vor dem Termin ein; die Frist nennt dir dein Prüfungsausschuss.',
            'documents' => [
                'Ausgefüllter und unterschriebener Antrag',
                'Falls vorhanden: Kopie deines FKN',
            ],
            'variants' => [
                'full' => [
                    'label' => 'Prüfung',
                    'course' => 'SKN',
                    'parts' => [['module' => 'SKN', 'of' => 18, 'min' => 13]],
                    'pass' => 'mind. 26 von 36 Punkten (2 Punkte je vollständig richtiger Antwort)',
                    'minutes' => 60,
                ],
                'addon' => [
                    'label' => 'Für FKN-Inhaber',
                    'course' => null,
                    'parts' => [['module' => 'SKN']],
                    'note' => 'Du hast schon den FKN: Ob und wie weit er bei der SKN-Prüfung angerechnet wird, sagt dir dein Prüfungsausschuss.',
                ],
            ],
            'exam_parts' => [
                ['key' => 'theory', 'title' => 'Theorie', 'icon' => 'document-text', 'link' => 'exam', 'text' => 'Fragebogen mit 18 Fragen aus dem amtlichen Fragenkatalog der Sachkundeprüfung, Kapitel „Not- und Seenotsignalmittel“: Waffen- und Sprengstoffrecht, Signalpistole und Munition, sichere Handhabung. 60 Minuten, bestanden ab 26 von 36 Punkten.'],
                ['key' => 'praxis', 'title' => 'Praxis', 'icon' => 'flag', 'link' => null, 'text' => 'Zwei Prüfer, vier Übungen aus: Fallschirmsignalrakete, Handfackel, Rauchfackel, Rauchtopf, Signalpistole und Verhalten bei Versagern. 3 von 4 Übungen müssen richtig sein.'],
            ],
            'faq' => [
                'Brauche ich dafür einen Bootsführerschein?' => 'Nein. Für den SKN musst du am Prüfungstag mindestens 18 Jahre alt sein; ein Bootsführerschein ist nicht nötig.',
                'Wann habe ich bestanden?' => 'Im Fragebogen (18 Fragen, 60 Minuten) brauchst du mindestens 26 von 36 Punkten, in der Praxis 3 von 4 Übungen.',
            ] + $faq['fee']('SKN', '48 €') + $faq['online'] + [
                'FKN oder SKN – was brauche ich?' => 'Für Handfackeln, Raketen und Rauchsignale reicht der FKN. Für eine Signalpistole brauchst du den SKN; für deren Besitz zusätzlich eine Erlaubnis der Waffenbehörde.',
                'Was passiert, wenn ich nicht bestehe?' => 'Du kannst die Prüfung frühestens 14 Tage nach der erfolglosen Prüfung wiederholen. Den Termin stimmst du mit deinem Prüfungsausschuss ab.',
            ],
        ],
    ],

    // Kurs-Code => [Seite => Variante, die zuerst steht], Seiten in Reiter-Reihenfolge.
    'courses' => [
        'SRC' => ['SRC' => 'full'],
        'UBI' => ['UBI' => 'full'],
        'ADD-UBI-TO-SRC' => ['UBI' => 'addon'],
        'SRC-UBI' => ['SRC' => 'full', 'UBI' => 'full'],
        'SBF-SEE' => ['SBF-SEE' => 'full'],
        'ADD-BIN-TO-SEE' => ['SBF-SEE' => 'addon'],
        'SBF-BIN-MOTOR' => ['SBF-BIN' => 'motor'],
        'SBF-BIN-SEGEL' => ['SBF-BIN' => 'segel'],
        'ADD-BIN-SEGEL' => ['SBF-BIN' => 'segel-addon'],
        'ADD-SEE-TO-BIN' => ['SBF-BIN' => 'addon'],
        // Review: die Binnen-Bögen des Kombikurses sind die 23 Binnen-Fragen ohne
        // Basisfragen (die kommen nur einmal, im See-Teil).
        'SBF-SEE-BIN' => ['SBF-SEE' => 'full', 'SBF-BIN' => 'combo'],
        'FKN' => ['FKN' => 'full'],
        'SKN' => ['SKN' => 'full'],
        'ADD-FKN-TO-SKN' => ['SKN' => 'addon'],
    ],

    'providers' => $providers,
];
