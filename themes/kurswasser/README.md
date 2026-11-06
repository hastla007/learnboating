# Kurswasser 2.0 – Block-Theme für die Lernplattform

Maritimes Block-Theme (Full Site Editing) für die Plattform zu Sportbootführerscheinen,
Funkzeugnissen und Seenotsignalmitteln. Es ist auf das Plugin **Bootsprüfung – Lernplattform**
und **WooCommerce mit Block-Checkout** abgestimmt. Markenname: Platzhalter „XXXX“ (Einstellungen
des Plugins bzw. Website-Titel).

## Seiten und Vorlagen

| Vorlage | Zweck |
|---|---|
| `front-page` | Startseite aus Mustern: Hero, Vorteilsleiste, Kursauswahl, Funktionen, Ablauf, beliebte Kurse, Gruppen, Garantie, FAQ, Abschluss-Aufruf |
| `archive-product`, `taxonomy-product_cat`, `product-search-results` | Shop und Kategorien mit Kategorie-Navigation, Garantie und FAQ |
| `single-product` | Produktseite mit Kaufbox, Zusicherungen und Beschreibung (aus dem Plugin-Katalog) |
| `page-cart`, `page-checkout` | Warenkorb und Kasse (Block-Checkout), Kasse mit reduziertem Kopfbereich |
| `page`, `page-legal`, `landing` | Seiten, Rechtstexte (schmal), Landingpages (volle Breite) |
| `index`, `home`, `archive`, `search`, `single`, `404` | Blog/Neuigkeiten, Suche, Fehlerseite |
| Lernbereich `/bootsscheine/` | Kommt aus dem Plugin; nutzt Kopf/Fuß und Farben des Themes |

Beim Aktivieren legt das Theme **Entwürfe** für Impressum, Datenschutz, AGB, Widerruf und
Kontakt an – nur mit Platzhalter, keine Rechtstexte. Die Links im Fußbereich zeigen auf diese
Seiten (`/impressum/` usw.).

## Muster (Editor › Muster › Kurswasser)

`hero`, `usp`, `courses`, `features`, `steps`, `popular`, `groups`, `guarantee`, `faq`, `cta` –
einzeln auf jeder Seite einsetzbar.

Inhaltliche Regel: Aussagen beschreiben nur Funktionen, die es im Plugin gibt, und die
Festlegungen des Betreibers (Laufzeiten, Geld-zurück-Garantie, Gruppenpakete). Keine Gebühren,
Fristen oder Prüfungsvorschriften – dafür wird auf den Prüfungsausschuss verwiesen.

## Links ohne feste IDs

In Vorlagen und Mustern stehen Ziele als `#kw:<ziel>` und werden beim Ausgeben ersetzt:
`shop`, `account`, `learn`, `tips`, die Shop-Kategorien `sbf`, `funk`, `seenot`, `ergaenzung`,
`pakete`, `gruppen` (feste Kürzel aus dem Plugin-Katalog), Startseiten-Anker wie `#kw:#faq` und
sonst `/<ziel>/` (z. B. `#kw:impressum`).

## Gestaltung

- Farben, Schrift und Abstände in `theme.json`, Komponenten in `assets/css/theme.css`.
- Schrift **Manrope** (variabel, SIL Open Font License, `assets/fonts/OFL-Manrope.txt`), lokal
  eingebunden – keine Anfragen an Google oder andere Server.
- Hero-Foto als WebP/JPEG (87 KB statt 1,8 MB PNG), mobil eine kleinere Fassung.
  **Bitte sicherstellen, dass die Nutzungsrechte am Foto vorliegen.**
- Symbole als SVG-Masken (`assets/icons/`), einfärbbar über CSS (`.kw-icon .kw-icon-anchor`).
- Kontraste: Text auf Weiß ≥ 4,5:1, dunkle Schrift auf Wasserblau und Signalgelb,
  sichtbarer Fokus, „Bewegung reduzieren“ wird respektiert.
- Palette mit Slug `primary`: Das Lern-Plugin übernimmt die Akzentfarbe automatisch.

## Prüfen

CI (Job mit WooCommerce 11.2.2) testet Lernbereich, Trainer und Kaufablauf mit diesem Theme,
legt danach den Produktkatalog an und speichert Screenshots (Desktop und Mobil) als Artefakt
`theme-kurswasser-screenshots`. Lokal:

```bash
BP_URL=http://127.0.0.1:8099 BP_SHOTS=/tmp/shots node themes/kurswasser/tests/screenshots.mjs
```

## Vor dem Livegang

- Logo hochladen (Editor › Kopfbereich › Website-Logo) und Markenname setzen.
- Rechtstexte in die Entwürfe einfügen und veröffentlichen.
- Hero-Foto ggf. durch eigenes Motiv ersetzen (`assets/images/hero-boat.*`).
