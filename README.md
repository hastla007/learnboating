# learnboating

WordPress-/WooCommerce-Lernplugin für Bootsführerscheine (SBF, SRC, UBI, SKN, FKN).

| Pfad | Inhalt |
|---|---|
| `konzept-wp-lernplugin.md` | technisches Umsetzungskonzept inkl. Umsetzungsstand (Kap. 11) |
| `content-source/boatingschool/` | unveränderter Content-Snapshot aus [`hastla007/boatingschool`](https://github.com/hastla007/boatingschool), Stand siehe `SOURCE.md` |
| `tools/sync-source.sh` | Snapshot auf einen anderen Quell-Commit aktualisieren |
| `schema/` | JSON-Schemata des Content-Pakets (Format v1) |
| `tools/content-build/` | Konverter Snapshot → Content-Paket, Tests, Golden Master gegen Laravel |
| `bootspruefung-learning/` | das WordPress-Plugin |
| `tools/sync-device-sim.sh` | Gerätesimulation (JS der Quelle) ins Plugin übernehmen und CSS bauen |

```bash
tools/sync-source.sh <commit|branch|tag>
```

```bash
php tools/content-build/bin/build-package.php
```

```bash
cd tools/content-build && composer install && vendor/bin/phpunit
```

Content-Paket ins Plugin importieren (WP-CLI; alternativ im Admin unter Bootsprüfung → Content-Pakete):

```bash
wp bootspruefung import build/<package_id>.zip --yes
```

Der Lernbereich liegt danach unter `/bootsscheine/`.

Paket-Rollback auf einen früher veröffentlichten Stand (alternativ auf der Detailseite des Imports):

```bash
wp bootspruefung rollback <import-id> --reason="Fehler in Paket X"
```

WooCommerce: Im Produkt unter dem Reiter „Lernzugang“ Kurse und Funktionen zuordnen; bezahlte
Bestellungen schalten frei – unabhängig von der Zahlungsart (Überweisung, Klarna, PayPal, Stripe, weitere).
Sobald der Shop live ist, unter Bootsprüfung → Einstellungen „Zugang ohne Shop“ auf
„Nur kostenlose Regeln und Freischaltungen“ stellen. Abgleich Bestellungen ↔ Zugänge (zuerst Vorschau):

```bash
wp bootspruefung reconcile
```

```bash
wp bootspruefung reconcile --apply
```

Zusätzlich gleicht der stündliche Wartungslauf automatisch die seit dem letzten Lauf geänderten Bestellungen ab
(höchstens 100 je Lauf, nach einer Plugin-Deaktivierung ab deren Zeitpunkt). Er räumt außerdem alte Paketkopien
(behalten werden das aktive Paket und die letzten zwei veröffentlichten) und Import-Protokolle auf
(„unverändert“ nach 14, übrige Einträge nach 180 Tagen; Filter `bootspruefung_import_keep_packages`,
`bootspruefung_import_log_unchanged_days`, `bootspruefung_import_log_days`). Fehler einzelner Einträge bricht
der Lauf nicht ab; sie stehen in der Option `bp_learning_cron_errors` und im PHP-Fehlerprotokoll.

Prüfung anmelden: Lernende melden sich selbst beim Prüfungsausschuss an; der Lernbereich zeigt je Kurs die
Anleitung und alle Prüfungsausschüsse. Ausschüsse unter Bootsprüfung → Prüfungsanmeldung pflegen (fehlende mit „Prüfungsausschuss hinzufügen“, nur mit
Quelle; eigene Einträge bleiben bei Content-Importen erhalten); für Lernende unter Einstellungen › Prüfungsanmeldung
freischalten (Standard: aus).

Membership-Funktionen (ohne Zusatz-Plugin): kostenlose Teilumfänge und zeitgesteuerte Freigaben unter
Bootsprüfung → Kostenlose Bereiche; geschützte Seiten über die Metabox „Lernzugang erforderlich“,
Abschnitte über den Block „Nur mit Lernzugang“ bzw. `[bootspruefung_zugang kurs="SBF-SEE" funktion="video_course"]…[/bootspruefung_zugang]`,
geschützte PDFs über das Feld „Lernzugang erforderlich“ in der Mediathek (Content-Pakete und geschützte
Dateien liegen in `uploads/bootspruefung/packages-<geheim>` bzw. `protected-<geheim>`; unter nginx diese Ordner
zusätzlich sperren – die passende Regel und ein Selbsttest stehen unter Bootsprüfung → Systemstatus – oder mit
`define( 'BOOTSPRUEFUNG_PRIVATE_DIR', '/pfad/außerhalb/des/webroots' );` in `wp-config.php` ganz aus dem Webroot
nehmen); Login-Härtung (Sperre nach Fehlversuchen für wp-login, „Mein Konto“ und App, XML-RPC aus, keine
Benutzer-Aufzählung) einzeln abschaltbar über den Filter `bootspruefung_hardening`; Gruppenlizenzen über „Plätze je Stück“ im
Reiter Lernzugang; Mitgliederrabatte unter Bootsprüfung → Mitgliederrabatte. PDFs für die Downloadliste eines
Kurses in der Mediathek mit „Kurs · Downloads (PDF)“ markieren. Dispute-Webhooks (PayPal, allgemein für Klarna u. a.)
unter Bootsprüfung → Einstellungen › Zahlungskonflikte einrichten.
Videos auf einem fremden Server (anderer Host als die eigene Domain samt Subdomains) lädt die Lern-App erst nach
Klick oder mit der Einwilligung „Externe Medien“ im Cookie-Banner des Themes; die Kategorie erscheint dort automatisch,
sobald eine Videolektion fremd gehostet ist. Einen Dienstleister mit Auftragsverarbeitung als eigenen Server behandeln:
Filter `bootspruefung_video_first_party_hosts` (Liste von Hostnamen).

Erweiterungs-Plugins (Gutschein, Praxis-Tickets): Hooks, Filter und App-Routen, über die ein Gutschein- bzw.
Ticket-Plugin mit dem Lern-Plugin zusammenarbeitet, stehen in `docs/schnittstellen-erweiterungen.md`
(Kursgutschein einlösen, besitzt Kurs, Mitgliederrabatt, Garantie-Anteil, Tickets, „Praxis in deiner Nähe“).

### Tests des Plugins

Gegen eine WordPress-Installation mit MySQL/MariaDB, Plugin unter `wp-content/plugins/bootspruefung-learning`:

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/smoke-test.php
```

```bash
WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php bootspruefung-learning/tests/integration/content-flow-test.php
```

Zugangslogik ohne Shop (Testbestellungen) und gegen echtes WooCommerce (vorher `woocommerce-setup.php`):

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/commerce-test.php
```

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/woocommerce-test.php
```

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/membership-test.php
```

Schnittstellen für Gutschein- und Ticket-Plugins (mit WooCommerce, vorher `woocommerce-setup.php`):

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/extensions-test.php
```

```bash
WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php bootspruefung-learning/tests/integration/exam-guide-test.php
```

Browser-Tests (Playwright) laufen gegen einen laufenden Server; Vorbereitung mit
`tests/e2e/setup.php`, Server z. B. `php -S 127.0.0.1:8099 router.php` mit `tests/e2e/router.php`:

```bash
BP_USER=lerner1 BP_PASS=Test-Passwort-123 node bootspruefung-learning/tests/e2e/learning-flow.mjs
```

```bash
BP_ADMIN=admin BP_ADMIN_PASS=Admin-Passwort-123 BP_ZIP=build/<package_id>.zip node bootspruefung-learning/tests/e2e/admin-import.mjs
```

```bash
BP_USER=lerner1 BP_PASS=Test-Passwort-123 node bootspruefung-learning/tests/e2e/trainers-flow.mjs
```

```bash
BP_TOKEN="$(WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/e2e/membership-setup.php)" node bootspruefung-learning/tests/e2e/membership-flow.mjs
```

Kaufablauf (WooCommerce aktiv, vorher `woocommerce-setup.php`):

```bash
WP_DIR=/pfad/zu/wordpress node bootspruefung-learning/tests/e2e/checkout-flow.mjs
```

## Staging (Docker)

`staging/` enthält eine Testinstallation mit dem Zielsystem (PHP 8.5, WordPress 7.1.4, MariaDB 12.3.3, WooCommerce 11.2.2), samt Plugin, Theme, Content-Paket und Shop-Katalog:

```bash
cd staging && cp .env.example .env   # Passwörter eintragen
docker compose up -d
```

Anleitung (auch für einen Server mit HTTPS) und Abnahme-Checkliste: [`staging/README.md`](staging/README.md). Die CI startet das Staging bei jedem Push einmal komplett.

## Theme

`themes/kurswasser/` – Block-Theme „Kurswasser 2.0“ für Startseite, Shop, Produktseiten, Block-Checkout, Rechtstexte und Lernbereich. Details in `themes/kurswasser/README.md`.
