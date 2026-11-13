# Kurswasser 2.0 – Block-Theme für die Lernplattform

Maritimes Block-Theme (Full Site Editing) für die Plattform zu Sportbootführerscheinen,
Funkzeugnissen und Seenotsignalmitteln. Es ist auf das Plugin **Bootsprüfung – Lernplattform**
und **WooCommerce mit Block-Checkout** abgestimmt. Markenname: Platzhalter „XXXX“ (Einstellungen
des Plugins bzw. Website-Titel).

## Einrichtung (Design › Kurswasser einrichten)

Nach dem Aktivieren des Themes im Backend leitet WordPress den Admin einmal auf **Design › Kurswasser einrichten**
(nicht bei WP-CLI, AJAX, Cron, Netzwerk-Admin oder Mehrfach-Aktivierung; abschaltbar mit dem Filter
`kurswasser_setup_redirect`). Die Seite ist danach jederzeit über das Menü erreichbar (`inc/setup.php`).

**Schritt 1 – Plugins.** Liste mit Status (nicht installiert / installiert / aktiv) und Häkchen „installieren und
aktivieren“ – Reihenfolge WooCommerce, Yoast SEO, Bootsprüfung Lernplattform (WooCommerce immer vor dem Lern-Plugin).
Vorausgewählt sind WooCommerce und das Lern-Plugin, Yoast ist optional. Installiert wird mit den WordPress-Bordmitteln
(`plugins_api` + `Plugin_Upgrader`), nur mit den Rechten `install_plugins`/`activate_plugins` und Nonce. Mit
`DISALLOW_FILE_MODS` oder ohne Schreibrechte/FTP-Zugangsdaten zeigt die Seite einen Hinweis und einen Link zur
Plugin-Suche bzw. zu wordpress.org statt einer Installation. Die Willkommens-Weiterleitungen von WooCommerce und Yoast
werden dabei abgeschaltet, damit der Assistent weiterläuft.

**Quelle des Lern-Plugins** (liegt nicht auf wordpress.org), in dieser Reihenfolge:

1. Konstante in `wp-config.php`: `define( 'KURSWASSER_LEARNING_PLUGIN_ZIP', 'https://…/bootspruefung-learning.zip' );`
   (URL oder lokaler Pfad zu einer ZIP) bzw. Filter `kurswasser_learning_plugin_zip`;
2. eine mitgelieferte ZIP im Theme: `plugins/bootspruefung-learning.zip` – erzeugt mit
   `themes/kurswasser/tools/build-plugin-zip.sh [<commit>]` (Stand eines Commits, ohne Tests, ca. 2,7 MB). Die ZIP
   liegt bewusst nicht im Git (`.gitignore`): Sie wäre bei jeder Plugin-Änderung veraltet und würde das Repo
   aufblähen. CI baut sie als Artefakt `bootspruefung-learning-plugin`; für ein Release-Paket des Themes vor dem
   Zippen das Skript ausführen;
3. sonst: Erklärung und Knopf „Plugin hochladen“ (`plugin-install.php?tab=upload`).

WP-CLI: `wp kurswasser setup plugins` (Stand) bzw. `wp kurswasser setup plugins --install=woocommerce,bootspruefung-learning --user=<admin>`
(oder `--install=all`).

**Schritt 2 – Inhaltsseiten** (35 Seiten aus `content/pages.php`):

| Yoast SEO | Auswahl |
|---|---|
| nicht aktiv | **A** „Inhaltsseiten anlegen (SEO-Daten gibt das Theme aus)“ · „Vorerst nicht anlegen“ |
| aktiv | **B** „Inhaltsseiten anlegen, SEO-Daten in Yoast“ (empfohlen, vorausgewählt) · **A** „…, SEO-Daten im Theme“ · „Vorerst nicht anlegen“ |

- **Option A:** wie bisher – die Seiten bekommen die Felder der Box „SEO (Kurswasser)“; `inc/seo.php` gibt sie aus
  (mit aktivem Yoast nur als Rückfall, wo das Yoast-Feld leer ist).
