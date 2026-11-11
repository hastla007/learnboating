---
nr: 34
title: "Häufige Fragen zum Bootsführerschein und zum Onlinekurs"
menu_title: FAQ
slug: faq-bootsfuehrerschein
parent:
type: B
status: publish
index: index
canonical:
seo_title: "Häufige Fragen zum Bootsführerschein | {{brand}}"
meta_description: "Antworten zu Onlinekurs, Zugang, Prüfung, Praxis, Kosten und Garantie – kurz erklärt, mit Wegen zu den ausführlichen Ratgeberseiten."
eyebrow:
lead: "Du hast Fragen zum Sportbootführerschein, zu Funkzeugnissen oder zu deinem Onlinekurs? Hier findest du kurze Antworten – und Links zu den ausführlichen Seiten."
cta_primary: "Kontakt aufnehmen | /kontakt/"
cta_secondary: "Kurse ansehen | /kurse/"
schema: FAQPage
breadcrumb:
open_items:
  - "Voraussetzung für die Veröffentlichung: die FAQ-Änderungen aus _faq-aenderungen-2.md im Plugin einpflegen (Admin „Bootsprüfung › FAQ“; DefaultFaqs.php nur für Neuinstallationen). Der Shortcode zeigt alle veröffentlichten FAQs, also auch FAQ 1, 3, 5, 7, 15–18, 22, 25, 51, 89 in der heutigen Fassung."
  - "Plugin-Änderungen: (1) FaqShortcode bekommt das Attribut schema=\"0\" (Standard 1), damit Auszüge auf anderen Seiten kein FAQPage-JSON-LD erzeugen – FAQPage gibt es nur hier. (2) Der Knopf „FAQ-Seite anlegen“ (FaqAdmin::createPage) legt diese Seite mit Slug faq-bootsfuehrerschein und Titel „Häufige Fragen zum Bootsführerschein und zum Onlinekurs“ an statt /haeufige-fragen/; faqPage() erkennt eine vorhandene Seite mit dem Shortcode weiter."
  - "Gibt es /haeufige-fragen/ bereits: in den Papierkorb und 301 auf /faq-bootsfuehrerschein/ (sonst doppelter Inhalt und doppeltes FAQPage-Markup)."
  - "Navigation: Kopf „FAQ“ und Fuß „Häufige Fragen“ von /#faq auf diese Seite umstellen (siehe _navigation.md). Das Startseiten-Muster kurswasser/faq bleibt (ohne JSON-LD)."
  - "Sicherheitsnetz (Recht): Filter, der FAQ-Antworten mit Platzhaltern in eckigen Klammern (außer {{agb}}) nicht ausgibt – der Import setzt solche FAQs schon auf Entwurf, im Admin geänderte Antworten aber nicht."
  - "SEO: Rubriken als H2, Unterkategorien als H3 – gibt der Shortcode ohne title-Attribut bereits so aus."
---

## Diskussion
- Fachexperte: Seitenrahmen passt; einige ausgegebene FAQ-Antworten sind fachlich falsch oder unpassend (Praxis an Standorten, „zuständiger“ Ausschuss, Gültigkeit, Alter, Ausland). FAQ zur Reform und zu führerscheinfreien Booten fehlen. Die Datenquelle korrigieren, nicht nur die Seite.
- Angebot: Shortcode passt; 83 von 92 FAQs werden veröffentlicht, die 9 Platzhalter bleiben Entwurf. Veröffentlichte FAQs 1, 7, 22, 25, 51, 89 widersprechen dem Angebot. Fehlende FAQs: Theorie gegenüber Komplett, Schnupper-Paket, Kauf für andere, Ablauf-Erinnerung.
- Design: Prototyp geprüft – Typ B, Einleitung, `[bootspruefung_faq]` ohne Attribute mit Suche und Rubrik-Pillen, dunkle Karte „Keine Antwort gefunden?“.
- SEO: Wollte die Seite nicht anlegen und `/haeufige-fragen/` nutzen; FAQ-Antworten kurz halten und auf die Ratgeber verlinken; FAQPage nur auf der FAQ-Seite.
- Recht: Hohes Risiko in der heutigen Konfiguration; empfahl `theme_only="1"` oder die Bereinigung aller ausgegebenen FAQs; FAQ 22 an den Garantie-Mustertext angleichen; Platzhalter technisch abfangen.
- Einig: Eine einzige FAQ-Seite mit FAQPage-Markup; Platzhalter dürfen nicht live gehen; FAQ 22 muss zu AGB Nr. 9 passen.
- Dissens und Entscheidung: Der Rahmen legt den Slug `/faq-bootsfuehrerschein/` fest; `/haeufige-fragen/` wird nicht angelegt bzw. weitergeleitet. Gegen `theme_only="1"` (Recht) spricht, dass ★ nur „auch im Theme zeigen“ bedeutet, nicht „geprüft“ – die Seite zeigt daher alle veröffentlichten FAQs, und alle veröffentlichten FAQs wurden geprüft und, wo nötig, neu formuliert (_faq-aenderungen-2.md). Primär-CTA ist „Kontakt aufnehmen“ (Design), „Kurse ansehen“ steht als Textlink daneben (SEO). Neue FAQs zu Reform, führerscheinfreien Booten, Theorie/Komplett, Schnupper-Paket, Kauf für andere und Ablauf-Erinnerung kommen hinzu.

## Inhalt

### [B1]
Du hast Fragen zum Sportbootführerschein, zu Funkzeugnissen oder zu deinem Onlinekurs? Hier findest du kurze Antworten – und Links zu den ausführlichen Seiten.

### [B9]
[bootspruefung_faq]

### [B8 Weiterlesen] Ausführlich erklärt
- [Sportbootführerschein im Überblick](/sportbootfuehrerschein/) (Symbol anchor) – welcher Schein, welche Voraussetzungen, welcher Weg.
- [Kosten des Bootsführerscheins](/bootsfuehrerschein-kosten/) (Symbol cart) – Prüfungsgebühr, Attest, Kurs und Praxis.
- [Geld-zurück-Garantie](/geld-zurueck-garantie-bootsfuehrerschein/) (Symbol shield) – Frist, Bedingungen und Ausnahmen.

### [B10 CTA] Keine Antwort gefunden?
Schreib uns – am besten mit Kurs und Bestellnummer. Wir antworten dir per E-Mail.
Button: Kontakt aufnehmen | /kontakt/
Button: Kurse ansehen | /kurse/
