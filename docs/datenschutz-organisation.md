# Datenschutz-Organisation (To-do T57)

Entwurf für den Betreiber. **Keine Rechtsberatung.** Alles, was hier über die Software steht, ist im Code belegt
(Datei in Klammern). Was der Code nicht zeigt, steht als **Frage** da. Rechtsgrundlagen sind **Vorschläge**; sie
folgen der Vorlage der Datenschutzerklärung (`themes/kurswasser/legal/datenschutz.html`, Abschnittsnummern „DSE Nr.“)
und müssen anwaltlich geprüft werden.

Abkürzungen: **Plugin** = `bootspruefung-learning/src/`, **DSE** = `themes/kurswasser/legal/datenschutz.html`,
**Cron** = stündlicher Wartungslauf `bp_learning_hourly` (`Plugin.php`, `hourly()`), **Kontolöschung** = Löschung
über „Meine Daten“, App oder die WordPress-Werkzeuge (`Privacy/AccountDeletion.php`, `Privacy/PersonalData.php`).

Inhalt:

1. Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)
2. Auftragsverarbeiter und andere Empfänger
3. Technische und organisatorische Maßnahmen (Art. 32 DSGVO)
4. Löschkonzept
5. Ablauf bei Datenpannen (Art. 33/34 DSGVO)
6. App-Datenschutz und Store-Angaben
7. Minderjährige: Kauf, Kasse, AGB
8. Liste der Entscheidungen und offenen Fragen

---

## 1. Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)

### 1.0 Angaben zum Verantwortlichen (für alle Tätigkeiten gleich)

| Feld | Inhalt |
|---|---|
| Verantwortlicher | [[Betreiber: Firmenname, Anschrift, vertretungsberechtigte Person]] |
| Kontakt Datenschutz | [[Betreiber: E-Mail für Datenschutzanfragen]] |
| Datenschutzbeauftragter | [[Betreiber: Name und Kontakt – oder „nicht benannt“; ob eine Pflicht besteht, prüfen (Art. 37 DSGVO, § 38 BDSG)]] |
| Drittlandübermittlung | Bei Zahlungsanbietern möglich (DSE Nr. 15). Für Hoster, Mail-Dienst, Backup-Ziel und Video-Hosting: [[Betreiber: prüfen]] |
| Technische und organisatorische Maßnahmen | siehe Abschnitt 3 |
| Stand / Prüfung | [[Betreiber: Datum, verantwortliche Person, nächste Prüfung]] |

### 1.1 Kundenkonto

| Feld | Inhalt |
|---|---|
| Zweck | Konto für Lernen und Kauf bereitstellen; Anmeldung im Web und in der App |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b DSGVO (DSE Nr. 5) |
| Datenkategorien | E-Mail-Adresse, Benutzername, Passwort (nur als Hash, WordPress-Kern), Name und Adressen, soweit ergänzt (WooCommerce-Kundenkonto) |
| Betroffene | Kundinnen und Kunden, Gruppenmitglieder, Interessenten mit kostenlosem Konto |
| Empfänger | Hoster; Mail-Dienst (Passwort-Mails); keine Weitergabe an Dritte laut DSE Nr. 14 |
| Löschfrist | Bis zur Kontolöschung. Eine automatische Löschung inaktiver Konten gibt es im Plugin nicht. Konten mit Verwaltungsrechten werden nie automatisch gelöscht (`Privacy/AccountDeletion.php`, `isProtected()`). |
| Belege | `Privacy/AccountDeletion.php`, `Privacy/SelfService.php`, `Security/Hardening.php` (E-Mail-Änderung nur mit Passwort) |
| Fragen | Sollen inaktive Konten nach einer Frist gelöscht werden? (siehe Abschnitt 8) |

### 1.2 Bestellung und Zahlung

| Feld | Inhalt |
|---|---|
| Zweck | Vertrag abwickeln, Zugänge freischalten, Rechnung und Buchhaltung, Widerruf, Geld-zurück-Garantie, Zahlungskonflikte, Missbrauchsabwehr |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b (Vertrag), lit. c (Aufbewahrung nach AO/HGB, Widerrufsfunktion), lit. f (IP-Adresse und Browser zur Bestellung, Zahlungskonflikte) – DSE Nr. 6. Bestellzuordnung (`sbjs_*`) nur mit Einwilligung, lit. a und § 25 Abs. 1 TDDDG (DSE Nr. 10, `themes/kurswasser/inc/consent.php`) |
| Datenkategorien | WooCommerce-Bestellung: Name, Rechnungsanschrift, E-Mail, Produkte, Preise, Zahlungsart, Zahlungsstatus, Transaktionsnummer, IP-Adresse, Browser (DSE Nr. 6). Vom Plugin an der Bestellung: Zustimmung zum vorzeitigen Beginn mit Zeitpunkt (`_bp_digital_consent`, `Commerce/WooCommerce/DigitalConsent.php`), Geld-zurück-Anfrage und Nutzungsstand (`_bp_money_back_request`, `_bp_money_back_usage`, `Commerce/MoneyBack.php`), Zahlungskonflikte (`_bp_dispute_state`, `_bp_dispute_events`, `Commerce/Disputes/DisputeWebhooks.php`). Kurszugänge in Tabelle `bp_entitlement` (Kurs, Funktion, Laufzeit, Status, Bestellbezug; `Infrastructure/Database/Schema.php`). Kursgutscheine: Freischaltung über den Grant-Dienst der Lernplattform, Referenz `voucher:{Schlüssel}` (`docs/kursgutscheine.md`) |
| Betroffene | Käuferinnen und Käufer |
| Empfänger | Zahlungsanbieter (Stripe, PayPal, Klarna – laut DSE in eigener Verantwortung), Bank bei Überweisung, Steuerberatung, Hoster, Mail-Dienst (Bestellmails) |
| Löschfrist | Gesetzliche Aufbewahrung: Buchungsbelege 8 Jahre, Geschäftsbriefe 6 Jahre, Bücher 10 Jahre (DSE Nr. 13). Bei Kontolöschung werden Bestellungen **nur dann** anonymisiert, wenn in WooCommerce „Persönliche Daten aus Bestellungen auf Anfrage entfernen“ an ist – **Standard: aus** (`AccountDeletion::anonymizesOrders()`). Dabei bleiben Transaktionsnummer, Firma, Land und Bundesland (`retainedOrderFields()`). Eine automatische Löschung der Bestellungen nach Ablauf der Fristen gibt es im Plugin nicht. |
| Belege | DSE Nr. 6, `Privacy/AccountDeletion.php`, `Commerce/WooCommerce/DigitalConsent.php`, `Commerce/MoneyBack.php` |
| Fragen | WooCommerce-Einstellung zur Anonymisierung an oder aus? Wer löscht Bestellungen nach Fristablauf, und wie? |

### 1.3 Lernplattform und Lernstand

