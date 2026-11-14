# Support-Abläufe und Textbausteine (To-do T58)

Vorlage für den Kundensupport. Die Abläufe beschreiben, was die Software tatsächlich tut (Stand: Plugin
`bootspruefung-learning`, Theme `kurswasser`, AGB-Vorlage `themes/kurswasser/legal/agb.html`). Die Textbausteine sind
Entwürfe: vor dem Einsatz an die veröffentlichten AGB, die Widerrufsbelehrung und die Datenschutzerklärung anpassen
und rechtlich prüfen lassen. **Keine Rechtsberatung.**

Platzhalter: `[Name]`, `[Bestellnummer]`, `[Datum]`, `[Firma]` usw. vor dem Senden ersetzen. Unterschrift und
Anbieter-Angaben kommen in Antworten aus dem eigenen Postfach von Hand dazu (Mailprogramm-Signatur mit Firma, Anschrift,
E-Mail; dieselben Angaben wie unter Design › Betreiberangaben).

## Grundregeln

- **Eingang:** Anfragen kommen an die Support-E-Mail (Kontaktseite), als Antwort auf Shop- und Plugin-Mails an den
  Absender (WooCommerce › Einstellungen › E-Mails) und als Bestellnotiz bzw. Mail aus der Widerrufsfunktion. Alle
  Postfächer mindestens werktäglich lesen, auch Bounces (siehe `docs/mailversand.md`).
- **Identität prüfen:** Auskünfte zu Bestellungen, Zugängen und Daten nur an die E-Mail-Adresse des Kontos bzw. der
  Bestellung. Kommt die Anfrage von einer anderen Adresse: um Antwort von der Konto-Adresse bitten, keine Daten nennen.
- **Nie per Mail anfordern:** Passwörter, vollständige Karten- oder Kontodaten, Ausweiskopien.
- **Dokumentieren:** Jede Zusage und jede Erstattung als Bestellnotiz in WooCommerce (Bestellung › Notiz hinzufügen).
- **Fristen:** Eingangsdatum notieren. Gesetzliche Fristen (Widerruf, Datenschutz) und die Erstattungsfrist der
  Garantie (AGB Nr. 9, Betreiberangaben „Erstattungsfrist“) im Blick behalten.

## 1. Widerruf

**Was die Software tut**

- Kunden erklären den Widerruf online über „Vertrag hier widerrufen“ (Fußzeile, `/vertrag-widerrufen/`, „Mein Lernen“,
  Bestellansicht). Das ist die WooCommerce-Funktion „Order withdrawal“; sie muss unter WooCommerce › Einstellungen ›
  Erweitert › Funktionen eingeschaltet sein (das Plugin schaltet sie einmalig ein und warnt im Admin, wenn sie aus ist).
- WooCommerce schickt dem Kunden eine Eingangsbestätigung und vermerkt den Widerruf an der Bestellung
  (Spalte bzw. Hinweis „Widerruf eingegangen“).
- **Rückzahlung und Ende des Zugangs macht der Betreiber von Hand:** Bestellung öffnen › „Erstattung“ › betroffene
  Positionen erstatten. Mit der Erstattung endet der Zugang zu diesen Kursen automatisch.
- Hat der Kunde in der Kasse zugestimmt, dass die Bereitstellung sofort beginnt (Pflicht-Checkbox, § 356 Abs. 5 BGB),
  kann das Widerrufsrecht erloschen sein (AGB Nr. 10). Ob das im Einzelfall gilt, prüft der Betreiber bzw. die
  Rechtsberatung – die Zustimmung steht in der Bestellbestätigung und an der Bestellung.

**Ablauf**

1. Widerruf in der Bestellung ansehen (Datum, Positionen). Frist prüfen (Widerrufsbelehrung).
2. Entscheiden: erstatten oder ablehnen (Widerrufsrecht erloschen). Im Zweifel: Geld-zurück-Garantie prüfen (Abschnitt 2),
   sie gilt unabhängig davon.
3. Erstatten über „Erstattung“ in der Bestellung, Bestellnotiz schreiben, Kunden informieren.

**Textbaustein: Widerruf erstattet**

> Hallo [Name],
>
> deinen Widerruf zu Bestellung #[Bestellnummer] haben wir erhalten und bearbeitet. Wir haben [Betrag] über deine
> ursprüngliche Zahlungsart erstattet. Je nach Zahlungsart dauert es einige Tage, bis der Betrag auf deinem Konto ist.
> Mit der Erstattung endet dein Zugang zu [Kurs].
>
> Viele Grüße
> [Name, Firma]

