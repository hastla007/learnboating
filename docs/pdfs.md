# PDFs für den Lernbereich (To-do T70)

Der Generator `tools/pdf-build/build-pdfs.mjs` erzeugt druckbare PDFs aus einem gebauten Content-Paket:
Navigationsaufgaben, Lösungen, Prüfungsbögen mit Lösungsschlüssel, Funk-Vokabeln, Muster-Funksprüche,
Funk-Übungstexte und die Buchstabiertafel. Die PDFs landen unter `build/pdfs/` und werden **nicht** versioniert.
Hochladen und Zuordnen geschieht von Hand im WordPress-Admin (Abschnitt 4).

## 1. Aufruf

Voraussetzungen: Node.js (getestet mit 22) und Playwright mit Chromium. Weitere npm-Pakete braucht das Skript nicht.
Das Skript lädt zuerst `playwright` über die normale Modulsuche (z. B. `NODE_PATH`) und danach
`/opt/node-tools/node_modules/playwright`.

```sh
# alles aus dem neuesten Paket unter build/ (Feld built_at im manifest.json)
node tools/pdf-build/build-pdfs.mjs

# Paket und Ziel ausdrücklich angeben
node tools/pdf-build/build-pdfs.mjs --package=build/boatingschool-136a243-2016f8cd853f --out=build/pdfs

# nur bestimmte Arten oder Kurse
node tools/pdf-build/build-pdfs.mjs --only=exam,exam-key --courses=SBF-SEE,SRC

# nur auflisten, nichts drucken
node tools/pdf-build/build-pdfs.mjs --dry-run
```

Weitere Schalter: `--html` legt das HTML jeder Datei neben das PDF (zur Fehlersuche; Bilder fehlen darin),
`--help` zeigt die Kurzhilfe.

Arten für `--only`: `navigation`, `navigation-solutions`, `exam`, `exam-key`, `vocabulary`, `samples`, `texts`,
`spelling`.

Neben den PDFs schreibt das Skript `build/pdfs/uebersicht.json`. Darin steht je Datei der vorgeschlagene Titel, die
Beschriftung (Kurztext), der Lernzugang (Kurs · Funktion), der Anzeigeort, die Seitenzahl und die Größe. Ein Teillauf
mit `--only` oder `--courses` ergänzt eine vorhandene Übersicht derselben Paketversion.

## 2. Was erzeugt wird

| Art | Inhalt | Daten im Paket |
|---|---|---|
| Navigationsaufgaben | Lage und Fragen mit Schreiblinien, ohne Lösungen | `navigation_tasks.json` |
| Lösungen der Navigationsaufgaben | Tabelle Frage/Lösung je Aufgabe, dazu der Hinweis aus dem Paket (z. B. Aufgabe 1) | `navigation_tasks.json` |
| Prüfungsbögen bzw. Übungsbögen | je Bogen eine neue Seite, Fragen mit Abbildungen und Antwortmöglichkeiten zum Ankreuzen, ohne Lösung | `exam_papers.json`, `questions.json`, `media.json` |
| Lösungsschlüssel | je Bogen: Frage, richtige Antwort (Buchstabe bzw. Musterantwort bei offenen Fragen), Kennung der Frage | wie oben |
| Funk-Vokabeln | Deutsch–Englisch nach Themen (ohne Kategorie „Buchstabiertafel“) | `radio_vocabulary.json` |
| Muster-Funksprüche | Lage, Musterlösung für Rang, DSC-Controller bzw. Verkehrskreis, Funkgerät und den Wortlaut der Meldung | `radio_practice.json` |
| Funk-Übungstexte | SRC: Texte Deutsch/Englisch (Land → See, See → Land); UBI: Aufnahme (Diktat) und Abgabe mit buchstabierten Wörtern | `radio_texts.json` |
| Buchstabiertafel | Buchstaben aus dem Paket (UBI mit Aussprachehilfe), Ziffern wie im Funktraining | `radio_vocabulary.json` (Kategorie „Buchstabiertafel“), Ziffern aus `bootspruefung-learning/src/Trainers/RadioText.php` |

**Welcher Kurs welche Datei bekommt.** Kombi- und Ergänzungskurse zeigen im Lernbereich die PDFs ihrer Teilkurse mit
an (`SectionFiles::PARTS` im Plugin, das Skript liest die Liste dort aus). Ein Kurs bekommt deshalb nur dann ein
eigenes PDF, wenn er Inhalte hat, die über seine Teilkurse noch nicht sichtbar sind. Beispiele:

- Navigationsaufgaben gibt es nur bei SBF See. SBF See & Binnen und Ergänzung Binnen → See zeigen sie über SBF See.
- Funk-PDFs gibt es nur bei SRC und UBI. Der Kombikurs SRC & UBI und die Ergänzung SRC → UBI zeigen sie über ihre
  Teilkurse.
- SBF See & Binnen hat im Paket 30 Bögen: Kopien der Bögen 1–15 von SBF See und der Bögen 1–15 von
  Ergänzung See → Binnen (dort Nr. 101–115). Die SBF-See-Bögen sieht der Kurs über SBF See; deshalb enthält
  `sbf-see-bin-pruefungsboegen.pdf` nur die Bögen 101–115. Gleiches gilt für SRC & UBI (nur Bögen 201–212, Kopien
  der Ergänzung SRC → UBI).
- SKN und Ergänzung FKN → SKN bekommen keine PDFs, weil das Paket dafür weder Bögen noch Navigations- oder
  Funkdaten enthält.
- UBI hat im Paket keine eigenen Vokabeln außer der Buchstabiertafel. Deshalb gibt es kein UBI-Vokabel-PDF.

**Antworten gemischt.** Im Paket steht bei Einfachauswahl die richtige Antwort an erster Stelle. Das Skript mischt
die Antworten deshalb je Bogen und Frage in einer festen Reihenfolge (aus Bogen- und Fragenkennung berechnet).
Bogen und Lösungsschlüssel passen nur zusammen, wenn sie aus demselben Lauf und Paket stammen. Darum immer beide
zusammen erzeugen und hochladen.

**Bezeichnung „Prüfungsbögen“ oder „Übungsbögen“.** Das Skript übernimmt die Bezeichnung aus der Quelldatei im
Paket (`…_pruefungsboegen.csv` bzw. `…_uebungsboegen.csv`). Die Bögen von UBI, Ergänzung SRC → UBI und SRC & UBI
heißen deshalb „Übungsbögen“. Das Wort „amtlich“ steht in keinem PDF. Die Quelle steht so drin, wie sie im Paket
steht, z. B. „Zentrale Fragenbasis SBF/SRC/UBI (Version 2026-09)“ oder „ELWIS Fragenkatalog See, Stand August 2023“.

**Abbildungen.** Das Skript bindet nur Bilder ein, die laut `media.json` im Paket liegen. Fehlt eine Datei, steht an
ihrer Stelle der Hinweis „Abbildung im Lernbereich“. Als Alternativtext dient die Beschreibung aus `media.json`,
sonst „Abbildung zur Frage“.

**Kennzeichnung.** Jede Seite hat oben den Kurs und den Titel. Unten stehen „Stand: <Datum der Inhalte>, Paket
<package_id>“, die Quelle laut Paket und „Seite x von y“. Die erste Seite nennt Kurs, Inhalt und Quelle noch einmal.
Die Freigabe der Rechte ist noch offen (To-do T68). Die PDFs enthalten dazu keinen Vermerk.

**Barrierefreiheit.** Jedes PDF hat einen Dokumenttitel aus `<title>` (z. B. „Navigationsaufgaben – SBF See“), die
Sprache `de` und Lesezeichen aus den Überschriften. Es ist getaggt: Playwright 1.63 unterstützt `tagged: true` und
`outline: true` bei `page.pdf`, pdfinfo meldet „Tagged: yes“. Inhalte stehen als echte Überschriften, Listen und
Tabellen mit Kopfzeilen im HTML. Englische Spalten sind mit `lang="en"` markiert. Schrift: `system-ui` mit
Ersatzschriften, schwarz auf weiß. Eine Prüfung mit PAC oder veraPDF (PDF/UA) steht noch aus.

## 3. Probelauf vom 13.11.2026

Paket `boatingschool-136a243-2016f8cd853f` (Inhalte vom 05.11.2026), Playwright 1.63.0, Chromium 1243. Laufzeit etwa
21 Sekunden.

| Art | Dateien | Seiten | Größe |
|---|---:|---:|---:|
| Navigationsaufgaben | 1 | 30 | 0,2 MB |
| Lösungen der Navigationsaufgaben | 1 | 15 | 0,3 MB |
| Prüfungs-/Übungsbögen | 12 | 965 | 42,7 MB |
| Lösungsschlüssel | 12 | 56 | 4,6 MB |
| Funk-Vokabeln | 1 | 14 | 0,4 MB |
| Muster-Funksprüche | 2 | 68 | 0,8 MB |
| Funk-Übungstexte | 2 | 8 | 0,2 MB |
| Buchstabiertafel | 2 | 4 | 0,2 MB |
| **Summe** | **33** | **1.160** | **49,5 MB** |

