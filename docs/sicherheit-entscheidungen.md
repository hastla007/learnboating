# Sicherheit: Entscheidungen des Betreibers (To-do T35)

Drei Fragen. Je Frage: wie es heute ist, was sich ändern ließe, unser Vorschlag.

## 1. Login-Grenzen

**Heute** (`src/Security/LoginGuard.php`): Web und App teilen sich die Zähler. In 15 Minuten sind erlaubt:

| Zähler | Fehlversuche |
|---|---|
| je IP-Adresse | 10 |
| je Konto und IP-Adresse | 5 |
| je Konto insgesamt | 30 |

- Die Grenze je Konto ist absichtlich höher. Sonst könnte jemand mit 5 falschen Passwörtern ein fremdes Konto
  aussperren.
- Eine erfolgreiche Anmeldung setzt den Zähler „Konto und IP“ zurück.
- Ist ein Sicherheits-Plugin mit eigener Login-Sperre aktiv (Wordfence, Limit Login Attempts (Reloaded), Solid
  Security, All In One WP Security, Loginizer), nimmt sich die Sperre des Lern-Plugins zurück.
- Ändern ohne Programmierung: Filter `bootspruefung_login_limits` (Schlüssel `ip`, `login_ip`, `login`).
- **Zählen ohne Wettlauf (T83):** Jeder Versuch wird vor der Passwortprüfung in einer einzigen
  Datenbank-Anweisung vorgemerkt. Liegt ein Zähler danach über der Grenze, wird der Versuch abgewiesen. Viele
  gleichzeitige Versuche können die Grenze so nicht mehr überholen. Erfolgreiche Anmeldungen nehmen ihre
  Vormerkung zurück; es zählen nur Fehlversuche.
- **Keine Aufzählung über die Antwortzeit (T88):** Bei unbekanntem Benutzernamen bzw. unbekannter E-Mail prüft
  das Plugin ein Dummy-Passwort. Die Anmeldung dauert dann gleich lang wie bei einem echten Konto, die Meldung ist
  gleich (Web und App).

**Seit T41 („bekanntes Gerät“):** Wer sich auf einem Gerät schon erfolgreich angemeldet oder dort das Passwort
zurückgesetzt hat, bekommt einen signierten Nachweis (Web: Cookie, App: `device_token`, 180 Tage, mit
Passwortänderung ungültig). Für diese Person zählen nur die 5 Fehlversuche je Konto und IP. Die Sperre je IP
(Fahrschul-WLAN, Mobilfunk, Proxy) und die Sperre je Konto (Angreifer mit vielen IPs) treffen sie nicht mehr.
Ein Passwort-Reset setzt die Kontozähler zurück. IPv6 zählt je /64-Netz. Ein nicht eingetragener Proxy wird im
Systemstatus gemeldet.

**Frage:** Passen 10 / 5 / 30 in 15 Minuten? Sollen Konten mit Verwaltungsrechten eigene Grenzen bekommen?

## 2. SVG im Content-Import

**Heute:** Der Import lehnt SVG ab, ebenso HTML und Skripte. Erlaubt sind PNG, JPG, GIF, WebP, MP3, M4A, MP4, WebM
und PDF. Grund: Eine SVG-Datei kann Skripte enthalten, die auf der eigenen Domain laufen würden. Das heutige
Content-Paket enthält nur PNG.

Seit T88/T96 prüft der Import Dateinamen und Endungen schon im Inhaltsverzeichnis des ZIP, bevor etwas entpackt wird
(dazu symbolische Links, Größe, Anzahl und ZIP-Bomben); abgelehnte Pakete werden gelöscht (`docs/betreiber-schritte.md`, T96).

**Möglich:** SVG über den Filter `bootspruefung_media_allowed_types` zulassen. Sicherer ist es, SVG-Grafiken
beim Erstellen des Pakets in PNG umzuwandeln.

**Vorschlag:** SVG weiter ablehnen. Falls Grafiken nur als SVG vorliegen, beim Paketbau in PNG umwandeln.

**Frage:** Einverstanden?

## 3. Zwei-Faktor-Anmeldung für Admins

**Heute:** Nicht eingebaut. WordPress selbst bietet keine Zwei-Faktor-Anmeldung.

**Vorschlag:** Für alle Konten mit Verwaltungsrechten ein eigenes Zwei-Faktor-Plugin einsetzen (z. B. „Two Factor“
aus dem WordPress-Plugin-Verzeichnis). Für Kunden nicht verpflichtend. Vor dem Livegang auf Staging prüfen, ob
Anmeldung, Passwort-Reset und App-Login damit funktionieren. Die App meldet sich nur mit Benutzername und Passwort
an; wie sich das Plugin beim App-Login eines Admin-Kontos verhält, auf Staging prüfen.

