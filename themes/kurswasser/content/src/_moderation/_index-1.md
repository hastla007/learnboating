# Übersicht Seiten 1–17 (Moderation 1)

Stand: 10.11.2026. Status: 12 × publish, 5 × draft. Index: 10 × index, 7 × noindex (davon 6 mit Canonical).

| Nr. | Slug | Typ | Status | Index | primärer CTA | offene Punkte (kurz) |
|---|---|---|---|---|---|---|
| 1 | online-bootsfuehrerschein | A | publish | noindex, Canonical `/` | Kurse vergleichen → /kurse/ | Startseite braucht Keyword-H1/Title; Garantie-Muster angleichen; Platzhaltervideos |
| 2 | bootsfuehrerschein-ausland-anerkennung | A | publish | index | SBF-Kurse vergleichen → /kurse/ | ELWIS/Bundesportal-Deep-Link zum ICC; UNECE-Liste; Reform beim BMV gegenprüfen |
| 3 | deutscher-bootsfuehrerschein-oesterreich | A | draft | index | Deutsche SBF-Kurse ansehen → /kurse/ | USt/OSS für AT klären (Betreiber); § 101 SchFG am RIS gegenlesen |
| 4 | deutscher-bootsfuehrerschein-schweiz | A | draft | index | Deutsche SBF-Kurse ansehen → /kurse/ | MWST CH klären (Betreiber); Art. 91a BSV auf fedlex gegenlesen |
| 5 | amtliche-pruefungsboegen-sbf | A | draft | index | Kostenlos Fragen testen → /bootsscheine/ | Katalogstand je Kurs `[[Betreiber]]`; Rechte Fragen/Bilder (Go-live-Blocker); ELWIS-Deep-Link; PDFs |
| 6 | bootsfuehrerschein-standorte | A | publish | index | Onlinekurs wählen → /kurse/ | Terminsuche-Deep-Link; „Prüfung anmelden“ freischalten; FAQ 12–20 korrigieren |
| 7 | bootsfuehrerschein-gruppenangebote | A | publish | index | Gruppenpakete ansehen → #kw:gruppen | Navigation „Gruppen“ hierher; Kontaktweg befüllen; Preisstaffel prüfen; Videos |
| 8 | bootsfuehrerschein-gutschein | A | draft | index | Gutschein auswählen → `[[Betreiber: Produkt-URL]]` | Kein Gutscheinprodukt; Bedingungen, AGB-Abschnitt, Checkout-Widerruf, FAQ 21 Fassung B |
| 9 | geld-zurueck-garantie-bootsfuehrerschein | A | draft | index | Kurs wählen → /kurse/ | Garantiegeber, Erstattungsfrist, Prüf-E-Mail `[[Betreiber]]`; Rundung im Code; Garantie-Muster; anwaltliche Freigabe |
| 10 | bestehensgarantie-bootsfuehrerschein | B | publish | noindex, Canonical geld-zurueck-garantie-bootsfuehrerschein | Zur Geld-zurück-Garantie → Seite 9 | Alternativ 301 auf 9#bestehensgarantie |
| 11 | bootsfuehrerschein-rabatte | A | publish | noindex, Canonical kurse/pakete | Preise ansehen → #preise | Streichpreis in `[kurswasser_offers]` ersetzen (Rahmen); keine Sale-Preise ohne 30-Tage-Angabe |
| 12 | online-lernplattform-bootsfuehrerschein | A | publish | index | Kostenlos reinschnuppern → /bootsscheine/ | Beworbene, noch nicht live: Videos, PDFs, Prüfungsanmeldung, App; Navigation „So funktioniert’s“ |
| 13 | bootsfuehrerschein-lernmaterialien-shop | A | publish | noindex, Canonical kurse | Kurse vergleichen → /kurse/ | D 49 Bezugsquelle optional; PDFs; FAQ 11 umstellen |
| 14 | bootsfuehrerschein-lexikon | A | publish | noindex (bis ca. 40 Einträge) | Kostenlos Fragen testen → /bootsscheine/ | Deep-Links für Funk-/Seenot-Einträge; Werte mit Rechtsstand; MStV-Impressum |
| 15 | ratgeber-bootsfuehrerschein | A | publish | noindex, Canonical sportbootfuehrerschein | Ratgeberthemen ansehen → #themen | Kopf-Navigation „Ratgeber“; Kacheltitel mit Seiten 18–21 abgleichen |
| 16 | bootsfuehrerschein-guide | A | publish | noindex, Canonical sportbootfuehrerschein | Passenden Kurs finden → /kurse/ | Schritte mit Seite 18 abstimmen; „unbefristet“ und „kein Ausbildungsnachweis“ am SpFV-Wortlaut |
| 17 | reihenfolge-bootsfuehrerschein | A | publish | index | Kombikurs ansehen → /kurse/sbf-kombi/ | Anrechnungsregeln gegen § 8 SpFV; FAQ 75–77; Kombi-Übungsmodus freigeben |

## Gilt für alle Seiten 1–17
- **Vor jedem echten Livegang:** Rechtstexte (AGB, Widerruf, Datenschutz, Impressum, Kontakt) ohne `[[Betreiber: …]]` veröffentlichen und anwaltlich freigeben (Recht, Querschnitt 2); Marke `{{brand}}` ersetzen und Markenrecherche (Recht, Querschnitt 5).
- **Shortcode:** `[bootspruefung_faq … schema="0"]` braucht das neue Attribut im Plugin (Rahmen); bis dahin FAQ-Auszüge weglassen. Welche FAQs vorher zu korrigieren sind: `_faq-aenderungen-1.md`, Teil D.
- **Muster `kurswasser/guarantee`:** Text an AGB Nr. 9 bzw. Seite 9 angleichen und auf Seite 9 verlinken; betrifft die Seiten 1, 11, 12 (Recht, Querschnitt 4).
- **noindex/Canonical:** Das Theme hat dafür noch keine Ausgabe; Umsetzung nach SEO 3.1 (`inc/seo.php`) oder per SEO-Plugin.
- **Rahmen-Ausnahmen bei Funktionen:** Videokurs/Knoten-Videos, PDF-Downloads, Prüfungsanmeldung mit Ausschuss-Suche und App werden beschrieben, wie sie gebaut sind; vor dem echten Livegang je Funktion prüfen (offene-punkte Nr. 3, 34, 13).
- **Querverweise auf 18–34:** Slugs aus dem Konzept; Anker `#vergleich` (18) ist in der Fassung von Moderation 2 vorhanden.
