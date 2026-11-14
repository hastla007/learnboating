# Backup und Restore (To-do T66)

Das Lern-Plugin hat keine eigene Sicherung. Gesichert wird mit der Sicherung des Hosters oder einem Backup-Werkzeug
(z. B. `mysqldump` plus Dateikopie, restic, borg oder ein WordPress-Backup-Plugin). Hier steht, **was** dazugehören
muss, **wie oft**, **wie lange**, **wohin** und wie ein Restore geht und geprüft wird.

Kurzfassung: **ganze Datenbank + ganzer Ordner `wp-content` + `wp-config.php` + ggf. der private Ordner
(`BOOTSPRUEFUNG_PRIVATE_DIR`)**, täglich, verschlüsselt an einem anderen Ort. Nach jedem Restore
`wp bootspruefung backup-check` ausführen.

## 1. Was gesichert werden muss

Am einfachsten und sichersten: die **ganze Datenbank** sichern, nicht einzelne Tabellen. Die Teile hängen
voneinander ab (Zugänge verweisen auf Bestellungen und Nutzer, geschützte Dateien auf Anhänge). Ein Backup, das
nur einen Teil enthält, ist nach dem Restore inkonsistent.

### Datenbank

| Teil | Tabellen bzw. Einträge | Warum |
|---|---|---|
| Lern-Plugin | alle Tabellen `wp_bp_*` (55 Tabellen, Liste in `Schema::TABLES`) | Inhalte, Lernstände, Prüfungen, Zugänge (`wp_bp_entitlement`), Produktzuordnung, Gruppen, Audit-Log, App-Sitzungen |
| Einstellungen | Optionen `bp_learning_*` in `wp_options`, dazu die übrigen `bp_*`-Optionen (z. B. `bp_mail_test`, `bp_cron_last_hourly`) | Einstellungen, aktives Paket, Schema-Version, Rabatte, Dispute-Protokoll. **Wichtig:** `bp_learning_packages_suffix` und `bp_learning_protected_suffix` sind Teil der Ordnernamen. Fehlen sie, findet das Plugin Paket- und Schutzordner nicht mehr und legt neue, leere an. |
| Ablage-Pfade | Option `bp_learning_storage_layout`, Beitrags-Metadaten `_bp_protected_path` | stehen als **absolute Pfade** in der Datenbank (siehe Abschnitt 5, „Restore an einen anderen Ort“) |
| FAQ, Kunden-FAQ, Tipps | Beitragstypen `bp_faq`, `bp_customer_faq`, `bp_tip` mit Kategorien (in `wp_posts`, `wp_postmeta`, `wp_terms` …) | Inhalte, die im Admin gepflegt werden |
| WooCommerce | Bestellungen (bei HPOS `wp_wc_orders*`, sonst `wp_posts`/`wp_postmeta` mit `shop_order`), `wp_woocommerce_*`, `wp_wc_*`, Optionen `woocommerce_*` | Zugänge verweisen auf Bestellung und Bestellposition |
| Kundenkonten | `wp_users`, `wp_usermeta` | Zugänge und Lernstände verweisen auf Nutzer-IDs |

### Dateien

| Ordner | Inhalt |
|---|---|
| `wp-content/uploads/bootspruefung/media/` | Bilder und Medien der Lerninhalte (öffentlich) |
| `wp-content/uploads/bootspruefung/packages-<geheim>/` | Kopien der Content-Pakete (je Import rund 17 MB, behalten werden das aktive und die letzten zwei) |
| `wp-content/uploads/bootspruefung/protected-<geheim>/` | geschützte Dateien (PDFs mit Lernzugang, Seekarten) |
| Ordner aus `BOOTSPRUEFUNG_PRIVATE_DIR` | wenn gesetzt, liegen Pakete und geschützte Dateien **dort** statt im Upload-Ordner – liegt außerhalb des Webroots und wird von vielen Backup-Plugins nicht erfasst |
| übriger Ordner `wp-content/uploads/` | Produktbilder, Mediathek |
| `wp-content/languages/` | deutsche Sprachdateien (lassen sich auch neu laden) |
| `wp-config.php` | Datenbank-Zugang, **Sicherheitsschlüssel** (`AUTH_KEY` usw.), `BOOTSPRUEFUNG_PRIVATE_DIR`, `BOOTSPRUEFUNG_MONITOR_KEY`, `DISABLE_WP_CRON` |

Zu `wp-config.php`: Mit anderen Sicherheitsschlüsseln sind nach dem Restore alle Anmeldungen ungültig, und die
Abmeldelinks in schon verschickten Erinnerungsmails (`ReminderOptOut`) sowie „bekannte Geräte“ funktionieren nicht
mehr. Deshalb die Datei mitsichern.

