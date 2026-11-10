# FAQ-Änderungen (Moderation Seiten 1–17)

Stand: 10.11.2026. Grundlage: `bootspruefung-learning/src/Faq/DefaultFaqs.php`. Legende Experten: 1 = Fach, 2 = Angebot, 3 = Design, 4 = SEO, 5 = Recht.

**Abstimmung mit Moderation 18–34 (`_faq-aenderungen-2.md`):** Die dortige Datei prüft alle 92 FAQs. Diese Datei wiederholt sie nicht, sondern
- bestätigt die Fassungen, die meine Seiten betreffen (Teil A),
- nennt Abweichungen, bei denen nach Rahmen die strengere Fassung gelten soll, und die Fassung von FAQ 22, die wortgleich mit Seite 9 sein muss (Teil B),
- schlägt zusätzliche FAQs vor (Teil C, Nummern ab 98, weil 93–97 dort vergeben sind),
- listet, welche FAQ-Auszüge auf den Seiten 1–17 eingebunden sind und was vorher erledigt sein muss (Teil D).

Umsetzung wie in `_faq-aenderungen-2.md` beschrieben: im Admin unter „Bootsprüfung › FAQ“ pflegen, für Neuinstallationen zusätzlich in `DefaultFaqs.php`; Links im Admin als echte Links (die Antworten laufen durch `wp_kses_post`).

---

## A. Übernommen aus `_faq-aenderungen-2.md` (für die Seiten 1–17 geprüft, ohne Einwand)

| ref | betrifft Seite(n) | Kern der neuen Fassung | Experte |
|---|---|---|---|
| 1 | 2, 17 | Schwellenwerte statt Beratungszusage | 1, 2, 5 |
| 2 | 2, 17 | Geltungsbereich und Antriebsart | 1 |
| 3 | 2, 3, 4 | ICC, Resolution 40 nicht überall, Wohnsitzregeln AT/CH, Link Seite 2 | 1, 4 |
| 4 | 16 | Mindestalter, Tauglichkeit (≤ 12 Monate), Zuverlässigkeit ab 18 | 1 |
| 7 | 1, 12 | keine Praxis in Kursen, Prüfung vor Ort | 2, 5 |
| 9 | 1, 13 | Leistungen und Ausschlüsse konkret | 2, 5 |
| 11 | 13 | „Gibt es gedruckte Lernmaterialien?“ – nein, D49 selbst besorgen | 2 |
| 12 | 6 | keine Praxis bei uns, Ausbildung keine Zulassungsvoraussetzung, Link Seite 6 | 1, 2, 5 |
| 13 | 6 | in den Papierkorb | 2, 5 |
| 14 | 6, 16 | Ausschuss frei wählbar, Antrag spätestens eine Woche vorher | 1 |
| 16 | 10 | Teile getrennt bewertet, Fristen/Gebühren beim Ausschuss | 1, 2 |
| 17 | 16 | Ausschuss erteilt den Schein | 1 |
| 18 | 16 | unbefristet gültig, Reform-Hinweis | 1 |
| 19, 20 | 11 | Preise live, Prüfungsgebühr nicht enthalten | 2, 4, 5 |
| 21 | 8 | Fassung A bis zum Gutscheinprodukt (siehe Teil B für Fassung B) | 2, 4, 5 |
| 23 | Kontakt | Verweis auf /kontakt/ | 2, 5, 1 |
| 24, 25 | 5, 12, 13 | alle Fragen, automatische Werteprüfung, D49 + Besteck | 1, 2 |
| 31, 38, 39 | 5, 6 | „deiner Wahl“, Unterlagenliste, Prüfungsteile an verschiedenen Tagen | 1, 2 |
| 45 | 17 | Segelprüfung, Ergänzung Binnen → Segeln | 1, 2 |
| 51 | 12 | Simulator-Stufen konkret | 2 |
| 75, 76, 77 | 17 | Kombi-Prüfung: zusammen oder nacheinander, Basisfragen einmal | 1 |
| 79 | 11 | Bundle ohne Prüfung, Ausschuss deiner Wahl | 1, 2 |
| 88 | 1, 5, 12 | Schnupper-Paket genau | 2 |
| 89 | 12 | Browser, keine Installation, App in Vorbereitung | 2, 4 |
| 91 | 7 | Laufzeit, kein Lernstand-Einblick, keine Garantie | 2, 5 |
| 93–97 | 2, 11, 12, 17 | neue FAQs (führerscheinfrei, Reform, Theorie/Komplett, Ergänzungskurse, Ablauf-Erinnerung) | 1, 2, 4 |

---

