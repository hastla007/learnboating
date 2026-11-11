---
nr: 33
title: "Wie können wir dir helfen?"
menu_title: Kontakt
slug: kontakt
parent:
type: B
status: draft
index: index
canonical:
seo_title: "Kontakt und Support | {{brand}}"
meta_description: "Fragen zu Kurs, Lernzugang oder Bestellung? So erreichst du unseren Support – mit Bestellnummer geht es schneller. Antworten auch in den FAQ."
eyebrow: Kontakt
lead: "Du hast eine Frage zu einem Kurs, zu deinem Zugang oder zu einer Bestellung? Schreib uns – wir antworten dir per E-Mail."
cta_primary: "Nachricht senden | mailto:[[Betreiber: Support-E-Mail]]"
cta_secondary: "Häufige Fragen ansehen | /faq-bootsfuehrerschein/"
schema: WebPage
breadcrumb:
stand: 10.11.2026
open_items:
  - "KEINE neue Seite: Die Umsetzung ersetzt die Theme-Vorlage themes/kurswasser/legal/kontakt.html durch diese Fassung (Block-Markup). Die Seite /kontakt/ legt das Theme über inc/legal.php an (kurswasser_legal_pages); nicht doppelt anlegen."
  - "Beim Ersetzen der Vorlage den theme-üblichen Vorlagen-Hinweis (Absatz kw-placeholder „Vorlage – vor der Veröffentlichung rechtlich prüfen lassen …“) als ersten Block beibehalten; er wird mit dem Ausfüllen der Platzhalter gelöscht. #kw:-Links bleiben, kurswasser_legal_resolve_links löst sie beim Einfügen auf."
  - "Ist die Seite bereits angelegt und bearbeitet, überschreibt wp kurswasser legal --force die Ergänzungen – Hash/Status prüfen (Design, Hinweis Technik 6). Seitentitel von „Kontakt“ auf „Wie können wir dir helfen?“ ändern, Menütitel „Kontakt“."
  - "Betreiber: Support-E-Mail, Telefon mit Erreichbarkeit, E-Mail für Datenschutzanfragen eintragen; Anschrift steht im Impressum. Keine Reaktionszeit zusagen, die nicht eingehalten wird."
  - "Recht: Datenschutzerklärung um einen Abschnitt „Kontaktaufnahme“ (E-Mail, Telefon; Rechtsgrundlage Art. 6 Abs. 1 lit. b bzw. f DSGVO; Speicherdauer) ergänzen. Ein späteres Kontaktformular nur mit Pflichtfeldern E-Mail und Nachricht, ohne Drittanbieter-Captcha ohne Einwilligung; Formularstile fehlen im Theme."
  - "SEO: ContactPage-Schema und contactPoint an der Organization, sobald der Kanal feststeht (Feld schema kennt nur WebPage)."
  - "FAQ-Auszug „Kontakt und Support“ entfällt: FAQ 23 ist ein Platzhalter-Entwurf (siehe _faq-aenderungen-2.md)."
---

## Diskussion
- Fachexperte: Ein Satz ist sinnvoll: Fragen zu Prüfungsanmeldung, Terminen oder Gebühren beantwortet der Prüfungsausschuss, Fragen zur Anerkennung im Ausland die Behörde des jeweiligen Landes.
- Angebot: Slug `/kontakt/` ist durch die Theme-Vorlage belegt; es gibt kein Kontaktformular, nur E-Mail und Telefon als Platzhalter. Bestellnummer und Kurs nennen, „niemals dein Passwort“, keine Zusage zur Antwortzeit. Self-Service-Wege (Geld zurück unter „Mein Lernen“, Vertrag online widerrufen, Passwort selbst zurücksetzen) stehen schon in der Vorlage.
- Design: Typ B, vorhandene Seite ergänzen; Einleitung, „So erreichst du uns“ mit Button „Nachricht senden“, Hinweisliste, Datenschutz-Karte, häufige Anliegen, Button zur FAQ. FAQ-Auszug erst nach Bereinigung.
- SEO: Indexieren; H1 „Wie können wir dir helfen?“, schnelle Antworten zuerst, eigener Abschnitt für Gruppen und Partner, ContactPage.
- Recht: Mittleres Risiko. „Kontaktformular“ streichen, solange es keins gibt; Datenschutzabschnitt „Kontaktaufnahme“ fehlt; E-Mail und Telefon sind Pflichtangaben; keine Gesundheits- und Zahlungsdaten, nie das Passwort.
- Einig: Bestehende Vorlage zusammenführen statt neuer Seite; kein Formular versprechen; Platzhalter bleiben.
- Dissens und Entscheidung: SEO und UX stellten zur Wahl, ob die FAQ vor dem Kontaktweg steht. Entscheidung: zuerst ein kurzer Block „Schnelle Antworten“ mit Self-Service-Links, dann der Kontaktweg – so lösen viele ihr Anliegen sofort, ohne dass der Kontakt versteckt wird. Status Entwurf, weil die Betreiberangaben fehlen.