| Feld | Inhalt |
|---|---|
| Zweck | Lerninhalte bereitstellen, Fortschritt anzeigen, Smarttrainer, Zugänge verwalten |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b DSGVO (DSE Nr. 7) |
| Datenkategorien | Tabellen `bp_attempt` und `bp_attempt_answer` (Antworten, richtig/falsch, Selbsteinschätzung, Antwortzeit), `bp_progress` (Lernstand je Frage), `bp_favorite`, `bp_favorite_collection` (mit selbst gewähltem Namen), `bp_navigation_progress` und `bp_radio_progress` (auch Freitext-Antworten), `bp_praxis_progress`, `bp_video_progress` (Position, letzter Zugriff) – alle in `Infrastructure/Database/Schema.php`. Nutzer-Meta: App-Einstellungen `bp_app_settings` (`Mobile/AppSettings.php`), Praxis-Tickets `_bp_tickets` (`Extensions/Tickets.php`). Kurzlebiger Zähler gegen zu viele Antworten (60 je Minute, Transient 120 Sekunden; `Learning/Services/LearningService.php`) |
| Betroffene | Lernende (Käufer, Gruppenmitglieder, Nutzer des kostenlosen Teilumfangs) |
| Empfänger | Hoster. Laut DSE keine Weitergabe, auch nicht an Gruppenkäufer |
| Löschfrist | Solange das Konto besteht. Bei Kontolöschung vollständig gelöscht (`PersonalData::eraseUser()`, Tabellenliste `Schema::USER_DATA_TABLES`). Zur Geld-zurück-Garantie wird der Nutzungsstand vorher an der Bestellung festgeschrieben (Hook `bootspruefung_before_erase_user`). |
| Belege | DSE Nr. 7, `Privacy/PersonalData.php` |

### 1.4 Prüfungssimulation

| Feld | Inhalt |
|---|---|
| Zweck | Prüfung üben und auswerten |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b DSGVO |
| Datenkategorien | `bp_exam_session` (Kurs, Bogen, Modus, Start, Frist, Abgabe, Punkte, bestanden ja/nein, Ergebnis), `bp_exam_session_question` (Antworten je Frage, markiert, Zeitpunkte) – `Schema.php`. Im Browser: `bp-exam-…` in localStorage bis zur Bestätigung durch den Server (DSE Nr. 10) |
| Betroffene | Lernende |
| Empfänger | Hoster |
| Löschfrist | Wie Lernstand: bis Kontolöschung (`Schema::USER_DATA_TABLES` enthält `exam_session`; Fragen folgen per `ON DELETE CASCADE`, `Schema::foreignKeys()`) |
| Hinweis | Die Ergebnisse sind Übungsergebnisse, keine amtlichen Prüfungsergebnisse. |

### 1.5 Gruppenlizenzen und Einladungen

| Feld | Inhalt |
|---|---|
| Zweck | Gekaufte Plätze zuteilen, Teilnehmende einladen, Gruppe verwalten |
| Rechtsgrundlage (Vorschlag) | Käufer und Mitglieder: Art. 6 Abs. 1 lit. b. Eingeladene vor dem Beitritt: Art. 6 Abs. 1 lit. f (DSE Nr. 7 „Gruppenpakete“) |
| Datenkategorien | `bp_learning_group`: Inhaber, Gruppenname, Plätze, Laufzeit, Bestellbezug, Beitrittscode. `bp_learning_group_member`: Konto oder E-Mail-Adresse, Status, Einladungscode, Einladungs-, Beitritts- und Entfernungszeitpunkt (`Schema.php`) |
| Betroffene | Käufer (z. B. Bootsschule, Verein), Eingeladene (auch ohne Konto), Mitglieder |
| Empfänger | Käufer sieht von Mitgliedern Anzeigename, E-Mail, Status, Einladungs- und Beitrittsdatum, nicht den Lernstand (DSE Nr. 7). Mail-Dienst (Einladungsmail) |
| Löschfrist | Einladungslink gilt 30 Tage (`GroupService::INVITE_DAYS`). Die E-Mail-Adresse einer nicht angenommenen Einladung wird 30 Tage nach Ablauf gelöscht (`EXPIRED_RETENTION_DAYS`, Cron `expireInvites()`). Entfernte Einladungen ohne Konto verlieren die Adresse. Bei Kontolöschung: E-Mail-Adresse aus Gruppen entfernt, Platz frei, eigener Gruppenname neutralisiert (`PersonalData::eraseGroupMembers()`, `GroupService::releaseUser()`). Gruppen- und Mitgliedszeilen selbst haben **keine Löschfrist** im Code. |
| Schutz | Höchstens 20 Einladungen je Gruppe und Tag (bei großen Gruppen 2 × Plätze; `GroupService::INVITE_LIMIT`), in der App 20 je Stunde (`Mobile/Controllers/GroupController.php`) |
| Fragen | Wie lange bleiben Gruppen- und Mitgliedszeilen nach Ende der Laufzeit? |

### 1.6 Ablauf-Erinnerung

| Feld | Inhalt |
|---|---|
| Zweck | Kundinnen und Kunden vor Ablauf eines gekauften Zugangs informieren |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. f DSGVO i. V. m. § 7 Abs. 3 UWG (DSE Nr. 8). Widerspruch jederzeit (Link in jeder Mail, `List-Unsubscribe`) |
| Datenkategorien | Zugänge aus `bp_entitlement` (Kurs, Ende), E-Mail-Adresse, Name. Nutzer-Meta `bp_expiry_reminders` (Kurs und Ende → Versandzeit) und `bp_expiry_reminder_optout` (Zeitpunkt, Weg). Protokoll `expiry_reminder_optout`/`optin` |
| Betroffene | Kundinnen und Kunden mit befristetem Zugang |
| Empfänger | Mail-Dienst |
| Einstellungen | Standard: an (`Settings/Settings.php`, `expiry_reminder_enabled`), 14 Tage vorher (`ExpiryReminder::DEFAULT_DAYS`, höchstens 90), täglicher Cron `bp_learning_daily`; je Lauf höchstens 50 Empfänger bzw. 20 Sekunden, der Rest eine Minute später (`bp_learning_expiry_reminder_continue`) |
| Löschfrist | Merker mit vergangenem Ablaufdatum werden beim nächsten Versand an dieselbe Person verworfen (`ExpiryReminder::reminded()`); sonst bei Kontolöschung (`ExpiryReminder::forget()`). Widerspruch bleibt, bis die Person wieder zustimmt oder das Konto gelöscht wird. |
| Belege | `Commerce/ExpiryReminder.php`, `Commerce/ReminderOptOut.php` |

### 1.7 App-Sitzungen (Schnittstelle `boatingschool/v1`)

| Feld | Inhalt |
|---|---|
| Zweck | Anmeldung in der App, sichere Sitzungen, Wiederholung nach Netzabbruch, Übergabe der Sitzung an die Webansicht, Datenexport in der App |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b DSGVO; Sicherheitsanteile lit. f (DSE Nr. 7 „App“) |
| Datenkategorien | `bp_mobile_session`: Konto, **nur SHA-256-Hashes** der Tokens, Gerätename (Freitext der App, max. 100 Zeichen), Plattform (ios/android), App-Version, Zeitpunkte, Widerrufsgrund. **Keine IP-Adresse.** `bp_mobile_idempotency`: Prüfwert der Anfrage und gespeicherte Antwort. `bp_mobile_web_code`: Hash des Einmal-Codes und des Prüfwerts, Zielpfad. `bp_mobile_export`: Datenexport als JSON. Nutzer-Meta `bp_app_settings` (max. 8 KB). `device_token` („bekanntes Gerät“) wird nicht gespeichert, sondern signiert ausgegeben (`Security/KnownDevice.php`) |
| Betroffene | App-Nutzerinnen und -Nutzer |
| Empfänger | Hoster |
| Löschfrist | Access-Token 15 Minuten, Refresh-Token 30 Tage ohne Nutzung, Sitzung höchstens 90 Tage (`Mobile/TokenAuth.php`); Zeile 7 Tage nach Ende oder Widerruf gelöscht (`TokenAuth::cleanup()`). Idempotenz 7 Tage (`Mobile/Idempotency.php`). Web-Code 60 Sekunden gültig, Zeile 1 Tag nach Ablauf gelöscht (`Mobile/WebSession.php`). App-Export 24 Stunden (`PrivacyController::EXPORT_TTL`). Alles per Cron (`MobileApi::cleanup()`) und bei Kontolöschung (`Schema::USER_DATA_TABLES`). Passwort-Reset beendet alle App-Sitzungen (`Mobile/MobileApi.php`, `after_password_reset`). |