- **Option B:** zusätzlich werden die Yoast-Felder jeder Seite gefüllt (siehe unten). Sie stehen dann im Yoast-Kasten,
  in der Snippet-Vorschau und in der Yoast-Sitemap.
- **Vorerst nicht:** Es passiert nichts; der Hinweis im Dashboard und die Einrichtungsseite bleiben.
- Die Wahl steht in der Option `kurswasser_content_seo_target` (`theme` bzw. `yoast`) und gilt für jeden späteren
  Abgleich (Knopf im Admin-Hinweis, WP-CLI). Ist Yoast gewählt, aber nicht aktiv, gilt `theme`. Ist Yoast aktiv und
  noch nichts gewählt, führt der Knopf im Admin-Hinweis zuerst zur Auswahl.

### Yoast-Felder (Option B, `inc/content-yoast.php`)

| Yoast-Feld | Wert |
|---|---|
| `_yoast_wpseo_title` | `seo_title`, `{{brand}}` → `%%sitename%%`; fehlt die Marke und passt sie noch (≤ 60 Zeichen), wird „` %%sep%% %%sitename%%`“ angehängt – wie die Theme-Ausgabe |
| `_yoast_wpseo_metadesc` | `meta_description`, `{{brand}}` → `%%sitename%%` |
| `_yoast_wpseo_meta-robots-noindex` | `1` bei `index: noindex`, sonst nicht gesetzt |
| `_yoast_wpseo_canonical` | absolute URL aus `canonical` (Slug, `/pfad/`, `/` oder URL); zeigt er auf eine Seite, die noch Entwurf ist, bleibt das Feld leer (das Theme setzt dann per Rückfall die Selbstreferenz) und wird beim nächsten Abgleich gefüllt, sobald das Ziel veröffentlicht ist. Nach einem Domainwechsel aktualisiert der nächste Abgleich die URL (sofern unverändert) |
| `_yoast_wpseo_schema_article_type` | `Article` bei `schema: Article` |
| `_yoast_wpseo_schema_page_type` | `AboutPage`/`ContactPage`, falls so angegeben. **Nicht** `FAQPage`: `[bootspruefung_faq]` gibt auf der FAQ-Seite schon FAQPage-JSON-LD mit den Fragen aus; Yoast würde zusätzlich seinen WebPage-Knoten zu einer FAQPage ohne Fragen machen (doppeltes Markup) |
| `_yoast_wpseo_focuskw` | nur aus dem optionalen Manifest-Feld `focus_keyword` (Front-Matter); ohne Angabe kein Fokus-Keyword – es wird keins erfunden |

Die `_kw_`-Felder setzt der Abgleich weiterhin, damit ein Wechsel zurück zu Option A (oder ohne Yoast) funktioniert.

**Mit echtem Yoast geprüft** (Yoast SEO 28.8, 13.11.2026, alle 35 Seiten mit Option B):
- Titel, Beschreibung, Robots, Canonical, OG- und Twitter-Tags kommen je Seite aus den Yoast-Feldern, ohne Doppelungen.
- noindex-Seiten fehlen in der Sitemap, die FAQ-Seite hat genau ein FAQPage-Markup (aus `[bootspruefung_faq]`).
- Behoben dabei: Warenkorb, Kasse und Mein Konto standen trotz noindex in der Yoast- und der Core-Sitemap (jetzt
  `kurswasser_seo_shop_private_ids()`); Yoasts Autoren-Sitemap nannte den Anmeldenamen (das Lern-Plugin schaltet sie
  und die Autoren-Archive jetzt mit seiner Härtung ab); Artikel bekamen ein `author` mit leerem Namen (jetzt die Marke).
- Achtung: Unter Einstellungen › Lesen muss „Suchmaschinen davon abhalten …“ im Livebetrieb aus sein, sonst gibt
  Yoast überall `noindex, nofollow` aus. Unter Yoast › Einstellungen › „Website-Darstellung“ Organisation, Name und
  Logo eintragen.
Die Theme-Ausgabe bleibt bei aktivem Yoast unverändert: nur Rückfall.