## Inhalt

### [B1]
Du hast eine Frage zu einem Kurs, zu deinem Zugang oder zu einer Bestellung? Schreib uns – wir antworten dir per E-Mail.

### [B2] Schnelle Antworten
Viele Anliegen kannst du sofort selbst erledigen:

- **Geld-zurück-Garantie:** Die Anfrage stellst du in deinem Kundenkonto unter „Mein Lernen“ über den Knopf „Geld zurück anfordern“. Dort siehst du auch, ob die Bedingungen erfüllt sind. Mehr zur [Geld-zurück-Garantie](/geld-zurueck-garantie-bootsfuehrerschein/).
- **Vertrag widerrufen:** Nutze die Funktion [Vertrag hier widerrufen](#kw:vertrag-widerrufen). Wann ein Widerruf möglich ist, steht in der [Widerrufsbelehrung](#kw:widerruf).
- **Passwort vergessen:** Du kannst es auf der Anmeldeseite selbst zurücksetzen.
- **Fragen zu Kurs, Prüfung und Zugang:** Die Antworten auf die häufigsten Fragen findest du in unseren [häufigen Fragen](/faq-bootsfuehrerschein/).

### [B2] So erreichst du uns
E-Mail (Support): [[Betreiber: Support-E-Mail]]
Telefon: [[Betreiber: Telefon]] ([[Betreiber: telefonische Erreichbarkeit, z. B. Mo–Fr 10–16 Uhr]])

Button: Nachricht senden | mailto:[[Betreiber: Support-E-Mail]]

Unsere vollständige Anschrift findest du im [Impressum](#kw:impressum).

### [B2] Damit wir dir schnell helfen können
- Schreib uns am besten von der E-Mail-Adresse, mit der du dein Kundenkonto angelegt hast.
- Nenne den Kurs, um den es geht.
- Gib bei Fragen zu einer Bestellung die Bestellnummer an. Sie steht in der Bestellbestätigung und unter „[Mein Konto](#kw:account)“.
- Beschreibe kurz, was du gemacht hast und was passiert ist – bei technischen Fragen auch Gerät und Browser.

### [B3 Datenschutz] Bitte nicht per E-Mail senden
Schick uns niemals dein Passwort. Bitte sende uns auch keine Zahlungsdaten wie Kartennummern und keine Gesundheitsdaten – zum Beispiel dein ärztliches Attest. Das Attest gehört zu deinem Antrag beim Prüfungsausschuss, nicht zu uns.

### [B2] Wer hilft wobei?
Nicht jede Frage können wir beantworten. Für manche Themen sind andere Stellen zuständig:

- **Prüfungsanmeldung, Prüfungstermine und Prüfungsgebühren:** dein Prüfungsausschuss des DSV oder DMYV. Ausschüsse und Termine findest du auf sportbootfuehrerscheine.org, der gemeinsamen Website von DSV und DMYV; in deinem Kurs hilft dir die Anleitung „Prüfung anmelden“.
- **Anerkennung deines Scheins im Ausland:** die zuständige Behörde des jeweiligen Landes. Eine erste Orientierung gibt unsere Seite [Bootsführerschein im Ausland](/bootsfuehrerschein-ausland-anerkennung/).
- **Fahrstunden und Prüfungsboot:** die Bootsschule, bei der du die Praxis machst.

### [B2] Für Bootsschulen, Vereine und Partner
Du möchtest Lernplätze für eine Gruppe kaufen oder mit uns zusammenarbeiten? Schreib uns an [[Betreiber: Support-E-Mail]] und nenne kurz deine Schule oder deinen Verein und die ungefähre Zahl der Teilnehmenden. Wie die Gruppenpakete funktionieren, steht unter [Angebote für Bootsschulen und Vereine](/angebote-bootschulen-vereine/).

### [B2] Deine Daten
Auskunft, Berichtigung oder Löschung deiner Daten: Schreib uns an [[Betreiber: E-Mail für Datenschutzanfragen]]. Mehr dazu in der [Datenschutzerklärung](#kw:datenschutz).

Wenn du uns schreibst, verwenden wir deine Angaben nur, um dein Anliegen zu bearbeiten. Einzelheiten findest du in der [Datenschutzerklärung](#kw:datenschutz).

### [B10 CTA] Lieber selbst nachlesen?
Viele Antworten zu Kurs, Zugang und Prüfung stehen in unseren häufigen Fragen.
Button: Häufige Fragen ansehen | /faq-bootsfuehrerschein/
Button: Kostenlos testen | /bootsscheine/
