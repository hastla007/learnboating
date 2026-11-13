# App-Schnittstelle `boatingschool/v1`

REST-API für die mobilen Lern-Apps (Flutter, iOS/Android, online-only), eingebaut in das Plugin
`bootspruefung-learning`. Sie ersetzt das Konzept-Plugin (`plugin-concept-original/`).

Die App nutzt **dieselben** Konten (WordPress/WooCommerce), Inhalte, Shop-Zugänge, Lernstände und
Prüfungsregeln wie der Web-Lernbereich unter `/bootsscheine/`. Die API rechnet nichts selbst: jeder
Endpunkt ruft die Services auf, die auch der Web-Lernbereich nutzt. Was im Web gesperrt ist
(kein Kauf, kostenloser Teilumfang, zeitgesteuerte Freigabe, Erstattung, Aussetzung), ist in der App
gesperrt.

| Datei | Inhalt |
| --- | --- |
| `openapi.yaml` | Vertrag (OpenAPI 3.1, validiert mit `openapi-spec-validator`) – maßgeblich für die App |
| `README.md` | dieses Dokument |
| `openapi-concept-original.yaml`, `konzept-original.md`, `plugin-concept-original/` | Unterlagen des Auftraggebers (nur Referenz) |

Basisadresse: `https://<shop>/wp-json/boatingschool/v1` (ohne Pretty Permalinks:
`https://<shop>/?rest_route=/boatingschool/v1/…`).

---

## 1. Architektur

```
src/Mobile/
├── MobileApi.php            Routen, Bearer-Auth (nur dieser Namespace), Fehlerformat, Header, Cron-Aufräumen,
│                            Widerruf bei Passwortänderung
├── TokenAuth.php            Gerätesitzungen (bp_mobile_session): Ausgabe, Prüfung, Rotation (mit Kulanz für
│                            verlorene Antworten), Widerruf
├── WebSession.php           Sitzungsübergabe App → Web: Einmal-Codes + Prüfwert der Webansicht
│                            (bp_mobile_web_code), ?bp_app_login=…
├── AppSettings.php          Synchronisierte App-Einstellungen (Nutzer-Meta bp_app_settings, Version)
├── AppLinks.php             Universal Links/App Links: /.well-known/…, /app/passwort/, /app/konto-loeschen/,
│                            Links in Reset- und Löschbestätigungs-Mail, seit 1.7.0 Einladungslinks
│                            /bootsscheine/gruppe/<token>/ in der apple-app-site-association
├── Idempotency.php          Idempotency-Key je Nutzer/Vorgang (bp_mobile_idempotency), Übernahme verwaister Einträge
├── RateLimiter.php          Atomare Zähler (bp_mobile_rate_limit) und Client-IP hinter eingetragenen Proxys
├── Problem.php, ApiException.php   einheitliches Fehlerobjekt
├── MobileSettings.php       Option bp_learning_mobile (App an/aus, Tenant-Slug, Marke, Proxys,
│                            Webansicht-Anmeldung, iOS-/Android-App-Kennungen)
├── Admin/MobileAdminPage.php  Bootsprüfung → Mobile App
├── Controllers/             Auth (inkl. Web-Sitzung), Account, Settings, Course (inkl. Prüfungsanmeldung), Learning, Exam,
│                            Training (Video/Navigation/Praxis), Radio (Funktraining), Coupon, Group (Gruppenlizenzen),
│                            Tips, Privacy, File – je ein dünner Adapter auf die Services
└── Mapper/                  Course-, Question-, Media-Mapper (Service-Ergebnis → Vertragsformat)
```

Registriert über eine Zeile in `src/Rest/RestRegistrar.php`. Datenbank: Schema-Version **1.13.0**
mit fünf eigenen Tabellen (`bp_mobile_session`, `bp_mobile_idempotency`, `bp_mobile_export`, seit
1.11.0 `bp_mobile_web_code`, seit 1.12.0 `bp_mobile_rate_limit`), seit 1.11.0 der Spalte
`bp_exam_session_question.flagged` (Markierung „zur Überprüfung“, Standard 0) und seit 1.12.0 den
Spalten `bp_mobile_export.started_at` (Sperre der Export-Erzeugung) und
`bp_mobile_web_code.verifier_hash` (Prüfwert der Webansicht); `dbDelta` ergänzt sie beim Update.
Die Migration läuft beim Plugin-Update automatisch. Die vier Tabellen mit `user_id` gehören zu den
personenbezogenen Tabellen (Löschung über die WordPress-Datenschutzwerkzeuge bzw. beim Löschen des
Kontos), App-Sitzungen stehen auch im Datenexport (ohne Token-Hashes). `bp_mobile_rate_limit` enthält
nur gesalzene SHA-256-Hashes der Zählernamen (E-Mail/IP), Treffer und Fensterende, keinen
Kontobezug; abgelaufene Fenster löscht der stündliche Cron, die Deinstallation (mit Datenlöschung)
entfernt die Tabelle. Die App-Einstellungen liegen in der Nutzer-Meta
`bp_app_settings` (im Export und in der Löschung enthalten, bei Deinstallation mit Datenlöschung
entfernt).

### Endpunkte und genutzte Services

| Pfad | Service im Plugin |
| --- | --- |
| `POST /auth/login` | `wp_authenticate()` (keine Cookies), `TokenAuth::issue`, `RateLimiter` (E-Mail+IP, E-Mail, IP) |
| `POST /auth/refresh`, `POST /auth/logout`, `GET/DELETE /me/sessions[/{id}]` | `TokenAuth` |
| `POST /auth/password/forgot`, `POST /auth/password/reset` | `retrieve_password()` (mit App-Kennungen: Link über `AppLinks`), `check_password_reset_key()`, `reset_password()` |
| `POST /auth/web-session` | `WebSession::create` (Einlösen: `WebSession::handleRequest` mit Header `X-Boatingschool-Web-Verifier` → `wp_set_auth_cookie`) |
| `GET/PUT /me/settings` | `AppSettings` (Nutzer-Meta `bp_app_settings`) |
| `GET /me/tickets` | `Extensions\Tickets` (Filter `bootspruefung_tickets_for_user` des Ticket-Plugins, sonst Nutzer-Meta `_bp_tickets`) |
| `GET /me` | WordPress-Konto, `Settings::brand()`, `MobileSettings` |
| `GET /courses`, `GET /courses/{id}` | `ContentRepository::courses`, `AccessService::scopes`, `LearningService::courseTotal`, `ExamService::overview`, `TrainerController::summary`, `Integration::offers`, `VideoUpgrade::offerFor`, `DownloadService`, `SectionFiles`, `Charts`, `DeviceSimService::available/premium` (`device_simulator`), `ExamGuideService::pagesFor` (`exam_registration`) |
| `GET /courses/{id}/exam-registration` | `ExamGuideService` (`enabled`, `payload` = Anleitungen + `committees`) wie `ExamGuideController` |
| `GET /courses/{id}/overview`, `/modules`, `/progress` | `LearningService::courseProgress` (Module, Themen, Drip, Teilumfang) |
| `GET /courses/{id}/trainer/next` | `LearningService::next` (+ `CollectionService::questionIds`, `ExamService::wrongQuestionIds`); `mode=question` (1.7.0): `LearningService::next` mit Fragenauswahl `[question_id]`, Sperrgrund aus `LearningService::moduleGates`/`allowedQuestions`/`blockedByRunningExam` |
| `POST /courses/{id}/trainer/reveal` | `LearningService::reveal` |
| `POST /courses/{id}/attempts`, `GET …/attempts/{id}` | `QuestionPresenter::verify`, `LearningService::answer` |
| `…/favorites`, `…/favorite-collections`, `…/mistakes` | `CollectionService` (`overview`, `assign`, `create`, `rename`, `delete`), `LearningService::setFavorite`; `saved_at`/`assigned_at` aus `bp_favorite.created_at` / `bp_favorite_collection_item.created_at` |
| `GET/POST /courses/{id}/exams`, `/exams/{id}…` | `ExamService` (`overview`, `start`, `show`, `saveAnswer`, `setFlag`, `reveal`, `submit`) |
| `GET /courses/{id}/videos`, `/videos/{id}`, `PUT …/progress` | `VideoService` (`course`, `lesson`, `progress`) |
| `GET /courses/{id}/navigation-tasks`, `/navigation-tasks/{id}`, `POST …/parts/{partId}` | `TrainerService` (`navigationTasks`, `navigationTask`, `navigationAnswer`), `Charts` |
| `GET/PUT /courses/{id}/praxis[/{cardId}]` | `TrainerService` (`praxis`, `praxisStatus`) |
| `GET /courses/{id}/radio` | `RadioService::overview` (Texte, Vokabeln, Funkpraxis, Simulator-Zähler), `RadioPracticeService::catalogsFor`, `DeviceSimService::available/premium` |
| `GET /courses/{id}/radio/spelling` | `RadioService::spelling` |
| `GET /courses/{id}/radio/texts/{textId}`, `POST …/evaluate`, `PUT …/done` | `RadioService` (`text`, `evaluate`, `setDone`) |
| `GET /courses/{id}/radio/vocabulary`, `POST …/vocabulary/{cardId}/answers` | `RadioService` (`vocabulary`, `vocabularyAnswer`) |
| `GET /courses/{id}/radio/practice[/{scenarioId}]`, `POST …/evaluate`, `PUT …/done` | `RadioPracticeService` (`overview`, `show`, `evaluate`, `setDone`) |
| `GET /courses/{id}/radio/samples` | `RadioPracticeService::samples` |
| `POST /coupons/redeem` | `GroupService::join` (Beitrittscodes `g…` und persönliche Einladungen `i…` von Gruppenlizenzen, wie Web `groups/join`); seit 1.11.0 Kursgutscheine über `Extensions\Vouchers` (Filter `bootspruefung_voucher_redeem`, interne 0-€-Bestellung) |
| `GET /groups/join/{token}` (1.7.0) | `GroupService::describeToken`, `GroupService::resolve` (Vorschau wie Web `GET groups/join/{token}`) |
| `GET /me/groups`, `GET /me/groups/{id}` (1.7.0) | `GroupService::forOwner`, `find`, `membersOf`, `freeSeats`, `inviteBudget`, `inviteCutoff`/`inviteExpiresAt`, `joinUrl` (wie Web `GET me/groups`) |
| `POST /me/groups/{id}/invites` (1.7.0) | `GroupService::invite` (gleiche Prüfung, Tageslimit, Einladungsmail wie Web `me/groups/{id}/invite`) |
| `DELETE /me/groups/{id}/members/{memberId}` (1.7.0) | `GroupService::removeMember` (wie Web) |
| `POST /me/groups/{id}/join-code` (1.7.0) | `GroupService::regenerateToken` (wie Web `me/groups/{id}/token`) |
| `GET /tips` (1.7.0) | `Tips::groups` (gleiche Daten wie Web `GET tips` = `Tips::payload`) |
| `GET /courses/{id}/faqs` (1.10.0) | `CustomerFaq::isCustomer` (`AccessService::full`, Fragenkatalog), `CustomerFaqRepository::forCourse` (gleiche Daten wie Web `GET courses/{code}/customer-faqs`), `TipsController::absoluteLinks/plainText/links`; `faq_count` im `CourseSummary`: `CustomerFaqRepository::count` |
| `POST /profile/export`, `GET /profile/export/{id}[/download]` | asynchron: Action Scheduler (`as_enqueue_async_action`) bzw. `wp_schedule_single_event`, Hook `bp_mobile_export_generate` → `PrivacyController::generate`; alle WordPress-Datenexporte (`PersonalData` des Plugins, WordPress-Konto, WooCommerce) |
| `POST /me/deletion-request` | `wp_create_user_request()`, `wp_send_user_request()` (mit App-Kennungen: Link über `AppLinks`) |
| `POST /me/deletion-request/confirm` | `wp_validate_user_request_key()`, Aktion `user_request_action_confirmed` (wie `wp-login.php?action=confirmaction`) |
| `GET /files/{id}` | `ProtectedFiles::requirement`, `DownloadService::allowed`, Range (`FileController::parseRange`) |
| `GET /health` | – (Server-Prüfung) |
| außerhalb der API: `/.well-known/apple-app-site-association`, `/.well-known/assetlinks.json`, `/app/passwort/`, `/app/konto-loeschen/` | `AppLinks` (früher `parse_request`-Handler); `/bootsscheine/gruppe/<token>/` steht seit 1.7.0 nur in der `apple-app-site-association`, die Seite selbst bleibt der Web-Lernbereich (`Frontend\Router`) |

---

## 2. Einrichtung

