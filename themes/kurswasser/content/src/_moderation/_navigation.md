# Navigation, Seitenhierarchie und Brotkrumen (Vorschlag)

Stand: 10.11.2026.

**Grundlagen:**
- `review/3-design.md`, Abschnitt 4, und `review/4-seo-marketing.md`, Abschnitte 1.1 und 3.4.
- Der Seitenbestand: alle 34 Seiten aus `content/pages/` plus `/ueber-uns/`.

**Regel:** Navigation und Fuß verlinken nur veröffentlichte Seiten. Entwürfe (Status `draft`) erscheinen erst nach ihrer Veröffentlichung. Seiten mit `noindex` werden nicht ins Menü aufgenommen, sie sind nur über Querverweise erreichbar.

Status der Seiten:

| Status | Seiten |
|---|---|
| publish, index | 2, 6, 7, 12, 17, 18, 19, 20, 21, 34, `/ueber-uns/` |
| publish, noindex | 1, 10, 11, 13, 14, 15, 16, 30 |
| draft | 3, 4, 5, 8, 9, 22–29, 31, 32, 33 |

---

## 1. Kopf (`parts/header.html`)

Es bleibt bei vier Einträgen. So passt die Navigation ab 600 px ohne Burger-Menü neben Konto, Warenkorb und „Kurs wählen“.

| Eintrag | Heute | Neu | Ziel |
|---|---|---|---|
| Kurse | `#kw:kurse` | unverändert | `/kurse/` |
| So funktioniert’s → **Ratgeber** | `#kw:#ablauf` | Bezeichnung „Ratgeber“ | `/sportbootfuehrerschein/` (Seite 18, Hub) |
| Gruppen | `#kw:gruppen` (Produktkategorie) | Seite statt Kategorie | `/bootsfuehrerschein-gruppenangebote/` (Seite 7) |
| FAQ | `#kw:#faq` (Startseiten-Anker) | Seite statt Anker | `/faq-bootsfuehrerschein/` (Seite 34) |

Der Button „Kurs wählen“ (`#kw:kurse`) bleibt.

Zum Eintrag „Ratgeber“:
- Er zeigt auf den Hub 18 und nicht auf Seite 15. Seite 15 und Seite 16 tragen `noindex` mit Canonical auf 18; das Menü verlinkt die kanonische Seite.
- Soll das Menü trotzdem die Übersichtsseite zeigen, ist `/ratgeber-bootsfuehrerschein/` (15) die Alternative.
- „So funktioniert’s“ wandert in den Fuß (→ Seite 12) und bleibt im Startseiten-Hero.

Umsetzung:
- In `header.html` die `url`-Attribute der `navigation-link`-Blöcke auf relative Pfade setzen, zum Beispiel `"url":"/sportbootfuehrerschein/"`.
- Alternativ `kurswasser_url()` um feste Ziele ergänzen.

---

## 2. Fuß (`parts/footer.html`)

Struktur und Stile bleiben gleich (`kw-footer-links`).

Spalten:
- Markenspalte und fünf Linkspalten.
- Die Markenspalte geht von 34 % auf etwa 28 %. Das ist nur eine Spaltenbreite, das Design ändert sich nicht.
- Bei 782 bis 1100 px prüfen. Unter 782 px stapeln sich die Spalten ohnehin.

### Kurse (wie heute, ergänzt)
- SBF See · SBF Binnen · SBF Binnen unter Segel · SRC · UBI · SKN · FKN · Pakete & Pässe
- **Gruppen & Vereine** → `/bootsfuehrerschein-gruppenangebote/` (heute `#kw:gruppen`)
- **Geld-zurück-Garantie** → `/geld-zurueck-garantie-bootsfuehrerschein/`, sobald Seite 9 veröffentlicht ist
- später **Gutscheine** → `/bootsfuehrerschein-gutschein/`, erst wenn ein Gutscheinprodukt existiert (Seite 8)

### Ratgeber (neu)
Die Spalte stärkt den Cluster auf allen Seiten (SEO).
- Sportbootführerschein → `/sportbootfuehrerschein/`
- See oder Binnen zuerst? → `/reihenfolge-bootsfuehrerschein/`
- Kosten → `/bootsfuehrerschein-kosten/`
- Ärztliches Attest → `/aerztliches-attest-bootsfuehrerschein/`
- Mindestalter → `/mindestalter-bootsfuehrerschein/`
- Im Ausland → `/bootsfuehrerschein-ausland-anerkennung/`
- Fragenkatalog → `/amtliche-pruefungsboegen-sbf/` (sobald Seite 5 veröffentlicht ist)

### Lernen
- Zum Lernbereich (`#kw:learn`)
- So funktioniert’s → `/online-lernplattform-bootsfuehrerschein/` (Seite 12)
- Tipps & Tricks (`#kw:tips`, nur nach Login)
- Mein Konto (`#kw:account`)
- Häufige Fragen → `/faq-bootsfuehrerschein/` (heute `#kw:#faq`)

