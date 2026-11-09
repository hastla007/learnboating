# Mobile Lern-App für BoatingSchool

**Produktkonzept, Wireframes und API-Dokumentation**  
Stand: 8. November 2026  
Grundlage: öffentliches Repository `hastla007/boatingschool` und dessen README, Web-Routen und Laravel-Implementierung.

> **Status der API:** Das Repository implementiert aktuell eine Laravel-Webanwendung. Die geprüften Routen liegen in `routes/web.php`; es wurde keine mobile REST-API gefunden. Die Endpunkte in diesem Dokument sind daher ein Zielentwurf für eine Ergänzung des bestehenden Backends, keine Behauptung bereits verfügbarer API-Funktionen. Produktannahmen des Auftraggebers: bestehende Web-Konten gelten auch für die App; Bootsschul-Mitarbeitende benutzen die App nicht; Prüfungsregeln gelten fachlich als freigegeben; Medien bleiben vorerst Platzhalter.

## 1. Kurzfassung und Empfehlung

Die bestehende Plattform soll um eine mobile Lernoberfläche ergänzt werden, die Kunden einer Bootsschule (Lernende) auf iOS und Android nutzen können. Die mobile App greift auf dieselbe Laravel-Plattform, dieselben mandantenübergreifenden Kursinhalte und dieselben tenantgebundenen Freischaltungen, Antworten, Favoriten und Lernstände zu. Es wird keine zweite Lernlogik und kein separater Fragenbestand aufgebaut.

**Festgelegter Ansatz:** Flutter-App für iOS und Android, versionierte JSON-REST-API im bestehenden Laravel-Monolithen, zentraler Auth- und Berechtigungsdienst. Die App ist online-only: Lerninhalte und Lernstände werden nur bei bestehender Internetverbindung geladen; es gibt keinen Offline-Zugriff auf Kursinhalte und keine Offline-Antworten. Bei Verbindungsverlust zeigt die App einen Offline-Zustand mit Wiederholen-Aktion.

**Sprache:** Die App und die im MVP ausgelieferten Lerninhalte sind ausschließlich auf Deutsch verfügbar. Es gibt keine Sprachwahl und keine Übersetzungs-/Lokalisierungsinfrastruktur im MVP.

Die App priorisiert vier Aufgaben: **Weiterlernen**, **Thema gezielt üben**, **Prüfungssimulation** und **Fortschritt verstehen**. Videos, Favoriten, Navigation und Praxisinhalte sind kursabhängige Lernbereiche. Prüfungen bleiben strikt: keine sofortige Antwortauflösung, serverseitiger Timer und unveränderliches Ergebnis nach Abgabe.

## 2. Produktziel und Zielgruppen

### Ziel

Lernende sollen unterwegs in kurzen Einheiten sicher und nachvollziehbar für Bootsführerschein- und Funkprüfungen lernen können. Die App ergänzt die bestehende Bootsschul-Webplattform und nutzt deren White-Label-Branding, Zugänge und Lerninhalte.

### Zielgruppen

- **Lernende/Kunden:** lernen auf eigenem Smartphone, wollen schnell dort weitermachen, wo sie aufgehört haben, und ihren Stand bis zur Prüfung einschätzen.
- **Bootsschule/Schulpersonal:** nutzt die App nicht. Verwaltung, Teilnehmerbetreuung und Freischaltung laufen weiterhin über die bestehende Web-Admin-Oberfläche. Die App zeigt Lernenden lediglich den Kontext und das Branding ihrer Schule.
- **Plattformbetrieb:** pflegt zentralen, versionierten Content und die fachlichen Prüfungsregeln.

### Produktprinzipien

1. **Ein Konto, ein Lernstand:** mobile App und Weboberfläche verwenden dieselbe Identität und dieselben serverseitigen Lernstände.
2. **Mandantensicherheit zuerst:** jeder API-Aufruf wird einem Tenant zugeordnet und serverseitig autorisiert. IDs aus einem anderen Tenant liefern keine Daten.
3. **Gleiche Lernlogik:** Smarttrainer-Priorisierung und Antwortbewertung bleiben in Laravel.
4. **Prüfung ist Prüfung:** Antworten und Punkte werden während eines Examens nicht aufgelöst; der Server setzt Zeitlimit, Regelwerk-Snapshot und Ergebnis durch.
5. **Kleine Schritte:** gute Lesbarkeit, große Touch-Flächen, barrierearme Kontraste, kurze Sitzungen und klare Erklärungen.
6. **Klarer Zugang:** Kurszugang und Laufzeit sind sichtbar; Kaufen/Verlängern führt im MVP zum vorhandenen externen Webshop-Link.

## 3. Bestand aus dem Repository und Produktfolgen

Die Plattform ist ein modularer Laravel-12-Monolith mit Blade/Tailwind und PostgreSQL. Inhalte und Fachlogik sind bereits umfangreich vorhanden:

- Mandantenfähige Bootsschulen, White-Label-Branding und Tenant-Mitgliedschaften; tenantgebundene Tabellen werden zusätzlich mit PostgreSQL Row-Level Security geschützt.
- Zentrale Kursdefinitionen und Module, veröffentlichte Frageversionen, kursbezogene Freischaltungen (Entitlements), Fortschritt, Favoriten, Versuche und Prüfungen.
- Smarttrainer mit deterministischer, erklärbarer Priorisierung (neue Fragen, zuletzt falsche Fragen, schwache Themen, Wiederholung) und serverseitiger Bewertung.
- Prüfungssimulation mit serverseitigem Ergebnis, Regelwerk-Snapshot, festen Bögen für bestimmte Kurse, Navigationstrainer, Videokurs und weiteren Lernbereichen.
- Externer Webshop-Link statt integrierter Zahlung; Coupons/Freischaltcodes werden in der Plattform eingelöst.
- Datenschutzfunktionen für Datenexport; eine Kontolöschung bleibt in der bestehenden Webplattform und ist kein App-Feature.

**Folgerung:** die Mobile-App ist in erster Linie ein neuer Client und kein Neuentwurf der Lernplattform. Neue API-Controller/Resources und ein mobiles Auth-Verfahren ergänzen die vorhandenen Services. Wo die bestehende Route HTML rendert, wird für Mobile eine JSON-Antwort angeboten, die dieselbe Service- und Policy-Schicht verwendet.

### Vor App-Launch zu klärende Bestandsrisiken

- Laut README verweisen angelegte Videolektionen derzeit zum Teil auf ein öffentliches Platzhaltervideo. Für die aktuelle Entwicklungsphase werden neutrale, nicht abspielbare Platzhalter verwendet; vor einem späteren produktiven Videobetrieb ist zu entscheiden, ob echte lizenzierte Medien eingesetzt werden.
- **Prüfungsregeln:** Sie gelten nach Produktentscheidung fachlich als freigegeben. Im Repository stehen viele Regelwerke technisch noch auf `draft`/unverifiziert; deren Status muss an diese Entscheidung angepasst werden, damit sie in der Prüfungssimulation tatsächlich startbar sind.
- **Medien:** Für den aktuellen Stand werden bewusst sichtbare Platzhalter verwendet. Im API-Vertrag stehen sie als `kind: placeholder`, `available: false`, `url: null`; die App zeigt eine neutrale Kachel („Inhalt folgt“) ohne Play-Aktion statt eines scheinbar verfügbaren Videos.
- Tenant-Auflösung existiert per Pfad/Subdomain/Custom Domain. Für die App wird der Tenant aus der vorhandenen Mitgliedschaft ermittelt; ein schulbezogener App-Link darf die Auswahl lediglich vorausfüllen. Schulpersonal erhält keinen mobilen App-Zugang.
- Bestehende Web-Konten, Passwort-Reset und E-Mail-Bestätigung gelten auch für Mobile. Sitzungen werden pro Gerät verwaltet; der Vertrag sieht Sitzungsliste, Einzelwiderruf und Widerruf aller Sitzungen bei Passwortwechsel vor.

## 4. Umfang

### MVP (erste veröffentlichte Version)

- Anmeldung mit bestehendem Web-Konto einschließlich Passwort-Reset und E-Mail-Bestätigung; aktive Tenant-Mitgliedschaften werden danach vom Server geladen.
- Kursübersicht mit freigeschalteten Kursen, Laufzeit, Fortschritt sowie gesperrten/kaufbaren Kursen und externem Shop-Link. Kauf läuft extern; die Bootsschule sendet danach den Einlösecode per E-Mail.
- Kursstart/Dashboard mit „Weiterlernen“, Lernbereichen, Themenstand und Kurszugang.
- Smarttrainer: nächste Frage abrufen, Antwort senden, serverseitig auswerten, Erklärung und Lernhinweis anzeigen.
- Modi: Smarttrainer, neue Fragen, zuletzt falsche Fragen, Thema/Modul, Favoriten (entsprechend der serverseitigen Möglichkeiten).
- Favoriten anlegen/entfernen und vorhandene Sammlungen anzeigen.
- Prüfungssimulation starten/fortsetzen, Fragen beantworten, markieren/zurückspringen, abgeben und Ergebnis sehen. Kein Sofortfeedback in der Prüfung.
- Videokurs im MVP: Video-/Lektionsübersicht, Lektion mit Player oder sichtbarem Platzhalter sowie Schritt-Galerie. Sehfortschritt wird mit der Webplattform synchronisiert.
- Synchronisierter Fortschritt und letzte Aktivitäten zwischen Web und Mobile.
- Accountprofil (lesend), Abmelden, Gerätesitzungen, Datenschutz-Export und Support-/Schulhinweise. Kontodaten müssen in der App nicht geändert werden; eine Kontolöschung wird in der App nicht angeboten.

### Nach MVP

