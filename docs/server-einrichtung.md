# Live-Server: Apache oder nginx (To-do T33)

Entscheidung des Betreibers bzw. Hosters: Läuft die Live-Website unter Apache oder unter nginx? Beides geht. Unter
nginx sind ein paar Regeln von Hand nötig, weil nginx keine `.htaccess`-Dateien liest. Die Schritte je Server
stehen unten. Alles Weitere zu App und API: `docs/mobile-api/README.md`, Abschnitt „Server-Voraussetzungen“.

## Versionen (To-do T34)

| Software | Mindestens | Getestet in CI |
|---|---|---|
| PHP | 8.1 | 8.1 und 8.5 (Einzeltests), 8.5 (mit WordPress und WooCommerce) |
| WordPress | 7.0 | 7.1.4 |
| WooCommerce | 11.2 | 11.2.2 und 11.3 (Beta) |

- **Zu altes WordPress:** WordPress selbst verhindert das Aktivieren des Lern-Plugins und des Themes
  (Kopfzeile „Requires at least: 7.0“).
- **Zu altes WooCommerce:** Das Plugin lässt sich aktivieren, zeigt aber im ganzen Admin einen Hinweis. Kasse,
  Freischaltung und die Zustimmung zur sofortigen Ausführung sind mit älteren Versionen nicht getestet.
- **Bitte bestätigen:** Läuft das Live-System mit mindestens WordPress 7.0 und WooCommerce 11.2?

## Für beide gleich

- **HTTPS** mit gültigem Zertifikat. Hinter einem TLS-Proxy muss `X-Forwarded-Proto: https` ankommen.
- **Proxy-Adresse eintragen**, falls ein Reverse-Proxy, Load-Balancer oder CDN vor WordPress steht:
  Bootsprüfung → Einstellungen → Sicherheit → „Vertrauenswürdige Proxys“. Sonst teilen sich alle Kunden die Anmelde-Limits der
  Proxy-IP.
- **Echter System-Cron** statt WP-Cron bei Besuchen (`define('DISABLE_WP_CRON', true);` und auf dem Server
  `wp cron event run --due-now` alle 5 Minuten). Das Lern-Plugin braucht den Cron für Aufräumen, Erinnerungen,
  Bestellabgleich und Datenexporte.
  Fehlen der stündliche Lauf (`bp_learning_hourly`) oder die tägliche Ablauf-Erinnerung (`bp_learning_daily`),
  z. B. nach einem Umzug oder einem Restore ohne Cron-Einträge, plant das Plugin sie bei der nächsten Anfrage
  von selbst neu. Die Ablauf-Erinnerung läuft nie doppelt parallel; ein Fehler bei einer Mail steht unter
  Systemstatus › Wartung und hält die übrigen Mails nicht auf. Sie arbeitet in Stapeln: höchstens 50 Empfänger
  und 20 Sekunden (bzw. die Hälfte von `max_execution_time`) je Lauf, der Rest folgt eine Minute später über das
  einmalige Ereignis `bp_learning_expiry_reminder_continue`. Anpassbar über die Filter
  `bp_learning_expiry_reminder_batch_size` und `bp_learning_expiry_reminder_time_budget`.
- **Deaktivieren und Reaktivieren** des Plugins ist gefahrlos: Beim Deaktivieren trägt das Plugin alle eigenen
  Cron-Ereignisse aus (`Plugin::CRON_HOOKS`) und nimmt `/bootsscheine/` sowie die „Mein Konto“-Endpunkte
  „mein-lernen“ und „meine-daten“ aus den Permalinks. Beim Aktivieren trägt es sie sofort wieder ein und schreibt
  die Permalinks bei der nächsten Anfrage noch einmal. Daten bleiben bei beidem erhalten.
