# Technisches Umsetzungskonzept: WordPress-Lernplugin für Bootsprüfungen

**Stand:** 6. November 2026 (überarbeitet nach Quellabgleich)  
**Zielgruppe:** WordPress-, PHP-, WooCommerce- und Frontend-Entwicklung  
**Inhaltsquelle:** `hastla007/boatingschool`, Branch `main`, geprüfter Stand Commit `136a243` (05.11.2026)  
**Ziel-Repository:** `hastla007/learnboating`  

## 1. Ziel und Abgrenzung

Dieses Plugin erweitert eine bestehende WordPress-Website um eine eigenständige Lernplattform für Bootsführerscheine, Navigation, Praxis, Funk und Video-Kurse. Es importiert und nutzt den bereits aufbereiteten Content aus dem GitHub-Projekt `hastla007/boatingschool`.

Das Laravel-Projekt dient ausschließlich als **schreibgeschützte Content-Quelle**. Im Repository `hastla007/boatingschool` wird nichts geändert – insbesondere wird dort kein Exporter-Kommando ergänzt. Stattdessen wird das gesamte inhaltliche Gerüst (Datendateien, in Seedern hinterlegter Content, Medien und die zur Textgenerierung nötige Logik als Referenz) als versionierter Snapshot in dieses WordPress-Repository übernommen und hier in ein Content-Paket konvertiert (siehe Kap. 3).

Die Laravel-Anwendung, deren PostgreSQL-Datenbank, Login-System, Mandanten, Nutzer, Lernstände, Freischaltungen, Coupons und Betrieb werden nicht als Laufzeitabhängigkeit übernommen. Es gibt keine direkte Laufzeitverbindung von WordPress zur Laravel-Datenbank.

WordPress wird zur Laufzeit maßgebliche Instanz für:

- WordPress-Nutzer und Lernfortschritt;
- WooCommerce-Produkte, Bestellungen, Rückerstattungen und Zahlung;
- Zugangsrechte zur Lernplattform;
- lokale redaktionelle Ergänzungen, sofern solche erlaubt werden.

Die importierten Quellinhalte behalten ihre Quell-ID und Revision. Reimporte aktualisieren Inhalte kontrolliert und duplizieren sie nicht.

### Im Umfang

- Übernahme des Content-Bestands als versionierter Snapshot in dieses Repository, Konvertierung in ein Content-Paket und versionierter Import in WordPress;
- alle 14 Kursdefinitionen der Quelle (SBF See, SBF Binnen, SBF Binnen unter Segel, Kombi- und Ergänzungskurse, SRC, UBI, SRC & UBI, SKN, FKN, Ergänzung FKN → SKN), sofern in Kap. 13 nicht anders entschieden;
- Fragenkatalog, Erklärungen, Module und Scheine;
- Lernmodus, Merkliste, Lernfortschritt, Smarttrainer;
- zufällige Prüfungssimulation und feste Prüfungsbögen;
- Navigationsaufgaben und Praxistrainer;
- Video-Kurse, Schrittgalerien und verknüpfte Videoquizfragen;
- SRC-/UBI-Funktexte, Vokabeln, Praxis-Funkszenarien und Gerätesimulation;
- WooCommerce-Produkte und robuste Zugangsfreischaltung;
- Adminbereich für Content-Import, Rechtekontrolle, Produktzuordnung und Support.

### Nicht im Umfang der ersten Version

- Import von Laravel-Nutzerkonten, Tenant-Daten, Versuchen, Fortschrittsdaten, Coupons oder persönlichen Einstellungen;
- native iOS-/Android-Apps und Offlinebetrieb;
- WooCommerce-Abonnements, sofern das Geschäftsmodell nicht ausdrücklich geändert wird;
- Übernahme der Laravel-Administration oder mandantenspezifischen Funktionen (Branding, Einladungen, Standorte, Superadmin);
- Bootsschul-Betriebsfunktionen der Quelle, die kein Lerncontent sind: Prüfungsbuchung (`pruefung_buchen.php`), Prüfungsausschüsse (`pruefungsausschuesse.csv`), Praxis-Anmeldeformulare und -termine, Willkommensbox, Promo-Banner, Addon-Vorlagen – Übernahme nur nach ausdrücklicher Entscheidung (Kap. 13);
- automatische Behauptung, ein Inhalt sei amtlich, aktuell oder frei nutzbar, wenn Quelle, Stand und Rechte nicht belegt sind.

## 2. Bekannte Content-Quelle und Konsequenzen

Das Repository enthält eine Laravel-Anwendung mit PostgreSQL-Datenmodell. Der Content liegt **an drei Stellen**: in Dateien unter `database/data/`, direkt im PHP-Code der Seeder unter `database/seeders/` (u. a. Videokurse, Lektionen, Schrittgalerien, Videoquiz-Zuordnungen, Regelwerke) und in Generatorlogik unter `app/Support/` (`FunkPraxis`, `FunkTraining`, `Geraetesimulation`, `NavigationAnswer`, `ComboExamGroups`, `ComboVideoGroups`, `VideoCourseLessons`). Ein Snapshot nur von `database/data/` reicht daher nicht.

### 2.1 Geprüfter Bestand (Commit `136a243`)

| Bereich | Quelle | Befund |
|---|---|---|
| Zentrale Fragenbasis | `database/data/zentrale_fragenbasis.xlsx`, Blatt „Central Question Pool“ | 838 Datenzeilen, `content_id` eindeutig; 823 mit A–D-Antworten; 15 Zeilen ohne Antworten, alle SBF See (Kartenaufgaben wie „Wie lautet der rwK?“, ab Nr. 286) |
| Verteilung nach Modul | ebd. | SBF See 228, SBF Binnen 181, SRC 180, UBI 130, Basis 72, SBF Binnen Segeln 47 |
| Bilder an Fragen | ebd., `image_required` | 135 Fragen benötigen ein Bild; 153 ELWIS-Bilder in `elwis_bilder/`, Zuordnung `elwis_bilder.csv` |
| Quellenangabe | ebd., `source_catalog`/`source_version` | nur bei 311 Zeilen gefüllt (SRC 180, UBI 130, 1 × ELWIS See); **527 Zeilen ohne Katalog-/Versionsangabe** |
| Weitere Kataloge | `skn_catalog.php`, `fkn_catalog.php`, `ubi_ergaenzung_katalog.php` | SKN 89 Fragen; FKN 62 Fragen, deckt laut Dateikommentar **nur das Kapitel Pyrotechnik/Waffenrecht** ab; UBI-Ergänzung 79 Fragen (Teilmenge UBI) |
| Erklärungen/Feedback | `fragen_erklaerungen.csv`, `sbf_see_binnen_fragen_feedback.csv` | richtiges/falsches Feedback je `content_id` |
| Katalogaktualisierung | `CatalogUpdateSeeder` | UBI 10/2026: 65 geänderte Fragen als neue Revision, alte Fassung `deprecated` |
| Module / Kurse | `ModuleSeeder`, `CourseSeeder` | 9 Module (`SBF_BASIS`, `SBF_SEE`, `SBF_BINNEN`, `SBF_BINNEN_SEGELN`, `SRC`, `UBI`, `UBI_ERG`, `SKN`, `FKN`), 14 Kurse (Typen `full`, `combo`, `addon`) |
| Feste Bögen | `*_pruefungsboegen.csv`, `*_uebungsboegen.csv`, `fkn_pruefungsboegen.php` | z. B. SBF See 15 × 30, SRC 12 × 24, UBI 12 × 22, UBI-Ergänzung 12 × 10; Kombikurs SRC & UBI nummeriert 1–12, 101–112, 201–212 |
| Regelwerke | `ExamRuleSetSeeder` | nur ein Teil ist verifiziert; Kombikurs SRC & UBI bleibt `draft` (unterschiedliche Bestehensgrenzen 19/24, 17/22, 8/10) |
| Navigation | `sbf_see_navigationsaufgaben.csv` | 15 Aufgaben × 9 Teilaufgaben = 135 Zeilen |
| Praxistrainer | `sbf_see_`, `sbf_binnen_`, `sbf_binnen_segeln_`, `skn_`, `fkn_praxistrainer.csv`, `praxistrainer_elwis_bilder.csv` | 68 PNG + 68 SVG in `praxistrainer_bilder*/` |
| Videokurse | `VideoCourseSeeder` u. a. (Content im Seeder-Code) | alle Lektionen verweisen auf das Platzhaltervideo `w3schools.com/html/mov_bbb.mp4`; Schrittgalerien teils nur benannte Platzhalter |
| Funk | `funk_praxis_aufgaben.php`, `funk_seefunktexte.php`, `funk_ubi_texte.php`, `funk_vokabeln.php`, `funk_video_quiz.php` | Praxisaufgaben kompakt als Parameter; Texte/Musterlösung/Prüfpunkte erzeugt `App\Support\FunkPraxis`; Fortschrittsschlüssel sind **Listenpositionen** (`SRC-01` …) |
| Gerätesimulation | `geraetesimulation.php` | Geräte IC-M323, IC-M330, IC-M423, IC-M503, IC-M505, IC-M94DE |
| Status-Enums | Migration `…000010` | `content_status`: `draft`, `review`, `approved`, `published`, `deprecated`; `rights_status`: `unknown`, `review`, `licensed`, `public`, `restricted` (Default `unknown`) |
| Rechtehinweise | Blatt „Source Catalogs“ | SRC- und UBI-PDF weisen auf **Genehmigungspflicht für Abdruck/Vervielfältigung/Veröffentlichung** hin |