- Navigationstrainer und Praxisbereich als native optimierte Lernflows. Video-Quiz und vertiefte Video-Interaktionen können später folgen.
- Push-Mitteilungen (Lern-Erinnerungen nur opt-in; keine sensiblen Inhalte auf Sperrbildschirm).
- Offline-Nutzung und Offline-Downloads sind nicht vorgesehen.
- In-App-Käufe oder Commerce-Integration erst nach fachlicher und Store-Payment-Entscheidung; aktuell bleibt der externe Webshop.
- Keine Schul-Administration in der App; Schulpersonal bleibt auf der Web-Admin-Oberfläche.

### Nicht-Ziele des MVP

- Neue Content-Autorensoftware oder redaktioneller Freigabeprozess.
- Zahlungsabwicklung, Rechnungsstellung oder automatisierte Domain-Provisionierung.
- Offline-Zugriff auf Inhalte und Prüfungsmodus.
- Eigene App-Datenbank als führendes System oder separate Prüfungslogik im Client.

## 5. Mobile Informationsarchitektur

**Hauptnavigation (Bottom Bar):** Start · Lernen · Prüfung · Fortschritt · Profil

- **Start:** Begrüßung im Bootsschul-Branding, „Weiterlernen“, aktive Kurse, nächster empfohlener Schritt, optional Schulhinweis.
- **Lernen:** Kurse → Module/Themen → Lernmodus → Frage/Erklärung; separate Karten für Video, Navigation, Praxis, Favoriten, sofern im Kurs verfügbar.
- **Prüfung:** Prüfungssimulation, feste Bögen falls verfügbar, laufende Prüfung fortsetzen, vergangene Ergebnisse.
- **Fortschritt:** Gesamtstand und pro Modul/Thema, richtige/falsche Antworten und Lernstatus; keine irreführenden Prognosen zur Prüfungssicherheit.
- **Profil:** Konto, Schule, Kurszugänge, Benachrichtigungen, Datenschutz, Hilfe, Abmelden.

## 6. Wireframes

Die Wireframes sind Low-Fidelity-Strukturvorschläge für ein Smartphone (ca. 390 × 844 dp). Text in eckigen Klammern bezeichnet dynamische Werte.

### 6.1 Anmeldung und Schulzuordnung

```text
┌──────────────────────────────┐
│ [Bootsschul-Logo]            │
│ Willkommen zurück            │
│                              │
│ E-Mail-Adresse               │
│ [________________________]   │
│ Passwort                     │
│ [________________________] ◉ │
│ [ Anmelden ]                 │
│ Passwort vergessen?          │
│                              │
│ Bestehendes Web-Konto        │
│ Schule wird nach Login       │
│ aus Mitgliedschaft gewählt   │
│                              │
│ Impressum · Datenschutz      │
└──────────────────────────────┘
```

Flow: Lernende melden sich mit dem bestehenden Web-Konto an. Nach Login lädt die App die aktiven Mitgliedschaften vom Server. Eine Schule wird automatisch gewählt, wenn genau eine Mitgliedschaft besteht; bei mehreren kann der Lernende wechseln. Ein Bootsschul-Link darf eine Schule vorauswählen, ersetzt aber nie die serverseitige Mitgliedschaftsprüfung. Der per E-Mail von der Schule versandte Kurs-Zugangscode ist ein separater Coupon und wird erst nach dem externen Kauf im App-Bereich „Zugangscode einlösen“ eingegeben. Schulpersonal meldet sich nicht in der App an.

### 6.2 Start / Weiterlernen

```text
┌──────────────────────────────┐
│ Guten Morgen, [Vorname]      │
│ [Schulname]             [•••]│
│                              │
│ WEITERLERNEN                 │
│ SBF See · 62 %               │
│ Zuletzt: Vorfahrtsregeln     │
│ [ Weiterüben · 8 Min. ]      │
│                              │
│ DEINE KURSE                  │
│ [SBF See]       [62 % →]     │
│ [SRC]           [18 % →]     │
│                              │
│ HEUTE EMPFOHLEN              │
│ Lichterführung · 5 Fragen    │
│ [ Thema starten ]            │
│                              │
│ Start  Lernen  Prüfung  Stand│
└──────────────────────────────┘
```

### 6.3 Kursübersicht

```text
┌──────────────────────────────┐
│ ‹ Meine Kurse                │
│                              │
│ SBF See                      │
│ [██████████░░░░] 62 %        │
│ Zugang bis 14.06.2027        │
│ [ Kurs öffnen ]              │
│                              │
│ Funk (SRC)                   │
│ [███░░░░░░░░░░] 18 %         │
│ [ Kurs öffnen ]              │
│                              │
│ Weitere Kurse                │
│ SBF Binnen · nicht freigesch.│
│ [ Beim Schulshop ansehen ]   │
│ [ Zugangscode einlösen ]     │
│                              │
│ Start  Lernen  Prüfung  Stand│
└──────────────────────────────┘
```

### 6.4 Kurs-Lernhub

```text
┌──────────────────────────────┐
│ ‹ SBF See            [•••]   │
│ Kursfortschritt       62 %   │
│ [████████████░░░░░░]         │
│                              │
│ [ Weiter mit Smarttrainer ]  │
│                              │
│ LERNBEREICHE                 │
│ Fragen üben            →     │
│ Nach Themen             →     │
│ Falsche Fragen          →     │
│ Favoriten               →     │
│ Prüfungssimulation      →     │
│ Videokurs               →     │
│ Navigation              →     │
│                              │
│ Start  Lernen  Prüfung  Stand│
└──────────────────────────────┘
```

Nur Features anzeigen, die Kurs und Tenant unterstützen. Nicht veröffentlichte/inaktive Fragen werden nicht angeboten.

### 6.5 Smarttrainer-Frage

```text
┌──────────────────────────────┐
│ ‹ SBF See         Frage 8    │
│ Thema: Ausweichregeln        │
│ [Fragetext, ggf. Bild/Karte] │
│                              │
│ ○ Antwort A                  │
│ ○ Antwort B                  │
│ ○ Antwort C                  │
│ ○ Antwort D                  │
│                              │
│ [ Favorit ☆ ]                │
│ [ Antwort prüfen ]           │
│                              │
│ Nach Abgabe:                 │
│ ✓ Richtig / ✕ Nicht richtig  │
│ Erklärung / Merksatz         │
│ [ Nächste Frage ]            │
│                              │
│ Sitzung beenden              │
└──────────────────────────────┘
```

Vor dem Absenden keine Korrektheit verraten. Bei Netzwerkfehler Antwort lokal als nicht gesendet kennzeichnen und Wiederholen ermöglichen. Idempotency-Key verhindert doppelte Versuche bei Retry.

### 6.6 Prüfungssimulation

```text
┌──────────────────────────────┐
│ ‹ Prüfung      18:42 verbleib.│
│ Frage 7 von 30         [⚑]   │
│ [Prüfungsfrage + ggf. Bild]  │
│                              │
│ ○ Antwort A                  │
│ ○ Antwort B                  │
│ ○ Antwort C                  │
│ ○ Antwort D                  │
│                              │
│ [‹ Zurück]      [Weiter ›]   │
│                              │
│ 1 2 3 4 5 6 7 8 … [Übersicht]│
│                              │
│ [ Prüfung abgeben ]          │
└──────────────────────────────┘
```

Antworten werden während der laufenden Prüfung nicht aufgelöst. Timer basiert auf serverseitiger `expires_at`; die Client-Uhr ist nur Anzeige. Bei Verbindungsabbruch vorhandene Antworten erneut laden, Exam fortsetzen und Status klar anzeigen.

### 6.7 Prüfungsergebnis

```text
┌──────────────────────────────┐
│ Prüfung abgeschlossen        │
│                              │
│       NICHT BESTANDEN        │
│        18 von 30             │
│                              │
│ Ergebnis nach Themen         │
│ Navigation      4/8          │
│ Vorfahrt        7/8          │
│ Schallsignale   7/14         │
│                              │
│ [ Fehler üben ]              │
│ [ Prüfung erneut starten ]   │
│ [ Zur Kursübersicht ]        │
└──────────────────────────────┘
```

Bestehenslabel und Zahl werden ausschließlich aus dem serverseitigen Prüfergebnis gerendert.

### 6.8 Vollständige Seitenliste und Designumfang

Für das Lernenden-MVP sind **24 App-Seiten** vorgesehen. Der Designumfang umfasst **46 Frames**: 24 MVP-Seiten, 19 wichtige Zustands-/Ergebnis-Frames und 3 Navigationsseiten aus dem Nach-MVP-Umfang. Die zusätzlichen Zustände sind separat in der API-Zuordnung dokumentiert.

#### MVP – 24 Seiten

