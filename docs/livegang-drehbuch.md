# Livegang-Drehbuch: Staging → Live (To-do T55)

Reihenfolge für den Umzug von Staging auf das Live-System, mit Echtgeld-Testkauf und Rückfallplan. Jede Zeile hat
einen Haken und einen Namen. Abbrechen, sobald ein „Muss“-Punkt nicht erfüllt ist.

## 1. Voraussetzungen (eine Woche vorher)

| Muss | Punkt | Wo |
|---|---|---|
| ☐ | Rechtstexte veröffentlicht und geprüft (Impressum, Datenschutz, AGB, Widerruf, Garantie) | T11, T52, T72 |
| ☐ | Steuer-Weg entschieden, Rechnungen geklärt | T14, T53 |
| ☐ | Mailversand eingerichtet, Zustelltest bestanden | T54, `docs/mailversand.md` |
| ☐ | Zahlungsarten in der Sandbox Ende-zu-Ende getestet (Kauf, Erstattung, Dispute) | T63 |
| ☐ | Staging-Abnahme mit Zielsystem bestanden | T67 |
| ☐ | Backup und Restore einmal geübt (`docs/backup-restore.md`, danach `wp bootspruefung backup-check`) | T66 |
| ☐ | WooCommerce-Grundeinstellungen ohne Hinweis: Systemstatus › Shop › „Grundeinstellungen“ (Deutsch, Bankverbindung, Shop-Adresse, E-Mail-Format, Absender) | T73 |
| ☐ | Inhalte: Content-Import, Rechte und Regelwerke freigegeben | T68 |
| ☐ | Produktkatalog angelegt, Preise final | T16, T71 |
| ☐ | Inhaltsseiten ohne Warnung (Hinweis „Inhaltsseiten aus dem Theme“) | T59 |

## 2. Server-Betrieb (einmalig auf Live)

- **Zeitzone:** Einstellungen › Allgemein › Zeitzone „Berlin“, keine feste Verschiebung wie „UTC+1“. Sonst stimmen
  die Uhrzeit der Zustimmung in der Bestellmail und das Fristende nicht (LIVE-08). Der Systemstatus warnt bei falscher
  Einstellung.
- **Echter System-Cron:** `define('DISABLE_WP_CRON', true);` in `wp-config.php` und auf dem Server alle 5 Minuten
  `wp cron event run --due-now --path=/pfad/zu/wordpress`. Kontrolle: Systemstatus › Wartung › „Letzter stündlicher
  Lauf“ ist höchstens eine Stunde alt.
- **Proxy:** Steht ein Reverse-Proxy, Load-Balancer oder CDN davor, dessen Adressen unter Bootsprüfung › Einstellungen ›
  Sicherheit › „Vertrauenswürdige Proxys“ eintragen (`docs/server-einrichtung.md`). Kontrolle: Systemstatus › „Client-IP dieser
  Anfrage“ zeigt deine eigene IP.
- **HTTPS und HSTS:** Zertifikat gültig, Weiterleitung http → https. Erst wenn alles über HTTPS läuft, im Server
  `Strict-Transport-Security: max-age=31536000` setzen (Apache: `Header always set …`, nginx: `add_header … always;`).
  `includeSubDomains` nur, wenn alle Subdomains HTTPS haben.
- **Deutsche Wartungs- und Fehlerseite:** `wp-content/plugins/bootspruefung-learning/dropins/maintenance.php` nach
  `wp-content/maintenance.php` kopieren, `dropins/php-error.php` nach `wp-content/php-error.php`. Kontrolle:
  Systemstatus › „Wartungs- und Fehlerseite“.
- **Überwachung mit Alarm:** Uptime-Dienst (Prüfung alle 5 Minuten, Alarm per Mail und SMS) auf
  1. die Startseite (erwartet 200),
  2. `https://deine-domain.de/wp-json/bootspruefung/v1/monitor` (erwartet 200; 503 heißt Datenbank, Migration oder
     Cron gestört). Optional mit Schlüssel: `define('BOOTSPRUEFUNG_MONITOR_KEY', '…');` und `?key=…` in der URL.
  3. `https://deine-domain.de/wp-json/boatingschool/v1/health` (App-Schnittstelle, erwartet `"status":"ok"`).
  Dazu Ablauf des TLS-Zertifikats überwachen.
- **Fehler-Log:** `WP_DEBUG` aus, `WP_DEBUG_LOG` auf eine Datei außerhalb des Webroots. Den Admin-Mail-Empfänger des
  WordPress-Wiederherstellungsmodus prüfen (Einstellungen › Allgemein › Administrator-E-Mail).

