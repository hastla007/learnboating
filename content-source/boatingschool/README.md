# White-Label-Lernplattform für Bootsschulen

Mandantenfähige Lernplattform für SBF See/Binnen, SBF Binnen Segeln, SRC und
UBI: zentraler, versionierter Fragenbestand, weißes Branding pro Bootsschule,
Smarttrainer, Prüfungssimulation. Umgesetzt als modularer Laravel-Monolith
auf PostgreSQL, gemäß dem mitgelieferten technischen Konzept
(`Technisches Umsetzungskonzept`, `Technikpaket` mit `schema.sql`,
`api_spec.md`, `content_import_mapping.md`, `implementation_plan.md`).

## Architektur

- **Laravel 12 / Blade / Tailwind**, PostgreSQL 16.
- **Multi-Tenancy**: jede Bootsschule ist ein `tenant`, aufgelöst per
  Pfad (`CENTRAL_DOMAIN/{slug}`, Standard, `ResolveTenantPath`), Subdomain
  (`{slug}.CENTRAL_DOMAIN`, optional mit `TENANT_URL_MODE=subdomain`) oder
  Custom Domain (`app/Http/Middleware/ResolveTenant.php`). Mandantengebundene Tabellen
  (inzwischen 30, u. a. `tenant_branding`, `tenant_user`,
  `entitlement`, `attempt`, `progress`, `favorite`, `exam_session`,
  `exam_session_question`, `coupon`, `audit_log`, `app_user`) sind
  zusätzlich per **Postgres Row-Level-Security** abgesichert – ein
  UI-Filter allein zählt nicht als Schutz. Globaler Content (Fragen,
  Module, Kursdefinitionen) ist tenant-unabhängig und wird nur
  referenziert, nie dupliziert.
- **Zwei DB-Rollen**: der Webprozess verbindet sich als
  least-privilege-Rolle `boatingschool_app` (RLS greift für sie ohne
  Ausnahme). Migrations, Seeder und der Content-Import laufen bewusst über
  die Owner-Rolle `boatingschool` (`--database=pgsql_admin`) und umgehen RLS
  absichtlich, weil sie administrative Vorgänge sind.
- **Connection-Pooling und RLS**: `TenantContext::set()`/`clear()` setzt den
  Mandanten pro Request per `SET app.current_tenant_id = ...` auf der
  jeweiligen Datenbankverbindung (Sicherheitsreview M5), `ResolveTenant::
  setCurrentUserIdForRls()` setzt analog `app.current_user_id` (Sicherheits-
  review R3-1). Beide GUCs werden sowohl am REQUEST-START (immer, auch
  wenn keine gültige Session vorliegt -- dann per `RESET`) als auch per
  `app()->terminating()`-Callback am REQUEST-ENDE zurückgesetzt
  (Bug-Review R3-7/R4-D) -- zwei voneinander unabhängige Absicherungen,
  die beide rein auf PHP-Code-Ebene laufen (keine Transaktionsgrenze mehr,
  siehe R3-7-Begründung). Ein vorgeschalteter Connection-Pooler (z. B.
  PgBouncer) **muss deshalb im Session-Modus betrieben werden**
  (`pool_mode = session`), niemals im Transaction- oder Statement-Modus –
  sonst könnte eine einzelne Anfrage mehrere, voneinander unabhängige
  physische Backends durchlaufen (oder eine Backend-Verbindung noch
  WÄHREND eines Requests an einen anderen Client weitergegeben werden,
  bevor der terminating()-Callback überhaupt läuft), auf denen die GUC
  nicht (oder noch vom vorherigen Client) gesetzt ist, was RLS aushebeln
  oder Daten eines anderen Mandanten anzeigen könnte. Diese Anforderung
  ist organisatorisch (Pooler-Konfiguration), nicht durch den
  Anwendungscode technisch erzwingbar -- der Request-Start-Reset mindert
  das Risiko, schließt es bei einem entgegen dieser Vorgabe im
  Transaction-Modus betriebenen Pooler aber nicht vollständig aus. Ohne
  Pooler (direkte Verbindungen, wie im lokalen Setup) besteht dieses
  Risiko nicht.
