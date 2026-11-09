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
├── TokenAuth.php            Gerätesitzungen (bp_mobile_session): Ausgabe, Prüfung, Rotation, Widerruf
├── Idempotency.php          Idempotency-Key je Nutzer/Vorgang (bp_mobile_idempotency)
├── RateLimiter.php          Zähler und Client-IP hinter Proxys
├── Problem.php, ApiException.php   einheitliches Fehlerobjekt
├── MobileSettings.php       Option bp_learning_mobile (App an/aus, Tenant-Slug, Marke, Proxys)
├── Admin/MobileAdminPage.php  Bootsprüfung → Mobile App
├── Controllers/             Auth, Account, Course, Learning, Exam, Training (Video/Navigation/Praxis),
│                            Coupon, Privacy, File – je ein dünner Adapter auf die Services
└── Mapper/                  Course-, Question-, Media-Mapper (Service-Ergebnis → Vertragsformat)
```

Registriert über eine Zeile in `src/Rest/RestRegistrar.php`. Datenbank: Schema-Version **1.10.0**
mit drei neuen Tabellen (`bp_mobile_session`, `bp_mobile_idempotency`, `bp_mobile_export`); die
Migration läuft beim Plugin-Update automatisch. Alle drei gehören zu den personenbezogenen Tabellen
(Löschung über die WordPress-Datenschutzwerkzeuge bzw. beim Löschen des Kontos), App-Sitzungen
stehen auch im Datenexport (ohne Token-Hashes).

### Endpunkte und genutzte Services

| Pfad | Service im Plugin |
| --- | --- |
| `POST /auth/login` | `wp_authenticate()` (keine Cookies), `TokenAuth::issue` |
| `POST /auth/refresh`, `POST /auth/logout`, `GET/DELETE /me/sessions[/{id}]` | `TokenAuth` |
| `POST /auth/password/forgot`, `POST /auth/password/reset` | `retrieve_password()`, `check_password_reset_key()`, `reset_password()` |
| `GET /me` | WordPress-Konto, `Settings::brand()`, `MobileSettings` |
| `GET /courses`, `GET /courses/{id}` | `ContentRepository::courses`, `AccessService::scopes`, `LearningService::courseTotal`, `ExamService::overview`, `TrainerController::summary`, `Integration::offers`, `VideoUpgrade::offerFor`, `DownloadService`, `SectionFiles`, `Charts` |
| `GET /courses/{id}/overview`, `/modules`, `/progress` | `LearningService::courseProgress` (Module, Themen, Drip, Teilumfang) |
| `GET /courses/{id}/trainer/next` | `LearningService::next` (+ `CollectionService::questionIds`, `ExamService::wrongQuestionIds`) |
| `POST /courses/{id}/trainer/reveal` | `LearningService::reveal` |
| `POST /courses/{id}/attempts`, `GET …/attempts/{id}` | `QuestionPresenter::verify`, `LearningService::answer` |
| `…/favorites`, `…/favorite-collections`, `…/mistakes` | `CollectionService` (`overview`, `assign`, `create`, `rename`, `delete`), `LearningService::setFavorite` |
| `GET/POST /courses/{id}/exams`, `/exams/{id}…` | `ExamService` (`overview`, `start`, `show`, `saveAnswer`, `reveal`, `submit`) |
| `GET /courses/{id}/videos`, `/videos/{id}`, `PUT …/progress` | `VideoService` (`course`, `lesson`, `progress`) |
| `GET /courses/{id}/navigation-tasks`, `/navigation-tasks/{id}`, `POST …/parts/{partId}` | `TrainerService` (`navigationTasks`, `navigationTask`, `navigationAnswer`), `Charts` |
| `GET/PUT /courses/{id}/praxis[/{cardId}]` | `TrainerService` (`praxis`, `praxisStatus`) |
| `POST /coupons/redeem` | `GroupService::join` (Beitrittscodes von Gruppenlizenzen) |
| `POST /profile/export`, `GET /profile/export/{id}[/download]` | alle WordPress-Datenexporte (`PersonalData` des Plugins, WordPress-Konto, WooCommerce) |
| `POST /me/deletion-request` | `wp_create_user_request()`, `wp_send_user_request()` |
| `GET /files/{id}` | `ProtectedFiles::requirement`, `DownloadService::allowed` |
| `GET /health` | – (Server-Prüfung) |

---

## 2. Einrichtung

1. Plugin aktualisieren – die Migration legt die Tabellen an (Systemstatus zeigt Schema 1.10.0).
2. **Bootsprüfung → Mobile App** (nur Administratoren):
   - *App-Schnittstelle aktiv* (Standard: an). Aus = alle App-Anfragen erhalten 503 `MOBILE_API_DISABLED`; der Web-Lernbereich ist nicht betroffen.
   - *Tenant-Slug*: Kennung des Shops für die App. Leer = aus dem Markennamen (Einstellungen → Markenname; derzeit Platzhalter „XXXX“ → `xxxx`). **Vor dem App-Start festlegen und danach nicht mehr ändern.**
   - *Markenfarbe*, *Support-E-Mail* (Standard: Absender von WooCommerce bzw. Admin-E-Mail). Logo = Website-Icon bzw. Theme-Logo.
   - *Vertrauenswürdige Proxys*: nur nötig, wenn ein Proxy/CDN mit öffentlicher IP vorgeschaltet ist.
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
- **Reverse-Proxy:** `X-Forwarded-For` wird nur von Loopback/privaten Netzen und eingetragenen Proxys
  übernommen (Anmelde-Limits je echter Client-IP). Bei CDNs mit eigenem Header (z. B.
  `CF-Connecting-IP`) Proxy-IPs eintragen oder den Filter `bootspruefung_mobile_client_ip` nutzen.
- **Keine Zwischenspeicherung** von `/wp-json/boatingschool/*` in Page-Cache/CDN (die API sendet
  `Cache-Control: private, no-store`). Sicherheits-Plugins/WAF, die die REST-API für Gäste sperren,
  müssen `/wp-json/boatingschool/v1/auth/*` und `/health` zulassen.
- **WP-Cron** (`bp_learning_hourly`) räumt abgelaufene Sitzungen, Idempotenz-Einträge und Exporte auf.
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
   **serialisieren** (nur ein Aufruf gleichzeitig). 409 `REFRESH_TOKEN_ROTATED` = paralleler Aufruf,
   Tokens aus der anderen Antwort verwenden. 401 beim Refresh (`UNAUTHENTICATED`/`REFRESH_TOKEN_REUSED`)
   → Tokens löschen, Login zeigen. Access-Token: 15 Minuten; Refresh: 30 Tage Inaktivität, max. 90 Tage.
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
10. **Konto:** Sitzungen anzeigen/beenden, `POST /profile/export` → Download-URL (mit Token) innerhalb
    von 24 h, `POST /me/deletion-request` (Kontolöschung beantragen, Bestätigung per E-Mail),
    `POST /auth/logout`.

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
| `REFRESH_TOKEN_ROTATED` | 409 | Paralleler Refresh → Tokens der anderen Antwort nutzen |
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
| `RATE_LIMITED` | 429 | Zu viele Anfragen, `retry_after` beachten |
| `MOBILE_API_DISABLED` | 503 | App vom Shop abgeschaltet → Wartungshinweis |
| `HTTPS_REQUIRED` | 403 | Anfrage ohne TLS |
| `METHOD_NOT_ALLOWED` | 405 | Falsche HTTP-Methode |
| `INTERNAL_ERROR` | 500 | Serverfehler → später erneut versuchen |

Limits: Login 10 Fehlversuche/IP und 5/E-Mail in 15 Min.; Passwort vergessen 10/IP, 3/E-Mail pro
Stunde; Refresh 120/IP in 15 Min.; Antworten 60/Min. (wie Web); Codes 10/Std.; Export und Löschantrag
5/Tag.

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
| Refresh-Wiederverwendung | Widerruf | Widerruf; innerhalb 10 s nach Rotation 409 ohne Widerruf | Parallele Refresh-Aufrufe der App sollen nicht abmelden |
| Passwortänderung | Reset widerruft | Jede echte Änderung (Reset, Profil, WooCommerce-Konto, `wp_set_password`) widerruft; reines Neu-Hashen beim Login nicht | Konzept-Vorgabe vollständig |
| Passwort-Reset | min. 12 Zeichen | übernommen (nur in der App) | Vertrag |
| E-Mail-Bestätigung | eingeschränkte Sitzung, `EMAIL_NOT_VERIFIED` | Konten gelten als bestätigt; Endpunkt antwortet 202 ohne Versand | WooCommerce-Konten haben keine Bestätigung; keine künstliche Sperre |
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
| Sammlung `updated_at` | Pflicht | `null` | wird nicht gespeichert |
| Prüfungsoptionen | rule_set/fixed_paper | `id` „simulation“ bzw. Bogen-ID, `locked`/`lock_reason`/`unlock_at`, `running_exam_id`, `meta.live` | Teilumfang, Drip, Übungsmodus |
| Prüfungsfragen | `questionId` | `questions[].id` = Position (1…n), Pfad `/answers/{position}` | Web liefert während der Prüfung bewusst keine Fragen-IDs |
| Prüfung abgelaufen | Status `expired` | `status: evaluated` + `result.expired`; Antworten danach 410 | Plattform wertet abgelaufene Prüfungen automatisch aus |
| `marked_for_review` | gespeichert | nicht gespeichert (immer `false`) | Plattform hat keine Markierung |
| Prüfungsergebnis | Grunddaten, Themen | + Teilergebnisse/Teilgrenzen, `reduced`, `live`, `expired`, falsche Fragen, vollständige Auswertung | Regeln der Plattform (z. B. Basis 5/7, See 18/23) |
| Videos | `/videos/{id}` | Kurs wird aus der Lektion ermittelt; Schrittbilder als Platzhalter „Bild folgt“; `completed` optional | Inhalte haben noch keine Schrittbilder |
| Navigation | `show_solution` | Lösungen erst nach Antwort/Aufdecken; `show_solution=true` deckt dauerhaft auf (wie Web); neuer Endpunkt für Antwort + Einschätzung; `course_id` optional | Aufgaben gehören zu mehreren Kursen |
| Zugangscodes | Bootsschule mailt Code nach Kauf | Kauf schaltet automatisch frei. `/coupons/redeem` löst nur Beitrittscodes von Gruppenlizenzen ein; WooCommerce-Gutschein → 422 `COUPON_NOT_SUPPORTED`; unbekannt → 422 `COUPON_INVALID` mit Hinweis | Gutscheine sind Rabatte, keine Zugänge |
| Datenexport | asynchron, öffentliche Datei mit Link-Token | sofort fertig, in der Datenbank, Download nur mit Bearer-Token, 24 h, automatisch gelöscht; enthält alle WordPress-Exporte (Plugin, Konto, WooCommerce) | kein öffentliches Upload-Verzeichnis |
| Kontolöschung | nicht in der App | `POST /me/deletion-request` (WordPress-Datenschutzanfrage mit Bestätigungsmail) | Apple verlangt einen Weg zur Kontolöschung in der App |
| Idempotency-Key | bei allen POST Pflicht | Pflicht bei `attempts`; optional (aber unterstützt) bei Sammlung, Prüfungsstart, Code, Export | übrige Vorgänge sind ohnehin wiederholbar |
| Neue Endpunkte | – | `/health`, `/me/sessions` (DELETE), `/trainer/reveal`, `/mistakes`, `/exams/{id}/answers/{pos}/reveal`, `/navigation-tasks/{id}/parts/{partId}`, `/praxis`, `/files/{id}`, `/profile/export/{id}/download` | Plattform-Funktionen, Server-Prüfung |
| Fehlerobjekt | RFC 9457 | gleiches Format, `type` als URN, zusätzlich `message`, `details`, `retry_after` | einheitlich und einfach auswertbar |

---

## 6. Tests

- **Integration:** `tests/integration/mobile-api-test.php` (über 120 Prüfungen): Login/Refresh/Rotation/
  Wiederverwendung/Logout, Sitzungen, Passwort-Reset und -Änderung, Rate-Limit (IP, E-Mail, Proxy),
  Tenant, Kursliste mit WooCommerce-Kauf (ohne WooCommerce: manueller Zugang), Smarttrainer mit
  gleichem Lernstand wie im Web, Idempotenz, Favoriten/Sammlungen, Prüfung Start → Antwort →
  Abgabe → Ergebnis, Video, Navigation, Codes, Export, Löschantrag, fremde Daten (IDOR), Abschalten.
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
4. **Funktraining und Gerätesimulation** (SRC/UBI) haben noch keine App-Endpunkte; die Fähigkeit
   `funk` wird angezeigt, die App kann auf `links.web_learning` verweisen. Ebenso die Prüfungsanmeldung.
5. **Passwort-Reset-Link** führt auf die Web-Seite des Shops. Soll er die App öffnen, braucht es
   Universal Links/App Links (apple-app-site-association, assetlinks.json) – nicht Teil dieser Lieferung.
6. **Sprache der WordPress-Mails** (Reset, Löschbestätigung) folgt der Website-Sprache; auf Staging
   englisch, live mit deutschem Sprachpaket deutsch.
7. **Medien:** Videos sind Platzhalter. Für echte Videos klären: Hosting (Datenschutz, keine
   Drittanbieter-Einbettung ohne Einwilligung) und ggf. signierte URLs. Fragenbilder liegen wie im
   Web öffentlich unter `uploads/bootspruefung/media/`.
8. **„Zur Überprüfung markieren“** in Prüfungen gibt es auf der Plattform nicht – gewünscht?
9. Laufzeiten (15 Min./30/90 Tage) und Limits sind derzeit feste Werte im Code; bei Bedarf einstellbar machen.
