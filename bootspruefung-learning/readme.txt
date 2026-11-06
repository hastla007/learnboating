=== Bootsprüfung Lernplattform ===
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0

Lernplattform für Bootsführerscheine mit Fragenkatalog, Prüfungssimulation, Spezialtrainern und WooCommerce-Freischaltung.

== Description ==

Stand 0.1.0 (Plugin-Gerüst, Konzept Kap. 11 Schritt 3):

* Datenbankschema für Content, Lernstände, Import, Audit und Zugänge (InnoDB, Fremdschlüssel)
* versionierte, gesperrte Migration bei Aktivierung und Update
* Capabilities manage_bp_content, import_bp_content, manage_bp_entitlements
* Adminmenü mit Systemstatus und Einstellungen
* REST: GET /wp-json/bootspruefung/v1/status
* WP-CLI: wp bootspruefung status|migrate
* WooCommerce: Kompatibilität mit HPOS und Cart/Checkout Blocks deklariert
* Datenschutz: Export/Löschung der Lerndaten über die WordPress-Werkzeuge
* Deinstallation löscht Daten nur auf ausdrückliche Einstellung

Content-Import, Lernoberfläche und WooCommerce-Freischaltung folgen in den nächsten Schritten.