### 1.8 Datenschutzanfragen (Auskunft, Export, Löschung)

| Feld | Inhalt |
|---|---|
| Zweck | Rechte nach Art. 15–20 DSGVO erfüllen |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. c DSGVO i. V. m. Art. 12 ff. DSGVO |
| Datenkategorien | WordPress-Antrag (E-Mail, Art, Status, Zeitpunkte – WordPress-Kern), Exportdatei (alle Daten nach `PersonalData::sources()`), App-Export (`bp_mobile_export`). Protokoll: `privacy_export_sent`/`failed`, `account_deleted` (Kennung als gesalzener Hash), `personal_data_erased` (Hash) |
| Betroffene | Antragstellende |
| Empfänger | Mail-Dienst (Bestätigungs- und Download-Mails) |
| Ablauf | Bestätigungslink per Mail; Löschung erst nach Klick auf „Konto jetzt löschen“; mit Einstellung „Datenschutz-Anträge automatisch ausführen“ (Standard: an, `Settings.php`, `privacy_requests_auto`) läuft alles automatisch (`AccountDeletion.php`). Je Art höchstens 5 Anträge pro Tag (`SelfService.php`, `PrivacyController.php`) |
| Löschfrist | App-Export 24 Stunden. Web-Export: Link 3 Tage (WordPress-Standard `wp_privacy_export_expiration`, angezeigt in `SelfService.php`; Löschung der Datei durch den WordPress-Kern, nicht in diesem Repo). Antragsdatensätze in den WordPress-Werkzeugen: im Plugin nicht geregelt. Protokoll 4 Jahre. |
| Fragen | Wie lange bleiben erledigte Anträge in „Werkzeuge › Personenbezogene Daten“ stehen? (Nachweis vs. Datensparsamkeit) |

### 1.9 Anmeldeschutz und Rate-Limits

| Feld | Inhalt |
|---|---|
| Zweck | Konten vor fremden Anmeldeversuchen schützen; Missbrauch bremsen (Passwort vergessen, Registrierung, Gutscheine, Einladungen, Exporte) |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. f DSGVO; Cookie `bp_known_device` technisch notwendig, § 25 Abs. 2 Nr. 2 TDDDG (DSE Nr. 5) |
| Datenkategorien | `bp_mobile_rate_limit`: nur **gesalzener SHA-256-Hash** aus Zählername (z. B. E-Mail, IP), Fensterbeginn, Anzahl, Ende (`Mobile/RateLimiter.php`, `key()`). Cookie bzw. `device_token`: Konto-ID, Ablauf, Signatur (180 Tage, `Security/KnownDevice.php`) |
| Grenzen | Login: 10 je IP, 5 je Konto + IP, 30 je Konto in 15 Minuten (`Security/LoginGuard.php`); Registrierung 10 je IP und Stunde (`Security/Hardening.php`); weitere Grenzen in `Mobile/Controllers/*` |
| Betroffene | Alle, die sich anmelden oder registrieren (auch Angreifer) |
| Empfänger | keine |
| Löschfrist | Zähler nach Ende des Zeitfensters, Löschung per Cron (`RateLimiter::cleanup()`). Cookie 180 Tage im Browser; ungültig nach Passwortänderung |

### 1.10 Protokoll (Audit-Log)

| Feld | Inhalt |
|---|---|
| Zweck | Nachweis von Kauf-, Zugangs-, Gruppen-, Import- und Verwaltungsvorgängen; Abwehr und Durchsetzung von Ansprüchen |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. c und lit. f DSGVO (DSE Nr. 9) |
| Datenkategorien | `bp_audit_log`: auslösendes Konto, Vorgang, Objekt (z. B. Bestellung, Konto), Begründung, Zusatzangaben als JSON, Zeitpunkt (`Schema.php`, `Audit/AuditLog.php`). **Keine IP-Adressen, keine Zahlungsdaten.** Zusatzangaben können Konto-IDs enthalten (z. B. `group_joined`, `Commerce/GroupService.php`) |
| Betroffene | Kundinnen und Kunden, Gruppenmitglieder, Administratoren |
| Empfänger | keine (nur Verwaltung). Einsicht im Admin unter Bootsprüfung › Protokoll nur für Administratoren (`manage_options`); dort ohne E-Mail-Adressen, Personen nur mit Anzeigename bzw. Nutzer-Nummer (`Admin/AuditPage.php`). „Verlauf“ je Zugang und Gruppe für das Zugangs-Team |
| Löschfrist | 4 Jahre (`PersonalData::AUDIT_RETENTION_YEARS`), Cron `pruneAuditLog()` (je Lauf bis 5000 Zeilen). Bleibt bei Kontolöschung stehen; die Löschung selbst wird nur mit Hash protokolliert. |

### 1.11 Partnerprogramm (nur falls betrieben)

| Feld | Inhalt |
|---|---|
| Zweck | Provision an Partner abrechnen |
| Rechtsgrundlage (Vorschlag) | [[Betreiber/Anwalt: z. B. Art. 6 Abs. 1 lit. f – DSE Nr. 6 hat hier einen Platzhalter]] |
| Datenkategorien | Partnercode, Kampagne (z. B. „app“), Produktseite an der Bestellung (DSE Nr. 6). Gespeichert vom Fremd-Plugin „WP Affiliate Captain“ (nicht in diesem Repo). Der Connector `learnboating-affiliate-connector/src/Connector.php` speichert selbst nur seine Einstellungen und hängt gültige Codes an Shop-Links der App an, ohne Cookie (`learnboating-affiliate-connector/README.md`) |
| Betroffene | Käufer, die über einen Partnerlink kommen; Partner |
| Empfänger | Partner: [[Betreiber: was sieht der Partner? im Affiliate-Plugin prüfen]] |
| Löschfrist | [[Betreiber: z. B. bis Auszahlung, danach gesetzliche Aufbewahrung]] |
| Fragen | Setzt das Affiliate-Plugin Cookies? Welche Daten sieht der Partner? |

### 1.12 Kontakt und Support

| Feld | Inhalt |
|---|---|
| Zweck | Anfragen beantworten |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. b (vertragsbezogen), sonst lit. f (DSE Nr. 8 „Kontaktaufnahme“) |
| Datenkategorien | Was die Person schreibt: Name, E-Mail, Telefon, Bestellnummer, Inhalt |
| Technik | **Kein Kontaktformular im Code.** Die Kontaktseite hat nur einen `mailto:`-Link und Telefon (`themes/kurswasser/legal/kontakt.html`). Die „Praxis-Tickets“ (`Extensions/Tickets.php`) sind Fahrstunden-Tickets, kein Support-System |
| Betroffene | Interessenten, Kundinnen und Kunden |
| Empfänger | Anbieter des Support-Postfachs [[Betreiber]] |
| Löschfrist | [[Betreiber: z. B. 6 Monate nach Erledigung]] (DSE Nr. 8), Handels- und Geschäftsbriefe 6 Jahre |
| Fragen | Welches Postfach, welches Ticketsystem? Wer löscht, wann? |

### 1.13 Server-Logs