## B. Abweichungen und zusätzliche Fassungen

### ref 5 – Wie alt muss ich für den Sportbootführerschein sein?
- **Alt:** „Das Mindestalter hängt von Schein und Antriebsart ab. Für viele Prüfungen gilt ein Mindestalter von 16 Jahren; für bestimmte Segelberechtigungen auf Binnengewässern kann ein niedrigeres Alter gelten. Prüfe die Vorgaben für deinen konkreten Prüfungsgang.“
- **Neu (Vorschlag Moderation 1, strengere Fassung):** „Unter Antriebsmaschine, also für den SBF See und den SBF Binnen unter Motor, gilt ein Mindestalter von 16 Jahren. Für den SBF Binnen unter Segel sind es 14 Jahre. Minderjährige brauchen die Zustimmung ihrer gesetzlichen Vertretung. Ob du schon kurz vor deinem Geburtstag zur Prüfung antreten kannst, sagt dir dein Prüfungsausschuss. Mehr dazu: Mindestalter (/mindestalter-bootsfuehrerschein/).“
- **Begründung:** Die vorgezogene Prüfung „bis zu drei Monate vorher“ ist laut Fach nur aus Repo-Leitfaden und Sekundärquellen belegt [nicht amtlich belegt]. Der Rahmen verlangt bei strittigen Fakten den strengeren Experten. Sobald der SpFV-Wortlaut die Regel bestätigt, gilt die Fassung aus `_faq-aenderungen-2.md`. Endgültig entscheidet die Fassung von Seite 21 (Moderation 2).
- **Experte:** 1, 5

### ref 15 – Kann ich die amtliche Prüfung online ablegen?
- **Alt:** „Die Theorie kannst du online lernen. Die amtliche Prüfung selbst findet nach den geltenden Vorgaben des zuständigen Prüfungsausschusses statt. Informiere dich bei der Anmeldung über Prüfungsort und Ablauf.“
- **Neu:** „Nein. Online lernst du die Theorie. Die amtliche Prüfung – Theorie und Praxis – legst du vor Ort bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl ab.“
- **Begründung:** Wie `_faq-aenderungen-2.md`, aber ohne den Satz „Online stellst du höchstens den Antrag.“ Ob Anträge online gestellt werden können, belegt keiner der Berichte; der Satz entfällt (nichts erfinden).
- **Experte:** 1

### ref 22 ★ – Gibt es eine Geld-zurück- oder Bestehensgarantie?
- **Alt:** siehe `DefaultFaqs.php` (u. a. „Einzelkurse, Pakete und Pässe … nicht für … Funkgerät-Simulator-Zugänge und zusätzlich zum gesetzlichen Widerrufsrecht“).
- **Neu (wortgleich mit Seite 9 und AGB Nr. 9; ersetzt die Fassung in `_faq-aenderungen-2.md`):**
  „Eine Bestehensgarantie gibt es nicht: Die Prüfung nimmt ein Prüfungsausschuss des DSV oder DMYV ab, und dein Ergebnis hängt von deiner Vorbereitung ab. Zusätzlich zu deinen gesetzlichen Rechten geben wir dir freiwillig eine Geld-zurück-Garantie:
  - **Frist:** 14 Tage ab Zahlungseingang, auf die Minute genau. Das Fristende siehst du unter „Mein Lernen“.
  - **Bedingung:** Du hast seit dem Zahlungseingang weniger als 20 % der gekauften Inhalte genutzt. Gezählt werden die seit dem Zahlungseingang beantworteten Prüfungsfragen der gekauften Kurse (im Smarttrainer oder in den Quizfragen der Video-Lektionen, jede Frage einmal) und die abgeschlossenen Video-Lektionen. Bei der Theorie-Stufe zählen nur Fragen, beim Video-Upgrade nur Lektionen, bei Komplett, Kombis, Bundle und Pässen beides. Mehrere Produkte einer Bestellung werden zusammen berechnet.
  - **Gilt für:** Einzelkurse (Theorie und Komplett), Video-Upgrades, Ergänzungskurse, Kombikurse, das Kapitäns-Bundle und die Pässe – auch soweit sie den Funkgerät-Simulator enthalten.
  - **Gilt nicht für:** Gruppenpakete, eigenständige Zugänge zum Funkgerät-Simulator (Simulator-Pässe und Simulator-Upgrade) und bereits ganz oder teilweise erstattete Produkte.
  - **So geht’s:** Unter „Mein Lernen“ auf „Geld zurück anfordern“ klicken; eine Anfrage je Bestellung, Bestätigung per E-Mail. Wir erstatten den gezahlten Preis der Produkte mit Garantie über deine ursprüngliche Zahlungsart. Produkte ohne Garantie bleiben nutzbar; der Zugang zu den erstatteten Kursen endet.
  - **Widerrufsrecht:** Die Garantie ist kein Widerrufsrecht. Bei digitalen Kursen erlischt das Widerrufsrecht, sobald dein Zugang nach deiner Zustimmung in der Kasse freigeschaltet ist.

  Alle Bedingungen: Geld-zurück-Garantie (/geld-zurueck-garantie-bootsfuehrerschein/) und {{agb}}.“