| Nr. | Seite | Zweck |
|---:|---|---|
| 1 | Anmeldung | Login mit bestehendem Web-Konto |
| 2 | Passwort zurücksetzen | Reset-E-Mail anfordern |
| 3 | Neues Passwort setzen | Aufruf über den signierten Reset-Link |
| 4 | E-Mail bestätigen | Status anzeigen und Bestätigungs-E-Mail erneut anfordern |
| 5 | Schule auswählen | Nur wenn das Konto mehreren aktiven Schulen angehört |
| 6 | Start/Dashboard | Weiterlernen, Kurse und Empfehlungen |
| 7 | Kursübersicht | Freigeschaltete und gesperrte Kurse, Fortschritt, Shop-Link, Code-Einlösung |
| 8 | Kurs-Lernhub | Kursfortschritt und Lernbereiche |
| 9 | Module/Themen | Themenfortschritt und gezielter Einstieg |
| 10 | Smarttrainer-Frage | Frage, Antwortoptionen und Favorit-Aktion |
| 11 | Antwortfeedback | Richtig/falsch, Erklärung und nächste Frage; eigener UI-Zustand des Trainer-Flows |
| 12 | Favoriten | Gespeicherte Fragen und Sammlungen |
| 13 | Prüfungsübersicht | Prüfungsregeln und feste Bögen |
| 14 | Prüfungsdetails/Start | Umfang, Zeitlimit und Prüfungsregeln vor dem Start |
| 15 | Laufende Prüfung | Frage, Timer, Navigation und Antwortspeicherung |
| 16 | Prüfungsergebnis | Ergebnis und Themenzusammenfassung |
| 17 | Fortschritt | Gesamt- und Themenstand |
| 18 | Zugangscode einlösen | Coupon-Eingabe nach Erhalt der E-Mail von der Bootsschule |
| 19 | Video-/Lektionsübersicht | Videomodule und Lektionen; Platzhalter bleiben sichtbar |
| 20 | Lektion | Player bei verfügbarem Medium; sichtbare „Video folgt“-Kachel ohne Play-Aktion bei Platzhalter |
| 21 | Schritt-Galerie | Schritt-für-Schritt-Inhalt innerhalb einer Lektion |
| 22 | Profil/Einstellungen | Kontodaten lesend, aktuelle Schule, Support und App-Einstellungen |
| 23 | Gerätesitzungen | Eigene App-Geräte anzeigen und einzelne Sitzungen widerrufen |
| 24 | Datenexport | Export anfordern und Status/Download anzeigen |

**Zustands-Frames im Designumfang:** Ladezustand; leere Kurs- und Favoritenliste; abgelaufener Kurszugang; ungültiger oder bereits eingelöster Code; erfolgreicher Code; Netzwerkfehler; abgelaufene Prüfung; alle Smarttrainer-Fragen erledigt; kein Shop-Link; Export bereit, fehlgeschlagen oder abgelaufen; Sitzung abgelaufen; Login fehlgeschlagen; Reset-Link ungültig/abgelaufen; keine aktive Schulmitgliedschaft; Formular zum Erstellen/Umbenennen einer Sammlung; Löschbestätigung für eine Sammlung. Die E-Mail-Bestätigung ist als MVP-Seite enthalten. Der externe Shop wird im Systembrowser geöffnet und zählt nicht als App-Seite; nach Kauf sendet die Schule den Code per E-Mail. Kontolöschung bleibt auf der bestehenden Webplattform.

#### Nach dem MVP – 3 weitere Seiten

| Seite | Zweck |
|---|---|
| Navigationstrainer-Übersicht | Aufgabenliste |
| Navigationsaufgabe | Aufgabe, Karte/Medien und Teilfragen |
| Musterlösung | Auflösung erst auf Anforderung im Übungsmodus |

Die drei Video-/Lektionsseiten gehören zum MVP. Die unteren App-Tabs sind Navigationselemente und keine zusätzlichen Seiten. Schul-Admin- und Teilnehmerverwaltungsseiten sind ausgeschlossen, da Bootsschulen die App nicht verwenden.

### 6.9 Screen-für-Screen-Zuordnung zu API und Fehlerzuständen

Die folgende Zuordnung beschreibt den **Zielvertrag** aus `openapi-boatingschool-mobile-v1.yaml`. Die Endpunkte sind im Konzept vorgesehen, aber noch nicht als Mobile-API implementiert. `X-Tenant-Slug` ist Auswahlkontext; die API muss die aktive Mitgliedschaft bei jedem Tenant-Aufruf erneut serverseitig prüfen. Bei Listen bedeutet `200` mit leerem `data` eine gültige leere Liste und keinen Fehler.

#### MVP-Screens

| Nr. / Screen | API-Aufruf(e) | Erfolgsantwort und UI-Verhalten | Relevante Fehler / UI-Zustand |
|---|---|---|---|
| 1 Anmeldung | `POST /auth/login`; danach `GET /me` | `200 LoginResponse`; Token sicher speichern, Profil und Mitgliedschaften laden | `401 UNAUTHENTICATED`: Anmeldedaten prüfen; `422 VALIDATION_FAILED`: Feldfehler; `429 RATE_LIMITED`: Wartehinweis. Netzwerkfehler: Eingabe erhalten, erneut versuchen |
| 2 Passwort zurücksetzen | `POST /auth/password/forgot` | `202`: neutrale Bestätigung anzeigen, unabhängig davon, ob die E-Mail existiert | `422 VALIDATION_FAILED`: E-Mail-Format; `429 RATE_LIMITED`: später erneut versuchen |
| 3 Neues Passwort setzen | `POST /auth/password/reset` | `204`: Erfolg anzeigen und zurück zum Login; bestehende Sitzungen sind widerrufen | `422 PASSWORD_RESET_INVALID_OR_EXPIRED`: Reset-Link ablehnen und neuen Reset anfordern; sonst `422 VALIDATION_FAILED` für Passwort-/Feldfehler |
| 4 E-Mail bestätigen | `GET /me`; `POST /auth/email/verification-notification` | `GET /me` liefert Verifizierungsstatus; `202` für erneuten Versand; nach Rückkehr aus signiertem Link `GET /me` neu laden | `401 UNAUTHENTICATED`: Sitzung erneuern oder neu anmelden; `429 RATE_LIMITED`: Versand abkühlen lassen; `EMAIL_NOT_VERIFIED`: Bestätigungszustand beibehalten |
| 5 Schule auswählen | Mitgliedschaften aus `GET /me` bzw. `LoginResponse`; danach `GET /courses` mit `X-Tenant-Slug` | Eine aktive Mitgliedschaft direkt wählen, mehrere als Auswahl anzeigen | Keine Mitgliedschaft: eigener Leerzustand; `TENANT_MEMBERSHIP_REQUIRED`/`TENANT_MEMBERSHIP_SUSPENDED`: Zugriff erklären und Profil/Schule neu laden |
| 6 Start/Dashboard | `GET /me`; `GET /courses`; `GET /courses/{courseId}/overview` für zuletzt genutzten Kurs | Kursfortschritt und Wiedereinstieg aus Overview; Empfehlungen aus verfügbaren Kursdaten | `401`: Refresh versuchen; `EMAIL_NOT_VERIFIED`, `TENANT_MEMBERSHIP_REQUIRED`, `ENTITLEMENT_EXPIRED`: passende Zugangssperre; Netzwerkfehler mit Retry |
| 7 Kursübersicht | `GET /courses`; optional `GET /courses/{courseId}`; Shop-Link aus Kursdaten | `200`: aktive/verfügbare Kurse und Zugänge; Shop extern im Systembrowser öffnen | Leere Liste: eigener Empty State; `ENTITLEMENT_EXPIRED`: Zugang abgelaufen; fehlende Shop-URL: Shop-Aktion verbergen/erklären, kein Fehlerdialog |
| 8 Kurs-Lernhub | `GET /courses/{courseId}/overview` | Fortschritt und Lernbereiche anzeigen | `403 ENTITLEMENT_REQUIRED`/`ENTITLEMENT_EXPIRED`: Zugangshinweis; `404 RESOURCE_NOT_FOUND`: zurück zur Kursübersicht |
| 9 Module/Themen | `GET /courses/{courseId}/modules` | Module und Themen mit Fortschritt anzeigen; Themenaktion startet passenden Lernfluss | Leere Liste als leerer Kursinhalt; `403`/`404` wie Kurszugang; Netzwerkfehler mit Retry |
| 10 Smarttrainer-Frage | `GET /courses/{courseId}/trainer/next`; Favorit: `PUT /courses/{courseId}/favorites/{questionId}` oder `DELETE ...` | `200`: Frage/Antwortoptionen und Versuchszähler (1 von 3 bis 3 von 3); Frage nach dem dritten gespeicherten Versuch erledigt | `204`: alle Fragen im gewählten Bereich erledigt; `403 ENTITLEMENT_REQUIRED`/`ENTITLEMENT_EXPIRED`; `404`; `422 VALIDATION_FAILED`; Favoritenfehler: Toggle zurücksetzen und Retry anbieten |
| 11 Antwortfeedback | `POST /courses/{courseId}/attempts`; bei Wiederaufnahme `GET /courses/{courseId}/attempts/{attemptId}` | `201`: serverseitiges Ergebnis, Erklärung und nächste Frage; correctness nicht lokal ableiten | `409 IDEMPOTENCY_KEY_REUSED`: gespeicherten Versuch laden; `403`/`422`: Feedback nicht als gespeichert markieren; Verbindungsausfall: Antwort mit gleicher Idempotency-Key wiederholen |
| 12 Favoriten | `GET /courses/{courseId}/favorites`; Sammlungen `GET/POST /courses/{courseId}/favorite-collections`; umbenennen/löschen `PATCH/DELETE .../{collectionId}` | `200`: Favoriten und Sammlungen; Favoriten einer Sammlung über `collection_id` filtern; Erstellen `201`, Umbenennen `200`, Löschen `204` | Leere Liste: Empty State; `403`/`404`; `422 VALIDATION_FAILED` bei ungültigem Sammlungsnamen; beim Löschen bleiben Fragenfavoriten erhalten und werden „Ohne Sammlung“ zugeordnet |
| 13 Prüfungsübersicht | `GET /courses/{courseId}/exams` | `200`: feste Prüfungsbögen und Regeln | `403 ENTITLEMENT_REQUIRED`/`ENTITLEMENT_EXPIRED`; leere Liste als kein Prüfungsbogen; `404` |
| 14 Prüfungsdetails/Start | `GET /exams/{examId}`; Start `POST /courses/{courseId}/exams` | `201`: neue oder fortsetzbare Prüfung; Details vor Start aus Serverdaten | `403`; `409 EXAM_NOT_STARTABLE` oder bereits laufende Prüfung: Zustand aktualisieren; `422`: Eingabe/Startkonflikt erklären |
| 15 Laufende Prüfung | `GET /exams/{examId}`; Antwort speichern `PUT /exams/{examId}/answers/{questionId}`; Abgabe `POST /exams/{examId}/submit` | `200`: Antwort gespeichert; Abgabe liefert finales Ergebnis. Timer/Fragenstatus aus Serverzeit/-status synchronisieren | `410 EXAM_EXPIRED`: Prüfung beenden und Ergebnisstatus laden; `409 EXAM_ANSWER_LOCKED`/`EXAM_ALREADY_SUBMITTED`: Antwort schreibgeschützt; `403`/`404`; ohne Verbindung keine Prüfungsinhalte laden oder Antworten absenden; gespeicherte Serverantworten nach Wiederverbindung neu laden |
| 16 Prüfungsergebnis | `GET /exams/{examId}/result` | `200`: serverseitiges Ergebnis und Themenübersicht | `403`: Zugriff nicht erlaubt; weitere Fälle gemäß Problemantwort behandeln. Der aktuelle Vertrag spezifiziert für diesen Endpunkt weder `404` noch `409` noch `410` |
| 17 Fortschritt | `GET /courses/{courseId}/progress` | `200`: Gesamt- und Themenstand | `403`/`404`; leere Fortschrittsdaten als Anfangszustand; Netzwerkfehler mit Retry |
| 18 Zugangscode einlösen | `POST /coupons/redeem` mit Tenant-Kontext und Idempotency-Key; anschließend `GET /courses` | `200`: Erfolg, betroffenen Kurszugang sichtbar machen und Kursliste aktualisieren | `422 COUPON_INVALID`: Code prüfen; `409 COUPON_ALREADY_REDEEMED`: bereits eingelöst erklären; `403 TENANT_MEMBERSHIP_REQUIRED`/`ENTITLEMENT_REQUIRED`; `429 RATE_LIMITED` |
| 19 Video-/Lektionsübersicht | `GET /courses/{courseId}/videos` | `200`: Module/Lektionen inklusive Verfügbarkeitsstatus | `403`/`404`; leere Liste als Empty State; fehlende Medien als sichtbarer Platzhalter, nicht als Ladefehler |
| 20 Lektion | `GET /videos/{lessonId}`; Fortschritt `PUT /videos/{lessonId}/progress` | `200`: Lektion und Medien-URL; Platzhalter ohne Player-Aktion; Fortschritt nach Ansicht speichern | `403`/`404`; nicht verfügbares Video als Platzhalterstatus; Speicherfehler als nicht synchronisiert kennzeichnen |
| 21 Schritt-Galerie | `GET /videos/{lessonId}` (Schritt-/Medienfelder) | `200`: sortierte Schritte mit Text/Medien; Galerie innerhalb der Lektion | `403`/`404`; keine Schritte als verständlicher Empty State; Medienfehler mit Retry pro Inhalt |
| 22 Profil/Einstellungen | `GET /me`; Support-Kontakt ggf. aus Tenant-Konfiguration | `200`: Kontodaten und Schule lesend anzeigen; App-Einstellungen lokal speichern | `401`: Sitzung erneuern; keine aktive Mitgliedschaft: entsprechender Zustand. Kontodaten werden in der App nicht geändert; daher ist kein Profil-Update-Endpunkt erforderlich |
| 23 Gerätesitzungen | `GET /me/sessions`; `DELETE /me/sessions/{sessionId}` | `200`: eigene Sitzungen; `204`: einzelne Sitzung widerrufen, Liste aktualisieren | `401`; `404`: Sitzung bereits entfernt; Netzwerkfehler: Status nachladen, keine falsche Erfolgsmeldung |
| 24 Datenexport | `POST /profile/export`; Status `GET /profile/export/{exportId}` | `202`: Export angefordert; Polling mit angemessenem Abstand; `ready` mit kurzlebigem `download_url` herunterladen | Status `failed`/`expired`; `403`/`404`; Link abgelaufen: Exportstatus aktualisieren und erneute Anforderung anbieten |

