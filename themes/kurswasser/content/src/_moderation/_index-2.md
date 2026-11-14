# Seitenindex – Moderation Seiten 18–34

Stand: 10.11.2026. Diese Datei gehört zu den Seitendateien `content/pages/18-…` bis `34-…`. Dazu kommt die Zusatzseite `00-ueber-uns.md`, die Elternseite von 23 und 24.

Legende:
- Typ A = Vorlage `course` mit Kurs-Hero und Brotkrumen.
- Typ B = Vorlage `page` mit grauem Kopf.
- Abschnitte sind als `### [B1]` … `### [B10]` notiert, nach dem Baukasten aus `review/3-design.md`, wie in den Dateien 1–17.

| Nr. | Slug | Typ | Status | Index | Primärer CTA | Offene Punkte (kurz) |
|---|---|---|---|---|---|---|
| 0 | `/ueber-uns/` (Zusatz) | B | publish | index | Kurse entdecken → `/kurse/` | vor 23/24 anlegen; Kacheln nur für veröffentlichte Unterseiten |
| 18 | `/sportbootfuehrerschein/` | A | publish | index | Passenden Kurs finden → `/kurse/` | SpFV-Wortlaut gegenprüfen; Reformstand beim BMV prüfen; Deep-Links ELWIS und Bundesportal; FAQ-Auszug braucht `schema="0"` |
| 19 | `/aerztliches-attest-bootsfuehrerschein/` | A | publish | index | Theorie online lernen → `/kurse/` | Ersatzregel und Höchstalter am SpFV-Wortlaut prüfen; eigene Anleitung (`pruefung_buchen.php:45`) angleichen |
| 20 | `/bootsfuehrerschein-kosten/` | A | publish | index | Kurspreise ansehen → `#preise` | Gebühren-Richtwerte mit Stand prüfen, Prüfintervall festlegen; Garantie-Muster erst nach rechtlicher Angleichung |
| 21 | `/mindestalter-bootsfuehrerschein/` | A | publish | index | SBF-Kurse ansehen → `/kurse/` | vorgezogene Prüfung und Funk-Altersgrenzen verifizieren; Hinweis für Minderjährige in AGB und Kasse ergänzen |
| 22 | `/bootsfuehrerschein-erfahrungen/` | A | draft | index | Kostenlos testen → `/bootsscheine/` | echte, freigegebene Rückmeldungen; Prüfverfahren (§ 5b UWG); Auswahlkriterien; Datenschutzerklärung ergänzen |
| 23 | `/ueber-uns/geschichte-mission/` | B | draft | index | Kurse entdecken → `/kurse/` | Unternehmensgeschichte vom Betreiber |
| 24 | `/ueber-uns/team/` | B | draft | index | Kontakt aufnehmen → `/kontakt/` | Personen, Rollen, belegte Qualifikationen, Einwilligungen |
| 25 | `/auszeichnungen-qualitaetssiegel/` | B | draft | noindex → Canonical `/ueber-uns/` | Lernplattform ansehen → Seite 12 | belegte Auszeichnung mit Lizenz, sonst nicht veröffentlichen |
| 26 | `/studien-untersuchungen/` | B | draft | noindex | Unser Lernkonzept → Seite 12 | echte Untersuchung; Zweckänderung der Lerndaten nur anonymisiert oder mit Einwilligung |
| 27 | `/partnerprogramm/` | B | draft | index | Partneranfrage senden → `/kontakt/` | Programm, Konditionen, Kontaktweg; Datenschutzabschnitt „Kontaktaufnahme“; Name „Partner-Flat 50“ prüfen |
| 28 | `/ausbildungsstaetten-bootsfuehrerschein/` | A | draft | noindex → Canonical Seite 6 | Theorie online starten → `/kurse/` | geprüftes Verzeichnis fehlt; nur zusammen mit Seite 6 veröffentlichen oder 301 |
| 29 | `/nautische-partner/` | B | draft | noindex → Canonical Seite 6 | Kursübersicht öffnen → `/kurse/` | echte Partner mit Freigabe und Kennzeichnung der Geschäftsbeziehung |
| 30 | `/angebote-bootschulen-vereine/` | A | publish | noindex → Canonical Seite 7 | Gruppenpakete ansehen → `#kw:gruppen` | B2B-Prozesse (Rechnung, Fortschritt) nur ergänzen, wenn eingerichtet; Preisstaffel-Auffälligkeit an Betreiber |
| 31 | `/affiliate-partnerschaft/` | B | draft | noindex → Canonical Seite 27 | Jetzt bewerben → [[Betreiber]] | Programm, Tracking mit Einwilligung (Kategorie „Marketing“), Partnerverträge |
| 32 | `/karriere/` | B | draft | index | Zu den offenen Stellen → `#stellen` | Stellen, Bewerbungsweg, Datenschutzabschnitt „Bewerbungen“, Entgelttransparenz |
| 33 | `/kontakt/` (bestehende Theme-Seite) | B | draft | index | Nachricht senden → `mailto:` | ersetzt Vorlage `legal/kontakt.html`; Support-E-Mail, Telefon, Datenschutz-E-Mail; Datenschutzabschnitt „Kontaktaufnahme“ |
| 34 | `/faq-bootsfuehrerschein/` | B | publish | index | Kontakt aufnehmen → `/kontakt/` | erst nach Einpflegen von `_faq-aenderungen-2.md`; Plugin: `schema`-Attribut, Knopf „FAQ-Seite anlegen“ auf diesen Slug, ggf. `/haeufige-fragen/` → 301 |