Zahlen sind nur Prüfstand dieses Commits. Beim Konvertieren werden sie dynamisch ermittelt und im Manifest ausgewiesen, nicht im WordPress-Plugin hardcodiert. Freie Kartenaufgaben ohne A–D-Antworten werden als eigener Fragetyp (`chart_task`/`open_text`) übernommen und nicht verworfen.

### 2.2 Konsequenzen

- **Rechte sind der größte Go-live-Blocker.** Mit `rights_status` = `unknown` als Default und 527 Fragen ohne Quellenangabe wäre nach Kap. 4.10 der Großteil des Contents gesperrt. Ein Rechte-Audit (Kap. 11, Schritt 1) ist Voraussetzung, nicht Nacharbeit.
- **FKN ist unvollständig.** Ein verkaufbarer FKN-Kurs braucht zusätzlich die Funkverkehr-Fragen.
- **Videos sind Platzhalter** und sperren die Veröffentlichung, bis lizenzierte Videos vorliegen.
- **Generatorlogik muss portiert werden.** Da die Quelle nicht geändert wird, bildet der Konverter in diesem Repository die Logik aus `app/Support/` nach. Er liefert fertig gerenderte Szenarien, Musterlösungen und Prüfpunkte, sodass das Plugin zur Laufzeit keine Rohparameter interpretiert. Die Portierung wird per Golden-Master-Test gegen die Laravel-Ausgabe abgesichert (lokal erzeugt aus dem gepinnten Quell-Commit, ohne Änderung am Quellrepository).
- **Positionsbasierte IDs** (`SRC-01` …) werden beim ersten Import als stabile Quell-ID eingefroren; spätere Umsortierungen in der Quelle dürfen sie nicht verschieben (Konverter prüft gegen das vorige Manifest).
- Fragen werden in festen Bögen über `official_number` + Fragenfamilie referenziert; der Konverter löst das in eindeutige `content_id` auf und meldet nicht auflösbare Verweise als Fehler.

### 2.3 Quellreferenzen