#### Zustands- und Ergebnis-Frames

| Frame/Zustand | Auslöser und erwartetes Verhalten |
|---|---|
| Ladezustand | Request läuft: Skeleton für erste Ladung; bei Aktualisierung vorhandene Daten stehen lassen und kleines Ladefeedback zeigen |
| Leere Kursliste | `GET /courses` liefert `200` und leeres `data`; nächste sinnvolle Aktion: Code einlösen oder externen Shop öffnen, falls URL vorhanden |
| Leere Favoritenliste | `GET .../favorites` liefert leeres `data`; Link zurück zum Smarttrainer |
| Zugang abgelaufen | `Problem.code=ENTITLEMENT_EXPIRED`; Kursdaten nicht als verfügbar behandeln, klare Rückkehr-/Support-Aktion |
| Ungültiger Code | `COUPON_INVALID`; Eingabe behalten, Feldfehler anzeigen |
| Code bereits verwendet | `COUPON_ALREADY_REDEEMED`; keine erneute Erfolgsmeldung, Kurszugänge neu laden |
| Code erfolgreich eingelöst | `200`; Erfolgsbestätigung, neue Zugänge aus `GET /courses` nachladen |
| Netzwerk-/Serverfehler | Kein API-Fehlercode oder `5xx`; Retry anbieten, Daten und Eingaben soweit sicher erhalten |
| Prüfung abgelaufen | `410 EXAM_EXPIRED`; Antwortaktionen sperren, gespeicherte Antworten sichern/nachladen und Ergebnis-/Übersichtsweg anbieten |
| Alle Smarttrainer-Fragen erledigt | `204` bei `GET .../trainer/next`, wenn jede aktive Frage dreimal beantwortet wurde; Fortschritt/Zusammenfassung anzeigen und Rückweg zu Modulen/Favoriten anbieten |
| Kein Shop-Link | `shop_url` fehlt; Shop-Aktion ausblenden oder mit ruhigem Hinweis ersetzen |
| Export bereit | `status=ready` und URL vorhanden; Download-Aktion zeigen, URL nicht langfristig speichern |
| Export fehlgeschlagen | `status=failed`; verständlicher Fehler und neue Anforderung anbieten |
| Export/Download-Link abgelaufen | `status=expired` oder URL nicht mehr gültig; Schaltfläche „Export erneut bereitstellen“ startet mit `POST /profile/export` einen neuen Export, danach Status neu laden |
| Sitzung abgelaufen | geschützter Request erhält `401`; Refresh einmal ausführen; bei Refresh-`401` Session löschen und Login zeigen |
| Login fehlgeschlagen | `401 UNAUTHENTICATED`; generische Anmeldedatenmeldung, Passwort nicht aus Feld löschen |
| Reset-Link ungültig/abgelaufen | `422 VALIDATION_FAILED` am Reset-Token; Link als nicht mehr gültig erklären, neuen Reset anfordern |
| Keine aktive Schulmitgliedschaft | `TENANT_MEMBERSHIP_REQUIRED` oder leere aktive Mitgliedschaften; kein endloser Ladezustand, Support/Kontakt anbieten |

#### Gemeinsame Zuordnung der Fehlercodes

| HTTP / `Problem.code` | Standardbehandlung |
|---|---|
| `401 UNAUTHENTICATED` | Bei geschütztem Request genau ein Refresh-Versuch; bei Fehlschlag Tokens löschen und Login verlangen. Login selbst zeigt generische Fehlermeldung. |
| `EMAIL_NOT_VERIFIED` | Verifizierungsseite öffnen; eingeschränkte Kontofunktionen vermeiden; erneuten Versand ermöglichen. |
| `TENANT_MEMBERSHIP_REQUIRED`, `TENANT_MEMBERSHIP_SUSPENDED` | Schulkontext neu laden; Auswahl oder erklärenden Mitgliedschaftszustand zeigen. Keine automatische Auswahl einer gesperrten Schule. |
| `ENTITLEMENT_REQUIRED`, `ENTITLEMENT_EXPIRED` | Kurs-/Feature-Sperre kontextbezogen erklären; Kursliste und Shop-Link nur dann anbieten, wenn vorhanden. |
| `RESOURCE_NOT_FOUND` | Ressource nicht verfügbar; zum nächsthöheren gültigen Screen navigieren. Keine rohe Servermeldung zeigen. |
| `VALIDATION_FAILED` | Feldbezogene Fehler aus `errors` anzeigen; Eingabe möglichst erhalten. |
| `PASSWORD_RESET_INVALID_OR_EXPIRED` | Reset-Link als ungültig/abgelaufen anzeigen und direkt eine neue Reset-Anforderung ermöglichen. |
| `IDEMPOTENCY_KEY_REUSED` | Bestehenden Vorgang/Versuch per GET abgleichen, niemals mutierende Aktion blind doppelt ausführen. |
| `EXAM_NOT_STARTABLE`, `EXAM_ALREADY_SUBMITTED`, `EXAM_EXPIRED`, `EXAM_ANSWER_LOCKED` | Prüfungszustand aktualisieren; gesperrte Aktion entfernen; Serverzustand als maßgeblich behandeln. |
| `COUPON_INVALID`, `COUPON_ALREADY_REDEEMED` | Codefeld direkt markieren bzw. bereits eingelösten Zustand erklären. |
| `RATE_LIMITED` | Wiederholungsaktion deaktivieren und `Retry-After` berücksichtigen, falls geliefert. |

Bei allen Fehlerantworten `request_id` für Support/Diagnose intern protokollieren und bei Supportkontakt mitsenden, nicht als technische Kennung prominent im UI anzeigen. API-Nachrichten werden nicht ungefiltert als UI-Text übernommen.

### 6.10 Entwickler-Handoff: Komponenten und visuelle Regeln

#### Komponentenbibliothek