**Frage:** Zwei-Faktor für Admins einführen, und mit welchem Plugin?

## 4. Zahlungskonflikte: PayPal-Signatur und Klarna (T64/T65)

**PayPal heute:** Der Webhook `/wp-json/bootspruefung/v1/webhooks/paypal` prüft jede Nachricht bei PayPal
(`POST /v1/notifications/verify-webhook-signature`, mit den Kopfzeilen `PAYPAL-TRANSMISSION-ID`, `-TIME`, `-SIG`,
`PAYPAL-CERT-URL`, `PAYPAL-AUTH-ALGO` und der Webhook-ID). Erst nach der Antwort `SUCCESS` setzt das Plugin den
Zugang aus bzw. stellt ihn wieder her.

| Fall | Antwort | Wirkung |
|---|---|---|
| Zugangsdaten fehlen | 503 | nichts verarbeitet; Einstellungen und Systemstatus zeigen „Signatur nicht geprüft“ |
| PayPal antwortet `FAILURE` oder Kopfzeilen fehlen | 401 | nichts verarbeitet; Audit-Log und PHP-Fehlerprotokoll |
| PayPal nicht erreichbar, kein Token | 503 | nichts verarbeitet; PayPal stellt die Nachricht später erneut zu |

**Vorfilter (T88):** Vor JSON-Auswertung und Signaturprüfung verwirft der Webhook offensichtlich ungültige Anfragen,
ohne PayPal zu fragen: mehr als 10 Anfragen je IP und Minute (429, IPv6 je /64-Netz, Filter
`bootspruefung_paypal_webhook_per_ip`; T125: deutlich unter dem gemeinsamen Budget von 60 Prüfungen bei PayPal je
Minute, damit eine einzelne Adresse es nicht allein aufbraucht), andere Methode als POST (405), Content-Type nicht
`application/json` (415), leer (400) oder größer als 256 KB (413), fehlende `PAYPAL-…`-Kopfzeilen oder Zertifikat
nicht unter `https://….paypal.com` (401). Abweisungen landen höchstens einmal je 10 Minuten im Audit-Log; abgelehnte
oder gescheiterte Prüfungen aktualisieren „Letzte Prüfung“ höchstens einmal je Minute (ein Erfolg immer).

**Was du einrichten musst:**

1. Im PayPal-Developer-Dashboard (zuerst **Sandbox**) bei der App einen Webhook anlegen:
   URL aus *Bootsprüfung › Einstellungen › Zahlungskonflikte*, Ereignisse „Customer dispute created“,
   „updated“ und „resolved“.
2. Client-ID, Secret und die Webhook-ID dieses Webhooks eintragen und „Sandbox“ wählen. Alternativ in
   `wp-config.php` (haben dann Vorrang, stehen nicht in der Datenbank):
   `BOOTSPRUEFUNG_PAYPAL_CLIENT_ID`, `BOOTSPRUEFUNG_PAYPAL_SECRET`, `BOOTSPRUEFUNG_PAYPAL_WEBHOOK_ID`,
   `BOOTSPRUEFUNG_PAYPAL_MODE` (`live` oder `sandbox`).
3. Sandbox-Test: mit einem Sandbox-Käuferkonto kaufen, dann einen Konflikt eröffnen. Erwartung: Zugang
   „ausgesetzt“, Bestellnotiz „PayPal-Konflikt eröffnet“; im Systemstatus „Letzte Prüfung: … erfolgreich“.
4. Für den Livebetrieb einen eigenen Webhook in der Live-App anlegen und Live-Zugangsdaten mit „Live“
   eintragen. Sandbox- und Live-Werte nicht mischen.

**Klarna:** Klarna meldet Konflikte nicht an WooCommerce. Zwei Wege:

- **Von Hand:** In der Bestellung (Bestellaktionen) „Klarna-Konflikt melden: eröffnet / gewonnen / verloren“
  wählen, Begründung eintragen (Pflicht), optional die Klarna-Fall-ID. Das wirkt genau wie der allgemeine
  Webhook: aussetzen, wiederherstellen oder widerrufen, Audit-Log, Bestellnotiz. Ein abgeschlossener Fall ist
  endgültig. Für einen zweiten Konflikt zur selben Bestellung die Klarna-Fall-ID angeben.