Achtung bei Backup-Plugins: Manche schließen große Dateien, Ordner mit Punkt bzw. fremde Unterordner oder alles
außerhalb von `wp-content` aus. Einmal im Backup-Archiv nachsehen, ob `packages-…` und `protected-…` enthalten sind.

Der Systemstatus (Bootsprüfung › Systemstatus › „Datensicherung“) zeigt die Pfade dieser Installation.

## 2. Wie oft und wie lange (Vorschlag, Betreiber entscheidet)

| Was | Wann | Aufbewahrung |
|---|---|---|
| Datenbank + Dateien (voll) | täglich nachts | 14 tägliche, 4 wöchentliche, 3 monatliche Stände |
| Datenbank zusätzlich | vor jedem Plugin-/Theme-Update, vor jedem Content-Import, am Livegang-Tag (T−2 h) | bis zum nächsten erfolgreichen Update, mindestens 14 Tage |

- So gehen im schlimmsten Fall die Bestellungen und Lernstände eines Tages verloren. Wer weniger verlieren will,
  braucht häufigere Datenbank-Sicherungen (z. B. stündlich) oder Binärlog-Sicherung beim Hoster.
- **Datenschutz:** Backups enthalten personenbezogene Daten (Konten, Bestellungen, Lernstände). Gelöschte Konten
  bleiben bis zum Ablauf der Aufbewahrung im Backup. Höchstalter (hier rund 3 Monate) im Löschkonzept und im
  Verarbeitungsverzeichnis festhalten (T57). Nach einem Restore aus einem älteren Stand Löschungen seit diesem
  Stand erneut ausführen.
- **Rechnungen:** Die gesetzliche Aufbewahrung von Rechnungen (Jahre) ist **kein** Fall für WordPress-Backups, sondern
  für das Rechnungs- bzw. GoBD-Archiv (`docs/rechnungen-steuer.md`).

## 3. Backup-Ziel: Ort, Verschlüsselung, Auftragsverarbeitung

- **Anderer Ort:** nicht nur auf demselben Server bzw. beim selben Speicher wie die Website (sonst sind bei
  Serverausfall, Löschung oder Verschlüsselung durch Schadsoftware beide weg). Mindestens eine Kopie an einem
  zweiten Ort, auf den der Webserver selbst nicht löschend zugreifen kann.
- **Verschlüsselt:** Übertragung nur verschlüsselt (SFTP, HTTPS); gespeichert verschlüsselt (z. B. restic/borg mit
  Passwort, oder verschlüsselter Speicher des Anbieters). Den Schlüssel **getrennt** vom Backup aufbewahren
  (Passwort-Manager und ein Ausdruck bzw. Offline-Kopie). Ohne Schlüssel ist das Backup wertlos.
- **Auftragsverarbeitung:** Mit dem Anbieter des Backup-Speichers (bzw. dem Hoster, wenn er sichert) einen Vertrag zur
  Auftragsverarbeitung nach Art. 28 DSGVO schließen, Serverstandort EU/EWR bevorzugen, im Verarbeitungsverzeichnis
  eintragen (T57).
- **Zugriff:** nur Betreiber und Hoster; eigene Zugangsdaten nur für das Backup, mit Zwei-Faktor-Anmeldung, wo möglich.
- **Kontrolle:** Backup-Werkzeug meldet Fehler per Mail; einmal im Monat prüfen, ob die letzte Sicherung da ist und
  eine plausible Größe hat.

## 4. Backup erstellen (Beispiel mit Bordmitteln)

```bash
# Datenbank (InnoDB: konsistent ohne Sperre)
mysqldump --single-transaction --quick --routines --triggers --hex-blob \
  --default-character-set=utf8mb4 DATENBANK | gzip > db-$(date +%F).sql.gz
# oder: wp db export - --path=/pfad/zu/wordpress | gzip > db-$(date +%F).sql.gz

# Dateien
tar -czf wp-content-$(date +%F).tar.gz -C /pfad/zu/wordpress wp-content wp-config.php
# nur falls gesetzt:
tar -czf private-$(date +%F).tar.gz -C /pfad/ausserhalb/des/webroots .

sha256sum db-*.sql.gz wp-content-*.tar.gz > SHA256SUMS
```

Danach verschlüsselt an das Backup-Ziel übertragen.

## 5. Restore

### Vorher entscheiden: an denselben Ort oder an einen anderen?

Paket- und Schutzordner stehen mit **absolutem Pfad** in der Datenbank (`bp_learning_storage_layout`,
`_bp_protected_path`). Das Plugin gleicht den Ordner beim nächsten Admin-Aufruf bzw. stündlichen Cron-Lauf an den
Soll-Ort an – und **verschiebt** dabei den Ordner vom alten Pfad an den neuen, wenn es den alten Pfad noch gibt.