| Feld | Inhalt |
|---|---|
| Zweck | Website ausliefern, Sicherheit und Fehlersuche |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. f DSGVO (DSE Nr. 4) |
| Datenkategorien | Zugriffslog beim Hoster: IP-Adresse, Zeit, Adresse, Datenmenge, Statuscode, Referrer, User-Agent (DSE Nr. 4). PHP-Fehlerlog: Das Plugin schreibt technische Meldungen, z. B. Anfrage-ID und Fehlermeldung der App-Schnittstelle (`Mobile/Controllers/BaseController.php`), Fehler bei Mails und Exporten (`Mobile/Controllers/PrivacyController.php`, `AuthController.php`) |
| Betroffene | Alle Besucher |
| Empfänger | Hoster |
| Löschfrist | [[Betreiber: z. B. 7 Tage]] – im Code nicht geregelt, beim Hoster einstellen. Ort des Fehlerlogs außerhalb des Webroots (siehe `docs/offene-punkte.md`, Staging) |
| Fragen | Hostet ein CDN oder eine Web-Firewall vor dem Server? Dann eigener Eintrag. |

### 1.14 Backups

| Feld | Inhalt |
|---|---|
| Zweck | Wiederherstellung nach Fehler, Angriff oder Ausfall |
| Rechtsgrundlage (Vorschlag) | Art. 6 Abs. 1 lit. f DSGVO und Art. 32 Abs. 1 lit. c DSGVO |
| Datenkategorien | Alle oben genannten Daten: Datenbank (Tabellen `wp_bp_*`, WordPress, WooCommerce), Dateien `uploads/bootspruefung/` bzw. der Ordner aus `BOOTSPRUEFUNG_PRIVATE_DIR` (`docs/offene-punkte.md` Nr. 19, `Admin/SystemStatus.php`) |
| Technik | **Das Plugin macht keine Backups.** Backup-Plugin oder Hoster-Sicherung ist offen (`docs/offene-punkte.md` Nr. 19, `docs/livegang-drehbuch.md`, T66) |
| Betroffene | Alle |
| Empfänger | Backup-Ziel [[Betreiber: Anbieter, Standort]] |
| Löschfrist | [[Betreiber: Aufbewahrung, z. B. 30 Tage rollierend]]. Gelöschte Daten bleiben bis zur Rotation im Backup (siehe Abschnitt 4) |

**Nicht im Auftrag genannt, aber in der DSE:** Bewerbungen (DSE Nr. 8), Cookies und Einwilligungsbanner (DSE Nr. 10, 11).
[[Betreiber: Falls Bewerbungen tatsächlich angenommen werden, eigene Tätigkeit ergänzen.]]

---

## 2. Auftragsverarbeiter und andere Empfänger

Art. 28 DSGVO: Mit jedem Auftragsverarbeiter ist ein Vertrag nötig (AV-Vertrag). Bei Diensten in eigener
Verantwortung ist das anders. Welche Rolle ein Dienst hat, bitte anwaltlich bestätigen lassen.

| ☐ AV-Vertrag | Dienst | Anbieter (Platzhalter) | Rolle (Vorschlag) | Welche Daten | Hinweis / Beleg |
|---|---|---|---|---|---|
| ☐ | Hosting (Server, Datenbank) | [[Betreiber]] | Auftragsverarbeiter | alle | DSE Nr. 4, 14 |
| ☐ | E-Mail-Versand (SMTP/Mail-Dienst) | [[Betreiber]] | Auftragsverarbeiter | E-Mail, Name, Mailinhalte (Bestellung, Passwort, Einladung, Erinnerung, Export-Link) | `docs/mailversand.md` Nr. 8; entfällt, wenn der Hoster versendet |
| ☐ | **Backup-Ziel** (falls nicht beim Hoster) | [[Betreiber]] | Auftragsverarbeiter | alle | Abschnitt 1.14; Verschlüsselung und Standort klären |
| ☐ | Video-Hosting (falls nicht eigener Server) | [[Betreiber]] | Auftragsverarbeiter | IP-Adresse, Abruf | DSE Nr. 7 „Videos“; Plugin erkennt fremde Hosts (`Trainers/VideoService.php`, `externalHost()`); eigenes CDN über Filter `bootspruefung_video_first_party_hosts` nur mit AV-Vertrag |
| ☐ | CDN / Web-Firewall (falls genutzt) | [[Betreiber]] | Auftragsverarbeiter | IP, Anfragen | im Code nicht vorgesehen; nur eintragen, wenn vorhanden |
| ☐ | Support-Postfach / Ticketsystem | [[Betreiber]] | Auftragsverarbeiter | Anfragen | Abschnitt 1.12 |
| ☐ | Überwachungsdienst (Uptime) | [[Betreiber]] | Frage | ruft `GET /wp-json/bootspruefung/v1/monitor` ab; Antwort enthält nur ok/Fehler (`Infrastructure/Monitor.php`) | vermutlich keine personenbezogenen Daten – prüfen |
| ☐ | Staging-Umgebung bei anderem Anbieter | [[Betreiber]] | Auftragsverarbeiter, falls Echtdaten | ggf. alle | Frage: Liegen auf Staging Echtdaten? |
| – | Stripe, PayPal, Klarna | siehe DSE Nr. 6 | **eigene Verantwortung** (laut DSE) | Zahlungsdaten, Name, E-Mail, Betrag; Skripte und Cookies auf der Kasse | Frage an Anwalt: für einzelne Teile ggf. Auftragsverarbeitung (z. B. Stripe) – Rolle je Anbieter prüfen; Drittland (DSE Nr. 15) |
| – | Bank (Überweisung) | [[Betreiber]] | eigene Verantwortung | Zahlungseingang | DSE Nr. 6 |
| – | Steuerberatung / Buchhaltung | [[Betreiber]] | Frage (oft eigene Verantwortung) | Bestell- und Rechnungsdaten | DSE Nr. 14 |
| – | Apple App Store, Google Play | – | eigene Verantwortung | Download, Store-Konto | DSE Nr. 7 „App“ hat einen Platzhalter |
| ☐ | Dienste in der App (Absturzberichte, Push, Analyse) | **unbekannt** | Frage | Frage | Abschnitt 6 |
| – | Partner im Partnerprogramm | [[Betreiber]] | Frage | siehe 1.11 | |
| – | GitHub (Versionsprüfung, nur wenn eingeschaltet) | GitHub | Frage (vermutlich keine personenbezogenen Daten der Kunden) | IP-Adresse des Servers, einmal am Tag; keine Website-Daten, eigene Kennung ohne Adresse | Standard aus; Bootsprüfung › Einstellungen › Betrieb (`Infrastructure/UpdateCheck.php`, T117) |

Für jeden Auftragsverarbeiter zusätzlich festhalten: Unterauftragnehmer, Serverstandort, Drittlandgarantie,
Meldepflicht bei Datenpannen „unverzüglich“ (Art. 33 Abs. 2 DSGVO), Löschung bei Vertragsende.

---

## 3. Technische und organisatorische Maßnahmen (Art. 32 DSGVO)

### 3.1 Im Code umgesetzt