- 783 Abbildungen eingebunden, 0 fehlend.
- Alle 33 PDFs getaggt, Sprache `de`, Titel gesetzt (geprüft mit pdfinfo und pypdf).
- Größte Dateien: `sbf-see-pruefungsboegen.pdf` (15,1 MB, 148 Seiten) und `add-bin-to-see-pruefungsboegen.pdf`
  (14,7 MB, 122 Seiten). Grund: die großen ELWIS-Abbildungen der See-Fragen; Chromium bettet ein Bild bei jedem
  Vorkommen neu ein. Alle anderen Dateien sind kleiner als 3 MB.
- Stichprobe: Je Art eine Seite als PNG unter
  `/tmp/claude-0/-home-user-learnboating/6400d818-1ce7-5bb7-865f-40d7cc776874/scratchpad/pdf-check/` angesehen
  (Navigationsaufgaben, Lösungen, Prüfungsbogen SBF See mit Abbildung, Lösungsschlüssel, Vokabeln, Muster-Funkspruch,
  UBI-Übungstexte, UBI-Buchstabiertafel, FKN-Bogen mit Mehrfachauswahl und offenen Fragen). Kopf, Fuß, Tabellen und
  Abbildungen sind gut lesbar. Stichprobe Lösungsschlüssel: SBF See, Bogen 1, Frage 1 (SBF-BASIS-0008) zeigt „d“.
  Das ist im Bogen die Antwort „Die dem Wind zugekehrte Seite.“, die laut Paket richtig ist.

### Dateien und Zuordnung

Titel und Beschriftung sind Vorschläge (stehen auch in `uebersicht.json`). Lernzugang = Feld „Lernzugang
erforderlich“, Anzeigeort = Feld „Anzeige im Lernbereich“.

