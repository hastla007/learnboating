# Inhaltsseiten-Entwürfe: was fehlt bis zur Freigabe (To-do T18)

16 der 35 Inhaltsseiten sind Entwürfe. Je Seite: was offen ist (aus der Redaktionsrunde) und welche Platzhalter
`[[Betreiber: …]]` noch fehlen. Platzhalter mit ✓ füllt Design › Betreiberangaben automatisch beim nächsten Abgleich
(Admin-Hinweis „Inhaltsseiten abgleichen“ bzw. `wp kurswasser content-pages sync`); alle anderen ergänzt du auf der
Seite selbst oder in der Quelle `themes/kurswasser/content/src/NN-…md` (dann `python3 themes/kurswasser/tools/content_build.py themes/kurswasser/content/src`).

Entwürfe sind für Gäste nicht erreichbar und werden aus Menüs, Weiterlesen-Kacheln und der Fußzeile herausgefiltert.
Veröffentlichen erst, wenn alle Punkte der Seite erledigt sind; Seiten mit „nur zusammen mit …“ gemeinsam veröffentlichen.

## 3. Deutscher Bootsführerschein mit Wohnsitz in Österreich (`/deutscher-bootsfuehrerschein-oesterreich/`)

Offen:
- Betreiber/Steuerberatung: Verkauf an Verbraucher in Österreich klären (Umsatzsteuer des Kundenlands bzw. OSS, Preisangabe „inkl. MwSt.“). Bis dahin Entwurf (Recht).
- Aussage „häufig nicht anerkannt“ stützt sich auf § 101 Abs. 3 SchFG und eine Fachquelle (Bootsschule); vor Veröffentlichung am RIS-Text gegenlesen (Fach).
- Zuverlässigkeitsnachweis aus Österreich (Führerschein, Strafregisterbescheinigung): keine belegte Aussage möglich, daher nur Hinweis auf Klärung beim Ausschuss.

## 4. Deutscher Bootsführerschein mit Wohnsitz in der Schweiz (`/deutscher-bootsfuehrerschein-schweiz/`)

Offen:
- Betreiber/Steuerberatung: Verkauf an Schweizer Privatkunden klären (Schweizer MWST-Pflicht ab weltweitem Umsatz von CHF 100.000, Preisangabe). Bis dahin Entwurf (Recht).
- Art. 91a BSV am amtlichen Text (fedlex.admin.ch, SR 747.201.1) gegenlesen; Fach zitiert über lawbrary.ch.
- Schweizer Schwellenwerte (Kategorie A/D) bewusst nicht genannt, weil die Quelle eine Altfassung zeigt (Fach).

## 5. SBF-Fragenkatalog: Prüfungsfragen üben, Prüfung simulieren (`/amtliche-pruefungsboegen-sbf/`)

Offen:
- Betreiber: Stand bzw. Fassung des Fragenkatalogs je Kurs liefern (Platzhalter im Abschnitt „Katalog oder Übung?“). Pflicht laut Recht; bis dahin Entwurf.
- Rechte an Fragen und ELWIS-Abbildungen sind nur vorläufig freigegeben (offene-punkte Nr. 30, blockiert Go-live); Bildnachweise im Impressum klären.
- ELWIS-Deep-Link zu den Fragenkatalogen SBF See und Binnen prüfen und einsetzen (Fach); bis dahin Link auf die ELWIS-Startseite.
- Prüfen, ob es nach der Bekanntmachung vom April 2023 weitere Katalogänderungen gab (ELWIS war für den Fachexperten nicht erreichbar).
- Downloads (PDF) beworben; PDFs vor Livegang hochladen (offene-punkte Nr. 34).
- FAQ 31 korrigieren (siehe _faq-aenderungen-1.md); Shortcode-Attribut schema="0" umsetzen.

Platzhalter:
- Fassung bzw. Stand des Fragenkatalogs je Kurs

## 8. Bootsführerschein-Gutschein: Onlinekurs verschenken (`/bootsfuehrerschein-gutschein/`)