- **Automatisch:** Eine Automatisierung (z. B. Zapier, Make, n8n oder ein eigenes Skript) wertet die
  Klarna-Benachrichtigung aus und schickt sie an den allgemeinen Webhook
  `/wp-json/bootspruefung/v1/webhooks/dispute`: JSON mit `event` (`opened`/`won`/`lost`), `order_id` oder
  `transaction_id`, `id`, `source: "klarna"`, `dispute_id`, `timestamp`; Kopfzeile
  `X-Bootspruefung-Signature: sha256=<HMAC-SHA256 über den Rohtext mit dem Schlüssel aus den Einstellungen>`.

**Fragen:** Wie meldet Klarna dir Konflikte (E-Mail aus dem Händlerportal oder eine eigene Schnittstelle)?
Erst danach lässt sich die Automatisierung genau beschreiben. Reicht bis dahin die Meldung von Hand?

## 5. Geschützte Seiten und Page-Builder (T77)

**Heute:** Seiten und Beiträge mit „Lernzugang erforderlich“ zeigen ohne Zugang nur Titel, manuellen Auszug und
einen Hinweis mit Produktlink (`src/Gate/ContentGate.php`). Page-Builder wie Elementor oder Beaver Builder setzen
ihren Inhalt aber erst nach dieser Sperre ein. Deshalb gibt es eine zweite Sperre ganz am Ende:

- `the_content`, `the_content_feed` und `get_the_excerpt` werden mit der letzten Priorität (`PHP_INT_MAX`) noch
  einmal geprüft; bei fehlendem Zugang steht danach wieder der Hinweis da. Das gilt auch für REST-API, Feeds und
  Einbettungen (oEmbed), die dieselben Filter nutzen.
- Elementor bekommt für gesperrte Seiten keine Layout-Daten (`elementor/frontend/builder_content_data`), Beaver
  Builder rendert sie nicht (`fl_builder_do_render_content`). Die Filter greifen nur, wenn das Plugin aktiv ist.
- Andere Builder (z. B. Divi) arbeiten über `the_content` und sind durch die zweite Sperre abgedeckt.

**Bitte auf Staging prüfen**, falls ein Page-Builder eingesetzt wird: eine Seite mit „Lernzugang erforderlich“ im
Builder gestalten und abgemeldet aufrufen. Erwartung: nur der Hinweis, kein Builder-Inhalt.

## 6. Gleichzeitigkeit, Aufzählung und Limits (T83, T87, T88)

- **Gruppenplätze:** Beitritt und Einladung zählen die freien Plätze unter einer Sperre je Gruppe (MySQL
  `GET_LOCK`, endet spätestens mit der Verbindung). Zwei gleichzeitige Beitritte können so nicht denselben letzten
  Platz belegen, eine Person wird nicht doppelt eingetragen. Ist die Sperre länger als 5 Sekunden belegt, kommt
  „Gerade nehmen mehrere Personen gleichzeitig einen Platz in dieser Gruppe …“.
- **Kursgutscheine:** siehe `docs/kursgutscheine.md` (Sperre je Gutschein, Limits). Seit T109 zählt ein
  gemeinsames Limit je IP (30 je Stunde) alle Einlösewege zusammen – App, „Mein Lernen“, Einlöseseite –, damit sich
  Codes nicht über viele Konten durchprobieren lassen; im Warenkorb bestätigt nur ein angemeldetes Konto nach der
  Limitprüfung, dass ein Code ein Kursgutschein ist.
- **Benannte Sperren (T115):** Kurze Sperren einzelner Anfragen laufen über `Infrastructure\NamedLock` (MySQL
  `GET_LOCK`, Name je Datenbank und Tabellenpräfix): Gruppenbeitritt, Prüfungsstart je Nutzer, Kursgutschein-Bestellung
  je Code. Belegt (Wartezeit abgelaufen) → freundliche Meldung „gerade belegt, bitte gleich erneut“. Kann die Datenbank
  keine benannten Sperren (`GET_LOCK` liefert NULL, z. B. bestimmte Cluster-Proxys), geht es ohne Sperre weiter und es
  wird protokolliert – die fachliche Prüfung danach (laufende Prüfung, Bestellung je Code, freie Plätze) bleibt; früher
  blockierte ein NULL den Prüfungsstart bzw. die Einlösung dauerhaft. Mit HyperDB/LudicrousDB schickt NamedLock vor
  `GET_LOCK` alle Lesezugriffe der Anfrage an den Hauptserver (`send_reads_to_masters()`), damit Sperre und Freigabe
  dieselbe Verbindung nutzen. Filter `bootspruefung_named_lock_timeout` (Wartezeit; Prüfungsstart und Gutschein
  10 Sekunden, Gruppen 5).
- **Antworten je Minute (T114):** höchstens 60 Antworten je Nutzer und Minute (Web und App), gezählt atomar in der
  Tabelle `bp_mobile_rate_limit` wie die App-Limits (vorher Transients `bp_rl_*`, nicht atomar).
