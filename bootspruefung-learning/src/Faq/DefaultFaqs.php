<?php

declare(strict_types=1);

namespace Bootspruefung\Learning\Faq;

/**
 * Standard-FAQs (Quelle: „Alle_FAQs_Learnboating.txt“, FAQ-Entwurf vom 10.11.2026), wörtlich übernommen.
 *
 * Aufbau: Rubrik (oberste Kategorie, Schlüssel = Kurscode bzw. „allgemein“) → Unterkategorie → FAQ.
 * ref = Nummer aus der Datei, theme = ★ (auch im Theme), note = Redaktionshinweis (nur im Admin).
 * {{agb}} wird beim Ausgeben zum Link auf die AGB-Seite (FaqRepository::replaceTokens).
 * Reihenfolge der Einträge = Reihenfolge in der Datei. Nur lesen – Änderungen macht der Betreiber im Admin.
 */
final class DefaultFaqs
{
    /** @var list<array{key:string,name:string,slug:string,subcategories:list<array{name:string,slug:string,faqs:list<array{ref:int,theme:bool,question:string,answer:string,note:?string}>}>}> */
    public const RUBRICS = [
        [
            'key' => 'allgemein', 'name' => 'Allgemeine Fragen', 'slug' => 'allgemein',
            'subcategories' => [
                ['name' => 'Scheinwahl und Geltungsbereich', 'slug' => 'allgemein-scheinwahl-und-geltungsbereich', 'faqs' => [
                    ['ref' => 1, 'theme' => false, 'question' => 'Welchen Bootsführerschein brauche ich?',
                        'answer' => 'Das hängt davon ab, wo du fahren möchtest und welches Boot du führen willst. Für Binnengewässer und Seeschifffahrtsstraßen gelten unterschiedliche Regelungen. Beschreibe uns dein Vorhaben – wir helfen dir, den passenden Schein zu finden.',
                        'note' => null],
                    ['ref' => 2, 'theme' => false, 'question' => 'Was ist der Unterschied zwischen SBF Binnen und SBF See?',
                        'answer' => 'Der SBF Binnen gilt für bestimmte Binnengewässer, der SBF See für Seeschifffahrtsstraßen. Welcher Schein für deine geplante Fahrt nötig ist, hängt auch vom Gewässer und vom Boot ab.',
                        'note' => null],
                    ['ref' => 3, 'theme' => false, 'question' => 'Kann ich mit meinem Bootsführerschein auch im Ausland fahren?',
                        'answer' => 'Das richtet sich nach dem jeweiligen Land und Gewässer. Informiere dich vor der Fahrt über die örtlichen Vorschriften und darüber, ob dein Führerschein dort anerkannt wird.',
                        'note' => null],
                ]],
                ['name' => 'Voraussetzungen', 'slug' => 'allgemein-voraussetzungen', 'faqs' => [
                    ['ref' => 4, 'theme' => false, 'question' => 'Welche Voraussetzungen muss ich für einen Bootsführerschein erfüllen?',
                        'answer' => 'Die Voraussetzungen hängen vom angestrebten Schein ab. Dazu können ein Mindestalter, ein ärztlicher Eignungsnachweis und eine bestandene Prüfung gehören. Prüfe die jeweils geltenden Bedingungen vor der Anmeldung.',
                        'note' => null],
                    ['ref' => 5, 'theme' => false, 'question' => 'Wie alt muss ich für den Sportbootführerschein sein?',
                        'answer' => 'Das Mindestalter hängt von Schein und Antriebsart ab. Für viele Prüfungen gilt ein Mindestalter von 16 Jahren; für bestimmte Segelberechtigungen auf Binnengewässern kann ein niedrigeres Alter gelten. Prüfe die Vorgaben für deinen konkreten Prüfungsgang.',
                        'note' => null],
                    ['ref' => 6, 'theme' => false, 'question' => 'Brauche ich Vorkenntnisse, bevor ich mit dem Kurs beginne?',
                        'answer' => 'In der Regel kannst du ohne Vorkenntnisse starten. Im Kurs werden die Grundlagen Schritt für Schritt erklärt. Für einzelne Scheine oder Prüfungsteile können besondere Voraussetzungen gelten.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'allgemein-lernen', 'faqs' => [
                    ['ref' => 7, 'theme' => false, 'question' => 'Kann ich mich online auf die Prüfung vorbereiten?',
                        'answer' => 'Ja. Mit einem Onlinekurs kannst du den Theorieteil flexibel und im eigenen Tempo lernen. Ob Praxisstunden oder einzelne Termine vor Ort dazugehören, hängt vom gebuchten Kurs ab.',
                        'note' => null],
                    ['ref' => 8, 'theme' => false, 'question' => 'Wie lange dauert die Vorbereitung auf die Prüfung?',
                        'answer' => 'Das ist individuell und hängt vom Schein, deinem Vorwissen und deiner Lernzeit ab. Plane genügend Zeit ein, um die Theorie zu verstehen und Fragen regelmäßig zu wiederholen.',
                        'note' => null],
                ]],
                ['name' => 'Kursinhalt und Leistungen', 'slug' => 'allgemein-kursinhalt-und-leistungen', 'faqs' => [
                    ['ref' => 9, 'theme' => false, 'question' => 'Was ist im Kurspreis enthalten?',
                        'answer' => 'Das hängt vom gebuchten Angebot ab. Typische Bestandteile können Lernmaterialien, ein Fragentrainer oder Praxiseinheiten sein. Die konkreten Leistungen findest du in der Kursbeschreibung.',
                        'note' => 'enthaltene Leistungen und Ausschlüsse ergänzen.'],
                ]],
                ['name' => 'Lernmaterial', 'slug' => 'allgemein-lernmaterial', 'faqs' => [
                    ['ref' => 11, 'theme' => false, 'question' => 'Gibt es einen Lernmittel-Shop?',
                        'answer' => 'Zusätzliche Lernmaterialien findest du im Lernmittel-Shop: [Link zum Shop].',
                        'note' => 'Antwort und Frage entfernen, falls kein Shop angeboten wird.'],
                ]],
                ['name' => 'Lernplattform und Zugang', 'slug' => 'allgemein-lernplattform-und-zugang', 'faqs' => [
                    ['ref' => 10, 'theme' => true, 'question' => 'Wie lange habe ich Zugriff auf die Lernplattform?',
                        'answer' => 'Je nach Kurs gilt der Zugang 12 oder 6 Monate ab Zahlungseingang. Pässe laufen 30 oder 90 Tage; Zugänge zum Funkgerät-Simulator 14 Tage, 3 oder 12 Monate. Die genaue Laufzeit steht beim jeweiligen Produkt. Wenn du denselben Kurs erneut kaufst, verlängert sich dein Zugang.',
                        'note' => null],
                    ['ref' => 88, 'theme' => true, 'question' => 'Kann ich den Kurs vor dem Kauf ausprobieren?',
                        'answer' => 'Ja. Mit einem kostenlosen Kundenkonto kannst du in jedem Kurs ausgewählte Inhalte testen, zum Beispiel einige Fragen je Thema und einen Prüfungsbogen.',
                        'note' => null],
                    ['ref' => 89, 'theme' => true, 'question' => 'Brauche ich eine App, um zu lernen?',
                        'answer' => 'Nein. Du lernst im Browser am Rechner, Tablet oder Smartphone. Dein Fortschritt wird in deinem Konto gespeichert.',
                        'note' => null],
                    ['ref' => 90, 'theme' => true, 'question' => 'Wann kann ich nach dem Kauf loslegen?',
                        'answer' => 'Sobald die Zahlung eingegangen ist, wird dein Zugang automatisch freigeschaltet. Bei Überweisung kann es bis zum Zahlungseingang dauern.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung und Prüfungsablauf', 'slug' => 'allgemein-pruefung-und-pruefungsablauf', 'faqs' => [
                    ['ref' => 12, 'theme' => false, 'question' => 'Wo findet die praktische Ausbildung statt?',
                        'answer' => 'Die Praxisausbildung findet an den angebotenen Standorten statt. Welche Orte verfügbar sind, siehst du in der Standortübersicht. [Link zu den Standorten ergänzen.]',
                        'note' => null],
                    ['ref' => 13, 'theme' => false, 'question' => 'Wie buche ich eine Praxisstunde?',
                        'answer' => 'Wähle einen verfügbaren Standort und Termin über [Buchungsweg ergänzen]. Wenn du Unterstützung brauchst, hilft dir unser Team unter [Kontakt ergänzen] weiter.',
                        'note' => null],
                    ['ref' => 14, 'theme' => false, 'question' => 'Wo lege ich die amtliche Prüfung ab?',
                        'answer' => 'Die Prüfung findet bei einem zuständigen Prüfungsausschuss statt. Welche Termine und Orte verfügbar sind, hängt vom Ausschuss ab. [Prüfungsanmeldung und zuständige Stellen ergänzen.]',
                        'note' => null],
                    ['ref' => 15, 'theme' => false, 'question' => 'Kann ich die amtliche Prüfung online ablegen?',
                        'answer' => 'Die Theorie kannst du online lernen. Die amtliche Prüfung selbst findet nach den geltenden Vorgaben des zuständigen Prüfungsausschusses statt. Informiere dich bei der Anmeldung über Prüfungsort und Ablauf.',
                        'note' => null],
                    ['ref' => 16, 'theme' => false, 'question' => 'Was passiert, wenn ich die Prüfung nicht bestehe?',
                        'answer' => 'Du kannst dich über eine Wiederholungsprüfung informieren und dich erneut vorbereiten. Ob zusätzliche Prüfungsgebühren anfallen und welche Teile wiederholt werden müssen, richtet sich nach den Vorgaben des Prüfungsausschusses.',
                        'note' => null],
                    ['ref' => 17, 'theme' => false, 'question' => 'Wann bekomme ich meinen Bootsführerschein?',
                        'answer' => 'Nach bestandener Prüfung wird der Führerschein von der zuständigen Stelle ausgestellt und zugeschickt. Die Bearbeitungszeit kann variieren. Für Informationen zu vorläufigen Nachweisen wende dich an den Prüfungsausschuss.',
                        'note' => null],
                    ['ref' => 18, 'theme' => false, 'question' => 'Wie lange ist mein Bootsführerschein gültig?',
                        'answer' => 'Die Gültigkeit richtet sich nach der Art des Führerscheins und den geltenden Vorschriften. Prüfe die Angaben auf deinem Dokument und informiere dich bei der zuständigen Behörde, wenn du Fragen zu deinem konkreten Schein hast.',
                        'note' => null],
                    ['ref' => 20, 'theme' => false, 'question' => 'Sind die Prüfungsgebühren im Kurspreis enthalten?',
                        'answer' => 'Das hängt vom Angebot ab. Prüfe die Kursbeschreibung und deine Buchungsübersicht, um zu sehen, welche Kosten enthalten sind.',
                        'note' => 'konkrete Regelung ergänzen.'],
                    ['ref' => 92, 'theme' => true, 'question' => 'Ist die amtliche Prüfung im Kurs enthalten?',
                        'answer' => 'Nein. Der Kurs bereitet dich online vor. Die amtliche Prüfung legst du bei einem Prüfungsausschuss ab; Anmeldung, Termin und Prüfungsgebühr laufen direkt über den Ausschuss.',
                        'note' => null],
                ]],
                ['name' => 'Kosten, Gutscheine und Garantien', 'slug' => 'allgemein-kosten-gutscheine-und-garantien', 'faqs' => [
                    ['ref' => 19, 'theme' => false, 'question' => 'Was kostet der Kurs?',
                        'answer' => 'Der Kurspreis hängt vom gewählten Schein und den enthaltenen Leistungen ab. Die aktuellen Preise findest du hier: [Link zur Preisseite]. Prüfungsgebühren können zusätzlich anfallen; sie werden von der zuständigen Stelle festgelegt.',
                        'note' => null],
                    ['ref' => 21, 'theme' => false, 'question' => 'Kann ich einen Kurs verschenken?',
                        'answer' => 'Wenn Gutscheine angeboten werden, kannst du einen Kurs als Geschenk erwerben. Informationen findest du hier: [Link zur Gutscheinseite].',
                        'note' => 'Bestellweg bestätigen oder Frage entfernen.'],
                    ['ref' => 22, 'theme' => true, 'question' => 'Gibt es eine Geld-zurück- oder Bestehensgarantie?',
                        'answer' => 'Für Einzelkurse, Pakete und Pässe gilt die Geld-zurück-Garantie innerhalb von 14 Tagen ab Zahlungseingang, wenn du weniger als 20 % der gekauften Inhalte genutzt hast. Gezählt werden seit der Zahlung beantwortete Prüfungsfragen der gekauften Kurse (im Smarttrainer und in den Quizfragen der Video-Lektionen) sowie abgeschlossene Video-Lektionen, die du in diesem Zeitraum aufgerufen hast. Die Erstattung beantragst du in deinem Konto unter „Mein Lernen“; sie erfolgt über die ursprüngliche Zahlungsart, und der Zugang zu den erstatteten Kursen endet. Die Garantie gilt nicht für Gruppenpakete oder Funkgerät-Simulator-Zugänge und zusätzlich zum gesetzlichen Widerrufsrecht. Alle Bedingungen stehen in den {{agb}}.',
                        'note' => null],
                ]],
                ['name' => 'Gruppenangebote', 'slug' => 'allgemein-gruppenangebote', 'faqs' => [
                    ['ref' => 91, 'theme' => true, 'question' => 'Wie funktionieren Gruppenpakete?',
                        'answer' => 'Eine Person kauft das Paket und lädt die Teilnehmenden per Link oder E-Mail ein. Jede Person lernt mit einem eigenen Konto. Die Plätze verwaltest du unter „Meine Gruppen“.',
                        'note' => null],
                ]],
                ['name' => 'Kontakt und Support', 'slug' => 'allgemein-kontakt-und-support', 'faqs' => [
                    ['ref' => 23, 'theme' => false, 'question' => 'Wie erreiche ich euch bei weiteren Fragen?',
                        'answer' => 'Schreib uns an [E-Mail-Adresse], ruf uns unter [Telefonnummer] an oder nutze [Kontaktformular, Chat oder WhatsApp]. Wir helfen dir bei Fragen zur Kurswahl, Anmeldung und Vorbereitung weiter.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'SBF-SEE', 'name' => 'SBF See', 'slug' => 'sbf-see',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'sbf-see-kursinhalt', 'faqs' => [
                    ['ref' => 24, 'theme' => true, 'question' => 'Was lerne ich im SBF-See-Kurs?',
                        'answer' => 'Du übst die im Kurs enthaltenen Prüfungsfragen, Navigationsaufgaben und Inhalte für die Prüfungssimulation. Dazu kommen Praxiskarten zur Vorbereitung auf den praktischen Teil.',
                        'note' => null],
                    ['ref' => 30, 'theme' => true, 'question' => 'Ist eine praktische Ausbildung auf dem Boot im Onlinekurs enthalten?',
                        'answer' => 'Der Kurs bereitet dich online auf die Theorie und die Prüfungsinhalte vor. Praxiskarten unterstützen dich beim Lernen; tatsächliche Fahrstunden auf dem Wasser sind nicht Bestandteil des Onlinekurses.',
                        'note' => null],
                ]],
                ['name' => 'Lernen', 'slug' => 'sbf-see-lernen', 'faqs' => [
                    ['ref' => 27, 'theme' => false, 'question' => 'Wie gehe ich bei Navigationsaufgaben am besten vor?',
                        'answer' => 'Lies zuerst die Angaben genau, markiere die relevanten Informationen auf der Karte und prüfe dein Ergebnis anschließend mit der Musterlösung.',
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
                        'answer' => 'Ja. Für die Aufgaben wird die gedruckte Übungskarte D49 benötigt. Die Aufgaben enthalten Musterlösungen und eine Selbsteinschätzung.',
                        'note' => null],
                ]],
                ['name' => 'Prüfung', 'slug' => 'sbf-see-pruefung', 'faqs' => [
                    ['ref' => 26, 'theme' => true, 'question' => 'Kann ich die SBF-See-Prüfung mit dem Kurs simulieren?',
                        'answer' => 'Ja. Die Prüfungssimulation übt den Ablauf mit Zeitlimit und anschließender Auswertung.',
                        'note' => null],
                    ['ref' => 31, 'theme' => false, 'question' => 'Gilt meine Kursbuchung auch als Anmeldung zur SBF-See-Prüfung?',
                        'answer' => 'Nein. Die Kursbuchung gibt dir Zugang zur Vorbereitung. Für die amtliche Prüfung meldest du dich separat beim zuständigen Prüfungsausschuss an.',
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
                        'answer' => 'Die benötigten Unterlagen hängen von deinem Prüfungstermin und dem zuständigen Ausschuss ab. Verwende die Checkliste zur Prüfungsanmeldung und prüfe die Vorgaben direkt vor dem Termin noch einmal.',
                        'note' => null],
                    ['ref' => 39, 'theme' => false, 'question' => 'Kann ich einzelne Prüfungsteile an verschiedenen Tagen ablegen?',
                        'answer' => 'Das hängt vom Prüfungsablauf und den Vorgaben des zuständigen Ausschusses ab. Kläre bei der Anmeldung, welche Prüfungsteile für deinen Termin vorgesehen sind.',
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
                        'answer' => 'Der Segelteil wird zusätzlich zum Binnenstoff behandelt. Wie die Prüfungsteile organisiert und terminiert werden, kann vom Prüfungsausschuss abhängen.',
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
                        'answer' => 'Du kannst den Funkgerät-Simulator testen. Der vollständige Zugang ist als Upgrade verfügbar; die enthaltene Version hängt vom gewählten Angebot ab.',
                        'note' => 'tatsächliche Angebotsstufen bestätigen.'],
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
                        'answer' => 'Prüfe, welcher Fragenstand für deinen Prüfungstermin gilt. Vergleiche den Kursstand mit den aktuellen Informationen des zuständigen Prüfungsausschusses und wiederhole gegebenenfalls geänderte Inhalte.',
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
                        'answer' => 'Der Kombikurs bündelt die Lerninhalte. Prüfungstermine und Anmeldung organisierst du entsprechend den Vorgaben der zuständigen Prüfungsausschüsse.',
                        'note' => null],
                    ['ref' => 76, 'theme' => false, 'question' => 'Kann ich die Prüfungen für den Kombikurs an unterschiedlichen Terminen ablegen?',
                        'answer' => 'Die Prüfungstermine planst du nach den Vorgaben der jeweiligen Prüfungsausschüsse. Frag dort nach, ob getrennte Termine möglich sind.',
                        'note' => null],
                    ['ref' => 77, 'theme' => false, 'question' => 'Was gilt, wenn ich bei einem Kombikurs nur eine Prüfung bestehe?',
                        'answer' => 'Die Folgen hängen von den Prüfungsregeln und der Art des Kurses ab. Erkundige dich beim Prüfungsausschuss, ob ein bestandener Teil angerechnet wird und bis wann ein nicht bestandener Teil wiederholt werden muss.',
                        'note' => null],
                ]],
            ],
        ],
        [
            'key' => 'KAPITAEN', 'name' => 'Kapitäns-Bundle', 'slug' => 'kapitaen',
            'subcategories' => [
                ['name' => 'Kursinhalt', 'slug' => 'kapitaen-kursinhalt', 'faqs' => [
                    ['ref' => 78, 'theme' => true, 'question' => 'Welche Kurse gehören zum Kapitäns-Bundle?',
                        'answer' => 'Das Bundle bündelt die Vorbereitung auf SBF See, SBF Binnen, SRC und UBI. Die jeweiligen Fragen und Lernbereiche sind im Paket enthalten.',
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
                        'answer' => 'Das Bundle umfasst Lerninhalte und Prüfungsvorbereitung. Die amtlichen Prüfungen legst du beim zuständigen Prüfungsausschuss ab; Gebühren und Anmeldung sind separat zu klären.',
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
