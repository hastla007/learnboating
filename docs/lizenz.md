# Lizenz – Fakten und offene Entscheidung (To-do T93)

**Die Lizenz legt der Betreiber fest, am besten nach Rücksprache mit dem Anwalt.** Diese Seite trägt nur zusammen,
was im Repository steht, und listet die Dateien, die nach der Entscheidung angepasst werden müssen. Sie ist keine
Rechtsberatung und trifft keine Wahl. Bis zur Entscheidung bleiben alle Angaben, wie sie sind.

Stand: 13.11.2026.

## Was heute in den Dateien steht

| Paket | Angabe | Fundstelle |
|---|---|---|
| Lern-Plugin `bootspruefung-learning` | **keine** `License:`-Zeile im Plugin-Kopf; Autor „hastla007“ | `bootspruefung-learning/bootspruefung-learning.php` |
| Lern-Plugin | `"license": "proprietary"` | `bootspruefung-learning/composer.json` |
| Lern-Plugin | `readme.txt` ohne `License:`-Kopfzeile; Abschnitt „Lizenz“ verweist auf diese Seite (T95); keine LICENSE-Datei | `bootspruefung-learning/readme.txt` |
| Theme `kurswasser` | `License: GNU General Public License v2 or later` (GPL-2.0-or-later) | `themes/kurswasser/style.css` |
| Partnerprogramm-Connector | `License: proprietary` | `learnboating-affiliate-connector/learnboating-affiliate-connector.php` |
| Kursgutschein-Connector | `License: proprietary` | `learnboating-voucher-connector/learnboating-voucher-connector.php` |
| Content-Konverter (Werkzeug, wird nicht ausgeliefert) | `"license": "proprietary"` | `tools/content-build/composer.json` |
| Repository | keine LICENSE-Datei im Hauptordner | – |

### Theme und Plugin hängen zusammen

- Das Theme ruft Funktionen und Klassen des Lern-Plugins auf, wenn es aktiv und kompatibel ist (z. B.
  `\Bootspruefung\Learning\Commerce\WooCommerce\WithdrawalFunction::url()` in `themes/kurswasser/functions.php`,
  `kurswasser_learning_compatible()` in `themes/kurswasser/inc/learning.php`). Ohne Plugin arbeitet es eingeschränkt
  weiter (Versionsvertrag).
- Das Theme-Paket der Auslieferung **enthält** das Lern-Plugin als ZIP-Datei (`plugins/bootspruefung-learning.zip`,
  `tools/release/build-zips.sh`), damit der Einrichtungs-Assistent es installieren kann (`themes/kurswasser/inc/setup.php`).
- Das Theme steht unter GPL-2.0-or-later, das mitgelieferte Plugin hat keine bzw. eine „proprietäre“ Angabe. Ob und
  wie das zusammenpasst (gemeinsames Paket, Weitergabe an Dritte, nur eigener Betrieb), ist eine Frage für den Anwalt.
- Plugin und Add-ons laufen in WordPress (GPL-2.0-or-later) und nutzen WooCommerce (GPL-3.0-or-later). Voucher
  Captain steht laut seinem Plugin-Kopf unter GPL-2.0-or-later (Repository `hastla007/wp-couponmaster`, Datei
  `voucher-captain.php`, LICENSE: GPL v2). Für WP Affiliate Captain liegt in diesem Repository keine Lizenzangabe vor.

### Herkunft der Gerätesimulation (Simulator-JavaScript)

- `bootspruefung-learning/assets/device-sim/funkgeraet/` ist eine **unveränderte Kopie** aus dem Repository
  `hastla007/boatingschool`, Commit `136a243af9268aeaa8d0184347820617ec95f76b`, Ordner `resources/js/funkgeraet`
  ohne Tests. Belege: `bootspruefung-learning/assets/device-sim/SOURCE.txt`, Prüfsummen in
  `bootspruefung-learning/assets/device-sim/SHA256SUMS`, Übernahme mit `tools/sync-device-sim.sh`; die CI prüft, dass
  die Kopie dem Snapshot entspricht (Schritt „Gerätesimulation entspricht dem Snapshot“).
- Derselbe Commit liefert den Content-Snapshot `content-source/boatingschool/` (Fragen, Medien, Regelwerke;
  `content-source/boatingschool/SOURCE.md`).
