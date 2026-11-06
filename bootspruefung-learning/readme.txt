=== Bootsprüfung Lernplattform ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.3.0

Lernplattform für Bootsführerscheine mit Fragenkatalog, Prüfungssimulation, Spezialtrainern und WooCommerce-Freischaltung.

== Description ==

Stand 0.3.0 (Konzept Kap. 11, Schritte 3–8):

* Content-Import: ZIP-Upload oder `wp bootspruefung import <paket>`, Prüfung (Prüfsummen, Schemata, Querverweise), Dry-Run, Freigabe, Import in Teilschritten, Revisionen, Archivierung, Protokoll
* Content-Browser: Übersicht, Kurse & Module, Fragen, Medien, Prüfungsregeln, Rechtefreigabe mit Begründung und Audit-Log
* Lernbereich unter /bootsscheine/: Kursübersicht, Smarttrainer, Modi Neu/Falsch/Merkliste, Modulfilter, offene Fragen
* Spezialtrainer: Navigationsaufgaben, Praxiskarten, Videokurs (Fortschritt, Schrittgalerien, Quiz), Funktraining (SRC-Seefunktexte mit Auswertung, UBI-Aufnahme/-Abgabe, Vokabeln, Buchstabiertafel)
* Prüfungssimulation: Zufallsprüfung nach Regelwerk, feste Bögen, Zeitlimit, Teilgrenzen, Ergebnis mit Rückblick
* REST unter /wp-json/bootspruefung/v1/ (Status, Import-Schritte, Kurse, Fragen, Antworten, Merkliste, Prüfungen)
* Zugang: kostenlose Regeln und Freischaltungen; solange kein Shop angebunden ist, wahlweise alle angemeldeten Nutzer
* Datenbankschema mit InnoDB und Fremdschlüsseln, Migration bei Aktivierung/Update, Datenschutz-Export/-Löschung, sichere Deinstallation
* Shortcode [bootspruefung] für einen Einstiegslink

Noch offen: WooCommerce-Freischaltung, Funkpraxis-Szenarien (8a), Gerätesimulation (8b), Paket-Rollback.
