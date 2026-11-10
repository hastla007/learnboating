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
│                            Links in Reset- und Löschbestätigungs-Mail
├── Idempotency.php          Idempotency-Key je Nutzer/Vorgang (bp_mobile_idempotency), Übernahme verwaister Einträge
├── RateLimiter.php          Atomare Zähler (bp_mobile_rate_limit) und Client-IP hinter eingetragenen Proxys
├── Problem.php, ApiException.php   einheitliches Fehlerobjekt
├── MobileSettings.php       Option bp_learning_mobile (App an/aus, Tenant-Slug, Marke, Proxys,
│                            Webansicht-Anmeldung, iOS-/Android-App-Kennungen)
├── Admin/MobileAdminPage.php  Bootsprüfung → Mobile App
├── Controllers/             Auth (inkl. Web-Sitzung), Account, Settings, Course (inkl. Prüfungsanmeldung), Learning, Exam,
│                            Training (Video/Navigation/Praxis), Radio (Funktraining), Coupon, Privacy,
│                            File – je ein dünner Adapter auf die Services
└── Mapper/                  Course-, Question-, Media-Mapper (Service-Ergebnis → Vertragsformat)
```

Registriert über eine Zeile in `src/Rest/RestRegistrar.php`. Datenbank: Schema-Version **1.12.0**
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
| `GET /me` | WordPress-Konto, `Settings::brand()`, `MobileSettings` |
| `GET /courses`, `GET /courses/{id}` | `ContentRepository::courses`, `AccessService::scopes`, `LearningService::courseTotal`, `ExamService::overview`, `TrainerController::summary`, `Integration::offers`, `VideoUpgrade::offerFor`, `DownloadService`, `SectionFiles`, `Charts`, `DeviceSimService::available/premium` (`device_simulator`), `ExamGuideService::pagesFor` (`exam_registration`) |
| `GET /courses/{id}/exam-registration` | `ExamGuideService` (`enabled`, `payload` = Anleitungen + `committees`) wie `ExamGuideController` |
| `GET /courses/{id}/overview`, `/modules`, `/progress` | `LearningService::courseProgress` (Module, Themen, Drip, Teilumfang) |
| `GET /courses/{id}/trainer/next` | `LearningService::next` (+ `CollectionService::questionIds`, `ExamService::wrongQuestionIds`) |
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
| `POST /coupons/redeem` | `GroupService::join` (Beitrittscodes von Gruppenlizenzen) |
| `POST /profile/export`, `GET /profile/export/{id}[/download]` | asynchron: Action Scheduler (`as_enqueue_async_action`) bzw. `wp_schedule_single_event`, Hook `bp_mobile_export_generate` → `PrivacyController::generate`; alle WordPress-Datenexporte (`PersonalData` des Plugins, WordPress-Konto, WooCommerce) |
| `POST /me/deletion-request` | `wp_create_user_request()`, `wp_send_user_request()` (mit App-Kennungen: Link über `AppLinks`) |
| `POST /me/deletion-request/confirm` | `wp_validate_user_request_key()`, Aktion `user_request_action_confirmed` (wie `wp-login.php?action=confirmaction`) |
| `GET /files/{id}` | `ProtectedFiles::requirement`, `DownloadService::allowed`, Range (`FileController::parseRange`) |
| `GET /health` | – (Server-Prüfung) |
| außerhalb der API: `/.well-known/apple-app-site-association`, `/.well-known/assetlinks.json`, `/app/passwort/`, `/app/konto-loeschen/` | `AppLinks` (früher `parse_request`-Handler) |

---

## 2. Einrichtung

1. Plugin aktualisieren – die Migration legt die Tabellen an (Systemstatus zeigt Schema 1.12.0).
2. **Bootsprüfung → Mobile App** (nur Administratoren):
   - *App-Schnittstelle aktiv* (Standard: an). Aus = alle App-Anfragen erhalten 503 `MOBILE_API_DISABLED`; der Web-Lernbereich ist nicht betroffen.
   - *Tenant-Slug*: Kennung des Shops für die App. Leer = aus dem Markennamen (Einstellungen → Markenname; derzeit Platzhalter „XXXX“ → `xxxx`). **Vor dem App-Start festlegen und danach nicht mehr ändern.**
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
     Bestätigungslink der Kontolöschung (`/app/konto-loeschen/`).
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

## 3. Ablauf für App-Entwickler

1. **Start:** optional `GET /health` (Wartung? `status: disabled`).
2. **Anmelden:** `POST /auth/login` mit `email` (oder Benutzername), `password`, `device_name`,
   `device_platform`, `app_version`. Antwort: Token-Paar, `user`, `memberships`. Tokens nur im
   Keychain/Keystore speichern. Es gibt genau eine Mitgliedschaft (ein Shop = eine Bootsschule).
3. **Jede weitere Anfrage:** `Authorization: Bearer <access_token>` und – außer bei `/auth/*`, `/me*`,
   `/files/*`, Export-Download – `X-Tenant-Slug: <memberships[0].tenant_slug>`.
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
6. **Smarttrainer:** `GET /courses/{id}/trainer/next?mode=…` → Frage + `answer_token` (2 h gültig).
   Antwort: `POST /courses/{id}/attempts` mit `answer_token`, `selected_answer_ids` und Header
   `Idempotency-Key` (UUID, pro Antwort neu, bei Netzwiederholung gleich). Offene Fragen
   (`answer_selection: open`): erst `POST …/trainer/reveal`, dann `known: true|false` senden.
   204 bei `next` = im Modus nichts übrig. Korrektheit nie lokal ableiten.
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
    Anmeldung (der Link kann auch auf einem Gerät ohne angemeldete App geöffnet werden). 200 =
    bestätigt (die Bootsschule bearbeitet den Antrag); 422 `DELETION_CONFIRMATION_INVALID` =
    ungültig, abgelaufen (24 h) oder schon bestätigt → ggf. neu beantragen. Ohne App öffnet der
    Browser die Adresse und die Website leitet auf die WordPress-Bestätigung weiter.

**Prüfung – Markieren (seit 1.3.0):** `PUT /exams/{examId}/answers/{position}/flag` mit
`{"flagged": true|false}` (auch `marked_for_review` beim Antworten). `GET /exams/{examId}` liefert
`flagged` je Frage (in `questions[]` und `answers[]`) und `flagged_count` – beim Fortsetzen
wiederherstellen. Nach Abgabe 409, nach Fristende 410. Das Ergebnis zeigt `review[].flagged`.
Der Web-Lernbereich zeigt Markierungen noch nicht an.

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
| `EXAM_NOT_SUBMITTED` | 409 | Ergebnis vor Abgabe angefragt |
| `EXAM_EXPIRED` | 410 | Zeit abgelaufen, Prüfung ausgewertet → Ergebnis laden |
| `EXAM_ANSWER_LOCKED` | 409 | Offene Prüfungsfrage: erst Musterantwort aufdecken |
| `COUPON_INVALID` | 422 | Code unbekannt/ungültig (Hinweis: Shop-Käufe brauchen keinen Code) |
| `COUPON_NOT_SUPPORTED` | 422 | WooCommerce-Gutschein (nur beim Kauf im Shop einlösbar) |
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
| `METHOD_NOT_ALLOWED` | 405 | Falsche HTTP-Methode |
| `INTERNAL_ERROR` | 500 | Serverfehler → später erneut versuchen |

Limits (seit 1.4.0 atomare Zähler in `bp_mobile_rate_limit`, Fenster ab dem ersten Treffer): Login
5 Fehlversuche je E-Mail + IP, 10 je IP und 30 je E-Mail insgesamt in 15 Min. (Erfolg setzt E-Mail + IP
zurück); Passwort vergessen 10/IP, 3/E-Mail pro Stunde; Refresh 120/IP in 15 Min.; Antworten 60/Min.
(wie Web); Codes 10/Std. (Wiederholungen mit gleichem Idempotency-Key zählen nicht); Export und
Löschantrag 5/Tag; Löschbestätigung 20/IP und 10/Antrag pro Stunde; Web-Anmeldecodes 20 je Konto in
15 Min.

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
| Zugangsstatus | active/expired/suspended/revoked/not_granted | zusätzlich `limited` (Schnupper-Umfang) und `pending` (Zahlung offen), dazu `trial`, `purchased`, `limits` | Plattform kennt Teilumfang und Zahlungsstatus |
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
| `marked_for_review` | gespeichert | seit 1.3.0 gespeichert (`bp_exam_session_question.flagged`), eigener Endpunkt `PUT …/answers/{position}/flag`, `flagged`/`flagged_count` in Prüfung und Ergebnis; bis 1.2.0 immer `false` | Wunsch des App-Teams; Web zeigt Markierungen noch nicht |
| Prüfungsergebnis | Grunddaten, Themen | + Teilergebnisse/Teilgrenzen, `reduced`, `live`, `expired`, falsche Fragen, vollständige Auswertung | Regeln der Plattform (z. B. Basis 5/7, See 18/23) |
| Videos | `/videos/{id}` | Kurs wird aus der Lektion ermittelt; Schrittbilder als Platzhalter „Bild folgt“; `completed` optional | Inhalte haben noch keine Schrittbilder |
| Navigation | `show_solution` | Lösungen erst nach Antwort/Aufdecken; `show_solution=true` deckt dauerhaft auf (wie Web); neuer Endpunkt für Antwort + Einschätzung; `course_id` optional | Aufgaben gehören zu mehreren Kursen |
| Zugangscodes | Bootsschule mailt Code nach Kauf | Kauf schaltet automatisch frei. `/coupons/redeem` löst nur Beitrittscodes von Gruppenlizenzen ein; WooCommerce-Gutschein → 422 `COUPON_NOT_SUPPORTED`; unbekannt → 422 `COUPON_INVALID` mit Hinweis | Gutscheine sind Rabatte, keine Zugänge |
| Datenexport | asynchron, öffentliche Datei mit Link-Token | asynchron (seit 1.4.0; `pending` → `ready`/`failed`, Action Scheduler bzw. WP-Cron, Rückfall in der Statusabfrage), in der Datenbank, Download nur mit Bearer-Token, 24 h, automatisch gelöscht; enthält alle WordPress-Exporte (Plugin, Konto, WooCommerce) | kein öffentliches Upload-Verzeichnis; keine Zeitüberschreitung bei großen Konten (bis 1.3.x synchron) |
| Kontolöschung | nicht in der App | `POST /me/deletion-request` (WordPress-Datenschutzanfrage mit Bestätigungsmail); seit 1.4.0 öffnet der Mail-Link die App (`/app/konto-loeschen/`), Bestätigung über `POST /me/deletion-request/confirm` | Apple verlangt einen Weg zur Kontolöschung in der App; Bestätigung ohne Wechsel in den Browser |
| Prüfung nach Zugangsende | – | Abgabe und Ergebnis möglich, weitere Antworten 403 `CONTENT_LOCKED`, kein Neustart (wie Web, gleicher Service) | Bereits Gearbeitetes nicht verlieren, aber keine weitere Nutzung ohne Zugang |
| Webansicht-Anmeldung | – | seit 1.4.0 nur mit Prüfwert-Header der App-WebView, kein Kontowechsel bei anderer bestehender Anmeldung | Schutz vor Login-CSRF über weitergeleitete Einmal-Links |
| Idempotency-Key | bei allen POST Pflicht | Pflicht bei `attempts`; optional (aber unterstützt) bei Sammlung, Prüfungsstart, Code, Export | übrige Vorgänge sind ohnehin wiederholbar |
| Neue Endpunkte | – | `/health`, `/me/sessions` (DELETE), `/trainer/reveal`, `/mistakes`, `/exams/{id}/answers/{pos}/reveal`, `/exams/{id}/answers/{pos}/flag`, `/navigation-tasks/{id}/parts/{partId}`, `/praxis`, `/radio/…`, `/exam-registration`, `/files/{id}`, `/profile/export/{id}/download`, `/auth/web-session`, `/me/settings`, `/me/deletion-request/confirm` | Plattform-Funktionen, Server-Prüfung, Wünsche des App-Teams (1.3.0, 1.4.0) |
| Fehlerobjekt | RFC 9457 | gleiches Format, `type` als URN, zusätzlich `message`, `details`, `retry_after` | einheitlich und einfach auswertbar |

---

## 6. Tests

- **Integration:** `tests/integration/mobile-api-test.php` (rund 320 Prüfungen, mit WooCommerce
  324): Login/Refresh/Rotation/Wiederverwendung/verlorene Refresh-Antwort/Logout, Sitzungen,
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
  Daten (IDOR), Abschalten, Markieren in Prüfungen, Sitzungsübergabe App → Web (offene Weiterleitung,
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

- **CI:** Schritt „App-Schnittstelle boatingschool/v1“ nach dem WooCommerce-Adapter – einmal ohne,
  einmal mit WooCommerce.
- **Vertrag:** `python3 -c "from openapi_spec_validator import validate; from openapi_spec_validator.readers import read_from_filename; validate(read_from_filename('docs/mobile-api/openapi.yaml')[0])"`

---

## 7. Offene Punkte / Entscheidungen für den Auftraggeber

1. **Kauflink in der App (App-Store-Regeln):** Apple (3.1.1) und Google erlauben Links zu externen
   Käufen digitaler Inhalte nur eingeschränkt bzw. je Land. Vor dem Release rechtlich klären, ob
   `webshop_url` angezeigt werden darf (z. B. als „Reader“-App ohne Kaufhinweis, oder nur im Web).
2. **Tenant-Slug und Markenname** festlegen (derzeit Platzhalter `xxxx`/„XXXX“) – danach nicht mehr ändern.
3. **Kontolöschung:** Nach der Bestätigung bearbeitet der Shop den Antrag in WordPress (Werkzeuge →
   Personenbezogene Daten löschen, danach Konto löschen). Soll das automatisch passieren? (Achtung
   Aufbewahrungsfristen für Bestellungen.)
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
   (`PUT /exams/{examId}/answers/{position}/flag`, `flagged` in Prüfung und Ergebnis). Der
   Web-Lernbereich zeigt und setzt Markierungen noch nicht – soll das Web nachziehen?
9. Laufzeiten (15 Min./30/90 Tage), das Kulanzfenster beim Refresh (60 s) und Limits sind derzeit
   feste Werte im Code; bei Bedarf einstellbar machen.
10. **Sprachausgabe im Funktraining (entschieden):** Vorerst liest die App Diktate mit der
    Sprachausgabe des Geräts vor (das Web mit der Browserstimme, Einstellung `radio_voice_output`).
    Eigene Tonaufnahmen werden später ergänzt; dann kommt ein Audio-Feld in die Antworten.
11. **Automatische Bewertung freier Antworten (untersucht, nicht umgesetzt):** Navigationsaufgaben
    (15 Aufgaben, 135 Teilaufgaben) speichern nur die amtliche Lösung als Text (`answer_text`, z. B.
    `rwK = 079°`, `6,1 sm`, `53° 55,8' N 007° 51,3' E`, `Siehe Karte`); bewertet wird per
    Selbsteinschätzung. Rund 60 % sind Zahlenwerte (Kurse/Peilungen, Positionen, Distanzen, Zeiten,
    Fahrt), ein Viertel „Siehe Karte“ (Eintragung in die Karte), der Rest Beschreibungen. Eine
    automatische Prüfung der Zahlenwerte wäre mit strukturierten Lösungen (Größe, Wert, Einheit,
    Toleranz) machbar; das erfordert eine redaktionelle Erweiterung des Inhaltspakets. Entscheidung
    des Auftraggebers offen.

---

## 8. Änderungsprotokoll

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
- Datenbank: Schema **1.12.0** (Tabelle `bp_mobile_rate_limit`, Spalten `bp_mobile_export.started_at`,
  `bp_mobile_web_code.verifier_hash`). Deinstallation entfernt zusätzlich geplante Export-Aufgaben.

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
