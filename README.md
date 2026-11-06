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

### Tests des Plugins

Gegen eine WordPress-Installation mit MySQL/MariaDB, Plugin unter `wp-content/plugins/bootspruefung-learning`:

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/smoke-test.php
```

```bash
WP_DIR=/pfad/zu/wordpress PACKAGE_DIR=build/<package_id> php bootspruefung-learning/tests/integration/content-flow-test.php
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