1. Plugin aktualisieren – die Migration legt die Tabellen an (Systemstatus zeigt Schema 1.13.0).
2. **Bootsprüfung → Mobile App** (nur Administratoren):
   - *App-Schnittstelle aktiv* (Standard: an). Aus = alle App-Anfragen erhalten 503 `MOBILE_API_DISABLED`; der Web-Lernbereich ist nicht betroffen.
   - *Tenant-Slug*: Kennung des Shops für die App. Leer = aus dem Markennamen (Einstellungen → Markenname; leer = Name der Website, erst ohne beides der Platzhalter „XXXX“ → `xxxx`). **Vor dem App-Start festlegen und danach nicht mehr ändern.**
   - *Markenfarbe*, *Support-E-Mail* (Standard: Absender von WooCommerce bzw. Admin-E-Mail). Logo = Website-Icon bzw. Theme-Logo.
   - *Vertrauenswürdige Proxys* (seit 1.4.0 **Standard: keiner**): IPs/CIDRs der Reverse-Proxys,
     Load-Balancer oder CDNs direkt vor WordPress. Nur von diesen wird `X-Forwarded-For` übernommen;
     ohne Eintrag zählt allein `REMOTE_ADDR` (auch Loopback/private Netze gelten nicht mehr automatisch).
     Steht ein Proxy davor und ist nichts eingetragen, teilen sich alle Kunden die Login-Limits der
     Proxy-IP. Zusätzlich Filter `bootspruefung_mobile_trusted_proxies` (Liste von IPs/CIDRs).
   - *Anmeldung in der Webansicht* (Standard: erlaubt): `POST /auth/web-session` (Abschnitt 3, Punkt 11). Aus = 403 `WEB_SESSION_DISABLED`, offene Codes werden nicht mehr eingelöst.
   - *Reset-Link öffnet die App* (Standard: leer = aus): **iOS-App-IDs** (je Zeile `TEAMID.bundle.id`,
     z. B. `ABCDE12345.at.beispiel.bootsschule` – Team-ID aus dem Apple-Developer-Konto + Bundle-ID der App),
     **Android-Paketname** (z. B. `at.beispiel.bootsschule`) und **SHA-256-Fingerabdrücke** des
     Signaturzertifikats (je Zeile `AA:BB:…`, 32 Bytes; bei Play App Signing der Schlüssel aus der Play
     Console „App-Integrität → App-Signatur“, ggf. zusätzlich Upload-/Testschlüssel). Alle Werte liefert das
     App-Team. Ungültige Einträge werden beim Speichern verworfen. Seit 1.4.0 gilt das auch für den
     Bestätigungslink der Kontolöschung (`/app/konto-loeschen/`), seit 1.7.0 für Einladungs- und
     Beitrittslinks von Gruppenlizenzen (`/bootsscheine/gruppe/<token>/`, ohne App wie bisher die
     Web-Seite „Gruppe beitreten“).
   - *Server prüfen*: testet, ob der Authorization-Header bei PHP ankommt.
   - *App-Sitzungen eines Kunden widerrufen* (E-Mail oder Benutzername).
3. Kunden brauchen kein neues Konto: Anmeldung mit dem Shop-Konto. Käufe im Shop schalten wie im Web automatisch frei.

### Server-Voraussetzungen

- **HTTPS.** Läuft die Website unter `https://`, lehnt die API unverschlüsselte Anfragen ab
  (403 `HTTPS_REQUIRED`). Hinter einem TLS-Proxy muss `X-Forwarded-Proto: https` ankommen
  (bzw. WordPress `is_ssl()` wahr sein).
