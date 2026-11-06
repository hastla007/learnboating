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

Plugin-Rauchtest gegen eine WordPress-Installation (MySQL/MariaDB, Plugin unter
`wp-content/plugins/bootspruefung-learning`):

```bash
WP_DIR=/pfad/zu/wordpress php bootspruefung-learning/tests/integration/smoke-test.php
```
