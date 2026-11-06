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

Praxis-Anmeldung (ohne Prüfungsbuchung): unter Bootsprüfung → Praxis-Anmeldung Praxisorte, Formulare und
Prüfungsausschüsse pflegen; für Lernende erst unter Einstellungen › Praxis-Anmeldung freischalten (Standard: aus).

Membership-Funktionen (ohne Zusatz-Plugin): kostenlose Teilumfänge und zeitgesteuerte Freigaben unter
Bootsprüfung → Kostenlose Bereiche; geschützte Seiten über die Metabox „Lernzugang erforderlich“,
Abschnitte über den Block „Nur mit Lernzugang“ bzw. `[bootspruefung_zugang kurs="SBF-SEE" funktion="video_course"]…[/bootspruefung_zugang]`,
geschützte PDFs über das Feld „Lernzugang erforderlich“ in der Mediathek (nginx: Ordner
`uploads/bootspruefung/protected-*` zusätzlich sperren); Gruppenlizenzen über „Plätze je Stück“ im
Reiter Lernzugang; Mitgliederrabatte unter Bootsprüfung → Mitgliederrabatte. PDFs für die Downloadliste eines
Kurses in der Mediathek mit „Kurs · Downloads (PDF)“ markieren. Dispute-Webhooks (PayPal, allgemein für Klarna u. a.)
unter Bootsprüfung → Einstellungen › Zahlungskonflikte einrichten.

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

```bash
WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php bootspruefung-learning/tests/integration/practice-test.php
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
