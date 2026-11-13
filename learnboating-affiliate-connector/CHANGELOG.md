# Änderungsprotokoll – LearnBoating Partnerprogramm-Connector

Die Version steht im Plugin-Kopf (`Version:`) und in der Konstante `LBAC_VERSION`; beide müssen gleich sein (Test
`tests/connector-test.php`). Auslieferung: `docs/installation-und-updates.md`.

## 1.1.0 – 13.11.2026

- Partnercode auch am Video-Upgrade und am Shop-Link der App.
- Versionsvertrag: braucht die Lernplattform ab 0.13.0 und prüft die genutzten Funktionen; sonst bleibt der Connector
  kontrolliert inaktiv und zeigt einen Hinweis (vorher drohte ein Fehler im Bestellablauf).
- `Requires at least: 7.0`, `Update URI: false`, Autor „LearnBoating“ (Projektname, vom Betreiber festzulegen).
- `uninstall.php` löscht die Einstellungen (`lbac_settings`), bei Multisite je Website (T82).
- Übersetzbar: Sprachordner `languages/` mit Vorlage `.pot`, Text-Domain wird geladen (T89).

## 1.0.0 – 11.11.2026

Erste Fassung: Partnercode aus der App bis in den Shop, keine Provision auf Gruppenpakete und Upgrades, Haltefrist
aus Geld-zurück-Garantie und Widerrufsfrist, kontrolliert inaktiv bei unpassendem Vertrag von WP Affiliate Captain.
