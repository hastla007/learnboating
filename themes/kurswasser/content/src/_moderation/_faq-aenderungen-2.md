# FAQ-Änderungen (Moderation Seiten 18–34)

Stand: 10.11.2026. Grundlage: `bootspruefung-learning/src/Faq/DefaultFaqs.php` (92 FAQs). Davon sind 83 veröffentlicht. Entwürfe bleiben 9, 11, 12, 13, 14, 19, 20, 21 und 23, weil sie Platzhalter oder Notizen enthalten (`FaqImporter::statusFor`).

**Warum alle veröffentlichten FAQs geprüft wurden:** Seite 34 (`/faq-bootsfuehrerschein/`) gibt mit `[bootspruefung_faq]` jede veröffentlichte FAQ öffentlich aus, dazu das FAQPage-JSON-LD. Die ★-FAQs erscheinen außerdem auf der Startseite (Muster `kurswasser/faq`) und auf den Kursseiten.

**So wird umgesetzt:**
- Bestehende Installationen pflegt man im Admin unter „Bootsprüfung › FAQ“. Der Importer fasst vorhandene Nummern nie an, auch keine im Papierkorb.
- Für Neuinstallationen dieselben Texte zusätzlich in `DefaultFaqs.php` übernehmen.
- Links:
  - Im Admin als echte Links setzen.
  - In `DefaultFaqs.php` maskiert der Import HTML (`htmlspecialchars`). Dort stehen Links deshalb als Klartext.
  - Alternative: ein Token wie `{{agb}}` für weitere Ziele ergänzen (`FaqRepository::replaceTokens`).
- Stil: Antworten bleiben kurz (2–4 Sätze) und verweisen mit „Mehr dazu“ auf die passende Seite, damit die FAQ nicht mit den Ratgeberseiten konkurriert (SEO).

**Abstimmung mit Moderation 1–17:**
- Sie führt eine eigene Datei `_faq-aenderungen.md`.
- Gibt es für dieselbe Nummer zwei Fassungen, gilt die strengere aus Fach- und Rechtssicht.
- Der Wortlaut von FAQ 22 muss mit Seite 9 und AGB Nr. 9 übereinstimmen.

Legende Experten: 1 = Fachexperte, 2 = Angebot, 3 = Design, 4 = SEO, 5 = Recht.

---

## A. Veröffentlichte FAQs, die geändert werden müssen (Pflicht vor Veröffentlichung von Seite 34)

### ref 1 – Welchen Bootsführerschein brauche ich? (Scheinwahl)
- **Alt:** „Das hängt davon ab, wo du fahren möchtest und welches Boot du führen willst. Für Binnengewässer und Seeschifffahrtsstraßen gelten unterschiedliche Regelungen. Beschreibe uns dein Vorhaben – wir helfen dir, den passenden Schein zu finden.“
- **Neu:** „Das hängt davon ab, wo und womit du fahren willst. Für Motorboote mit mehr als 11,03 kW (15 PS) brauchst du auf Seeschifffahrtsstraßen den SBF See und auf Binnenschifffahrtsstraßen den SBF Binnen. Für Elektromotoren liegt die Grenze derzeit bei 7,5 kW. Unter Segel ist der SBF Binnen nur auf bestimmten Revieren vorgeschrieben; auf See brauchst du zum Segeln keinen Schein. Mehr dazu: Sportbootführerschein im Überblick (/sportbootfuehrerschein/).“
- **Begründung:**
  - Der alte Text sagt eine individuelle Beratung zu, die nicht belegt ist (§ 5 UWG).
  - Ihm fehlen die Schwellenwerte.
- **Experte:** 2, 5 (Beratung), 1 (Schwellenwerte, Seite 18)