- **Ergänzen, sobald festgelegt:** „… spätestens [X] Tage nach Eingang deiner Anfrage.“ hinter „über deine ursprüngliche Zahlungsart“ – gleichlautend mit AGB Nr. 9, Seite 9, Produkttext und „Mein Lernen“ (Recht, Punkt h).
- **Begründung:** § 443 BGB – die FAQ ist Garantiewerbung und darf nicht weiter gehen als AGB Nr. 9. Gegenüber `_faq-aenderungen-2.md` ergänzt: „seit dem Zahlungseingang“ bei der Zählung, „beides“ für Komplett/Kombi/Bundle/Pässe, gemeinsame Berechnung je Bestellung, Fristende minutengenau, Bestätigung per E-Mail, „Produkte ohne Garantie bleiben nutzbar“, „auch soweit sie den Simulator enthalten“. Link auf Seite 9 statt nur auf die AGB (SEO).
- **Experte:** 2, 5; Rahmen

### ref 21 – Kann ich einen Kurs verschenken? (Fassung B, erst mit Gutscheinprodukt)
- **Alt:** „Wenn Gutscheine angeboten werden, kannst du einen Kurs als Geschenk erwerben. Informationen findest du hier: [Link zur Gutscheinseite].“
- **Neu, Fassung A (sofort):** wie `_faq-aenderungen-2.md` („Gutscheine bieten wir derzeit nicht an … Gruppenpaket“).
- **Neu, Fassung B (erst wenn Seite 8 veröffentlicht wird):** „Ja, mit einem Gutschein. Ein normal gekaufter Kurs gehört zu dem Konto, mit dem er gekauft wurde, und ist nicht übertragbar – deshalb gibt es den Gutschein. Die beschenkte Person löst ihn im eigenen kostenlosen Kundenkonto ein. Gültigkeit und Bedingungen: Bootsführerschein-Gutschein (/bootsfuehrerschein-gutschein/).“
- **Begründung:** Seite 8 ist als fertiger Entwurf angelegt; FAQ und Seite müssen gleichzeitig umgestellt werden, sonst wirbt die FAQ mit einem Produkt, das es nicht gibt (Recht: Lockangebot).
- **Experte:** 2, 5

### ref 10 ★ – Wie lange habe ich Zugriff auf die Lernplattform? (empfohlen)
- **Alt:** „Je nach Kurs gilt der Zugang 12 oder 6 Monate ab Zahlungseingang. Pässe laufen 30 oder 90 Tage; Zugänge zum Funkgerät-Simulator 14 Tage, 3 oder 12 Monate. Die genaue Laufzeit steht beim jeweiligen Produkt. Wenn du denselben Kurs erneut kaufst, verlängert sich dein Zugang.“
- **Neu:** „Sportbootführerscheine, Funkzeugnisse, Kombis, das Kapitäns-Bundle und Gruppenpakete laufen 12 Monate ab Zahlungseingang, SKN, FKN und Ergänzungskurse 6 Monate. Pässe laufen 30 oder 90 Tage, Simulator-Zugänge 14 Tage, 3 oder 12 Monate. Das Video-Upgrade läuft bis zum Ende deines Theoriezugangs. Bei Gruppenpaketen endet die Laufzeit für alle gleichzeitig. Kaufst du denselben Kurs erneut, schließt die neue Laufzeit an.“
- **Begründung:** Der Rahmen nennt FAQ 10 ausdrücklich. Die alte Fassung ist richtig, aber unvollständig (Video-Upgrade, Gruppen; AGB Nr. 6). Moderation 2 hat ohne Befund übernommen; die Ergänzung ist optional, aber AGB-gleich.
- **Experte:** 2

### ref 92 ★ – Ist die amtliche Prüfung im Kurs enthalten? (empfohlen)
- **Alt:** „Nein. Der Kurs bereitet dich online vor. Die amtliche Prüfung legst du bei einem Prüfungsausschuss ab; Anmeldung, Termin und Prüfungsgebühr laufen direkt über den Ausschuss.“
- **Neu:** „Nein. Der Kurs bereitet dich online vor. Die amtliche Prüfung legst du bei einem Prüfungsausschuss des DSV oder DMYV deiner Wahl ab; Anmeldung, Termin und Prüfungsgebühr laufen direkt über den Ausschuss.“
- **Begründung:** Einheitliche Formulierung „deiner Wahl“ auf allen Seiten (Fach, Querschnitt 2).
- **Experte:** 1