| Ziel | Maßnahme | Beleg |
|---|---|---|
| Passwörter | Nur als Hash gespeichert (WordPress-Kern). Das Plugin prüft Passwörter nur mit `wp_check_password()` | DSE Nr. 5, `Security/Hardening.php` |
| Anmeldeschutz | Sperre nach Fehlversuchen (10/5/30 in 15 Minuten), gemeinsam für Web und App; „bekanntes Gerät“ schützt vor Aussperren durch Fremde | `Security/LoginGuard.php`, `Security/KnownDevice.php` |
| Kein Ausspähen von Konten | Neutrale Fehlermeldungen; „Passwort vergessen“ mit gleicher Antwort und Mail-Grenze; keine Benutzer-Sitemap, keine Autorenarchive, `/wp/v2/users` nur für Redaktion; Registrierung max. 10 je IP und Stunde | `Security/Hardening.php` |
| Weniger Angriffsfläche | XML-RPC aus; Anwendungspasswörter nur für Redaktionskonten; E-Mail-Änderung nur mit aktuellem Passwort | `Security/Hardening.php` |
| Sicherheits-Header | `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy` (keine Kamera, kein Standort, Mikrofon nur eigene Seite) | `Security/Hardening.php`, `securityHeaderList()` |
| Rate-Limits ohne Klartext | Zählername nur als gesalzener SHA-256-Hash; atomare Zählung | `Mobile/RateLimiter.php` |
| App-Tokens | Zufallstokens (48 Byte); in der Datenbank nur SHA-256-Hashes; Refresh-Token rotiert, Wiederverwendung beendet die Sitzung; Laufzeiten 15 Min. / 30 / 90 Tage; Bearer-Token gilt nur für `boatingschool/v1` | `Mobile/TokenAuth.php`, `Schema.php` (`bp_mobile_session`) |
| Sitzungen beenden | Nutzer sieht und beendet eigene App-Sitzungen (`/me/sessions`); Admin beendet alle App-Sitzungen eines Kontos; Passwort-Reset beendet alle App-Sitzungen | `Mobile/Controllers/AccountController.php`, `Mobile/Admin/MobileAdminPage.php`, `Mobile/MobileApi.php` |
| Sitzungsübergabe App → Web | Einmal-Code 60 Sekunden, nur als Hash, an Sitzung und Prüfwert der App gebunden | `Mobile/WebSession.php` |
| Transportverschlüsselung | App-Schnittstelle lehnt unverschlüsselte Anfragen ab, wenn die Website unter `https://` läuft (`HTTPS_REQUIRED`); Cookie `bp_known_device` mit `Secure` (bei HTTPS), `HttpOnly`, `SameSite=Lax` | `Mobile/MobileApi.php` (`httpsRequired()`), `Security/KnownDevice.php` |
| Kein Zwischenspeichern | `Cache-Control: private, no-store` für App-Antworten und geschützte Dateien | `Mobile/MobileApi.php`, `Gate/ProtectedFiles.php`, `Mobile/Controllers/FileController.php` |
| Geschützte Dateien | Pakete und geschützte PDFs in gesperrtem Ordner mit Zufallsnamen, `.htaccess`-Sperre, auf Wunsch außerhalb des Webroots (`BOOTSPRUEFUNG_PRIVATE_DIR`); Auslieferung nur nach Zugriffsprüfung; Systemstatus warnt, wenn Dateien direkt abrufbar sind (mit nginx-Regel) | `Content/Media/MediaStorage.php`, `Gate/ProtectedFiles.php`, `Admin/SystemStatus.php` |
| Keine Skripte im Medienordner | Gefährliche Dateitypen (PHP, HTML, SVG, JS …) gesperrt bzw. in Quarantäne | `Content/Media/MediaStorage.php` |
| Rollen und Rechte | Eigene Rechte `manage_bp_content`, `import_bp_content`, `manage_bp_entitlements`, bei Aktivierung nur für Administratoren | `Security/Capabilities.php` |
| Schutz von Verwaltungskonten | Konten mit Verwaltungsrechten werden nie automatisch gelöscht | `Privacy/AccountDeletion.php` |
| Protokoll | Audit-Log für Zugänge, Gruppen, Garantie, Konflikte, Importe, Einstellungen; ohne IP und Zahlungsdaten; 4 Jahre | `Audit/AuditLog.php`, `Privacy/PersonalData.php` |
| Pseudonymisierung | Gelöschte Konten im Protokoll nur als gesalzener Hash; Export ohne Tokens | `Privacy/PersonalData.php`, `Privacy/AccountDeletion.php` |
| Sichere Löschung | Löschung der Lerndaten in einer Datenbank-Transaktion mit Rücksprung bei Fehler | `Privacy/PersonalData.php` (`eraseUser()`) |
| Prüfung von Webhooks | PayPal-Zahlungskonflikte werden bei PayPal auf Echtheit geprüft; abgewiesene Aufrufe begrenzt protokolliert | `Commerce/Disputes/DisputeWebhooks.php`, DSE Nr. 6 |
| Datensparsamkeit im Browser | Keine Analyse- und Marketing-Dienste; Bestellzuordnung nur mit Einwilligung; selbst gehostetes Banner | DSE Nr. 10–12, `themes/kurswasser/inc/consent.php` |
| Verfügbarkeit | Wartungsaufgaben laufen getrennt (ein Fehler stoppt nicht alle); Überwachungs-Endpunkt mit optionalem Schlüssel; Mail-Test im Systemstatus | `Infrastructure/Cron.php`, `Infrastructure/Monitor.php`, `docs/mailversand.md` |

### 3.2 Aufgaben für den Betreiber (organisatorisch und Server)

| ☐ | Maßnahme | Hinweis |
|---|---|---|
| ☐ | HTTPS auf der ganzen Website, gültiges Zertifikat, HSTS | HSTS ist nicht im Plugin, siehe T55 (`docs/offene-punkte.md`) |
| ☐ | Zwei-Faktor-Anmeldung für alle Konten mit Verwaltungsrechten | `docs/sicherheit-entscheidungen.md` Nr. 3 |
| ☐ | Rollenkonzept: wer bekommt Administrator, Shop-Manager, Redaktion | schriftlich festhalten, jährlich prüfen |
| ☐ | Zugang zum Hoster-Konto, SSH und Datenbank nur für Berechtigte, mit 2FA bzw. Schlüssel | |
| ☐ | Updates für WordPress, WooCommerce, Plugins, PHP regelmäßig | |
| ☐ | Backup-Konzept: Häufigkeit, Aufbewahrung, Verschlüsselung, Standort, Wiederherstellung üben | T66, `docs/livegang-drehbuch.md` |
| ☐ | Geschützte Ordner außerhalb des Webroots oder nginx-Sperre | Systemstatus zeigt den Stand |
| ☐ | Fehlerlog außerhalb des Webroots, Logfristen beim Hoster | |
| ☐ | Mail: SPF, DKIM, DMARC; kein PHP-`mail()` | `docs/mailversand.md` |
| ☐ | Vertraulichkeitsverpflichtung aller Personen mit Datenzugriff | |
| ☐ | Kurze Schulung: Phishing, Datenpanne erkennen und melden, Umgang mit Anfragen | |
| ☐ | Staging ohne Echtdaten oder mit gleichen Schutzmaßnahmen; Test-Postfach statt echter Mails | `docs/offene-punkte.md` (Mailpit) |
| ☐ | AV-Verträge abschließen und ablegen (Abschnitt 2) | |
| ☐ | Wirksamkeit prüfen: jährlich TOMs, Rechte, Backups, Löschläufe kontrollieren (Art. 32 Abs. 1 lit. d) | |
| ☐ | Geräte der Mitarbeitenden: Bildschirmsperre, Verschlüsselung, Passwortmanager | |

---

## 4. Löschkonzept