- **Authorization-Header muss PHP erreichen.** Prüfen mit
  `curl -H 'Authorization: Bearer test-0000000000000000000' https://<shop>/wp-json/boatingschool/v1/health`
  → `authorization_header_received: true`. Sonst:
  - Apache (CGI/FastCGI): in `.htaccess` vor den WordPress-Regeln
    `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` (Standard-`.htaccess` von WordPress enthält das) oder `CGIPassAuth On`.
  - nginx + PHP-FPM: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`
  - Die API liest zusätzlich `REDIRECT_HTTP_AUTHORIZATION` und `getallheaders()`.
- **Reverse-Proxy (seit 1.4.0 geändert):** `X-Forwarded-For` wird **nur** von eingetragenen Proxys
  übernommen (Einstellung *Vertrauenswürdige Proxys* oder Filter `bootspruefung_mobile_trusted_proxies`),
  ausgewertet von rechts (der erste nicht vertrauenswürdige Eintrag ist der Client). Standard: keiner –
  bis 1.3.x galten Loopback und private Netze automatisch, damit konnte jeder Dienst im selben Netz die
  Client-IP vorgeben. Bei CDNs mit eigenem Header (z. B. `CF-Connecting-IP`) zusätzlich den Filter
  `bootspruefung_mobile_client_ip` nutzen.
  **Docker-Staging (Caddy davor):** WordPress sieht als `REMOTE_ADDR` die Adresse des Caddy-Containers.
  Dort das Docker-Netz des Compose-Projekts eintragen (Subnetz ermitteln mit
  `docker network inspect <projekt>_default --format '{{(index .IPAM.Config 0).Subnet}}'`, z. B.
  `172.18.0.0/16`), sonst gelten die Login-Limits für alle Testkunden gemeinsam. Siehe `staging/README.md`.
- **Keine Zwischenspeicherung** von `/wp-json/boatingschool/*` in Page-Cache/CDN (die API sendet
  `Cache-Control: private, no-store`). Sicherheits-Plugins/WAF, die die REST-API für Gäste sperren,
  müssen `/wp-json/boatingschool/v1/auth/*` und `/health` zulassen.
- **WP-Cron** (`bp_learning_hourly`) räumt abgelaufene Sitzungen, Idempotenz-Einträge, Exporte,
  Web-Anmeldecodes und Rate-Limit-Fenster auf. Datenexporte werden seit 1.4.0 im Hintergrund erzeugt:
  mit WooCommerce über den **Action Scheduler** (Gruppe `bootspruefung-learning`), sonst als
  WP-Cron-Einzelereignis `bp_mobile_export_generate`. Läuft beides nicht zuverlässig (WP-Cron ohne
  Besucher bzw. `DISABLE_WP_CRON` ohne System-Cron), erzeugt die Statusabfrage der App den Export nach
  30 Sekunden selbst.
- **Universal Links/App Links:** `https://<shop>/.well-known/apple-app-site-association` und
  `/.well-known/assetlinks.json` müssen **PHP/WordPress erreichen** (keine statischen Dateien gleichen
  Namens, keine Server-Regel, die Punkt-Verzeichnisse sperrt – bei nginx z. B. `location ~ /\.` um eine
  Ausnahme `location ^~ /.well-known/ { try_files $uri /index.php?$args; }` ergänzen), über **HTTPS mit
  gültigem Zertifikat**, **ohne Weiterleitung** und an der **Domain-Wurzel** (WordPress in einem
  Unterverzeichnis reicht nicht; `www.` und ohne `www.` sind für Apple verschiedene Domains – die
  Domain verwenden, auf die der Mail-Link zeigt). Apple lädt die Datei über sein CDN; Änderungen
  wirken auf Geräten erst nach Stunden. Ohne Einträge antworten beide Adressen mit 404.
  **Staging (Docker):** Caddy reicht alle Pfade an den WordPress-Container weiter
  (`reverse_proxy wordpress:80`), dort leitet die Standard-`.htaccess` von WordPress nicht vorhandene
  Dateien an `index.php` – `/.well-known/` erreicht also PHP. Für echte Universal Links braucht
  Staging eine öffentlich erreichbare Domain mit gültigem Zertifikat.
- **Web-Anmeldecode:** URLs mit `?bp_app_login=` dürfen nicht aus einem Page-Cache/CDN beantwortet
  werden (Abfrageparameter umgehen üblicherweise den Cache; sonst Ausnahme eintragen). Der Header
  `X-Boatingschool-Web-Verifier` muss PHP erreichen (Proxys/WAF dürfen ihn nicht entfernen).
- **Downloads (`/files/{id}`):** Range-Anfragen beantwortet PHP selbst (206/416); vorgeschaltete
  Caches/Proxys dürfen `Range`/`Content-Range` nicht verändern.
- PHP ≥ 8.1, wie das Plugin.

---

### Export der Zugangs- und Kursdateien für das Laravel-Original

Die Zugangsregeln und geschützten Kursdateien können mit WP-CLI exportiert werden.
Der Schul-Slug muss bereits im Laravel-Original angelegt sein; Export und Import
werden je Zielmandant ausgeführt. Für FAQ-Daten steht derselbe JSON-Export bereit.

```sh
wp bootspruefung export access-rules <tenant-slug> --output=/tmp/mobile-access.json
wp bootspruefung export entitlements <tenant-slug> --output=/tmp/mobile-entitlements.json
wp bootspruefung export course-files <tenant-slug> --media-root=/tmp/mobile-media --output=/tmp/mobile-files.json
wp bootspruefung export customer-faqs --output=/tmp/mobile-faqs.json
```

Der Access-Export enthält auch den WP-Standard `default_access` und die
Zeitfenster der Regeln. Der Entitlement-Export enthält aktuell gültige Zugänge
und E-Mail-Adressen bestehender WP-Nutzer; er enthält keine Passwörter und muss
als personenbezogene Datei geschützt übertragen werden. Geräte-Simulator-Freigaben
sind WP-seitige Einstellungen und werden nicht als Lernzugang exportiert.
Video-Drip-Regeln enthalten Kapiteltitel und Reihenfolge,
damit der Laravel-Importer die WP-Quell-ID dem passenden nativen Videokapitel
zuordnet. Der Datei-Export kopiert geschützte Anhänge ins Staging-Verzeichnis
und erhält Download-, Abschnitts- und Seekarten-Zuordnungen. Vorhandene Staging-Dateien
werden bei jedem Export aktualisiert; jede Dateizuordnung enthält eine SHA-256-Prüfsumme,
die der Original-Importer vor und nach dem Kopieren prüft. Die exportierten JSON-Dateien und Medien sind Kundendaten bzw. lizenzierte Kursinhalte; nur in
einen geschützten Übertragungsweg legen.

---

## 3. Ablauf für App-Entwickler

1. **Start:** optional `GET /health` (Wartung? `status: disabled`).
2. **Anmelden:** `POST /auth/login` mit `email` (oder Benutzername), `password`, `device_name`,
   `device_platform`, `app_version`. Antwort: Token-Paar, `user`, `memberships`. Tokens nur im
   Keychain/Keystore speichern. Es gibt genau eine Mitgliedschaft (ein Shop = eine Bootsschule).
3. **Jede weitere Anfrage:** `Authorization: Bearer <access_token>` und – außer bei `/auth/*`, `/me*`
   (auch `/me/groups*`), `/files/*`, Export-Download – `X-Tenant-Slug: <memberships[0].tenant_slug>`
   (also auch bei `/tips` und `/groups/join/{token}`).
4. **401 `UNAUTHENTICATED`:** genau einmal `POST /auth/refresh`, danach Anfrage wiederholen. Refresh
   **serialisieren** (nur ein Aufruf gleichzeitig) und neue Tokens **sofort** im Keychain/Keystore
   speichern. **Antwort verloren (Timeout, Funkloch, seit 1.4.0):** denselben Refresh-Aufruf mit dem
   bisherigen Refresh-Token innerhalb von 60 Sekunden wiederholen – der Server liefert ein neues,
   gültiges Paar derselben Sitzung (das verlorene wird ungültig; gültig ist immer das zuletzt
   erhaltene Paar). Nach 60 Sekunden beendet die Wiederverwendung die Sitzung
   (`REFRESH_TOKEN_REUSED`). 409 `REFRESH_TOKEN_ROTATED` (nur noch bei extremen Wettläufen) → einmal
   wiederholen. 401 beim Refresh (`UNAUTHENTICATED`/`REFRESH_TOKEN_REUSED`) → Tokens löschen, Login
   zeigen. Access-Token: 15 Minuten; Refresh: 30 Tage Inaktivität, max. 90 Tage.
5. **Kurse:** `GET /courses` → `access.status` (`active`, `limited` = Schnupper-Umfang, `pending` =
   Zahlung offen, `expired`, `suspended`, `revoked`, `not_granted`), `features` je Bereich,
   `webshop_url` (im Systembrowser öffnen; nach dem Kauf mit demselben Konto ist der Zugang sofort da –
   Kursliste neu laden). Details mit Kaufangeboten je Bereich: `GET /courses/{id}`.
   **Kaufstatus (seit 1.11.0):** `pending`, `suspended` und `expired` haben Vorrang vor `limited`; der
   kostenlose Teilumfang bleibt nutzbar (erkennbar an `trial: true`, `limits` und `features`).
   - `pending` → Screen 88 „Zahlung noch offen“: Bestellung `access.order_number`, Link
     `access.order_url` (Bestellung in „Mein Konto“ mit den Zahlungsdaten, im Systembrowser öffnen),
     Laufzeit `access.duration_days` ab Zahlungseingang; `webshop_url` ist `null` (kein Doppelkauf).
     „Kurse erneut laden“ = `GET /courses`.
   - `expired` → Screen 25 „Kurszugang abgelaufen“: Ende in `access.valid_until`, „Kurszugang
     verlängern“ über `access.renew_url` (gekaufte Stufe; auch `webshop_url`). Der Lernstand bleibt.
   - `suspended` → Screen 89 „Zugang ausgesetzt“ (z. B. Zahlungsstreit/Rückbuchung): Kontakt über
     `access.contact_url` (bzw. `legal.contact` aus `/health`); `webshop_url` ist `null`.
6. **Smarttrainer:** `GET /courses/{id}/trainer/next?mode=…` → Frage + `answer_token` (2 h gültig).
   Antwort: `POST /courses/{id}/attempts` mit `answer_token`, `selected_answer_ids` und Header
   `Idempotency-Key` (UUID, pro Antwort neu, bei Netzwiederholung gleich). Offene Fragen
   (`answer_selection: open`): erst `POST …/trainer/reveal`, dann `known: true|false` senden.
   204 bei `next` = im Modus nichts übrig. Korrektheit nie lokal ableiten. Eine **bestimmte Frage**
   üben (seit 1.7.0): `trainer/next?mode=question&question_id=…` (siehe 20).
7. **Prüfung:** `GET /courses/{id}/exams` → `POST /courses/{id}/exams` (`paper_id` oder ohne für die
   Zufallssimulation; laufende Prüfung wird fortgesetzt) → `PUT /exams/{examId}/answers/{position}`
   je Frage (`questions[].id` ist die Position) → `POST /exams/{examId}/submit`. Timer nach
   `expires_at`/`server_time`; nach App-Neustart `GET /exams/{examId}`. 410 `EXAM_EXPIRED` =
   Zeit abgelaufen, Ergebnis über `GET /exams/{examId}/result`. Fehler üben:
   `trainer/next?mode=exam_mistakes&exam_id=…`.
8. **Videos:** `GET /courses/{id}/videos`, `GET /videos/{lessonId}` (Platzhalter: `kind: placeholder`,
   `url: null`, Kachel „Video folgt“), Fortschritt `PUT /videos/{lessonId}/progress`. Videoquiz über
   `POST /courses/{id}/attempts` mit dem `answer_token` der Quizfrage.
9. **Navigation:** Liste, Aufgabe (Lösungen erst nach Antwort/Aufdecken), `POST
   /navigation-tasks/{id}/parts/{partId}` mit eigener Antwort und/oder Einschätzung
   (`correct|partly|wrong`). Seekarten und Kurs-PDFs: URL aus der Antwort mit Authorization-Header laden.

   **Automatische Prüfung der eigenen Werte (seit 1.4.0, Navigation):** Teilaufgaben mit prüfbaren
   Lösungswerten tragen `auto_check: true` (78 von 135: Kurse, Peilungen, Besteckversetzung,
   Distanzen, Positionen, Uhrzeiten, Fahrt, Dauer; nicht bei „Siehe Karte“ und Beschreibungen). Nach
   `POST …/parts/{partId}` mit `answer` enthält die Antwort `check`: `status` (`within` | `deviates` |
   `unrecognized`), `message` und je Wert `label`, `given`, `ok`, `deviation`, `tolerance`,
   `tolerance_unit`, `expected` (Schema `NavigationCheck`). `GET /navigation-tasks/{id}` liefert
   `check` je Teilaufgabe, sobald die Lösung aufgedeckt ist. Das Ergebnis ist nur eine Hilfe: Die App
   zeigt es vor der Selbsteinschätzung an (als Text, nicht nur farbig); maßgeblich bleibt
   `self_assessment`, das wie bisher gespeichert wird. Teilaufgaben ohne Werte (`auto_check: false`,
   `check: null`) verhalten sich wie bisher.
10. **Funktraining (SRC/UBI):** nur bei Fähigkeit `funk`. `GET /courses/{id}/radio` (Übersicht),
    Texte: `GET /radio/texts/{textId}[?direction=de-en|en-de]` → Eingabe → `POST …/evaluate`
    (Prozent, fehlende Wörter, Musterlösung) → `PUT …/done`. Bei Übersetzungen hält der Server
    die Lösung (Zielsprache) bis zur ersten Auswertung in dieser Richtung zurück
    (`solution_hidden: true`, Zielsprache null). Vokabeln: `GET /radio/vocabulary[?catalog=SRC][&category=…]`
    (ohne `catalog` gilt der einzige Katalog des Kurses; bei SRC und UBI → 422),
    `POST /radio/vocabulary/{cardId}/answers` mit `known` und der angezeigten `streak`.
    Funkpraxis: `GET /radio/practice/{scenarioId}` → `POST …/evaluate` mit `answers`
    (Feldname → Text) → `PUT …/done`. Dazu `/radio/spelling` und `/radio/samples`. Gesperrte
    Übungen (Teilumfang) kommen mit `locked: true` und liefern beim Öffnen 403 `CONTENT_LOCKED`.
11. **Funkgerät-Simulator:** keine Bedien-API. `device_simulator` (Kursdetails und Funk-Übersicht)
    nennt `available`, `premium`, `web_url`. Handy-App: „Am Tablet oder im Browser öffnen“, `web_url`
    im Systembrowser (Anmeldung dort mit demselben Konto). **Tablet-App mit eingebetteter Webansicht
    (seit 1.3.0):** unmittelbar vor dem Öffnen `POST /auth/web-session` mit
    `{"redirect": "<Pfad von web_url>"}` (z. B. `/bootsscheine/src/funk/geraet/`) → `url` sofort in der
    WebView laden (60 s gültig, einmal verwendbar; nicht speichern/protokollieren) – **seit 1.4.0 mit
    dem Header `verifier_header: verifier` aus derselben Antwort** (Pflicht, Schutz vor Login-CSRF):

    ```kotlin
    // Android
    webView.loadUrl(data.url, mapOf(data.verifier_header to data.verifier))
    ```
    ```swift
    // iOS (WKWebView)
    var request = URLRequest(url: URL(string: data.url)!)
    request.setValue(data.verifier, forHTTPHeaderField: data.verifierHeader)
    webView.load(request)
    ```
    ```dart
    // Flutter (webview_flutter)
    controller.loadRequest(Uri.parse(data.url), headers: {data.verifierHeader: data.verifier});
    ```

    Nur der erste Aufruf braucht den Header (die Weiterleitung danach nicht). Ohne/mit falschem Header
    wird niemand angemeldet (Code verbraucht, Anmeldeseite) – so kann ein weitergeleiteter Link
    niemanden ins fremde Konto bringen. Ist in der WebView schon ein **anderes** WordPress-Konto
    angemeldet, schaltet die Website nicht um (Anmeldeseite, bestehende Anmeldung bleibt): Cookies der
    WebView beim Abmelden und beim Kontowechsel in der App löschen. Die Website meldet
    das Konto an (Sitzungscookie in der WebView, ohne „angemeldet bleiben“) und leitet aufs Ziel
    ohne Code weiter. Für jedes erneute Öffnen eine neue URL holen; abgelaufene Codes landen auf der
    Anmeldeseite. Das Einbetten selbst (WebView, Cookie-Speicher, Zurück-Navigation, Ausrichtung)
    ist Sache der App. Die Simulator-Seite setzt kein `X-Frame-Options`/`frame-ancestors` (betrifft
    ohnehin nur iframes, nicht WebViews); WordPress sendet `X-Frame-Options: SAMEORIGIN` nur auf
    Anmelde- und Adminseiten. Abmelden in der App beendet die Web-Sitzung **nicht** – die App sollte
    beim Logout die Cookies der WebView löschen. Konten mit Verwaltungsrechten erhalten keine
    Web-Sitzung (403 `WEB_SESSION_NOT_ALLOWED`).
12. **Prüfungsanmeldung:** `GET /courses/{id}/exam-registration` (nur lesen: Gebühr, Fristen,
    Unterlagen, Prüfungsformen, FAQ, Prüfungsausschüsse mit Kontaktdaten) und `web_url` zur
    Web-Seite. Angemeldet wird nicht über die App, sondern direkt beim Prüfungsausschuss.
    `exam_registration.available` in den Kursdetails zeigt, ob es die Seite gibt.
13. **Konto:** Sitzungen anzeigen/beenden, `POST /auth/logout`. **Datenexport (seit 1.4.0
    asynchron):** `POST /profile/export` → 202 `status: pending` → `GET /profile/export/{id}` abfragen
    (alle 2–5 s mit Rückoff, höchstens einige Minuten) bis `ready` (dann `download_url`, mit Token,
    24 h gültig) oder `failed` (neu anfordern); Download vor Fertigstellung → 409 `EXPORT_NOT_READY`.
    **Kontolöschung:** `POST /me/deletion-request` (Bestätigung per E-Mail, siehe 16).
14. **App-Einstellungen geräteübergreifend (seit 1.3.0):** `GET /me/settings` → `{settings, version,
    updated_at}`; ändern mit `PUT /me/settings` `{"settings": {…}}` (ersetzt alles) und
    `If-Match: "<version>"` (oder `expected_version`). 409 `VERSION_CONFLICT` → `details.current`
    übernehmen/zusammenführen und erneut senden. Objekt ≤ 8 KB, ≤ 4 Ebenen, Schlüssel
    `^[a-z0-9_.-]{1,64}$`. Nur für Einstellungen, die auf allen Geräten gleich sein sollen (z. B.
    Tagesziel, Sprachausgabe, Design); rein lokale Einstellungen (Benachrichtigungen, Cache,
    Geräte-Lautstärke) bleiben auf dem Gerät und brauchen diesen Endpunkt nicht.
15. **Passwort-Reset in der App (seit 1.3.0, wenn der Shop App-Kennungen eingetragen hat):** Der Link
    der aus der App angeforderten Mail lautet `https://<shop>/app/passwort/?key=…&login=…` (ggf. mit
    `&wp_lang=…`). App-Konfiguration: iOS *Associated Domains* `applinks:<shop-domain>`; Android
    Intent-Filter mit `android:autoVerify="true"`, `https`, Host `<shop-domain>`, `pathPrefix
    /app/passwort/`. Die App liest `key` und `login`, fragt das neue Passwort ab und ruft
    `POST /auth/password/reset` mit `login` (aus dem Link, WordPress-Benutzername) bzw. `email` und
    `token` = `key` auf. Ohne App öffnet der Browser die
    Adresse und die Website leitet auf die normale Seite zum Passwortsetzen weiter.
16. **Kontolöschung in der App bestätigen (seit 1.4.0, wenn der Shop App-Kennungen eingetragen hat):**
    Der Link der Bestätigungsmail (nach `POST /me/deletion-request`) lautet
    `https://<shop>/app/konto-loeschen/?request_id=…&confirm_key=…`. App-Konfiguration wie bei 15,
    zusätzlicher Pfad `/app/konto-loeschen/` (Android `pathPrefix`; iOS steht er in der
    `apple-app-site-association`). Die App zeigt eine Sicherheitsabfrage („Konto wirklich löschen?“)
    und ruft `POST /me/deletion-request/confirm` mit `request_id` und `confirm_key` auf – ohne
    Anmeldung (der Link kann auch auf einem Gerät ohne angemeldete App geöffnet werden). 200 mit
    `status: completed` = Konto gelöscht (Lernstand, Sitzungen, Konto; Bestellungen bleiben anonymisiert),
    alle Tokens sind ungültig → abmelden und lokale Daten verwerfen; 200 mit `status: confirmed` =
    bestätigt, die Bootsschule bearbeitet den Antrag von Hand (Einstellung aus oder Konto mit
    Verwaltungsrechten); 422 `DELETION_CONFIRMATION_INVALID` =
    ungültig, abgelaufen (24 h) oder schon bestätigt → ggf. neu beantragen. Ohne App öffnet der
    Browser die Adresse und die Website leitet auf die WordPress-Bestätigung weiter.
17. **Tipps & Tricks (seit 1.7.0):** `GET /tips` (mit `X-Tenant-Slug`, ohne Kurszugang) liefert dieselben
    Tipps wie `/bootsscheine/tipps/`: `data[]` = Kategorien (`id` – null für „Allgemein“ –, `slug`,
    `name`) mit `tips[]` (`id`, `title`, `html`, `text`, `links`). `html` ist WordPress-Beitrags-HTML
    nach `wp_kses_post` (wie im Web: `p`, `br`, `strong`, `em`, `a`, Listen, Zwischenüberschriften,
    ggf. Bilder/Tabellen; relative Links sind absolut). Ohne HTML-Darstellung `text` anzeigen
    (Absätze durch Leerzeile, Listenpunkte „• “) und `links` als Schaltflächen. Links auf die Website
    im Systembrowser öffnen (bzw. als App-Link, falls die App den Pfad behandelt). Reihenfolge der
    Kategorien und Tipps nicht umsortieren.
18. **Gruppen verwalten (seit 1.7.0, nur für die besitzende Person einer Gruppenlizenz – wie im Web):**
    `GET /me/groups` (Liste, `meta.seats_total/seats_used`; leer = keine eigene Gruppe, dann den
    Menüpunkt ausblenden), `GET /me/groups/{groupId}`. Je Gruppe: `status` (`active`, `suspended`,
    `revoked`), `valid_until`, `seats` (`total`, `used` = aktive Mitglieder + offene Einladungen,
    `free`), `scopes` (Kurs-ID, Fähigkeit), `join_code`/`join_url` (nur bei aktiver Gruppe; zum Teilen,
    z. B. Share-Sheet), `invites_remaining_today`, `members[]` mit `status` `active` | `invited` |
    `expired` (Einladung nach 30 Tagen nicht angenommen, belegt keinen Platz), `invite_expires_at`,
    `is_me`, `removable`. **Einladen:** `POST /me/groups/{groupId}/invites` mit `{"emails": [...]}`
    (bis 200) bzw. `{"email": "…"}` und optional `Idempotency-Key`; Antwort `invited`, `skipped`
    (ungültig, schon eingeladen, keine Plätze, Tageslimit – wie im Web ohne Einzelgrund) und die
    aktualisierte `group`. Die Mail mit persönlichem Link verschickt der Server (wie im Web).
    **Platz freigeben:** `DELETE /me/groups/{groupId}/members/{memberId}` (optional `reason`) –
    Rückfrage in der App („Zugang von … wirklich beenden?“); widerruft die Platz-Zugänge sofort.
    **Neuer Link:** `POST /me/groups/{groupId}/join-code` – der alte Link/Code ist sofort ungültig
    (Rückfrage!), persönliche Einladungen bleiben gültig. Ausgesetzte/widerrufene Gruppe → Einladen
    und neuer Link 409 `GROUP_NOT_ACTIVE`. Fremde Gruppen → 404.
19. **Einladungslink öffnet die App (seit 1.7.0, wenn der Shop App-Kennungen eingetragen hat):**
    Einladungsmails und geteilte Beitrittslinks lauten `https://<shop>/bootsscheine/gruppe/<token>/`
    (`token` = `g` + 32 Hex für den Gruppenlink, `i` + 32 Hex für eine persönliche Einladung). Der Pfad
    steht in der `apple-app-site-association` (`/bootsscheine/gruppe/*`); Android: Intent-Filter
    zusätzlich mit `pathPrefix /bootsscheine/gruppe/` (`assetlinks.json` gilt fürs ganze Paket,
    dort ändert sich nichts). Ablauf in der App:
    1. Token aus dem Pfad lesen (Regex `/gruppe/([gi][0-9a-f]{32})/?`).
    2. **Nicht angemeldet:** Token merken (nur im Speicher bzw. sicherem Speicher, nicht protokollieren),
       Anmeldung bzw. Registrierung zeigen (Registrierung über die Website im Systembrowser – danach
       anmelden), danach mit dem gemerkten Token weiter.
    3. `GET /groups/join/{token}` → Vorschau (Name, verwaltende Person, Kurse, `free_seats`,
       `personal`, `already_member`, `joinable`). 422 `COUPON_INVALID` = ungültig, abgelaufen, bereits
       angenommen oder ersetzt → Hinweis „Bitte neuen Link anfordern“.
    4. „Beitreten“ (nur bei `joinable`) → `POST /coupons/redeem` mit `{"code": "<token>"}` (der ganze
       Link geht auch) und `Idempotency-Key` → 200 mit `courses` → Kursliste neu laden.
       Schon Mitglied → ebenfalls 200.
    Ohne App bzw. ohne Eintrag öffnet der Browser die Seite des Web-Lernbereichs („Gruppe beitreten“)
    wie bisher – der Pfad wird vom Server nicht umgeleitet. Hinweis: Eine persönliche Einladung ist an
    keine E-Mail-Adresse gebunden (wie im Web) – wer den Link hat, kann sie annehmen.
20. **Bestimmte Frage gezielt üben (seit 1.7.0):** `GET /courses/{id}/trainer/next?mode=question&question_id=<id>`
    – z. B. „Diese Frage üben“ in Favoriten, Fehlerliste oder Prüfungsauswertung (`question_id` wie
    `question.id` bzw. `question_id` dort). Antwort wie jede `trainer/next`-Antwort (`mode: question`,
    frisches `answer_token`); antworten wie gewohnt über `POST /courses/{id}/attempts` – zählt in
    denselben Lernstand (Serie, Fortschritt, Fehlerliste) wie Web und Smarttrainer. Die Frage kommt
    auch direkt nach einer Antwort erneut (für „Nochmal“). Fehler: 404 = nicht im Kurs bzw. nicht
    (mehr) verfügbar; 403 `CONTENT_LOCKED` mit `details.reason` = `partial_access` (außerhalb des
    kostenlosen Teilumfangs, `details.webshop_url`), `scheduled_release` (`details.unlock_at`) oder
    `running_exam` (erst Prüfung abgeben); 403 `ENTITLEMENT_*` ohne Smarttrainer-Zugang. Bewusst
    eigener Modus (statt `question_id` an jedem Modus): `question_id` ohne `mode=question` → 422.
21. **Fragen & Antworten zum Kurs (Kunden-FAQ, seit 1.10.0):** `GET /courses/{id}/faqs` liefert dieselben
    veröffentlichten Kunden-FAQs wie der Abschnitt „Fragen & Antworten zu deinem Kurs“ im Kurs-Hub des
    Web-Lernbereichs. Nur für Kunden des Kurses (voller Zugang zum Fragenkatalog: Kauf, manuelle Freigabe,
    Gruppe oder „Zugang ohne Shop“). Testkurs (kostenloser Teilumfang): 403 `CONTENT_LOCKED` mit
    `details.reason = partial_access` und `details.webshop_url`; ohne Zugang 403 `ENTITLEMENT_REQUIRED`
    (bzw. `_EXPIRED`/`_SUSPENDED`), Meldung „Die Fragen und Antworten stehen Kunden dieses Kurses zur
    Verfügung.“ `data[]` = Scheine (`id`, `key` = Kurscode, `name`, `slug`, `faqs` direkt am Schein,
    `subcategories`) – Kombikurse liefern ihre Teilscheine als getrennte Gruppen (SBF See & Binnen → SBF See,
    SBF Binnen; SRC & UBI → SRC, UBI), Ergänzungen ihren Zielschein (z. B. Binnen → See: SBF See), eine
    Kategorie mit dem Code des Kurses selbst steht davor. `subcategories` ist flach in Anzeigereihenfolge
    (Tiefensuche) mit `parent_id` und `depth` (1 = direkt unter dem Schein); Überschriften nach `depth`
    einrücken. Je FAQ `question`, `answer_html` (wp_kses_post, Links absolut), `answer_text` (Klartext wie
    bei den Tipps) und `links`. Leere Unterkategorien fehlen; Reihenfolge nicht ändern. Eine FAQ kann
    mehreren Scheinen zugeordnet sein (gemeinsame FAQ, z. B. „Wie melde ich mich zur Prüfung an?“); in einem
    Kombikurs steht sie trotzdem nur einmal – beim ersten Schein (Reihenfolge von `data[]`), `faq_count` und
    `meta.faqs` zählen sie einmal (Format unverändert). Mehrere Scheine
    mit Scheinnamen als Zwischenüberschrift zeigen, einen einzelnen ohne. `faq_count` in jedem
    `CourseSummary` (0 ohne Kundenzugang) entscheidet, ob die App den Abschnitt bzw. Menüpunkt zeigt –
    dann entfällt der 403-Aufruf für Nicht-Kunden.

**Prüfung – Markieren (seit 1.3.0):** `PUT /exams/{examId}/answers/{position}/flag` mit
`{"flagged": true|false}` (auch `marked_for_review` beim Antworten). `GET /exams/{examId}` liefert
`flagged` je Frage (in `questions[]` und `answers[]`) und `flagged_count` – beim Fortsetzen
wiederherstellen. Nach Abgabe 409, nach Fristende 410. Das Ergebnis zeigt `review[].flagged`.
Der Web-Lernbereich zeigt und setzt dieselben Markierungen (gemeinsame Spalte, auch geräteübergreifend).

**Prüfung und Zugangsende (Entscheidung, dokumentiert in 1.4.0):** App und Web nutzen denselben
`ExamService`. Endet der Kurszugang während einer laufenden Prüfung (Erstattung, Rückbuchung,
Sperre, Ablauf), gilt: weitere Antworten, Markierungen und „Musterantwort aufdecken“ → 403
`CONTENT_LOCKED`; die **Abgabe bleibt möglich** und wertet die bis dahin gespeicherten Antworten,
das Ergebnis bleibt abrufbar; eine **neue Prüfung** lässt sich nicht starten (403 `ENTITLEMENT_*`).
Die App bietet in diesem Fall „Abgeben“ an.

Allgemein: Erfolg = `{data, meta?}`; IDs sind Strings; Zeitstempel UTC (RFC 3339);
Fehlertexte sind deutsch (Du-Form), die App sollte trotzdem anhand von `code` reagieren und
`request_id` für den Support protokollieren.

---

## 4. Fehlercodes

Fehlerobjekt (`application/problem+json`):
`{type, title, status, detail, code, message, request_id, errors?, details?, retry_after?}`
(`message` = `detail`; `errors` = Feldfehler; `details` z. B. bei Zugangsfehlern
`course_id`, `capability`, `access_status`, `webshop_url`; `retry_after` auch als Header `Retry-After`).

| Code | Status | Bedeutung / App-Verhalten |
| --- | --- | --- |
| `UNAUTHENTICATED` | 401 | Kein/abgelaufenes Token bzw. Login falsch → einmal refreshen, sonst Login |
| `REFRESH_TOKEN_REUSED` | 401 | Rotiertes Refresh-Token erneut benutzt → Sitzung widerrufen, neu anmelden |
| `REFRESH_TOKEN_ROTATED` | 409 | Seltener Wettlauf beim Refresh → einmal wiederholen (bis 1.3.x: paralleler Refresh) |
| `PASSWORD_RESET_INVALID_OR_EXPIRED` | 422 | Reset-Link ungültig/abgelaufen → neuen anfordern |
| `TENANT_MEMBERSHIP_REQUIRED` | 403 | Falscher `X-Tenant-Slug` |
| `ENTITLEMENT_REQUIRED` | 403 | Bereich nicht freigeschaltet → `details.webshop_url` anbieten |
| `ENTITLEMENT_EXPIRED` | 403 | Zugang abgelaufen → Verlängerung im Shop |
| `ENTITLEMENT_SUSPENDED` | 403 | Zugang ausgesetzt (z. B. Zahlungsstreit) → Support |
| `CONTENT_LOCKED` | 403 | Trotz Zugang gesperrt: Schnupper-Umfang oder zeitgesteuerte Freigabe (Meldung nennt Datum) |
| `RESOURCE_NOT_FOUND` | 404 | Unbekannt oder gehört einem anderen Konto |
| `VALIDATION_FAILED` | 422 | Eingabe prüfen (`errors`), auch fachliche Ablehnungen der Services |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Gleicher Key, anderer Inhalt |
| `REQUEST_IN_PROGRESS` | 409 | Gleiche Anfrage läuft noch → kurz warten, wiederholen |
| `QUESTION_TOKEN_EXPIRED` | 409 | Frage neu laden |
| `EXAM_NOT_STARTABLE` | 409 | Kein Regelwerk / keine freigegebenen Fragen |
| `EXAM_ALREADY_SUBMITTED` | 409 | Prüfung schon abgegeben → Ergebnis laden |
| `EXAM_ABORTED` | 409 | Prüfung wurde abgebrochen (seit 1.11.0) → keine Antworten, kein Ergebnis |
| `EXAM_NOT_SUBMITTED` | 409 | Ergebnis vor Abgabe angefragt |
| `EXAM_EXPIRED` | 410 | Zeit abgelaufen, Prüfung ausgewertet → Ergebnis laden |
| `EXAM_ANSWER_LOCKED` | 409 | Offene Prüfungsfrage: erst Musterantwort aufdecken |
| `COUPON_INVALID` | 422 | Code unbekannt/ungültig (Hinweis: Shop-Käufe brauchen keinen Code) |
| `COUPON_NOT_SUPPORTED` | 422 | WooCommerce-Gutschein (nur beim Kauf im Shop einlösbar) |
| `GROUP_NOT_ACTIVE` | 409 | Gruppe ausgesetzt/widerrufen: Einladen und neuer Beitrittslink nicht möglich (seit 1.7.0) |
| `EXPORT_EXPIRED` | 410 | Export abgelaufen → neu anfordern |
| `EXPORT_NOT_READY` | 409 | Export wird noch erstellt → Status abfragen, `retry_after` beachten (seit 1.4.0) |
| `EXPORT_FAILED` | 410 | Erstellung fehlgeschlagen → neu anfordern (seit 1.4.0) |
| `RANGE_NOT_SATISFIABLE` | 416 | `Range` ungültig/außerhalb der Datei (`Content-Range: bytes */<Größe>`) → ganze Datei laden (seit 1.4.0) |
| `DELETION_CONFIRMATION_INVALID` | 422 | Bestätigungslink ungültig, abgelaufen oder schon benutzt (seit 1.4.0) |
| `VERSION_CONFLICT` | 409 | App-Einstellungen inzwischen geändert → `details.current` übernehmen, erneut senden |
| `WEB_SESSION_DISABLED` | 403 | Anmeldung in der Webansicht im Shop abgeschaltet → im Browser öffnen |
| `WEB_SESSION_NOT_ALLOWED` | 403 | Konto mit Verwaltungsrechten → keine Web-Sitzung über die App |
| `RATE_LIMITED` | 429 | Zu viele Anfragen, `retry_after` beachten |
| `MOBILE_API_DISABLED` | 503 | App vom Shop abgeschaltet → Wartungshinweis |
| `HTTPS_REQUIRED` | 403 | Anfrage ohne TLS |
| `APP_UPDATE_REQUIRED` | 426 | App-Version unter der Mindestversion der Plattform (`details.min_app_version`); App zeigt „Bitte aktualisieren“ mit Link in den Store |
| `METHOD_NOT_ALLOWED` | 405 | Falsche HTTP-Methode |
| `INTERNAL_ERROR` | 500 | Serverfehler → später erneut versuchen |

Limits (seit 1.4.0 atomare Zähler in `bp_mobile_rate_limit`, Fenster ab dem ersten Treffer): Login
5 Fehlversuche je E-Mail + IP, 10 je IP und 30 je E-Mail insgesamt in 15 Min. (Erfolg setzt E-Mail + IP
zurück; dieselben Zähler wie beim Web-Login über wp-login.php bzw. „Mein Konto“, `Security\LoginGuard` –
Fehlversuche im Web zählen also auch für die App und umgekehrt; Benutzername und E-Mail eines Kontos zählen
gemeinsam); Passwort vergessen 10/IP, 3/E-Mail pro Stunde; Refresh 120/IP in 15 Min.; Antworten 60/Min.
(wie Web); Codes 10/Std. (Wiederholungen mit gleichem Idempotency-Key zählen nicht); Export und
Löschantrag 5/Tag; Löschbestätigung 20/IP und 10/Antrag pro Stunde; Web-Anmeldecodes 20 je Konto in
15 Min.; seit 1.7.0 Gruppen-Einladungen 20 Vorgänge je Konto und Stunde (Wiederholungen mit gleichem
Idempotency-Key zählen nicht; zusätzlich wie im Web höchstens 20 Einladungen je Gruppe in 24 h, bei
großen Gruppen 2 × Plätze – darüber `skipped`), Vorschau von Einladungslinks 60 je Konto und Stunde.

Fachfehler der Services (Prüfung beendet/abgelaufen, Musterantwort fehlt, kein Regelwerk, Start läuft,
zu viele Antworten, Frage-Token abgelaufen) bildet die API seit 1.4.0 über stabile Codes ab
(`Support\DomainException::errorCode()`), nicht mehr über den deutschen Meldungstext. Für die App
ändert sich nichts außer: `REQUEST_IN_PROGRESS` beim Prüfungsstart trägt jetzt `retry_after: 2`.

---

## 5. Änderungen gegenüber dem Konzept (und warum)

Pfade, Namespace, Hülle `{data, meta}`, Problem-Format und Statuscodes des Konzept-Vertrags sind
beibehalten, wo es passt. Abweichungen:

| Thema | Konzept | Umsetzung | Grund |
| --- | --- | --- | --- |
| Datenmodell | Konzept-Plugin mit eigenen Beitragstypen, `user_meta`, LearnDash, eigener Prüfungslogik | Nur bestehende Plugin-Tabellen und Services | Gleiche Inhalte, Lernstände und Regeln wie im Web; keine zweite Logik |
| Login | `wp_signon` | `wp_authenticate` | setzt keine Cookies; Sicherheits-Plugins greifen trotzdem |
| Login-Antwort | Plugin: Tokens oben; Vertrag: in `data` | in `data` (+ `expires_in`, `user`, `memberships`) | Vertrag maßgeblich |
| Authentifizierung | Bearer allgemein | Bearer **nur** für `boatingschool/v1`; Cookies dort ignoriert | App-Token kann nicht für `/wp/v2` o. Ä. missbraucht werden; kein CSRF |
| Refresh-Wiederverwendung | Widerruf | Widerruf; seit 1.4.0 liefert das unmittelbar vorherige Token innerhalb 60 s nach der Rotation ein neues Paar (erneute Rotation, verlorenes Paar ungültig); bis 1.3.x 409 ohne Tokens innerhalb 10 s | Verlorene Refresh-Antworten (schlechtes Netz auf dem Boot) sollen nicht abmelden; nach dem Fenster greift der Diebstahlschutz |
| Anmelde-Limit | je IP und je E-Mail | seit 1.4.0: 5 je E-Mail + IP, 10 je IP, 30 je E-Mail; Zähler atomar in der Datenbank; `X-Forwarded-For` nur von eingetragenen Proxys | Kein Aussperren fremder Konten mit 5 Fehlversuchen; parallele Anfragen überholen das Limit nicht; keine gefälschte Client-IP |
| Passwortänderung | Reset widerruft | Jede echte Änderung (Reset, Profil, WooCommerce-Konto, `wp_set_password`) widerruft; reines Neu-Hashen beim Login nicht | Konzept-Vorgabe vollständig |
| Passwort-Reset | min. 12 Zeichen | übernommen (nur in der App) | Vertrag |
| E-Mail-Bestätigung | eingeschränkte Sitzung, `EMAIL_NOT_VERIFIED`, `POST /auth/email/verification-notification` | Konten gelten als bestätigt (`email_verified: true`); der Endpunkt ist entfernt (seit 1.1.0 → 404). Die App bietet **keinen** Bestätigungsablauf an | WooCommerce-Konten haben keine Bestätigung; keine künstliche Sperre |
| Tenant | mehrere Mitgliedschaften | Ein Shop = ein Tenant (Slug einstellbar); Mitglied = jedes angemeldete Kundenkonto | Plattform ist ein Shop |
| IDs | Strings | Strings mit numerischen Werten; `courseId` = `bp_course.id`, Kurscode wird auch akzeptiert | Vorgabe; Code bequem für Tests |
| Zugangsstatus | active/expired/suspended/revoked/not_granted | zusätzlich `limited` (Schnupper-Umfang) und `pending` (Zahlung offen, seit 1.11.0 vor `limited`), dazu `trial`, `purchased`, `limits`, `order_url`, `renew_url` | Plattform kennt Teilumfang und Zahlungsstatus |
| Fähigkeiten | feste Liste | nur was der Kurs enthält (+ `favorites`, `funk`); `features` mit Zugriff je Bereich | Kurse unterscheiden sich stark |
| Shop-Link | Link je Tenant | passendstes WooCommerce-Angebot (`Integration::offers`, günstigstes zuerst); Details: Angebote je gesperrtem Bereich | wie im Web-Lernbereich |
| Smarttrainer-Regel | „3 gespeicherte Versuche → erledigt“ | Regeln des Web-Smarttrainers: gefestigt nach 3 richtigen in Folge mit Abstand; Felder `attempts_completed/attempts_required/attempt_number` entfallen, dafür `progress` (Status, Serie, Versuche) | Gleicher Lernstand Web/App; Regel des Konzepts widerspricht der Plattform |
| Lernstatus | neu/in_bearbeitung/gefestigt | beides: `learning_status` (new/unsure/learning/mastered) und `learning_state` (Konzept-Werte) | Kompatibel + genau |
| Antwort-Token | – | `answer_token` (signiert: Revision, Antwortreihenfolge, Konto; 2 h) Pflicht beim Antworten | Schutz vor Manipulation wie im Web |
| Modi | smarttrainer/new/wrong/topic/favorites | + `module`, `collection`, `exam_mistakes` | Web-Funktionen |
| Offene Fragen | – | `answer_selection: open`, `/trainer/reveal`, `known` | Plattform hat offene Fragen (SKN/Funk) |
| Wiederholte Antwort | 201 | 201 beim ersten Mal, 200 mit `repeated: true` bei gleichem Frage-Token | Frage-Token zählt wie im Web genau einmal |
| `mastery_score` | im Ergebnis | nicht ausgegeben | Web zeigt ihn nicht |
| Favoriten | je Kurs, eine Sammlung | kontoweit wie im Web, mehrere Sammlungen je Frage (`collection_ids`); Löschen entfernt auch aus Sammlungen | Bestehende Tabellen/Regeln |
| Sammlung `updated_at` | Pflicht | entfällt (seit 1.1.0 nicht mehr ausgegeben) | wird nicht gespeichert; ein immer leeres Feld war irreführend |
| Favoriten-Zeitpunkte, Sortierung | `created_at` (optional) | `saved_at` (Pflicht, nie null), bei `collection_id` zusätzlich `assigned_at`; `sort` (`saved_at` oder `assigned_at`), `order` (`desc` oder `asc`), gleiche Zeitpunkte nach Frage-ID; `created_at` bleibt als veralteter Alias | App sortiert serverseitig stabil; Einsortieren in weitere Sammlungen ändert bestehende Zeitpunkte nicht |
| Funktraining | nicht im Konzept | `/courses/{id}/radio/…` (Texte, Vokabeln, Buchstabiertafel, Funkpraxis, Muster) | gleiche Übungen und gleicher Lernstand wie im Web |
| Funkgerät-Simulator | nicht im Konzept | keine Bedien-API; `device_simulator` mit Browser-Link und Testversion/Vollversion | Simulator ist eine große Browser-Anwendung (Gerätezeichnungen, Zustandsmaschine); Nachbau in der App wäre zweite Logik |
| Prüfungsanmeldung | nicht im Konzept | `GET /courses/{id}/exam-registration`, nur lesen, `web_url` | Anmeldung erfolgt beim Prüfungsausschuss; keine Personendaten über die App |
| Prüfungsoptionen | rule_set/fixed_paper | `id` „simulation“ bzw. Bogen-ID, `locked`/`lock_reason`/`unlock_at`, `running_exam_id`, `meta.live` | Teilumfang, Drip, Übungsmodus |
| Prüfungsfragen | `questionId` | `questions[].id` = Position (1…n), Pfad `/answers/{position}` | Web liefert während der Prüfung bewusst keine Fragen-IDs |
| Prüfung abgelaufen | Status `expired` | `status: evaluated` + `result.expired`; Antworten danach 410 | Plattform wertet abgelaufene Prüfungen automatisch aus |
| `marked_for_review` | gespeichert | seit 1.3.0 gespeichert (`bp_exam_session_question.flagged`), eigener Endpunkt `PUT …/answers/{position}/flag`, `flagged`/`flagged_count` in Prüfung und Ergebnis; bis 1.2.0 immer `false` | Wunsch des App-Teams; Web zeigt und setzt Markierungen ebenfalls |
| Prüfungsergebnis | Grunddaten, Themen | + Teilergebnisse/Teilgrenzen, `reduced`, `live`, `expired`, falsche Fragen, vollständige Auswertung | Regeln der Plattform (z. B. Basis 5/7, See 18/23) |
| Videos | `/videos/{id}` | Kurs wird aus der Lektion ermittelt; Schrittbilder als Platzhalter „Bild folgt“; `completed` optional | Inhalte haben noch keine Schrittbilder |
| Navigation | `show_solution` | Lösungen erst nach Antwort/Aufdecken; `show_solution=true` deckt dauerhaft auf (wie Web); neuer Endpunkt für Antwort + Einschätzung; `course_id` optional | Aufgaben gehören zu mehreren Kursen |
| Zugangscodes | Bootsschule mailt Code nach Kauf | Kauf schaltet automatisch frei. `/coupons/redeem` löst nur Beitrittscodes von Gruppenlizenzen ein; WooCommerce-Gutschein → 422 `COUPON_NOT_SUPPORTED`; unbekannt → 422 `COUPON_INVALID` mit Hinweis; seit 1.11.0 Kursgutscheine eines Gutschein-Plugins (`kind: course_voucher`) | Gutscheine sind Rabatte, keine Zugänge (Kursgutscheine laufen über eine interne 0-€-Bestellung) |
| Datenexport | asynchron, öffentliche Datei mit Link-Token | asynchron (seit 1.4.0; `pending` → `ready`/`failed`, Action Scheduler bzw. WP-Cron, Rückfall in der Statusabfrage), in der Datenbank, Download nur mit Bearer-Token, 24 h, automatisch gelöscht; enthält alle WordPress-Exporte (Plugin, Konto, WooCommerce) | kein öffentliches Upload-Verzeichnis; keine Zeitüberschreitung bei großen Konten (bis 1.3.x synchron) |
| Kontolöschung | nicht in der App | `POST /me/deletion-request` (WordPress-Datenschutzanfrage mit Bestätigungsmail); seit 1.4.0 öffnet der Mail-Link die App (`/app/konto-loeschen/`), Bestätigung über `POST /me/deletion-request/confirm` | Apple verlangt einen Weg zur Kontolöschung in der App; Bestätigung ohne Wechsel in den Browser |
| Prüfung nach Zugangsende | – | Abgabe und Ergebnis möglich, weitere Antworten 403 `CONTENT_LOCKED`, kein Neustart (wie Web, gleicher Service) | Bereits Gearbeitetes nicht verlieren, aber keine weitere Nutzung ohne Zugang |
| Webansicht-Anmeldung | – | seit 1.4.0 nur mit Prüfwert-Header der App-WebView, kein Kontowechsel bei anderer bestehender Anmeldung | Schutz vor Login-CSRF über weitergeleitete Einmal-Links |
| Idempotency-Key | bei allen POST Pflicht | Pflicht bei `attempts`; optional (aber unterstützt) bei Sammlung, Prüfungsstart, Code, Export | übrige Vorgänge sind ohnehin wiederholbar |
| Neue Endpunkte | – | `/health`, `/me/sessions` (DELETE), `/trainer/reveal`, `/mistakes`, `/exams/{id}/answers/{pos}/reveal`, `/exams/{id}/answers/{pos}/flag`, `/navigation-tasks/{id}/parts/{partId}`, `/praxis`, `/radio/…`, `/exam-registration`, `/files/{id}`, `/profile/export/{id}/download`, `/auth/web-session`, `/me/settings`, `/me/deletion-request/confirm`, `/tips`, `/me/groups…`, `/groups/join/{token}`, `/courses/{id}/faqs`, `/me/tickets` | Plattform-Funktionen, Server-Prüfung, Wünsche des App-Teams (1.3.0, 1.4.0, 1.7.0, 1.10.0, 1.11.0) |
| Gruppenlizenzen | – | Verwalten nur durch die besitzende Person (wie Web-Lernbereich); Personal mit `manage_bp_entitlements` verwaltet im Backend, nicht über die App; Einladen/neuer Link nur bei aktiver Gruppe (409 statt still wie im Web-REST) | Gleiche Rechte wie die Web-Oberfläche; keine Mails aus ausgesetzten Gruppen |
| Fehlerobjekt | RFC 9457 | gleiches Format, `type` als URN, zusätzlich `message`, `details`, `retry_after` | einheitlich und einfach auswertbar |

---

## 6. Tests

- **Integration:** `tests/integration/mobile-api-test.php` (412 Prüfungen, mit WooCommerce
  416): Login/Refresh/Rotation/Wiederverwendung/verlorene Refresh-Antwort/Logout, Sitzungen,
  Passwort-Reset und -Änderung, Rate-Limit (E-Mail+IP, E-Mail, IP, kein Aussperren fremder Konten,
  Rücksetzen nach Erfolg, atomarer Zähler, eingetragene Proxys/Filter, gefälschtes X-Forwarded-For),
  verwaiste Idempotenz-Einträge, Fehlercodes statt Meldungstexten, Prüfung nach Zugangsende,
  asynchroner Export (geplant, Rückfall, Sperre, Fehler), Range/Content-Disposition, Code-Einlösung
  (Idempotenz vor Limit), Kontolöschung per App-Link (Mail-Link, Weiterleitung, Bestätigung, falscher
  Schlüssel, Wiederverwendung, Benachrichtigung),
  Tenant, Kursliste mit WooCommerce-Kauf (ohne WooCommerce: manueller Zugang), Smarttrainer mit
  gleichem Lernstand wie im Web, Idempotenz, Favoriten/Sammlungen inkl. Sortierung und Nulldatum,
  Prüfung Start → Antwort → Abgabe → Ergebnis, Video, Navigation, Funktraining (Texte, Vokabeln,
  Funkpraxis, Teilumfang), Simulator-Link, Prüfungsanmeldung, Codes, Export, Löschantrag, fremde
  Daten (IDOR), Abschalten, Markieren in Prüfungen, seit 1.7.0 Tipps (gleich wie Web, Text/Links),
  Gruppen verwalten (nur Besitzer, Einladen mit Prüfung/Platz-/Tages-/Rate-Limit/Idempotenz, Mail,
  Ablauf `expired`, Platz freigeben, neuer Code macht alten ungültig, ausgesetzte Gruppe, Abgleich mit
  der Web-Ausgabe), Einladungslink als App-Link (Vorschau, Einlösen mit `i…`-Link, AASA nur mit
  Einträgen, Browser-Pfad unverändert), gezieltes Üben einer Frage (Lernstand, unbekannt/fremd,
  Teilumfang, Freigabe, laufende Prüfung), Sitzungsübergabe App → Web (offene Weiterleitung,
  Wiederverwendung, Ablauf, Widerruf, Passwortänderung, Admin/Redaktion, Limit, Prüfwert-Header,
  kein Kontowechsel), App-Einstellungen
  (Größe, Tiefe, Schlüssel, Versionskonflikt, Export/Löschung), Universal/App Links (`.well-known` mit
  und ohne Einträge, Reset-Mail aus App bzw. Web) und **Abgleich aller Routen mit `openapi.yaml`** (jede Route im Vertrag
  und umgekehrt; übersprungen, wenn `docs/` nicht vorhanden ist). Funkinhalte und Anleitungen
  kommen aus dem Paketimport von `content-flow-test.php`.
  Läuft nur gegen eine Test-Datenbank (`support/guard.php`).

  ```bash
  WP_DIR=/pfad/zu/test-wordpress php bootspruefung-learning/tests/integration/mobile-api-test.php
  ```

- **Kunden-FAQ (seit 1.10.0):** `tests/integration/customer-faq-test.php` prüft `GET /courses/{id}/faqs`
  (Gast 401, Testkurs 403 `CONTENT_LOCKED`, ohne Zugang 403 `ENTITLEMENT_REQUIRED`, Kunde, Kombi mit
  getrennten Gruppen, `answer_text`, absolute Links, `faq_count`) zusammen mit dem Web-Endpunkt.
- **CI:** Schritt „App-Schnittstelle boatingschool/v1“ nach dem WooCommerce-Adapter – einmal ohne,
  einmal mit WooCommerce.
- **Vertrag:** `python3 -c "from openapi_spec_validator import validate; from openapi_spec_validator.readers import read_from_filename; validate(read_from_filename('docs/mobile-api/openapi.yaml')[0])"`

---

## 7. Offene Punkte / Entscheidungen für den Auftraggeber

1. **Kauflink in der App (App-Store-Regeln):** Apple (3.1.1) und Google erlauben Links zu externen
   Käufen digitaler Inhalte nur eingeschränkt bzw. je Land. Vor dem Release rechtlich klären, ob
   `webshop_url` angezeigt werden darf (z. B. als „Reader“-App ohne Kaufhinweis, oder nur im Web).
2. **Tenant-Slug und Markenname** festlegen (ohne Eintrag folgen beide dem Namen der Website) – danach nicht mehr ändern.
3. **Kontolöschung (entschieden):** Nach der Bestätigung löscht der Shop sofort Lerndaten und Konto
   (`wp_delete_user`); WooCommerce-Bestellungen bleiben anonymisiert erhalten (Nummer, Datum, Produkte,
   Beträge, Transaktionsnummer), Gruppenplätze werden frei. Abschaltbar in den Plugin-Einstellungen
   („Datenschutz-Anträge automatisch ausführen“); Konten mit Verwaltungsrechten nie automatisch. Im Web
   gibt es denselben Weg unter „Mein Konto“ → „Meine Daten“.
4. **Funkgerät-Simulator (entschieden):** In der Handy-App gibt es vorerst keinen Simulator, nur den
   Hinweis „am Tablet oder im Browser öffnen“ mit `web_url`. Für die Tablet-App gibt es seit 1.3.0 die
   Sitzungsübergabe in eine eingebettete Webansicht (`POST /auth/web-session`); das Einbetten selbst
   ist Sache der App. Offen: Soll der Systembrowser-Link der Handy-App ebenfalls angemeldet öffnen?
   (Technisch möglich mit derselben Einmal-URL, dann aber Cookie im Systembrowser des Geräts.)
5. **Passwort-Reset-Link (seit 1.3.0):** Mit eingetragenen App-Kennungen öffnet der Link der aus der
   App angeforderten Mail die App (`/app/passwort/`, ohne App Weiterleitung zur Web-Seite). Über die
   Website angeforderte Mails bleiben unverändert. **Seit 1.4.0 (entschieden: ja)** öffnet auch der
   Bestätigungslink der Kontolöschung die App (`/app/konto-loeschen/`, Bestätigung über
   `POST /me/deletion-request/confirm`); ohne App wie bisher die WordPress-Bestätigung im Browser.
6. **Sprache der WordPress-Mails** (Reset, Löschbestätigung) folgt der Website-Sprache; auf Staging
   englisch, live mit deutschem Sprachpaket deutsch.
7. **Medien:** Videos sind Platzhalter. Für echte Videos klären: Hosting (Datenschutz, keine
   Drittanbieter-Einbettung ohne Einwilligung) und ggf. signierte URLs. Fragenbilder liegen wie im
   Web öffentlich unter `uploads/bootspruefung/media/`.
8. **„Zur Überprüfung markieren“ (seit 1.3.0 in der App):** serverseitig gespeichert
   (`PUT /exams/{examId}/answers/{position}/flag`, `flagged` in Prüfung und Ergebnis). Entschieden:
   Der Web-Lernbereich zeigt und setzt Markierungen ebenfalls (Knopf „Markieren“, Übersicht, Ergebnis).
9. Laufzeiten (15 Min./30/90 Tage), das Kulanzfenster beim Refresh (60 s) und Limits sind derzeit
   feste Werte im Code; bei Bedarf einstellbar machen.
10. **Sprachausgabe im Funktraining (entschieden):** Vorerst liest die App Diktate mit der
    Sprachausgabe des Geräts vor (das Web mit der Browserstimme, Einstellung `radio_voice_output`).
    Eigene Tonaufnahmen werden später ergänzt; dann kommt ein Audio-Feld in die Antworten.
11. **Automatische Bewertung freier Antworten (entschieden, seit 1.4.0 als Hilfe umgesetzt – siehe
    Abschnitt 3, Punkt 9):** Navigationsaufgaben
    (15 Aufgaben, 135 Teilaufgaben) speichern nur die amtliche Lösung als Text (`answer_text`, z. B.
    `rwK = 079°`, `6,1 sm`, `53° 55,8' N 007° 51,3' E`, `Siehe Karte`); bewertet wird per
    Selbsteinschätzung. Rund 60 % sind Zahlenwerte (Kurse/Peilungen, Positionen, Distanzen, Zeiten,
    Fahrt), ein Viertel „Siehe Karte“ (Eintragung in die Karte), der Rest Beschreibungen. Eine
    automatische Prüfung der Zahlenwerte wäre mit strukturierten Lösungen (Größe, Wert, Einheit,
    Toleranz) machbar; das erfordert eine redaktionelle Erweiterung des Inhaltspakets. Umgesetzt mit
    der Pflegedatei `tools/content-build/data/navigation_answer_values.json`; die Selbsteinschätzung
    bleibt maßgeblich.

12. **Gruppen in der App (seit 1.7.0):** Verwaltet wird wie im Web nur von der besitzenden Person.
    Offen: Sollen Mitglieder ihre Gruppe (Name, verwaltende Person, Laufzeit) in der App sehen bzw.
    selbst austreten können? Das Web bietet beides nicht. Ebenfalls offen: Umbenennen der Gruppe
    (Web: `PUT me/groups/{id}`) ist bewusst nicht in der App – bei Bedarf ergänzen. Einzelgründe für
    übersprungene Einladungen gibt der Service nicht zurück (Web zeigt nur die Liste); bei Bedarf im
    `GroupService` ergänzen (dann für Web und App).
13. **Einladungslink in der App (seit 1.7.0):** Die Registrierung neuer Konten bleibt auf der Website
    (die App merkt sich den Token, bis angemeldet ist). Persönliche Einladungen sind – wie im Web – an
    keine E-Mail-Adresse gebunden; soll das Annehmen nur mit dem eingeladenen Konto möglich sein, wäre
    das eine Änderung für Web und App. Für Android muss der Intent-Filter `pathPrefix
    /bootsscheine/gruppe/` enthalten; ändert der Shop den Lernbereichs-Pfad (Filter
    `bootspruefung_base_path`), ändern sich Link und `apple-app-site-association` mit, die App-Konfiguration
    muss nachgezogen werden.
14. **Tipps (seit 1.7.0):** Die App bekommt das redaktionelle HTML; eingebettete Medien (Videos, iframes)
    lässt `wp_kses_post` nicht durch. Sollen Tipps künftig gezielt auf App-Bildschirme verlinken (z. B.
    „Smarttrainer öffnen“), braucht es eine Linkkonvention (z. B. Web-Pfade, die die App als App-Link
    erkennt).
15. **Kunden-FAQ in der App (seit 1.10.0):** Für den Abschnitt „Fragen & Antworten zu deinem Kurs“ gibt es
    noch keinen Figma-Screen. Vorschlag wie im Web: in der Kursübersicht nach den Lernbereichen, Fragen als
    aufklappbare Liste, gruppiert nach Schein (bei Kombikursen) und Unterkategorie.

---

## 8. Änderungsprotokoll

### 1.11.0 (additiv)

- **Store-Links:** `GET /health` liefert `store_urls.ios/android` (App Store bzw. Google Play, aus
  „Bootsprüfung → Mobile App“; `null`, solange die App nicht im Store steht). Beim Pflicht-Update (426
  `APP_UPDATE_REQUIRED`) steht der Link der eigenen Plattform in `details.store_url`. Mit App-Store-Link
  zeigt Safari auf der Website das App-Banner (`apple-itunes-app`).

- **Kursgutscheine (Gutschein-Plugin):** `POST /coupons/redeem` löst – wenn ein Gutschein-Plugin den
  WordPress-Filter `bootspruefung_voucher_redeem` belegt – auch Kursgutscheine ein. Der Kurs wird über
  eine interne WooCommerce-Bestellung über 0 € freigeschaltet, die Laufzeit beginnt mit der Einlösung.
  Antwort wie beim Beitrittscode mit `kind: course_voucher` (neuer Enum-Wert; `group_name` ist `null`),
  `courses` und `entitlement` (`valid_from`/`valid_until`). Gleicher Code durch dasselbe Konto erneut →
  200 ohne neue Bestellung; abgelehnt → 422 `COUPON_INVALID` mit der Meldung des Gutschein-Plugins.
  Ohne Gutschein-Plugin unverändert. **Ältere App-Versionen:** Wer `kind` streng auf `group_license`
  prüft, sollte unbekannte Werte wie „eingelöst“ behandeln.
- **Neu: `GET /me/tickets`** (ohne Tenant-Header): Praxis-Tickets aus einem Ticket-Plugin (`PracticeTicket`:
  Einheiten gesamt/absolviert/übrig, Status `issued|redeemed|cancelled`, Termin, Ort, Grund bei Storno).
  Ohne Ticket-Plugin `data: []`. Anzeige z. B. „Praxis: 3 von 5 Einheiten absolviert“, mit dem Hinweis,
  dass das kein amtlicher Nachweis ist.
- **Neues Feld `practice_offers`** in `GET /courses/{courseId}`: „Praxis in deiner Nähe“ (`PracticeOffer`:
  `title`, `url`, `date`, `location`, `price`), ohne Ticket-Plugin leer – dann nichts anzeigen.
- Schnittstellen für die Erweiterungs-Plugins (Hooks, Filter): `docs/schnittstellen-erweiterungen.md`.
  Test: `tests/integration/extensions-test.php`.
- **Kaufstatus sichtbar (Zahlung offen, abgelaufen, gesperrt):** `access.status` liefert `pending`,
  `suspended` bzw. `expired` jetzt auch, wenn der Kurs einen kostenlosen Teilumfang hat (bisher kam
  dann immer `limited`, entgegen dieser Doku). Reihenfolge: `active` vor `pending` vor `suspended` vor
  `expired` vor `limited` vor `revoked`/`not_granted`. Der Teilumfang bleibt nutzbar (`trial`, `limits`,
  `features` unverändert). Grundlage ist der Zugang zum Fragenkatalog (`AccessService::purchaseStates`,
  dieselbe Logik wie im Web-Lernbereich). „Zahlung offen“ gilt nur, solange die Bestellung im Shop
  ausstehend oder in Wartestellung ist.
- Neue Felder im Zugangs-Objekt `access`: `order_number`, `order_url` (bei `pending`; Bestellansicht in
  „Mein Konto“), `duration_days` (bei `pending`: Laufzeit ab Zahlungseingang, `null` = unbefristet),
  `contact_url` (bei `suspended`). `renew_url` gibt es jetzt auch bei `expired` und führt (auch bei
  `expires_soon`) zur **gekauften Stufe** (Theorie bzw. Komplett, wenn der Videokurs dabei war; Kombi,
  Bundle, Pass) statt zum ersten Angebot.
- `webshop_url` (Kurs und 403-`details`) ist bei `pending` und `suspended` `null`, bei `expired` der
  Verlängern-Link. Meldung bei 403 `ENTITLEMENT_REQUIRED` mit `access_status: pending`: „Deine Zahlung
  ist noch offen …“.
- **Ältere App-Versionen:** Wer `limited` als einzigen Testkurs-Status behandelt, sieht einen Testkurs mit
  offener Zahlung bzw. abgelaufenem Kauf jetzt als `pending`/`expired`; der Teilumfang ist weiter über
  `trial`/`features` erkennbar.
- Server (nicht App-sichtbar): Ablauf-Erinnerung mit Widerspruchshinweis und Abmeldelink, Schalter
  unter „Mein Konto“ › „Mein Lernen“; Hinweis im Warenkorb bei schon gekauften bzw. bestellten Kursen.
  Keine neuen Routen.
- **Smarttrainer mit verteilter Wiederholung (wie im Web):** Drei richtige Antworten in Folge ergeben die
  Stufe „sicher heute“; gefestigt ist eine Frage erst mit einer weiteren richtigen Antwort frühestens 20 h
  später (an einem anderen Tag). Gefestigte Fragen kommen nach 7, 21, dann 60 Tagen wieder. Die
  Reihenfolge bleibt wie bisher: zuerst neue Fragen, dann falsch beantwortete, dann fällige Wiederholungen.
  `trainer/next` liefert sie automatisch; die App muss dafür nichts ändern.
- **Neue Felder, alte unverändert:** `LearningProgress.stage` (`new|unsure|learning|secure|mastered`) und
  `LearningProgress.due_at`; `learning_status`/`learning_state` behalten ihren Wertebereich („sicher heute“
  = `learning`/`in_bearbeitung`). `ProgressSummary.secure`, `secure_percent` und `due`; `learning` zählt
  „sicher heute“ mit, damit `new + unsure + learning + mastered = total` bleibt; `percent`/`completed`
  bleiben der Anteil bzw. die Zahl gefestigter Fragen. Lernstand vor 1.11.0: „gefestigt“ bleibt gefestigt
  und gilt 7 Tage nach der letzten Antwort als fällig (keine rückwirkende Herabstufung).
- **Prüfung im Funkloch:** `PUT /exams/{examId}/answers/{questionId}` nimmt `age_ms` (Alter der
  zwischengespeicherten Antwort beim Senden) bzw. `answered_at` (Gerätezeit) an. Vor Fristende gegebene
  Antworten werden bis 120 s nach Fristende noch angenommen, spätere mit 410 `EXAM_EXPIRED` abgelehnt;
  abgelaufene Prüfungen werden erst danach automatisch ausgewertet. Gleiche Antwort zweimal = einmal; ein
  älterer Stand überschreibt keinen neueren (`applied: false` in der Antwort, die den gespeicherten Stand
  zeigt). Empfehlung: Antworten lokal halten, bis der Server sie bestätigt hat, bei Netzfehlern mit
  wachsendem Abstand wiederholen und `submit` erst danach aufrufen (wie die Web-App).
- Server: dieselbe Logik in `ExamService::saveAnswer` und `LearningService` für Web und App;
  Schema 1.14.0 (Spalte `bp_progress.due_at`). Test: `tests/integration/learning-review-test.php`.
- **Neu: Prüfung abbrechen** `POST /exams/{examId}/abort`: beendet eine laufende Prüfung ohne Wertung
  (`status: aborted`, `aborted_at`); sie erscheint nicht im Verlauf und nicht als letztes Ergebnis eines
  Bogens, ihre Fragen sind im Lernmodus sofort wieder frei, gegebene Antworten bleiben gespeichert. Nach
  Fristende wird stattdessen gewertet. Danach 409 `EXAM_ABORTED` für Antworten, Markieren und `result`;
  `GET /exams/{examId}` liefert `status: aborted` ohne Fragen. Die App sollte vorher nachfragen
  („Prüfung abbrechen? Sie wird nicht gewertet.“) und lokal gepufferte Antworten verwerfen.

### 1.10.0 (additiv)

- **Neu: Kunden-FAQ** `GET /courses/{courseId}/faqs` (Tenant-Header): scheinspezifische Fragen und
  Antworten nur für Kunden des Kurses (voller Zugang zum Fragenkatalog; Testkurs → 403 `CONTENT_LOCKED`
  mit `details.reason = partial_access`, ohne Zugang 403 `ENTITLEMENT_REQUIRED`/`_EXPIRED`/`_SUSPENDED`).
  Gruppiert nach Schein (Kombikurse: Teilscheine, Ergänzungen: Zielschein) und Unterkategorie (flach mit
  `parent_id`, `depth`); je FAQ `answer_html` (Links absolut), `answer_text`, `links`. Gleiche Daten wie
  der neue Abschnitt „Fragen & Antworten zu deinem Kurs“ im Kurs-Hub (Web `GET bootspruefung/v1/courses/{code}/customer-faqs`).
- **`faq_count`** in jedem `CourseSummary` (`GET /courses`, `GET /courses/{courseId}`, `course` in
  `/overview`): Anzahl der Kunden-FAQs, 0 ohne Kundenzugang.
- Neuer Tag `CustomerFaq`; neue Schemas `CourseFaq`, `CourseFaqSubcategory`, `CourseFaqGroup`,
  `CourseFaqsResponse`. Keine neuen Fehlercodes (vorhandene Zugangscodes), keine Datenbanktabelle
  (eigener Beitragstyp `bp_customer_faq`, Taxonomie `bp_cfaq_category`).
- Server: `BaseController::featureDenied` (Fehlerobjekt von `requireFeature`, jetzt auch mit eigener
  Meldung nutzbar); App-Verhalten der bestehenden Endpunkte unverändert.

### 1.9.0 (additiv)

- **Kursempfehlungen und Ausblenden von Doppeltem:** `relation` in jedem `CourseSummary`
  (`GET /courses`, `GET /courses/{courseId}`, `course` in `/overview`) mit `hidden`, `recommended` und
  `recommended_for` (`[{id, name}]` der besessenen Kurse, die den Vorschlag auslösen). „Besessen“ =
  gültiger Zugang zum Fragenkatalog durch Kauf, manuell oder Gruppe; der kostenlose Teilumfang zählt
  nicht. Grundlage ist dieselbe Tabelle wie im Web (`Commerce\CourseRelations`, Filter
  `bootspruefung_course_relations`), z. B. Besitzer von SBF Binnen: SBF See & Binnen ausgeblendet,
  Ergänzung Binnen → See empfohlen; UBI-Besitzer: SRC empfohlen. Testkurse (kostenloser Teilumfang)
  werden nie ausgeblendet (Festlegung 10.11.2026), eine Empfehlung dürfen sie tragen.
  **Entscheidung:** Ausgeblendete Kurse bleiben in der Liste und werden nur markiert, damit Deep-Links,
  Kursdetails und eine laufende Anzeige weiter funktionieren. Die App blendet sie unter „Weitere Kurse“
  aus und zeigt empfohlene oben mit „Empfohlen“ und „Passt zu deinem Kurs {name}“.
- **Zugang endet bald:** im Zugangs-Objekt `access` zusätzlich `expires_soon`, `expires_at` (Ende des
  Fragenkatalog-Zugangs, nur wenn bald) und `renew_url` (erstes passendes Shop-Angebot, ohne Shop die
  Kursseite im Web). `expires_soon` ist true, wenn der Zugang in höchstens N Tagen endet (Admin
  „Tage vorher“, Standard 14) und kein späterer oder unbefristeter Zugang besteht. `valid_until` bleibt
  unverändert (spätestes Ende aller Bereiche). Anzeige wie im Web: „Dein Zugang zu {Kurs} endet am
  {Datum}. Dein Lernstand bleibt erhalten.“ mit „Jetzt verlängern“.
- Server (nicht App-sichtbar): tägliche Erinnerungsmail vor Ablauf (WP-Cron `bp_learning_daily`,
  abschaltbar unter Bootsprüfung → Einstellungen). Keine Datenbankänderung, keine neuen Routen.

### 1.8.0 (additiv)

- **Rechtstexte:** `GET /health` (ohne Anmeldung) liefert `legal` mit `privacy_policy`, `imprint`,
  `terms`, `withdrawal_policy`, `withdrawal_function` (Widerrufsbutton) und `contact`; dieselben Links
  stehen zusätzlich in `GET /me` (`links`). Nur veröffentlichte Seiten, sonst `null`. Anpassbar über den
  Filter `bootspruefung_mobile_legal_links`.
- **Pflicht-Update:** Mindestversion je Plattform unter „Bootsprüfung → Mobile App“. `GET /health` nennt
  sie (`min_app_version.ios/android`). Die App schickt ihre Version im Header
  `X-Boatingschool-App-Version` und die Plattform in `X-Boatingschool-Platform` (`ios`/`android`);
  ohne Header gelten die Angaben der Sitzung bzw. beim Login die aus dem Body. Liegt die Version
  darunter, antworten alle Endpunkte außer `/health` mit 426 `APP_UPDATE_REQUIRED`
  (`details.min_app_version`). Ohne Versionsangabe wird nichts blockiert.

### 1.7.0 (additiv)

- **Neu: Tipps & Tricks** `GET /tips` (Tenant-Header, ohne Kurszugang): dieselben Tipps wie
  `/bootsscheine/tipps/` – Kategorien (`id`, `slug`, `name`) mit Tipps (`id`, `title`, `html` nach
  `wp_kses_post`, relative Links absolut, `text` ohne HTML, `links`).
- **Neu: Gruppen verwalten** (wie „Meine Gruppen“ im Web, nur die besitzende Person, ohne Tenant):
  `GET /me/groups`, `GET /me/groups/{groupId}` (Plätze `total/used/free`, Umfang, `join_code`/`join_url`,
  `invites_remaining_today`, Mitglieder mit `active`/`invited`/`expired` und `invite_expires_at`),
  `POST /me/groups/{groupId}/invites` (gleiche Prüfung, Platz- und Tageslimit und Einladungsmail wie im
  Web; `invited`/`skipped`; Idempotency-Key; 20 Vorgänge je Stunde),
  `DELETE /me/groups/{groupId}/members/{memberId}` (Platz freigeben, optional `reason`),
  `POST /me/groups/{groupId}/join-code` (neuer Beitrittscode, alter sofort ungültig). Fremde Gruppen 404.
- **Neu: Einladungslink als App-Link:** `/bootsscheine/gruppe/*` in der `apple-app-site-association`
  (nur mit eingetragenen iOS-App-IDs); Android-Intent-Filter um `pathPrefix /bootsscheine/gruppe/`
  ergänzen. Vorschau `GET /groups/join/{token}` (wie die Web-Seite „Gruppe beitreten“), Beitritt wie
  bisher über `POST /coupons/redeem` (nimmt `g…`, `i…` und den ganzen Link, wie Web `groups/join`).
  Ohne App bleibt der Link die Web-Seite.
- **Neu: Bestimmte Frage gezielt üben:** `GET /courses/{courseId}/trainer/next?mode=question&question_id=…`
  – Frage mit normalem `answer_token`, Antwort zählt in den Lernstand; 404 außerhalb des Kurses,
  403 `CONTENT_LOCKED` mit `details.reason` (`partial_access`, `scheduled_release` + `unlock_at`,
  `running_exam`). `question_id` nur mit `mode=question` (sonst 422).
- Neuer Fehlercode `GROUP_NOT_ACTIVE` (409). Neue Tags `Groups`, `Tips`.
- Server: `GroupService::inviteBudget` (Tageslimit, bisher inline in `invite`) und
  `GroupService::resolve` (öffentlich für die Vorschau), `Tips::groups` (Kategorien mit Begriff;
  `Tips::payload` liefert unverändert `name`/`tips`). Web-Verhalten und Web-Ausgaben unverändert.
  Keine Datenbankänderung.

### 1.6.0 (additiv)

- **Bestehensgrenzen strukturiert:** `pass_rule.parts` in `GET /courses/{courseId}/exams` (je Teil
  `label`, `minimum_correct`, `of`, z. B. Basisfragen 5/7 und Spezifische See 18/23); ohne Teile
  steht die Gesamtgrenze in `pass_rule.minimum_correct`. Der Text `description` bleibt.
- **Fragetypen vor dem Start:** `question_types` je Prüfungsoption (`single_choice`, `multi_choice`,
  `open_text` → Anzahl). Feste Bögen immer; Zufallsprüfung nur, wenn alle Fragen der Prüfungsmodule
  denselben Typ haben, sonst `null`.

### 1.5.0 (additiv)

- **Knoten als eigener Bereich:** `knots` in `GET /courses/{courseId}` (Kapitel-ID, Lektionen,
  abgeschlossen, gesperrt/Freigabetermin, Shop-Link ohne Videozugang); `null`, wenn der Kurs kein
  Knoten-Kapitel hat. Lektionen wie bisher über `GET /courses/{courseId}/videos`.
- **„Dein nächster Schritt“:** `continue_learning` in `GET /courses/{courseId}/overview` enthält
  zusätzlich `topic` (erstes nicht gefestigtes Thema des Moduls), `open`, `unsure` und
  `trainer_query` (Parameter für `GET /courses/{courseId}/trainer/next`).
- **Anmeldesperre:** unverändert, zur Klarstellung: 429 `RATE_LIMITED` enthält die echte Restzeit
  als Header `Retry-After` und Feld `retry_after` (Sekunden bis Ende des Fensters) – für Anzeigen wie
  „Erneut versuchen in 30 Sekunden“.

### 1.4.0 (Sicherheits- und Robustheitskorrekturen aus dem externen Review)

**Vertragsänderungen für die App (bitte umsetzen):**

- **`POST /auth/web-session` – Prüfwert-Header Pflicht (Login-CSRF):** Die Antwort enthält zusätzlich
  `verifier` und `verifier_header` (`X-Boatingschool-Web-Verifier`). Die WebView muss `url` mit diesem
  Header laden (Android `loadUrl(url, headers)`, iOS `URLRequest` + `setValue(…, forHTTPHeaderField:)`,
  Flutter `loadRequest(…, headers:)`). Ohne/mit falschem Header keine Anmeldung (Code verbraucht,
  Anmeldeseite, kein Grund). Ist in der WebView bereits ein **anderes** Konto angemeldet, wird nicht
  umgeschaltet (Code verbraucht, Anmeldeseite) → WebView-Cookies bei Abmeldung/Kontowechsel löschen.
  Ältere App-Versionen ohne Header werden nicht mehr angemeldet.
- **`POST /profile/export` ist asynchron:** 202 mit `status: pending` und `download_url: null`;
  `GET /profile/export/{id}` liefert `pending` → `ready` (mit `download_url`) oder `failed`.
  Download vor Fertigstellung → 409 `EXPORT_NOT_READY` (`retry_after`), fehlgeschlagen → 410
  `EXPORT_FAILED`. `status` hat die neuen Werte `pending` und `failed`. Ältere App-Versionen, die
  sofort `ready` erwarten, müssen abfragen.
- **`POST /auth/refresh` – verlorene Antwort:** Das unmittelbar vorherige Refresh-Token liefert
  innerhalb von **60 Sekunden** nach der Rotation **200 mit einem neuen, gültigen Paar** derselben
  Sitzung (bisher 409 `REFRESH_TOKEN_ROTATED` ohne Tokens innerhalb 10 s, danach Abmeldung). Das
  verlorene Paar wird ungültig – immer das zuletzt erhaltene Paar verwenden. Nach 60 s weiterhin 401
  `REFRESH_TOKEN_REUSED` mit Widerruf. 409 kommt nur noch bei extremen Wettläufen vor.
- **Neu: `POST /me/deletion-request/confirm`** (ohne Anmeldung, `request_id`, `confirm_key`) – bestätigt
  den Löschantrag wie `wp-login.php?action=confirmaction` (inkl. Benachrichtigung der Verwaltung).
  Mit eingetragenen App-Kennungen zeigt der Link der Bestätigungsmail auf
  `/app/konto-loeschen/?request_id=…&confirm_key=…` (in der `apple-app-site-association` enthalten;
  Android-Intent-Filter um `pathPrefix /app/konto-loeschen/` ergänzen); ohne App Weiterleitung zur
  WordPress-Bestätigung. Fehler: 422 `DELETION_CONFIRMATION_INVALID`.
- **`GET /files/{id}`:** `Accept-Ranges: bytes`, Range (genau ein Bereich) → 206 mit `Content-Range`,
  ungültig/nicht erfüllbar/mehrere Bereiche → 416 `RANGE_NOT_SATISFIABLE` (`Content-Range: bytes */<Größe>`).
  `Content-Disposition` mit ASCII-Ersatz `filename="…"` und `filename*=UTF-8''…` (auch beim
  Export-Download; dieser unterstützt keine Bereiche, `Accept-Ranges: none`).
- **Login-Limits:** 5 Fehlversuche je E-Mail + IP (bisher je E-Mail von beliebigen IPs), 10 je IP,
  30 je E-Mail insgesamt in 15 Min.; Erfolg setzt E-Mail + IP zurück.
- **`POST /coupons/redeem`:** Wiederholung mit demselben Idempotency-Key liefert die gespeicherte
  Antwort, ohne einen der 10 Versuche je Stunde zu verbrauchen.
- Neue Fehlercodes: `EXPORT_NOT_READY` (409), `EXPORT_FAILED` (410), `RANGE_NOT_SATISFIABLE` (416),
  `DELETION_CONFIRMATION_INVALID` (422). `REQUEST_IN_PROGRESS` beim Prüfungsstart mit `retry_after: 2`.

**Server (ohne Vertragsänderung):**

- **Rate-Limits atomar:** Zähler in der neuen Tabelle `bp_mobile_rate_limit` per
  `INSERT … ON DUPLICATE KEY UPDATE` (Stand über `LAST_INSERT_ID` in derselben Anweisung) statt
  Transients mit Lesen-Prüfen-Schreiben; Zählernamen nur gesalzen gehasht; Aufräumen im stündlichen Cron.
- **Vertrauenswürdige Proxys:** Standard keiner – auch Loopback/private Netze nicht mehr automatisch.
  `X-Forwarded-For` nur von eingetragenen Proxys (Admin-Einstellung oder Filter
  `bootspruefung_mobile_trusted_proxies`), von rechts ausgewertet. **Hinter einem Reverse-Proxy (auch
  Docker-Staging mit Caddy) dessen Netz eintragen**, sonst teilen sich alle die Limits der Proxy-IP.
- **Idempotenz:** Einträge „in Arbeit“ (Prozess hart abgebrochen) gelten nach 60 s als verwaist und
  werden von der nächsten gleichen Wiederholung atomar übernommen und ausgeführt (bisher 7 Tage
  `REQUEST_IN_PROGRESS`).
- **Fehlercodes statt Meldungstexten:** Neue Klasse `Support\DomainException` (erbt von
  `RuntimeException`, Meldungen und Web unverändert) in `ExamService`, `LearningService`,
  `QuestionPresenter`; `BaseController` bildet über den Code ab. Die Web-REST-Controller verglichen
  keine Meldungstexte (nur Klassen) und bleiben unverändert.
- **Prüfung nach Zugangsende:** bewusst wie im Web (gleicher `ExamService`): Abgabe/Ergebnis möglich,
  weitere Antworten 403 `CONTENT_LOCKED`, kein Neustart – dokumentiert, keine Codeänderung.
- Datenbank: Schema **1.13.0** (Tabelle `bp_mobile_rate_limit`, Spalten `bp_mobile_export.started_at`,
  `bp_mobile_web_code.verifier_hash`). Deinstallation entfernt zusätzlich geplante Export-Aufgaben.

**Navigation (neu, additiv):**

- **Neu: Automatische Prüfung bei Navigationsaufgaben** (Hilfe, keine Bewertung): `auto_check` je
  Teilaufgabe in `GET /navigation-tasks/{taskId}`; `check` (Schema `NavigationCheck`) in der Antwort von
  `POST /navigation-tasks/{taskId}/parts/{partId}` und je aufgedeckter Teilaufgabe in der Aufgabe.
  Toleranzen: Kurs/Peilung/Winkel ±2°, Distanz ±0,2 sm, Position ±0,3', Uhrzeit ±3 min,
  Fahrt ±0,3 kn, Dauer ±3 min. Die Selbsteinschätzung bleibt maßgeblich; Teilaufgaben ohne Werte
  unverändert. Sollwerte (`expected`) nur, wenn die Lösung ohnehin sichtbar ist.
- Inhalte: Content-Paket ab Schema 1.4 mit `answer_values` (Pflegedatei des Konverters, von Hand
  geprüft; Werte folgen dem angezeigten, ggf. berichtigten amtlichen Ergebnis). Wirksam nach dem
  Import eines neuen Pakets.
- Datenbank: Spalte `bp_navigation_question.answer_values` (ebenfalls Schema 1.13.0).
- Keine Änderung an bestehenden Feldern; neue Felder sind additiv.

### 1.3.1 (Sicherheitskorrektur, ohne Vertragsänderung)

- Bearer-Token galt über eine gemischte Anfrage (`/wp-json/boatingschool/v1/…?rest_route=/wp/v2/…`)
  auch für andere WordPress-Routen. Jetzt wertet die Erkennung `rest_route` (POST/GET) wie
  WordPress vor dem Pfad aus, und `rest_pre_dispatch` lehnt jede fremde Route ab, solange eine
  App-Sitzung aktiv ist (401).

### 1.3.0

- **Neu: Sitzungsübergabe App → Web** `POST /auth/web-session` (`redirect` im Lernbereich) → Einmal-URL
  (`url`, `expires_at`, 60 s, einmal verwendbar, an Konto und App-Sitzung gebunden). Einlösen über
  `?bp_app_login=…`: WordPress-Sitzungscookie, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`,
  Weiterleitung aufs Ziel ohne Code; ungültig → Anmeldeseite mit `redirect_to`. Nicht für Konten mit
  `manage_options`/`edit_others_posts` (403 `WEB_SESSION_NOT_ALLOWED`); abschaltbar (403
  `WEB_SESSION_DISABLED`); 20 je Konto in 15 Minuten.