| Komponente | Varianten und Verhalten |
|---|---|
| `AppScaffold` | Safe Areas, Hintergrund, optionaler App-Bar-Bereich; Bottom-Navigation auf Hauptbereichen, in Prüfungen ausgeblendet oder reduziert |
| `TopAppBar` | Titel, Zurück-Navigation, optionale kontextbezogene Aktion; Titel bleibt bei langen Texten zugänglich |
| `BottomNavigation` | 4–5 Hauptbereiche mit Icon und Textlabel; aktiver Zustand zusätzlich durch Form/Farbe markiert |
| `Button` | Primary, Secondary, Tertiary, Destructive; Größen `large` und `regular`; Lade- und Disabled-Zustand, Mindesthöhe 48 dp |
| `TextField` | Label immer sichtbar, Hilfetext, Fehlertext, Passwort-Sichtbarkeit, Code-Eingabe; Fehler programmatisch mit Feld verknüpfen |
| `CourseCard` / `CourseRow` | Titel, Status, Fortschritt, Zugang und optionale Shop-/Weiterlernen-Aktion |
| `ProgressBar` / `ProgressRing` | Zahlenwert zusätzlich zum visuellen Balken ausgeben; 0 und 100 Prozent korrekt beschriften; Smarttrainer-Wiederholungen als „Versuch 1 von 3“ anzeigen |
| `ModuleRow` / `TopicRow` | Fortschritt, Status und klarer Einstieg; Status nicht nur durch Farbe vermitteln |
| `QuestionCard` / `AnswerOption` | Frage, Medien und Single-/Multi-Select; ausgewählt, korrekt/falsch erst nach Serverfeedback, gesperrt |
| `FavoriteButton` | Toggle mit zugänglichem Zustand „Als Favorit speichern/entfernen“; Serverfehler setzt optimistischen Zustand zurück |
| `FeedbackPanel` | Erfolg/Fehler-Icon plus Text, Erklärung und nächste Aktion; Screenreader-Ankündigung nach Antwort |
| `ExamTimer` / `QuestionNavigator` | Restzeit textlich und visuell; beantwortet, offen und aktuelle Frage mit zusätzlichen Labels unterscheiden |
| `StatusView` | Einheitliche Lade-, Leer-, Offline-, Fehler- und Erfolgsansichten mit Titel, Erklärung und passender Aktion; Offlineansicht bietet „Erneut laden“ |
| `CouponForm` | Codefeld, Inline-Fehler, Ladezustand und Erfolg; Eingabe nach ungültigem Code erhalten |
| `VideoPlayer` / `VideoPlaceholder` | Player nur bei gültigem Medium; Platzhalter ohne irreführende Play-Schaltfläche |
| `LessonStepCard` / `StepGallery` | Nummerierung, Text und Medien; Reihenfolge auch ohne visuelle Position verständlich |
| `ExportStatusCard` | Wartend, Verarbeitung, bereit, fehlgeschlagen, abgelaufen; Download nur mit gültiger URL |
| `ConfirmDialog` | Für destruktive Aktionen mit Konsequenztext, eindeutigen Aktionen und nicht destruktiver Abbruchoption |

#### Design-Tokens und Abstände

| Token | Vorgabe |
|---|---|
| Raster | 4-dp-Grundraster; Abstände vorzugsweise 4, 8, 12, 16, 20, 24, 32 dp |
| Seitenrand | 20 dp horizontal, bei sehr schmalen Viewports mindestens 16 dp; Inhalt nicht unter System-Safe-Areas legen |
| Karten | 16 dp Innenabstand; 16–20 dp Radius; zwischen Karten 12–16 dp |
| Abschnitte | 20–24 dp vertikaler Abstand; Label zu Inhalt 8 dp; zusammengehörige Controls 8–12 dp |
| Touch-Ziele | mindestens 48 × 48 dp auf Android; mindestens 44 × 44 pt auf iOS, auch wenn das sichtbare Icon kleiner ist |
| Typografie | Figtree gemäß Web-Marke; Richtwerte: 12–14 sp Zusatzinfo, 16 sp Fließtext/Formulare, 20–24 sp Seitentitel; Textskalierung nicht beschneiden |
| Farben | Markenblau `#005FD7`, Teal `#007A7A`; pro Tenant konfigurierbare Markenfarben. Erfolg/Warnung/Fehler bleiben semantisch und kontrastgeprüft. |
| Bewegung | kurze, sparsame Übergänge; Systemoption „Bewegung reduzieren“ respektieren; Ladeanimationen nicht als einzige Statusinformation |

Komponenten erhalten einheitliche Zustandsnamen (default, pressed, focused, disabled, loading, error, success). Für Datenlisten werden Skeletons nur beim initialen Laden benutzt; beim Aktualisieren bleiben vorhandene Daten sichtbar. Für Mutationen wird ein Button während der Anfrage gegen Doppeltippen geschützt. Abstände sind als Tokens zu implementieren, nicht pro Screen frei zu schätzen.

### 6.11 Barrierefreiheit und Abnahme

- **Zielniveau:** WCAG 2.2 AA für Inhalte und Interaktionen; mindestens 4,5:1 Kontrast bei normalem Text, 3:1 bei großem Text und relevanten UI-Grafiken.
- **Touch und Fokus:** Interaktive Ziele mindestens 48 dp (Android) bzw. 44 pt (iOS). Sichtbarer Tastatur-/Switch-Fokus; Fokus nach Navigation, Dialogöffnung und Validierungsfehler sinnvoll setzen.
- **Screenreader:** Semantische Rollen, sprechende Namen und Zustände für Favoriten, Tabs, Fortschritt, Auswahloptionen, Timer, Lade- und Fehlerzustände. Icons ohne Text bekommen einen zugänglichen Namen.
- **Nicht nur Farbe:** Richtig/falsch, Zugang, Fortschritt und Prüfungstatus zusätzlich mit Text, Icon oder Muster kennzeichnen.
- **Formulare:** Labels dauerhaft sichtbar; Pflichtfelder, Eingabeformat und Fehler mit dem Feld verknüpfen. Fehler kurz und konkret formulieren; nach Absenden zum ersten fehlerhaften Feld führen.
- **Dynamische Inhalte:** Antwortfeedback und erfolgreicher Code-/Exportstatus als höfliche Live-Ansage melden. Keine wiederholte Screenreader-Ansage des Timers pro Sekunde; Zeitwarnungen nur an sinnvollen Schwellen ankündigen.
- **Skalierung und Layout:** Dynamic Type/Font Scaling unterstützen, auf 320 dp Breite ohne horizontales Scrollen nutzbar bleiben und große Textgrößen berücksichtigen. UI-Sprache ist Deutsch; es gibt keine Sprachwahl oder übersetzten Texte im MVP.
- **Prüfung:** Timer, gespeicherte Antwort, nicht gespeicherte Antwort und Abgabezustand sowohl visuell als auch assistiv verständlich machen. Vor Abgabe klare Zusammenfassung unbeantworteter Fragen; keine Antwortkorrektheit vor der Abgabe preisgeben.
- **Medien und Bewegung:** Videos mit Untertiteln/Transkript, Bilder mit sinnvollen Alternativtexten; keine Information ausschließlich in Animation oder Audio. Systemweite Bewegungsreduktion beachten.
- **Abnahmekriterium pro Screen:** erfolgreicher Zustand, leerer Zustand (falls Liste), Ladezustand, mindestens ein relevanter Fehlerzustand, Screenreader-Namen, Fokusreihenfolge, vergrößerte Schrift und Kontrast in der Umsetzung prüfen.

#### Festgelegte API-Entscheidungen für die Umsetzung

1. **Favoritensammlungen:** Die Endpunkte zum Auflisten, Erstellen, Umbenennen und Löschen sind im API-Zielvertrag enthalten. Beim Löschen einer Sammlung bleiben ihre Favoriten erhalten und werden keiner Sammlung zugeordnet.
2. **Export-Link:** Läuft der kurzlebige Download-Link ab, zeigt die App „Export erneut bereitstellen“ und ruft `POST /profile/export` mit einer neuen Idempotency-Key auf. Der neue Exportstatus wird anschließend wie gewohnt abgefragt.
3. **Reset-Link-Fehler:** `PASSWORD_RESET_INVALID_OR_EXPIRED` ist als eigener maschinenlesbarer Code bei `422` definiert.
4. **Smarttrainer-Wiederholungen:** Jede aktive Frage wird über alle Übungsmodi hinweg genau dreimal als beantworteter Versuch gezählt. Nach dem dritten gespeicherten Versuch gilt sie als erledigt und wird in keinem Modus erneut ausgeliefert. `204` bedeutet im Standardmodus, dass der gesamte aktive Fragenpool erledigt ist; gefilterte Modi können `204` liefern, wenn keine offene Frage zum Filter passt.

## 7. Nutzerflüsse und Regeln

### Erster Start

1. Nutzer meldet sich mit bestehendem Web-Konto an; ein optionaler Bootsschul-Link kann eine Mitgliedschaft vorwählen.
2. App lädt die Mitgliedschaften vom Server; bei mehreren wählt der Lernende eine Schule.
3. API prüft Tenant-Mitgliedschaft und E-Mail-Bestätigung; App zeigt nur freigeschaltete oder verfügbare Kurse.
4. Zum Kauf öffnet die App den hinterlegten externen Webshop der Schule.
5. Die Bootsschule sendet den Zugangscode per E-Mail; der Lernende gibt ihn in der App ein und der Server aktiviert den Zugang.

### Übung