| Datenart | Frist | Mechanismus | Beleg |
|---|---|---|---|
| Lerndaten (Antworten, Lernstand, Favoriten, Sammlungen, Prüfungen, Navigation, Praxis, Video, Funk) | bis Kontolöschung | automatisch bei Kontolöschung | `Privacy/PersonalData.php`, `Schema::USER_DATA_TABLES` |
| App-Einstellungen, Erinnerungs-Merker, Praxis-Tickets (Nutzer-Meta) | bis Kontolöschung | automatisch bei Kontolöschung | `PersonalData::eraseUser()` |
| Kundenkonto (WordPress/WooCommerce) | bis Kontolöschung | automatisch nach bestätigtem Antrag (Einstellung an) oder manuell in den Werkzeugen | `Privacy/AccountDeletion.php` |
| Inaktive Konten | **keine Frist** | – | Entscheidung Betreiber |
| Kurszugänge ohne Bestellung (manuell, Gruppe, Gutschein) | bis Kontolöschung | automatisch gelöscht | `AccountDeletion::closeEntitlements()` |
| Kurszugänge mit Bestellung | bis Kontolöschung, danach „widerrufen“ | Zeile bleibt mit Konto-ID stehen, **keine spätere Löschung** | `AccountDeletion::closeEntitlements()` – Frage |
| Bestellungen | 6/8/10 Jahre (DSE Nr. 13) | bei Kontolöschung anonymisiert **nur** mit WooCommerce-Einstellung (Standard aus); Löschung nach Fristablauf: im Plugin nicht vorhanden | `AccountDeletion::anonymizesOrders()` – Frage |
| App-Sitzung | 15 Min. / 30 Tage / 90 Tage; Zeile 7 Tage nach Ende | Cron stündlich | `TokenAuth::cleanup()` |
| Idempotenz-Einträge (mit gespeicherter Antwort) | 7 Tage | Cron stündlich | `Mobile/Idempotency.php` |
| Web-Codes der App | 60 Sek. gültig, Zeile 1 Tag nach Ablauf | Cron stündlich | `Mobile/WebSession.php` |
| App-Datenexport | 24 Stunden | Cron stündlich | `PrivacyController::EXPORT_TTL`, `cleanup()` |
| Web-Datenexport (Datei) | Link 3 Tage | WordPress-Kern (nicht in diesem Repo geprüft) | `Privacy/SelfService.php` |
| Datenschutzanträge (Werkzeuge) | keine Frist im Plugin | manuell | Frage |
| Rate-Limit-Zähler | Ende des Fensters (15 Min. bis 1 Tag) | Cron stündlich | `RateLimiter::cleanup()` |
| Antwort-Zähler im Lernbereich | 120 Sekunden | Ablauf des Transients | `Learning/Services/LearningService.php` |
| Cookie `bp_known_device` | 180 Tage, ungültig nach Passwortänderung | im Browser, nichts auf dem Server | `Security/KnownDevice.php` |
| Cookie `kw_consent` | 12 Monate | im Browser | `themes/kurswasser/inc/consent.php` |
| Gruppeneinladung (Link) | 30 Tage | Cron setzt auf „abgelaufen“ | `GroupService::expireInvites()` |
| E-Mail einer nicht angenommenen Einladung | 30 Tage nach Ablauf | Cron | `GroupService::EXPIRED_RETENTION_DAYS` |
| E-Mail eines Mitglieds in Gruppen | bis Kontolöschung | automatisch | `PersonalData::eraseGroupMembers()` |
| Gruppen- und Mitgliedszeilen | **keine Frist** | – | Frage |
| Widerspruch gegen Erinnerungen | bis Widerruf oder Kontolöschung | – | `Commerce/ReminderOptOut.php` |
| Audit-Log | 4 Jahre | Cron stündlich | `PersonalData::AUDIT_RETENTION_YEARS` |
| Import-Protokolle (kaum Personenbezug) | 180 Tage | Cron | `Content/Import/Retention.php` (`LOG_DAYS`) |
| Zahlungskonflikt-Ereignisse | mit der Bestellung | wie Bestellung | `Commerce/Disputes/DisputeWebhooks.php` |
| Support-Mails | [[Betreiber: z. B. 6 Monate]] | manuell | DSE Nr. 8 |
| Server-Logs | [[Betreiber: z. B. 7 Tage]] | beim Hoster | DSE Nr. 4 |
| Backups | [[Betreiber]] | Rotation | Abschnitt 1.14 |

**Backups und Löschung:** Gelöschte Daten stehen noch in älteren Backups. Vorschlag: Backups nicht länger als nötig
aufbewahren; nach einer Wiederherstellung die Löschungen seit dem Backup erneut ausführen (Liste der seit dem Backup
gelöschten Konten aus dem Audit-Log, Vorgang `account_deleted` – dort aber nur als Hash). **Frage an Entwicklung:**
Reicht das, um gelöschte Konten nach einem Rückspielen zu finden?

---

## 5. Ablauf bei Datenpannen (Art. 33/34 DSGVO)

Eine Datenpanne ist jede Verletzung der Sicherheit, die zu Verlust, Veränderung, unbefugter Offenlegung oder
unbefugtem Zugang zu personenbezogenen Daten führt (z. B. gehacktes Admin-Konto, offene Backup-Datei, Mail an
falsche Empfänger, verlorener Laptop).

### 5.1 Schritte

1. **Erkennen und intern melden** – sofort an [[Betreiber: verantwortliche Person, Vertretung, Telefon]].
   Zeitpunkt der Kenntnis notieren: Ab hier läuft die Frist.
2. **Eindämmen** – je nach Fall:
   - Passwörter betroffener Konten zurücksetzen; das beendet auch deren App-Sitzungen (`Mobile/MobileApi.php`).
   - App-Sitzungen eines Kontos beenden: Bootsprüfung › Mobile App (`Mobile/Admin/MobileAdminPage.php`).
   - **Frage an Entwicklung:** Eine Funktion „alle App-Sitzungen aller Konten beenden“ wurde im Code nicht gefunden.
   - Schlüssel in `wp-config.php` neu erzeugen beendet Web-Sitzungen (WordPress-Standard) und macht „bekanntes
     Gerät“-Nachweise ungültig (`KnownDevice` signiert mit dem Auth-Salt).
   - Hoster, Mail-Dienst und betroffene Auftragsverarbeiter informieren.
3. **Aufklären** – Hilfen: Audit-Log (`bp_audit_log`), Server- und Fehlerlogs (Hoster), App-Sitzungen mit Gerät,
   Plattform und letzter Nutzung (`bp_mobile_session`), Systemstatus.
4. **Risiko bewerten** – Welche Daten, wie viele Personen, wie wahrscheinlich ein Schaden? Sind Daten verschlüsselt
   oder nur als Hash betroffen?
5. **Aufsichtsbehörde melden** – **innerhalb von 72 Stunden** nach Kenntnis, außer es besteht voraussichtlich
   kein Risiko (Art. 33 Abs. 1). Später nachreichen ist erlaubt, wenn noch nicht alles bekannt ist (Art. 33 Abs. 4).
   - Behörde: [[Betreiber: zuständige Aufsichtsbehörde, Online-Meldeformular, Telefon]]
   - Inhalt (Art. 33 Abs. 3): Art der Panne, Kategorien und ungefähre Zahl der Betroffenen und Datensätze,
     Kontakt, wahrscheinliche Folgen, ergriffene und geplante Maßnahmen.
6. **Betroffene benachrichtigen** – bei voraussichtlich **hohem Risiko** unverzüglich, in klarer Sprache
   (Art. 34). Weg: E-Mail an die Kontoadresse [[Betreiber: Textvorlage]].
7. **Dokumentieren** – **jede** Panne, auch wenn keine Meldung nötig war (Art. 33 Abs. 5).
8. **Nacharbeiten** – Ursache beheben, TOMs anpassen, Schulung.

Auftragsverarbeiter müssen dem Betreiber Pannen unverzüglich melden (Art. 33 Abs. 2) – im AV-Vertrag festhalten.

### 5.2 Dokumentationsvorlage