**Textbaustein: Widerrufsrecht erloschen (nur nach Prüfung)**

> Hallo [Name],
>
> danke für deine Nachricht zu Bestellung #[Bestellnummer]. Bei der Bestellung hast du zugestimmt, dass wir sofort mit
> der Bereitstellung der digitalen Inhalte beginnen, und bestätigt, dass dein Widerrufsrecht damit erlischt. Wir haben
> den Zugang am [Datum] freigeschaltet. Deshalb können wir den Widerruf leider nicht annehmen (siehe AGB Nr. 10 und
> Widerrufsbelehrung).
>
> [Falls zutreffend:] Unabhängig davon kannst du bis [Fristende] die Geld-zurück-Garantie nutzen: in deinem Konto
> unter „Mein Lernen“ mit dem Knopf „Geld zurück anfordern“.
>
> Viele Grüße
> [Name, Firma]

## 2. Geld-zurück-Garantie

**Was die Software tut** (AGB Nr. 9)

- Frist 14 Tage ab Zahlungseingang (auf die Minute); Bedingung: weniger als 20 % der gekauften Inhalte genutzt. Stand
  und Fristende sieht der Kunde unter „Mein Lernen“.
- Anfrage nur über den Knopf „Geld zurück anfordern“ unter „Mein Lernen“, eine Anfrage je Bestellung. Der Kunde bekommt
  eine Bestätigungsmail, der Betreiber eine Mail „Geld-zurück-Anfrage zu Bestellung #…“ (an die Admin-Adresse) und eine
  Bestellnotiz mit Lernstand, Frist und den zu erstattenden Positionen.
- Ausgenommen: Gruppenpakete, eigenständige Simulator-Zugänge, schon erstattete Positionen. Per Gutschein bezahlte
  Anteile werden nicht als Geld erstattet (steht in Notiz und Mail).
- Eine Datenschutz-Löschung setzt den gezählten Lernstand nicht zurück.
- **Erstattung macht der Betreiber von Hand** über „Erstattung“ in der Bestellung, spätestens innerhalb der
  Erstattungsfrist (Betreiberangaben). Der Zugang zu den erstatteten Kursen endet dann automatisch.

**Ablauf**

1. Mail „Geld-zurück-Anfrage“ öffnen, Link zur Bestellung folgen, Bestellnotiz lesen.
2. Genau die genannten Positionen über „Erstattung“ erstatten (Zahlungsart des Kunden, wenn das Gateway es kann;
   sonst Überweisung und „manuell erstatten“).
3. Kunden kurz informieren (Textbaustein).

**Textbaustein: Garantie erstattet**

> Hallo [Name],
>
> wir haben deine Geld-zurück-Anfrage zu Bestellung #[Bestellnummer] bearbeitet und [Betrag] über deine ursprüngliche
> Zahlungsart erstattet. Dein Zugang zu [Kurs] ist damit beendet. Dein Kundenkonto bleibt bestehen; du kannst jederzeit
> wieder einen Kurs kaufen.
>
> Viele Grüße
> [Name, Firma]

**Textbaustein: Anfrage außerhalb der Garantie (per Mail statt Knopf)**

> Hallo [Name],
>
> danke für deine Nachricht. Die Geld-zurück-Garantie forderst du in deinem Konto unter „Mein Lernen“ mit dem Knopf
> „Geld zurück anfordern“ an. Dort siehst du auch, ob die Bedingungen erfüllt sind: Für Bestellung #[Bestellnummer]
> [ist die Frist am [Datum] abgelaufen / liegt dein Lernstand bei [x] % und damit über der Grenze von 20 % /
> gilt die Garantie für [Produkt] nicht (AGB Nr. 9)]. Deine gesetzlichen Rechte (Widerruf, Mängel) bleiben davon
> unberührt.
>
> Viele Grüße
> [Name, Firma]

## 3. Datenschutzanfragen (Auskunft/Export, Löschung)

**Was die Software tut**

- **Selbstbedienung:** Kunden fordern unter Mein Konto › „Meine Daten“ (oder in der App) einen Datenexport an oder
  beantragen die Löschung ihres Kontos. WordPress schickt einen Bestätigungslink an die Konto-Adresse.
