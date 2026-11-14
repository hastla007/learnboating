# Änderungsprotokoll – Bootsprüfung Lernplattform

Format: je Version die für Betreiber und Kunden sichtbaren Änderungen, danach Technik. Versionsnummern nach der
Release-Regel in `docs/release-regel.md`. Datenbank-Schema und App-API haben eigene Versionen (Systemstatus,
`docs/mobile-api/README.md`).

## Nächste Version (noch nicht veröffentlicht)

**Sicherheit (Prüfrunde 14.11., T129–T134)**
- App-Login nur noch für Kundenkonten: Verwaltungskonten (Administration, Redaktion, Shop, Plugin-Rechte) bekommen keine
  App-Tokens mehr, laufende App-Sitzungen solcher Konten enden sofort (das App-Login kennt keinen zweiten Faktor).
  Freigabe für Tests über den Filter `bootspruefung_mobile_user_allowed` (T131).
- „Bekanntes Gerät“ hängt am ganzen Passwort-Hash: Nach einer Passwortänderung gilt kein alter Nachweis mehr. Bestehende
  Nachweise werden mit dem Update einmalig ungültig; sie entstehen bei der nächsten Anmeldung neu (T130).
- Geschützte Dateien: nur PDF, Bilder, Audio und Video werden im Browser angezeigt, alles andere als Download; außer bei
  PDF mit `Content-Security-Policy: sandbox` (T133).
- Allgemeiner Konflikt-Webhook: Nachrichten mit Zeitstempel älter als 60 Tage oder mehr als einen Tag in der Zukunft werden
  abgewiesen (kein erneutes Einspielen, T134).
- Prüfungssimulation: Kulanz für nachgesendete Offline-Antworten über den Filter `bootspruefung_exam_late_tolerance`
  einstellbar (Standard 120 s, 0 = streng, nur Eingang beim Server zählt) – Entscheidung des Betreibers: Standard 120 s bleibt (T129).

**Gruppen (T132)**
- Persönliche Einladungen bleiben übertragbar. Die Einladungsmail bittet, den Link nur an die vorgesehene Person
  weiterzugeben. Öffnet jemand mit einem anderen Konto die Einladung, nennt der Lernbereich bzw. die App die
  eingeladene Adresse gekürzt („n…@example.de“) und fragt mit dem Knopf „Platz mit meinem Konto übernehmen“ nach.

App-API 1.13.0: Login nur für Kundenkonten, Felder `other_account`/`invited_email` in der Einladungsvorschau.

**Technik**
- Statische Analyse: Platzhalter für Klassen externer Plugins (`tests/phpstan/external-stubs.php`), PHPStan-Baseline
  201 → 129 Funde; Filter ohne `'strlen'`-Rückruf, Aktionen ohne Rückgabewert.
- Übersetzungsvorlage (`.pot`) mit den neuen Texten.

## 0.14.0 – 13.11.2026

Datenbank-Schema 1.15.0, App-API 1.12.0. Seit 0.13.0 rund 120 Änderungen; die wichtigsten:

**Betrieb und Auslieferung (Audit T106, T117, T118, T127)**
- „Auf neue Versionen prüfen“ (Einstellungen › Betrieb, Standard aus): höchstens einmal am Tag die GitHub-Releases,
  Ergebnis im Systemstatus („Neue Version“) und als Admin-Hinweis mit Link zur Release-Seite; Sicherheitsupdates als
  Warnung; keine automatische Installation, keine Website-Daten.
- Werkzeuge › Website-Zustand: Abschnitt „Bootsprüfung“ und Tests „Datenbank-Migration“ und „Inhaltspakete und
  geschützte Dateien“; Vorschlag für die Datenschutzerklärung (Einstellungen › Datenschutz › Leitfaden).
- Plugin-ZIP enthält CREDITS.md; „WC tested up to: 11.2“; das Theme-Paket enthält das Plugin nicht mehr.