| Feld | Eintrag |
|---|---|
| Laufende Nummer | |
| Kenntnis am (Datum, Uhrzeit), durch wen | |
| Beginn / Ende der Panne (soweit bekannt) | |
| Was ist passiert? | |
| Betroffene Systeme (Website, App, Mail, Backup …) | |
| Datenkategorien | |
| Zahl der Betroffenen / Datensätze (ca.) | |
| Risikobewertung (kein / Risiko / hohes Risiko) mit Begründung | |
| Sofortmaßnahmen | |
| Meldung an Aufsichtsbehörde: ja/nein, Datum, Aktenzeichen, Begründung bei „nein“ | |
| Benachrichtigung Betroffener: ja/nein, Datum, Weg, Begründung bei „nein“ | |
| Beteiligte Auftragsverarbeiter | |
| Ursache und Abhilfe | |
| Abgeschlossen am, durch | |

---

## 6. App-Datenschutz und Store-Angaben

### 6.1 Was die Schnittstelle speichert (Fakten)

| Daten | Wann | Beleg |
|---|---|---|
| E-Mail-Adresse und Passwort | Anmeldung (`POST /auth/login`), Passwort vergessen | `Mobile/MobileApi.php`, `Mobile/Controllers/AuthController.php` |
| Gerätename, Plattform, App-Version | Anmeldung, in `bp_mobile_session` | `Mobile/TokenAuth.php` |
| Konto-ID, Name, E-Mail | nur gelesen (`GET /me`); die API hat **keinen** Endpunkt zum Ändern des Namens | `Mobile/Controllers/AccountController.php` |
| Lernaktivität: Antworten, Favoriten, Sammlungsnamen, Prüfungen, Video-Position, Navigations- und Funk-Freitexte, Praxisstatus | Nutzung | `Mobile/MobileApi.php` (Routen), Schema |
| E-Mail-Adressen **anderer Personen** | Gruppeneinladung (`POST …/invites`) | `Mobile/Controllers/GroupController.php` |
| App-Einstellungen (frei, max. 8 KB) | `PUT /me/settings` | `Mobile/AppSettings.php` |
| Gutschein- und Beitrittscodes | Einlösen | `Mobile/Controllers/CouponController.php`, `VoucherController.php` |
| Zugänge, Kaufstatus | nur gelesen; **Kauf im Systembrowser** über `webshop_url`, kein In-App-Kauf in der API | `docs/mobile-api/README.md` |
| Partnercode | Parameter oder Header, nur an Shop-Links angehängt, nicht gespeichert | `learnboating-affiliate-connector/README.md` |
| **Nicht** gespeichert | IP-Adresse (nur als Hash im Rate-Limit), Standort, Kontakte, Fotos, Werbe-ID, Geräte-ID | Schema, `Mobile/RateLimiter.php` |

### 6.2 Entwurf Apple „App Privacy“ (App-Datenschutz)

Nur aus Sicht des Servers. **Alles, was SDKs in der App sammeln, fehlt hier** (Abschnitt 6.4).

| Apple-Datentyp | Erhoben? | Mit Identität verknüpft? | Tracking? | Zweck (Vorschlag) |
|---|---|---|---|---|
| Kontaktinformationen › E-Mail-Adresse | ja | ja | nein | App-Funktionalität |
| Kontaktinformationen › Name | Frage: nur angezeigt, nicht in der App erfasst; Gerätename kann einen Namen enthalten („iPhone von …“) | ja | nein | App-Funktionalität |
| Kontaktinformationen › Andere Kontaktinfos des Nutzers | Frage: E-Mails eingeladener Personen | ja | nein | App-Funktionalität |
| Nutzerinhalte › Andere Nutzerinhalte | ja (Freitexte, Sammlungsnamen, Gruppenname) | ja | nein | App-Funktionalität |
| Identifikatoren › Nutzer-ID | ja (Konto-ID) | ja | nein | App-Funktionalität |
| Identifikatoren › Geräte-ID | nein (Server) | – | – | – |
| Käufe › Kaufverlauf | Frage: Zugänge werden angezeigt, Kauf im Browser | ja | nein | App-Funktionalität |
| Nutzungsdaten › Produktinteraktion | ja (Lernfortschritt, Video-Position) | ja | nein | App-Funktionalität; Frage: zählt der Smarttrainer als „Produktpersonalisierung“? |
| Diagnose › Andere Diagnosedaten | Frage: App-Version und Plattform | ja | nein | App-Funktionalität |
| Standort, Kontakte, Finanzinfos, Gesundheit, Browserverlauf | nein (Server) | – | – | – |
| **Tracking insgesamt** | **Vorschlag: nein** – keine Werbe-ID, keine Weitergabe an Werbenetzwerke im Server-Code. Frage: Zählt die Partnerzuordnung (Kampagne „app“) als Tracking? | | | |

### 6.3 Entwurf Google Play „Datensicherheit“

| Play-Datentyp | Erhoben | Geteilt | Pflicht/optional | Zweck (Vorschlag) |
|---|---|---|---|---|
| Persönliche Daten › E-Mail-Adresse | ja | nein* | Pflicht | App-Funktionen, Kontoverwaltung |
| Persönliche Daten › Name | Frage (siehe Apple) | nein* | – | Kontoverwaltung |
| Persönliche Daten › Nutzer-IDs | ja | nein | Pflicht | App-Funktionen, Kontoverwaltung |
| Finanzdaten › Kaufverlauf | Frage (siehe Apple) | nein | – | App-Funktionen |
| App-Aktivitäten › App-Interaktionen | ja | nein | Pflicht | App-Funktionen |
| App-Aktivitäten › Andere nutzergenerierte Inhalte | ja | nein | optional | App-Funktionen |
| App-Infos und Leistung › Diagnose | Frage (App-Version) | nein | – | App-Funktionen |
| Geräte- oder andere IDs | nein (Server) | – | – | – |

\* Frage an Anwalt: Gruppenkäufer sehen Name und E-Mail der Mitglieder. Ist das „Teilen“ im Sinne von Google, oder
eine vom Nutzer erwartete Übertragung (Ausnahme)?

Weitere Pflichtangaben:

| Frage im Formular | Antwort (Vorschlag) | Beleg |
|---|---|---|
| Daten bei der Übertragung verschlüsselt? | ja, wenn die Website unter HTTPS läuft (API verweigert sonst) | `Mobile/MobileApi.php` |
| Können Nutzer die Löschung beantragen? | ja: in der App (`POST /me/deletion-request`), im Web unter „Mein Konto › Meine Daten“, per E-Mail | `Mobile/MobileApi.php`, `Privacy/SelfService.php` (Endpunkt `meine-daten`) |
| Web-Link zur Kontolöschung (Google verlangt ihn) | [[Betreiber: URL, z. B. …/mein-konto/meine-daten/ – Anmeldung nötig; prüfen, ob Google das akzeptiert, sonst Seite mit Anleitung und E-Mail]] | |
| Datenschutzerklärung-URL | [[Betreiber]]; DSE Nr. 7 „App“ vorher ergänzen | DSE |
| Account-Löschung in der App (Apple-Vorgabe) | ja, mit Bestätigung per E-Mail-Link; Universal Link `/app/konto-loeschen/` | `Mobile/AppLinks.php` |

### 6.4 Offene Fragen an das App-Team

1. Welche SDKs sind in der App? (Absturzberichte, Analyse, Push-Mitteilungen, Werbung) – je SDK: Daten, Anbieter,
   Zweck, Tracking ja/nein. **Ohne diese Liste sind die Store-Angaben unvollständig.**