- **Neu: App-Einstellungen** `GET/PUT /me/settings` (`settings`, `version`, `updated_at`, `ETag`;
  `If-Match`/`expected_version` → 409 `VERSION_CONFLICT`). Im Datenexport (auch `/profile/export`) und
  in der Datenschutz-Löschung enthalten.
- **Neu: Prüfungsfragen markieren** `PUT /exams/{examId}/answers/{position}/flag` (`flagged`).
  `flagged` je Frage und `flagged_count` in `GET /exams/{examId}`, im Ergebnis `review[].flagged` und
  `flagged_count`. `marked_for_review` beim Antworten setzt die Markierung jetzt ebenfalls und wird
  ausgegeben (bisher immer `false`).
- **Neu: Universal Links/App Links** für den Passwort-Reset: Admin-Felder (iOS-App-IDs,
  Android-Paket, SHA-256-Fingerabdrücke), `/.well-known/apple-app-site-association` und
  `/.well-known/assetlinks.json` (nur mit Einträgen, sonst 404), `/app/passwort/` (ohne App Weiterleitung
  zur WordPress-Seite). Reset-Mails aus `POST /auth/password/forgot` verlinken dann auf `/app/passwort/`.
- `POST /auth/password/reset` nimmt statt `email` auch `login` (Benutzername aus dem Reset-Link).
- Neue Fehlercodes `VERSION_CONFLICT`, `WEB_SESSION_DISABLED`, `WEB_SESSION_NOT_ALLOWED`.
- Datenbank: Schema 1.11.0 (Tabelle `bp_mobile_web_code`, Spalte `bp_exam_session_question.flagged`).

