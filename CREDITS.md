# Fremdbestandteile (CREDITS)

Bestandteile, die nicht in diesem Repository entstanden sind, mit Lizenz und Beleg. Die Lizenz der eigenen Pakete
ist noch offen: `docs/lizenz.md` (T93). Stand: 13.11.2026.

## In den ausgelieferten Paketen

| Bestandteil | Wo | Herkunft | Lizenz | Beleg |
|---|---|---|---|---|
| Schrift **Manrope** (Latin, Latin Extended, variable Stärke, WOFF2) | Theme: `assets/fonts/manrope-*.woff2` | The Manrope Project Authors (github.com/sharanda/manrope) | SIL Open Font License 1.1 | `themes/kurswasser/assets/fonts/OFL-Manrope.txt` (liegt im Theme-Paket bei) |
| **Bundesländer-Umrisse** Deutschland | Plugin: `assets/maps/de-states.json` | Natural Earth 1:10m Admin-1, umgerechnet mit `tools/maps/make_de_states.py` | gemeinfrei (Public Domain) | Feld `source` in der Datei, `bootspruefung-learning/readme.txt` |
| **Gerätesimulation** (Funkgeräte, JavaScript) | Plugin: `assets/device-sim/funkgeraet/` | unveränderte Kopie aus `hastla007/boatingschool@136a243`, Ordner `resources/js/funkgeraet` | **ungeklärt** (Quell-Repository ohne LICENSE-Datei, siehe `docs/lizenz.md`) | `assets/device-sim/SOURCE.txt`, `SHA256SUMS`, `tools/sync-device-sim.sh` |
| CSS der Gerätesimulation | Plugin: `assets/device-sim/device-sim.css` | erzeugt mit Tailwind CSS 3.4.17 aus den Klassen der Simulation | Werkzeug: MIT | `tools/device-sim/package.json`, `package-lock.json` |
| **Kursfotos** (8 Motive, WebP-Ableitungen, abgeleitete Bildplätze) | Theme: `assets/images/courses/` | KI-erzeugt mit OpenAI-Bildgenerierung (ChatGPT) | Nutzungsbedingungen von OpenAI (Europe Terms of Use); keine Exklusivität | `themes/kurswasser/assets/images/courses/IMAGE-RIGHTS.md` |
| Produktbilder (Shop) | Plugin: `assets/products/*.png` | erzeugt mit `tools/product-images/generate.py` (Pillow, Schrift DejaVu Sans) | Schrift DejaVu: freie Lizenz (Bitstream-Vera-Lizenz mit Ergänzungen) | `tools/product-images/generate.py` |
| Inhalte (Fragen, Medien, Prüfungsregeln, Verweise auf Videos) | Content-Paket (nicht im Plugin-ZIP; Import über den Admin) | Snapshot von `hastla007/boatingschool@136a243` | **ungeklärt**, Rechtefreigaben siehe `docs/offene-punkte.md` (Punkt 1) | `content-source/boatingschool/SOURCE.md` |

Nicht belegt im Repository: Herkunft des Hero-Fotos (`themes/kurswasser/assets/images/hero-boat.*`) und der Symbole
(`themes/kurswasser/assets/icons/*.svg`). Der Rechtenachweis für Fotos ist Betreiber-Schritt T74
(`docs/betreiber-schritte.md`).

Das Lern-Plugin und die Add-ons bündeln **keine** PHP- oder JavaScript-Bibliotheken von Dritten (kein `vendor/`,
kein `node_modules/` in den ZIP-Dateien; `tools/release/build-zips.sh` prüft das).

## Vorausgesetzt (wird separat installiert, nicht mitgeliefert)

| Software | Lizenz | Beleg |
|---|---|---|
| WordPress | GPL-2.0-or-later | wordpress.org |
| WooCommerce | GPL-3.0-or-later | wordpress.org |
| Yoast SEO (optional) | GPL-3.0-or-later | wordpress.org |
| Voucher Captain (nur mit Kursgutschein-Connector) | GPL-2.0-or-later | Kopf von `voucher-captain.php` und `LICENSE` im Repository `hastla007/wp-couponmaster` |
| WP Affiliate Captain (nur mit Partnerprogramm-Connector) | im Repository nicht belegt | – |

## Nur zur Entwicklung (nicht in den Paketen)

| Werkzeug | Lizenz | Beleg |
|---|---|---|
| PHPUnit 10 und Abhängigkeiten (sebastian/*, phar-io/*, theseer/tokenizer, nikic/php-parser, myclabs/deep-copy) | BSD-3-Clause bzw. MIT | `tools/content-build/composer.lock` |
| opis/json-schema, opis/string, opis/uri | Apache-2.0 | `tools/content-build/composer.lock` |
| Tailwind CSS 3.4.17 | MIT | `tools/device-sim/package-lock.json` |
| PHPStan, phpstan/extension-installer, szepeviktor/phpstan-wordpress, php-stubs/wordpress-stubs, php-stubs/woocommerce-stubs | MIT | `bootspruefung-learning/composer.lock` |
| PHP_CodeSniffer | BSD-3-Clause | `bootspruefung-learning/composer.lock` |
| WordPress Coding Standards, dealerdirect/phpcodesniffer-composer-installer | MIT | `bootspruefung-learning/composer.lock` |
| PHPCSUtils, PHPCSExtra | LGPL-3.0-or-later | `bootspruefung-learning/composer.lock` |
| Playwright 1.48.2 (Browser-Tests in der CI) | Apache-2.0 | `.github/workflows/ci.yml` |
| openapi-spec-validator (CI) | Apache-2.0 | `.github/workflows/ci.yml` |