**Lernbereich und App**
- Prüfungssimulation abbrechen (Web und App); Prüfungsantworten werden offline gepuffert und nachgesendet.
- Smarttrainer mit verteilter Wiederholung; Navigationstrainer mit Punktestand nach Selbsteinschätzung.
- Zugangsstatus „Zahlung offen“, „abgelaufen – verlängern“, „gesperrt“ in Web und App.
- Videos auf fremden Servern laden erst nach Klick oder Einwilligung „Externe Medien“.
- Kursgutscheine einlösen (Voucher-Captain-Connector, App-Routen `/vouchers/check` und `/vouchers/redeem`).
- Praxis-Tickets und „Praxis in deiner Nähe“ (Gegenstellen für ein Ticket-Plugin).
- App: Store-Links, `device_token` („bekanntes Gerät“), `Media.external_host`, Fehlercode `MAINTENANCE`.
- Kleinere Bedienfehler: Prüfungs-Puffer und Abbruch-Fehler mobil gut sichtbar, Bestätigung nach „Prüfung abbrechen“,
  getrennte Meldung für Antworten nach Abgabe in einem anderen Tab, lange Links im Ablauf-Hinweis umbrechen,
  „Dein nächster Schritt“ nur dann ein Video, wenn es offen ist, kein zweiter Kaufaufruf unter „Verlängern“,
  Gruppeneinladung für Gäste mit Gruppe und Kursen, deutsche Meldung bei kaputtem Einladungslink.

**Shop und Garantie**
- Hinweis zu Zahlungsarten, Verkaufsländern und Lieferung vor der Kasse; Hinweis gegen Doppelkäufe.
- Kasse für Gäste zeigt vor der Anmeldung Produkt und Summe.
- Geld-zurück-Garantie: Grenze ungerundet verglichen, Stand bleibt nach Datenlöschung; Prüfungssimulationen zählen
  nicht zur Nutzung (wie AGB Nr. 9).
- Ablauf-Erinnerung nur an Käufer, mit Hinweis bei Kasse und Registrierung und Abmeldelink.
- Livegang-Warnung „Zugang ohne Shop“ mit Knopf zum Umstellen; Hinweis bei zu altem WooCommerce.
- Konflikt-Webhook: Auswertung nach Ereigniszeit, optional Pflichtfelder `dispute_id` und `timestamp`.

**Datenschutz und Sicherheit**
- Echte Kontolöschung nach bestätigtem Antrag, erst nach Klick auf „Konto jetzt löschen“; Bestellungen je nach
  WooCommerce-Einstellung anonymisiert, Firma, Land und Transaktionsnummer bleiben.
- Login-Begrenzung mit „bekanntem Gerät“ (keine Sperre für geteilte IPs und kein Aussperren fremder Konten),
  IPv6 je /64, Warnung bei nicht eingetragenem Proxy; XML-RPC aus, keine Benutzer-Aufzählung.
- Content-Pakete und geschützte Dateien nicht per Direkt-URL abrufbar, Medientypen geprüft, Selbsttest.
- Audit-Log speichert Text-IDs korrekt (vorher als 0).

**Betrieb**
- Robuster stündlicher Wartungslauf, Bestellabgleich mit Wiederholung fehlgeschlagener Bestellungen, Aufbewahrung von
  Paketkopien und Import-Protokoll.
- Nach einem Update: schreibende Lern-Anfragen warten (503), bis die Migration gelaufen ist; Abschnitt „Wartung“ im
  Systemstatus. Kein stilles Zurückmigrieren mehr bei einem Downgrade.
- Eingebundene Dateien tragen den Dateistand in der Version (Browser laden Änderungen sofort).
- Versionsvertrag mit Theme und Add-ons; Export für das Laravel-Original (Zugänge vollständig, Format 2).

**Audit 13.11. (T77–T105)**
- Inhaltssperre zusätzlich ganz am Ende von Inhalt, Feed und Auszug; Elementor und Beaver Builder bekommen für
  gesperrte Seiten keine Inhalte (T77). Persönliche Seiten als nicht zwischenspeicherbar gekennzeichnet (T78).
- Login-Grenze atomar, Gruppenbeitritt gesperrt, Gutscheine je Sperre (T83); Einladungsvorschau nur mit Vor- und
  Nachname (T87); PayPal-Vorfilter, gleiche Antwortzeit bei unbekannten Konten, Tipps nicht über die REST-API für
  Gäste, IPv6 je /64 in allen Grenzen (T88).