### ref 2 – Was ist der Unterschied zwischen SBF Binnen und SBF See?
- **Alt:** „Der SBF Binnen gilt für bestimmte Binnengewässer, der SBF See für Seeschifffahrtsstraßen. Welcher Schein für deine geplante Fahrt nötig ist, hängt auch vom Gewässer und vom Boot ab.“
- **Neu:** „Der SBF Binnen gilt auf Binnenschifffahrtsstraßen, unter Antriebsmaschine, unter Segel oder beides. Der SBF See gilt auf Seeschifffahrtsstraßen und nur für Boote mit Antriebsmaschine. Jeder Schein gilt nur für den eingetragenen Geltungsbereich. Wer beide Bereiche befahren will, braucht beide Scheine. Mehr dazu: SBF See und Binnen im Vergleich (/sportbootfuehrerschein/#vergleich).“
- **Begründung:**
  - „Bestimmte Binnengewässer“ ist unklar.
  - Die Antriebsart fehlt, ebenso der Hinweis, dass der Schein nur im eingetragenen Bereich gilt.
- **Experte:** 1

### ref 3 – Kann ich mit meinem Bootsführerschein auch im Ausland fahren?
- **Alt:** „Das richtet sich nach dem jeweiligen Land und Gewässer. Informiere dich vor der Fahrt über die örtlichen Vorschriften und darüber, ob dein Führerschein dort anerkannt wird.“
- **Neu:** „Das entscheidet jedes Land selbst. Der deutsche SBF wird als internationales Zertifikat nach der UNECE-Resolution Nr. 40 ausgestellt. Das kann die Anerkennung erleichtern, aber nicht alle Staaten wenden die Resolution an. Manche Länder erkennen ausländische Scheine nur bei Personen an, die im Ausstellerland wohnen, zum Beispiel Österreich und die Schweiz. Frag vor Reise oder Charter bei der Behörde des Ziellandes und beim Vercharterer nach. Mehr dazu: Bootsführerschein im Ausland (/bootsfuehrerschein-ausland-anerkennung/).“
- **Begründung:**
  - ICC und Wohnsitzregeln fehlen.
  - Für Menschen mit Wohnsitz in AT oder CH droht ein Fehlkauf.
- **Experte:** 1, 4

### ref 4 – Welche Voraussetzungen muss ich für einen Bootsführerschein erfüllen?
- **Alt:** „Die Voraussetzungen hängen vom angestrebten Schein ab. Dazu können ein Mindestalter, ein ärztlicher Eignungsnachweis und eine bestandene Prüfung gehören. Prüfe die jeweils geltenden Bedingungen vor der Anmeldung.“
- **Neu:** „Für den Sportbootführerschein brauchst du:
  - das Mindestalter (16 Jahre unter Antriebsmaschine, 14 Jahre für den SBF Binnen unter Segel);
  - einen ärztlichen Tauglichkeitsnachweis, der bei Antragstellung höchstens zwölf Monate alt ist;
  - ab 18 Jahren einen Zuverlässigkeitsnachweis, also eine Kopie deines Kfz-Führerscheins oder ein Führungszeugnis;
  - eine bestandene Prüfung in Theorie und Praxis.

  Mehr dazu: Mindestalter (/mindestalter-bootsfuehrerschein/) und ärztliches Attest (/aerztliches-attest-bootsfuehrerschein/).“
- **Begründung:**
  - Die Zuverlässigkeit fehlt.
  - Der alte Text ist zu vage für eine belegbare Regel.
- **Experte:** 1

### ref 5 – Wie alt muss ich für den Sportbootführerschein sein?
- **Alt:** „Das Mindestalter hängt von Schein und Antriebsart ab. Für viele Prüfungen gilt ein Mindestalter von 16 Jahren; für bestimmte Segelberechtigungen auf Binnengewässern kann ein niedrigeres Alter gelten. Prüfe die Vorgaben für deinen konkreten Prüfungsgang.“
- **Neu:** „Unter Antriebsmaschine, also für den SBF See und den SBF Binnen unter Motor, gilt ein Mindestalter von 16 Jahren. Für den SBF Binnen unter Segel sind es 14 Jahre. Minderjährige brauchen die Zustimmung ihrer Eltern. Zur Prüfung kannst du schon bis zu drei Monate vor dem Geburtstag antreten. Das bestätigt dir dein Prüfungsausschuss. Mehr dazu: Mindestalter (/mindestalter-bootsfuehrerschein/).“
- **Begründung:**
  - Die Altersangaben werden konkret.
  - Die vorgezogene Prüfung ist nur sekundär belegt. Deshalb steht sie mit Hinweis auf den Ausschuss da.
- **Experte:** 1, 5 (an Seite 21 angleichen)

### ref 7 – Kann ich mich online auf die Prüfung vorbereiten?
- **Alt:** „Ja. Mit einem Onlinekurs kannst du den Theorieteil flexibel und im eigenen Tempo lernen. Ob Praxisstunden oder einzelne Termine vor Ort dazugehören, hängt vom gebuchten Kurs ab.“
- **Neu:** „Ja. Mit unseren Onlinekursen lernst du die Theorie flexibel und im eigenen Tempo. Dazu gehören alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und Praxiskarten, beim SBF See außerdem die Navigationsaufgaben. Fahrstunden auf dem Wasser gehören zu keinem Kurs; die bekommst du bei einer Bootsschule. Die amtliche Prüfung legst du vor Ort bei einem Prüfungsausschuss ab.“
- **Begründung:**
  - Kein Kurs enthält Praxis (`DefaultFaqs.php` ref 30, 37; AGB Nr. 2).
  - Der alte Text deutet Praxisleistungen an (§ 5 UWG).
- **Experte:** 2, 5

### ref 15 – Kann ich die amtliche Prüfung online ablegen?
- **Alt:** „Die Theorie kannst du online lernen. Die amtliche Prüfung selbst findet nach den geltenden Vorgaben des zuständigen Prüfungsausschusses statt. Informiere dich bei der Anmeldung über Prüfungsort und Ablauf.“
- **Neu:** „Nein. Online lernst du die Theorie. Die amtliche Prüfung legst du vor Ort vor einem Prüfungsausschuss ab, Theorie und Praxis. Online stellst du höchstens den Antrag.“
- **Begründung:**
  - Die Frage verlangt eine klare Antwort.
  - „Zuständiger Ausschuss“ ist fachlich schief: Den Ausschuss wählst du frei.
- **Experte:** 1

### ref 16 – Was passiert, wenn ich die Prüfung nicht bestehe?
- **Alt:** „Du kannst dich über eine Wiederholungsprüfung informieren und dich erneut vorbereiten. Ob zusätzliche Prüfungsgebühren anfallen und welche Teile wiederholt werden müssen, richtet sich nach den Vorgaben des Prüfungsausschusses.“
- **Neu:** „Dann kannst du die Prüfung wiederholen. Beim Sportbootführerschein werden Theorie und Praxis getrennt bewertet; einen nicht bestandenen Teil legst du erneut ab. Welche Fristen gelten und welche Gebühren dafür anfallen, sagt dir dein Prüfungsausschuss. Im Kurs hilft dir die Auswertung der Prüfungssimulation, gezielt die Fehler zu wiederholen.“
- **Begründung:**
  - Die Antwort wird konkreter.
  - Sie nennt eine belegte Stärke (Auswertung, „Fehler aus der Prüfung lernen“).
  - Die 12-Monats-Frist ist nicht amtlich belegt und wird deshalb nicht genannt.
- **Experte:** 1, 2

### ref 17 – Wann bekomme ich meinen Bootsführerschein?
- **Alt:** „Nach bestandener Prüfung wird der Führerschein von der zuständigen Stelle ausgestellt und zugeschickt. Die Bearbeitungszeit kann variieren. Für Informationen zu vorläufigen Nachweisen wende dich an den Prüfungsausschuss.“
- **Neu:** „Nach bestandener Prüfung erteilt der Prüfungsausschuss den Führerschein. Die Karte wird dir zugeschickt. Wie lange das dauert und ob du vorher einen Nachweis bekommst, sagt dir dein Prüfungsausschuss.“
- **Begründung:** Der Fachexperte hat präzisiert, wer den Schein erteilt.
- **Experte:** 1

### ref 18 – Wie lange ist mein Bootsführerschein gültig?
- **Alt:** „Die Gültigkeit richtet sich nach der Art des Führerscheins und den geltenden Vorschriften. Prüfe die Angaben auf deinem Dokument und informiere dich bei der zuständigen Behörde, wenn du Fragen zu deinem konkreten Schein hast.“
- **Neu:** „Der Sportbootführerschein ist unbefristet gültig. Er kann entzogen werden, wenn die Eignung oder Zuverlässigkeit fehlt. Eine Reform ist angekündigt. Nach Berichten der Fachpresse sollen bereits erteilte Scheine gültig bleiben. Mehr dazu: Sportbootführerschein im Überblick (/sportbootfuehrerschein/).“
- **Begründung:**
  - Die alte Antwort beantwortet die Frage nicht.
  - Fach: unbefristet gültig.
  - Der Reformstand ist nur über die Fachpresse belegt. Deshalb steht er mit Quelle und Link da.
- **Experte:** 1

### ref 22 ★ – Gibt es eine Geld-zurück- oder Bestehensgarantie?
- **Alt:** „Für Einzelkurse, Pakete und Pässe gilt die Geld-zurück-Garantie innerhalb von 14 Tagen ab Zahlungseingang, wenn du weniger als 20 % der gekauften Inhalte genutzt hast. Gezählt werden seit der Zahlung beantwortete Prüfungsfragen der gekauften Kurse (im Smarttrainer und in den Quizfragen der Video-Lektionen) sowie abgeschlossene Video-Lektionen, die du in diesem Zeitraum aufgerufen hast. Die Erstattung beantragst du in deinem Konto unter „Mein Lernen“; sie erfolgt über die ursprüngliche Zahlungsart, und der Zugang zu den erstatteten Kursen endet. Die Garantie gilt nicht für Gruppenpakete oder Funkgerät-Simulator-Zugänge und zusätzlich zum gesetzlichen Widerrufsrecht. Alle Bedingungen stehen in den {{agb}}.“
- **Neu:** „Eine Bestehensgarantie gibt es nicht: Die Prüfung nimmt ein unabhängiger Prüfungsausschuss ab. Zusätzlich zu deinen gesetzlichen Rechten geben wir dir freiwillig eine Geld-zurück-Garantie.
  - **Frist und Bedingung:** Innerhalb von 14 Tagen ab Zahlungseingang erstatten wir dir den gezahlten Preis der Produkte mit Garantie. Voraussetzung: Du hast seit Zahlungseingang weniger als 20 % der gekauften Inhalte genutzt.
  - **So wird gezählt:** Gezählt werden die Prüfungsfragen der gekauften Kurse, die du beantwortet hast, im Smarttrainer und in den Quizfragen der Video-Lektionen, jede Frage einmal. Dazu kommen abgeschlossene Video-Lektionen. In der Theorie-Stufe zählen nur Fragen, beim Video-Upgrade nur Lektionen.
  - **Gilt für:** Einzelkurse (Theorie und Komplett), Video-Upgrades, Ergänzungskurse, Kombikurse, das Kapitäns-Bundle und die Pässe.
  - **Gilt nicht für:** Gruppenpakete, eigenständige Zugänge zum Funkgerät-Simulator und bereits erstattete Produkte.
  - **Ablauf:** Du forderst die Erstattung unter „Mein Lernen“ an, eine Anfrage je Bestellung. Wir erstatten über deine ursprüngliche Zahlungsart, und dein Zugang zu den erstatteten Kursen endet.
  - **Kein Widerrufsrecht:** Die Garantie ist kein Widerrufsrecht. Bei digitalen Kursen erlischt das Widerrufsrecht, sobald dein Zugang nach deiner Zustimmung in der Kasse freigeschaltet ist.

  Alle Bedingungen stehen in den {{agb}}.“
- **Begründung:**
  - Werbung bindet bei Garantien (§ 443 BGB). Die FAQ darf deshalb nicht weiter gehen als AGB Nr. 9.
  - „Pakete“ kollidiert mit „Gruppenpakete“.
  - Ausgenommen sind nur die *eigenständigen* Simulator-Zugänge; Bundle und Pässe sind auch mit Simulator erfasst.
  - Es fehlen der Ausschluss erstatteter Produkte, „eine Anfrage je Bestellung“ und die Zählregeln je Stufe.
  - „Zusätzlich zum Widerrufsrecht“ ist missverständlich, weil das Widerrufsrecht mit der Freischaltung erlischt.
  - Der Satz zur Bestehensgarantie fehlt.
- **Hinweis Erstattungsfrist:** Die Frist („spätestens X Tage“) nicht nennen, solange sie nicht in AGB, Seite 9, Produkttext und „Mein Lernen“ einheitlich festgelegt ist (Recht, Punkt h).
- **Experte:** 2, 5; Rahmen (Garantie-Mustertext)

### ref 24 ★ – Was lerne ich im SBF-See-Kurs?
- **Alt:** „Du übst die im Kurs enthaltenen Prüfungsfragen, Navigationsaufgaben und Inhalte für die Prüfungssimulation. Dazu kommen Praxiskarten zur Vorbereitung auf den praktischen Teil.“
- **Neu:** „Du übst alle Prüfungsfragen des SBF See im Smarttrainer und simulierst die Prüfung mit Zeitlimit. Bei den Navigationsaufgaben werden deine Werte automatisch geprüft. Praxiskarten bereiten dich auf die praktische Prüfung vor. In der Stufe „Komplett“ kommt der Videokurs dazu.“
- **Begründung:**
  - „Im Kurs enthaltene“ klingt nach einer Auswahl; die Kursseiten sagen „alle Prüfungsfragen“.
  - Die Stärken automatische Werteprüfung und Videokurs fehlen.
- **Experte:** 2

### ref 25 ★ – Brauche ich für die Navigationsaufgaben eine Seekarte?
- **Alt:** „Ja. Für die Aufgaben wird die gedruckte Übungskarte D49 benötigt. Die Aufgaben enthalten Musterlösungen und eine Selbsteinschätzung.“
- **Neu:** „Ja. Für die Navigationsaufgaben brauchst du die gedruckte Übungsseekarte D49 sowie Kursdreieck und Zirkel. Die Karte besorgst du dir selbst, wir verkaufen sie nicht. Im Kurs werden deine Werte automatisch geprüft, du siehst die Musterlösung und kannst dich selbst einschätzen.“
- **Begründung:**
  - Es fehlt die automatische Werteprüfung (`Strings.php:465-472`).
  - Es fehlt der Hinweis, dass wir die Karte nicht verkaufen.
  - Das Navigationsbesteck fehlt.
- **Experte:** 2, 1

### ref 31 – Gilt meine Kursbuchung auch als Anmeldung zur SBF-See-Prüfung?
- **Alt:** „… Für die amtliche Prüfung meldest du dich separat beim zuständigen Prüfungsausschuss an.“
- **Neu:** „Nein. Die Kursbuchung gibt dir Zugang zur Vorbereitung. Für die amtliche Prüfung meldest du dich separat bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl an.“
- **Begründung:** Es gibt keine örtliche Zuständigkeit; der Ausschuss ist frei wählbar.
- **Experte:** 1

### ref 38 – Welche Unterlagen muss ich zur SBF-Binnen-Prüfung mitbringen?
- **Alt:** „Die benötigten Unterlagen hängen von deinem Prüfungstermin und dem zuständigen Ausschuss ab. Verwende die Checkliste zur Prüfungsanmeldung und prüfe die Vorgaben direkt vor dem Termin noch einmal.“
- **Neu:** „Zur Anmeldung brauchst du:
  - den unterschriebenen Antrag;
  - ein Passbild;
  - den ärztlichen Tauglichkeitsnachweis (höchstens zwölf Monate alt);
  - ab 18 Jahren eine Kopie deines Kfz-Führerscheins oder ein Führungszeugnis;
  - als Minderjährige oder Minderjähriger die Zustimmung deiner Eltern.

  Am Prüfungstag bringst du deinen Ausweis und deinen Führerschein im Original mit. Ob dein Prüfungsausschuss weitere Unterlagen verlangt, erfährst du dort.“
- **Begründung:**
  - Die „Checkliste zur Prüfungsanmeldung“ liegt im Lernbereich und ist standardmäßig abgeschaltet (`Settings.php:21`).
  - Die Unterlagen sind belegt (`pruefung_buchen.php`).
  - „Zuständiger Ausschuss“ ist fachlich schief.
- **Experte:** 1, 2

### ref 39 – Kann ich einzelne Prüfungsteile an verschiedenen Tagen ablegen?
- **Alt:** „Das hängt vom Prüfungsablauf und den Vorgaben des zuständigen Ausschusses ab. Kläre bei der Anmeldung, welche Prüfungsteile für deinen Termin vorgesehen sind.“
- **Neu:** „Das ist möglich. Theorie und Praxis können am selben Tag oder an verschiedenen Tagen stattfinden; das legt dein Prüfungsausschuss fest. Kläre bei der Anmeldung, welche Prüfungsteile für deinen Termin vorgesehen sind.“
- **Begründung:**
  - Die Antwort wird konkreter (`pruefung_buchen.php`).
  - Die Formulierung „zuständig“ entfällt.
- **Experte:** 1

### ref 45 – Ist die Segelprüfung ein eigener Prüfungsteil?
- **Alt:** „Der Segelteil wird zusätzlich zum Binnenstoff behandelt. Wie die Prüfungsteile organisiert und terminiert werden, kann vom Prüfungsausschuss abhängen.“
- **Neu:** „Teilweise. Im Fragebogen kommen zu den Basis- und Binnen-Fragen 7 Segelfragen dazu, und die praktische Prüfung findet unter Segel statt. Hast du den SBF Binnen unter Antriebsmaschine schon, legst du nur die Segelfragen und eine praktische Prüfung unter Segel ab. Dafür gibt es den Ergänzungskurs Binnen → Segeln.“
- **Begründung:**
  - Die belegten Prüfungsteile werden genannt (`pruefung_buchen.php`).
  - Der Ergänzungskurs ist eine Stärke des Angebots.
- **Experte:** 1, 2

### ref 51 ★ – Gibt es im SRC-Kurs einen Funkgerät-Simulator?
- **Alt:** „Du kannst den Funkgerät-Simulator testen. Der vollständige Zugang ist als Upgrade verfügbar; die enthaltene Version hängt vom gewählten Angebot ab.“ (Notiz: „tatsächliche Angebotsstufen bestätigen.“)
- **Neu:** „Im SRC-Kurs ist die kostenlose Testversion enthalten: Gerät einschalten, Kanäle wählen und einen Routineanruf üben. Die Vollversion mit Notalarm, Prüfungsmodus und freiem Üben bekommst du auf zwei Wegen:
  - beim Kauf eines Funkkurses als Upgrade in der Kasse;
  - als eigenen Simulator-Zugang für 14 Tage, 3 oder 12 Monate.

  Im Kapitäns-Bundle und in den Pässen ist die Vollversion schon enthalten.“
- **Begründung:**
  - Die Angebotsstufen sind belegt (`ShopCatalog.php:240-243`, `Strings.php:541-544`, `DefaultFaqs.php` ref 85).
  - Die Notiz wird damit erledigt und kann gelöscht werden.
- **Experte:** 2

### ref 75 ★ – Muss ich beide Prüfungen gleichzeitig ablegen?
- **Alt:** „Der Kombikurs bündelt die Lerninhalte. Prüfungstermine und Anmeldung organisierst du entsprechend den Vorgaben der zuständigen Prüfungsausschüsse.“
- **Neu:** „Nein. Du kannst SBF See und SBF Binnen zusammen oder nacheinander ablegen. Bei einer gemeinsamen Prüfung beantwortest du die Basisfragen nur einmal. Den Termin stimmst du mit deinem Prüfungsausschuss ab.“
- **Begründung:**
  - Die Frage wird beantwortet.
  - Die Anrechnung der Basisfragen ist belegt (`pruefung_buchen.php`, Fach Seite 17).
- **Experte:** 1

### ref 77 – Was gilt, wenn ich bei einem Kombikurs nur eine Prüfung bestehe?
- **Alt:** „Die Folgen hängen von den Prüfungsregeln und der Art des Kurses ab. Erkundige dich beim Prüfungsausschuss, ob ein bestandener Teil angerechnet wird und bis wann ein nicht bestandener Teil wiederholt werden muss.“
- **Neu:** „SBF See und SBF Binnen werden getrennt erteilt. Bestehst du nur eine Prüfung, bekommst du diesen Schein und kannst die andere wiederholen. Ob ein bestandener Teil angerechnet wird und bis wann du wiederholen musst, sagt dir dein Prüfungsausschuss.“
- **Begründung:** Der Kurs („Art des Kurses“) hat keinen Einfluss auf die Prüfungsregeln.
- **Experte:** 1

### ref 79 ★ – Sind amtliche Prüfungen im Kapitäns-Bundle enthalten?
- **Alt:** „… Die amtlichen Prüfungen legst du beim zuständigen Prüfungsausschuss ab; Gebühren und Anmeldung sind separat zu klären.“
- **Neu:** „Nein. Das Bundle umfasst Lerninhalte und Prüfungsvorbereitung für SBF See, SBF Binnen, SRC und UBI. Die amtlichen Prüfungen legst du bei einem Prüfungsausschuss deiner Wahl ab; Anmeldung, Termin und Gebühr laufen direkt über den Ausschuss.“
- **Begründung:**
  - Die Antwort ist klar.
  - Die Formulierung „zuständig“ entfällt.
- **Experte:** 1, 2

### ref 88 ★ – Kann ich den Kurs vor dem Kauf ausprobieren?
- **Alt:** „Ja. Mit einem kostenlosen Kundenkonto kannst du in jedem Kurs ausgewählte Inhalte testen, zum Beispiel einige Fragen je Thema und einen Prüfungsbogen.“
- **Neu:** „Ja. Mit einem kostenlosen Kundenkonto testest du in jedem Kurs:
  - 10 Fragen je Modul;
  - einen Prüfungsbogen;
  - die erste Video-Lektion;
  - die erste Navigationsaufgabe;
  - die erste Praxiskarte.

  Bei den Funkkursen kannst du die Testversion des Funkgerät-Simulators ausprobieren. Die Zufallsprüfung gehört zum gekauften Kurs.“
- **Begründung:**
  - Das Schnupper-Paket ist belegt (`ShopCatalog.php:79-85`, `Strings.php:94-95`).
  - Angebot wünschte eine eigene FAQ dazu; die Ergänzung hier reicht.
- **Experte:** 2

### ref 89 ★ – Brauche ich eine App, um zu lernen?
- **Alt:** „Nein. Du lernst im Browser am Rechner, Tablet oder Smartphone. Dein Fortschritt wird in deinem Konto gespeichert.“
- **Neu:** „Nein. Du lernst direkt im Browser, am Rechner, Tablet oder Smartphone, ohne Installation. Dein Fortschritt wird in deinem Konto gespeichert. Eine App für iOS und Android ist in Vorbereitung. Sie nutzt dasselbe Konto und denselben Lernstand.“
- **Begründung:**
  - Die alte Aussage „keine App“ ist nicht zukunftssicher: Die App-Schnittstelle ist gebaut (`docs/mobile-api/README.md`).
  - Nach dem Rahmen darf die App so beworben werden, wie sie gebaut ist.
- **Hinweis:** Den letzten Satz streichen, falls der Betreiber die App nicht mehr plant.
- **Experte:** 2, 4

### ref 91 ★ – Wie funktionieren Gruppenpakete?
- **Alt:** „Eine Person kauft das Paket und lädt die Teilnehmenden per Link oder E-Mail ein. Jede Person lernt mit einem eigenen Konto. Die Plätze verwaltest du unter „Meine Gruppen“.“
- **Neu:** „Eine Person kauft das Paket mit 2 bis 50 Plätzen und lädt die Teilnehmenden per Link oder E-Mail ein; ein Platz kann auch für sie selbst sein. Jede Person lernt mit eigenem Konto und eigenem Fortschritt. Den Lernstand der anderen sieht niemand. Die Laufzeit von 12 Monaten beginnt mit der Zahlung und endet für alle gleichzeitig. Für Gruppenpakete gilt die Geld-zurück-Garantie nicht. Mehr dazu: Gruppenangebote (/bootsfuehrerschein-gruppenangebote/).“
- **Begründung:**
  - Laufzeit und fehlende Garantie sind wesentliche Informationen (§ 5a UWG).
  - Dass der Lernstand privat bleibt, ist belegt (`datenschutz.html:200`).
- **Experte:** 2, 5

---

## B. Veröffentlichte FAQs mit empfohlener (nicht zwingender) Änderung

| ref | Alt (Kern) | Neu | Begründung | Experte |
|---|---|---|---|---|
| 6 | „… Für einzelne Scheine oder Prüfungsteile können besondere Voraussetzungen gelten.“ | „In der Regel kannst du ohne Vorkenntnisse starten. Der Kurs erklärt die Grundlagen Schritt für Schritt. Für den FKN brauchst du allerdings einen Sportbootführerschein, oder du legst die SBF-Prüfung gleichzeitig ab.“ | konkreter (`pruefung_buchen.php`, FKN) | 1 |
| 27 | „… prüfe dein Ergebnis anschließend mit der Musterlösung.“ | „Lies zuerst die Angaben genau, markiere die relevanten Informationen auf der Karte und rechne Schritt für Schritt. Im Kurs werden deine Werte automatisch geprüft, danach vergleichst du deinen Weg mit der Musterlösung.“ | Stärke: automatische Werteprüfung | 2 |
| 60 | „… mit den aktuellen Informationen des zuständigen Prüfungsausschusses …“ | „… mit den aktuellen Informationen deines Prüfungsausschusses …“ | Begriff „zuständig“ | 1 |
| 76 | „… nach den Vorgaben der jeweiligen Prüfungsausschüsse …“ | „… mit deinem Prüfungsausschuss. Frag dort nach, ob getrennte Termine möglich sind.“ | Begriff | 1 |
| 78 ★ | „Das Bundle bündelt die Vorbereitung auf SBF See, SBF Binnen, SRC und UBI …“ | Satz ergänzen: „Die Vollversion des Funkgerät-Simulators ist enthalten.“ | Stärke, belegt (ref 85, `ShopCatalog.php:206`) | 2 |

Alle übrigen veröffentlichten FAQs hat die Moderation geprüft und ohne Befund übernommen: 8, 10, 26, 28–30, 32–37, 40–44, 46–50, 52–59, 61–74, 80–87, 90, 92.

---

## C. Entwürfe: neu formulieren und veröffentlichen

| ref | Status heute | Alt (Kern) | Neu | Begründung | Experte |
|---|---|---|---|---|---|
| 9 | Entwurf (Notiz) | „Das hängt vom gebuchten Angebot ab. Typische Bestandteile können … Praxiseinheiten sein …“ | „Je nach Kurs: alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und Praxiskarten. Beim SBF See kommen die Navigationsaufgaben dazu, bei den Funkkursen das Funktraining. In der Stufe „Komplett“ und in den Kombis ist der Videokurs enthalten. Nicht enthalten sind die amtliche Prüfung und ihre Gebühr, Fahrstunden, die Übungskarte D49 und das ärztliche Attest. Was ein Kurs genau umfasst, steht auf seiner Kursseite.“ – Notiz löschen, veröffentlichen | „Praxiseinheiten“ irreführend (§ 5 UWG); Leistungen belegt | 2, 5 |
| 12 | Entwurf (Platzhalter) | „Die Praxisausbildung findet an den angebotenen Standorten statt … [Link …]“ | „Fahrstunden auf dem Wasser bieten wir nicht an. Praxis bekommst du bei einer Bootsschule. Eine Ausbildung ist keine Zulassungsvoraussetzung. Für die praktische Prüfung brauchst du aber Fahrpraxis und ein geeignetes Prüfungsboot. Mehr dazu: Praxis und Prüfung vor Ort planen (/bootsfuehrerschein-standorte/).“ – veröffentlichen | Keine eigene Praxis (AGB Nr. 2); Fach: Ausbildung keine Pflicht | 1, 2, 5 |
| 13 | Entwurf (Platzhalter) | „Wähle einen verfügbaren Standort und Termin über [Buchungsweg ergänzen] …“ | **In den Papierkorb** (beantwortet durch ref 12). Der Importer legt die Nummer dann nicht neu an. | Leistung existiert nicht | 2, 5 |
| 14 | Entwurf (Platzhalter) | „… bei einem zuständigen Prüfungsausschuss … [Prüfungsanmeldung … ergänzen.]“ | „Bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl. Eine Zuständigkeit nach Wohnort gibt es nicht. Termine und Orte findest du auf sportbootfuehrerscheine.org. Für den SBF muss dein Antrag mit allen Unterlagen spätestens eine Woche vor dem Termin vorliegen, manche Ausschüsse verlangen mehr Vorlauf. Für SRC und UBI sind es 14 Tage.“ – veröffentlichen | Fach: freie Wahl, § 7 Abs. 1 SpFV; Funk-Frist laut `pruefung_buchen.php` | 1 |
| 19 | Entwurf (Platzhalter) | „… Die aktuellen Preise findest du hier: [Link zur Preisseite] …“ | „Den aktuellen Preis siehst du bei jedem Kurs auf der Kursseite und im Shop. Du zahlst einmal, ohne Abo. Die amtliche Prüfungsgebühr kommt hinzu. Sie ist bundesweit festgelegt und geht direkt an den Prüfungsausschuss. Mehr dazu: Kosten des Bootsführerscheins (/bootsfuehrerschein-kosten/).“ – veröffentlichen | Platzhalter; keine festen Preise (Rahmen) | 2, 4 |
| 20 | Entwurf (Notiz) | „Das hängt vom Angebot ab …“ | „Nein. Die Prüfungsgebühr zahlst du direkt an den Prüfungsausschuss. In keinem unserer Kurse ist sie enthalten.“ – Notiz löschen, veröffentlichen | Widerspruch zu ref 92 und AGB Nr. 2 | 5 |
| 21 | Entwurf (Platzhalter) | „Wenn Gutscheine angeboten werden … [Link zur Gutscheinseite].“ | Frage neu: „Kann ich einen Kurs verschenken oder für jemand anderen kaufen?“ – Antwort: „Gutscheine bieten wir derzeit nicht an. Ein Kurs gehört immer zu dem Kundenkonto, mit dem er gekauft wurde, und ist nicht übertragbar. Wollt ihr gemeinsam lernen, kann eine Person ein Gruppenpaket für SBF See, SBF Binnen, die Kombi oder Funk kaufen und die andere einladen. Jede Person lernt dann mit eigenem Konto.“ – Notiz löschen, veröffentlichen | Kein Gutscheinprodukt; Zugang an Käuferkonto gebunden (`WooOrderSource.php:44`, AGB); keine positive Gutscheinaussage (Recht, SEO) | 2, 4, 5 |
| 11 | Entwurf (Platzhalter) | „Zusätzliche Lernmaterialien findest du im Lernmittel-Shop: [Link zum Shop].“ | Frage neu: „Gibt es gedruckte Lernmaterialien?“ – Antwort: „Nein. Alle unsere Kurse sind digital und laufen im Browser, es gibt keine Versandkosten. Für die Navigationsaufgaben zum SBF See brauchst du die gedruckte Übungsseekarte D49. Die besorgst du dir selbst.“ – Notiz löschen, veröffentlichen | Keine physischen Produkte (AGB, `ShopCatalog.php:375`) | 2 |
| 23 | Entwurf (Platzhalter) | „Schreib uns an [E-Mail-Adresse], ruf uns unter [Telefonnummer] an oder nutze [Kontaktformular, Chat oder WhatsApp] …“ | „Alle Kontaktwege findest du auf unserer Kontaktseite (/kontakt/). Nenne am besten den Kurs und, bei Fragen zu einer Bestellung, die Bestellnummer. Fragen zu Prüfungsterminen und Gebühren beantwortet dein Prüfungsausschuss.“ – veröffentlichen, **sobald die Kontaktseite mit Betreiberangaben live ist** | Kontaktwege wie Chat oder WhatsApp gibt es nicht (§ 5 UWG); Fach-Hinweis zu Zuständigkeiten | 2, 5, 1 |

---

## D. Neue FAQs (Vorschlag)

| neu | Rubrik › Unterkategorie | Frage | Antwort | Begründung | Experte |
|---|---|---|---|---|---|
| 93 | allgemein › Scheinwahl und Geltungsbereich | Brauche ich für mein Boot überhaupt einen Führerschein? | „Nicht immer. Auf den meisten Binnen- und Seeschifffahrtsstraßen darfst du Motorboote bis 11,03 kW (15 PS) ohne Führerschein fahren, bei Elektromotoren derzeit bis 7,5 kW. Segeln auf See ist fahrerlaubnisfrei. Auch ohne Schein gilt binnen für Motorboote ein Mindestalter von 16 Jahren. Auf dem Bodensee und dem Rhein gelten eigene Regeln. Mehr dazu: Wann du keinen Bootsführerschein brauchst (/sportbootfuehrerschein/#fuehrerscheinfrei).“ | häufige Einsteigerfrage | 1, 4 |
| 94 | allgemein › Prüfung und Prüfungsablauf | Gilt mein Bootsführerschein nach der angekündigten Reform weiter? | „Das Bundesverkehrsministerium hat 2025 angekündigt, den amtlichen Sportbootführerschein durch anerkannte Scheine der Verbände zu ersetzen. Ob und wann das in Kraft tritt, stand am 10.11.2026 noch nicht fest. Nach Berichten der Fachpresse sollen bereits erteilte Scheine gültig bleiben. Den aktuellen Stand findest du beim Bundesverkehrsministerium.“ | Fach: Reform-Hinweis auf 34; Stand-Datum Pflicht, vor Go-live beim BMV prüfen | 1 |
| 95 | allgemein › Kursinhalt und Leistungen | Was ist der Unterschied zwischen „Theorie“ und „Komplett mit Videokurs“? | „Beide Stufen enthalten alle Prüfungsfragen im Smarttrainer, die Prüfungssimulation und die Trainer des Kurses. „Komplett“ enthält zusätzlich den Videokurs. Du kannst auch mit „Theorie“ starten und den Videokurs später als Upgrade dazubuchen. Die erste Video-Lektion ist in jedem Kurs kostenlos.“ | Angebot: fehlende FAQ, belegt (AGB, `VideoUpgrade.php`) | 2 |
| 96 | allgemein › Kursinhalt und Leistungen | Ich habe schon einen Schein – muss ich den ganzen Kurs kaufen? | „Nein. Für Inhaber eines Scheins gibt es Ergänzungskurse mit nur den zusätzlichen Inhalten: See → Binnen, Binnen → See, Binnen → Segeln und SRC → UBI. Für FKN-Inhaber gibt es die Ergänzung FKN → SKN mit dem vollständigen SKN-Fragenkatalog. Hast du einen Kurs schon gekauft, schlagen wir dir im Lernbereich und im Warenkorb die passende Ergänzung vor.“ | Stärke, belegt (`CourseRelations.php`, `CartRelationHint.php`) | 2 |
| 97 | allgemein › Lernplattform und Zugang | Erinnert ihr mich, bevor mein Zugang abläuft? | „Ja. 14 Tage vor Ablauf bekommst du eine E-Mail, und in deinem Lernbereich erscheint ein Hinweis mit „Jetzt verlängern“. Kaufst du denselben Kurs erneut, verlängert sich dein Zugang.“ | Angebot, belegt (`ExpiryReminder.php`); **nur, solange die Einstellung „Ablauf-Erinnerung“ aktiv ist** | 2 |

Neue Nummern ab 93, weil 1–92 vergeben sind. Für Neuinstallationen die Einträge auch in `DefaultFaqs.php` ergänzen.

---

## E. Verwandte Texte außerhalb der FAQ (gleicher Fehler, gleiche Korrektur)

- **Muster `themes/kurswasser/patterns/guarantee.php`:**
  - Der Text hat dieselben Schwächen wie die alte ref 22: „Einzelkurse, Pakete und Pässe“, „Zugänge zum Funkgerät-Simulator“ und „zusätzlich zum gesetzlichen Widerrufsrecht“.
  - Auf den Wortlaut von AGB Nr. 9 bzw. Seite 9 kürzen, zum Beispiel: „… Gilt für Einzelkurse, Video-Upgrades, Ergänzungskurse, Kombis, das Kapitäns-Bundle und die Pässe – nicht für Gruppenpakete und eigenständige Simulator-Zugänge. Die Garantie ist kein Widerrufsrecht.“
  - Experte: 5, 3.
- **Muster `themes/kurswasser/patterns/faq.php` (Ersatztext ohne Plugin):**
  - Der Ersatztext zur Garantie und „Brauche ich eine App?“ ist wie ref 22 und ref 89 anzupassen.
  - Er greift nur, wenn das Plugin fehlt.
- **Eigene Prüfungsanleitung `content-source/boatingschool/database/data/pruefung_buchen.php:45`:**
  - Heute: „ersetzt ggf. das ärztliche Zeugnis“.
  - Neu: „ein durch Prüfung erworbener Sportbootführerschein, der bei Antragstellung nicht älter als ein Jahr ist, ersetzt das ärztliche Zeugnis“.
  - Die Datei ist Quellmaterial und bleibt unverändert. Die Korrektur erfolgt deshalb in `GuideCorrections.php`.
  - Experte: 1, 2.