- **Einladungsvorschau (Web und App):** nennt die kaufende Person nur mit Vor- und Nachname aus dem Konto, sonst
  niemanden – nie den Anzeigenamen (oft der Anmeldename).
- **Passwort vergessen in der App:** Antwort immer 202, gleich für bekannte und unbekannte Adressen. Reset-Schlüssel
  und Mail entstehen erst nach dem Senden der Antwort (PHP-FPM bzw. LiteSpeed), damit die Antwortzeit nichts verrät.
- **Passwort vergessen im Web (T110):** wp-login.php und WooCommerce „Mein Konto › Passwort vergessen“ senden für ein
  bekanntes Konto zuerst dieselbe Weiterleitung wie für unbekannte Adressen (`checkemail=confirm` bzw.
  `reset-link-sent=true`) und schließen die Verbindung (`fastcgi_finish_request` bzw. `litespeed_finish_request`);
  erst danach erzeugen WordPress bzw. WooCommerce Schlüssel und Mail. Ohne diese Funktionen (z. B. mod_php) dauert
  jede Antwort – mit und ohne Konto – gleich lang, mindestens 2 Sekunden (Filter
  `bootspruefung_neutral_response_seconds`; gilt ebenso für die App). Je IP zählen alle Anfragen, auch für unbekannte
  Adressen (10 je Stunde), je Konto 3 Mails je Stunde; darüber kommt dieselbe Bestätigung ohne Mail.
  Hinweis: Schlägt im Rückfall der Mailversand selbst fehl, zeigt wp-login.php weiter die Fehlermeldung von WordPress.
- **Tipps:** Über die WordPress-Schnittstelle `/wp/v2/bp_tip` (und die Kategorien) lesen nur Konten mit
  Bearbeitungsrecht; der Block-Editor funktioniert wie bisher. Lernende lesen Tipps im Lernbereich.
- **IPv6:** Alle Limits je IP zählen IPv6-Adressen je /64-Netz (`RateLimiter::clientKey()`): Anmeldung, Passwort
  vergessen/zurücksetzen, Registrierung, App-Token, Kontolöschung, PayPal-Webhook, Gutschein-Einlösung (alle Wege).

## 7. Inhaltspakete ohne Hochladen: Ablageordner und Link (T108, T125)

- **Ablageordner:** liegt im privaten Paketordner (`uploads/bootspruefung/packages-<geheim>/import/`, mit
  `BOOTSPRUEFUNG_PRIVATE_DIR` außerhalb des Webroots) und hat damit denselben Schutz wie die Pakete:
  Zufallsgeheimnis im Ordnernamen, `.htaccess`, nginx-Regel, Selbsttest im Systemstatus. Der frühere feste Ordner
  `uploads/bootspruefung-import/` war unter nginx abrufbar; sein Inhalt wird nach dem Update einmalig verschoben
  (Importseite, stündlicher Cron, „Erneut prüfen“), Reste nennt die Importseite, der Selbsttest prüft sie mit, und
  die nginx-Regel sperrt den alten Pfad zusätzlich. Nach erfolgreicher Übernahme löscht das Plugin die abgelegte
  ZIP-Datei bzw. den Paketordner (die Meldung sagt es); ein abgelehnter Paketordner bleibt zum Nachbessern liegen.
- **Dateinamen:** Der gewählte Name geht unverändert an die strenge Prüfung (`ImportSources::resolveDrop`: kein
  Pfadanteil, kein Link, muss direkt im Ablageordner liegen). „Paket (1).zip“ oder „inhalt.final.zip“ funktionieren.
- **Systemdateien:** `.DS_Store`, `Thumbs.db` und `__MACOSX/` werden in ZIP und Paketordner stillschweigend
  übersprungen (nicht entpackt, nicht kopiert); alles andere Unerwartete lehnt die Vorprüfung weiter ab.
- **Link-Import:** nur https; Weiterleitungen verfolgt das Plugin selbst (höchstens drei, jede wieder nur auf https
  und keine interne Adresse). Optional eine SHA-256-Prüfsumme: Stimmt sie nicht, wird die Datei verworfen.

## 8. Admin-Hinweise (T125)

- **Wegklicken:** Der Link (bzw. die AJAX-Anfrage) trägt eine Signatur über Nutzer, Schlüssel,
  Tage und Version (`wp_hash`). Damit lassen sich nur gezeigte Hinweise ausblenden, Fehler-Hinweise nicht per
  `days=0` dauerhaft; das Nutzer-Meta `bp_notices_dismissed` bleibt bei höchstens 50 Einträgen.