| Datei (unter `build/pdfs/<kurs>/`) | Seiten | Größe | Lernzugang | Anzeigeort |
|---|---:|---:|---|---|
| `sbf-see-navigationsaufgaben.pdf` | 30 | 245 KB | SBF See · Navigationstrainer | Hauptseite |
| `sbf-see-navigationsaufgaben-loesungen.pdf` | 15 | 299 KB | SBF See · Navigationstrainer | Hauptseite |
| `sbf-see-pruefungsboegen.pdf` (1–15) | 148 | 15,1 MB | SBF See · Prüfungssimulation | Hauptseite |
| `sbf-see-pruefungsboegen-loesungsschluessel.pdf` | 7 | 577 KB | SBF See · Prüfungssimulation | Hauptseite |
| `sbf-bin-motor-pruefungsboegen.pdf` (1–15) | 130 | 2,5 MB | SBF Binnen · Prüfungssimulation | Hauptseite |
| `sbf-bin-motor-pruefungsboegen-loesungsschluessel.pdf` | 7 | 577 KB | SBF Binnen · Prüfungssimulation | Hauptseite |
| `sbf-bin-segel-pruefungsboegen.pdf` (1–15) | 149 | 2,9 MB | SBF Binnen unter Segel · Prüfungssimulation | Hauptseite |
| `sbf-bin-segel-pruefungsboegen-loesungsschluessel.pdf` | 8 | 691 KB | SBF Binnen unter Segel · Prüfungssimulation | Hauptseite |
| `sbf-see-bin-pruefungsboegen.pdf` (101–115) | 110 | 2,1 MB | SBF See & Binnen · Prüfungssimulation | Hauptseite |
| `sbf-see-bin-pruefungsboegen-loesungsschluessel.pdf` | 5 | 462 KB | SBF See & Binnen · Prüfungssimulation | Hauptseite |
| `add-see-to-bin-pruefungsboegen.pdf` (1–15) | 110 | 2,2 MB | Ergänzung See → Binnen · Prüfungssimulation | Hauptseite |
| `add-see-to-bin-pruefungsboegen-loesungsschluessel.pdf` | 5 | 463 KB | Ergänzung See → Binnen · Prüfungssimulation | Hauptseite |
| `add-bin-to-see-pruefungsboegen.pdf` (1–15) | 122 | 14,7 MB | Ergänzung Binnen → See · Prüfungssimulation | Hauptseite |
| `add-bin-to-see-pruefungsboegen-loesungsschluessel.pdf` | 5 | 464 KB | Ergänzung Binnen → See · Prüfungssimulation | Hauptseite |
| `add-bin-segel-pruefungsboegen.pdf` (1–15) | 30 | 467 KB | Ergänzung Binnen → Segeln · Prüfungssimulation | Hauptseite |
| `add-bin-segel-pruefungsboegen-loesungsschluessel.pdf` | 3 | 206 KB | Ergänzung Binnen → Segeln · Prüfungssimulation | Hauptseite |
| `src-pruefungsboegen.pdf` (1–12) | 60 | 942 KB | SRC Vollkurs · Prüfungssimulation | Hauptseite |
| `src-pruefungsboegen-loesungsschluessel.pdf` | 4 | 389 KB | SRC Vollkurs · Prüfungssimulation | Hauptseite |
| `ubi-uebungsboegen.pdf` (1–12) | 49 | 837 KB | UBI Vollkurs · Prüfungssimulation | Hauptseite |
| `ubi-uebungsboegen-loesungsschluessel.pdf` | 4 | 363 KB | UBI Vollkurs · Prüfungssimulation | Hauptseite |
| `src-ubi-uebungsboegen.pdf` (201–212) | 24 | 432 KB | SRC & UBI Kombikurs · Prüfungssimulation | Hauptseite |
| `src-ubi-uebungsboegen-loesungsschluessel.pdf` | 2 | 210 KB | SRC & UBI Kombikurs · Prüfungssimulation | Hauptseite |
| `add-ubi-to-src-uebungsboegen.pdf` (1–12) | 24 | 433 KB | Ergänzung SRC → UBI · Prüfungssimulation | Hauptseite |
| `add-ubi-to-src-uebungsboegen-loesungsschluessel.pdf` | 2 | 211 KB | Ergänzung SRC → UBI · Prüfungssimulation | Hauptseite |
| `fkn-pruefungsboegen.pdf` (1–3) | 9 | 191 KB | FKN Vollkurs · Prüfungssimulation | Hauptseite |
| `fkn-pruefungsboegen-loesungsschluessel.pdf` | 4 | 126 KB | FKN Vollkurs · Prüfungssimulation | Hauptseite |
| `src-funk-vokabeln.pdf` (358 Vokabeln) | 14 | 392 KB | SRC Vollkurs · Funktraining | Funk: auch auf der Vokabelseite |
| `src-muster-funksprueche.pdf` (42 Lagen) | 41 | 483 KB | SRC Vollkurs · Funktraining | Funk: auch auf der Seite Muster-Funksprüche |
| `ubi-muster-funksprueche.pdf` (30 Lagen) | 27 | 352 KB | UBI Vollkurs · Funktraining | Funk: auch auf der Seite Muster-Funksprüche |
| `src-funk-uebungstexte.pdf` (27 Texte) | 4 | 122 KB | SRC Vollkurs · Funktraining | Hauptseite |
| `ubi-funk-uebungstexte.pdf` (16 Texte) | 4 | 121 KB | UBI Vollkurs · Funktraining | Hauptseite |
| `src-buchstabiertafel.pdf` | 2 | 103 KB | SRC Vollkurs · Funktraining | Funk: auch auf der Seite Buchstabiertafel |
| `ubi-buchstabiertafel.pdf` | 2 | 110 KB | UBI Vollkurs · Funktraining | Funk: auch auf der Seite Buchstabiertafel |

## 4. Im WordPress-Admin hochladen und einem Kurs zuordnen

So steht es im Plugin (`src/Gate/ProtectedFiles.php`, `src/Gate/SectionFiles.php`, `src/Gate/DownloadService.php`):

1. **Medien › Datei hinzufügen**: PDF hochladen.
2. Datei in der Mediathek öffnen (Anhang-Details bzw. „Weitere Details bearbeiten“).
3. **Titel** setzen: Er erscheint im Lernbereich als Name der Datei. **Beschriftung** setzen: Sie erscheint als
   Kurztext darunter. Vorschläge stehen in `uebersicht.json` (Felder `title` und `caption`).
4. Feld **„Lernzugang erforderlich“**: „<Kurs> · <Funktion>“ wählen, z. B. „SBF See · Navigationstrainer“,
   „SRC Vollkurs · Prüfungssimulation“ oder „UBI Vollkurs · Funktraining“ (siehe Tabelle oben).