### 1.2.0

- **Funktexte (Übersetzung):** Die Lösung kommt erst nach der Auswertung. `GET …/radio/texts/{textId}`
  nimmt `direction` (`de-en` Standard, `en-de`) und liefert `translation_direction` und
  `solution_hidden`. Vor der ersten Auswertung in dieser Richtung ist die Zielsprache
  (`text_en` bzw. `text_de`) null; die Lösung steht in der Antwort von `POST …/evaluate`
  (`solution.to`) und danach auch beim erneuten Abruf. Diktate und Buchstabieren unverändert
  (der Text wird für die Sprachausgabe gebraucht).
- **Vokabeln:** `catalog` ist optional. Ohne Angabe gilt der einzige Vokabelkatalog des Kurses
  (`meta.catalog` nennt ihn); hat der Kurs SRC und UBI, kommt 422.

### 1.1.0 (API-Version, Header `X-Boatingschool-Api-Version`)

- **Neu: Funktraining SRC/UBI** unter `/courses/{courseId}/radio/…` (Übersicht, Buchstabiertafel,
  Funktexte mit Auswertung und „erledigt“, Vokabeln mit „gewusst/nicht gewusst“, Funkpraxis mit
  Auswertung und „erledigt“, Muster-Funksprüche). Kurse ohne Funkinhalte → 404, ohne Zugang → 403.
- **Neu: Prüfungsanmeldung** `GET /courses/{courseId}/exam-registration` (nur lesen) und
  `exam_registration` in den Kursdetails.
- **Neu: Funkgerät-Simulator als Link:** `device_simulator` in den Kursdetails und der
  Funk-Übersicht (`available`, `premium`, `tier`, `web_url`, `hint`, `webshop_url`).
- **Favoriten:** `saved_at` (Pflicht, nie null), bei `collection_id` `assigned_at` (Pflicht, nie null);
  Parameter `sort` (`saved_at`, `assigned_at` nur mit `collection_id`) und `order` (`desc`, `asc`);
  `meta.sort`, `meta.order`, `meta.collection_id`. `created_at` ist veraltet (= `saved_at`).
  Fehlerliste (`/mistakes`): `saved_at` null bei Fragen, die kein Favorit sind.
  Einsortieren in weitere Sammlungen lässt bestehende Zuordnungszeitpunkte unverändert
  (`CollectionService::assign`, gilt auch im Web).
- **Entfernt:** `updated_at` an Sammlungen (war immer null) und
  `POST /auth/email/verification-notification` (→ 404). `email_verified` bleibt `true`.

### 1.0.0

- Erste Fassung der App-Schnittstelle.
