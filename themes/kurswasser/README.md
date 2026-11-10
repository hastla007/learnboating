# Kurswasser 2.0 – Block-Theme für die Lernplattform

Maritimes Block-Theme (Full Site Editing) für die Plattform zu Sportbootführerscheinen,
Funkzeugnissen und Seenotsignalmitteln. Es ist auf das Plugin **Bootsprüfung – Lernplattform**
und **WooCommerce mit Block-Checkout** abgestimmt. Markenname: Platzhalter „XXXX“ (Einstellungen
des Plugins bzw. Website-Titel).

## Seiten und Vorlagen

| Vorlage | Zweck |
|---|---|
| `front-page` | Startseite aus Mustern: Hero, Vorteilsleiste, **Kurs-Kacheln** (Preis, Kennzahlen, Link zur Kursseite), Funktionen, Ablauf, Gruppen, Garantie, FAQ, Abschluss-Aufruf |
| `course` | **Kurs-Infoseiten** `/kurse/<kurs>/` (SBF See, SBF Binnen, Kombi SBF, Kapitäns-Bundle, SRC, UBI, Kombi Funk, Binnen Segel, SKN, FKN, Pakete, Weitere Kurse): Hero mit Kennzahlen, Inhalte, Prüfungsablauf (aus den Prüfungsanleitungen der Quelle), Preise & Kauf, Garantie, FAQ |
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

FAQ: Das Muster `faq` und die Kurs-FAQ (`kurswasser_course_faq()`) zeigen die ★-FAQs aus dem Lern-Plugin
(Bootsprüfung › FAQ, „Im Theme anzeigen“) – `faq` die Rubrik „allgemein“, jede Kursseite die Rubrik ihres
Scheins (`kurswasser_faq_rubrics()`). Ohne Plugin oder ohne ★-FAQ gelten die festen Texte im Theme.
Das Muster wird je Seitenaufruf ausgewertet; wird es in eine Seite oder eine im Website-Editor angepasste
Vorlage eingefügt, steht dort aber der Stand zum Zeitpunkt des Einfügens.

Inhaltliche Regel: Aussagen beschreiben nur Funktionen, die es im Plugin gibt, und die
Festlegungen des Betreibers (Laufzeiten, Geld-zurück-Garantie, Gruppenpakete). Keine Gebühren,
Fristen oder Prüfungsvorschriften – dafür wird auf den Prüfungsausschuss verwiesen.

## Kursseiten (`[kurswasser_course id="sbf-see"]`)

Beim Aktivieren (und mit `tests/demo-setup.php`) legt das Theme die Seite „Kurse“ und je Kurs eine
Unterseite an (Vorlage „Kursseite“). Definitionen in `inc/courses.php` (Texte, Angebote,
Farbe); Zahlen kommen aus dem Plugin (`ShopCatalog::courseStats`), der Prüfungsablauf aus den
Prüfungsanleitungen (Umfang, Bestehensgrenze, Zeit, Prüfungsteile) – keine Gebühren/Fristen.
Kacheln: `[kurswasser_course_tiles]` (Startseite, zwei Reihen: Kapitäns-Bundle · Kombi SBF See & Binnen · SBF See · SBF Binnen / Kombi SRC & UBI · SRC · UBI · Weitere Kurse); „Weitere Kurse“ führt zur Übersicht `/kurse/` mit allen Kursen, nach Bereichen gegliedert. Kursfotos in `assets/images/courses/` (Austausch: `tools/course-images/README.md`). Reihen in `kurswasser_tile_sets()`. Kombis zeigen „Du sparst … €“ aus den Shop-Preisen.

## Kurse & Preise (`[kurswasser_offers]`)

Verkaufssektion mit drei Reitern (Sportbootführerschein, Funkzeugnis, Pakete & Pässe), je drei
Angebote, das mittlere hervorgehoben. Alles kommt aus echten Daten:

- Produkte über die Artikelnummern des Plugin-Katalogs, Preis und Link aus WooCommerce
  (ohne veröffentlichtes Produkt: Katalogpreis als Vorschau, Button „Bald verfügbar“).
- Ersparnis = Summe der Vergleichsprodukte minus Preis (Kombi vs. Einzelkurse, Bundle vs.
  beide Kombis), durchgestrichener Vergleichspreis, Monatspreis bei 12 Monaten.
- Inhaltspunkte aus den Produkttexten des Plugins (Fragen, Bögen, Aufgaben, Videos).
- „Jetzt starten“ legt den Kurs in den Warenkorb und öffnet direkt die Kasse.
- Keine künstliche Verknappung, keine erfundenen Verkaufszahlen.

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

## Inhaltsseiten (Info- und Marketingseiten)

Texte entstehen als Markdown (`NN-slug.md`: Front-Matter + `## Inhalt` mit `### [Typ] Überschrift`,
Typen B1–B10 des Seiten-Baukastens; Format im Kopf von `tools/content_build.py`). Daraus:

```bash
python3 themes/kurswasser/tools/content_build.py <ordner-mit-md> --check   # nur prüfen
python3 themes/kurswasser/tools/content_build.py <ordner-mit-md>           # → content/pages.php + content/pages/*.html
wp kurswasser content-pages status                                         # was wäre neu/aktualisierbar, offene Punkte
wp kurswasser content-pages sync --user=<admin>                             # anlegen bzw. aktualisieren
```

- Typ A = Vorlage `course` mit Kurs-Hero (`[kurswasser_page_hero]`: Brotkrumen, Eyebrow, H1 = Seitentitel,
  Lead, CTAs, optional Foto/Kennzahlen/Sprungnavigation); Typ B = Vorlage `page`.
- `{{brand}}` wird beim Anlegen (und beim Ausgeben) zum Website-Namen.
- Nichts wird ungefragt überschrieben: vom Betreiber geänderte Seiten bleiben (Prüfsumme), neue Vorlagenstände
  meldet der Admin-Hinweis (Dashboard, Seiten) mit Knopf „Inhaltsseiten anlegen bzw. aktualisieren“ und den
  offenen Punkten je Seite. Seiten mit `[[Betreiber: …]]` bzw. „Offen“-Kasten entstehen als Entwurf.
- Einträge für Rechtstext-Seiten (`kontakt` usw.) werden übersprungen – die pflegt `inc/legal.php`.
- `#kw:`-Links überstehen kses (auch ohne `unfiltered_html`).

## SEO (`inc/seo.php`)

Metabox „SEO (Kurswasser)“ an Seiten und Beiträgen: SEO-Titel, Meta-Description, Canonical, Stand, noindex,
Schema. Ausgabe: `<title>`, Description, `noindex, follow`, Canonical, keine noindex-Seiten in der Sitemap,
JSON-LD BreadcrumbList (Seiten), Article (Schema „Article“), Organization + WebSite (nur Startseite).
Mit Yoast SEO oder Rank Math aktiv: keine eigene Ausgabe, nur Rückfall auf die eigenen Felder, wo das
Plugin-Feld leer ist.

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