1. Nutzer wählt Smarttrainer, Modul/Thema, neue/falsche/Favoriten.
2. API liefert nur erlaubte, aktuell gültige veröffentlichte Frage und zugehörige Medien.
3. App zeigt den Wiederholungsstand „Versuch n von 3“; Nutzer sendet Antwort mit Idempotency-Key.
4. Server validiert Kurszugang, Revision, Fragezugehörigkeit und Modus, speichert Attempt unveränderlich und zählt einen gültigen Versuch genau einmal – unabhängig davon, ob die Antwort richtig ist.
5. Server liefert Ergebnis, Erklärung und Zähler zurück. Nach dem dritten gespeicherten Versuch gilt die Frage als erledigt; der Standardmodus liefert sie nicht erneut.

### Prüfung

1. Nutzer wählt einen freigegebenen Kurs und optional festen Bogen.
2. Server erstellt Exam-Snapshot und Start-/Ablaufzeit sowie festgelegte Fragen.
3. Antworten werden mit PUT je Frage gespeichert; Wiederholung derselben Eingabe ist idempotent.
4. Ergebnis wird erst nach expliziter Abgabe oder serverseitigem Ablauf berechnet.
5. Ergebnis bleibt an die konkreten Content-Revisionen und das Regelwerk gebunden.

## 8. Technisches Zielbild

```text
Flutter iOS/Android
  ├── secure token storage, HTTP client, Online-/Offline-Status
  └── JSON /api/v1/* über HTTPS
              │
Laravel 12 API-Schicht (neu)
  ├── Mobile Auth / Token-Lifecycle
  ├── Tenant resolution + Policies + Form Requests
  ├── API Resources / Versionierung / Rate Limits
  └── bestehende Services und Modelle
              │
PostgreSQL 16 + bestehende RLS/TenantContext
```

### Client

- **Flutter** ist festgelegt für gemeinsame iOS-/Android-Codebasis und kontrollierbare UI. Separates Admin-Frontend ist nicht Teil des App-Projekts.
- Zustandsverwaltung modular pro Feature; HTTP-Client mit Refresh/Logout-Behandlung, Retry nur für sichere/idempotente Requests.
- Token und sensible lokale Daten ausschließlich in Keychain/Keystore-geschütztem Speicher.
- Kein lokaler Cache für Kursinhalte, Fragen, Prüfungen, Videos oder Lernstände. Token bleiben im Keychain/Keystore; lokale UI-Einstellungen dürfen gespeichert werden.
- Medien über signierte oder CDN-URLs mit sinnvollen Ablaufzeiten; kein dauerhaft öffentliches private URL-Muster.

### Backend

- `routes/api.php` bzw. Laravel API-Routen neu anlegen; Prefix und Version `/api/v1`.
- API-Controller orchestrieren vorhandene Services (`EntitlementService`, `SmarttrainerService`, `LearningService`, `ExamEvaluationService`), statt dieselben Regeln neu zu programmieren.
- Auth-Implementierung: bestehende Nutzeridentität und Passwort-/E-Mail-Abläufe wiederverwenden. Laravel Sanctum ist eine naheliegende Ergänzung für mobile Bearer-Tokens, ist im aktuellen `composer.json` aber nicht als Paket aufgeführt. Pro Gerät wird eine widerrufbare Sitzung geführt; der Vertrag setzt 15 Minuten Access-Token und rotierende Refresh-Tokens (30 Tage Inaktivität, max. 90 Tage) als konfigurierbare Vorgabe.
- App-Nutzer sind ausschließlich Lernende/Kunden. Tenant wird aus der aktiven Mitgliedschaft gewählt (ein optionaler Schul-Link kann vorauswählen); `X-Tenant-Slug` dient nur als Kontext und wird bei jedem Request gegen die Nutzer-Mitgliedschaft geprüft. Schulpersonalrollen werden von der mobilen API nicht für App-Administration freigeschaltet.
- Authentifizierte Requests setzen Tenant/User-DB-Kontext, bevor RLS-geschützte Daten gelesen werden; Kontext wird am Request-Ende sicher zurückgesetzt.
- OpenAPI wird als veröffentlichte Spezifikation gepflegt und aus API-Tests oder Schema-Definitionen auf Drift kontrolliert.

## 9. API-Konventionen

- Basis: `https://{tenant-domain}/api/v1` oder zentrale API-Domain plus `X-Tenant-Slug: {slug}`.
- Inhaltstyp: `application/json`; Zeichensatz UTF-8. Zeitstempel RFC 3339 UTC.
- Authentifizierung: `Authorization: Bearer <access_token>`.
- Tenant: `X-Tenant-Slug` erforderlich für tenantgebundene Endpunkte; ausschließlich Kontext, kein Zugriffsrecht.
- Pagination: `page` und `per_page` (Default 20, Maximum 100) sowie `meta.pagination`.
- Fehlerformat: `application/problem+json` nach RFC 9457-Stil mit `type`, `title`, `status`, `detail`, `code`, optional `errors` und `request_id`.
- Sprache: UI, API-validierungsnahe Texte und ausgelieferte Kursinhalte im MVP ausschließlich Deutsch; keine Sprachwahl oder Übersetzungsmechanik. Der Client mappt maschinenlesbare Fehlercodes auf deutsche Meldungen.
- Online-Zwang: Kurs-, Fragen-, Video-, Prüfungs- und Fortschrittsdaten werden nur online angefordert. Kursinhalte werden nicht für Offline-Nutzung zwischengespeichert. Bei Offline-Erkennung zeigt die App einen globalen Offline-Zustand und ermöglicht erneutes Laden, sobald die Verbindung wiederhergestellt ist.
- Konflikt-/Retry-Schutz: `Idempotency-Key` UUID bei POST-Aktionen mit Nebenwirkung, insbesondere Attempt, Exam, Coupon, Export und neue Favoritensammlung; Schlüssel pro Nutzer/Tenant/Operation speichern.
- Cache: nur öffentliche statische Inhalte mit explizitem ETag; persönliche Lernstände und Prüfungen `Cache-Control: private, no-store`.
- IDs sind undurchsichtig zu behandeln. Clients dürfen keine tenantfremden IDs erraten oder Rechte aus clientseitigen Feldern ableiten.

## 10. API-Ressourcen und Endpunkte

Alle Endpunkte unten sind **Zielentwurf**. „Auth“ bedeutet Bearer-Token, „Tenant“ bedeutet bestätigter Tenant-Kontext plus aktive Membership, „Kurszugang“ bedeutet wirksames Entitlement.

| Methode und Pfad | Zweck | Zugriff | Statuscodes |
|---|---|---|---|
| `POST /auth/login` | Login, Access-/Refresh-Token (oder langfristig rotierender Token) | öffentlich, Tenant-Code/Slug erforderlich | 200, 401, 422, 429 |
| `POST /auth/refresh` | Access-Token rotieren | Refresh-Token | 200, 401 |
| `POST /auth/logout` | aktuelles Gerät/Token widerrufen | Auth | 204, 401 |
| `GET /me` | Profil, Tenant, Rollen, Branding | Auth + Tenant | 200, 401, 403 |
| `GET /courses` | freigeschaltete und verfügbare Kurse | Auth + Tenant | 200, 401, 403 |
| `GET /courses/{courseId}` | Kursmetadaten und Lernbereiche | Auth + Tenant + Kurszugang | 200, 403, 404 |
| `GET /courses/{courseId}/overview` | Gesamt-/Modulfortschritt, Weiterlernen | Auth + Tenant + Kurszugang | 200, 403, 404 |
| `GET /courses/{courseId}/modules` | Module/Themen | Auth + Tenant + Kurszugang | 200, 403, 404 |
| `GET /courses/{courseId}/trainer/next?mode=&module_id=&topic=` | nächste Trainerfrage | Auth + Tenant + Kurszugang | 200, 204, 403, 404, 422 |
| `POST /courses/{courseId}/attempts` | Antwort absenden; Server bewertet und speichert | Auth + Tenant + Kurszugang | 201, 409, 422 |
| `GET /courses/{courseId}/attempts/{attemptId}` | gespeicherte Rückmeldung erneut laden | Eigentümer + Tenant | 200, 404 |
| `GET /courses/{courseId}/favorites` | Favoriten und optionale Sammlung | Auth + Tenant + Kurszugang | 200, 403 |
| `GET /courses/{courseId}/favorite-collections` | eigene Sammlungen des Lernenden | Auth + Tenant + Kurszugang | 200, 403 |
| `POST /courses/{courseId}/favorite-collections` | Sammlung erstellen | Auth + Tenant + Kurszugang | 201, 422 |
| `PATCH /courses/{courseId}/favorite-collections/{collectionId}` | Sammlung umbenennen | Eigentümer + Tenant + Kurszugang | 200, 404, 422 |
| `DELETE /courses/{courseId}/favorite-collections/{collectionId}` | Sammlung löschen, Favoriten behalten | Eigentümer + Tenant + Kurszugang | 204, 404 |
| `PUT /courses/{courseId}/favorites/{questionId}` | Favorit aktivieren/Collection setzen | Auth + Tenant + Kurszugang | 200, 204, 403, 404 |
| `DELETE /courses/{courseId}/favorites/{questionId}` | Favorit entfernen | Auth + Tenant + Kurszugang | 204, 403, 404 |
| `GET /courses/{courseId}/exams` | freigegebene Regelwerke/Bögen und letzte Ergebnisse | Auth + Tenant + Kurszugang | 200, 403 |
| `POST /courses/{courseId}/exams` | Exam starten oder laufendes Exam fortsetzen | Auth + Tenant + Kurszugang | 201, 409, 422 |
| `GET /exams/{examId}` | Session, Fragen, gegebene Antworten, Timerstatus | Eigentümer + Tenant | 200, 404, 410 |
| `PUT /exams/{examId}/answers/{questionId}` | Antwort sichern; keine Auflösung | Eigentümer + Tenant | 200, 409, 410, 422 |
| `POST /exams/{examId}/submit` | abgeben und serverseitig auswerten | Eigentümer + Tenant | 200, 409, 410 |
| `GET /exams/{examId}/result` | unveränderliches Ergebnis | Eigentümer + Tenant | 200, 404, 409 |
| `GET /courses/{courseId}/progress` | Fortschritt nach Bereichen | Auth + Tenant + Kurszugang | 200, 403 |
| `GET /courses/{courseId}/videos` | verfügbare Video-Module/Lektionen | Auth + Tenant + Kurszugang | 200, 403 |
| `PUT /videos/{lessonId}/progress` | Sehfortschritt speichern | Auth + Tenant + Kurszugang | 200, 403, 404 |
| `POST /profile/export` | neuen DSGVO-Datenexport anfordern | Auth + Tenant-Mitgliedschaft | 202, 401, 429 |
| `GET /profile/export/{exportId}` | Status und kurzlebigen Download-Link abrufen | Eigentümer + Tenant-Mitgliedschaft | 200, 403, 404 |