**Neuaufbau in Yoast:** Yoast liest Titel, Beschreibung, Robots und Canonical im Frontend und in der Sitemap aus
seinen Indexables. Nach dem Schreiben baut das Theme das Indexable der Seite über Yoasts eigenen Watcher neu
(`YoastSEO()->classes->get(Indexable_Post_Watcher::class)->build_indexable($id)`, derselbe Weg wie beim Speichern
im Editor) und leert den Sitemap-Cache der Seite (`WPSEO_Sitemaps_Cache::invalidate_post`). Zusätzlich erkennt
Yoasts `Indexable_Post_Meta_Watcher` geänderte `_yoast_wpseo_`-Felder selbst. Falls doch etwas veraltet wirkt:
Yoast SEO › Werkzeuge › SEO-Datenoptimierung bzw. `wp yoast index --reindex`.

### Überschreib-Regeln

- **Neuer Vorlagenstand** (neue `content/pages.php` mit einem Theme-Update): Der nächste Abgleich übernimmt ihn
  überall dort, wo du in WordPress nichts geändert hast – Seiteninhalt (Prüfsumme `_kw_content_hash`) und jedes
  SEO-Feld einzeln (`_kw_content_seo` bzw. `_kw_yoast_written` = zuletzt vom Theme geschriebene Werte).
- **Von dir geändert:** Seiten mit geändertem Titel/Inhalt bleiben (Hinweis „von dir bearbeitet“). Ein Yoast-Feld
  wird nur gesetzt, wenn es leer ist und das Theme es noch nie beschrieben hat, oder wenn es noch genau den zuletzt
  vom Theme geschriebenen Wert enthält. Ein Feld, das du ausgefüllt, geändert oder bewusst geleert hast, bleibt.
- **„Alles aus dem Theme übernehmen (überschreibt deine Änderungen)“** (Einrichtungsseite, nie vorausgewählt): erst
  eine Bestätigungsseite mit Warnung und Liste der betroffenen Seiten samt Grund (Inhalt, SEO-Felder, Yoast-Felder,
  schon vorhandene fremde Seite), dann nur mit Pflicht-Häkchen. Überschreibt auch Geändertes; der alte Seitentext
  bleibt in den Revisionen, SEO-/Yoast-Felder haben keine. Rechtstexte, Seiten im Papierkorb und veröffentlichte
  Seiten, deren neue Vorlage Platzhalter enthält, bleiben auch dann unberührt. WP-CLI: `--force`.

```bash
wp kurswasser content-pages status [--seo=yoast|theme]          # inkl. Yoast-Felder, die gesetzt würden
wp kurswasser content-pages sync --user=<admin> [--seo=yoast|theme] [--force]
```

`--seo` gilt nur für diesen Lauf; ohne gilt die Wahl aus der Einrichtung.

### Schritt 3 – Shop einrichten (`inc/setup-shop.php`)

Erscheint nur mit aktivem WooCommerce (sonst ein Satz im Einleitungstext). Ablauf:

1. **Formular:** Land (27 EU-Länder und Schweiz) und Region (aus WooCommerce), Anschrift (Straße, PLZ, Ort),
   Mehrwertsteuer-Weg (kein Weg vorausgewählt, Hinweis „im Zweifel mit dem Steuerberater klären“), Verkauf an
   „EU + Schweiz“ (empfohlen) oder „alle Länder“, Häkchen: Produkte als Entwurf, Schnupper-Paket, Registrierung,
   Sprache Deutsch.
2. **Vorschau** (`kw_view=shop`, ändert nichts): je Einstellung „aktuell → neu“ mit Häkchen, die EU-Steuertabelle mit
   Stand, der Produkt-Probelauf (`applyProducts(true)`) und das Schnupper-Paket (`applyFreeTier(true)`). Tragen die von
   WooCommerce angelegten Seiten noch den englischen Standardtitel („Cart“, „Checkout“, „My account“), bietet die
   Vorschau an, sie in „Warenkorb“, „Kasse“, „Mein Konto“ umzubenennen (Slugs `warenkorb`, `kasse`, `mein-konto`, nur
   wenn der Slug noch der englische Standard ist; von Hand benannte Seiten bleiben).