Offen:
- Es gibt kein Gutscheinprodukt (keine Zugangscodes; WooCommerce-Gutscheine sind nur Rabattcodes, CouponController.php:15-20). Seite bleibt Entwurf, bis Produkt, Einlösefunktion und AGB-Abschnitt „Gutscheine“ live sind.
- Betreiber festlegen und in die Platzhalter eintragen: Gutscheinart (Kurs oder Wert), Zustellweg, Einlöseweg, Beginn der Kurslaufzeit, Gültigkeitsdauer, Übertragbarkeit, Restguthaben/Barauszahlung, Geltung der Geld-zurück-Garantie.
- Recht: Gültigkeit nicht kürzer als die gesetzliche Verjährung (3 Jahre, § 195 BGB) ansetzen; kürzere Fristen sind riskant.
- Recht: Wertgutschein ist kein digitaler Inhalt. Die Pflicht-Zustimmung nach § 356 Abs. 5 BGB (DigitalConsent.php) darf beim Gutscheinkauf nicht zum Erlöschen des Widerrufsrechts führen; Checkout-Logik anpassen.
- Recht: In AGB Nr. 9 regeln, ob und ab wann die Geld-zurück-Garantie beim Gutschein gilt (Kauf oder Einlösung).
- FAQ 21 erst nach Produktstart auf die Fassung „mit Gutschein“ umstellen (siehe _faq-aenderungen-1.md).

Platzhalter:
- URL des Gutscheinprodukts
- einen bestimmten Kurs oder einen Wert
- Zustellweg, z. B. als PDF per E-Mail zum Ausdrucken oder Weiterleiten
- Einlöseweg, z. B. Code unter „Mein Lernen“ eingeben
- mit der Einlösung oder mit dem Kauf
- Gültigkeitsdauer des Gutscheins
- ja/nein
- Regel zu Restguthaben und Barauszahlung
- ob und ab wann die Geld-zurück-Garantie für den eingelösten Kurs gilt

## 9. 14 Tage Geld-zurück-Garantie – bei weniger als 20 % Nutzung (`/geld-zurueck-garantie-bootsfuehrerschein/`)

Offen:
- Betreiber: Name/Firma, Anschrift und E-Mail des Garantiegebers eintragen (§ 479 BGB; Platzhalter auch in AGB Nr. 1). Grund für Status Entwurf.
- Betreiber: verbindliche Erstattungsfrist festlegen und gleichlautend in AGB Nr. 9, auf dieser Seite, in FAQ 22, im Produkttext (ShopCatalog::guaranteeParagraph()) und unter „Mein Lernen“ (MoneyBackWoo) eintragen. Heute nennt keine Quelle eine Frist.
- Betreiber: E-Mail-Adresse für die persönliche Prüfung der Berechnung (Art. 22 Abs. 3 DSGVO) eintragen; Datenschutzerklärung Nr. 16 („automatisierte Entscheidung findet nicht statt“) vorsichtiger fassen (Recht).
- Code: MoneyBack::progress() rundet vor dem Vergleich mit 20 % auf eine Nachkommastelle (19,95 % gilt als 20,0 %). Ungerundet vergleichen; sonst Rundung in AGB, Seite und FAQ offenlegen (Recht, § 443 BGB).
- Muster „guarantee“ sagt „Einzelkurse, Pakete und Pässe – nicht für … Zugänge zum Funkgerät-Simulator“ und „zusätzlich zum gesetzlichen Widerrufsrecht“: an den Text unten angleichen. Muster und Vertrauenszeile (offers.php:107) auf diese Seite statt nur auf den AGB-Anker verlinken.
- Anwaltliche Freigabe des Textes vor dem Livegang (Recht).

Platzhalter:
- ✓ Firma, Anschrift, E-Mail
- ✓ Erstattungsfrist
- ✓ E-Mail-Adresse

## 22. Erfahrungen mit [Marke] (`/bootsfuehrerschein-erfahrungen/`)