### Über uns (neu)
- Über uns → `/ueber-uns/`
- Unsere Mission → `/ueber-uns/geschichte-mission/` (sobald 23 veröffentlicht ist)
- Team → `/ueber-uns/team/` (sobald 24 veröffentlicht ist)
- Partner werden → `/partnerprogramm/` (sobald 27 veröffentlicht ist)
- Karriere → `/karriere/` (sobald 32 veröffentlicht ist)

Nicht im Fuß:
- Seite 25 (Auszeichnungen) und Seite 26 (Studien) erscheinen nicht im Fuß, auch nicht nach ihrer Veröffentlichung, solange sie `noindex` tragen. Recht verlangt für leere Seiten ausdrücklich: nicht in Menü oder Sitemap.
- Seite 29 und Seite 31 erscheinen ebenfalls nicht im Fuß (`noindex`).

### Rechtliches (unverändert)
Impressum · Datenschutz · AGB · Widerrufsbelehrung · Vertrag hier widerrufen · **Kontakt** (`#kw:kontakt` = Seite 33) · Cookie-Einstellungen

### Alternative ohne fünfte Linkspalte
Ist die fünfte Spalte zu eng, kommen die Links von „Über uns“ an den Anfang der Spalte „Rechtliches“. Die Spalte heißt dann „Über uns & Recht“ (Design-Alternative).

---

## 3. Seitenhierarchie (WordPress-Elternseiten)

Elternseiten gibt es nur dort, wo der Slug es verlangt. Sonst würden sich die URLs ändern (Rahmen: Slugs wie im Konzept).

```
/                                  Startseite
├── /kurse/ …                      vorhanden (Theme)
├── /ueber-uns/                    NEU, Typ B, publish (00-ueber-uns.md)
│   ├── /ueber-uns/geschichte-mission/   Seite 23 (draft)
│   └── /ueber-uns/team/                 Seite 24 (draft)
└── alle übrigen Seiten (1–22, 25–34) auf oberster Ebene
```

Die Gruppen ordnen die Seiten logisch. Das ist keine WordPress-Hierarchie; sie zeigt sich in Brotkrumen, Hub-Kacheln und Fuß.

| Gruppe | Hub | Seiten |
|---|---|---|
| Ratgeber | 18 `/sportbootfuehrerschein/` | 2, 3, 4, 5, 14, 15*, 16*, 17, 19, 20, 21, 28* |
| Kurse | `/kurse/` | 1*, 6, 7, 8, 9, 10*, 11*, 12, 13*, 22, 30* |
| Über uns | `/ueber-uns/` | 23, 24, 25*, 26*, 27, 29*, 31*, 32 |
| Service | – | 33 Kontakt, 34 FAQ |

\* `noindex`, Canonical auf die Hauptseite. Die Zuordnung steht in den jeweiligen Seitendateien:

| Seite | Canonical auf |
|---|---|
| 15, 16 | 18 |
| 28, 29 | 6 |
| 30 | 7 |
| 31 | 27 |
| 25 | `/ueber-uns/` |
| 10, 11, 13, 1 | laut Moderation 1–17 |

Statt Canonical sind Weiterleitungen (301) möglich, wenn eine Seite später ganz entfallen soll. Das gilt für 10 → 9, 28 → 6#ausbildungsstaetten, 30 → 7#vereine und 31 → 27. Heute ist keine Weiterleitung nötig, weil alle Seiten angelegt werden. Einzige Ausnahme: `/haeufige-fragen/` → `/faq-bootsfuehrerschein/`, falls diese Seite schon existiert.

---

## 4. Brotkrumen

Typ A (Kurs-Hero):
- Brotkrumen erscheinen im Hero wie auf den Kursseiten, als `<nav class="kw-crumbs" aria-label="Brotkrumen">`. Das letzte Glied trägt `aria-current="page"`.
- Kurzlabel höchstens 25 Zeichen.
- Pfade:
  - **Ratgeber:** Start / Ratgeber / Kurzlabel. „Ratgeber“ verlinkt `/ratgeber-bootsfuehrerschein/`. Für 18 lautet die Kette Start / Ratgeber / Sportbootführerschein.
  - **Kurse:** Start / Kurse / Kurzlabel. „Kurse“ verlinkt `/kurse/`, nicht `/#kurse` (SEO 3.5; gilt auch für die vorhandenen Kursseiten).

Typ B (grauer Kopf): keine Brotkrumen. `kw-crumbs` hat auf Nebelgrau nur 1,76 : 1 Kontrast. Das betrifft 23–27, 29, 31–34 und `/ueber-uns/`. Die Zugehörigkeit zeigen Fuß und Querverweise.

BreadcrumbList-JSON-LD: optional aus derselben Datenquelle wie die sichtbaren Brotkrumen (`kurswasser_breadcrumbs()`, SEO 3.5). Bei flachen Slugs braucht es dafür das Meta-Feld `_kw_crumb_parent`.