3. **Bestätigen** (POST an `admin-post.php?action=kurswasser_setup_shop`, Nonce, `manage_options` **und**
   `manage_woocommerce`) → **Ergebnis** (`kw_view=shop-result`): erledigt, übersprungen, Fehler, bei Produkten die Zeilen
   aus `applyProducts` (Artikelnummer, Ergebnis, von Hand geänderte Felder).

**Mehrwertsteuer-Wege**

| Weg | Steuer | Steuersätze (Standard-Klasse) | Preis-Hinweis |
|---|---|---|---|
| `oss` – Satz je EU-Kundenland | an, nach Rechnungsadresse | Normalsätze aller 27 EU-Länder; außerhalb der EU kein Satz | „inkl. MwSt.“ |
| `own` – eigener Satz für alle | an, nach Rechnungsadresse | ein Satz für „alle Länder“ (Vorschlag nach Land: DE 19, AT 20, CH 8,1) | „inkl. MwSt.“ |
| `small` – Kleinunternehmer | aus (`woocommerce_calc_taxes` = no) | keine | DE „ohne MwSt. – Kleinunternehmer gem. § 19 UStG“, AT „ohne USt. – Kleinunternehmer gem. § 6 Abs. 1 Z 27 UStG“ (im Formular änderbar) |

Der Preis-Hinweis ist die Einstellung **„Hinweis zum Preis“ des Lern-Plugins** (`bp_learning_settings[price_note]`,
max. 80 Zeichen, später unter Bootsprüfung › Einstellungen änderbar). Das Plugin zeigt ihn hinter jedem Preis, in
Warenkorb und Kasse („Alle Preise …“) und in den Bestellmails. Ohne aktives Lern-Plugin gibt es keinen Hinweis
(Meldung in der Vorschau).

Die EU-Tabelle steht in `KURSWASSER_EU_VAT_RATES` mit `KURSWASSER_EU_VAT_RATES_STAND` (letzte berücksichtigte
Änderung, derzeit 01.08.2025: u. a. FI 25,5 %, SK 23 %, EE 24 %, RO 21 %). **Vor dem Livegang prüfen** – regionale
Sätze (Madeira, Azoren, griechische Inseln …) sind nicht enthalten. Bei OSS setzt der Schritt zusätzlich
`kurswasser_shop_same_gross_price` = yes (Filter `woocommerce_adjust_non_base_location_prices` → false): Der Preis der
Preis-Seite gilt für alle EU-Kunden, nur der MwSt.-Anteil ändert sich.

**Weitere Einstellungen:** Währung EUR, Symbol rechts mit Leerzeichen, Tausender „.“, Dezimal „,“, 2 Nachkommastellen
(passt zu `wc_price()` in `inc/offers.php` und dessen Rückfall `29,90 €`); Preise inklusive Steuer (die Preise der
Preis-Seite sind Bruttopreise, vgl. Systemstatus des Plugins), Anzeige inkl. in Shop und Warenkorb;
`woocommerce_ship_to_countries` = disabled (nur digitale Produkte, keine Lieferadresse); Verkaufsländer;
`woocommerce_enable_myaccount_registration` = yes (das zählt in `templates/app.php` des Plugins neben
`users_can_register` für „Konto anlegen“); `WPLANG` = de_DE nur, wenn `wp_download_language_pack()` das Paket laden
konnte (danach Versuch, die Übersetzungen der Plugins zu laden) – sonst klare Meldung, alles andere läuft weiter.

**Sichtbarkeit:** Neue WooCommerce-Shops starten im Modus „Demnächst verfügbar“ (Gäste sehen Shop, Warenkorb und Kasse nicht). Der Assistent schaltet ihn nur mit dem Häkchen „Shop für Kunden sichtbar machen“ aus (`woocommerce_coming_soon` = no; WP-CLI `--live`) – erst zum Livegang. Ohne Häkchen weist die Vorschau darauf hin.

**Was nicht überschrieben wird**

- Einstellungen: Angehakt sind nur leere Felder und WooCommerce-Werkseinstellungen (`kurswasser_shop_factory_defaults()`).
  Was schon anders eingestellt ist, steht gelb und **nicht angehakt** in der Vorschau und bleibt ohne Häkchen. Hat sich
  ein Wert zwischen Vorschau und Bestätigung geändert, wird er nicht überschrieben (Prüfsumme `kw_seen`).