Offen:
- Betreiber: echte, freigegebene Rückmeldungen liefern (Empfehlung SEO: mindestens etwa 10), je Rückmeldung Vorname oder Initialen, Kurs, Monat/Jahr, Quelle, Kennzeichnung „gekürzt“ und dokumentierte Einwilligung (Art. 6 Abs. 1 lit. a, Art. 7 DSGVO; bei Fotos § 22 KUG).
- Betreiber: Prüfverfahren einrichten und dokumentieren. Der Text unter „So prüfen wir Rückmeldungen“ beschreibt das empfohlene Verfahren (Kauf über Kundenkonto, Abgleich mit der Bestellung) – nur veröffentlichen, wenn es genau so läuft (§ 5b Abs. 3 UWG, Anhang Nr. 23b UWG).
- Betreiber: Auswahlkriterien festlegen (z. B. alle freigegebenen oder nach Kurs gemischt) und im Platzhalter eintragen (Anhang Nr. 23c UWG).
- Recht: Bitten um Bewertung per E-Mail sind Werbung (BGH VI ZR 225/17) – nur mit Einwilligung oder nach § 7 Abs. 3 UWG; Datenschutzerklärung um „Bewertungen und Erfahrungsberichte“ ergänzen (sie sagt derzeit „Werbe-E-Mails … versenden wir nicht“).
- Keine Sterne, keine Durchschnittswerte, kein AggregateRating/Review-Markup für die eigene Organisation; keine Erfolgs- oder Bestehensquoten.
- Betreiber bestätigen: keine Gegenleistung für Rückmeldungen (sonst offenlegen, § 5a Abs. 4 UWG; Anreize nur für positive Bewertungen sind unzulässig).
- Rückmeldeweg: Link auf /kontakt/ setzt eine funktionierende Kontaktseite voraus (Seite 33).

Platzhalter:
- Rückmeldung 1 – Wortlaut
- Vorname oder Initialen, Kurs, Monat/Jahr, Quelle, ggf. „gekürzt“
- Rückmeldung 2 – Wortlaut
- Rückmeldung 3 – Wortlaut
- Auswahlkriterium, z. B. „alle freigegebenen Rückmeldungen der letzten zwölf Monate“

## 23. Unsere Geschichte und Mission (`/geschichte-mission/`)

Offen:
- Betreiber: Unternehmensgeschichte liefern – Gründungsjahr, Anlass, Gründerinnen und Gründer, nachprüfbare Meilensteine. Keine unbelegten Angaben wie „seit X Jahren“ (§ 5 Abs. 2 Nr. 3 UWG).
- Sekundär-CTA zum Team erst aktiv schalten, wenn Seite 24 veröffentlicht ist; bis dahin „Kontakt aufnehmen“ → /kontakt/.
- Elternseite /ueber-uns/ (00-ueber-uns.md) muss vorher angelegt sein.

Platzhalter:
- Gründungsjahr und Anlass – warum {{brand}} gegründet wurde
- Wer {{brand}} gegründet hat und welchen Bezug die Personen zum Wassersport bzw. zum digitalen Lernen haben
- nachprüfbare Meilensteine, z. B. Start der ersten Kurse

## 24. Das Team hinter [Marke] (`/team/`)

