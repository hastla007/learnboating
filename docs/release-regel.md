# Release-Regel und Downgrade (To-do T48)

Gilt für das Lern-Plugin `bootspruefung-learning`. Theme und Add-ons haben eigene Versionen und prüfen die
Plugin-Version über den Versionsvertrag (`bootspruefung_learning_compatible()`).

Theme und Add-ons (T92): Ändert sich ihr Code, bekommt die nächste Auslieferung auch dort eine neue Version nach
demselben Schema (Theme `style.css` + `KURSWASSER_VERSION`, Add-ons Kopf + `LBAC_VERSION`/`LBVC_VERSION`) und einen
Eintrag oben im jeweiligen `CHANGELOG.md`. Tests prüfen, dass Kopf, Konstante und Änderungsprotokoll übereinstimmen.
Installation, ZIP-Dateien und Reihenfolge: `docs/installation-und-updates.md`.

## Versionsnummer

- **Jede Auslieferung auf Staging oder Live bekommt eine neue Plugin-Version.** Gleiche Version mit anderem Code
  ist nicht erlaubt.
- Schema: `0.MINOR.PATCH` bis zum ersten öffentlichen Start, danach `MAJOR.MINOR.PATCH`.
  - **PATCH** (0.14.1): Fehlerbehebungen ohne neue Funktion und ohne Datenbank-Änderung.
  - **MINOR** (0.15.0): neue Funktionen, Datenbank-Änderungen (neue Schema-Version), neue App-API-Version.
  - **MAJOR**: Änderungen, die Theme, Add-ons oder App anpassen müssen (Versionsvertrag `contract` erhöhen).
- Die Version steht an drei Stellen, die gleich sein müssen (Test `operations-test`, Abschnitt „Release“):
  Kopf von `bootspruefung-learning.php` (`Version:`), `readme.txt` (`Stable tag:`) und `Plugin::VERSION`.
- Datenbank-Schema (`Migrator::SCHEMA_VERSION`) und App-API (`MobileApi::API_VERSION`) werden nur bei Änderungen an
  Datenbank bzw. App-Schnittstelle erhöht.

## Ablauf einer Auslieferung

1. Version an den drei Stellen erhöhen, Eintrag oben in `bootspruefung-learning/CHANGELOG.md` (Datum, Schema- und
   App-API-Version, Änderungen für Betreiber und Kunden).
2. CI grün (alle Jobs; der Job „Voucher Captain“ ist nur informativ).
   Tag `v<Plugin-Version>` setzen (z. B. `git tag v0.15.0 && git push origin v0.15.0`). Der Workflow „Release“
   baut daraus alle vier ZIP-Dateien mit `SHA256SUMS` und legt sie als GitHub-Release ab
   (`tools/release/build-zips.sh`, `docs/installation-und-updates.md`).
3. **Backup** von Datenbank und `wp-content/uploads/bootspruefung/` (bzw. `BOOTSPRUEFUNG_PRIVATE_DIR`), siehe `docs/backup-restore.md` (T66).
4. Erst auf Staging einspielen, Systemstatus prüfen: Schema „aktuell“, Abschnitt „Wartung“ ohne Fehler.
5. Dann live einspielen. Nach einem Update ohne erneute Aktivierung läuft die Datenbank-Migration im Admin, per
   Cron oder in der CLI (`wp bootspruefung migrate`). Bis dahin beantwortet die Lern-API schreibende Anfragen mit 503
   „Wartung“ (Eingaben gehen nicht verloren). Empfehlung: direkt nach dem Einspielen einmal den Admin öffnen.
   Anfragen von Kunden über `admin-ajax.php` oder `admin-post.php` (z. B. Warenkorb per Ajax) starten die Migration
   nicht selbst, sie planen nur einen sofortigen Cron-Lauf. Schlägt die Migration fehl, wartet jeder weitere Versuch
   außerhalb der CLI 15 Minuten (auch der Cron-Lauf); danach plant das Plugin den nächsten Versuch von selbst.
   `wp bootspruefung migrate` versucht es immer sofort.
   Neue Standard-FAQs, Kunden-FAQs und Starttexte der Tipps legt das Plugin ebenfalls nur im Admin, per Cron oder
   in der CLI an bzw. gleicht sie an, nie in einer Besucheranfrage (T84). Auch dafür nach dem Einspielen einmal den
   Admin öffnen.
6. Browser laden neue Dateien sofort: Die Version der eingebundenen Dateien enthält den Dateistand.
7. Mit einem neuen Theme-Stand gleicht das Theme die Inhaltsseiten automatisch ab (nur unveränderte Seiten, siehe
   `docs/theme-livegang.md`, Abschnitt „Inhaltsseiten bei Theme-Updates“). Danach im Dashboard den Hinweis
   „Inhaltsseiten“ prüfen: Seiten mit Warnung „von dir bearbeitet“ von Hand angleichen.

## Downgrade

Migrationen laufen nur vorwärts. Ein älteres Plugin auf einer neueren Datenbank migriert nichts mehr zurück und zeigt
im Admin einen Hinweis („Die Datenbank hat Schema …“).

Ältere ZIP-Dateien liegen dauerhaft unter den GitHub-Releases (ab dem ersten Tag); für Stände davor mit
`tools/release/build-zips.sh --rev <commit>` neu bauen.

- **Gleiche Schema-Version** (nur PATCH oder MINOR ohne Datenbank-Änderung): Die ältere ZIP-Datei einspielen genügt.
  Das Schema steht im Systemstatus und im Änderungsprotokoll.
- **Neuere Schema-Version:** Datenbank-Backup von vor dem Update zurückspielen und die ältere ZIP-Datei einspielen.
  Achtung: Lernstand, Bestellungen und Zugänge seit dem Update gehen dabei verloren. Nur im Notfall; besser die
  neuere Version mit einer Fehlerbehebung (PATCH) korrigieren.
- Ohne Backup ist ein Downgrade mit neuerer Schema-Version nicht vorgesehen. Die ältere Version läuft dann meist,
  weil neue Spalten und Tabellen nur zusätzlich sind, aber das ist nicht getestet.