- Migration nicht in Kunden-Ajax, Wartezeit nach Fehlschlag auch im Cron (T79); Wartungsläufe planen sich selbst neu,
  Ablauf-Erinnerung mit Sperre und Fehlerschutz (T80); Permalinks und Cron beim Aktivieren/Deaktivieren (T81);
  vollständige, genaue Deinstallation inkl. Multisite (T82); FAQ-/Tipps-Import nicht bei Besuchern (T84); Indizes
  auf `created_at` (Schema 1.15.0, T86).
- Shop schneller: Zuordnungen und Warenkorb einmal je Anfrage, Kurskennzahlen zwischengespeichert, OSS-Summen per
  SQL, Jahreswechsel-Fehler behoben (T85); Rücksprung im Unterordner, saubere Downloads, Ordnerumzug nur bei Bedarf
  (T105).
- Import ohne Hochladen (Ablageordner, Link), Grenze angezeigt, Prüfung vor dem Entpacken, deutscher Fortschritt (T96);
  Livegang-Ampel (T97); Katalog-Abgleich überschreibt keine von Hand geänderten Preise und keine zurückgestellten
  Entwürfe (T98); Hinweise gebündelt, Livegang-Checkliste in der Übersicht (T99).
- Einheitliche Begriffe mit Glossar (T100); Laufzeit vorbelegt, „ohne Ablauf“ nur ausdrücklich (T101); Protokollseite
  mit Verlauf je Zugang und Gruppe (T102); Rückfrage vor Rechte-/Regelwerk-Freigaben (T103); Einstellungen mit Bereich
  „Sicherheit“, Menü geordnet, Barrierefreiheit im Admin (T104).
- Übersetzbar: feste Text-Domain in allen Aufrufen, `languages/bootspruefung-learning.pot`, Simulator-Ansagen und
  Block übersetzbar (T89). Release-ZIPs, CI mit ZIP-, Update-, WordPress-7.0- und Analyse-Prüfung (T90–T95).

**Audit 13.11., Runde 2 (T107–T128)**
- Sicherheit: Inhaltspakete per Ablageordner nur noch aus einem geheimen Unterordner, Altbestand wird übernommen
  (T108); gemeinsame Einlösegrenze je IP für Gutscheine (T109); Anmelde-Schutz antwortet sofort und rechnet danach
  (T110); Antwort-Grenze je Nutzer (T114); Sperren gegen Doppelverarbeitung bei Prüfungsabgabe, Gutscheinen und
  Garantie-Anfrage (T115); Weiterleitungs-URLs nur https mit Prüfsumme, PayPal-Grenzen je IP, signierte
  Admin-Hinweise (T125); jede Datei unter `src/` beendet sich bei direktem Aufruf (T125, CI-Prüfung).
- Datenschutz: Export und Löschung erfassen alle Untertabellen; Multisite-Konten nur auf der eigenen Website (T111,
  T112).
- Leistung: Sperrprüfung geschützter Inhalte mit Zwischenspeicher (T113); Ablauf-Erinnerung in Blöcken mit
  Zeitbudget (T124); Admin-Listen laden Nutzer gesammelt (T124).
- Admin: Garantie-Felder im Produkt und Zuordnungsprüfung (T107); Protokoll mit verständlichen Bezeichnungen (T119);
  Hinweis bei fehlgeschlagener Migration (T120); Zugangsseiten behalten Eingaben, Import mit Klartext (T121);
  Livegang-Checkliste prüft selbst, Tabellen scrollen auf schmalen Bildschirmen (T126).
- Inhalte und Texte: FAQ-Blöcke werden bei jedem Aufruf frisch gerendert (T116); Seiten-Cache-Schutz nur noch über
  `DONOTCACHEPAGE` und Header (T123); Shop-Texte und Preise übersetzbar und nach Sprache formatiert (T128).

## 0.13.0 – 10.11.2026

Kursempfehlungen, Ausblenden von Doppeltem und Ablauf-Erinnerung (App-API 1.9.0).