`204 No Content` bei `trainer/next` bedeutet im Standardmodus, dass alle aktiven Fragen im ausgewählten Bereich dreimal beantwortet und damit erledigt sind. In gefilterten Modi kann `204` bedeuten, dass der Filter aktuell keine offene Frage enthält. Für abgelaufene oder ungültige Prüfungssessions wird keine neue Antwort akzeptiert. Die genaue Semantik muss mit den vorhandenen Web-Controller-Regeln deckungsgleich implementiert werden.

### 10.1 Login-Beispiel

```http
POST /api/v1/auth/login
Content-Type: application/json
X-Tenant-Slug: mueller
```

```json
{
  "email": "max@example.com",
  "password": "<passwort>",
  "device_name": "iPhone von Max"
}
```

```json
{
  "data": {
    "access_token": "<opaque-token>",
    "token_type": "Bearer",
    "expires_at": "2026-11-08T18:00:00Z",
    "refresh_token": "<opaque-refresh-token>"
  }
}
```

Tokens werden nur einmal ausgegeben und nicht in Logs geschrieben. Falls Sanctum ohne Refresh-Token verwendet wird, soll stattdessen ein begrenztes Ablaufdatum und erneute Anmeldung bzw. sicherer Token-Neuausgabeprozess festgelegt werden.

### 10.2 Kursliste-Beispiel

```http
GET /api/v1/courses
Authorization: Bearer <access_token>
X-Tenant-Slug: mueller
```

```json
{
  "data": [
    {
      "id": "crs_see_01",
      "code": "SBF-SEE",
      "name": "Sportbootführerschein See",
      "access": {"status": "active", "valid_until": "2027-06-14T21:59:59Z"},
      "progress": {"percent": 62, "completed": 84, "total": 136},
      "capabilities": ["smarttrainer", "exam", "video", "navigation"]
    }
  ],
  "meta": {"tenant": {"slug": "mueller", "name": "Bootsschule Müller"}}
}
```

### 10.3 Smarttrainer-Antwort

```http
POST /api/v1/courses/crs_see_01/attempts
Authorization: Bearer <access_token>
X-Tenant-Slug: mueller
Idempotency-Key: 76e92bf4-2d2f-42c5-85f3-c29cc1de3ed8
Content-Type: application/json
```

```json
{
  "question_id": "q_123",
  "revision_id": "rev_456",
  "mode": "smarttrainer",
  "selected_answer_ids": ["ans_b"],
  "client_answered_at": "2026-11-08T17:28:14Z"
}
```

`client_answered_at` ist nur Telemetrie und entscheidet niemals über Zugriff, Bewertung oder Ablauf. Beispielantwort:

```json
{
  "data": {
    "attempt_id": "att_789",
    "correct": false,
    "selected_answer_ids": ["ans_b"],
    "correct_answer_ids": ["ans_c"],
    "explanation": "…",
    "learning_state": "in_bearbeitung",
    "progress": {"mastery_score": 0.42, "correct_streak": 0}
  }
}
```

### 10.4 Prüfung starten, beantworten und abgeben

`POST /courses/{courseId}/exams` Request:

```json
{"paper_id":"paper_1","mode":"official_simulation"}
```

Response (201):

```json
{
  "data": {
    "exam_id": "exam_abc",
    "status": "running",
    "started_at": "2026-11-08T17:30:00Z",
    "expires_at": "2026-11-08T18:30:00Z",
    "question_count": 30,
    "current_question_index": 0,
    "questions": [{"id":"q_1","revision_id":"rev_1","text":"…","media":[],"answers":[{"id":"a","text":"…"}]}]
  }
}
```

Antworten werden separat gesichert:

```http
PUT /api/v1/exams/exam_abc/answers/q_1
Idempotency-Key: 0a694d8c-b8df-4ff0-8c41-8f36e87384fa
```

```json
{"selected_answer_ids":["a"]}
```

Antwort während des Examens enthält nur `saved: true` und gespeicherte Auswahl. Sie enthält **keine** `correct`, `correct_answer_ids`, Erklärung oder Punkte. Bei Abgabe: `POST /exams/exam_abc/submit` mit `{}`. Das Ergebnis enthält `status`, `passed`, `score`, `correct_count`, `question_count`, `evaluated_at`, thematische Zusammenfassung und Exam-/Regelwerk-Version.

### 10.5 Fehlerobjekt

```json
{
  "type": "https://api.example.com/problems/entitlement-expired",
  "title": "Kurszugang abgelaufen",
  "status": 403,
  "detail": "Für diesen Kurs besteht derzeit kein aktiver Zugang.",
  "code": "ENTITLEMENT_EXPIRED",
  "request_id": "req_01J..."
}
```

Für Validierung enthält `errors` Feldpfade; für abgelaufene Session `EXAM_EXPIRED`; für Wiederverwendung eines Idempotency-Keys mit anderem Request `IDEMPOTENCY_KEY_REUSED`; für Rate Limit `RATE_LIMITED` samt `Retry-After` Header. Antworttexte und richtige Lösungen dürfen bei Exam-Endpoints nicht in Fehlern auftauchen.

## 11. Vollständiger API-Vertrag (OpenAPI 3.1)

Der eigenständige, maschinenlesbare Vertrag liegt in [`openapi-boatingschool-mobile-v1.yaml`](./openapi-boatingschool-mobile-v1.yaml). Er definiert Authentifizierung, Profil/Mitgliedschaften und Gerätesitzungen, Kursliste und externen Shop-Link, Lernmodule, Smarttrainer und Versuche, Favoriten und Favoritensammlungen, Prüfungsoptionen und Prüfungssessions, Video-/Platzhaltermedien, Navigationstrainer, Code-Einlösung sowie Datenschutzexport. Kontolöschung ist kein App-Endpunkt.

### Festgelegte Produktannahmen im Vertrag

- Anmeldung mit bestehenden Web-Konten. Passwort-Reset und E-Mail-Bestätigung verwenden die bestehenden Web-Abläufe; signierte E-Mail-Links dürfen per App-Link zurück in die App führen.
- Sitzung wird pro Gerät geführt. Der Vertrag sieht Tokenrotation, aktuelle Sitzung abmelden, Gerätesitzungen auflisten und einzelne Sitzungen widerrufen vor. Passwortänderungen über den bestehenden Reset-/Web-Ablauf widerrufen bestehende Sitzungen. Kontolöschung erfolgt bei Bedarf außerhalb der App über die bestehende Webplattform.
- App und Mobile-API liefern im MVP deutsche UI-Texte und deutschsprachige Lerninhalte; es gibt keine Sprachwahl, keinen `Accept-Language`-Schalter und keine Übersetzungsinfrastruktur.
- Lerninhalte und Lernstände werden nur online geladen. Bei fehlender Verbindung sind inhaltliche Bereiche nicht verfügbar; die App zeigt einen Offline-Hinweis mit Retry. Es gibt keine Offline-Downloads oder lokale Inhaltsdatenbank.
- App-Zielgruppe sind Lernende/Kunden. Schulpersonal erhält keine App-Administrationsrollen.
- Nach Login ruft die App die verfügbaren Mitgliedschaften ab. Jede tenantbezogene Anfrage sendet `X-Tenant-Slug`; das Backend verifiziert aktive Mitgliedschaft und Kurszugang auf jedem Request. Bei einer Mitgliedschaft wird sie vorausgewählt, bei mehreren kann der Lernende wechseln.
- Ein Kurskauf öffnet den externen Webshop der jeweiligen Bootsschule, sofern im Websystem ein Link hinterlegt ist. Nach dem Kauf sendet die Bootsschule den Zugangscode per E-Mail. Der Kunde gibt diesen Code anschließend in der App ein; die App fragt danach Kurszugänge neu ab. Es gibt keinen automatischen Rücksprung aus dem Shop und keinen In-App-Kauf.
- Prüfungsregeln gelten fachlich als freigegeben; im Repository verbliebene `draft`-Statuswerte müssen technisch synchronisiert werden.
- Fehlende Medien bleiben sichtbar. Die API liefert ein Media-Objekt mit `kind: placeholder`, `available: false`, `url: null` und einem Label/Grund. Der Client zeigt eine neutrale Kachel wie „Video folgt“ oder „Bild folgt“, ohne abspielbare Aktion. Die Kachel soll den Lernbereich sichtbar halten, ohne eine echte Medien-URL vorzutäuschen.

### Gemeinsame API-Regeln