- **Content-Modell**: `content_question` ist die stabile fachliche Identität,
  jede Textänderung erzeugt eine neue `content_question_revision`. Attempts
  speichern sowohl die stabile Frage als auch die tatsächlich gezeigte
  Revision; Content-Änderungen überschreiben nie historische Attempts oder
  abgeschlossene Prüfungen.
- **Learning Engine** (`app/Services/MasteryCalculator.php`,
  `LearningService.php`, `SmarttrainerService.php`): serverseitige
  Bewertung, unveränderliche Attempts, deterministische und erklärbare
  Priorisierung (neu → falsch → schwaches Thema → gefestigt). Gefestigt
  bedeutet 3 richtige Antworten in Folge, ohne zeitbasierte Wiederholung.
- **Prüfungssimulation** (`app/Http/Controllers/ExamController.php`):
  Regelwerk-Snapshot, keine Sofortauflösung während der Prüfung, serverseitig
  berechnetes Ergebnis nach Abschluss.
- **Modul-gefilterter Smarttrainer**: `LearningController@show` akzeptiert
  einen optionalen `?module=`-Query-Parameter, der `SmarttrainerService` auf
  die Fragen eines einzelnen Kursmoduls einschränkt. Wird von den
  Kapitel-Kacheln auf der Kursdetailseite genutzt.
- **Videokurs** (`video_module`, `video_lesson`, `video_progress`,
  `app/Http/Controllers/VideoCourseController.php`): wie Fragen/Module ist
  der Videocontent zentraler, mandantenunabhängiger Content, an
  `course_definition` gehängt (`CourseDefinition::videoModules()`). Nur der
  Sehfortschritt (`video_progress`) ist mandantenbezogen und per RLS
  abgesichert (Policy `video_progress_isolation`). Der Player
  (`resources/views/video/show.blade.php`) zeigt eine nach Kapiteln
  gruppierte Lektionsliste, markiert abgeschlossene Lektionen und springt
  beim Abschließen automatisch zur nächsten Lektion. **Hinweis**: Die per
  `VideoCourseSeeder` angelegten Lektionen verweisen aktuell auf ein
  öffentliches Platzhaltervideo (`https://www.w3schools.com/html/mov_bbb.mp4`)
  und müssen vor einem produktiven Einsatz durch lizenzierte Kursvideos
  ersetzt werden. Das geht im Superadmin unter Kurse → Kurs → Lektion
  (Titel, https-Video-URL, Dauer); Lektionen mit Platzhaltervideo sind dort
  markiert. Eine neue URL lässt sich auf Wunsch in die von den Seedern
  angelegten Kopien derselben Lektion in Kombi-/Ergänzungskursen
  übernehmen.
- **Schritt-Galerie unter dem Video** (`video_lesson_step`): einzelne
  Lektionen (z. B. Knotenkunde) können eine Schritt-für-Schritt-Galerie
  (`VideoLesson::steps()`) unter dem Player anzeigen. Ohne echtes
  Bildmaterial werden aktuell nur benannte Platzhalter-Schritte angezeigt.
- **Navigationsaufgaben-Trainer** (`navigation_task`, `navigation_question`,
  `app/Http/Controllers/NavigationTaskController.php`): Übungsaufgaben mit
  sofort einsehbarer Musterlösung, bewusst getrennt von der strengen
  Prüfungssimulation (dort gilt "keine Sofortauflösung"). Wie der Videokurs
  zentraler, globaler Content ohne eigenen Fortschritt. `NavigationTaskSeeder`
  importiert die 15 amtlichen Navigationsaufgaben (je 9 Teilaufgaben) aus
  `database/data/sbf_see_navigationsaufgaben.csv` (Quelle: ELWIS,
  Fragenkatalog-See/Navigationsaufgaben). Einzelne Aufgaben tragen einen
  aufgabenweiten Hinweis auf eine spätere amtliche Korrektur (z. B.
  Verkehrsblatt-Berichtigung), der für alle ihre Teilaufgaben gilt.