- **Restore an denselben Ort** (gleicher Server, gleiche Pfade, z. B. nach einem Fehler auf Live): nichts weiter zu tun.
- **Restore an einen anderen Ort, der alte Pfad existiert nicht** (neuer Server): Ordner werden gefunden, aber die
  geschützten Dateien zeigen noch auf den alten Pfad → Pfade ersetzen (Schritt 5 unten).
- **Testkopie oder Staging neben dem Original auf demselben Server: gefährlich.** Ohne Pfad-Ersetzung würde der erste
  Admin-Aufruf in der Kopie den Paket- und Schutzordner **des Originals in die Kopie verschieben**. Das Original hätte
  danach keine geschützten PDFs mehr. Deshalb: in der Kopie zuerst Cron aus, Pfade ersetzen, `backup-check`, erst
  danach den Admin öffnen.

### Schritte

1. Wartungsmodus bzw. Shop „Demnächst verfügbar“ am Ziel, damit während des Restores niemand bestellt.
2. Prüfsummen prüfen (`sha256sum -c SHA256SUMS`), Archive entschlüsseln.
3. Datenbank einspielen (leere Datenbank, Zeichensatz utf8mb4):
   ```bash
   zcat db-JJJJ-MM-TT.sql.gz | mysql ZIEL_DATENBANK
   ```
4. Dateien einspielen: `wp-content` (mindestens `uploads/`), `wp-config.php` bzw. dessen Schlüssel und
   `BOOTSPRUEFUNG_*`-Konstanten, ggf. den privaten Ordner an seinen Pfad. Datenbank-Name und -Zugang in
   `wp-config.php` an das Ziel anpassen. Dateirechte: Der Webserver muss in `uploads/bootspruefung/` schreiben dürfen.
5. **Nur bei anderem Ort** – vor jedem Aufruf im Browser:
   ```bash
   # in einer Kopie neben dem Original zusätzlich in wp-config.php: define('DISABLE_WP_CRON', true);
   wp search-replace '/alter/pfad/wp-content/uploads/bootspruefung' '/neuer/pfad/wp-content/uploads/bootspruefung' \
     wp_options wp_postmeta --precise --path=/neuer/pfad
   # bei privatem Ordner entsprechend dessen alten und neuen Pfad
   # bei anderer Domain: wp search-replace 'https://alte-domain.de' 'https://neue-domain.de' --precise
   ```
6. Prüfen: `wp bootspruefung backup-check --path=/neuer/pfad` (Abschnitt 6).
7. **Testkopie/Staging:** Ausgehende Mails abfangen (z. B. Mailpit) oder abschalten, Zahlungsarten in den
   Testmodus bzw. Webhooks der Zahlungsanbieter nicht auf die Kopie zeigen lassen. Sonst bekommen echte Kunden
   Erinnerungs- und Bestellmails aus der Kopie.
8. Admin öffnen: Systemstatus (Schema „aktuell“, Wartung ohne Fehler, Dateischutz „geschützt“). Ist das Backup älter
   als das Plugin, läuft jetzt die Datenbank-Migration (oder `wp bootspruefung migrate`).
9. Shop wieder sichtbar schalten (nur Live).

## 6. Prüfen nach dem Restore

`wp bootspruefung backup-check` prüft nur und ändert nichts. Dieselbe Tabelle steht im Systemstatus unter
„Datensicherung“ (im Admin erst aufrufen, wenn Schritt 5 erledigt ist – siehe oben). Exit-Code 1 bei einem Fehler,
`--format=json` für Skripte.

| Prüfung | Fehler bedeutet |
|---|---|
| Datenbank-Schema | Option `bp_learning_schema_version` fehlt, oder Backup stammt aus einer neueren Plugin-Version (Warnung: Migration fällig) |
| Tabellen | eine `wp_bp_*`-Tabelle fehlt oder ist nicht InnoDB |
| Fremdschlüssel | weniger als erwartet (Warnung) |
| Optionen `bp_learning_*` | Ordner-Geheimnisse fehlen |
| Paketordner, Schutzordner | Ordner fehlt, oder `bp_learning_storage_layout` zeigt auf einen vorhandenen fremden Ordner (Gefahr: Verschieben) |
| Aktives Content-Paket | kein veröffentlichter Import zum aktiven Paket (Tabellen und Optionen aus verschiedenen Ständen); Warnung, wenn die Paketkopie fehlt |
| Geschützte Dateien | Datei fehlt am eingetragenen Pfad; Warnung, wenn sie außerhalb des Schutzordners liegt |
| Medien | Bilder der Inhalte fehlen im Medienordner |
| Lernzugänge | Stichprobe von bis zu 25 aktiven Theorie-Zugängen: `AccessService::can()` muss „ja“ sagen; Warnung bei aktiven Zugängen ohne Nutzerkonto |
| WooCommerce-Bestellungen | Bestellungen zu nicht beendeten Zugängen fehlen (WooCommerce-Daten nicht im selben Backup) |

