# Technisches Umsetzungskonzept: WordPress-Lernplugin für Bootsprüfungen

**Stand:** 6. November 2026  
**Zielgruppe:** WordPress-, PHP-, WooCommerce- und Frontend-Entwicklung  
**Inhaltsquelle:** `hastla007/boatingschool`  

## 1. Ziel und Abgrenzung

Dieses Plugin erweitert eine bestehende WordPress-Website um eine eigenständige Lernplattform für Bootsführerscheine, Navigation, Praxis, Funk und Video-Kurse. Es importiert und nutzt den bereits aufbereiteten Content aus dem GitHub-Projekt `hastla007/boatingschool`.

Das Laravel-Projekt dient ausschließlich als Content-Quelle und Exporter. Die Laravel-Anwendung, deren PostgreSQL-Datenbank, Login-System, Mandanten, Nutzer, Lernstände, Freischaltungen, Coupons und Betrieb werden nicht als Laufzeitabhängigkeit übernommen. Es gibt keine direkte Laufzeitverbindung von WordPress zur Laravel-Datenbank.

WordPress wird zur Laufzeit maßgebliche Instanz für:

- WordPress-Nutzer und Lernfortschritt;
- WooCommerce-Produkte, Bestellungen, Rückerstattungen und Zahlung;
- Zugangsrechte zur Lernplattform;
- lokale redaktionelle Ergänzungen, sofern solche erlaubt werden.

Die importierten Quellinhalte behalten ihre Quell-ID und Revision. Reimporte aktualisieren Inhalte kontrolliert und duplizieren sie nicht.

### Im Umfang

- Content-Export aus Laravel-Quelle und versionierter Import in WordPress;
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
- Übernahme der Laravel-Administration oder mandantenspezifischen Funktionen;
- automatische Behauptung, ein Inhalt sei amtlich, aktuell oder frei nutzbar, wenn Quelle, Stand und Rechte nicht belegt sind.

## 2. Bekannte Content-Quelle und Konsequenzen

Das Repository enthält eine Laravel-Anwendung mit PostgreSQL-Datenmodell sowie Content in Datenbank-Seeds und Dateien unter `database/data/`. Der dokumentierte Bestand umfasst u. a. Fragen mit Revisionen, Antworten und Medien, Kurse und Module, Prüfungsregeln, feste Bögen, Navigationsaufgaben, Praxiskarten, Videokurse, Funktraining und Gerätesimulation.

Die README beschreibt eine zentrale Fragenbasis mit 838 Tabellenzeilen, davon 823 als A–D-Fragen importierbar; freie Kartenaufgaben ohne A–D-Antworten müssen als eigener Inhaltstyp behandelt werden und dürfen nicht verworfen werden. Versions- und Umfangszahlen sind beim Export dynamisch zu ermitteln, nicht im WordPress-Plugin zu hardcodieren.

Die Quellmigrationen und Dateien sind Teil der technischen Spezifikation:

- [Repository und README](https://github.com/hastla007/boatingschool)
- [Fragen, Revisionen, Antworten und Frage-Medien](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000020_create_content_tables.php)
- [Kurse und Module](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000030_create_module_and_course_tables.php)
- [Prüfungsregeln und Simulationen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000050_create_exam_tables.php)
- [Feste Prüfungsbögen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000110_create_exam_paper_tables.php)
- [Navigationsaufgaben](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000100_create_navigation_task_tables.php)
- [Praxistrainer](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000140_create_praxis_trainer_tables.php)
- [Videokurse](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000080_create_video_course_tables.php)
- [Video-Schrittgalerien](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000090_create_video_lesson_step_table.php)
- [Videoquiz-Zuordnungen](https://github.com/hastla007/boatingschool/blob/main/database/migrations/2024_02_01_000330_create_video_lesson_question_table.php)
- [Funk-Praxisaufgaben](https://github.com/hastla007/boatingschool/blob/main/database/data/funk_praxis_aufgaben.php)
- [SRC-Seefunktexte](https://github.com/hastla007/boatingschool/blob/main/database/data/funk_seefunktexte.php)
- [UBI-Funktexte](https://github.com/hastla007/boatingschool/blob/main/database/data/funk_ubi_texte.php)
- [Funk-Vokabeln](https://github.com/hastla007/boatingschool/blob/main/database/data/funk_vokabeln.php)
- [Funk-Videoquiz-Zuordnungen](https://github.com/hastla007/boatingschool/blob/main/database/data/funk_video_quiz.php)
- [Gerätesimulation](https://github.com/hastla007/boatingschool/blob/main/database/data/geraetesimulation.php)

Die PHP-Datendateien enthalten teilweise kompakte Rohdaten, aus denen Laravel derzeit Texte, Musterlösungen oder Prüfpunkte generiert. Ein WordPress-Content-Export muss deshalb entweder fertig gerenderte Inhalte und strukturierte Bewertungsdaten liefern oder die nötige Generator- und Bewertungslogik explizit in WordPress nachbilden. Rohdaten allein reichen dort nicht.

## 3. Systemarchitektur

### 3.1 Komponenten

1. **Laravel Content Exporter:** CLI-Kommando im Quellrepository; liest nur veröffentlichbaren Content sowie erforderliche Beziehungen und Dateien. Es exportiert keine personenbezogenen Daten oder Mandanten.
2. **Content Package:** versioniertes ZIP mit `manifest.json`, strukturierten JSON-Dateien pro Inhaltstyp und Medien im relativen Pfad. UTF-8, explizite Datumsformate und keine PHP-Serialisierung.
3. **WordPress Importer:** Dry-Run, Validierung, Mapping, Idempotenz, Transaktions-/Batchverarbeitung, Importprotokoll und Freigabe.
4. **Lernplugin:** PHP-Domain-Layer, Tabellen, REST-API, Adminoberfläche und nutzerseitige Lernoberfläche.
5. **WooCommerce Adapter:** Produktzuordnung und Ereignisverarbeitung; nutzt WooCommerce-Datenobjekte und APIs, keine direkten Bestell-SQL-Abfragen.

### 3.2 Paketformat

`manifest.json` enthält mindestens:

- `package_id`, `schema_version`, `exporter_version`;
- `source_repository`, `source_branch`, `source_commit_sha`, `exported_at`;
- deklarierte Content-Kataloge, Sprachen und Versionsstände;
- Anzahl Datensätze je Entität;
- Datei- und Assetmanifest mit Größe, MIME-Type und SHA-256;
- Warnungen, ausgelassene Datensätze und Gründe;
- Rights-/Publish-Status je Entität oder Revision.

Entitäten werden als getrennte Dateien geliefert, z. B. `courses.json`, `modules.json`, `questions.json`, `question_revisions.json`, `answers.json`, `exam_rules.json`, `exam_papers.json`, `navigation_tasks.json`, `praxis_tasks.json`, `video_lessons.json`, `radio_items.json`, `device_simulations.json`.

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
- Status `draft`, `review`, `published`, `deprecated`, `archived` oder äquivalente WordPress-Enums;
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

Die Quelle speichert Fälle teilweise kompakt als Parameterobjekte. Der Exporter muss zusätzlich den von Laravel erzeugten vollständigen Szenariotext, Musterfunkspruch und Bewertungsprüfpunkte exportieren. Das WP-Plugin soll keine unvollständigen Szenarien aus bloßen Rohparametern erraten.

**SRC-Seefunktext:** stabile ID, Richtung (Land→See/See→Land), Meldungsart, deutscher Text, englischer Text, segmentierte Textteile, Reihenfolge, Quelle/Rechte.

**UBI-Text:** stabile ID, Übungsform (Aufnahme/Abgabe), Meldungsteile, Schreibtext, markierte Buchstabierwörter, erwartete Schreibweise, Reihenfolge, Quelle/Rechte.

**Vokabel:** feste Quell-ID, Katalog, Kategorie, Deutsch, Englisch, Aussprache-/Audioasset wenn vorhanden, zulässige Varianten und Sortierung. IDs nicht aus Textpaaren generieren, da Textkorrekturen sonst Fortschrittsschlüssel ändern.

**Gerätesimulation:** Geräte-ID/Modell (z. B. IC-M505, IC-M323, IC-M423, IC-M503/DS-100), Funkkatalog SRC/UBI, Lektionen, Stufe, unterstützte Geräte, Startzustand, Schrittfolge, Zielereignis (`goal`), Bedienteil (`target`), Hilfetext, gerätespezifische Abweichung, Empfangsereignis, Gegensprechtext, Prüfchecks (Label, Regex/Regel, Mindestanzahl), Musterlösung und Szenarioverweis.

Wenn eine Browserstimme statt lizenzierter Sprecheraufnahme verwendet wird, ist das als Ausgabemodus konfigurierbar. Audio-/Stimmdateien werden als Medien mit Rechten geführt.

### 4.10 Rechte und Veröffentlichung

- `rights_status=unknown` oder `restricted` blockiert öffentliche Auslieferung.
- Rechteinformationen sind je Inhalt/Revision/Asset vererbbar, müssen aber bei Asset-Abweichung überschreibbar sein.
- Für eigene Übungstexte und amtliche Originaltexte werden getrennte Quellen-/Lizenzangaben geführt.
- Importprotokoll vermerkt, wer einen gesperrten Inhalt freigegeben hat, wann und auf welcher Rechtsgrundlage.

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
- `bp_navigation_task`, `bp_navigation_question`.
- `bp_praxis_task`.
- `bp_video_module`, `bp_video_lesson`, `bp_video_lesson_step`, `bp_video_lesson_question`.
- `bp_radio_scenario`, `bp_radio_text`, `bp_radio_vocabulary`, `bp_device`, `bp_device_lesson`, `bp_device_step`.
- `bp_import_batch`, `bp_import_item_log`, `bp_audit_log`.

Alle Contenttabellen tragen mindestens Quell-ID, Quellhash und Status. Fremdschlüssel und eindeutige Indizes sichern die Zuordnungen. Migrationen sind versioniert und laufen bei Plugin-Aktivierung/Update kontrolliert.

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

Lernversuche sind unveränderlich. Korrekturen daran erfolgen als neue administrative Korrekturereignisse, nicht durch stilles Umschreiben der Antworthistorie.

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
- Funkpraxis: gerendertes Szenario, Nutzer spricht/schreibt Funkspruch, Server prüft Pflichtpunkte und zeigt Musterlösung.
- Vokabeln: Deutsch→Englisch, erlaubte Varianten, „gewusst/nicht gewusst“, Lernserie.
- Geräte: Zustandsmaschine mit Geräteeingaben, Hinweisen, Zwischenzielen und Stufenbewertung. Client darf Schritte anzeigen; finale Zielerreichung bleibt serverseitig prüfbar.

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
- `downloads` – freigegebene PDF-Dateien.

Die Feature- und Kurszuordnung wird pro Woo-Produkt editierbar gemacht. Es gibt keine Preis- oder Produktcode-Annahmen im PHP-Code.

### 7.2 Grundmodell für Free/Pro

Plugin-Einstellungen definieren, welche Funktion frei und welche bezahlt ist. Empfohlener Ausgangspunkt:

- kostenlos: Einstieg, Beispiel-/Basis-Lernfunktion und ggf. definierte Frage-/Prüfungsumfänge;
- bezahlt: explizit gewählte Pro-Features pro Schein;
- Produktpakete können mehrere Scheine und Features enthalten.

Welche amtlichen Fragen kostenlos oder kostenpflichtig sind, ist eine Produktentscheidung und muss pro Kurs/Katalog konfigurierbar sein. Zugriffsrechte werden nicht aus dem Frontend-Preistext abgeleitet.

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

Eindeutige Referenz auf Bestellposition plus Feature verhindert Doppelzugänge, auch wenn Zahlungsevents wiederholt ankommen.

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
| Chargeback/Payment-Reversal | Zugang suspendieren; nach erfolgreicher Klärung reaktivieren |

Die Regeln gelten pro Bestellposition. Bei Paketen wird pro enthaltenem Kurs/Feature ein eigener Zugang angelegt, damit Rückerstattung eines einzelnen Produkts nicht andere bezahlte Zugänge löscht.

### 7.6 Produkte, Bundles, Coupons, Käufe und Support

- **Bundles:** Produktmapping enthält mehrere Kurs-/Feature-Zeilen.
- **Variationen:** jede Variation kann eigene Zugänge überschreiben; sonst erbt sie Produktmapping.
- **Woo-Coupons:** WooCommerce berechnet Rabatt; Coupon gewährt keinen Lernzugang ohne bezahlte Order.
- **Mengen > 1:** Standard auf Lernprodukte beschränken; falls zulässig, keine mehrfachen Rechte desselben Nutzers erzeugen.
- **Doppelkäufe:** mehrere Bestellungen bleiben in Order-Historie; aktiver Zugang wird nicht dupliziert. Rechte mit Dauer verfallen erst nach Regeln der Produktkonfiguration.
- **Dauerzugang:** `valid_until = NULL`; bei zeitlich begrenztem Zugang Enddatum aus Kauf-/Produktregel berechnen.
- **Manuelle Adminfreigabe:** eigener Button, erforderliche Begründung, optional Enddatum, protokollierte Person/Zeit.
- **Mein Konto:** WooCommerce-Tab oder Lernplugin-Endpoint zeigt aktive/abgelaufene Zugänge und Kurslink.
- **Order-Admin:** Bestellposition zeigt zugehörige Entitlements und Synchronisationsstatus, ohne Woo-Core-Bestellfelder zu überschreiben.
- **Reconciliation:** Admin-Tool/CLI gleicht Woo-Order-Items und Entitlements ab; Vorschau zuerst, keine stille Massenwiderrufe.

### 7.7 Kompatibilität

WooCommerce-Bestellungen werden über `WC_Order`, `WC_Order_Item_Product`, CRUD- und Order-Query-APIs gelesen; nie mit direkten `wp_posts`/`wp_postmeta`-Abfragen. HPOS-Kompatibilität ist ausdrücklich zu deklarieren. Checkout muss mit klassischem Checkout und aktivierten Checkout Blocks kompatibel sein oder die nicht unterstützte Variante sauber melden. citeturn8675590search2turn8675590search3turn8675590search4turn8675590search7

Kein eigener Payment Gateway in V1; Zahlungsabwicklung bleibt bei bestehenden WooCommerce-Gateways. Keine Kartendaten oder Zahlungs-Tokens werden durch das Lernplugin verarbeitet.

## 8. Plugin-Aufbau und technische Konventionen

Vorgeschlagener Verzeichnisaufbau:

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
- REST-Routen in versioniertem Namespace `/wp-json/bootpruefung/v1/`.
- Validierung und Berechtigungsprüfung in jedem REST-Controller; Nonce für eingeloggte Browserrequests; Rate-Limits für Antwort-/Simulationsaktionen.
- `$wpdb->prepare()` für SQL und Transaktionen für Lernversuch/Fortschritt.
- keine fachliche Logik im Theme; Shortcode/Block rendert nur Einstieg in Plugin-UI.
- Update-Migrationen mit `dbDelta()` nur für unterstützte einfache DDL; komplexe Änderungen mit versionierten Migrationsschritten.
- HPOS, aktuelle WooCommerce-Checkout-Architektur und sichere Deaktivierung ohne Content-Löschung berücksichtigen.

## 9. Admin- und Lernoberflächen

### Adminmenü

- **Dashboard:** Inhalts-/Katalogstatus, ausstehende Rechteprüfung, Importwarnungen, WooCommerce-Zuordnungsfehler.
- **Content-Pakete:** hochladen, Vorschau, Fehler, Rechteblockaden, Freigabe, Verlauf und Rollback auf vorherige veröffentlichte Content-Version.
- **Kurse & Module:** Zuordnungen und Reihenfolge kontrollieren.
- **Prüfungsregeln:** Regelwerk, Verifizierung und Gültigkeit.
- **Medien:** fehlende Dateien, Lizenzstatus, verwaiste Assets.
- **WooCommerce-Zuordnungen:** Produkt → Kurs/Features, Dauer und Rückerstattungsregel.
- **Zugänge:** Suche nach Nutzer/Order, manuelle Verlängerung/Widerruf mit Audit.
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

1. **Content-Audit:** Quelle, Datenstände, Medienverfügbarkeit, Duplikate, offene Frageformate und Rights-Status inventarisieren.
2. **Exportvertrag:** JSON-Schema und Medienmanifest festlegen; alle PHP-Generatoren zur Ausgabe vollständig gerenderter Content-Objekte befähigen.
3. **Plugin-Skelett:** Autoloading, Capabilities, Settings, Tabellenmigrationen und REST-Grundstruktur.
4. **Importer:** Dry-Run, Schema-Validierung, Quell-ID/Hash, Batchimport, Protokoll und Freigabeschritt.
5. **Content-Browser/Admin:** Kurse, Module, Fragen, Medien, Regeln und offene Fehler kontrollieren.
6. **Lernmodus:** Frage/Antwort, Erklärungen, Merkliste, Fortschritt und Smarttrainer.
7. **Prüfung:** Regeln, Zufallsprüfungen, feste Bögen, Snapshot und Ergebnis.
8. **Spezialtrainer:** Navigation, Praxis, Videos, Funktexte, Vokabeln, Szenario-Sprechfunk und Gerätesimulation.
9. **WooCommerce:** Produktmapping, paid-order-Freischaltung, Bundles, Refunds, My Account und Adminsupport.
10. **Content-Freigabe und Go-live:** Rechtsstatus, Katalogaktualität, Regelwerksverifizierung, Medienersatz, Backup/Restore und Supportablauf bestätigen.

## 12. Abnahmekriterien

- Content-Paket importiert alle vorgesehenen Typen und weist Datensatzanzahl/Hash im Admin nach.
- 823 importierbare A–D-Fragen aus der dokumentierten Basis werden nicht mit den 15 abweichenden freien Kartenaufgaben vermischt; diese erhalten ihren passenden Typ.
- Reimport derselben Quelle erzeugt keine Duplikate; Textänderung legt eine Revision an; archivierter Inhalt bleibt für historische Versuche referenzierbar.
- Jede Frage, Aufgabe, Lektion und Medienreferenz wird ihrer Quell-ID, ihrem Kurs und ihrer Reihenfolge entsprechend dargestellt.
- Navigations-, Praxis-, Video-, Funk- und Geräteinhalte sind über die Oberfläche erreichbar und speichern lokale WordPress-Lernstände.
- Prüfungsmodus verwendet nur gültige, veröffentlichte und verifizierte Regeln; Lösung bleibt bis zur Abgabe verborgen.
- Bezahlte WooCommerce-Order aktiviert die zugeordneten Features genau einmal; unbezahlte Order nicht.
- Vollständige Rückerstattung widerruft nur die zur betreffenden Order-Item gehörenden Zugänge; andere Paketbestandteile bleiben gemäß Regel erhalten.
- HPOS und WooCommerce-Checkout-Variante sind ausgewiesen und unterstützt.
- Admin kann Importfehler, Rechteblockaden, Produktzuordnungen und Zugänge nachvollziehen und korrigieren.
- Keine Laravel-Laufzeitverbindung und keine Laravel-Personendaten sind für den WordPress-Betrieb erforderlich.

## 13. Vor Entwicklungsstart verbindlich zu liefern

1. Zugriff auf das Content-Repository und Festlegung des Content-Branches/Release-Tags.
2. Liste der in V1 verkauften Scheine und Pro-Features.
3. WooCommerce-Produkt-/Variantenliste oder Entscheidung, dass Produkte im Shop angelegt werden.
4. Entscheidung zu Free-vs-Pro-Umfang, Dauerzugang und Rückerstattungsregel.
5. Rechtliche Freigabe/Quellenangaben für Fragen, Seekarten, Bilder, Funktexte, Videos und Audio.
6. Ziel-WordPress-/WooCommerce-Version und verwendetes Theme/Checkout.
7. Zu ersetzende Platzhaltervideos und noch zu prüfende Prüfungsregelwerke.
8. Designreferenzen für Lernbereich und gewünschte Brand-Texte.

## 14. Technische Referenzen

- [WordPress Plugin Handbook: eigene Tabellen](https://developer.wordpress.org/plugins/creating-tables-with-plugins/)
- [WordPress REST API: eigene Endpunkte](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WooCommerce: virtuelle/herunterladbare Produkte](https://woocommerce.com/document/managing-products/virtual-downloadable/)
- [WooCommerce: HPOS](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/)
- [WooCommerce: HPOS-Extension-Kompatibilität](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/)
- [WooCommerce: Payment Gateway API](https://developer.woocommerce.com/docs/features/payments/payment-gateway-api/)
