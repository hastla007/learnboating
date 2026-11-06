=== Bootsprüfung Lernplattform ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.9.0
WC requires at least: 8.0
WC tested up to: 11.2

Lernplattform für Bootsführerscheine mit Fragenkatalog, Prüfungssimulation, Spezialtrainern und WooCommerce-Freischaltung.

== Description ==

Stand 0.9.0 (Konzept Kap. 11, Schritte 3–9c):

* Content-Import: ZIP-Upload oder `wp bootspruefung import <paket>`, Prüfung (Prüfsummen, Schemata, Querverweise), Dry-Run, Freigabe, Import in Teilschritten, Revisionen, Archivierung, Protokoll; Rollback auf einen früheren Stand (`wp bootspruefung rollback`)
* Content-Browser: Übersicht, Kurse & Module, Fragen, Medien, Prüfungsregeln, Rechtefreigabe mit Begründung und Audit-Log
* Lernbereich unter /bootsscheine/: Kursübersicht, Smarttrainer, Modi Neu/Falsch/Merkliste, Modulfilter, offene Fragen
* Spezialtrainer: Navigationsaufgaben, Praxiskarten, Videokurs (Fortschritt, Schrittgalerien, Quiz), Funktraining (SRC-Seefunktexte mit Auswertung, UBI-Aufnahme/-Abgabe, Vokabeln, Buchstabiertafel), Funkpraxis-Szenarien mit Auswertung, Gerätesimulation (Original-JavaScript der Quelle unter assets/device-sim)
* Prüfungssimulation: Zufallsprüfung nach Regelwerk, feste Bögen, Zeitlimit, Teilgrenzen, Ergebnis mit Rückblick
* REST unter /wp-json/bootspruefung/v1/ (Status, Import-Schritte, Kurse, Fragen, Antworten, Merkliste, Prüfungen)
* WooCommerce: Produktfeld „Lernzugang“ (Kurse × Funktionen, Dauer, Verlängerung, Erstattungsregel, Variationen), Freischaltung bei bezahlter Bestellung unabhängig vom Gateway (Überweisung, Klarna, PayPal, Stripe, weitere), Aussetzung bei Dispute, Widerruf bei Erstattung/Storno je Position, Kundenkonto Pflicht, „Mein Lernen“ in Mein Konto, Kurslink in Bestätigung und E-Mail, Bestellaktionen, Abgleich `wp bootspruefung reconcile`; HPOS und Checkout-Blocks
* Membership: kostenlose Teilumfänge (n Fragen je Modul, n Prüfungsbögen, erste n Lektionen/Aufgaben/Übungen), zeitgesteuerte Freigabe von Modulen und Videokapiteln, Seiten/Beiträge per Metabox, Abschnitte per Block „Nur mit Lernzugang“ oder Shortcode [bootspruefung_zugang], geschützte Dateien (PDF) über /bootsscheine/datei/<id>/, Gruppenlizenzen (Plätze je Paket, Einladung per Link oder E-Mail, Verwaltung unter /bootsscheine/gruppen/), Mitgliederrabatte im Shop
* Prüfung anmelden (vorbereitet, standardmäßig deaktiviert): Anleitung je Prüfung wie die Quelle (Gebühr, Fristen, Mindestalter, Unterlagen, Prüfungsformen mit Umfang und Bestehensgrenze, Prüfungsteile, FAQ, DSV/DMYV) und alle Prüfungsausschüsse mit Suche; Lernende melden sich selbst an
* Downloads (PDF) je Kurs, Zuordnungsfehler in der Admin-Übersicht, Dispute-Webhooks für PayPal und gateway-neutral (Klarna u. a.)
* Zugang: kostenlose Bereiche, manuelle Freigaben (mit Begründung) und Shop-Freischaltungen; solange der Shop nicht live ist, wahlweise alle angemeldeten Nutzer
* Datenbankschema mit InnoDB und Fremdschlüsseln, Migration bei Aktivierung/Update, Datenschutz-Export/-Löschung, sichere Deinstallation
* Shortcode [bootspruefung] für einen Einstiegslink

Noch offen: Content-Freigabe und Go-live (Schritt 10).