- **Löschung erst nach Klick** auf „Konto jetzt löschen“ auf der Seite des Links (nicht schon beim Öffnen des Links).
- Mit der Einstellung Bootsprüfung › Einstellungen › „Datenschutz-Anträge: Bestätigte Anträge automatisch ausführen“
  (Standard: an) passiert danach alles automatisch: Export als Download-Link per Mail bzw. Löschung von Lerndaten
  und Kundenkonto; Bestellungen werden anonymisiert, wenn das in WooCommerce › Einstellungen › Konten & Datenschutz
  eingestellt ist (steuerlich nötige Felder bleiben). **Konten mit Verwaltungsrechten werden nie automatisch
  gelöscht.**
- Ist die Einstellung aus oder kommt die Anfrage per Mail: Werkzeuge › „Personenbezogene Daten exportieren“ bzw.
  „… löschen“ › Anfrage anlegen (E-Mail-Adresse) › WordPress schickt den Bestätigungslink › nach Bestätigung
  ausführen.
- Abmeldung von Ablauf-Erinnerungen: Link in jeder Erinnerungsmail oder unter „Mein Lernen“ (kein Support nötig).

**Ablauf bei Anfrage per Mail**

1. Eingang und Datum notieren (gesetzliche Frist beachten; in der Regel ein Monat – mit Rechtsberatung klären).
2. Anfrage in Werkzeuge anlegen; der Bestätigungslink geht an die Konto-Adresse (prüft die Identität).
3. Nach Bestätigung ausführen bzw. automatisch ausführen lassen, Ergebnis kurz bestätigen.
4. Was nicht gelöscht wird (Aufbewahrungspflichten), im Antwortsatz nennen – passend zur Datenschutzerklärung.

**Textbaustein: Anfrage erhalten**

> Hallo [Name],
>
> danke für deine Anfrage zu deinen Daten. Damit niemand anderes Daten über dich anfordern kann, haben wir dir eine
> E-Mail mit einem Bestätigungslink an die Adresse deines Kundenkontos geschickt. Bitte bestätige dort.
> [Löschung:] Nach dem Klick auf „Konto jetzt löschen“ löschen wir dein Konto und deinen Lernstand. Gekaufte Zugänge
> kannst du danach nicht mehr nutzen. Bestelldaten bewahren wir auf, soweit wir gesetzlich dazu verpflichtet sind
> (siehe Datenschutzerklärung).
>
> Du kannst das übrigens auch selbst erledigen: Mein Konto › „Meine Daten“.
>
> Viele Grüße
> [Name, Firma]

**Textbaustein: Löschung erledigt**

> Hallo [Name],
>
> wir haben dein Kundenkonto und deinen Lernstand am [Datum] gelöscht. Bestelldaten, die wir aus steuer- und
> handelsrechtlichen Gründen aufbewahren müssen, haben wir von persönlichen Angaben getrennt [bzw. aufbewahrt, wie in
> der Datenschutzerklärung beschrieben].
>
> Viele Grüße
> [Name, Firma]

## 4. Mängel und Gewährleistung

**Was gilt** (AGB Nr. 7, 8, 11): gesetzliche Rechte bei Mängeln digitaler Produkte (§§ 327 ff. BGB). Fehlermeldungen
an die E-Mail aus den AGB; die Rechte hängen nicht von der Meldung ab.

**Ablauf**

1. Fehler nachstellen: Kurs, Seite bzw. Frage-Nummer, Gerät/Browser, Bildschirmfoto erfragen.
2. Inhaltlicher Fehler (Frage, Antwort, Erklärung): an die fachliche Prüfung geben, Korrektur über den Content-Import.
   Technischer Fehler: Bootsprüfung › Systemstatus prüfen, ggf. Entwicklung.
3. Kunden über Behebung informieren. Ist der Kurs länger nicht nutzbar, mit dem Kunden eine Lösung abstimmen
   (z. B. Verlängerung des Zugangs im Admin unter Bootsprüfung › Zugänge oder Teilerstattung) – rechtliche Ansprüche im Zweifel
   mit der Rechtsberatung klären.

**Textbaustein: Fehler gemeldet**

> Hallo [Name],
>
> danke für deinen Hinweis zu [Kurs/Frage]. Wir sehen uns das an. Damit wir den Fehler schnell finden: Auf welchem
> Gerät und in welchem Browser ist er aufgetreten, und kannst du uns ein Bildschirmfoto schicken?
> Wir melden uns, sobald der Fehler behoben ist.
>
> Viele Grüße
> [Name, Firma]

**Textbaustein: Fehler behoben**

> Hallo [Name],
>
> der Fehler bei [Kurs/Frage] ist behoben. Die Änderung ist sofort für dich sichtbar, du musst nichts installieren.
> [Falls vereinbart:] Als Ausgleich haben wir deinen Zugang bis [Datum] verlängert.
>
> Viele Grüße
> [Name, Firma]