2. Was sendet die App als `device_name`? Vorschlag: nur das Modell („iPhone“), nicht den Gerätenamen mit Personennamen.
3. Nutzt die App Spracherkennung im Funktrainer? Wenn ja: lokal oder über Server von Apple/Google?
4. Lädt die App Videos direkt von einem fremden Video-Host? Dann erhält dieser die IP-Adresse (AV-Vertrag, Abschnitt 2).
5. Setzt die eingebettete Webansicht (Sitzungsübergabe, `Mobile/WebSession.php`) Cookies der Website, z. B. den
   Einwilligungsbanner? Wird das Banner in der Webansicht angezeigt?
6. Werden Tokens im Schlüsselbund bzw. Keystore gespeichert (Vorgabe in `docs/mobile-api/README.md`)?
7. Altersfreigabe im Store: Fragebogen ausfüllen; Zielgruppe ab 14/16 Jahren berührt Abschnitt 7.

---

## 7. Minderjährige: Kauf, Kasse, AGB

### 7.1 Stand im Code und in den Texten

| Ort | Ergebnis | Beleg |
|---|---|---|
| AGB | **Keine** Regel zu Minderjährigen (Suche nach „Minderj“, „volljähr“, „Eltern“, „18“: kein Treffer zum Kauf) | `themes/kurswasser/legal/agb.html` |
| Kasse | **Kein** Hinweis und keine Abfrage zum Alter. Es gibt nur das Pflicht-Kästchen zum vorzeitigen Beginn bei digitalen Inhalten und die Pflicht zum Kundenkonto | `Commerce/WooCommerce/DigitalConsent.php`, `Commerce/WooCommerce/Integration.php` |
| Inhaltsseite „Mindestalter“ | Offener Punkt: „In AGB und Kasse den Satz ergänzen ‚Bist du unter 18, brauchst du für den Kauf die Zustimmung deiner Eltern.‘ (§§ 107, 108 BGB)“ | `themes/kurswasser/content/pages.php` |
| FAQ | Mindestalter für die **Prüfung**: 16 Jahre (Motor), 14 Jahre (Binnen unter Segel); Minderjährige brauchen für die **Prüfung** die Zustimmung der gesetzlichen Vertretung | `Faq/DefaultFaqs.php` |
| Datenschutzerklärung | Keine Aussage zu Minderjährigen | DSE |

**Wichtig:** Das Prüfungs-Mindestalter (14/16 Jahre, Sportbootführerscheinverordnung) sagt nichts darüber, ob
jemand einen Kaufvertrag allein schließen kann. Das sind zwei verschiedene Fragen. Zielgruppe sind also auch
14- bis 17-Jährige.

### 7.2 Fragen an den Anwalt

1. Wirksamkeit von Käufen durch 14- bis 17-Jährige ohne Zustimmung der Eltern (§§ 106 ff. BGB, „Taschengeld“ § 110
   BGB): Was gilt bei Einmalzahlungen von wenigen Euro bis knapp 90 € (vorläufige Preise, z. B. Simulator-Pass 9,90 €,
   Kapitäns-Bundle 88,70 €; `docs/offene-punkte.md`)? Was gilt bei Gruppenpaketen?
2. Was ist bei Rückforderung durch Eltern zu tun (Zugang beenden, Erstattung, Lernstand)?
3. Kostenloses Konto für Minderjährige (z. B. Gruppenmitglied eines Vereins): Gibt es etwas zu beachten?
4. Einwilligung in Statistik-Cookies (`kw_consent`) durch unter 16-Jährige: Gilt Art. 8 DSGVO? (Die übrigen
   Verarbeitungen stützen sich laut DSE auf Vertrag oder berechtigtes Interesse, nicht auf Einwilligung.)
5. Nehmen die Zahlungsanbieter (PayPal, Klarna, Kreditkarte) Minderjährige an? Bitte bei den Anbietern prüfen –
   das steht nicht im Code.
6. Reicht ein Hinweis, oder ist ein Kästchen sinnvoll? Hat ein Kästchen rechtliche Wirkung oder nur Hinweischarakter?

### 7.3 Optionen (Entscheidung des Betreibers – **nicht umgesetzt**)

| Option | Was | Aufwand und Folgen |
|---|---|---|
| A – nichts ändern | Stand wie heute | kein Aufwand; Risiko unklarer Verträge bleibt (Frage 1) |
| B – Hinweis | Satz in AGB (Abschnitt 3 oder 4) und als Text in der Kasse: „Bist du unter 18, brauchst du für den Kauf die Zustimmung deiner Eltern.“ | gering; keine zusätzlichen Daten |
| C – Bestätigung | Pflicht-Kästchen in der Kasse: „Ich bin volljährig oder habe die Zustimmung meiner Eltern.“ Technisch ähnlich wie das Kästchen in `DigitalConsent.php` (Kassenfeld, Speicherung mit Zeitpunkt in der Bestellung) | mittel; ein weiteres Kästchen in der Kasse; speichert eine zusätzliche Angabe je Bestellung |
| D – Geburtsdatum abfragen | Alter in der Kasse erfassen | höherer Aufwand; mehr Daten (Datensparsamkeit, Art. 5 DSGVO); nicht empfohlen ohne klaren Grund |
| E – Kauf durch Eltern | Eltern kaufen einen **Kursgutschein**, das Kind löst ihn im eigenen Konto ein (gibt es schon: `docs/kursgutscheine.md`). Ein normaler Kurs ist an das Konto des Käufers gebunden (AGB Nr. 6 „Persönliche Nutzung“) | kein Entwicklungsaufwand; Hinweis in FAQ oder Kasse möglich |

Optionen lassen sich kombinieren (z. B. B + E). Bei C bitte vorher klären, ob das Kästchen auch auf
„Bestellung bezahlen“ und im klassischen Checkout gelten soll (wie `DigitalConsent.php`).

---

## 8. Liste der Entscheidungen und offenen Fragen

**Entscheidungen des Betreibers**

1. Platzhalter füllen: Verantwortlicher, Datenschutzbeauftragter, Aufsichtsbehörde, alle Anbieter in Abschnitt 2.
2. AV-Verträge mit Hoster, Mail-Dienst, Backup-Ziel, ggf. Video-Hosting, CDN, Support-Postfach abschließen.
3. WooCommerce: Bestellungen bei Kontolöschung anonymisieren – an oder aus? (Standard aus)
4. Löschfristen festlegen für: inaktive Konten, Bestellungen nach Fristablauf, Kurszugänge gelöschter Konten,
   Gruppen- und Mitgliedszeilen, Datenschutzanträge, Support-Mails, Server-Logs, Backups.
5. Backup-Lösung, Aufbewahrung, Verschlüsselung, Wiederherstellungstest (T66).
6. Zwei-Faktor-Anmeldung für Verwaltungskonten, HSTS.
7. Minderjährige: Option A–E (Abschnitt 7.3).
8. Partnerprogramm: betreiben ja/nein; wenn ja, Datenumfang für Partner und Frist.
9. Verantwortliche Person und Vertretung für Datenpannen.

**Fragen an Anwalt**

- Rechtsgrundlagen aller Tätigkeiten (Abschnitt 1), Rolle der Zahlungsanbieter und der Steuerberatung.
- Minderjährige (Abschnitt 7.2).
- Store-Angaben: „Teilen“ bei Gruppen, Partnerzuordnung als Tracking (Abschnitt 6).

**Fragen an App-Team und Entwicklung**

- SDK-Liste und übrige Punkte aus Abschnitt 6.4.
- Funktion „alle App-Sitzungen beenden“ für den Notfall (Abschnitt 5.1).
- Wiederfinden gelöschter Konten nach Rückspielen eines Backups (Abschnitt 4).