**Status-Verteilung (18–34):**
- publish: 6 Seiten (18, 19, 20, 21, 30, 34), dazu die Zusatzseite 0.
- draft: 11 Seiten (22–29, 31, 32, 33).
- Davon `noindex`:
  - 25, 26, 28, 29, 30 und 31;
  - 30 ist veröffentlicht, aber mit Canonical auf Seite 7.

## Übergreifende Hinweise für die Umsetzung

1. **Anlegen mit Admin-Rechten** (`wp post create … --user=<admin>`), sonst entfernt kses `#kw:`-Links und Attribute in `wp:html`-Blöcken (Design).
2. **Reihenfolge:** zuerst `/ueber-uns/` (0), dann 23 und 24 als Unterseiten.
3. **Links auf Entwürfe:**
   - Die Seiten 18–34 verlinken auch auf Seiten, die noch Entwurf sind: 3, 4, 5, 8, 9, 22–29, 31, 32, 33.
   - Solche Links und Kacheln erst ausgeben, wenn das Ziel veröffentlicht ist, sonst führen sie ins Leere.
   - Besonders betroffen ist Seite 9 (Geld-zurück-Garantie), die von 18, 20, 23, 31, 33 und 34 verlinkt wird. Ihre Veröffentlichung hat Vorrang.
4. **FAQ-Auszüge** auf 18, 19, 20 und 21:
   - Sie brauchen das neue Shortcode-Attribut `schema="0"`. FAQPage-Markup gibt es nur auf Seite 34.
   - Je Seite höchstens ein Auszug.
5. **Stand-Kasten:** „Stand: 10.11.2026“ mit Quellenliste. Autor und Fachprüfer sind weggelassen (Rahmen).
6. **Preise:** Kein fester Preis im Text. Kurspreise kommen nur live über `[kurswasser_offers]`, `[kurswasser_course_tiles]` oder Muster `courses`/`groups`. Die amtlichen Gebühren stehen auf Seite 20 als Richtwerte mit Stand und Quelle.
7. **Rechtstexte:**
   - Keine Seite darf live gehen, solange Impressum, AGB, Widerruf und Datenschutz `[[Betreiber: …]]` enthalten (Recht, Querschnitt 2).
   - In der Testumgebung gilt das nicht.
8. **Weiterleitung:** `/haeufige-fragen/` → `/faq-bootsfuehrerschein/`, falls die Seite schon angelegt wurde.