- Im Quell-Repository gibt es (Stand 13.11.2026, Hauptzweig) **keine LICENSE-Datei**. Seine `composer.json` nennt
  `"license": "MIT"`, trägt aber noch den Namen `laravel/laravel` der Laravel-Vorlage – die Angabe stammt also
  vermutlich aus der Vorlage und ist keine bewusste Lizenz für die Simulation. Das Quell-Repository liegt unter
  demselben GitHub-Konto (`hastla007`) wie dieses Repository (Theme-Kopf `Theme URI`).
- `device-sim.css` wird aus den Klassen der Simulation mit Tailwind CSS 3.4.17 (MIT) erzeugt
  (`tools/device-sim/`). `assets/build/device-sim-a11y.js` ist eigener Code dieses Repositorys.
- Zu klären: Wer hat die Rechte an Simulation und Inhalten des Quell-Repositorys, und unter welcher Lizenz dürfen sie
  im Plugin (und ggf. an Dritte) weitergegeben werden? Zum Umzug zurück zum Laravel-Original siehe T61 in
  `docs/betreiber-schritte.md`.

### Fremdbestandteile in den Paketen

Vollständige Liste mit Lizenzen: `CREDITS.md`. Kurz: Schrift Manrope (SIL Open Font License 1.1, Lizenztext liegt
bei), Bundesländer-Karte aus Natural Earth (gemeinfrei), KI-erzeugte Kursfotos (Nachweis
`themes/kurswasser/assets/images/courses/IMAGE-RIGHTS.md`). Das Lern-Plugin bündelt **keine** Composer- oder
npm-Bibliotheken (kein `vendor/` in der Auslieferung). Für das Hero-Foto (`themes/kurswasser/assets/images/hero-boat.*`)
und die Symbole (`themes/kurswasser/assets/icons/`) ist die Herkunft im Repository nicht belegt; der Rechtenachweis
für Fotos ist Betreiber-Schritt T74 (`docs/betreiber-schritte.md`).

## Fragen an den Betreiber bzw. Anwalt

1. Welche Lizenz bekommen Lern-Plugin und Add-ons (z. B. proprietär/alle Rechte vorbehalten, GPL-2.0-or-later, …)?
2. Bleibt das Theme GPL-2.0-or-later? Passt das zur Mitlieferung des Plugins im Theme-Paket – oder soll das Theme-Paket
   das Plugin nicht mehr enthalten (dann Installation nur noch über die eigene Plugin-ZIP)?
3. Rechte und Lizenz an Gerätesimulation und Inhalten aus `hastla007/boatingschool` (siehe oben).
4. Werden die Pakete nur auf der eigenen Website betrieben oder auch an Dritte weitergegeben (z. B. Partner,
   Bootsschulen)? Das beeinflusst, welche Pflichten aus GPL und OFL greifen.
5. Wer steht als Autor in den Kopfzeilen? Heute: Plugin „hastla007“, Theme und Add-ons „LearnBoating“ (Projektname
   als Platzhalter, T92).

## Nach der Entscheidung anzupassen

| Datei | Änderung |
|---|---|
| `bootspruefung-learning/bootspruefung-learning.php` | Kopfzeilen `License:` und `License URI:` ergänzen, ggf. `Author:`/`Author URI:` |
| `bootspruefung-learning/readme.txt` | Kopfzeilen `License:`/`License URI:`, Abschnitt „Lizenz“ (heute: „vom Betreiber festzulegen“) |
| `bootspruefung-learning/composer.json`, `tools/content-build/composer.json` | Feld `license` (SPDX-Kennung, z. B. `GPL-2.0-or-later` oder `proprietary`) |
| `themes/kurswasser/style.css` | `License:`/`License URI:` nur ändern, wenn das Theme nicht GPL bleiben soll; `Author:` |
| `learnboating-affiliate-connector/learnboating-affiliate-connector.php`, `learnboating-voucher-connector/learnboating-voucher-connector.php` | `License:`, ggf. `Author:` |
| neu: `LICENSE` (Hauptordner) und je Paket (`bootspruefung-learning/LICENSE`, `themes/kurswasser/LICENSE`, Add-ons) | Lizenztext; damit er in den ZIPs landet, liegt er im jeweiligen Paketordner |
| `CREDITS.md` | prüfen, ob Hinweise in die Pakete gehören (z. B. als `CREDITS.md` je Paket) |
| `tools/release/build-zips.sh` | nur falls Dateien ausgeschlossen bzw. zusätzlich aufgenommen werden sollen |
| diese Seite | Entscheidung mit Datum eintragen |