Danach von Hand:

- mit einem Testkonto mit Zugang anmelden, „Mein Lernen“ und eine Frage öffnen;
- eine geschützte PDF über `/bootsscheine/datei/<id>/` laden (mit Zugang: lädt; abgemeldet: Anmeldung);
- WooCommerce › Bestellungen: neueste Bestellung des Backup-Zeitpunkts vorhanden;
- Systemstatus › Wartung: „Letzter stündlicher Lauf“ aktualisiert sich (Cron läuft).

## 7. Übung am 13.11.2026 (Testumgebung)

Einmal vollständig geübt, mit der lokalen Testinstallation (WordPress mit WooCommerce 11.2.2, Plugin 0.14.0,
Schema 1.14.0) – Restore an einen **anderen Ort auf demselben Server**, also der schwierigste Fall.

**Vorbereitung:** Die Testinstallation hatte vorher keine Upload-Dateien. Für die Übung wurden die 221 Medien aus
dem Paket-Build, eine Paketkopie (17 MB) und eine geschützte Test-PDF angelegt.

**Ablauf und Messwerte:**

| Schritt | Ergebnis |
|---|---|
| Backup Datenbank (`mysqldump`, gzip) | 103 Tabellen, 38,8 MB in der Datenbank → Dump 16,4 MB, gepackt 2,1 MB; 0,4 s |
| Backup Dateien (`tar` von `uploads/` + `wp-config.php`) | 465 Dateien, 28,3 MB → gepackt 21,9 MB; 0,8 s |
| Prüfsummen | `sha256sum -c` vor dem Restore: alle „OK“ |
| Restore Datenbank in neue Datenbank | 2,3 s |
| Restore Dateien in eine Kopie der Installation, `wp-config.php` auf die neue Datenbank, `DISABLE_WP_CRON` | 0,3 s |
| `backup-check` vor der Pfad-Ersetzung | **Fehler** bei Paket- und Schutzordner (Layout zeigt auf das Original, das noch existiert), Warnung bei der geschützten Datei (liegt beim Original) – wie erwartet |
| `wp search-replace` alter → neuer Uploads-Pfad in `wp_options`, `wp_postmeta` | 2 Ersetzungen |
| `backup-check` danach | alles ok bis auf eine Warnung (siehe unten) |
| Systemstatus-Funktionen per PHP-Skript (`SystemStatus::collect()`) | Schema 1.14.0 aktuell, 41 Fremdschlüssel, 0 fehlende Tabellen, WooCommerce aktiv |
| `AccessService::can()` für einen Nutzer mit SBF-See-Zugang | ja; ohne Anmeldung: nein |
| Geschützte PDF | vorhanden unter dem neuen Pfad, gleiche SHA-256-Prüfsumme wie im Original, `get_attached_file()` liefert den neuen Pfad |
| Zeilen je Tabelle Original vs. Restore | gleich; einziger Unterschied 2 Transient-Optionen, die WordPress beim ersten Laden anlegt |
| Gesamtdauer reiner Arbeitsschritte | unter 10 Sekunden (ohne Übertragung und Entschlüsselung; auf Live mit mehr Daten länger) |

**Auffälligkeiten:**

1. **Absolute Pfade:** Ohne Schritt 5 hätte die Kopie beim ersten Admin-Aufruf die Ordner des Originals
   verschoben. Genau dieser Fall trat schon vor der Übung auf: Die Datenbank der Testinstallation war aus einer
   anderen Testinstallation kopiert, `bp_learning_storage_layout` zeigte auf deren (vorhandenen) Ordner. Vor dem
   ersten Aufruf korrigiert. `backup-check` erkennt das jetzt als Fehler.
2. **Zugänge ohne Nutzerkonto:** 7 aktive Zugänge gehören zu Nutzer-IDs, die es nicht gibt (Testdaten, auch im
   Original so). Auf Live ist das ein Zeichen, dass `wp_users` nicht aus demselben Stand stammt oder Konten am
   Plugin vorbei gelöscht wurden – prüfen.
3. Beendete Zugänge (`revoked`) zu endgültig gelöschten Bestellungen sind normal und werden nicht als Fehler gezählt.

Restore-Datenbank und Kopie der Installation wurden danach gelöscht.

**Offen für den Betreiber:** Backup-Werkzeug bzw. Hoster-Sicherung festlegen (Abschnitt 2 und 3), Vertrag zur
Auftragsverarbeitung, und die Übung **einmal mit dem echten Backup auf Staging** wiederholen (T67, Livegang-Drehbuch
T−2 h). Ergebnis hier mit Datum eintragen.