---

## C. Neue FAQs (Vorschlag, Nummern ab 98)

| neu | Rubrik › Unterkategorie | Frage | Antwort | Begründung | Experte |
|---|---|---|---|---|---|
| 98 | allgemein › Scheinwahl und Geltungsbereich | Ich wohne in Österreich oder der Schweiz – lohnt sich ein deutscher SBF? | „Für Gewässer in deinem Wohnland oft nicht. In Österreich gelten ausländische Scheine vor allem für Gäste mit Wohnsitz im Ausland, und ein Befähigungsausweis ist schon ab 4,4 kW nötig. In der Schweiz brauchst du nach zwölf Monaten Wohnsitz einen Schweizer Ausweis. Für deutsche Gewässer kann der deutsche SBF trotzdem sinnvoll sein. Kläre das vor dem Kurskauf mit der zuständigen Behörde. Mehr dazu: Wohnsitz in Österreich (/deutscher-bootsfuehrerschein-oesterreich/) und in der Schweiz (/deutscher-bootsfuehrerschein-schweiz/).“ | Fach: größtes Fehlkauf-Risiko; Antwort kurz, Details auf den Seiten 3/4 (SEO). Erst veröffentlichen, wenn die Seiten 3 und 4 live sind, sonst Links weglassen. | 1, 4 |
| 99 | allgemein › Kosten, Gutscheine und Garantien | Gibt es Rabatte oder Ermäßigungen? | „Rabattcodes und Ermäßigungen für bestimmte Personengruppen bieten wir derzeit nicht an. Sparen kannst du mit dem passenden Paket: Kombikurse, Kapitäns-Bundle, Pässe, Ergänzungskurse für Inhaber eines Scheins und Gruppenpakete. Die Ersparnis bei Kombis berechnet der Shop aus den aktuellen Einzelpreisen. Mehr dazu: Preise und Kombiangebote (/bootsfuehrerschein-rabatte/).“ | Angebot: keine Codes, keine Ermäßigungsregeln (MemberDiscounts ohne Regel); Recht: kein Rabattversprechen. | 2, 5 |

---

## D. FAQ-Auszüge auf den Seiten 1–17 und was vorher erledigt sein muss

Voraussetzung für alle Auszüge: Das Shortcode-Attribut `schema="0"` (kein FAQPage-JSON-LD außerhalb von Seite 34) ist im Plugin umgesetzt (`FaqShortcode.php`, Rahmen). Bis dahin die Auszüge nicht einbinden.

| Seite | Shortcode bzw. Muster | Vorher korrigieren |
|---|---|---|
| 1, 11, 13 | Muster `kurswasser/faq` (★ allgemein, kein Schema) | 22, 89 (★) |
| 2 | `subcategory="allgemein-scheinwahl-und-geltungsbereich"` | 1, 2, 3; neu 98 optional |
| 5 | `rubric="SBF-SEE" subcategory="sbf-see-pruefung"` | 31 |
| 6 | `subcategory="allgemein-pruefung-und-pruefungsablauf"` | 12, 13 (Papierkorb), 14, 15, 16, 17, 18, 20; neu 94 |
| 7 | `subcategory="allgemein-gruppenangebote"` | 91 |
| 8 | `subcategory="allgemein-kosten-gutscheine-und-garantien"` | 19, 20, 21 (Fassung B), 22; neu 99 |
| 12 | `subcategory="allgemein-lernplattform-und-zugang"` | 10 (optional), 88, 89; neu 97 |
| 16 | `subcategory="allgemein-voraussetzungen"` | 4, 5 |
| 17 | `rubric="SBF-SEE-BIN"` | 75, 76, 77 |
| 9 | kein Auszug (würde nur FAQ 22 wiederholen) | 22 (wortgleich mit Seite 9) |

Außerhalb der FAQ (gleicher Fehler wie alte FAQ 22): Muster `patterns/guarantee.php` auf den Wortlaut von Seite 9 bringen; Muster und Vertrauenszeile `kurswasser_offer_trust()` (offers.php:107, Text korrekt) auf `/geld-zurueck-garantie-bootsfuehrerschein/` statt nur auf den AGB-Anker verlinken (Recht, SEO). Siehe auch `_faq-aenderungen-2.md`, Teil E.