- Steuersätze: nie gelöscht, nie doppelt. Gleiches Land + Satz + Klasse = vorhanden; gibt es für ein Land schon einen
  anderen Satz, wird keiner angelegt (Meldung). Weitere vorhandene Sätze bleiben und werden genannt.
- Produkte: über `ShopCatalog::applyProducts(false, false)` – neue als **Entwurf**, nie veröffentlicht, Status
  bestehender Produkte bleibt, Preise nur von der Preis-Seite, von Hand geänderte Felder bleiben. Bei Angebotspreis-
  Konflikten (`saleConflicts()`) kein Abgleich.
- Schnupper-Paket: `ShopCatalog::applyFreeTier()` legt nur fehlende Regeln an.
- Nicht angefasst: „Zugang ohne Shop“ des Lern-Plugins – steht er auf „alle angemeldeten Nutzer“, weist die Vorschau
  darauf hin (vor dem Verkaufsstart auf „Nur kostenlose Regeln und Freischaltungen“ stellen).

WP-CLI (ohne `--yes` nur Vorschau; ohne `--overwrite` nur die vorausgewählten Punkte):

```bash
wp kurswasser setup shop --country=DE --tax=oss --products
wp kurswasser setup shop --country=AT --tax=own --rate=20 --products --free --yes --user=admin
wp kurswasser setup shop --country=DE --tax=small --no-language --yes   # weitere: --state --address --postcode --city --note --sell=eu|all --no-registration
```

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

**Betreiberangaben** (Design › Betreiberangaben, `inc/operator.php`): Firmenname, Anschrift, Kontakt, Register,
Steuernummern, Dienstleister, Fristen einmal eintragen – sie ersetzen die passenden `[[Betreiber: …]]`-Platzhalter
der Rechtstext-Vorlagen beim Übernehmen (57 von 82; der Rest sind Entscheidungen bzw. Prüfungen) und dieselben Platzhalter in den Inhaltsseiten (z. B. Garantiegeber, Erstattungsfrist, Support-E-Mail). Geänderte Angaben
aktualisieren unbearbeitete Entwürfe automatisch, bearbeitete Seiten nie. Ausfüll-Liste: `docs/rechtstexte-platzhalter.md`.

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

Hinweis zu Zahlungsarten, Verkaufsländern und Lieferung (wie unter dem Kaufknopf) frei platzieren, z. B. in der
Fußzeile oder auf einer Kursseite: Shortcode `[kurswasser_purchase_info]` (ohne Lern-Plugin bzw. WooCommerce leer).

## Links ohne feste IDs

In Vorlagen und Mustern stehen Ziele als `#kw:<ziel>` und werden beim Ausgeben ersetzt:
`shop`, `account`, `learn`, `tips`, die Shop-Kategorien `sbf`, `funk`, `seenot`, `ergaenzung`,
`pakete`, `gruppen` (feste Kürzel aus dem Plugin-Katalog), Startseiten-Anker wie `#kw:#faq` und
sonst `/<ziel>/` (z. B. `#kw:impressum`).

`#kw:page:<pfad>[#anker][|<ersatz>]` (Kopf und Fuß): Link auf eine Seite nur, wenn sie veröffentlicht ist; sonst
das Ersatzziel (z. B. `|gruppen`, `|#faq`) oder der Eintrag entfällt. Im Seiteninhalt und in Mustern werden Links
auf Inhaltsseiten, die noch Entwurf sind, als Text ausgegeben; Karten, Kacheln und Buttons mit solchem Ziel entfallen.

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
Typen B1–B10 des Seiten-Baukastens; Format im Kopf von `tools/content_build.py`). Die Quellen der 35 Seiten
(Moderation vom 10.11.2026) liegen in `content/src/`, die Moderationsunterlagen (FAQ-Änderungen, Seitenindex,
Navigation) in `content/src/_moderation/`. Daraus:

