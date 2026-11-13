# Änderungsprotokoll – Bootsprüfung Lernplattform

Format: je Version die für Betreiber und Kunden sichtbaren Änderungen, danach Technik. Versionsnummern nach der
Release-Regel in `docs/release-regel.md`. Datenbank-Schema und App-API haben eigene Versionen (Systemstatus,
`docs/mobile-api/README.md`).

## 0.14.0 – 13.11.2026

Datenbank-Schema 1.14.0, App-API 1.12.0. Seit 0.13.0 rund 90 Änderungen; die wichtigsten:

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

## 0.13.0 – 10.11.2026

Kursempfehlungen, Ausblenden von Doppeltem und Ablauf-Erinnerung (App-API 1.9.0).