5. Nur bei Funk-PDFs: Feld **„Anzeige im Lernbereich“** wählen. „Hauptseite des Bereichs“ zeigt die Datei nur auf der
   Funk-Seite. „Funk: auch auf der Vokabelseite“, „… Seite Muster-Funksprüche“ oder „… Seite Buchstabiertafel“ zeigt sie
   zusätzlich dort. Das Plugin speichert den Anzeigeort nur zusammen mit einem Lernzugang.
6. Speichern („Aktualisieren“).

Was dann passiert:

- Das Plugin verschiebt die Datei in einen gesperrten Ordner und liefert sie nur noch über
  `/bootsscheine/datei/<id>/` aus. Der Link steht unter dem Feld. Vorschaubilder, die WordPress zum PDF erzeugt hat,
  werden mit verschoben.
- Klappt das Verschieben nicht, bleibt die Datei frei zugänglich und als nicht geschützt markiert. Der Admin sieht
  einen roten Hinweis (z. B. Schreibrechte des gesperrten Ordners prüfen) und speichert danach erneut.
- Dateien mit Navigationstrainer, Prüfungssimulation, Praxistrainer oder Funktraining erscheinen oben auf der
  passenden Lernseite des Kurses. Kombi- und Ergänzungskurse zeigen auch die Dateien ihrer Teilkurse:
  SRC & UBI → SRC, UBI; SBF See & Binnen → SBF See, SBF Binnen; Ergänzung SRC → UBI → UBI;
  Ergänzung Binnen → See → SBF See; Ergänzung See → Binnen → SBF Binnen; Ergänzung Binnen → Segeln →
  SBF Binnen unter Segel; Ergänzung FKN → SKN → SKN.
- Herunterladen dürfen nur angemeldete Nutzer mit vollem Zugang zu dieser Funktion, auch über einen Kombi- oder
  Ergänzungskurs. Im Teilumfang (z. B. Schnupper-Paket) ist die Datei gesperrt und zeigt das Kaufangebot.
- Reihenfolge: zuerst die Reihenfolge der Mediathek (`menu_order`), dann der Titel.

Alternative **„<Kurs> · Downloads (PDF)“**: Die Datei erscheint dann auf der Downloads-Seite genau dieses Kurses,
ohne Übernahme in Kombi- oder Ergänzungskurse. Im Teilumfang sind dort nur die ersten n Dateien frei. Für die PDFs
aus diesem Generator empfehlen wir die Lernseiten (Tabelle oben). Die Kunden-FAQ Nr. 6 „Wo finde ich Unterlagen zum
Herunterladen?“ erst freigeben, wenn Dateien hinterlegt sind (siehe `docs/kunden-faq-freigabe.md`).

Nach einem neuen Content-Paket: PDFs neu erzeugen und die alten Dateien in der Mediathek ersetzen. Bögen und
Lösungsschlüssel immer gemeinsam tauschen.

## 5. Offene Punkte

- **Rechte (T68):** Die Freigabe der Rechte ist noch offen. Im Paket stehen die Fragen, die eingebundenen ELWIS-Abbildungen, Funk-Vokabeln,
  Funktexte und Muster-Funksprüche auf Rechte-Status „review“, die Navigationsaufgaben auf „unknown“ (Ausnahme: die
  UBI-Buchstabiertafel steht auf „public“). Die PDFs erst hochladen, wenn die Nutzungsrechte geklärt sind.
- **Seekarte D49 (T69):** nicht enthalten. Die Navigationsaufgaben und Lösungen weisen auf der ersten Seite darauf
  hin.
- **SBF See & Binnen:** Der Kurs zeigt über `SectionFiles::PARTS` auch die Bögen von SBF Binnen (30 Fragen). Seine
  eigene Prüfung nutzt aber die Bögen der Ergänzung See → Binnen (Nr. 101–115). Ob das so gewollt ist, entscheidet
  der Betreiber. Gleiches gilt für SRC & UBI (zeigt UBI-Übungsbögen, nutzt die Ergänzungsbögen 201–212).
- **PDF/UA-Prüfung:** Getaggt ja, eine Prüfung mit PAC oder veraPDF steht aus (siehe `docs/barrierefreiheit-oesterreich.md`).
- **Dateigröße:** Die Bögen SBF See und Ergänzung Binnen → See sind je etwa 15 MB groß. Für das Laden auf dem Handy
  wäre eine kleinere Fassung (z. B. Bilder einmal einbetten oder verkleinern) besser. Das ist noch nicht umgesetzt.
- **Hochladen:** nur von Hand im Admin. Einen WP-CLI-Befehl für Upload und Lernzugang gibt es im Plugin nicht.