## 3. Ablauf am Livegang-Tag

| Zeit | Schritt | Haken |
|---|---|---|
| T−2 h | Vollbackup Live (Datenbank, `wp-content/uploads/bootspruefung/` bzw. `BOOTSPRUEFUNG_PRIVATE_DIR`), Rückspielen auf Testkopie und `wp bootspruefung backup-check` (T66, `docs/backup-restore.md`) | ☐ |
| T−1 h | Shop-Modus „Demnächst verfügbar“ an (WooCommerce), Theme und Plugins in den Versionen von Staging einspielen | ☐ |
| T−45 min | Admin öffnen: Datenbank-Migration läuft, Systemstatus › Schema „aktuell“, Wartung ohne Fehler | ☐ |
| T−40 min | Content-Paket importieren bzw. prüfen, Rechte und Regelwerke freigeben (T68) | ☐ |
| T−30 min | Produktkatalog prüfen: Preise, Zuordnung „Lernzugang“, Garantie je Produkt (T71) | ☐ |
| T−20 min | Testmail aus dem Systemstatus, Passwort-Reset mit eigenem Konto | ☐ |
| T−15 min | **Echtgeld-Testkauf** mit eigenem Kundenkonto: kleinstes Produkt, je Zahlungsart einmal (Überweisung kann vorab erfolgen). Prüfen: Bestellmail mit Rechtstexten, Laufzeit und Garantie-Erklärung, Freischaltung im Lernbereich, „Mein Lernen“ | ☐ |
| T−10 min | Testkäufe erstatten (WooCommerce › Bestellung › Erstattung über die Zahlungsart). Prüfen: Zugang endet, Erstattungsmail kommt, Geld kommt zurück (Kontoauszug in den Folgetagen) | ☐ |
| T−5 min | Assistent „Zugang ohne Shop“ prüfen (T15), Inhaltsseiten veröffentlicht | ☐ |
| T 0 | Design › Kurswasser einrichten › Schritt 3 „Shop für Kunden sichtbar machen“: Die Vorschau zeigt die **Livegang-Ampel** (Rechtstexte ohne Platzhalter, Produkte veröffentlicht, Prüfungsregelwerke freigegeben, Schnupper-Paket, „Zugang ohne Shop“, Inhaltspaket aktiv; je Punkt mit Link). „Shop für Kunden sichtbar“ ist nie vorausgewählt; bei Rot zusätzlich „Trotzdem sichtbar machen“ (WP-CLI `--live --force-live`). Dieselbe Ampel steht in Bootsprüfung › Übersicht und unter WooCommerce › Einstellungen › Sichtbarkeit | ☐ |
| T+5 min | Als Gast: Startseite, Kursseite, Warenkorb, Kasse mit Produkt und Summe, Rechtstexte erreichbar; am Handy (390 px) | ☐ |
| T+15 min | Überwachung meldet „ok“; Fehler-Log leer | ☐ |
| T+24 h | Bestellungen, Freischaltungen und Mails des ersten Tages durchsehen; Systemstatus › Bestellabgleich ohne Rückstand | ☐ |

## 4. Rückfallplan

**Wann zurück:** Kasse nimmt kein Geld an oder schaltet nicht frei, Bestellmails kommen nicht an, Rechtstexte fehlen,
Fehlerseite bei Kernseiten, Überwachung meldet dauerhaft 503.

**Wie:**
1. Shop sofort wieder auf „Demnächst verfügbar“ (WooCommerce › Einstellungen › Sichtbarkeit). Damit nimmt der Shop keine
   Bestellungen mehr an, Lernende mit Zugang lernen weiter.
2. Ursache im Fehler-Log und Systemstatus suchen. Kleine Fehler mit einer PATCH-Version beheben (`docs/release-regel.md`).
3. Nur wenn nötig: ältere Plugin- bzw. Theme-Version einspielen. Bei neuerer Datenbank-Schema-Version das Backup von
   T−2 h zurückspielen. Achtung: Bestellungen seit dem Livegang gehen dabei verloren; vorher exportieren
   (WooCommerce › Bestellungen › Export bzw. `wp bootspruefung export entitlements`) und von Hand nachtragen.
4. Echtgeld-Bestellungen aus der Zwischenzeit prüfen: bezahlt? freigeschaltet? Kunden per Mail informieren.

## 5. Nach dem Livegang

- Erste Woche täglich: Systemstatus (Wartung, Bestellabgleich, OSS-Schwelle), Fehler-Log, Spam-Ordner des Absenders.
- Nach vier Wochen ohne Probleme: HSTS-Dauer beibehalten, DMARC auf `p=quarantine` (T54).