```bash
python3 themes/kurswasser/tools/content_build.py themes/kurswasser/content/src --check   # nur prüfen
python3 themes/kurswasser/tools/content_build.py themes/kurswasser/content/src           # → content/pages.php + content/pages/*.html
wp kurswasser content-pages status                                         # was wäre neu/aktualisierbar, offene Punkte
wp kurswasser content-pages sync --user=<admin>                             # anlegen bzw. aktualisieren
```

- Typ A = Vorlage `course` mit Kurs-Hero (`[kurswasser_page_hero]`: Brotkrumen, Eyebrow, H1 = Seitentitel,
  Lead, CTAs, optional Foto/Kennzahlen/Sprungnavigation); Typ B = Vorlage `page`.
- `{{brand}}` wird beim Anlegen (und beim Ausgeben) zum Website-Namen.
- Nichts wird ungefragt überschrieben: vom Betreiber geänderte Seiten bleiben (Prüfsumme), neue Vorlagenstände
  meldet der Admin-Hinweis (Dashboard, Seiten) mit Knopf „Inhaltsseiten anlegen bzw. aktualisieren“ und den
  offenen Punkten je Seite. Seiten mit `[[Betreiber: …]]` bzw. „Offen“-Kasten entstehen als Entwurf. Regeln im
  Einzelnen und „Alles aus dem Theme übernehmen“: Abschnitt „Einrichtung“ oben.
- Optionales Front-Matter-Feld `focus_keyword:` (ein Begriff, ohne `{{brand}}`) – nur für Yoast (Option B).
- Einträge für Rechtstext-Seiten (`kontakt` usw.) werden übersprungen – die pflegt `inc/legal.php`
  (Seite 33 steckt in `legal/kontakt.html`).
- Eine alte FAQ-Seite `/haeufige-fragen/` mit `[bootspruefung_faq]` leitet per 301 auf `/faq-bootsfuehrerschein/`.
- `#kw:`-Links überstehen kses (auch ohne `unfiltered_html`).

## SEO (`inc/seo.php`)

Metabox „SEO (Kurswasser)“ an Seiten und Beiträgen: SEO-Titel, Meta-Description, Canonical, Stand, noindex,
Schema. Ausgabe: `<title>`, Description, `noindex, follow`, Canonical, keine noindex-Seiten in der Sitemap,
JSON-LD BreadcrumbList (Seiten), Article (Schema „Article“), Organization + WebSite (nur Startseite).
Mit Yoast SEO oder Rank Math aktiv: keine eigene Ausgabe, nur Rückfall auf die eigenen Felder, wo das
Plugin-Feld leer ist. Mit Option B der Einrichtung stehen die Werte der Inhaltsseiten zusätzlich direkt in den
Yoast-Feldern (siehe „Einrichtung“).

## Prüfen

CI (Job mit WooCommerce 11.2.2) testet Lernbereich, Trainer und Kaufablauf mit diesem Theme,
legt danach den Produktkatalog an und speichert Screenshots (Desktop und Mobil) als Artefakt
`theme-kurswasser-screenshots`. Integrationstests `tests/content-pages-test.php` und `tests/setup-yoast-test.php`
(Einrichtung, Plugin-Installation aus einer Dummy-ZIP, Yoast-Übernahme – Yoast dort nur als Stub, nicht das echte
Plugin) sowie `tests/setup-shop-test.php` (Schritt 3 mit echtem WooCommerce, ohne Netz; aktiviert WooCommerce bei Bedarf
selbst). Lokal:

```bash
BP_URL=http://127.0.0.1:8099 BP_SHOTS=/tmp/shots node themes/kurswasser/tests/screenshots.mjs
```

## Vor dem Livegang

- Logo hochladen (Editor › Kopfbereich › Website-Logo) und Markenname setzen.
- EU-Steuertabelle (`KURSWASSER_EU_VAT_RATES` in `inc/setup-shop.php`) und den Mehrwertsteuer-Weg mit dem
  Steuerberater prüfen; Lern-Plugin „Zugang ohne Shop“ auf „Nur kostenlose Regeln und Freischaltungen“ stellen.
- Rechtstexte in die Entwürfe einfügen und veröffentlichen.
- Hero-Foto ggf. durch eigenes Motiv ersetzen (`assets/images/hero-boat.*`).