- **Deinstallieren** löscht Daten nur, wenn unter Bootsprüfung › Einstellungen „Daten beim Deinstallieren löschen“
  gesetzt ist (Standard: behalten; Cron-Ereignisse werden immer ausgetragen). Dann gehen: alle Tabellen, eigene
  Optionen (auch das Testmail-Ergebnis mit E-Mail-Adresse) und Transients mit genau den eigenen Namen, Nutzer-Meta
  (App-Einstellungen, Ablauf-Erinnerung und Widerspruch, Praxis-Tickets), Tipps, FAQ und Kunden-FAQ in jedem
  Status samt Kategorien, Seekarten, importierte Medien und Pakete samt Ablageordner (`packages-<geheim>/import/`;
  auch der frühere `uploads/bootspruefung-import/`, falls noch vorhanden) und die Merkwerte des Katalog-Abgleichs an Produkten (Preis, Status). Es bleiben: Bestellungen und Produkte in
  WooCommerce, die Seite „Häufige Fragen“, geschützte Kurs-PDFs im gesperrten Ordner. Multisite: je Website nach
  deren Einstellung; Nutzer-Meta nur, wenn keine Website ihre Daten behält. Wird eine Website gelöscht, gehen ihre
  Lernplattform-Tabellen mit (solange das Plugin auf der Hauptwebsite aktiv ist). Die Add-ons haben eigene
  `uninstall.php` (siehe deren README).
- **Kein Seiten-Cache** für `/wp-json/boatingschool/*`, `/bootsscheine/*`, Warenkorb, Kasse und „Mein Konto“.
  Das Plugin kennzeichnet diese Seiten selbst (T78): Lernbereich `/bootsscheine/…` mit Gruppeneinladung
  (`gruppe/…`) und Abmeldeseite (`erinnerungen`), `/vertrag-widerrufen/` und das Widerrufsformular sowie Seiten
  mit „Lernzugang erforderlich“ oder dem Block bzw. Shortcode „Nur mit Lernzugang“ (auch in FAQ-Antworten). Gesetzt
  werden `DONOTCACHEPAGE` (WP Super Cache, W3 Total Cache, WP Rocket u. a.),
  `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` und für LiteSpeed
  `X-LiteSpeed-Cache-Control: no-cache` bzw. die Aktion `litespeed_control_set_nocache`. Bewusst **nicht**
  `DONOTCACHEOBJECT` und `DONOTCACHEDB` (T123): damit schaltet W3 Total Cache Objekt- und Datenbank-Cache für die
  ganze Anfrage ab, gerade auf den Seiten mit den meisten Abfragen. Die Kennzeichnung gilt nur für Seitenaufrufe im
  Frontend; in der Verwaltung, bei Cron und WP-CLI setzt das Plugin nichts, bei REST- und Ajax-Anfragen nur die
  Kopfzeilen (ohne Konstante). Ein CDN oder ein
  Server-Cache (nginx `fastcgi_cache`, Varnish), der `Cache-Control` nicht beachtet, braucht die Pfade trotzdem
  als Ausnahme. Für eigene Regeln gibt es die Aktion `bootspruefung_nocache`.
- **Zwischenspeicher des Plugins (T85):** Produktzuordnungen, Mitgliedskurse und „Warenkorb mit Lernprodukt“ gelten nur
  für eine Anfrage und werden bei jeder Änderung sofort verworfen. Die Kennzahlen der Kurse (Fragen, Aufgaben, Videos
  für Produkttexte und Kurskarten) liegen bis zu einem Tag im Transient `bp_learning_course_stats`; Import,
  Paketwechsel, Rechte-Änderungen und Produktänderungen leeren ihn. Mit persistentem Object-Cache (Redis/Memcached)
  liegt der Transient dort, sonst in der Optionstabelle. Nichts davon muss von Hand geleert werden.
  FAQ und Kunden-FAQ liegen in den Objekt-Cache-Gruppen `bp_faq` bzw. `bp_cfaq` (bis zu einem Tag, bei jeder
  Änderung neu); Antworten mit dynamischen Blöcken (z. B. „Nur mit Lernzugang“) nur als Rohinhalt – sie werden bei
  jeder Ausgabe für die jeweilige Person gerendert (T116). Die Inhaltssperre merkt sich ihre Prüfungen nur für die
  laufende Anfrage (T113).
- **Speicherordner (T105):** Den Umzug der privaten Ordner (Content-Pakete, geschützte Dateien), z. B. nach dem
  Setzen von `BOOTSPRUEFUNG_PRIVATE_DIR`, erledigt der stündliche Cron oder der nächste Admin-Aufruf; normale
  Besucheranfragen prüfen die Ordner nicht. Sofort: Systemstatus › Dateischutz › „Erneut prüfen“.
