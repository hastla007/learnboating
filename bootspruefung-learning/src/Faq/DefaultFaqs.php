<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Standard-FAQs (Quelle: „Alle_FAQs_Learnboating.txt“, FAQ-Entwurf vom 10.11.2026), wörtlich übernommen.
 *
 * Aufbau: Rubrik (oberste Kategorie, Schlüssel = Kurscode bzw. „allgemein“) → Unterkategorie → FAQ.
 * ref = Nummer aus der Datei, theme = ★ (auch im Theme), note = Redaktionshinweis (nur im Admin).
 * {{agb}} wird beim Ausgeben zum Link auf die AGB-Seite, {{link:/pfad/|Text}} zum Link auf eine Seite der Website
 * (FaqRepository::tokens). Antworten mit Zeilenumbrüchen: Absätze (Leerzeile), Listen („- “), **fett**
 * (FaqImporter::content). Reihenfolge der Einträge = Reihenfolge in der Datei. Nur lesen – Änderungen macht der
 * Betreiber im Admin.
 *
 * Datenstand 3 (10.11.2026): Korrekturen der Inhaltsmoderation (content/src/_moderation/_faq-aenderungen-1/-2.md),
 * neue FAQs 93–99, FAQ 13 entfernt. Datenstand 4 (11.11.2026): FAQ 18 und 94 ohne Verweis auf Presseberichte
 * (Reform-Text wie auf den Inhaltsseiten). Frühere Texte: DefaultFaqHistory.
 */
final class DefaultFaqs
{
    /**
     * Mit einem späteren Datenstand hinzugekommene FAQs (Datenstand => Nummern). Bestehende Installationen bekommen
     * sie beim Update automatisch (FaqImporter::maybeImport); früher vorhandene, vom Betreiber gelöschte FAQs nicht.
     *
     * @var array<string,list<int>>
     */
    public const ADDED = [
        '3' => [93, 94, 95, 96, 97, 98, 99],
    ];

    /**
     * Aus den Standards entfernte FAQs (Nummer => Grund). Auf bestehenden Installationen legt
     * FaqImporter::updateDefaults() sie in den Papierkorb, wenn sie unverändert sind.
     *
     * @var array<int,string>
     */
    public const REMOVED = [
        13 => 'Wie buche ich eine Praxisstunde? – Praxisstunden bietet die Plattform nicht an (beantwortet durch FAQ 12).',
    ];