## 5. Zugangsprobleme

**Typische Ursachen und was die Software tut**

| Meldung | Ursache prüfen | Lösung |
|---|---|---|
| „Kurs nicht freigeschaltet“ nach Kauf | Bestellstatus: bei Überweisung „In Wartestellung“ bis zum Zahlungseingang | Zahlungseingang prüfen, Bestellung auf „In Bearbeitung“/„Fertiggestellt“ setzen; der Zugang entsteht automatisch. Notfalls Bestellaktion „Lernzugang abgleichen“. |
| Zugang „ruht“ | Rückbuchung oder offener Käuferschutzfall (AGB Nr. 6) | Fall beim Zahlungsdienst klären; nach Klärung Bestellaktion „Manuelle Lernzugang-Entscheidung aufheben“ bzw. „Lernzugang abgleichen“ |
| Zugang abgelaufen | Laufzeit in „Mein Lernen“ | Verlängerung kaufen (Laufzeit schließt an); Lernstand bleibt erhalten |
| Gruppenmitglied ohne Zugang | Einladung abgelaufen (30 Tage), Plätze voll, falsche E-Mail | Gruppenverwalter lädt neu ein (Meine Gruppen) |
| Kursgutschein geht nicht | Gutschein im Warenkorb statt unter „Mein Lernen“ eingegeben | Kursgutscheine unter „Mein Lernen“ bzw. in der App einlösen |
| Falsches Konto | Kauf mit anderer E-Mail-Adresse | Bestellung prüfen, Kunde meldet sich mit dem Kauf-Konto an |

**Textbaustein: Überweisung noch nicht eingegangen**

> Hallo [Name],
>
> deine Bestellung #[Bestellnummer] ist bei uns, die Überweisung ist aber noch nicht eingegangen. Sobald das Geld auf
> unserem Konto ist, schalten wir deinen Zugang automatisch frei – du bekommst dann eine E-Mail. Die Bankdaten findest
> du in der Bestellbestätigung und in deinem Konto unter Bestellungen.
>
> Viele Grüße
> [Name, Firma]

## 6. Login-Sperre

**Was die Software tut:** Nach mehreren Fehlversuchen wird die Anmeldung für 15 Minuten gesperrt (je IP 10, je Konto
und IP 5, je Konto insgesamt 30 Fehlversuche in 15 Minuten; Web und App zusammen). Die Meldung ist neutral. Über
„Passwort vergessen“ setzt der Kunde ein neues Passwort; damit ist die Sperre für sein Konto sofort aufgehoben.
Ist ein Sicherheits-Plugin mit eigener Login-Sperre aktiv, gilt dessen Regel.

**Textbaustein: Login gesperrt**

> Hallo [Name],
>
> nach mehreren falschen Passwörtern sperren wir die Anmeldung zum Schutz deines Kontos für 15 Minuten. Du musst nicht
> warten: Klicke auf der Anmeldeseite auf „Passwort vergessen“ und setze ein neues Passwort über den Link in der
> E-Mail. Danach kannst du dich sofort anmelden.
> Kommt keine E-Mail an, sieh bitte im Spam-Ordner nach und prüfe, ob du die E-Mail-Adresse deines Kontos eingegeben
> hast.
>
> Viele Grüße
> [Name, Firma]

## 7. Checkliste Marke, Domain, Versicherungen (Fragen an den Betreiber)

Nur Fragen zur Klärung – **keine Rechtsberatung, keine Empfehlung bestimmter Anbieter**. Ergebnisse in die rechte
Spalte eintragen.

### Marke und Name

| Frage | Antwort / Datum |
|---|---|
| Ist der Markenname (Website-Name, Logo, App-Name) auf Verwechslung mit bestehenden Marken geprüft (z. B. durch Markenrecherche bei einer Fachperson)? | |
| Soll der Name als Marke angemeldet werden? Wenn ja: für welche Klassen und Länder? | |
| Stimmen Firmenname (Impressum, AGB, Mail-Fuß) und Markenname dort, wo beide stehen, sauber getrennt? | |
| Sind Rechte an Logo, Fotos, Videos und Texten schriftlich geklärt (Urheber, Nutzungsumfang)? | |
| Sind App-Store-Name und Entwicklerkonto auf den Betreiber (nicht auf eine Privatperson) eingetragen? | |

### Domain und technische Konten