Offen:
- Betreiber: je Person Name, Rolle, Aufgabenbereich, kurze Vorstellung und nur belegte Qualifikationen (z. B. Ausbilderschein, SKS/SSS) liefern; Fotos nur autorisiert, keine KI-Personenfotos.
- Betreiber: schriftliche oder elektronische Einwilligung je Person dokumentieren (Art. 6 Abs. 1 lit. a, Art. 88 DSGVO, § 26 Abs. 2 BDSG, § 22 KUG) und Ablauf für ausscheidende Mitarbeitende festlegen.
- Betreiber: Wer prüft die Inhalte fachlich? Nur eintragen, wenn eine benannte Person das tatsächlich tut. Mitglieder von Prüfungsausschüssen nicht als „Prüfer bei {{brand}}“ bewerben (Interessenkonflikt).
- SEO: je Person ein Anker (#vorname-nachname) für spätere Autorenangaben; Person-Schema über inc/seo.php.
- Elternseite /ueber-uns/ (00-ueber-uns.md) muss vorher angelegt sein.

Platzhalter:
- Name Person 1
- Rolle, z. B. Kursinhalte Sportbootführerscheine
- zwei bis drei Sätze zur Person und zur Aufgabe
- belegte Qualifikation, falls vorhanden
- Bereich – Nautik, Funk, Plattform oder Service
- Name Person 2
- Rolle
- Vorstellung
- Bereich
- Name Person 3
- Name und Qualifikation der Person, die Kursinhalte und Ratgeber fachlich prüft – nur, wenn sie das tatsächlich tut

## 25. Auszeichnungen und Qualität (`/auszeichnungen-qualitaetssiegel/`)

Offen:
- Betreiber: Gibt es Auszeichnungen, Zertifizierungen oder Testsiegel? Je Eintrag Name, Vergabestelle, Kriterien, Datum, Gültigkeit, Link zur Vergabestelle bzw. Fundstelle und Lizenz für das Siegel-Logo liefern. Ohne mindestens einen belegten Eintrag nicht veröffentlichen (Anhang Nr. 2 und 4 UWG, § 5 Abs. 2 Nr. 3 UWG).
- Keine leeren Badge-Flächen; Logos von DSV, DMYV oder Behörden nur mit Freigabe.
- Solange es keine Auszeichnungen gibt: noindex, Canonical auf /ueber-uns/ (die Qualitätspunkte stehen dort in Kurzform). Mit belegten Auszeichnungen auf index umstellen und Canonical leeren; dann award an der Organization (SEO).
- Bei Veröffentlichung ohne Siegel stattdessen nur den Abschnitt „So sichern wir Qualität“ nutzen und Titel/Slug ohne „Auszeichnungen“ wählen (Recht: Titel darf keine Auszeichnungen suggerieren).

Platzhalter:
- Name der Auszeichnung
- Vergabestelle
- worauf die Auszeichnung beruht
- Datum
- wo die Auszeichnung veröffentlicht ist

## 26. Studien und Untersuchungen (`/studien-untersuchungen/`)

Offen:
- Betreiber: Es liegt keine Untersuchung vor. Vor Veröffentlichung: tatsächliche Untersuchung mit Fragestellung, Datengrundlage, Zeitraum, Methode, Einschränkungen und Interessenkonflikten liefern.
- Recht/Datenschutz: Lerndaten werden laut Datenschutzerklärung nur für die eigene Lernansicht genutzt – jede Auswertung ist eine Zweckänderung. Nur mit echter Anonymisierung oder Einwilligung, Datenschutzerklärung vorher anpassen (Art. 5 Abs. 1 lit. b, Art. 6 Abs. 4 DSGVO). Befragungen per E-Mail sind Werbung (BGH VI ZR 225/17).
- Keine Erfolgs- oder Bestehensquoten und keine Vergleiche mit „amtlichen Durchfallquoten“ – eine offizielle Statistik ist nicht bekannt.
- Bis zur ersten Untersuchung: noindex, nicht in Menü oder Sitemap. Danach index; SEO-Idee: Ergebnis als Ratgeberartikel aufbereiten (z. B. „häufig falsch beantwortete Fragen“ auf Basis anonymisierter Daten).

Platzhalter:
- Titel der Untersuchung
- Was wollten wir herausfinden?
- Datengrundlage, Umfang, Anonymisierung bzw. Einwilligung
- von – bis
- Vorgehen und Auswertung
- Einschränkungen
- Hinweis auf eigenes Interesse als Anbieter
- Ergebnis in zwei bis vier Absätzen

## 27. Partner werden: Online-Theorie für Bootsschulen und Vereine (`/partnerprogramm/`)

Offen:
- Betreiber: Gibt es ein Partnerprogramm? Leistungen, Konditionen (Vergütung, Laufzeit, Vertragsbedingungen B2B), Ablauf, Ansprechperson und Kontaktweg festlegen. Bis dahin Entwurf (Recht, Angebot: kein Code, keine Bedingungen).
- Recht: Kooperationsanfragen per E-Mail/Formular brauchen einen Datenschutzabschnitt „Kontaktaufnahme“ (Art. 13 DSGVO); bei Datenweitergabe an Partner eigene Rechtsgrundlage und Hinweis.
- Produktname „Partner-Flat 50“ (ShopCatalog.php:230) ist nur ein Gruppenpaket mit 50 Plätzen und kann mit diesem Programm verwechselt werden – Umbenennung prüfen.
- Ein Affiliate-Programm (Seite 31) würde als Abschnitt hier aufgehen; Seite 29 (Partnerliste) verweist hierher.

Platzhalter:
- Leistungen für Partner, z. B. Ansprechperson, Material für Teilnehmende
- Vergütung oder Sonderkonditionen, Laufzeit, Vertragsbedingungen
- nächster Schritt, z. B. Gespräch und Angebot
- Start der Zusammenarbeit

## 28. Eine Ausbildungsstätte für die Praxis finden (`/ausbildungsstaetten-bootsfuehrerschein/`)

Offen:
- Betreiber: Gibt es ein geprüftes Verzeichnis von Ausbildungsstätten oder verlässliche öffentliche Listen? Ohne Verzeichnis erfüllt die Seite ihr Versprechen „finden“ nur teilweise – daher Entwurf. Bei einem Verzeichnis: Aufnahmekriterien, Aktualisierungsdatum, Ranking-Transparenz (§ 5b Abs. 2 UWG) und Kennzeichnung bezahlter Einträge (Anhang Nr. 11a UWG) liefern.
- Zusammengelegt mit Seite 6: noindex, Canonical auf /bootsfuehrerschein-standorte/. Alternativ 301 auf /bootsfuehrerschein-standorte/#ausbildungsstaetten (Design, SEO). Nur zusammen mit Seite 6 veröffentlichen.
- Fachliche Endkontrolle: Ausbildung keine Zulassungsvoraussetzung (SpFV-Wortlaut).

Platzhalter:
- geprüftes Verzeichnis von Ausbildungsstätten oder verlässliche öffentliche Liste mit Aktualisierungsdatum

## 29. Unsere nautischen Partner (`/nautische-partner/`)

Offen:
- Betreiber: Es gibt keine belegten Partner. Je Partner liefern: Name, schriftliche Freigabe (Name, Logo), Leistung, Region, Kontakt bzw. Website und Art der Geschäftsbeziehung (Provision, Kooperation, keine Gegenleistung).
- Recht: Kommerzielle Beziehung offenlegen (§ 5a Abs. 4 UWG, § 6 Abs. 1 Nr. 1 DDG); Links mit Gegenleistung mit rel=\"sponsored\"; keine Prüfaussage („geprüft“, „verifiziert“) ohne offengelegtes Prüfverfahren; Datenweitergabe an Partner nur mit Rechtsgrundlage und Hinweis in der Datenschutzerklärung.
- Zusammengelegt: noindex, Canonical auf Seite 6 (Nutzersicht „Wo mache ich Praxis?“); als Referenz auch auf Seite 27 verlinken.
- Sekundär-CTA „Partner werden“ erst aktiv, wenn Seite 27 veröffentlicht ist.

Platzhalter:
- Name des Partners
- Art der Zusammenarbeit, z. B. „Kooperationspartner“ oder „Partner gegen Provision“
- Leistung, z. B. Fahrstunden und Prüfungsboot für SBF See und Binnen
- Name und Ort des Partners
- Region

## 31. Affiliate-Partnerschaft (`/affiliate-partnerschaft/`)

Offen:
- Betreiber: Es gibt kein Affiliate-Programm und kein Tracking im Code. Vor Veröffentlichung festlegen: Netzwerk bzw. eigenes Programm, Anmeldung, Vergütung, Cookie-Laufzeit bzw. Zuordnung, erlaubte Werbemittel, Auszahlung, Bewerbungsweg und Teilnahmebedingungen.
- Recht: Affiliate-Tracking braucht eine Einwilligung (§ 25 Abs. 1 TDDDG). Das Banner (inc/consent.php) kennt nur „notwendig“ und „statistik“ – Kategorie „Marketing“ ergänzen, Consent-Version erhöhen, Datenschutzerklärung anpassen (sie sagt „keine Marketing-Tools“).
- Recht: Haftung für Affiliates (§ 8 Abs. 2 UWG) – vertraglich Kennzeichnungspflicht, Verbot irreführender Aussagen, Verbot von E-Mail-Spam und Brand-Bidding sowie Kontroll- und Kündigungsrechte regeln.
- Ohne Programm: noindex, Canonical auf /partnerprogramm/ bzw. 301 dorthin (Design, SEO). Mit Programm als Abschnitt in Seite 27 aufnehmen (SEO).

Platzhalter:
- über Netzwerk oder eigenes Formular
- Provision je Kauf, Höhe, Grundlage
- wie Käufe zugeordnet werden, Laufzeit
- Links, Banner, Texte
- Rhythmus, Mindestbetrag, Gutschrift
- wo die Teilnahmebedingungen stehen
- Bewerbungsweg

## 32. Arbeite mit uns an digitaler Prüfungsvorbereitung (`/karriere/`)

Offen:
- Ohne ausgeschriebene Stelle statt der Stellenkarte den Satz der Moderation zeigen: „Derzeit sind keine Stellen ausgeschrieben. Schau gern später wieder vorbei.“
- Betreiber: konkrete Stellen liefern (Titel mit „(m/w/d)“, Beschäftigungsart, Ort bzw. Remote-Regel, Aufgaben, Profil, Ansprechperson, Bewerbungsweg); ohne Stellen und Bewerbungsweg Entwurf.
- Recht: Abschnitt „Bewerbungen“ steht in der Vorlage der Datenschutzerklärung (legal/datenschutz.html) – Rechtsgrundlage, Bewerbungsweg und Löschfrist eintragen und rechtlich prüfen lassen; verschlüsselter Bewerbungsweg; AGG-neutrale Anzeigen.
- Recht: Entgelttransparenz (Richtlinie (EU) 2023/970, Umsetzungsfrist 07.06.2026) – Stand des deutschen Umsetzungsgesetzes prüfen; ggf. Einstiegsentgelt bzw. Spanne nennen.
- SEO: JobPosting-Markup nur je echter Stelle auf eigener URL, mit validThrough; abgelaufene Stellen entfernen.

Platzhalter:
- Stellentitel (m/w/d)
- Beschäftigungsart
- Ort oder Remote
- Aufgaben und Profil in drei bis fünf Sätzen
- Link zur Stellenseite
- Bewerbungsweg, z. B. verschlüsseltes Formular oder E-Mail-Adresse, und Ansprechperson
- Löschfrist, z. B. sechs Monate nach Abschluss des Verfahrens

## 33. Wie können wir dir helfen? (`/kontakt/`)

Offen:
- Betreiber: Support-E-Mail, Telefon mit Erreichbarkeit, E-Mail für Datenschutzanfragen eintragen; Anschrift steht im Impressum. Keine Reaktionszeit zusagen, die nicht eingehalten wird.
- Recht: Abschnitt „Kontaktaufnahme“ steht in der Vorlage der Datenschutzerklärung (legal/datenschutz.html) – Löschfrist eintragen und rechtlich prüfen lassen. Ein späteres Kontaktformular nur mit Pflichtfeldern E-Mail und Nachricht, ohne Drittanbieter-Captcha ohne Einwilligung; Formularstile fehlen im Theme.
- SEO: ContactPage-Schema und contactPoint an der Organization, sobald der Kanal feststeht (Feld schema kennt nur WebPage).
- FAQ-Auszug „Kontakt und Support“ entfällt: FAQ 23 ist ein Platzhalter-Entwurf (siehe _faq-aenderungen-2.md).

Platzhalter:
- ✓ Support-E-Mail
- ✓ Telefon
- ✓ telefonische Erreichbarkeit, z. B. Mo–Fr 10–16 Uhr
- ✓ E-Mail für Datenschutzanfragen