    /** @var list<array{key:string,name:string,slug:string,subcategories:list<array{name:string,slug:string,faqs:list<array{ref:int,theme:bool,question:string,answer:string,note:?string}>}>}> */
    public const RUBRICS = [
        [
            'key' => 'allgemein', 'name' => 'Allgemeine Fragen', 'slug' => 'allgemein',
            'subcategories' => [
                ['name' => 'Scheinwahl und Geltungsbereich', 'slug' => 'allgemein-scheinwahl-und-geltungsbereich', 'faqs' => [
                    ['ref' => 1, 'theme' => false, 'question' => 'Welchen Bootsführerschein brauche ich?',
                        'answer' => 'Das hängt davon ab, wo und womit du fahren willst. Für Motorboote mit mehr als 11,03 kW (15 PS) brauchst du auf Seeschifffahrtsstraßen den SBF See und auf Binnenschifffahrtsstraßen den SBF Binnen. Unter Segel ist der SBF Binnen nur auf bestimmten Revieren vorgeschrieben; auf See brauchst du zum Segeln keinen Schein. Mehr dazu: {{link:/sportbootfuehrerschein/|Sportbootführerschein im Überblick}}.',
                        'note' => null],
                    ['ref' => 2, 'theme' => false, 'question' => 'Was ist der Unterschied zwischen SBF Binnen und SBF See?',
                        'answer' => 'Der SBF Binnen gilt auf Binnenschifffahrtsstraßen, unter Antriebsmaschine, unter Segel oder beides. Der SBF See gilt auf Seeschifffahrtsstraßen und nur für Boote mit Antriebsmaschine. Jeder Schein gilt nur für den eingetragenen Geltungsbereich. Wer beide Bereiche befahren will, braucht beide Scheine. Mehr dazu: {{link:/sportbootfuehrerschein/#vergleich|SBF See und Binnen im Vergleich}}.',
                        'note' => null],
                    ['ref' => 3, 'theme' => false, 'question' => 'Kann ich mit meinem Bootsführerschein auch im Ausland fahren?',
                        'answer' => 'Das entscheidet jedes Land selbst. Der deutsche SBF wird als internationales Zertifikat nach der UNECE-Resolution Nr. 40 ausgestellt. Das kann die Anerkennung erleichtern, aber nicht alle Staaten wenden die Resolution an. Manche Länder erkennen ausländische Scheine nur bei Personen an, die im Ausstellerland wohnen, zum Beispiel Österreich und die Schweiz. Frag vor Reise oder Charter bei der Behörde des Ziellandes und beim Vercharterer nach. Mehr dazu: {{link:/bootsfuehrerschein-ausland-anerkennung/|Bootsführerschein im Ausland}}.',
                        'note' => null],
                    ['ref' => 93, 'theme' => false, 'question' => 'Brauche ich für mein Boot überhaupt einen Führerschein?',
                        'answer' => 'Nicht immer. Auf den meisten Binnen- und Seeschifffahrtsstraßen darfst du Motorboote bis 11,03 kW (15 PS) ohne Führerschein fahren. Segeln auf See ist fahrerlaubnisfrei. Auch ohne Schein gilt binnen für Motorboote ein Mindestalter von 16 Jahren. Auf dem Bodensee und dem Rhein gelten eigene Regeln. Mehr dazu: {{link:/sportbootfuehrerschein/#fuehrerscheinfrei|Wann du keinen Bootsführerschein brauchst}}.',
                        'note' => null],
                    ['ref' => 98, 'theme' => false, 'question' => 'Ich wohne in Österreich oder der Schweiz – lohnt sich ein deutscher SBF?',
                        'answer' => 'Für Gewässer in deinem Wohnland oft nicht. In Österreich gelten ausländische Scheine vor allem für Gäste mit Wohnsitz im Ausland, und ein Befähigungsausweis ist schon ab 4,4 kW nötig. In der Schweiz brauchst du nach zwölf Monaten Wohnsitz einen Schweizer Ausweis. Für deutsche Gewässer kann der deutsche SBF trotzdem sinnvoll sein. Kläre das vor dem Kurskauf mit der zuständigen Behörde. Mehr dazu: {{link:/deutscher-bootsfuehrerschein-oesterreich/|Wohnsitz in Österreich}} und {{link:/deutscher-bootsfuehrerschein-schweiz/|in der Schweiz}}.',
                        'note' => 'erst veröffentlichen, wenn die Seiten „Wohnsitz in Österreich“ und „Wohnsitz in der Schweiz“ live sind (sonst Links weglassen).'],
                ]],
                ['name' => 'Voraussetzungen', 'slug' => 'allgemein-voraussetzungen', 'faqs' => [
                    ['ref' => 4, 'theme' => false, 'question' => 'Welche Voraussetzungen muss ich für einen Bootsführerschein erfüllen?',
                        'answer' => "Für den Sportbootführerschein brauchst du:\n\n- das Mindestalter (16 Jahre unter Antriebsmaschine, 14 Jahre für den SBF Binnen unter Segel);\n- einen ärztlichen Tauglichkeitsnachweis, der bei Antragstellung höchstens zwölf Monate alt ist;\n- ab 18 Jahren einen Zuverlässigkeitsnachweis, also eine Kopie deines Kfz-Führerscheins oder ein Führungszeugnis;\n- eine bestandene Prüfung in Theorie und Praxis.\n\nMehr dazu: {{link:/mindestalter-bootsfuehrerschein/|Mindestalter}} und {{link:/aerztliches-attest-bootsfuehrerschein/|ärztliches Attest}}.",
                        'note' => null],
                    ['ref' => 5, 'theme' => false, 'question' => 'Wie alt muss ich für den Sportbootführerschein sein?',
                        'answer' => 'Unter Antriebsmaschine, also für den SBF See und den SBF Binnen unter Motor, gilt ein Mindestalter von 16 Jahren. Für den SBF Binnen unter Segel sind es 14 Jahre. Minderjährige brauchen die Zustimmung ihrer gesetzlichen Vertretung. Ob du schon kurz vor deinem Geburtstag zur Prüfung antreten kannst, sagt dir dein Prüfungsausschuss. Mehr dazu: {{link:/mindestalter-bootsfuehrerschein/|Mindestalter}}.',
                        'note' => null],
                    ['ref' => 6, 'theme' => false, 'question' => 'Brauche ich Vorkenntnisse, bevor ich mit dem Kurs beginne?',
                        'answer' => 'In der Regel kannst du ohne Vorkenntnisse starten. Der Kurs erklärt die Grundlagen Schritt für Schritt. Für den FKN brauchst du allerdings einen Sportbootführerschein, oder du legst die SBF-Prüfung gleichzeitig ab.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'allgemein-lernen', 'faqs' => [
                    ['ref' => 7, 'theme' => false, 'question' => 'Kann ich mich online auf die Prüfung vorbereiten?',
                        'answer' => 'Ja. Mit unseren Onlinekursen lernst du die Theorie flexibel und im eigenen Tempo. Dazu gehören alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und Praxiskarten, beim SBF See außerdem die Navigationsaufgaben. Fahrstunden auf dem Wasser gehören zu keinem Kurs; die bekommst du bei einer Bootsschule. Die amtliche Prüfung legst du vor Ort bei einem Prüfungsausschuss ab.',
                        'note' => null],
                    ['ref' => 8, 'theme' => false, 'question' => 'Wie lange dauert die Vorbereitung auf die Prüfung?',
                        'answer' => 'Das ist individuell und hängt vom Schein, deinem Vorwissen und deiner Lernzeit ab. Plane genügend Zeit ein, um die Theorie zu verstehen und Fragen regelmäßig zu wiederholen.',
                        'note' => null],
                ]],
                ['name' => 'Kursinhalt und Leistungen', 'slug' => 'allgemein-kursinhalt-und-leistungen', 'faqs' => [
                    ['ref' => 9, 'theme' => false, 'question' => 'Was ist im Kurspreis enthalten?',
                        'answer' => 'Je nach Kurs: alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und Praxiskarten. Beim SBF See kommen die Navigationsaufgaben dazu, bei den Funkkursen das Funktraining. In der Stufe „Komplett“ und in den Kombis ist der Videokurs enthalten. Nicht enthalten sind die amtliche Prüfung und ihre Gebühr, Fahrstunden, die Übungskarte D49 und das ärztliche Attest. Was ein Kurs genau umfasst, steht auf seiner Kursseite.',
                        'note' => null],
                    ['ref' => 95, 'theme' => false, 'question' => 'Was ist der Unterschied zwischen „Theorie“ und „Komplett mit Videokurs“?',
                        'answer' => 'Beide Stufen enthalten alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und die Trainer des Kurses. „Komplett“ enthält zusätzlich den Videokurs. Du kannst auch mit „Theorie“ starten und den Videokurs später als Upgrade dazubuchen. Die erste Video-Lektion ist in jedem Kurs kostenlos.',
                        'note' => null],
                    ['ref' => 96, 'theme' => false, 'question' => 'Ich habe schon einen Schein – muss ich den ganzen Kurs kaufen?',
                        'answer' => 'Nein. Für Inhaber eines Scheins gibt es Ergänzungskurse mit nur den zusätzlichen Inhalten: See → Binnen, Binnen → See, Binnen → Segeln und SRC → UBI. Für FKN-Inhaber gibt es die Ergänzung FKN → SKN mit dem vollständigen SKN-Fragenkatalog. Hast du einen Kurs schon gekauft, schlagen wir dir im Lernbereich und im Warenkorb die passende Ergänzung vor.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'allgemein-lernmaterial', 'faqs' => [
                    ['ref' => 11, 'theme' => false, 'question' => 'Gibt es gedruckte Lernmaterialien?',
                        'answer' => 'Nein. Alle unsere Kurse sind digital und laufen im Browser, es gibt keine Versandkosten. Für die Navigationsaufgaben zum SBF See brauchst du die gedruckte Übungsseekarte D49. Die besorgst du dir selbst.',
                        'note' => null],
                ]],
                ['name' => 'Lernplattform und Zugang', 'slug' => 'allgemein-lernplattform-und-zugang', 'faqs' => [
                    ['ref' => 10, 'theme' => true, 'question' => 'Wie lange habe ich Zugriff auf die Lernplattform?',
                        'answer' => 'Sportbootführerscheine, Funkzeugnisse, Kombis, das Kapitäns-Bundle und Gruppenpakete laufen 12 Monate ab Zahlungseingang, SKN, FKN und Ergänzungskurse 6 Monate. Pässe laufen 30 oder 90 Tage, Simulator-Zugänge 14 Tage, 3 oder 12 Monate. Das Video-Upgrade läuft bis zum Ende deines Theoriezugangs. Bei Gruppenpaketen endet die Laufzeit für alle gleichzeitig. Kaufst du denselben Kurs erneut, schließt die neue Laufzeit an.',
                        'note' => null],
                    ['ref' => 88, 'theme' => true, 'question' => 'Kann ich den Kurs vor dem Kauf ausprobieren?',
                        'answer' => "Ja. Mit einem kostenlosen Kundenkonto testest du in jedem Kurs:\n\n- 10 Fragen je Modul;\n- einen Prüfungsbogen;\n- die erste Video-Lektion;\n- die erste Navigationsaufgabe;\n- die erste Praxiskarte.\n\nBei den Funkkursen kannst du die Testversion des Funkgerät-Simulators ausprobieren. Die Zufallsprüfung gehört zum gekauften Kurs.",
                        'note' => null],
                    ['ref' => 89, 'theme' => true, 'question' => 'Brauche ich eine App, um zu lernen?',
                        'answer' => 'Nein. Du lernst direkt im Browser, am Rechner, Tablet oder Smartphone, ohne Installation. Dein Fortschritt wird in deinem Konto gespeichert. Eine App für iOS und Android ist in Vorbereitung. Sie nutzt dasselbe Konto und denselben Lernstand.',
                        'note' => null],
                    ['ref' => 90, 'theme' => true, 'question' => 'Wann kann ich nach dem Kauf loslegen?',
                        'answer' => 'Sobald die Zahlung eingegangen ist, wird dein Zugang automatisch freigeschaltet. Bei Überweisung kann es bis zum Zahlungseingang dauern.',
                        'note' => null],
                    ['ref' => 97, 'theme' => false, 'question' => 'Erinnert ihr mich, bevor mein Zugang abläuft?',
                        'answer' => 'Ja. 14 Tage vor Ablauf bekommst du eine E-Mail, und in deinem Lernbereich erscheint ein Hinweis mit „Jetzt verlängern“. Kaufst du denselben Kurs erneut, verlängert sich dein Zugang.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung und Prüfungsablauf', 'slug' => 'allgemein-pruefung-und-pruefungsablauf', 'faqs' => [
                    ['ref' => 12, 'theme' => false, 'question' => 'Wo findet die praktische Ausbildung statt?',
                        'answer' => 'Fahrstunden auf dem Wasser bieten wir nicht an. Praxis bekommst du bei einer Bootsschule. Eine Ausbildung ist keine Zulassungsvoraussetzung. Für die praktische Prüfung brauchst du aber Fahrpraxis und ein geeignetes Prüfungsboot. Mehr dazu: {{link:/bootsfuehrerschein-standorte/|Praxis und Prüfung vor Ort planen}}.',
                        'note' => null],
                    ['ref' => 14, 'theme' => false, 'question' => 'Wo lege ich die amtliche Prüfung ab?',
                        'answer' => 'Bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl. Eine Zuständigkeit nach Wohnort gibt es nicht. Termine und Orte findest du auf sportbootfuehrerscheine.org. Für den SBF muss dein Antrag mit allen Unterlagen spätestens eine Woche vor dem Termin vorliegen, manche Ausschüsse verlangen mehr Vorlauf. Für SRC und UBI sind es 14 Tage.',
                        'note' => null],
                    ['ref' => 15, 'theme' => false, 'question' => 'Kann ich die amtliche Prüfung online ablegen?',
                        'answer' => 'Nein. Online lernst du die Theorie. Die amtliche Prüfung – Theorie und Praxis – legst du vor Ort bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl ab.',
                        'note' => null],
                    ['ref' => 16, 'theme' => false, 'question' => 'Was passiert, wenn ich die Prüfung nicht bestehe?',
                        'answer' => 'Dann kannst du die Prüfung wiederholen. Beim Sportbootführerschein werden Theorie und Praxis getrennt bewertet; einen nicht bestandenen Teil legst du erneut ab. Welche Fristen gelten und welche Gebühren dafür anfallen, sagt dir dein Prüfungsausschuss. Im Kurs hilft dir die Auswertung der Prüfungssimulation, gezielt die Fehler zu wiederholen.',
                        'note' => null],
                    ['ref' => 17, 'theme' => false, 'question' => 'Wann bekomme ich meinen Bootsführerschein?',
                        'answer' => 'Nach bestandener Prüfung erteilt der Prüfungsausschuss den Führerschein. Die Karte wird dir zugeschickt. Wie lange das dauert und ob du vorher einen Nachweis bekommst, sagt dir dein Prüfungsausschuss.',
                        'note' => null],
                    ['ref' => 18, 'theme' => false, 'question' => 'Wie lange ist mein Bootsführerschein gültig?',
                        'answer' => 'Der Sportbootführerschein ist unbefristet gültig. Er kann entzogen werden, wenn die Eignung oder Zuverlässigkeit fehlt. Eine Reform ist angekündigt; bereits erteilte Scheine sollen nach den bisherigen Ankündigungen gültig bleiben. Mehr dazu: {{link:/sportbootfuehrerschein/|Sportbootführerschein im Überblick}}.',
                        'note' => null],
                    ['ref' => 20, 'theme' => false, 'question' => 'Sind die Prüfungsgebühren im Kurspreis enthalten?',
                        'answer' => 'Nein. Die Prüfungsgebühr zahlst du direkt an den Prüfungsausschuss. In keinem unserer Kurse ist sie enthalten.',
                        'note' => null],
                    ['ref' => 92, 'theme' => true, 'question' => 'Ist die amtliche Prüfung im Kurs enthalten?',
                        'answer' => 'Nein. Der Kurs bereitet dich online vor. Die amtliche Prüfung legst du bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl ab; Anmeldung, Termin und Prüfungsgebühr laufen direkt über den Ausschuss.',
                        'note' => null],
                    ['ref' => 94, 'theme' => false, 'question' => 'Gilt mein Bootsführerschein nach der angekündigten Reform weiter?',
                        'answer' => 'Das Bundesverkehrsministerium hat eine Neuregelung der Sportbootführerscheine angekündigt: Künftig sollen anerkannte Scheine der Verbände den amtlichen Sportbootführerschein ersetzen. In Kraft ist sie nach unserem Stand (November 2026) noch nicht. Bereits erteilte Scheine sollen nach den bisherigen Ankündigungen gültig bleiben. Wir passen unsere Seiten an, sobald sich etwas ändert.',
                        'note' => null],
                ]],
                ['name' => 'Kosten, Gutscheine und Garantien', 'slug' => 'allgemein-kosten-gutscheine-und-garantien', 'faqs' => [
                    ['ref' => 19, 'theme' => false, 'question' => 'Was kostet der Kurs?',
                        'answer' => 'Den aktuellen Preis siehst du bei jedem Kurs auf der Kursseite und im Shop. Du zahlst einmal, ohne Abo. Die amtliche Prüfungsgebühr kommt hinzu. Sie ist bundesweit festgelegt und geht direkt an den Prüfungsausschuss. Mehr dazu: {{link:/bootsfuehrerschein-kosten/|Kosten des Bootsführerscheins}}.',
                        'note' => null],
                    ['ref' => 21, 'theme' => false, 'question' => 'Kann ich einen Kurs verschenken oder für jemand anderen kaufen?',
                        'answer' => 'Gutscheine bieten wir derzeit nicht an. Ein Kurs gehört immer zu dem Kundenkonto, mit dem er gekauft wurde, und ist nicht übertragbar. Wollt ihr gemeinsam lernen, kann eine Person ein Gruppenpaket für SBF See, SBF Binnen, die Kombi oder Funk kaufen und die andere einladen. Jede Person lernt dann mit eigenem Konto.',
                        'note' => null],
                    ['ref' => 22, 'theme' => true, 'question' => 'Gibt es eine Geld-zurück- oder Bestehensgarantie?',
                        'answer' => "Eine Bestehensgarantie gibt es nicht: Die Prüfung nimmt ein Prüfungsausschuss des DSV oder DMYV ab, und dein Ergebnis hängt von deiner Vorbereitung ab. Zusätzlich zu deinen gesetzlichen Rechten geben wir dir freiwillig eine Geld-zurück-Garantie:\n\n- **Frist:** 14 Tage ab Zahlungseingang, auf die Minute genau. Das Fristende siehst du unter „Mein Lernen“.\n- **Bedingung:** Du hast seit dem Zahlungseingang weniger als 20 % der gekauften Inhalte genutzt. Gezählt werden die seit dem Zahlungseingang beantworteten Prüfungsfragen der gekauften Kurse (im Smarttrainer oder in den Quizfragen der Video-Lektionen, jede Frage einmal) und die abgeschlossenen Video-Lektionen. Bei der Theorie-Stufe zählen nur Fragen, beim Video-Upgrade nur Lektionen, bei Komplett, Kombis, Bundle und Pässen beides. Mehrere Produkte einer Bestellung werden zusammen berechnet.\n- **Gilt für:** Einzelkurse (Theorie und Komplett), Video-Upgrades, Ergänzungskurse, Kombikurse, das Kapitäns-Bundle und die Pässe – auch soweit sie den Funkgerät-Simulator enthalten.\n- **Gilt nicht für:** Gruppenpakete, eigenständige Zugänge zum Funkgerät-Simulator (Simulator-Pässe und Simulator-Upgrade) und bereits ganz oder teilweise erstattete Produkte.\n- **So geht’s:** Unter „Mein Lernen“ auf „Geld zurück anfordern“ klicken; eine Anfrage je Bestellung, Bestätigung per E-Mail. Wir erstatten den gezahlten Preis der Produkte mit Garantie über deine ursprüngliche Zahlungsart. Produkte ohne Garantie bleiben nutzbar; der Zugang zu den erstatteten Kursen endet.\n- **Widerrufsrecht:** Die Garantie ist kein Widerrufsrecht. Bei digitalen Kursen erlischt das Widerrufsrecht, sobald dein Zugang nach deiner Zustimmung in der Kasse freigeschaltet ist.\n\nAlle Bedingungen: {{link:/geld-zurueck-garantie-bootsfuehrerschein/|Geld-zurück-Garantie}} und {{agb}}.",
                        'note' => null],
                    ['ref' => 99, 'theme' => false, 'question' => 'Gibt es Rabatte oder Ermäßigungen?',
                        'answer' => 'Rabattcodes und Ermäßigungen für bestimmte Personengruppen bieten wir derzeit nicht an. Sparen kannst du mit dem passenden Paket: Kombikurse, Kapitäns-Bundle, Pässe, Ergänzungskurse für Inhaber eines Scheins und Gruppenpakete. Die Ersparnis bei Kombis berechnet der Shop aus den aktuellen Einzelpreisen. Mehr dazu: {{link:/bootsfuehrerschein-rabatte/|Preise und Kombiangebote}}.',
                        'note' => null],
                ]],
                ['name' => 'Gruppenangebote', 'slug' => 'allgemein-gruppenangebote', 'faqs' => [
                    ['ref' => 91, 'theme' => true, 'question' => 'Wie funktionieren Gruppenpakete?',
                        'answer' => 'Eine Person kauft das Paket mit 2 bis 50 Plätzen und lädt die Teilnehmenden per Link oder E-Mail ein; ein Platz kann auch für sie selbst sein. Jede Person lernt mit eigenem Konto und eigenem Fortschritt. Den Lernstand der anderen sieht niemand. Die Laufzeit von 12 Monaten beginnt mit der Zahlung und endet für alle gleichzeitig. Für Gruppenpakete gilt die Geld-zurück-Garantie nicht. Mehr dazu: {{link:/bootsfuehrerschein-gruppenangebote/|Gruppenangebote}}.',
                        'note' => null],
                ]],
                ['name' => 'Kontakt und Support', 'slug' => 'allgemein-kontakt-und-support', 'faqs' => [
                    ['ref' => 23, 'theme' => false, 'question' => 'Wie erreiche ich euch bei weiteren Fragen?',
                        'answer' => 'Alle Kontaktwege findest du auf unserer {{link:/kontakt/|Kontaktseite}}. Nenne am besten den Kurs und, bei Fragen zu einer Bestellung, die Bestellnummer. Fragen zu Prüfungsterminen und Gebühren beantwortet dein Prüfungsausschuss.',
                        'note' => 'erst veröffentlichen, wenn die Kontaktseite mit den Betreiberangaben live ist.'],
                ]],
            ],
        ],
        [
            'key' => 'SBF-SEE', 'name' => 'SBF See', 'slug' => 'sbf-see',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'sbf-see-kursinhalt', 'faqs' => [
                    ['ref' => 24, 'theme' => true, 'question' => 'Was lerne ich im SBF-See-Kurs?',
                        'answer' => 'Du übst alle Prüfungsfragen des SBF See im Smarttrainer und simulierst die Prüfung mit Zeitlimit. Bei den Navigationsaufgaben werden deine Werte automatisch geprüft. Praxiskarten bereiten dich auf die praktische Prüfung vor. In der Stufe „Komplett“ kommt der Videokurs dazu.',
                        'note' => null],
                    ['ref' => 30, 'theme' => true, 'question' => 'Ist eine praktische Ausbildung auf dem Boot im Onlinekurs enthalten?',
                        'answer' => 'Der Kurs bereitet dich online auf die Theorie und die Prüfungsinhalte vor. Praxiskarten unterstützen dich beim Lernen; tatsächliche Fahrstunden auf dem Wasser sind nicht Bestandteil des Onlinekurses.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'sbf-see-lernen', 'faqs' => [
                    ['ref' => 27, 'theme' => false, 'question' => 'Wie gehe ich bei Navigationsaufgaben am besten vor?',
                        'answer' => 'Lies zuerst die Angaben genau, markiere die relevanten Informationen auf der Karte und rechne Schritt für Schritt. Im Kurs werden deine Werte automatisch geprüft, danach vergleichst du deinen Weg mit der Musterlösung.',
                        'note' => null],
                    ['ref' => 28, 'theme' => false, 'question' => 'Was mache ich, wenn ich eine Prüfungsfrage falsch beantworte?',
                        'answer' => 'Schau dir die Erklärung zur Frage an und überlege, welcher Gedanke dich zur falschen Antwort geführt hat. Wiederhole die Frage später noch einmal, statt nur die richtige Antwort auswendig zu lernen.',
                        'note' => null],
                    ['ref' => 29, 'theme' => false, 'question' => 'Wie gehe ich mit einer unsicheren Antwort bei einer Navigationsaufgabe um?',
                        'answer' => 'Arbeite die Angaben systematisch durch und kontrolliere jeden Rechenschritt. Übe mit den Musterlösungen, damit du deinen Lösungsweg nachvollziehen und mögliche Fehlerstellen erkennen kannst.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'sbf-see-lernmaterial', 'faqs' => [
                    ['ref' => 25, 'theme' => true, 'question' => 'Brauche ich für die Navigationsaufgaben eine Seekarte?',
                        'answer' => 'Ja. Für die Navigationsaufgaben brauchst du die gedruckte Übungsseekarte D49 sowie Kursdreieck und Zirkel. Die Karte besorgst du dir selbst, wir verkaufen sie nicht. Im Kurs werden deine Werte automatisch geprüft, du siehst die Musterlösung und kannst dich selbst einschätzen.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'sbf-see-pruefung', 'faqs' => [
                    ['ref' => 26, 'theme' => true, 'question' => 'Kann ich die SBF-See-Prüfung mit dem Kurs simulieren?',
                        'answer' => 'Ja. Die Prüfungssimulation übt den Ablauf mit Zeitlimit und anschließender Auswertung.',
                        'note' => null],
                    ['ref' => 31, 'theme' => false, 'question' => 'Gilt meine Kursbuchung auch als Anmeldung zur SBF-See-Prüfung?',
                        'answer' => 'Nein. Die Kursbuchung gibt dir Zugang zur Vorbereitung. Für die amtliche Prüfung meldest du dich separat bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl an.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SBF-BIN-MOTOR', 'name' => 'SBF Binnen – Antriebsmaschine', 'slug' => 'sbf-bin-motor',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'sbf-bin-motor-kursinhalt', 'faqs' => [
                    ['ref' => 32, 'theme' => true, 'question' => 'Welche Inhalte deckt der SBF-Binnen-Kurs unter Antriebsmaschine ab?',
                        'answer' => 'Der Kurs bereitet auf den SBF Binnen unter Antriebsmaschine vor. Du übst Fragen im Smarttrainer und vertiefst Zeichen, Lichter und Manöver mit Praxiskarten.',
                        'note' => null],
                    ['ref' => 33, 'theme' => true, 'question' => 'Ist der Segelteil im SBF-Binnen-Kurs enthalten?',
                        'answer' => 'Der hier beschriebene Kurs bereitet auf den SBF Binnen unter Antriebsmaschine vor. Für die zusätzlichen Segelfragen und Segelmanöver gibt es einen eigenen Kurs für den SBF Binnen unter Segel.',
                        'note' => null],
                    ['ref' => 37, 'theme' => true, 'question' => 'Enthält der Onlinekurs Fahrstunden auf dem Wasser?',
                        'answer' => 'Nein. Der Kurs ist für die Onlinevorbereitung gedacht. Praxiskarten helfen dir beim Wiederholen der praktischen Inhalte; Fahrstunden auf dem Wasser sind separat zu organisieren.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'sbf-bin-motor-lernen', 'faqs' => [
                    ['ref' => 36, 'theme' => false, 'question' => 'Soll ich alle Binnenfragen gleich oft wiederholen?',
                        'answer' => 'Konzentriere dich zuerst auf Fragen, bei denen du unsicher bist oder Fehler machst. Wenn diese sicher sitzen, kannst du dein Wissen mit gemischten Übungsrunden festigen.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'sbf-bin-motor-lernmaterial', 'faqs' => [
                    ['ref' => 35, 'theme' => false, 'question' => 'Wie kann ich Schifffahrtszeichen und Lichter besser auseinanderhalten?',
                        'answer' => 'Übe sie in kleinen Gruppen und achte auf die Merkmale, die sie unterscheiden. Die Praxiskarten helfen dir, Zeichen und Lichter wiederholt anzusehen und sicherer zuzuordnen.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'sbf-bin-motor-pruefung', 'faqs' => [
                    ['ref' => 34, 'theme' => true, 'question' => 'Gibt es eine Prüfungssimulation für den SBF Binnen?',
                        'answer' => 'Ja. Du kannst mit Zeitlimit üben und erhältst nach dem Durchgang eine Auswertung.',
                        'note' => null],
                    ['ref' => 38, 'theme' => false, 'question' => 'Welche Unterlagen muss ich zur SBF-Binnen-Prüfung mitbringen?',
                        'answer' => "Zur Anmeldung brauchst du:\n\n- den unterschriebenen Antrag;\n- ein Passbild;\n- den ärztlichen Tauglichkeitsnachweis (höchstens zwölf Monate alt);\n- ab 18 Jahren eine Kopie deines Kfz-Führerscheins oder ein Führungszeugnis;\n- als Minderjährige oder Minderjähriger die Zustimmung deiner Eltern.\n\nAm Prüfungstag bringst du deinen Ausweis und deinen Führerschein im Original mit. Ob dein Prüfungsausschuss weitere Unterlagen verlangt, erfährst du dort.",
                        'note' => null],
                    ['ref' => 39, 'theme' => false, 'question' => 'Kann ich einzelne Prüfungsteile an verschiedenen Tagen ablegen?',
                        'answer' => 'Das ist möglich. Theorie und Praxis können am selben Tag oder an verschiedenen Tagen stattfinden; das legt dein Prüfungsausschuss fest. Kläre bei der Anmeldung, welche Prüfungsteile für deinen Termin vorgesehen sind.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SBF-BIN-SEGEL', 'name' => 'SBF Binnen unter Segel', 'slug' => 'sbf-bin-segel',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'sbf-bin-segel-kursinhalt', 'faqs' => [
                    ['ref' => 40, 'theme' => true, 'question' => 'Was kommt im SBF-Binnen-Segelkurs zusätzlich dazu?',
                        'answer' => 'Der Kurs verbindet die Binnenfragen mit den zusätzlichen Segelfragen. Außerdem übst du Segelmanöver sowie Themen wie Wind und Trimm.',
                        'note' => null],
                    ['ref' => 41, 'theme' => false, 'question' => 'Was bedeutet „Trimm“ beim Segeln?',
                        'answer' => 'Trimm beschreibt, wie die Segel auf Wind und Kurs eingestellt sind. Im Kurs kannst du die Grundlagen dazu zusammen mit den Themen Wind und Segelmanöver wiederholen.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'sbf-bin-segel-lernen', 'faqs' => [
                    ['ref' => 42, 'theme' => true, 'question' => 'Kann ich die Segelfragen gezielt wiederholen?',
                        'answer' => 'Ja. Die Fragen lassen sich im Smarttrainer üben und gezielt wiederholen.',
                        'note' => null],
                    ['ref' => 44, 'theme' => false, 'question' => 'Wie kann ich mir Segelmanöver besser merken?',
                        'answer' => 'Lerne die Abläufe in einzelnen Schritten und verbinde jeden Schritt mit dem Ziel des Manövers. Anschließend kannst du die Reihenfolge mit den Praxiskarten wiederholen.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'sbf-bin-segel-lernmaterial', 'faqs' => [
                    ['ref' => 43, 'theme' => true, 'question' => 'Wie bereite ich mich auf den praktischen Segelteil vor?',
                        'answer' => 'Praxiskarten helfen dir, Manöver und wichtige Abläufe zu wiederholen. Die praktische Ausbildung auf dem Wasser ist nicht Teil des Onlinekurses.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'sbf-bin-segel-pruefung', 'faqs' => [
                    ['ref' => 45, 'theme' => false, 'question' => 'Ist die Segelprüfung ein eigener Prüfungsteil?',
                        'answer' => 'Teilweise. Im Fragebogen kommen zu den Basis- und Binnen-Fragen 7 Segelfragen dazu, und die praktische Prüfung findet unter Segel statt. Hast du den SBF Binnen unter Antriebsmaschine schon, legst du nur die Segelfragen und eine praktische Prüfung unter Segel ab. Dafür gibt es den Ergänzungskurs Binnen → Segeln.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SRC', 'name' => 'SRC – Seefunkzeugnis', 'slug' => 'src',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'src-kursinhalt', 'faqs' => [
                    ['ref' => 46, 'theme' => true, 'question' => 'Was lerne ich im SRC-Kurs?',
                        'answer' => 'Der Kurs behandelt die Prüfungsfragen zum Seefunkzeugnis SRC. Zusätzlich übst du englische Funktexte, Vokabeln und das Buchstabieralphabet.',
                        'note' => null],
                    ['ref' => 47, 'theme' => true, 'question' => 'Was ist der Unterschied zwischen SRC und UBI?',
                        'answer' => 'Das SRC ist das Funkzeugnis für den Seefunk. Das UBI behandelt den Binnenfunk. Für beide Zeugnisse gibt es eigene Lerninhalte und Prüfungsvorbereitungen.',
                        'note' => null],
                    ['ref' => 48, 'theme' => false, 'question' => 'Wofür brauche ich die Buchstabiertafel im Seefunk?',
                        'answer' => 'Mit der Buchstabiertafel lassen sich Buchstaben am Funk eindeutig übermitteln. Sie hilft dir, beispielsweise Namen oder Kennungen klar und verständlich zu buchstabieren.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'src-lernen', 'faqs' => [
                    ['ref' => 50, 'theme' => false, 'question' => 'Wie bereite ich mich auf englische Funktexte vor?',
                        'answer' => 'Übe regelmäßig mit den Funktexten und achte darauf, Schlüsselwörter und feststehende Formulierungen sicher zu erkennen. Wiederhole anschließend die Stellen, bei denen du beim Verstehen oder Eingeben unsicher warst.',
                        'note' => null],
                ]],
                ['name' => 'Funkübungen und Simulator', 'slug' => 'src-funkuebungen-und-simulator', 'faqs' => [
                    ['ref' => 49, 'theme' => true, 'question' => 'Kann ich Funktexte für die SRC-Prüfung üben?',
                        'answer' => 'Ja. Die englischen Funktexte werden mit einer Auswertung geübt, damit du deine Eingaben nachvollziehen kannst.',
                        'note' => null],
                    ['ref' => 51, 'theme' => true, 'question' => 'Gibt es im SRC-Kurs einen Funkgerät-Simulator?',
                        'answer' => "Im SRC-Kurs ist die kostenlose Testversion enthalten: Gerät einschalten, Kanäle wählen und einen Routineanruf üben. Die Vollversion mit Notalarm, Prüfungsmodus und freiem Üben bekommst du auf zwei Wegen:\n\n- beim Kauf eines Funkkurses als Upgrade in der Kasse;\n- als eigenen Simulator-Zugang für 14 Tage, 3 oder 12 Monate.\n\nIm Kapitäns-Bundle und in den Pässen ist die Vollversion schon enthalten.",
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'src-pruefung', 'faqs' => [
                    ['ref' => 52, 'theme' => false, 'question' => 'Welches Funkgerät wird in meiner praktischen SRC-Prüfung verwendet?',
                        'answer' => 'Das hängt vom Prüfungsausschuss ab. Frag dort vor deinem Termin nach dem verwendeten Gerät oder Simulator, damit du dich gezielt vorbereiten kannst.',
                        'note' => null],
                    ['ref' => 53, 'theme' => false, 'question' => 'Ist der Funkgerät-Simulator genau wie das Prüfungsgerät?',
                        'answer' => 'Ein Simulator hilft dir, typische Bedienabläufe zu üben. Die tatsächliche Ausstattung kann je nach Prüfungsausschuss abweichen. Erkundige dich zusätzlich nach dem Gerät für deine Prüfung.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'UBI', 'name' => 'UBI – Binnenfunkzeugnis', 'slug' => 'ubi',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'ubi-kursinhalt', 'faqs' => [
                    ['ref' => 54, 'theme' => true, 'question' => 'Was lerne ich im UBI-Kurs?',
                        'answer' => 'Der Kurs bereitet auf das UKW-Sprechfunkzeugnis für den Binnenfunk vor. Er umfasst Prüfungsfragen, Funktexte und Praxisszenarien.',
                        'note' => null],
                    ['ref' => 55, 'theme' => true, 'question' => 'Ist das UBI dasselbe wie das SRC?',
                        'answer' => 'Nein. Das UBI bezieht sich auf den Binnenfunk, das SRC auf den Seefunk. Die Prüfungsinhalte unterscheiden sich entsprechend.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'ubi-lernen', 'faqs' => [
                    ['ref' => 59, 'theme' => true, 'question' => 'Gibt es eine Prüfungssimulation für das UBI?',
                        'answer' => 'Ja. Mit der Simulation kannst du die Vorbereitung unter Zeitvorgabe üben und deine Antworten anschließend auswerten.',
                        'note' => null],
                ]],
                ['name' => 'Funkübungen', 'slug' => 'ubi-funkuebungen', 'faqs' => [
                    ['ref' => 56, 'theme' => true, 'question' => 'Kann ich Textaufnahme und Textabgabe im UBI-Kurs üben?',
                        'answer' => 'Ja. Der Kurs enthält Übungen zu Funktexten für die Textaufnahme und Textabgabe.',
                        'note' => null],
                    ['ref' => 57, 'theme' => false, 'question' => 'Wie übe ich Funktexte für die Aufnahme und Abgabe?',
                        'answer' => 'Trainiere beide Aufgabenarten getrennt. So kannst du dich zunächst auf das Verstehen eines Textes und danach auf die strukturierte Wiedergabe konzentrieren.',
                        'note' => null],
                    ['ref' => 58, 'theme' => false, 'question' => 'Wie bereite ich mich auf UBI-Praxisszenarien vor?',
                        'answer' => 'Gehe jedes Szenario Schritt für Schritt durch und achte darauf, welche Information jeweils übermittelt werden soll. Wiederhole anschließend die Szenarien, bei denen dir Ablauf oder Formulierungen noch schwerfallen.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'ubi-pruefung', 'faqs' => [
                    ['ref' => 60, 'theme' => false, 'question' => 'Was mache ich, wenn sich der UBI-Fragenkatalog vor meinem Prüfungstermin ändert?',
                        'answer' => 'Prüfe, welcher Fragenstand für deinen Prüfungstermin gilt. Vergleiche den Kursstand mit den aktuellen Informationen deines Prüfungsausschusses und wiederhole gegebenenfalls geänderte Inhalte.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SKN', 'name' => 'SKN – Sachkundenachweis für Seenotsignalmittel', 'slug' => 'skn',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'skn-kursinhalt', 'faqs' => [
                    ['ref' => 61, 'theme' => true, 'question' => 'Worum geht es im SKN-Kurs?',
                        'answer' => 'Der Kurs bereitet auf den Sachkundenachweis für Seenotsignalmittel vor. Du übst Fragen und wiederholst den Umgang mit Signalmitteln anhand von Praxiskarten.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'skn-lernen', 'faqs' => [
                    ['ref' => 63, 'theme' => true, 'question' => 'Wie kann ich meine SKN-Prüfungsvorbereitung überprüfen?',
                        'answer' => 'Der Smarttrainer hilft dir, Fragen zu wiederholen und unsichere Themen erneut zu üben.',
                        'note' => null],
                    ['ref' => 64, 'theme' => false, 'question' => 'Wie kann ich die verschiedenen Seenotsignalmittel lernen?',
                        'answer' => 'Lerne die Mittel einzeln und ordne jedem die zugehörigen Merkmale und Hinweise aus dem Kurs zu. Mit den Praxiskarten kannst du dein Wissen regelmäßig überprüfen.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'skn-lernmaterial', 'faqs' => [
                    ['ref' => 62, 'theme' => true, 'question' => 'Was trainieren die SKN-Praxiskarten?',
                        'answer' => 'Die Praxiskarten unterstützen dich beim Erkennen und bei der sicheren Handhabung der behandelten Seenotsignalmittel.',
                        'note' => null],
                ]],
                ['name' => 'Sicherheit und Prüfung', 'slug' => 'skn-sicherheit-und-pruefung', 'faqs' => [
                    ['ref' => 65, 'theme' => false, 'question' => 'Was sollte ich bei Fragen zur sicheren Handhabung beachten?',
                        'answer' => 'Orientiere dich an den Kursinhalten und den offiziellen Sicherheitshinweisen. Praktische Handhabung solltest du nur im vorgesehenen Rahmen und unter geeigneter Aufsicht üben.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'FKN', 'name' => 'FKN – Fachkundenachweis für Seenotsignalmittel', 'slug' => 'fkn',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'fkn-kursinhalt', 'faqs' => [
                    ['ref' => 66, 'theme' => true, 'question' => 'Was behandelt der FKN-Kurs?',
                        'answer' => 'Der Kurs bereitet auf den Fachkundenachweis für Seenotsignalmittel vor. Er umfasst Prüfungsfragen, Praxiskarten und eine Prüfungssimulation.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'fkn-lernen', 'faqs' => [
                    ['ref' => 68, 'theme' => true, 'question' => 'Kann ich einzelne FKN-Fragen mehrfach üben?',
                        'answer' => 'Ja. Im Smarttrainer kannst du Fragen wiederholen und dich erneut mit Themen beschäftigen, bei denen du noch unsicher bist.',
                        'note' => null],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'fkn-lernmaterial', 'faqs' => [
                    ['ref' => 67, 'theme' => true, 'question' => 'Wie kann ich die Handhabung der Signalmittel wiederholen?',
                        'answer' => 'Mit den Praxiskarten kannst du das Erkennen und die Handhabung der behandelten Signalmittel wiederholen.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung und Fragenkatalog', 'slug' => 'fkn-pruefung-und-fragenkatalog', 'faqs' => [
                    ['ref' => 69, 'theme' => false, 'question' => 'Welchen Fragenkatalog soll ich für die FKN-Prüfung lernen?',
                        'answer' => 'Nutze die Unterlagen, die zu deinem Prüfungsausschuss passen. Beim FKN können sich Fragenkataloge je nach Verband unterscheiden; kläre vorab, ob du die DSV- oder DMYV-Unterlagen brauchst.',
                        'note' => null],
                ]],
                ['name' => 'Sicherheit', 'slug' => 'fkn-sicherheit', 'faqs' => [
                    ['ref' => 70, 'theme' => false, 'question' => 'Kann ich die praktische Prüfung zu Seenotsignalmitteln allein üben?',
                        'answer' => 'Für praktische Übungen gelten besondere Sicherheitsregeln. Übe den Umgang mit Signalmitteln nur in einem geeigneten Rahmen und nach den Anweisungen deiner Ausbildungs- oder Prüfungsstelle.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SBF-SEE-BIN', 'name' => 'Kombikurs SBF See & SBF Binnen', 'slug' => 'sbf-see-bin',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'sbf-see-bin-kursinhalt', 'faqs' => [
                    ['ref' => 71, 'theme' => true, 'question' => 'Welche Scheine umfasst der Kombikurs See und Binnen?',
                        'answer' => 'Der Kombikurs verbindet die Vorbereitung auf den SBF See und den SBF Binnen. Die Lerninhalte beider Scheine sind im Kurs zusammengeführt.',
                        'note' => null],
                    ['ref' => 72, 'theme' => true, 'question' => 'Sind die Fragen für SBF See und SBF Binnen enthalten?',
                        'answer' => 'Ja. Du kannst die im Kurs enthaltenen Fragen für See und Binnen üben. Zusätzlich umfasst die Vorbereitung Navigationsaufgaben, Praxiskarten und Prüfungssimulationen.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'sbf-see-bin-lernen', 'faqs' => [
                    ['ref' => 73, 'theme' => false, 'question' => 'Wie behalte ich beim Kombikurs den Überblick über zwei Scheine?',
                        'answer' => 'Lerne die Inhalte zunächst getrennt und notiere dir, welche Themen zu welchem Schein gehören. So kannst du gezielt wiederholen und anschließend beide Bereiche im Wechsel trainieren.',
                        'note' => null],
                    ['ref' => 74, 'theme' => false, 'question' => 'In welcher Reihenfolge sollte ich die Inhalte des Kombikurses lernen?',
                        'answer' => 'Eine mögliche Reihenfolge ist: zunächst die Grundlagen bearbeiten, danach die See- und Binneninhalte getrennt üben und zum Schluss gemischte Prüfungssimulationen durchführen.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'sbf-see-bin-pruefung', 'faqs' => [
                    ['ref' => 75, 'theme' => true, 'question' => 'Muss ich beide Prüfungen gleichzeitig ablegen?',
                        'answer' => 'Nein. Du kannst SBF See und SBF Binnen zusammen oder nacheinander ablegen. Bei einer gemeinsamen Prüfung beantwortest du die Basisfragen nur einmal. Den Termin stimmst du mit deinem Prüfungsausschuss ab.',
                        'note' => null],
                    ['ref' => 76, 'theme' => false, 'question' => 'Kann ich die Prüfungen für den Kombikurs an unterschiedlichen Terminen ablegen?',
                        'answer' => 'Die Prüfungstermine planst du mit deinem Prüfungsausschuss. Frag dort nach, ob getrennte Termine möglich sind.',
                        'note' => null],
                    ['ref' => 77, 'theme' => false, 'question' => 'Was gilt, wenn ich bei einem Kombikurs nur eine Prüfung bestehe?',
                        'answer' => 'SBF See und SBF Binnen werden getrennt erteilt. Bestehst du nur eine Prüfung, bekommst du diesen Schein und kannst die andere wiederholen. Ob ein bestandener Teil angerechnet wird und bis wann du wiederholen musst, sagt dir dein Prüfungsausschuss.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'KAPITAEN', 'name' => 'Kapitäns-Bundle', 'slug' => 'kapitaen',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'kapitaen-kursinhalt', 'faqs' => [
                    ['ref' => 78, 'theme' => true, 'question' => 'Welche Kurse gehören zum Kapitäns-Bundle?',
                        'answer' => 'Das Bundle bündelt die Vorbereitung auf SBF See, SBF Binnen, SRC und UBI. Die jeweiligen Fragen und Lernbereiche sind im Paket enthalten. Die Vollversion des Funkgerät-Simulators ist enthalten.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'kapitaen-lernen', 'faqs' => [
                    ['ref' => 80, 'theme' => true, 'question' => 'Kann ich die Scheine im Kapitäns-Bundle in meinem eigenen Tempo lernen?',
                        'answer' => 'Ja. Du lernst online und kannst zwischen den Themen und Kursbereichen wechseln. Dein Lernfortschritt wird in deinem Konto gespeichert.',
                        'note' => null],
                    ['ref' => 81, 'theme' => false, 'question' => 'Wie teile ich meine Lernzeit auf die vier Scheine auf?',
                        'answer' => 'Plane feste Lernabschnitte für SBF See, SBF Binnen, SRC und UBI ein. Beginne mit einem überschaubaren Lernplan und passe ihn daran an, welche Themen dir noch schwerfallen.',
                        'note' => null],
                    ['ref' => 82, 'theme' => false, 'question' => 'Kann ich zwischen den Lernbereichen wechseln?',
                        'answer' => 'Ja. Du kannst die verschiedenen Kursbereiche in deinem eigenen Lernablauf nutzen. Es kann helfen, jeweils ein Thema abzuschließen, bevor du zum nächsten wechselst.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'kapitaen-pruefung', 'faqs' => [
                    ['ref' => 79, 'theme' => true, 'question' => 'Sind amtliche Prüfungen im Kapitäns-Bundle enthalten?',
                        'answer' => 'Nein. Das Bundle umfasst Lerninhalte und Prüfungsvorbereitung für SBF See, SBF Binnen, SRC und UBI. Die amtlichen Prüfungen legst du bei einem Prüfungsausschuss deiner Wahl ab; Anmeldung, Termin und Gebühr laufen direkt über den Ausschuss.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SRC-UBI', 'name' => 'Kombikurs SRC & UBI', 'slug' => 'src-ubi',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'src-ubi-kursinhalt', 'faqs' => [
                    ['ref' => 83, 'theme' => true, 'question' => 'Welche Funkzeugnisse umfasst der Kombikurs SRC und UBI?',
                        'answer' => 'Der Kurs verbindet die Vorbereitung auf SRC für den Seefunk und UBI für den Binnenfunk.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'src-ubi-lernen', 'faqs' => [
                    ['ref' => 86, 'theme' => false, 'question' => 'Wie kann ich Seefunk und Binnenfunk beim Lernen auseinanderhalten?',
                        'answer' => 'Übe die Inhalte zunächst in getrennten Lernblöcken und achte darauf, ob du gerade für SRC oder UBI lernst. Danach kannst du mit gemischten Einheiten prüfen, ob du die Bereiche sicher zuordnen kannst.',
                        'note' => null],
                    ['ref' => 87, 'theme' => false, 'question' => 'Was ist eine gute Übungsroutine für SRC und UBI?',
                        'answer' => 'Wechsle zwischen Prüfungsfragen, Funktexten und Praxisszenarien. So trainierst du nicht nur einzelne Antworten, sondern auch die Anwendung des Gelernten.',
                        'note' => null],
                ]],
                ['name' => 'Funkübungen und Simulator', 'slug' => 'src-ubi-funkuebungen-und-simulator', 'faqs' => [
                    ['ref' => 84, 'theme' => true, 'question' => 'Welche Funkübungen sind im Kombikurs enthalten?',
                        'answer' => 'Je nach Funkzeugnis übst du englische oder deutsche Funktexte, Vokabeln, die Buchstabiertafel und Praxisszenarien.',
                        'note' => null],
                    ['ref' => 85, 'theme' => true, 'question' => 'Ist der Funkgerät-Simulator im Kombikurs enthalten?',
                        'answer' => 'Im Kombikurs ist die Testversion enthalten. Die Vollversion buchst du als Upgrade dazu – im Kapitäns-Bundle und in den Pässen ist sie schon enthalten.'],
                ]],
            ],
        ],
    ];
}