| Frage | Antwort / Datum |
|---|---|
| Ist die Domain auf den Betreiber registriert (Inhaber, nicht nur Admin-Kontakt)? | |
| Automatische Verlängerung an, Zahlungsmittel gültig, Ablaufdatum notiert? | |
| Wichtige Schreibweisen bzw. Endungen (z. B. mit/ohne Bindestrich) gesichert und auf die Hauptdomain weitergeleitet? | |
| Zugänge zu Registrar, Hoster, DNS, Mail-Dienst, Zahlungsdiensten: mit Zwei-Faktor-Anmeldung, an mindestens zwei Personen bzw. im Notfall zugänglich? | |
| DNS-Einträge (SPF, DKIM, DMARC) gesetzt (siehe `docs/mailversand.md`)? | |

### Versicherungen

| Frage | Antwort / Datum |
|---|---|
| Besteht eine Betriebshaftpflicht? Deckt sie Online-Angebote und digitale Inhalte ab? | |
| Besteht eine Vermögensschaden-Haftpflicht (z. B. für Schäden durch fehlerhafte Lerninhalte)? Gilt sie für Prüfungsvorbereitung? | |
| Besteht eine Cyber-Versicherung (Datenpanne, Ausfall, Erpressung, Wiederherstellung, Benachrichtigung von Betroffenen)? | |
| Sind Urheberrechts- und Wettbewerbsrechts-Abmahnungen mitversichert (z. B. Rechtsschutz)? | |
| Welche Pflichten stellen die Versicherer (z. B. Backups, Updates, Zwei-Faktor-Anmeldung), und sind sie erfüllt? | |
| Wer ist im Schadensfall Ansprechpartner, wo liegen die Policen? | |

## 8. Search Console und Sitemap

**Was die Software tut**

- Ohne SEO-Plugin: WordPress-Sitemap unter `/wp-sitemap.xml`. Das Theme nimmt Seiten mit „Nicht in Suchmaschinen“
  (noindex) sowie Warenkorb, Kasse und Mein Konto heraus; das Plugin entfernt die Benutzer-Sitemap und die
  Kunden-FAQ. Der Lernbereich `/bootsscheine/` ist keine WordPress-Seite, steht nicht in der Sitemap und ist noindex.
- Mit Yoast SEO: Yoast-Sitemap unter `/sitemap_index.xml`. Das Theme schließt Warenkorb, Kasse, Mein Konto und Seiten
  mit Theme-noindex aus (auch wenn die SEO-Daten nicht in die Yoast-Felder geschrieben werden).
- Wichtig: Solange unter Einstellungen › Lesen „Suchmaschinen davon abhalten, diese Website zu indexieren“ angehakt
  ist, gibt WordPress keine Sitemap aus.

**Schritte für den Betreiber (nach dem Livegang)**

1. Einstellungen › Lesen: Haken „Suchmaschinen davon abhalten …“ entfernen (erst auf der Live-Domain).
2. `/wp-sitemap.xml` bzw. `/sitemap_index.xml` im Browser öffnen: Inhaltsseiten, Kurse und Produkte vorhanden,
   keine Seiten für Warenkorb, Kasse, Mein Konto, keine Entwürfe.
3. Google Search Console: Domain-Property anlegen und per DNS-Eintrag bestätigen (beim Domain-Anbieter).
   Sitemap-Adresse unter „Sitemaps“ einreichen. Optional auch bei Bing Webmaster Tools.
4. Nach einigen Tagen „Seiten“-Bericht prüfen: Seiten mit „Durch noindex-Tag ausgeschlossen“ sollen genau die
   gewollten sein (Lernbereich, Konto, Kasse, bewusst noindex gesetzte Inhaltsseiten).
5. Zugang zur Search Console an eine zweite Person bzw. ein Betreiber-Konto geben (nicht nur ein privates Konto).

## 9. Gutscheinfeld

Das Gutscheinfeld in Warenkorb und Kasse erscheint nur, wenn es mindestens einen veröffentlichten, nicht abgelaufenen
WooCommerce-Gutschein gibt (Marketing › Gutscheine), oder wenn ein Gutschein-Plugin wie Voucher Captain aktiv ist.
Kursgutscheine werden unabhängig davon unter „Mein Lernen“ bzw. in der App eingelöst. Abschalten: Option
`bp_learning_hide_unused_coupon_field` auf `no` (z. B. `wp option update bp_learning_hide_unused_coupon_field no`)
oder Filter `bootspruefung_hide_coupon_field` (Details in `docs/schnittstellen-erweiterungen.md`).