- Basis `/api/v1`, JSON UTF-8, RFC 3339 UTC-Zeitstempel, Bearer-Token im `Authorization`-Header.
- `X-Tenant-Slug` ist für tenantbezogene Endpunkte erforderlich, aber niemals selbst ein Berechtigungsnachweis.
- Seitenergebnisse nutzen `page`/`per_page` (Standard 20, Maximum 100). Schreibvorgänge mit wiederholbarer Nebenwirkung tragen einen UUID-`Idempotency-Key`.
- Fehler verwenden `application/problem+json` mit stabilem `code` und `request_id`.
- Prüfungsantworten enthalten vor finaler Abgabe weder Korrektheit noch Lösung, Erklärung oder Punktestand.
- Default für Sitzungslaufzeiten im Vertrag: Access-Token 15 Minuten; rotierendes Refresh-Token mit 30 Tagen Inaktivitätsfrist und 90 Tagen maximaler Lebensdauer. Wiederverwendung eines bereits rotierten Refresh-Tokens widerruft die zugehörige Sitzung. Diese Werte sind Serverkonfiguration und können vor Go-live angepasst werden.

## 12. Sicherheit, Datenschutz und Betrieb

- **TLS überall**, HSTS und sichere Header; Zugangstoken nie in URL, Analytics oder Crash-Logs.
- Gerätesitzungen verwenden widerrufbare, rotierende Refresh-Tokens; Logout widerruft das aktuelle Gerät, und Lernende können andere Gerätesitzungen einzeln beenden. Der Vertrag setzt konfigurierbare Default-Laufzeiten fest.
- Login, Passwort-Reset, Exam-Start/-Abgabe, Attempt-POST und Export mit passenden Rate Limits. Passwort-Reset neutral beantworten, damit E-Mail-Adressen nicht enumerierbar sind.
- Tenant-Zuordnung serverseitig auf aktive Mitgliedschaft prüfen. Tenant-ID aus Request-Body ignorieren. Jede Ressource zusätzlich auf Tenant und Eigentümer prüfen; RLS bleibt zusätzliche Schutzschicht.
- Prüfungsfragen, Antwortauswahl und Ergebnis: keine Antwortschlüssel vor Finalisierung im API-Payload oder in Logs. Evaluierung atomar und idempotent ausführen.
- PII-Datenminimierung, definierte Retention, Kontolöschung und Export bleiben mit bestehenden Datenschutzdiensten konsistent.
- Audit-Log für administrative Änderungen und Entitlement-/Coupon-Aktionen; keine unnötige Auditierung jeder Lernantwort mit Klardaten.
- Metriken: Login-Erfolg, API-Latenz, Fehlerquote, Abbruchrate bei Lernflows, Exam-Abgaben, Sync-Fehler. Produktanalyse nur datenschutzkonform und transparent.
- Push nur nach expliziter Einwilligung, Präferenzen widerrufbar. Keine Prüfungsstände oder sensiblen Personendaten in Push-Texten.

## 13. Implementierungsplan

### Phase 0 – Produkt-/Fachfreigabe

- App-Namen/Store-Accounts und unterstützte Länder/Kurse entscheiden.
- Tenant-Auswahl und Login-Fälle (bestehende Nutzer, unbestätigte E-Mail, gesperrte Membership) gemäß dem Vertrag umsetzen: Membership-Liste laden, bei mehreren Schulen Auswahl zeigen und jeden Tenant-Zugriff serverseitig autorisieren.
- Alle vorgesehenen Prüfungsregelwerke gemäß Produktentscheidung im Backend freischalten; Medien bleiben in dieser Phase neutrale Platzhalter.

### Phase 1 – API-Grundlage

- Token- und Gerätesitzungen gemäß Vertrag implementieren (Access 15 Minuten; Refresh rotiert, 30 Tage Inaktivität, maximal 90 Tage).
- `/api/v1`-Routen, Tenant-Middleware, API-Resources, Form Requests, Problem-Responses und Rate Limits.
- Service-Integration für Kurse, Übersicht, Trainer und Attempt; OpenAPI in CI/Review-Prozess versionieren.
- RLS/Tenant-Tests mit echtem App-DB-Rollenverhalten für sämtliche neue API-Endpunkte ergänzen.

### Phase 2 – Mobile MVP

- Flutter App Shell, Branding, Login/Passwort-Reset, Tab-Navigation, Kursübersicht, Lernhub und Videokurs.
- Trainerflow, Favoriten und Schritt-Galerie; Lade-, Fehler-, Offline- und abgelaufener-Zugang-Status.
- Push zunächst auslassen, bis Einwilligung/Benachrichtigungspräferenzen umgesetzt sind.

### Phase 3 – Prüfung und Release

- Exam-API und Bildschirmablauf mit Fortsetzen, serverseitiger Zeit und finalem Ergebnis.
- Datenschutz-/Profilflows, Accessibility, Crash-Reporting ohne Antwortinhalte.
- Testflight/Play Internal Test mit Bootsschule; Storescreens, Support und Datenschutzangaben.

### Phase 4 – Ausbau

- Navigation-/Praxis-Lernbereiche, Video-Quiz, Push und optionale Commerce-Integration nach Messung und fachlicher Entscheidung. Offline-Nutzung ist nicht geplant.

## 14. Abnahmekriterien für MVP

- Nutzer mit bestehendem Webkonto kann sich ohne separates Konto in Mobile anmelden.
- Eine Anfrage ohne aktive Tenant-Mitgliedschaft bzw. Kursfreischaltung liefert keinen Kurscontent.
- Versuch im Smarttrainer wird genau einmal gespeichert, serverseitig bewertet und in Web/Mobile im selben Lernstand berücksichtigt.
- Nicht veröffentlichte, deaktivierte oder aktuell ungültige Frage wird nicht ausgeliefert.
- Laufende Prüfung kann nach App-Neustart fortgesetzt werden; Antworten bleiben serverseitig gesichert.
- Keine korrekte Antwort/Erklärung ist vor der Abgabe in Exam-Antworten oder Fehlerantworten enthalten.
- Zeitablauf und Ergebnis richten sich nach Serverzeit und serverseitigem Snapshot.
- Tenantübergreifende Objekt-IDs können keine fremden Daten auslesen oder ändern.
- Abgelaufener Zugang wird verständlich dargestellt und verweist bei vorhandenem Shop-Link dorthin.
- Video-/Lektionsübersicht, Player oder nicht abspielbarer Platzhalter und Schritt-Galerie zeigen Inhalte und synchronisieren Sehfortschritt.
- Datenexport, Abmeldung und Gerätesitzungen funktionieren in der App; eine Kontolöschung wird in der App nicht angeboten und bleibt auf der bestehenden Webplattform.
- Favoritensammlungen lassen sich in der App erstellen, umbenennen, filtern und löschen; beim Löschen einer Sammlung bleiben die Fragenfavoriten erhalten.
- Jede aktive Smarttrainer-Frage wird genau dreimal als gespeicherter Antwortversuch gezählt und danach als erledigt markiert.
- Lerninhalte werden nur bei aktiver Internetverbindung geladen; App und Inhalte sind im MVP ausschließlich deutschsprachig.

## 15. Offene Entscheidungen

| Entscheidung | Empfehlung | Muss vor |
|---|---|---|
| Technologie | Flutter für iOS und Android | Festgelegt |
| API-Auth | bestehende Web-Konten; widerrufbare Gerätesitzungen, rotierende Refresh-Tokens, Logout/Widerruf je Gerät | Phase 1 |
| Tenant-API-Form | Mitgliedschaften nach Login laden, Auswahl bei mehreren; `X-Tenant-Slug` je Request serverseitig prüfen | Phase 1 |
| Offline | MVP online-only; Lerninhalte und Lernstände werden ausschließlich bei aktiver Verbindung geladen | Festgelegt |
| Kauf/Verlängerung | externer Shop; Bootsschule mailt nach Kauf den Code; Kunde löst ihn in der App ein und aktualisiert dort seinen Zugang | MVP |
| Prüfungstraining | alle vorgesehenen Prüfungsregeln gelten fachlich als freigegeben; technische `draft`-Statuswerte entsprechend anpassen | API-Umsetzung |
| App-Branding | App-Name/Store-Listing zentral; Bootsschul-Logo/Farben und Hinweise pro Tenant dynamisch, ohne Schulpersonal-App-Rollen | Phase 0 |
| Sprache | ausschließlich Deutsch im MVP; keine Lokalisierung oder Sprachwahl | Festgelegt |
| Platzhalter-UI | fehlende Medien bleiben sichtbar als neutrale Kachel mit Medientyp und „Inhalt folgt“, ohne Play-Aktion; API liefert `available: false` und keine URL | MVP |

## 16. Referenz zum untersuchten Bestand

- Repository: [github.com/hastla007/boatingschool](https://github.com/hastla007/boatingschool)
- `README.md`: Architektur, Mandantenschutz, Produktumfang, offene Punkte und Hinweise zu Medien/Prüfungsregeln.
- `routes/web.php`: derzeitige Web-Routen für Dashboard, Kurse, Smarttrainer, Fortschritt, Videos, Favoriten und Prüfungen.
- `app/Services/SmarttrainerService.php`: serverseitige Auswahl und Priorisierung von Lernfragen.
- `app/Http/Controllers/LearningController.php`: Kurszugang, Lernmodi und Antwortfluss.
- `app/Http/Controllers/ExamController.php`: Prüfungsbögen, Sessions und serverseitige Prüfungsauswertung.
- `composer.json`: Laravel 12; Sanctum ist derzeit nicht aufgeführt.

---

**Arbeitsstatus:** Fachliches Konzept und vollständiger OpenAPI-3.1-Zielvertrag für die mobile Ergänzung. Festgelegt: bestehende Web-Konten und Passwort-/E-Mail-Abläufe, widerrufbare Gerätesitzungen, Lernenden-App ohne Schulpersonalzugang, serverseitige Tenantprüfung, externe Schulkäufe mit Codeversand per E-Mail und Einlösung in der App, freigegebene Prüfungsregeln und sichtbar bleibende Platzhalter-Medien. Die API und App sind noch nicht im Laravel-Repository implementiert.