- **Feste Prüfungsbögen** (`exam_paper`, `exam_paper_question`,
  `ExamController@papersOverview`/`startPaper`): ergänzt das bestehende,
  zufällig zusammenstellende `exam_rule_set`/`exam_blueprint` um 15 feste,
  wiederholbare Fragensets ("Bogen 1" .. "Bogen 15", je 30 Fragen: 7
  allgemeine Basisfragen + 23 kursspezifische Fragen). Ein Bogen-Versuch
  ist ein ganz normaler `exam_session`-Datensatz (Zeitlimit, keine
  Sofortauflösung, unveränderliches Ergebnis) mit einem zusätzlichen
  `paper_id`-Verweis statt zufällig gewürfelter Fragen; erneutes Starten
  desselben Bogens setzt einen bereits laufenden Versuch fort statt ihn zu
  duplizieren. Die Prüfungssimulation-Startseite zeigt pro Bogen einen
  Fortschrittsring mit dem Ergebnis des letzten abgeschlossenen Versuchs.
  Die 15 Bögen werden von `ExamPaperSeeder` aus
  `database/data/sbf_see_pruefungsboegen.csv` importiert (Quelle: die
  einzelnen Bögen unter bootsfuehrerscheinpruefung.de/sbfsee/pruefungsboegen/).
  Jede Zeile referenziert eine Frage über ihre Fragenkatalog-Nr.
  (= `content_question.official_number`), eindeutig erst in Kombination mit
  der Fragenfamilie aus der Tags-Spalte ("Basisfragen" → `shared_basis`,
  "Spezifische Fragen See" → `see_specific`), da dieselbe Katalognummer je
  Fragenfamilie mehrfach vorkommt. Alle Fragenbilder der SBF-Kurse (Basis,
  See, Binnen, Binnen Segeln) stammen von ELWIS (Fragenkatalog Binnen
  online, Fragenkatalog See als PDF Stand August 2023;
  `database/data/elwis_bilder/`, Zuordnung in `elwis_bilder.csv`), hängen
  direkt an der jeweiligen Frage (`question_media`, `ElwisQuestionImageSeeder`)
  und erscheinen dadurch überall, wo diese Frage angezeigt wird (Smarttrainer,
  Prüfungssimulation). Die früheren Bilder aus den Bögen von
  bootsfuehrerscheinpruefung.de sind entfernt. Dasselbe
  Muster (eigener Seeder je Kurs, feste statt zufällig gewürfelter Fragen)
  wurde inzwischen auch auf weitere Kurse übertragen (u. a. SRC, FKN, SBF
  Binnen, Kombikurse) – mit jeweils eigener Fragenzahl/-zusammensetzung laut
  deren amtlichem Prüfungsbogen statt der hier beschriebenen 30/7/23.

## Setup (lokal)

Voraussetzungen: PHP 8.3+, Composer, Node 20+, PostgreSQL 16+.

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan storage:link
```

`storage:link` wird für den Logo-Upload im Branding benötigt (Bootsschul-Admin
→ Branding-Einstellungen); Dateien landen unter `storage/app/public` und
werden über `public/storage` ausgeliefert.

Zwei DB-Rollen anlegen (einmalig, per Infra/Admin – die Owner-Rolle braucht
aus Sicherheitsgründen absichtlich kein `CREATE ROLE`-Recht für die App
selbst):

```sql
-- Eigene, zufällig erzeugte Passwörter einsetzen (z. B. `openssl rand -base64 24`)
-- statt der Rollennamen selbst -- diese Platzhalter nie produktiv übernehmen.
CREATE ROLE boatingschool LOGIN PASSWORD '<eigenes-owner-passwort>';
CREATE ROLE boatingschool_app LOGIN PASSWORD '<eigenes-app-passwort>';
CREATE DATABASE boatingschool OWNER boatingschool;
CREATE DATABASE boatingschool_test OWNER boatingschool;
GRANT CONNECT ON DATABASE boatingschool TO boatingschool_app;
GRANT CONNECT ON DATABASE boatingschool_test TO boatingschool_app;
```

Migrieren, Fragenbestand importieren und Demodaten seeden (ein Kommando,
läuft komplett über die Owner-Rolle):

```bash
php artisan migrate --database=pgsql_admin --force
php artisan db:seed --database=pgsql_admin --force
```

Der Seed-Lauf importiert automatisch die mitgelieferte zentrale
Fragenbasis (`database/data/zentrale_fragenbasis.xlsx`, 838 Zeilen, davon
823 importierbar – 15 Zeilen sind freie Kartenaufgaben ohne A–D-Antworten
und werden bewusst als Fehler ausgewiesen, nicht stillschweigend
übersprungen), legt die 9 Module und 14 Kursdefinitionen an und erzeugt
zwei Demo-Bootsschulen.

Die 130 UBI-Fragen entsprechen dem UBI-Gesamtfragenkatalog Stand Oktober
2026 (ABVT Koblenz, gültig ab 01.10.2026; Zeilen mit `source_version`
`2026-10`). Auf einer Installation mit dem bisherigen Katalog 08/2023 legt
der Seed-Lauf für die 65 geänderten Fragen neue Revisionen an und
veröffentlicht sie (`CatalogUpdateSeeder`); die alte Fassung bleibt als
`deprecated` erhalten, bereits gespeicherte Versuche verweisen weiter auf
sie. Die Ergänzung SRC → UBI nutzt nur die 79 Fragen des amtlichen
Ergänzungskatalogs (Modul `UBI_ERG`, dieselben Fragen wie im Modul `UBI`)
und hat 12 Übungsbögen à 10 Fragen; ihr Regelwerk ist veröffentlicht
(8 von 10 richtig, 30 Minuten). Der Kombikurs SRC & UBI übernimmt die
12 SRC-Bögen (Nr. 1–12), die 12 UBI-Bögen (Nr. 101–112) und die 12 Bögen
der Ergänzung SRC → UBI (Nr. 201–212); sein eigenes Regelwerk bleibt
`draft`, weil ein Regelwerk die verschiedenen Grenzen (SRC 19/24,
UBI 17/22, Ergänzung 8/10) nicht abbilden kann.

```bash
php artisan serve
```

Jede Bootsschule ist auch lokal unter ihrem Pfad erreichbar, z. B.
`http://localhost:8000/mueller/`. Zusätzlich gibt es einen Dev-Override:
`http://localhost:8000/login?as_tenant=mueller` (nur aktiv in
`APP_ENV=local|testing`). Auf der zentralen Domain ohne gewählten Tenant
erscheint ein einfacher Bootsschul-Picker.