- **WordPress im Unterordner** (z. B. `https://example.de/schule/`): Rücksprünge nach der Anmeldung bilden den Pfad
  relativ zur Website-Adresse (T105). Geschützte PDFs gehen ohne vorangestellte Ausgabe anderer Plugins raus und
  behalten Umlaute im Dateinamen.
- **Ordner außerhalb des Webroots (empfohlen, für beide Server):** in `wp-config.php`
  `define('BOOTSPRUEFUNG_PRIVATE_DIR', '/pfad/außerhalb/des/webroots');`. Dann liegen Content-Pakete,
  der Ablageordner für den Import und geschützte PDFs gar nicht im Webordner, und die Sperrregeln unten sind nur
  noch eine zweite Sicherung.
- **Ablageordner für den Import per SFTP (T108):** liegt im privaten Paketordner,
  `packages-<geheim>/import/` (den genauen Pfad zeigt Bootsprüfung › Inhaltspakete unter „Ohne Hochladen
  importieren“). Er ist im Selbsttest „Dateischutz“ enthalten und von der nginx-Regel erfasst. Der frühere feste
  Ordner `uploads/bootspruefung-import/` wird nach dem Update einmalig geleert (Inhalt in den neuen Ordner
  verschoben) und gelöscht; solange dort noch etwas liegt, prüft ihn der Selbsttest mit, und die Importseite warnt.
  Nach einer erfolgreichen Übernahme löscht das Plugin die abgelegte ZIP-Datei bzw. den Paketordner.
- **PHP-FPM bzw. LiteSpeed empfohlen (T110):** „Passwort vergessen“ (Web und App) schickt die Antwort vor dem
  Mailversand ab (`fastcgi_finish_request` bzw. `litespeed_finish_request`), damit die Antwortzeit nicht verrät, ob
  es ein Konto gibt. Mit mod_php dauert stattdessen jede dieser Antworten mindestens 2 Sekunden.
- **Prüfen:** Bootsprüfung → Systemstatus, Abschnitt „Dateischutz“ (Selbsttest) und
  Bootsprüfung → Mobile App → „Server prüfen“ (kommt der Authorization-Header an?).

## Apache

Läuft ohne Zusatzregeln für den Dateischutz: Das Plugin legt in den geschützten Ordnern eine `.htaccess` mit
„Require all denied“ an. Voraussetzung: `AllowOverride` erlaubt `.htaccess` im Upload-Ordner.

- **Authorization-Header:** Die Standard-`.htaccess` von WordPress reicht ihn weiter
  (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`). Bei CGI/FastCGI ohne diese Zeile:
  `CGIPassAuth On`.
- **App-Links:** `/.well-known/apple-app-site-association` und `/.well-known/assetlinks.json` müssen bei WordPress
  ankommen. Keine statischen Dateien gleichen Namens anlegen.

## nginx

Drei Punkte sind von Hand nötig:

1. **Dateischutz:** Die passenden Regeln für diese Installation zeigt Bootsprüfung → Systemstatus → „Dateischutz“
   an (mit dem tatsächlichen Upload-Pfad). Bei Standardpfaden sehen sie so aus:

   ```nginx
   location ~* ^/wp-content/uploads/bootspruefung/(packages|protected) {
       deny all;
   }
   location ~* ^/wp-content/uploads/bootspruefung-import/ {
       deny all;
   }
   location ~* ^/wp-content/uploads/bootspruefung/media/.*\.(php[0-9]?|phtml|phar|html?|svgz?)$ {
       deny all;
   }
   ```

   Die Regeln vor den allgemeinen Regeln für statische Dateien eintragen. Danach den Selbsttest auf der
   Statusseite erneut starten. Die erste Regel deckt auch den Ablageordner `packages-<geheim>/import/` ab; die
   zweite sperrt den früheren Ablageordner, falls er noch nicht geleert werden konnte (T108).
2. **Authorization-Header** an PHP-FPM weiterreichen: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`
3. **App-Links:** Sperrt eine Regel Punkt-Verzeichnisse (z. B. `location ~ /\.`), davor eine Ausnahme setzen:
   `location ^~ /.well-known/ { try_files $uri /index.php?$args; }`

## Frage an den Betreiber

Welcher Webserver läuft beim Hoster: Apache oder nginx? Kann `BOOTSPRUEFUNG_PRIVATE_DIR` gesetzt werden (Ordner
außerhalb des Webroots)?