- [Repository und README](https://github.com/hastla007/boatingschool)
- [Fragen, Revisionen, Antworten und Frage-Medien](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000020_create_content_tables.php)
- [Multi-Choice/Offene Fragen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000340_add_multi_choice_and_open_text_columns.php)
- [Kurse und Module](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000030_create_module_and_course_tables.php)
- [Prüfungsregeln und Simulationen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000050_create_exam_tables.php)
- [Feste Prüfungsbögen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000110_create_exam_paper_tables.php)
- [Navigationsaufgaben](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000100_create_navigation_task_tables.php)
- [Praxistrainer](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000140_create_praxis_trainer_tables.php)
- [Videokurse](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000080_create_video_course_tables.php)
- [Video-Schrittgalerien](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000090_create_video_lesson_step_table.php)
- [Videoquiz-Zuordnungen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000330_create_video_lesson_question_table.php)
- [Datendateien `database/data/`](https://github.com/hastla007/boatingschool/tree/main/database/data)
- [Seeder `database/seeders/`](https://github.com/hastla007/boatingschool/tree/main/database/seeders)
- [Generatorlogik `app/Support/`](https://github.com/hastla007/boatingschool/tree/main/app/Support)

## 3. Systemarchitektur

### 3.1 Komponenten

1. **Content-Snapshot (`content-source/boatingschool/`):** unveränderte Kopie der content-relevanten Teile des Quellrepos zu einem gepinnten Commit, Pfade wie im Original: `database/data/` vollständig, `database/migrations/` (Schema-Referenz), `database/seeders/` ohne Demo-/Mandanten-/Nutzer-Seeder, `app/Models/`, die Generatorklassen aus `app/Support/`, die Content-Importkommandos, die Gerätesimulation `resources/js/funkgeraet/` sowie die Kursbilder `public/images/courses/`. `SOURCE.md` dokumentiert Commit-SHA, Datum und übernommene Pfade, `SHA256SUMS` die Prüfsummen aller Dateien. Aktualisierung nur per Skript (`tools/sync-source.sh <commit>`), nie per Handbearbeitung; Mandanten-, Nutzer- und Demodaten-Seeder werden nicht übernommen.
2. **Content-Konverter (`tools/content-build/`):** PHP-CLI in diesem Repository; liest ausschließlich den Snapshot, portiert die nötige Generatorlogik, validiert gegen das JSON-Schema und erzeugt das Content-Paket. Keine Datenbank-, Netzwerk- oder Laravel-Laufzeitabhängigkeit.
3. **Content Package:** versioniertes ZIP mit `manifest.json`, strukturierten JSON-Dateien pro Inhaltstyp und Medien im relativen Pfad. UTF-8, explizite Datumsformate und keine PHP-Serialisierung.
4. **WordPress Importer:** Dry-Run, Validierung, Mapping, Idempotenz, Transaktions-/Batchverarbeitung, Importprotokoll und Freigabe.
5. **Lernplugin:** PHP-Domain-Layer, Tabellen, REST-API, Adminoberfläche und nutzerseitige Lernoberfläche.
6. **WooCommerce Adapter:** Produktzuordnung und Ereignisverarbeitung; nutzt WooCommerce-Datenobjekte und APIs, keine direkten Bestell-SQL-Abfragen.

### 3.2 Paketformat

`manifest.json` enthält mindestens:

- `package_id`, `schema_version`, `converter_version`;
- `source_repository`, `source_branch`, `source_commit_sha` (aus `SOURCE.md` des Snapshots), `built_at`;
- `previous_package_id` zum Abgleich stabiler IDs;
- deklarierte Content-Kataloge, Sprachen und Versionsstände;
- Anzahl Datensätze je Entität;
- Datei- und Assetmanifest mit Größe, MIME-Type und SHA-256;
- Warnungen, ausgelassene Datensätze und Gründe;
- Rights-/Publish-Status je Entität oder Revision.

Entitäten werden als getrennte Dateien unter `data/` geliefert. Umgesetzt (Format v1, Schemata und Regeln in [`schema/`](schema/README.md)): `courses.json`, `modules.json` (inkl. Fragenzuordnung), `questions.json` (Frage mit aktuellem Stand, Antworten und Medienbezug – Revisionen entstehen erst beim Import), `media.json`, `exam_rules.json` (inkl. Blueprints), `exam_papers.json`, `navigation_tasks.json`, `praxis_tasks.json`. Folgen: `video_*.json`, `radio_*.json`, `device_*.json`.

### 3.3 Importregeln

- Quelle ist autoritativ für importierte Felder; WordPress speichert lokale Overrides getrennt.
- Jeder Datensatz erhält `source_system`, `source_id`, `source_revision_id`, `source_hash` und `imported_at`.
- Wiederholter Import derselben Paket-ID/Hashes erzeugt keine Duplikate.
- Gleiche Quell-ID plus neuer Hash erzeugt eine neue lokale Revision bzw. ein kontrolliertes Update.
- Entfernte Quellen werden zunächst als `source_archived` markiert, nicht hart gelöscht. Historische Lernversuche bleiben erhalten.
- Unvollständige oder rechteunklare Inhalte werden importiert, aber nicht öffentlich ausgeliefert.
- Fehlerhafte Einzelentitäten werden in einer Vorschau ausgewiesen; ein Paket darf nur nach explizitem Admin-Freigabeschritt veröffentlicht werden.
- Import ist gebündelt und resumierbar; große Medien dürfen nicht den PHP-Request-Timeout überschreiten.

## 4. Vollständige Content-Feldliste

### 4.1 Gemeinsame Content-Metadaten

Jede importierte Entität braucht:

- stabile Quell-ID und Entitätstyp;
- Sprache/Locale;
- Katalog-/Content-Version;
- Status `draft`, `review`, `approved`, `published`, `deprecated` (wie Quelle) plus lokal `source_archived` für in der Quelle entfernte Datensätze;
- Rechte-Status `unknown`, `review`, `licensed`, `public`, `restricted` (wie Quelle);
- `valid_from`, `valid_to` soweit fachlich anwendbar;
- Quelle, Herausgeber, Quellenreferenz und Quellseite;
- Rechteinhaber, Lizenzreferenz, Rechte-Status;
- `created_at`, `updated_at` der Quelle, soweit vorhanden;
- Quellhash, Paket-ID und Importdatum.

Quell-IDs von Laravel-Nutzern (`reviewed_by`, `verified_by`) werden nicht als WordPress-Benutzer-ID interpretiert. Sie bleiben entweder als Herkunftstext erhalten oder werden bei redaktioneller Freigabe in WordPress neu zugeordnet.

### 4.2 Kurse, Scheine, Module

**Kurs/Schein:** `source_id`, Code, Name, Kurz-/Langbeschreibung, Kursart, Sprache, Status, Sortierung, enthaltene Module, Kursoptionen und Sichtbarkeit.

**Modul:** `source_id`, Code, Name, Beschreibung, Version, Status, Sortierung, Content-Fragen, Navigation/Praxis-/Video-/Funk-Module, Pflichtstatus und modulbezogene Auswahlregeln.

**Kurs-Modul-Zuordnung:** Kurs-ID, Modul-ID, Reihenfolge, Pflichtkennzeichen und Regel-JSON. Die Regelstruktur wird validiert und versioniert.

### 4.3 Fragen und Antworten

**Stabile Frage:** `source_id`/`content_id`, amtliche Nummer, `content_role`/Fragenfamilie, Sprache, aktiv/inaktiv.

**Frage-Revision:** Revisionsnummer, Fragetyp (`single_choice`, `multi_choice`, `open_text` und weitere explizit unterstützte Typen), Fragetext, Thema, Unterthema, Kompetenz, Smarttrainer-Kategorie, `image_required`, Feedback/Erklärung richtig, Feedback/Erklärung falsch, Quelle/Katalog, Quellversion, Quellseite, externe Fragen-ID, gültig ab/bis, Editorial-Status und Rights-Status.

**Antwort:** Quell-ID, Frage-Revision, Antwortschlüssel, Antworttext, `is_correct`, Sortierung. Mehrfachauswahl darf mehrere richtige Antworten enthalten. Offene Fragen brauchen typisierte Antwort-/Selbsteinschätzungsregeln und dürfen nicht stillschweigend als Multiple Choice behandelt werden.

### 4.4 Medien und Fragebilder

Je Asset: `source_id`, relativer Dateipfad oder URL, Dateiname, MIME-Type, Dateigröße, SHA-256, Medienrolle, Caption, Alt-Text, Sortierung, Quelle, Rechteinhaber, Lizenz und Rights-Status. Zuordnungstabelle verbindet Asset mit Revision/Aufgabe/Lektion und Rolle.

Für den Start werden Originalformate aus der Quelle erhalten; WordPress erzeugt nur Display-Varianten. Medienlinks dürfen nicht auf nicht erreichbare Laravel-Storage-Pfade zeigen.

### 4.5 Prüfungsregeln und Bögen

**Regelwerk:** Kurs, Version, Zeitraum, Zeitlimit, Bestehensregel als strukturierte JSON-Schema-Daten, Modul-/Fragenzahl, Auswahlregeln, Status und Verifizierungsmetadaten.

**Blueprint:** Regelwerk, Modul, Fragenzahl und Regeln für Fragenfamilien/Kompetenzen.

**Fester Bogen:** Kurs, Bogennummer, Sortierung und geordnete Frage-ID-Liste. Die Frage-ID muss eindeutig sein; Nummer plus Familie darf nur als Fallback dienen.

**Abnahmevoraussetzung für Live-Prüfungsmodus:** Regelwerk ist veröffentlicht, innerhalb seiner Gültigkeit und als fachlich verifiziert markiert. Nicht verifizierte Regelwerke sind nur als Übungsmodus sichtbar.

### 4.6 Navigationsaufgaben

**Aufgabe:** Quell-ID, Kurs, Nummer, Sortierung, Szenariotext, Hinweis, Chart-/Kartenausschnitt, Caption, Quelle, Rights-Status.

**Teilfrage:** Quell-ID, Aufgabe, Nummer, Sortierung, Frage, Musterlösung, Hinweise zur Selbsteinschätzung und Bewertungsart.

Navigationsaufgaben werden als Lerntrainer mit sichtbarer Musterlösung nach Nutzeraktion angeboten; sie werden nicht automatisch mit dem zeitlimitierten Fragen-Prüfungsmodus vermischt.

### 4.7 Praxistrainer

Je Aufgabe: Quell-ID, Kurs, Kategorie, Unterkategorie, Prüfungsbezug, Aufgabentyp, Frage/Handlungsauftrag, Musterantwort, Erklärung, Medienreferenz, Quelle/URL, Sortierung und aktiv-Status.

Kategorien sind navigierbar und bilden die Grundlage für Kategorie-Fortschritt. Manche Aufgaben sind Bild-Erkennung, andere Text/Antwort. Der Fragetyp muss daher im Export ausgewiesen werden.

### 4.8 Videokurs, Lektionen, Schrittgalerien, Quiz

**Videomodul:** Quell-ID, Kurs, Titel, Sortierung.

**Lektion:** Quell-ID, Modul, Titel, Video-URL/Asset, Provider, Dauer, Sortierung, Caption/Transkript, Kapitelmarken und Status. Platzhalter-URLs sperren Veröffentlichung.

**Schritt:** Quell-ID, Lektion, Titel, Bildasset, Caption/Alt-Text, Sortierung.

**Videoquiz-Zuordnung:** Lektion, Frage-Quell-ID, Reihenfolge. Quizfragen referenzieren die zentrale Fragenbank und werden nicht dupliziert.

**Nutzerfortschritt:** nicht aus Quelle importieren; lokal pro Nutzer/Lektion speichern: abgeschlossen, letzte Position, letzter Zugriff.

### 4.9 Funkmaterial

**Funk-Praxis-Szenario (SRC/UBI):** Quell-ID, Katalog, laufende Nummer, Meldungsart, Szenario- und Situationsdaten, eigenes Schiff, Rufzeichen, MMSI, Schiffstyp, Position, Zeit, Gegenstellen, Kanal, Gefahr/Ereignis, Beteiligte/Personenzahl, gerenderte Aufgabenbeschreibung, Musterfunkspruch, Prüfpunkte, Musterlösung, zulässige Varianten, kritische Fehler, Bewertungsregel, Sprache, Rechte-/Quellenstatus.

Die Quelle speichert Fälle kompakt als Parameterobjekte; Texte, Musterlösung und Prüfpunkte erzeugt dort `App\Support\FunkPraxis`. Der Konverter portiert diese Logik und schreibt vollständigen Szenariotext, Musterfunkspruch und Bewertungsprüfpunkte ins Paket. Das WP-Plugin erzeugt keine Szenarien aus Rohparametern. Die Quell-IDs sind dort Listenpositionen (`SRC-01` …) und werden beim ersten Import eingefroren.

**SRC-Seefunktext:** stabile ID, Richtung (Land→See/See→Land), Meldungsart, deutscher Text, englischer Text, segmentierte Textteile, Reihenfolge, Quelle/Rechte.

**UBI-Text:** stabile ID, Übungsform (Aufnahme/Abgabe), Meldungsteile, Schreibtext, markierte Buchstabierwörter, erwartete Schreibweise, Reihenfolge, Quelle/Rechte.

**Vokabel:** feste Quell-ID, Katalog, Kategorie, Deutsch, Englisch, Aussprache-/Audioasset wenn vorhanden, zulässige Varianten und Sortierung. IDs nicht aus Textpaaren generieren, da Textkorrekturen sonst Fortschrittsschlüssel ändern.

**Gerätesimulation:** Geräte-ID/Modell (laut Quelle IC-M323, IC-M330, IC-M423, IC-M503/DS-100, IC-M505, IC-M94DE), Funkkatalog SRC/UBI, Lektionen, Stufe, unterstützte Geräte, Startzustand, Schrittfolge, Zielereignis (`goal`), Bedienteil (`target`), Hilfetext, gerätespezifische Abweichung, Empfangsereignis, Gegensprechtext, Prüfchecks (Label, Regex/Regel, Mindestanzahl), Musterlösung und Szenarioverweis.

Wenn eine Browserstimme statt lizenzierter Sprecheraufnahme verwendet wird, ist das als Ausgabemodus konfigurierbar. Audio-/Stimmdateien werden als Medien mit Rechten geführt.

### 4.10 Rechte und Veröffentlichung

- `rights_status=unknown` oder `restricted` blockiert öffentliche Auslieferung.
- Rechteinformationen sind je Inhalt/Revision/Asset vererbbar, müssen aber bei Asset-Abweichung überschreibbar sein.
- Für eigene Übungstexte und amtliche Originaltexte werden getrennte Quellen-/Lizenzangaben geführt.
- Importprotokoll vermerkt, wer einen gesperrten Inhalt freigegeben hat, wann und auf welcher Rechtsgrundlage.
- Rechte werden beim Konvertieren aus dem Snapshot abgeleitet (z. B. Katalogblatt „Source Catalogs“, ELWIS-Quell-URLs in `elwis_bilder.csv`); fehlt eine Angabe, bleibt `unknown`. Eine pauschale Freigabe ganzer Kataloge ist im Admin möglich, wird aber mit Rechtsgrundlage protokolliert.

## 5. WordPress-Datenmodell

Fragen/Versuche sind relationale Daten, keine tausenden Post-Meta-Felder. WordPress-CPTs können für redaktionell sichtbare Landingpages/Kursseiten dienen; das normalisierte Lernmodell liegt in eigenen Tabellen.

Präfix `wp_bp_` ist beispielhaft und muss das echte `$wpdb->prefix` berücksichtigen.

### 5.1 Content-Tabellen

- `bp_course`: Quell-ID, Code, Name, Beschreibung, Typ, Status, Sprache, Sortierung, Quellhash.
- `bp_module`: Quell-ID, Code, Name, Beschreibung, Version, Status, Sortierung.
- `bp_course_module`: Kurs-ID, Modul-ID, Sortierung, Pflichtstatus, Regeln.
- `bp_question`: Quell-ID, offizielle Nummer, Familienrolle, Sprache, Aktivstatus.
- `bp_question_revision`: Frage-ID, Quell-Revisions-ID, Revisionsnummer, Fragetyp, Text, Taxonomie-Felder, Erklärungen, Quell-/Rights-Daten, Gültigkeit, Status, Hash.
- `bp_answer`: Revision-ID, Quell-ID, Schlüssel, Text, korrekt, Sortierung.
- `bp_media`: Quell-ID, WordPress Attachment-ID oder verwalteter Storage-Key, MIME, Hash, Rechte-Metadaten.
- `bp_content_media`: Content-Revision-ID, Media-ID, Rolle, Sortierung, Caption/Alttext-Override.
- `bp_exam_rule`, `bp_exam_blueprint`, `bp_exam_paper`, `bp_exam_paper_question`.
- `bp_module_question`: Modul-ID, Frage-ID, Sortierung, Pflichtstatus.
- `bp_navigation_task`, `bp_navigation_task_course` (eine Aufgabe in mehreren Kursen), `bp_navigation_question`.
- `bp_praxis_task`.
- `bp_video_module`, `bp_video_lesson`, `bp_video_lesson_step`, `bp_video_lesson_question`.
- `bp_radio_scenario`, `bp_radio_text`, `bp_radio_vocabulary`, `bp_device`, `bp_device_lesson`, `bp_device_step`.
- `bp_import_batch`, `bp_import_item_log`, `bp_audit_log`.
- `bp_entitlement`, `bp_access_rule`, `bp_product_access` (Woo-Produkt/Variation → Kurs/Feature, Dauer, Verlängerungs- und Erstattungsregel).

Umgesetzt in `bootspruefung-learning/src/Infrastructure/Database/Schema.php` (43 Tabellen, 37 Fremdschlüssel).

- `bp_access_rule`: Free/Pro-Konfiguration je Kurs/Modul/Feature (siehe 7.2).

Alle Contenttabellen tragen mindestens Quell-ID, Quellhash und Status. Eindeutige Indizes sichern die Zuordnungen. Mindestanforderung ist **InnoDB** (für Transaktionen und Fremdschlüssel); das Plugin prüft die Engine bei Aktivierung und verweigert sonst den Start mit Hinweis. Da `dbDelta()` keine Fremdschlüssel verwaltet, werden Tabellen per `dbDelta()` angelegt und Fremdschlüssel in separaten, versionierten Migrationsschritten (`ALTER TABLE … ADD CONSTRAINT`) ergänzt. Migrationen laufen bei Aktivierung/Update kontrolliert und einmalig (Schema-Version in `wp_options`, Lock gegen Parallelausführung).

### 5.2 Nutzer-/Lerntabellen

- `bp_attempt`: Nutzer-ID, Frage, konkrete Revision, Auswahl, Ergebnis, Kontext, Antwortzeit, Start-Token, Zeitstempel.
- `bp_attempt_answer`: für Mehrfachauswahl und offene Antworten, geordnete Antworten.
- `bp_progress`: Nutzer, Frage, Anzahl Versuche, korrekt/falsch Zähler, aktuelle Serie, Lernstatus, erste/letzte Antwort.
- `bp_favorite`: Nutzer und Frage.
- `bp_exam_session`: Nutzer, Kurs, Regelwerksversion, Bogen, Status, Start/Abgabe, Ergebnis, bestanden.
- `bp_exam_session_question`: Sitzung, Frage/Revision, Position, Antwort und Ergebnis.
- `bp_navigation_progress`: Nutzer, Teilfrage, Antwort/Selbsteinschätzung, erledigt, Zeitstempel.
- `bp_praxis_progress`: Nutzer, Aufgabe, erledigt/Status, Zeitstempel.
- `bp_video_progress`: Nutzer, Lektion, Abschluss, letzte Abspielposition.
- `bp_radio_progress`: Nutzer, Elementtyp (Praxis/Text/Vokabel/Gerät), stabile Content-ID, erledigt, Serie, Antwort-/Versuchsdaten, erreichte Hilfestufe.

Lernversuche sind unveränderlich. Korrekturen daran erfolgen als neue administrative Korrekturereignisse, nicht durch stilles Umschreiben der Antworthistorie. **Ausnahme:** Eine Löschung auf Anfrage der betroffenen Person (DSGVO Art. 17, WordPress-Eraser) entfernt Versuche und Fortschritt dieses Nutzers vollständig; im Audit-Log bleibt nur ein pseudonymisierter Löschvermerk. Für Entitlements gelten handels-/steuerrechtliche Aufbewahrungsfristen der zugehörigen Bestellung.

## 6. Lernfunktionen und Verhalten

### 6.1 Lernmodus

- Nutzer wählt Schein und Modul oder „alle Themen“.
- Server liefert Frage und Antwortoptionen ohne Lösungsschlüssel.
- Nutzerantwort wird serverseitig validiert und bewertet.
- Nach Einreichung erhält der Browser Ergebnis, Erklärung und optional Medien.
- Antwortversuch wird transaktional gespeichert; Fortschritt wird atomar aktualisiert.
- Doppel-Submit/Doppelklick wird durch Idempotency-Token neutralisiert.
- Schwierige/falsche/neue Fragen können gefiltert und erneut gelernt werden.

### 6.2 Smarttrainer

- Priorisierung nach Zustand aus Quell-/Lernlogik: neu, falsch, Thema schwach, gefestigt.
- Auswahlregeln deterministisch dokumentieren; keine unkontrollierte Randomisierung.
- Antwortreihenfolge für Nutzer variieren, aber pro Seitenaufruf stabil halten.
- Kategorien und Fortschritt aus Kurs/Modulrelationen berechnen.

### 6.3 Prüfungssimulation

- Nur veröffentlichtes, aktuell gültiges und verifiziertes Regelwerk verwenden.
- Fragen nach Blueprint zusammenstellen oder festen Bogen laden.
- Start speichert Snapshot der Regelwerksversion, Frage-IDs, Revisionen, Reihenfolge und Startzeit.
- Während des Prüfungsmodus keine Lösungen/Erklärungen zurückgeben.
- Serverseitige Zeitprüfung und Bewertung bei Abgabe.
- Abgelaufene Sitzungen abschließen oder als abgebrochen markieren; keine unbegrenzt offenen Sessions.
- Ergebnis zeigt erreichte Punkte, Bestehen, Bereiche und anschließende Lernlinks.

### 6.4 Spezifische Trainer

- Navigation: Szenario → Teilfrage → eigene Antwort → Musterlösung/Selbsteinschätzung.
- Praxis: Karte nach Kategorie → Aufgabe/Bild → Musterantwort → erledigt/noch üben.
- Video: Lektionen nach Modul, Position speichern, Videoquiz mit zentralen Frage-IDs.
- Funktext: Aufnahme/Abgabe, Text, Übersetzung oder Buchstabierwörter passend zur Übungsart.
- Funkpraxis: gerendertes Szenario, Nutzer schreibt den Funkspruch oder spricht ihn über die Browser-Spracherkennung (Web Speech API, optional, mit Hinweis zur Datenverarbeitung durch den Browseranbieter) – an den Server geht nur Text. Server prüft Pflichtpunkte und zeigt Musterlösung. Serverseitige Audioverarbeitung ist nicht Teil von V1.
- Vokabeln: Deutsch→Englisch, erlaubte Varianten, „gewusst/nicht gewusst“, Lernserie.
- Geräte: Zustandsmaschine mit Geräteeingaben, Hinweisen, Zwischenzielen und Stufenbewertung. Die Zustandsmaschine läuft im Client; an den Server geht die Eingabefolge, die er bei Abschluss gegen die Schrittfolge nachprüft. Kein serverseitiger Live-Zustand pro Tastendruck.

Funkpraxis mit Sprache und Gerätesimulation sind jeweils eigene Teilprojekte und werden in Kap. 11 als separate Meilensteine geplant.

## 7. WooCommerce – vollständige Spezifikation

### 7.1 Produktmodell

WooCommerce ist für Preis, Steuer, Warenkorb, Checkout, Bestellung, Zahlung und Rückerstattung zuständig. Das Lernplugin verwaltet die daraus abgeleiteten Kursrechte.

- Produkte sind standardmäßig **virtuell**, nicht herunterladbar, ohne Versand.
- Ein Produkt kann einen oder mehrere Kurse/Funktionspakete freischalten.
- Produktbearbeitung erhält ein Plugin-Feld „Lernzugang“ mit Kurscode(n), Features, Dauer, Produkt-Status und Freischaltungsregel.
- Produktidentität wird über WooCommerce-Produkt-/Variations-ID gespeichert; SKU ist eine zusätzliche lesbare Kennung, nicht der alleinige Primärschlüssel.
- Content und Produktpreise bleiben getrennt. Content-Paket importiert nie Preise oder Zahlungsstatus.

Konfigurierbare Feature-Codes, z. B.:

- `question_bank` – Fragen lernen;
- `learning_explanations` – Erklärungen/Pro-Hinweise;
- `exam_simulation` – Prüfungssimulation;
- `video_course` – Videokurs;
- `navigation_trainer` – Navigationsaufgaben;
- `praxis_trainer` – Praxistrainer;
- `radio_training` – SRC/UBI Funktraining;
- `device_simulation` – Funkgerätesimulation;
- `downloads` – freigegebene PDF-Dateien. *Umsetzung: Datei in der Mediathek mit „Lernzugang erforderlich: Kurs · Downloads (PDF)“ markieren; sie erscheint in der Kursseite unter „Downloads“, wird in einen gesperrten Ordner verschoben und nur über `/bootsscheine/datei/<id>/` nach Prüfung ausgeliefert; Teilumfang: erste n Dateien.*

Die Feature- und Kurszuordnung wird pro Woo-Produkt editierbar gemacht. Es gibt keine Preis- oder Produktcode-Annahmen im PHP-Code.

### 7.2 Grundmodell für Free/Pro

Plugin-Einstellungen definieren, welche Funktion frei und welche bezahlt ist. Empfohlener Ausgangspunkt:

- kostenlos: Einstieg, Beispiel-/Basis-Lernfunktion und ggf. definierte Frage-/Prüfungsumfänge;
- bezahlt: explizit gewählte Pro-Features pro Schein;
- Produktpakete können mehrere Scheine und Features enthalten.

Welche amtlichen Fragen kostenlos oder kostenpflichtig sind, ist eine Produktentscheidung und muss pro Kurs/Katalog konfigurierbar sein. Zugriffsrechte werden nicht aus dem Frontend-Preistext abgeleitet.

Datenstruktur `bp_access_rule`: Kurscode, optional Modulcode, Feature-Code, Zugriffsart (`free`, `paid`, `free_limited`), Limit-Typ und -Wert (z. B. Anzahl Fragen je Modul, Anzahl Prüfungsbögen, erste n Lektionen), gültig ab/bis. Die Zugriffsprüfung ist eine zentrale Funktion `can_access(user, course, feature, item)`, die Free-Regeln und Entitlements gemeinsam auswertet.

*Umsetzung (`AccessService`):* voll (Zugang, Free-Regel ohne Limit oder „Zugang ohne Shop“), Teilumfang (`free_limited`) oder kein Zugriff. Limit-Typen: Fragen je Modul (`questions_per_module`), Prüfungsbögen (`exam_papers`, erste n feste Bögen, keine Zufallsprüfung) und erste n Einträge (`first_items`: Navigationsaufgaben, Praxiskarten, Videolektionen, Funktexte/-szenarien je Übungsart). Die Gerätesimulation hat keinen Teilumfang. Gesperrte Einträge werden ohne Inhalt gelistet; die REST-Endpunkte weisen sie ab. Zeitgesteuerte Freigabe (`drip`, Modulcode, Tage) gilt für Fragenmodule und Videokapitel; Beginn ist der früheste aktive Zugang, ohne Kauf die Registrierung.

### 7.3 Zugangstabelle und Idempotenz

`bp_entitlement` speichert:

- WordPress-Benutzer-ID;
- Kurscode/Content-Scope;
- Feature-Code oder Featuregruppe;
- Status: `pending`, `active`, `expired`, `suspended`, `revoked`;
- `valid_from`, `valid_until` (leer bei dauerhaft);
- Quelle `woocommerce`, `manual`, `coupon/import` falls später erweitert;
- WooCommerce-Order-ID und Order-Item-ID;
- Produkt-/Variations-ID und SKU-Snapshot;
- Freischaltungs- und Änderungszeitpunkt;
- Grund/Referenz bei Widerruf;
- Idempotency-Key und Audit-Metadaten.

Eindeutiger Schlüssel ist `order_item_id + course_scope + feature_code`. Er verhindert Doppelzugänge, auch wenn Zahlungsevents wiederholt ankommen, und lässt Bundles zu, die dasselbe Feature für mehrere Scheine enthalten. Für manuelle Zugänge ersetzt eine eigene Referenz-ID die Order-Item-ID.

Ein Nutzer kann für denselben Kurs/Feature mehrere Entitlement-Zeilen aus verschiedenen Bestellungen besitzen. Zugang besteht, wenn **mindestens eine** Zeile aktiv und gültig ist. Das Enddatum zeitlich begrenzter Zugänge ist das späteste `valid_until` aller aktiven Zeilen (Verlängerungsregel siehe 7.6).

### 7.4 Kaufablauf

1. Käufer muss angemeldet sein oder während Checkout ein WordPress-Konto anlegen.
2. Produkt/Variante wird dem Warenkorb hinzugefügt.
3. Checkout/Payment erfolgt ausschließlich über WooCommerce und dessen Payment Gateway.
4. Plugin reagiert auf bestätigte Zahlung bzw. WooCommerce-Order-API `is_paid()`; es wartet nicht allein auf eine Thank-you-Page.
5. Für jede Order-Item-Produktzuordnung wird im Plugin ein aktiver Entitlement-Datensatz erzeugt.
6. „Mein Lernen“ zeigt freigeschaltete Kurse und Features; Bestellbestätigungsseite und E-Mail können einen direkten Kurslink ergänzen.
7. Zugang ist sofort serverseitig prüfbar. Der Kunde erhält keinen direkten Datenbank-/Medienpfad als Rechteersatz.

WooCommerce Payment-Integrationen sollen Zahlungsabschluss über die WooCommerce-APIs behandeln. Für virtuell-digitale Bestellungen kann WooCommerce nach Zahlung direkt auf `Completed` wechseln; der Adapter muss deshalb paid state und Order-APIs korrekt behandeln. citeturn8675590search0turn8675590search5

### 7.5 Status- und Erstattungsmatrix

| WooCommerce-Ereignis/Status | Lernzugang |
|---|---|
| Warenkorb/Checkout noch nicht bezahlt | kein Zugang |
| `pending`, `failed`, `cancelled` vor Zahlung | kein Zugang |
| `on-hold` / manuelle Zahlungsprüfung | kein Zugang, außer Admin genehmigt ausdrücklich |
| Zahlung bestätigt (`is_paid`) | Zugang aktivieren |
| bezahlte Bestellung wechselt `processing` ↔ `completed` | keine doppelte Freischaltung |
| vollständige Rückerstattung einer Lern-Order-Item-Position | nur von dieser Position stammende Rechte widerrufen |
| Teilrückerstattung ohne Rückerstattung des Lernprodukts | Zugang bleibt aktiv |
| teilweise Rückerstattung des digitalen Produkts | per Pluginregel konfigurierbar; Standard: Position widerrufen, wenn Produkt vollständig erstattet ist |
| bezahlte Order storniert | zugehörige Rechte widerrufen und protokollieren |
| Woo-Produkt deaktiviert/gelöscht | bestehende bezahlte Rechte bleiben bestehen; neue Käufe gesperrt |
| Chargeback/Payment-Reversal | Zugang suspendieren; nach erfolgreicher Klärung reaktivieren (siehe Hinweis unten) |

WooCommerce kennt keinen einheitlichen Status oder Hook für Chargebacks; die Meldung ist gateway-spezifisch (z. B. setzt WooCommerce Stripe bei einem Dispute die Bestellung auf `on-hold` und schreibt eine Order-Notiz). V1 behandelt deshalb den Wechsel einer bereits bezahlten Bestellung (Zahldatum gesetzt) nach `on-hold` als Suspendierung. Für alle anderen Fälle stehen eine manuelle Suspendierung (Bestellaktion bzw. Adminseite „Zugänge“) und die Hooks `bootspruefung_suspend_order`/`bootspruefung_reactivate_order` bereit.

**Festgelegte Zahlungsarten (Kap. 13 Nr. 9): Überweisung, Klarna, PayPal, Stripe – weitere können hinzukommen.** Der Adapter ist deshalb gateway-neutral: maßgeblich sind ausschließlich `is_paid()` (Status aus `wc_get_is_paid_statuses()`), das Zahldatum, Bestellstatus und Erstattungen der Bestellung. Ein neues Gateway braucht keinen Code; für eigene Bestellstatus gibt es den Filter `bootspruefung_order_access_target`, für die Anzeige im Systemstatus `bootspruefung_gateway_profiles`.

| Zahlungsart | Freischaltung | Rückbuchung/Konflikt |
|---|---|---|
| Überweisung (BACS) | Bestellung `on-hold` → Zugang „wartet auf Zahlung“; Statuswechsel auf `processing`/`completed` nach Zahlungseingang aktiviert. Optional Freigabe vorab per Bestellaktion (mit Begründung). | keine Gateway-Rückbuchung; Erstattung/Storno in WooCommerce widerruft |
| Stripe | bei bestätigter Zahlung | Dispute → `on-hold` → automatisch ausgesetzt; Dispute gewonnen (zurück auf bezahlt) → reaktiviert |
| PayPal | bei bestätigter Zahlung | Erstattungen als Woo-Erstattung → Widerruf; Disputes automatisch über den PayPal-Webhook des Plugins (Signatur per PayPal-API geprüft, Bestellung über die Transaktions-ID): eröffnet → ausgesetzt, Händler gewinnt → aktiv, Käufer gewinnt → widerrufen |
| Klarna | sobald Klarna bestätigt und die Bestellung als bezahlt gilt; Bestellungen in Prüfung bleiben „wartet auf Zahlung“ | Storno/Erstattung widerruft; Konflikte über den allgemeinen, HMAC-signierten Dispute-Webhook (z. B. Weiterleitung der Klarna-Benachrichtigung per Automatisierung) oder manuell |
| weitere | wie oben über `is_paid()` | `on-hold` nach Zahlung = Aussetzung, sonst manuell |

Weitere Regeln der Umsetzung: Ein Widerruf nach erfolgter Freischaltung (Erstattung, Storno) ist endgültig; ein nie aktiver Zugang (fehlgeschlagene Zahlung, später doch bezahlt) lebt wieder auf. Manuelle Entscheidungen (Aussetzen, Vorab-Freigabe) bleiben bei weiteren Statuswechseln bestehen, bis sie aufgehoben werden – nur Storno/Erstattung widerrufen trotzdem. Die Erstattungsregel wird beim Anlegen im Zugang gespeichert und gilt auch, wenn die Produktzuordnung später entfernt wird. Bestellungen ohne Kundenkonto erzeugen keinen Zugang (Audit-Eintrag, Zuordnung im Admin möglich); der Checkout verlangt deshalb für Lernprodukte ein Konto (klassisch und Checkout-Block).

Die Regeln gelten pro Bestellposition. Bei Paketen wird pro enthaltenem Kurs/Feature ein eigener Zugang angelegt, damit Rückerstattung eines einzelnen Produkts nicht andere bezahlte Zugänge löscht.

### 7.6 Produkte, Bundles, Coupons, Käufe und Support

- **Bundles:** Produktmapping enthält mehrere Kurs-/Feature-Zeilen.
- **Variationen:** jede Variation kann eigene Zugänge überschreiben; sonst erbt sie Produktmapping.
- **Woo-Coupons:** WooCommerce berechnet Rabatt; Coupon gewährt keinen Lernzugang ohne bezahlte Order.
- **Mengen > 1:** Standard auf Lernprodukte beschränken; falls zulässig, keine mehrfachen Rechte desselben Nutzers erzeugen.
- **Doppelkäufe:** jede Bestellposition erzeugt eigene Entitlement-Zeilen (siehe 7.3); in „Mein Lernen“ wird der Zugang nur einmal angezeigt. Wird eine von zwei Bestellungen erstattet, bleibt der Zugang über die andere bestehen. Bei zeitlich begrenzten Zugängen regelt die Produktkonfiguration, ob ein Zweitkauf verlängert (Beginn = Ende des bestehenden Zugangs) oder parallel läuft.
- **Dauerzugang:** `valid_until = NULL`; bei zeitlich begrenztem Zugang Enddatum aus Kauf-/Produktregel berechnen.
- **Manuelle Adminfreigabe:** eigener Button, erforderliche Begründung, optional Enddatum, protokollierte Person/Zeit.
- **Mein Konto:** WooCommerce-Tab oder Lernplugin-Endpoint zeigt aktive/abgelaufene Zugänge und Kurslink.
- **Order-Admin:** Bestellposition zeigt zugehörige Entitlements und Synchronisationsstatus, ohne Woo-Core-Bestellfelder zu überschreiben.
- **Reconciliation:** Admin-Tool/CLI gleicht Woo-Order-Items und Entitlements ab; Vorschau zuerst, keine stille Massenwiderrufe.
- **Gruppenlizenzen:** Produkt mit „Plätzen je Stück“; Menge = Anzahl Pakete. Bezahlte Position ergibt eine Gruppe (`bp_learning_group`) mit Kontingent und Laufzeit; die verwaltende Person lädt per Link oder E-Mail ein (`/bootsscheine/gruppen/`), jeder belegte Platz erhält eigene Zugänge (Quelle `group`). Dispute/Erstattung/Storno wirken über die Gruppe auf alle Plätze, eine Teilerstattung reduziert die Plätze. Platz freigeben widerruft die Zugänge des Mitglieds.
- **Mitgliederrabatte:** Prozentrabatt für Nutzer mit aktivem Zugang (Kauf, manuell, Gruppe; kostenlose Bereiche zählen nicht) auf andere Produkte, Lernprodukte oder alles; im Warenkorb auf den Positionspreis angewendet und an der Bestellposition vermerkt.
- **Geschützte WordPress-Inhalte:** Seiten/Beiträge (Metabox), Abschnitte (Block „Nur mit Lernzugang“, Shortcode `[bootspruefung_zugang]`) und Dateien (Mediathek-Feld; Datei wird in einen gesperrten Ordner verschoben und nur über `/bootsscheine/datei/<id>/` nach Prüfung ausgeliefert). Geschützte Inhalte verlangen vollen Zugang; REST, Feeds und automatische Auszüge enthalten den Text nicht.
- **Kein Abo in V1:** wiederkehrende Zahlungen nur über WooCommerce Subscriptions o. ä.; das Plugin würde dann auf deren Status reagieren.

### 7.7 Kompatibilität

WooCommerce-Bestellungen werden über `WC_Order`, `WC_Order_Item_Product`, CRUD- und Order-Query-APIs gelesen; nie mit direkten `wp_posts`/`wp_postmeta`-Abfragen. HPOS-Kompatibilität ist ausdrücklich zu deklarieren. Checkout muss mit klassischem Checkout und aktivierten Checkout Blocks kompatibel sein oder die nicht unterstützte Variante sauber melden. citeturn8675590search2turn8675590search3turn8675590search4turn8675590search7

Kein eigener Payment Gateway in V1; Zahlungsabwicklung bleibt bei bestehenden WooCommerce-Gateways. Keine Kartendaten oder Zahlungs-Tokens werden durch das Lernplugin verarbeitet.

## 8. Plugin-Aufbau und technische Konventionen

Vorgeschlagener Verzeichnisaufbau:

Repository-Aufbau (`hastla007/learnboating`):

```text
content-source/boatingschool/   # unveränderter Snapshot + SOURCE.md
tools/
  sync-source.sh                # Snapshot zu einem Commit aktualisieren
  content-build/                # Konverter Snapshot → Content-Paket
schema/                         # JSON-Schemata des Content-Pakets
bootspruefung-learning/         # das eigentliche WordPress-Plugin
```

Plugin-Aufbau:

```text
bootspruefung-learning/
  bootspruefung-learning.php
  src/
    Infrastructure/Database/
    Content/Import/
    Content/Repositories/
    Learning/Services/
    Exams/Services/
    Radio/Services/
    Commerce/WooCommerce/
    Admin/
    Rest/Controllers/
    Security/
  assets/src/
  assets/build/
  templates/
  languages/
  uninstall.php
```

- Namespaced PHP-Klassen; Composer-Autoloading.
- eigene Plugin-Capabilities: `manage_bp_content`, `import_bp_content`, `manage_bp_entitlements`; Administrator erhält sie bei Aktivierung.
- Plugin-Slug, Text-Domain und REST-Namespace einheitlich `bootspruefung` bzw. `bootspruefung-learning`; REST-Routen unter `/wp-json/bootspruefung/v1/`.
- Validierung und Berechtigungsprüfung in jedem REST-Controller; Nonce für eingeloggte Browserrequests; Rate-Limits für Antwort-/Simulationsaktionen.
- `$wpdb->prepare()` für SQL und Transaktionen für Lernversuch/Fortschritt.
- keine fachliche Logik im Theme; Shortcode/Block rendert nur Einstieg in Plugin-UI.
- Update-Migrationen mit `dbDelta()` nur für unterstützte einfache DDL; komplexe Änderungen mit versionierten Migrationsschritten.
- HPOS, aktuelle WooCommerce-Checkout-Architektur und sichere Deaktivierung ohne Content-Löschung berücksichtigen.
- Mindestversionen: PHP 8.1, WordPress 6.4, WooCommerce 8.x (genaue Ziele siehe Kap. 13); Prüfung bei Aktivierung.
- Hintergrundjobs (Batch-Import, Ablauf von Zugängen, Abschluss abgelaufener Prüfungssitzungen) über Action Scheduler, nicht über WP-Cron allein.
- WP-CLI-Befehle: `wp bootspruefung import`, `… import --dry-run`, `… reconcile`, `… entitlements`.
- `uninstall.php` löscht Tabellen und Daten nur, wenn in den Einstellungen ausdrücklich „Daten beim Deinstallieren löschen“ gesetzt ist; Standard ist Erhalt.
- Multisite: V1 unterstützt Einzelinstallationen; bei Multisite Aktivierung nur pro Site, keine netzwerkweite Aktivierung.
- i18n: alle UI-Strings über die Text-Domain; Content-Sprache ist Datenfeld (V1: Deutsch, Englisch nur in Funktexten/Vokabeln).
- Barrierefreiheit: Lernoberfläche per Tastatur bedienbar, WCAG 2.1 AA als Ziel.
- Caching: Fragenauswahl und Kursstruktur über Object Cache/Transients, invalidiert bei Import-Freigabe; keine Cache-Auslieferung nutzerspezifischer Daten.
- Tests: PHPUnit mit WordPress-Test-Suite für Domain-/Repository-Logik und REST, Golden-Master-Tests für den Konverter, E2E (Playwright) für Lernmodus, Prüfung und Checkout (klassisch und Blocks); CI über GitHub Actions.

## 9. Admin- und Lernoberflächen

### Adminmenü

- **Dashboard:** Inhalts-/Katalogstatus, ausstehende Rechteprüfung, Importwarnungen, WooCommerce-Zuordnungsfehler. *Umsetzung: Übersicht listet Fehler/Hinweise je Produkt (unbekannter Kurs, gelöscht, unveröffentlicht, nicht kaufbar, nicht virtuell, uneinheitliche Regeln), bezahlte Bestellungen ohne Kundenkonto und überbuchte Gruppen.*
- **Content-Pakete:** hochladen, Vorschau, Fehler, Rechteblockaden, Freigabe, Verlauf und Rollback. Rollback bedeutet: Das vorher veröffentlichte Paket wird erneut als aktiver Stand gesetzt, d. h. dessen Revisionen werden wieder `published`, die des zurückgenommenen Pakets `deprecated`. Revisionen werden nie gelöscht, damit bestehende Versuche gültig referenziert bleiben. *Umsetzung: Rollback spielt das gespeicherte Paketverzeichnis eines früher veröffentlichten Imports als neuen Import ein (Pflichtbegründung, Vorschau, Freigabe, Audit `import_rolled_back`); Fragen mit gleichem Hash erhalten ihre frühere Revision zurück statt einer neuen, nicht mehr enthaltene Datensätze werden wie beim Reimport archiviert bzw. reaktiviert. Admin: Detailseite des Imports, CLI: `wp bootspruefung rollback <import-id> --reason=…`.*
- **Kurse & Module:** Zuordnungen und Reihenfolge kontrollieren.
- **Prüfungsregeln:** Regelwerk, Verifizierung und Gültigkeit.
- **Medien:** fehlende Dateien, Lizenzstatus, verwaiste Assets.
- **WooCommerce-Zuordnungen:** Produkt → Kurs/Features, Dauer und Rückerstattungsregel. *Umsetzung: Reiter „Lernzugang“ im Produkt bzw. in der Variation.*
- **Zugänge:** Suche nach Nutzer/Order, manuelle Freigabe/Verlängerung/Aussetzung/Widerruf mit Pflichtbegründung und Audit.
- **Kostenlose Bereiche und Freigaben:** Free-Regeln (`bp_access_rule`) je Kurs und Funktion – ganz oder Teilumfang, optional befristet – und zeitgesteuerte Freigaben je Modul/Videokapitel.
- **Gruppenlizenzen:** Liste, Detail mit Teilnehmenden, Aussetzen/Beenden/Platz freigeben mit Begründung.
- **Mitgliederrabatte:** Regeln je Kurs, Prozentsatz und Geltungsbereich (nur mit WooCommerce).
- **Systemstatus:** DB-Schemaversion, Woo-Kompatibilität, letzter Export/Import, Cron/Action Scheduler Fehler.

### Lernende

- „Mein Lernen“ mit Scheinen, freigeschalteten Features und Fortschritt.
- pro Schein Kacheln für Fragen, Prüfung, Navigation, Praxis, Video und Funk.
- sichtbare Sperrseite mit WooCommerce-Produktlink, wenn Zugriff fehlt.
- verständliche Statusanzeige für kostenlos, freigeschaltet, abgelaufen oder Zahlung ausstehend.

## 10. Sicherheit, Datenschutz und Rechte

- Richtige Antworten und Musterlösungen für Prüfungssitzungen nie vorzeitig an den Client ausliefern.
- Zugriff pro REST-Aufruf und Content-Funktion serverseitig prüfen.
- Benutzer-ID nie aus Clientpayload als vertrauenswürdig übernehmen; aus authentifizierter WP-Session beziehen.
- Eindeutige Idempotency-Tokens gegen Doppel-Submit; keine doppelte Progress-Erhöhung.
- Dateizugriff auf geschützte PDFs/Videos über kontrollierte URL/Provider-Regeln; öffentliche Media-Library-URL gilt nicht als Zugriffsschutz.
- keine Kreditkartendaten speichern.
- Datenexport/-löschung für Lernversuche, Fortschritt, Favoriten und Video-/Funkfortschritt in WordPress berücksichtigen.
- Audit-Log enthält Import-, Freigabe-, manuelle Zugangs- und Widerrufsaktionen, aber keine geheimen Payment-Daten.
- Rechteunklare amtliche Texte, Seekarten, Bilder und Audios bleiben gesperrt. Das Repository unterscheidet bereits Rights-Owner/License-Reference/Rights-Status; diese Felder werden nicht weggelassen.

## 11. Umsetzungsschritte

1. **Content-Snapshot und -Audit:** Snapshot zu gepinntem Commit übernehmen; Datenstände, Medienverfügbarkeit, Duplikate, offene Frageformate und vor allem Rights-Status inventarisieren (Ausgangslage siehe 2.1). *Stand: Snapshot ✅ (`content-source/boatingschool/`, `tools/sync-source.sh`); Rechte-Audit offen.*
2. **Paketvertrag und Konverter:** JSON-Schema und Medienmanifest festlegen; Konverter inkl. Portierung der Generatorlogik aus `app/Support/` mit Golden-Master-Tests. *Stand: Format v1 (`schema/`) und Konverter (`tools/content-build/`) ✅ für Kurse, Module, Fragen, Medien, Regelwerke, Bögen, Navigation und Praxis – Golden Master gegen Laravel ohne Abweichung; Video, Funk und Gerätesimulation offen.*
3. **Plugin-Skelett:** Autoloading, Capabilities, Settings, Tabellenmigrationen und REST-Grundstruktur. *Stand: ✅ (`bootspruefung-learning/`), Rauchtest gegen WordPress 7.1.4 + MariaDB 10.11.*
4. **Importer:** Dry-Run, Schema-Validierung, Quell-ID/Hash, Batchimport, Protokoll und Freigabeschritt. *Stand: ✅ Upload (ZIP, Pfadprüfung) oder WP-CLI `wp bootspruefung import`; Prüfung gegen Manifest-Prüfsummen, Schemata und Querverweise; Dry-Run-Vorschau; Freigabe mit Notiz; Import in Teilschritten (fortsetzbar); neue Revision bei geändertem Hash, `source_archived` statt Löschen; Protokoll je Datensatz; Audit-Log.*
5. **Content-Browser/Admin:** Kurse, Module, Fragen, Medien, Regeln und offene Fehler kontrollieren. *Stand: ✅ Übersicht mit offenen Punkten, Kurse & Module, Fragen (Filter, Detail, Revisionen), Medien, Prüfungsregeln (Verifizierung per Override), Rechtefreigabe je Katalog/Quelle/Kurs. Lokale Änderungen als begründete Overrides (`bp_override`), die Reimporte überleben. Rollback auf einen früher veröffentlichten Paketstand ✅ (Kap. 9).*
6. **Lernmodus:** Frage/Antwort, Erklärungen, Merkliste, Fortschritt und Smarttrainer. *Stand: ✅ unter `/bootsscheine/` (Block- und klassische Themes); Smarttrainer-Logik wie die Quelle (neu → falsch → schwaches Thema → gefestigt, gefestigt = 3× richtig in Folge), Modi Neu/Falsch/Merkliste, Modulfilter, offene Fragen mit Musterantwort und Selbsteinschätzung; signierte Frage-Tokens, Idempotency-Key, Rate-Limit.*
7. **Prüfung:** Regeln, Zufallsprüfungen, feste Bögen, Snapshot und Ergebnis. *Stand: ✅ Prüfungsmodus nur mit veröffentlichtem, gültigem, verifiziertem Regelwerk, sonst Übungsmodus; Zufallsauswahl nach Blueprint, feste Bögen mit Fortsetzen; serverseitige Zeit und Abgabe nach Ablauf (auch per Cron); Bewertung mit Teilgrenzen wie die Quelle; Ergebnis mit Teilen, Themen und Fragenrückblick.*
8. **Spezialtrainer:** Navigation, Praxis, Videos, Funktexte, Vokabeln. *Stand: ✅ Konverter (Paketformat 1.1: `video_modules`, `radio_texts`, `radio_vocabulary`, Golden Master gegen Laravel ohne Abweichung) und Trainer im Plugin: Navigation mit amtlichem Ergebnis und Selbsteinschätzung, Praxiskarten nach Kategorie, Videokurs mit Sehfortschritt, Schrittgalerien und Quiz aus der Fragenbank, SRC-Seefunktexte mit Auswertung (Wortvergleich wie die Quelle), UBI-Aufnahme/-Abgabe, Vokabeln und Buchstabiertafel. Alle Videos sind noch Platzhalter der Quelle.*
   - 8a: **Funkpraxis (Sprechfunk-Szenarien)** als eigener Meilenstein. *Stand: ✅ 72 Szenarien (42 SRC, 30 UBI); der Konverter nutzt den Generator `FunkPraxis` der Quelle unverändert, das Plugin wertet Rang, DSC/Verkehrskreis, Funkgerät und Meldung serverseitig gegen die Prüfpunkte aus (Spracheingabe optional per Browser).*
   - 8b: **Gerätesimulation** als eigener Meilenstein. *Stand: ✅ Original-JavaScript der Quelle unverändert übernommen (`tools/sync-device-sim.sh`, CSS per Tailwind auf den Lernbereich begrenzt); 7 Geräte, 48 Lektionen, 4 Prüfungsreihen, freies Üben. Server: Konfiguration, Bewertung von Funksprüchen, Stufen 1–3 und Prüfungsergebnisse – wie in der Quelle meldet der Browser die Gerätebedienung, serverseitig geprüft werden die Funksprüche.*
9. **WooCommerce:** Produktmapping, paid-order-Freischaltung, Bundles, Refunds, My Account und Adminsupport. *Stand: ✅ gateway-neutral für Überweisung, Klarna, PayPal, Stripe und weitere (Kap. 7.5): Reiter „Lernzugang“ (Kurse × Funktionen, Dauer, Verlängerung/parallel, Erstattungsregel, Variationen erben oder überschreiben); idempotente Zugänge je Bestellposition; Aussetzung bei Dispute, Widerruf je Position bei Erstattung bzw. bei Storno; Lernprodukte einzeln und nur mit Kundenkonto; „Mein Lernen“ in Mein Konto, Kurslink in Bestellbestätigung und E-Mail, Sperrhinweis mit Produktlink im Lernbereich; Bestellposition zeigt Zugänge, Bestellaktionen (abgleichen, vorab freischalten, aussetzen, aufheben); Adminseiten „Zugänge“ und „Kostenlose Bereiche“; `wp bootspruefung reconcile [--apply]` mit Vorschau und Rückfrage vor Widerrufen; stündlicher Ablauf befristeter Zugänge; Systemstatus mit Zahlungsarten. Getestet mit Testbestellungen und gegen WooCommerce 11.2 (CI). Bis zum Shopstart steht „Zugang ohne Shop“ weiter auf „alle angemeldeten Nutzer“ – danach auf „Nur kostenlose Regeln und Freischaltungen“ umstellen.*
9c. **Prüfungsausschüsse und Prüfungsanmeldung (Selbstanmeldung):** *Stand: ✅ Zielgruppe ist B2C (Einzelpersonen; Gruppenlizenzen für private Gruppen wie Paare oder Vereine), keine Bootsschulen als Kunden. Lernende melden sich selbst beim Prüfungsausschuss an; das Plugin zeigt nur, wie. Paketformat 1.3 mit `exam_committees` (18 Adressen der DMYV-Liste) und `exam_guides` (6 Anleitungen der Quellseite „Prüfung buchen“: SRC, UBI, SBF See, SBF Binnen, FKN, SKN – mit Gebühr, Fristen, Mindestalter, Unterlagen, Prüfungsformen, Prüfungsteilen, FAQ, DSV/DMYV und Kurszuordnung); Golden Master gegen ExamBooking ohne Abweichung. Plugin: Seite „Prüfung anmelden“ je Kurs (Reiter im Kombikurs, Umfang und Bestehensgrenze wie die Quelle, Links zu den Trainern, Ausschüsse mit Suche), Admin „Prüfungsanmeldung“ (Ausschüsse pflegen, lokale Änderungen überstehen Reimporte; Anleitungen ansehen). **Für Lernende deaktiviert**, bis „Prüfungsanmeldung“ in den Einstellungen freigeschaltet wird. Nicht übernommen: Anmeldeformular, Praxisorte, Terminvergabe durch eine Bootsschule.*
9b. **Restpunkte Schritt 9:** *Stand: ✅ Feature `downloads` mit Downloadliste je Kurs; Zuordnungsfehler in der Admin-Übersicht; automatische Dispute-Behandlung für PayPal (Webhook) und gateway-neutral (signierter Webhook, z. B. Klarna); Browser-Test des Kaufablaufs mit WooCommerce (Sperrhinweis → Produkt → Block- und klassischer Checkout mit Überweisung → Freischaltung nach Zahlungseingang → Mein Lernen, Kontopflicht für Gäste).*
9a. **Membership-Funktionen ohne Zusatz-Plugin:** *Stand: ✅ kostenlose Teilumfänge, zeitgesteuerte Freigabe, geschützte Seiten/Abschnitte/Dateien, Gruppenlizenzen, Mitgliederrabatte (Kap. 7.2, 7.6); DB-Schema 1.4.0; Integrations- und Browser-Tests, Rabatte und Gruppen zusätzlich gegen WooCommerce (CI).*
10. **Content-Freigabe und Go-live:** Rechtsstatus, Katalogaktualität, Regelwerksverifizierung, Medienersatz, Backup/Restore und Supportablauf bestätigen.

## 12. Abnahmekriterien

- Content-Paket importiert alle vorgesehenen Typen und weist Datensatzanzahl/Hash im Admin nach.
- Anzahl der A–D-Fragen und der freien Kartenaufgaben entspricht den Werten im `manifest.json` (Prüfstand Commit `136a243`: 823 bzw. 15); freie Kartenaufgaben werden nicht mit A–D-Fragen vermischt und erhalten ihren passenden Typ.
- Konverter-Ausgabe für Funkpraxis, Funktexte und Gerätesimulation stimmt mit der Golden-Master-Ausgabe der Quelle überein.
- Stabile IDs bleiben über Re-Konvertierungen erhalten (Abgleich mit vorigem Manifest).
- Reimport derselben Quelle erzeugt keine Duplikate; Textänderung legt eine Revision an; archivierter Inhalt bleibt für historische Versuche referenzierbar.
- Jede Frage, Aufgabe, Lektion und Medienreferenz wird ihrer Quell-ID, ihrem Kurs und ihrer Reihenfolge entsprechend dargestellt.
- Navigations-, Praxis-, Video-, Funk- und Geräteinhalte sind über die Oberfläche erreichbar und speichern lokale WordPress-Lernstände.
- Prüfungsmodus verwendet nur gültige, veröffentlichte und verifizierte Regeln; Lösung bleibt bis zur Abgabe verborgen.
- Bezahlte WooCommerce-Order aktiviert die zugeordneten Features genau einmal; unbezahlte Order nicht.
- Vollständige Rückerstattung widerruft nur die zur betreffenden Order-Item gehörenden Zugänge; andere Paketbestandteile bleiben gemäß Regel erhalten.
- HPOS und WooCommerce-Checkout-Variante sind ausgewiesen und unterstützt.
- Admin kann Importfehler, Rechteblockaden, Produktzuordnungen und Zugänge nachvollziehen und korrigieren.
- Keine Laravel-Laufzeitverbindung und keine Laravel-Personendaten sind für den WordPress-Betrieb erforderlich; im Quellrepository wurden keine Änderungen vorgenommen.

## 13. Vor Entwicklungsstart verbindlich zu liefern

1. Festlegung des Quell-Commits bzw. Tags für den ersten Snapshot (geprüft: `136a243`).
2. Liste der in V1 verkauften Scheine und Pro-Features; insbesondere, ob FKN trotz unvollständigem Katalog (nur Pyrotechnik) angeboten wird. *Entschieden: FKN ist dabei; Produktliste offen.*
3. WooCommerce-Produkt-/Variantenliste oder Entscheidung, dass Produkte im Shop angelegt werden.
4. Entscheidung zu Free-vs-Pro-Umfang, Dauerzugang und Rückerstattungsregel.
5. Rechtliche Freigabe/Quellenangaben für Fragen, Seekarten, Bilder, Funktexte, Videos und Audio – insbesondere Genehmigung für SRC-/UBI-Kataloge, Quellenangabe für die 527 Fragen ohne Katalogangabe und Nutzung der ELWIS-Bilder.
6. Ziel-WordPress-/WooCommerce-Version und verwendetes Theme/Checkout.
7. Zu ersetzende Platzhaltervideos und noch zu prüfende Prüfungsregelwerke.
8. Designreferenzen für Lernbereich und gewünschte Brand-Texte.
9. Unterstützte Payment-Gateways (für Chargeback-Behandlung, Kap. 7.5). *Festgelegt: Überweisung, Klarna, PayPal, Stripe; weitere möglich.*
10. Entscheidung, ob Bootsschul-Betriebsfunktionen (Prüfungsbuchung, Prüfungsausschüsse, Praxis-Anmeldung, Tipps) übernommen werden. *Entschieden: Prüfungsausschüsse und Anleitung zur Selbstanmeldung übernommen (vorerst deaktiviert); keine Anmeldung/Terminvergabe durch Bootsschulen (Zielgruppe B2C); Tipps offen.*

## 14. Technische Referenzen

- [WordPress Plugin Handbook: eigene Tabellen](https://developer.wordpress.org/plugins/creating-tables-with-plugins/)
- [WordPress REST API: eigene Endpunkte](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WooCommerce: virtuelle/herunterladbare Produkte](https://woocommerce.com/document/managing-products/virtual-downloadable/)
- [WooCommerce: HPOS](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/)
- [WooCommerce: HPOS-Extension-Kompatibilität](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/)
- [WooCommerce: Payment Gateway API](https://developer.woocommerce.com/docs/features/payments/payment-gateway-api/)