### Demo-Zugänge

| Bootsschule | Rolle | E-Mail | Passwort |
|---|---|---|---|
| Bootsschule Müller (`mueller`) | Admin | admin@bootsschule-mueller.de | password |
| Bootsschule Müller (`mueller`) | Lernender | max@bootsschule-mueller.de | password |
| Hanse Bootsschule Kiel (`hanse-kiel`) | Admin | admin@hanse-bootsschule-kiel.de | password |
| Hanse Bootsschule Kiel (`hanse-kiel`) | Lernender | lena@hanse-bootsschule-kiel.de | password |

### Scheduler (Cron) und Queue

Der Laravel-Scheduler (`routes/console.php`) braucht serverseitig einen
echten Cronjob, der `php artisan schedule:run` jede Minute aufruft --
ohne ihn laufen die folgenden Kommandos nie, auch nicht im Dev-Setup mit
`php artisan serve`:

```cron
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Geplante Kommandos:

- `app:expire-entitlements` (stündlich) -- setzt `entitlement.status` für
  abgelaufene Freischaltungen von `active` auf `expired` (Bug-Review B15).
  Ohne diesen Lauf bliebe eine abgelaufene Freischaltung dauerhaft als
  aktiv markiert.
- `app:expire-abandoned-exam-sessions` (stündlich) -- wertet Prüfungs-
  Sessions aus, die ihr Zeitlimit überschritten haben, aber nie manuell
  abgeschlossen wurden (Bug-Review B32). Ohne diesen Lauf bliebe
  `exam_session.status` für eine abgebrochene Prüfung dauerhaft auf
  "running" stehen.
- `app:prune-personal-data` (täglich) -- löscht personenbezogene Daten
  gemäß den in `config/data_retention.php` konfigurierten Fristen (DSGVO-
  Review D3). Greift nur, wenn die jeweilige Frist bewusst per `.env`
  aktiviert wurde.

- `app:backup` (täglich 03:15 Europe/Berlin) -- Datenbank-Dump und Uploads
  nach `storage/app/backups/`, optional auf externen Speicher
  (`BACKUP_DISK`), siehe `deploy/README.md`.
- `ops:scheduler-heartbeat` (jede Minute) -- Lebenszeichen für den
  Go-live-Check.

`QUEUE_CONNECTION` steht in `.env`/`.env.example` auf `database` (Tabellen
`jobs`/`failed_jobs`/`job_batches` existieren bereits), aktuell nutzt aber
keine Klasse `ShouldQueue` -- der gesamte Mailversand (Passwort-Set-Link,
Einladung, E-Mail-Bestätigung) läuft bewusst synchron im Request über
`App\Support\BestEffortMail` (Bug-Review B31: ein SMTP-Fehler wird
geloggt, bricht den Request aber nicht ab). Ein `php artisan queue:work`
ist daher für den aktuellen Funktionsumfang nicht nötig; sobald ein
künftiges Feature echte Queue-Jobs einführt, muss ein Worker-Prozess
(Vorlage `deploy/supervisor-worker.conf`) dauerhaft laufen --
`app:check-production` meldet hängende Jobs.

### Produktivbetrieb

Server-Vorlagen (Caddy/nginx, Cron, Worker), Deploy-Skript
(`deploy/deploy.sh`), Datensicherung und Wiederherstellung sowie
Überwachung beschreibt `deploy/README.md`. Offene Punkte zeigt
`php artisan app:check-production` bzw. im Superadmin die Seite „System“.

## Tests

```bash
php artisan test
```

Die Test-Suite läuft über die reale `boatingschool_app`-Rolle (RLS aktiv),
Fixtures werden über die Owner-Rolle angelegt. Vor dem ersten Lauf muss die
Testdatenbank ebenfalls migriert und geseedet sein
(`DB_DATABASE=boatingschool_test php artisan migrate|db:seed --database=pgsql_admin --force`).

Abgedeckt sind u. a.: Tenant-Isolation auf DB-Ebene (403/404 bei
ID-Manipulation über Mandantengrenzen), serverseitige Bewertung und
Unveränderlichkeit von Attempts, Idempotenz und Revisionslogik des
Content-Imports, sowie die Prüfungsengine (kein Sofort-Feedback,
serverseitige Auswertung).

## Umfang dieser Version

**Umgesetzt** (deutlich über Sprint 0–6 des ursprünglichen
Implementierungsplans hinaus gewachsen): zentraler Content mit
Revisionshistorie, Excel-Import, Tenant/Branding/Rollen mit RLS,
Kurse/Entitlements, vollständige Learning Loop, Smarttrainer,
Lernenden-Dashboard, Bootsschul-Admin (Teilnehmer, manuelle Freischaltung,
Branding), Prüfungssimulation (inkl. fester, amtlicher Prüfungsbögen für
mehrere Kurse), Videokurs, Navigationsaufgaben-Trainer,
Coupon-/Zugangscode-System (inkl. Bundle-Optionen, Export, Audit-Log),
Praxisformular-Anmeldung samt Prüfungsterminen, Willkommensbox-Bestellung
und Versandlabel-Druck, Addon-Banner, Tipps sowie DSGVO-Werkzeuge
(Datenexport, Löschfristen).

**Bewusst ausgeklammert**, konsistent mit der im Technikpaket festgelegten
Reihenfolge "nach V1": ein echter WooCommerce-/Commerce-Adapter bzw.
In-App-Zahlungsabwicklung (Kurskäufe laufen weiterhin über einen externen
Webshop-Link je Kurs, `course_webshop_link`, dessen Bestätigungscode erst
hier als Coupon eingelöst wird), automatisierte Domain-Provisionierung,
Self-Service-Billing, ein vollständiges Content-Review-UI
(Staging/Diff/Publish-Oberfläche – der Import validiert und veröffentlicht
aktuell in einem Schritt) sowie fortgeschrittenes Spaced Repetition.

**Wichtiger Hinweis zur Prüfungssimulation**: Die Prüfungsregelwerke
(Fragenmix, Zeitlimit, Bestehensgrenze) sind für die meisten Kurse weiterhin
als `draft`/unverifiziert markiert. Nur für Kurse mit echten, amtlichen
Prüfungsbögen (ADD-BIN-SEGEL, SRC, FKN, UBI) ist zusätzlich eine tatsächlich aus
der jeweiligen Prüfungsordnung belegte Bestehensgrenze eingetragen und das
Regelwerk als verifiziert markiert (siehe `ExamRuleSetSeeder`) – für alle
anderen Kurse müssen Zeitlimit/Fragenmix/Bestehensgrenze vor echtem
Produktivbetrieb noch fachlich geprüft werden (siehe Umsetzungskonzept,
Abschnitt "Noch festzulegende Betriebsparameter").
