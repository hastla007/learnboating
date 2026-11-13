# Übersetzung (To-do T89)

Plugin, Theme und beide Add-ons sind übersetzbar. Ausgangssprache ist Deutsch (Deutschland). Eine zweite Fassung, z. B.
für Österreich (`de_AT`) oder die Schweiz (`de_CH`), braucht nur Übersetzungsdateien, keinen Code.

| Teil | Text-Domain | Vorlage |
|---|---|---|
| Plugin | `bootspruefung-learning` | `bootspruefung-learning/languages/bootspruefung-learning.pot` |
| Theme | `kurswasser` | `themes/kurswasser/languages/kurswasser.pot` |
| Partnerprogramm-Connector | `learnboating-affiliate-connector` | `learnboating-affiliate-connector/languages/…pot` |
| Kursgutschein-Connector | `learnboating-voucher-connector` | `learnboating-voucher-connector/languages/…pot` |

## Was umgesetzt ist

- **Feste Text-Domain:** Jeder Übersetzungsaufruf nennt die Domain als festes Wort (`__('…', 'bootspruefung-learning')`).
  Vorher stand im Plugin bei rund 1.000 Aufrufen eine Variable `$d`; `wp i18n make-pot` hat diese Texte nicht gefunden.
  `php tools/i18n/check-domains.php` prüft das für alle vier Teile und ob die Vorlagen da sind.
- **Laden:** Plugin (`Plugin.php`), Theme (`functions.php`) und Add-ons laden ihre Domain aus dem eigenen Ordner
  `languages/`. Übersetzungen unter `wp-content/languages/plugins/` bzw. `…/themes/` haben Vorrang (WordPress-Standard).
- **Lernbereich (JavaScript):** Alle Texte kommen aus `src/Frontend/Strings.php` (übersetzbar) über `bpLearning.i18n`.
  Neu dazu: die Bildschirmleser-Texte der Drehknöpfe im Funkgeräte-Simulator (`sim_aria_*`), die vorher fest im
  Skript `assets/build/device-sim-a11y.js` standen. Der Simulator selbst ist eine unveränderte Kopie aus dem
  Quell-Repository und bleibt deutsch.
- **Block „Nur mit Lernzugang“:** nutzt `wp.i18n`; das Plugin meldet die Übersetzungen mit
  `wp_set_script_translations` an.

### Ergänzt mit T128

- **Produkttexte des Shop-Katalogs** (`Commerce/ShopCatalog.php`, `texts()`): Kurztext, Langtext, Garantie-Absatz,
  Video-Upgrade und Simulator-Zugänge laufen über `__()` mit Platzhaltern (`%1$d`, `%2$s` …) und Hinweisen für
  Übersetzer. Auf Deutsch sind die Texte Wort für Wort gleich geblieben.
  Die Produktnamen (z. B. „… – Komplett mit Videokurs“) sind Katalogdaten und werden nicht übersetzt.
- **Beträge** in Produkttexten und in der Meldung des Katalog-Abgleichs („von Hand geändert …“) kommen aus
  `ShopCatalog::priceText()`: mit WooCommerce im Format des Shops (WooCommerce › Einstellungen › Allgemein ›
  Währungsoptionen: Währungszeichen, Position, Trennzeichen, Nachkommastellen), ohne WooCommerce als Zahl nach
  Sprache der Website mit „€“ (Text `%s €`, übersetzbar). Mit dem deutschen Shop-Format („11,90 €“, das die
  Theme-Einrichtung „Shop einrichten“ setzt) bleibt alles wie bisher; geschützte Leerzeichen aus WooCommerce
  werden zu normalen, damit der Abgleich die Produkte nicht unnötig neu schreibt. Die Preise selbst ändern sich nicht.
- **Sprache der Produkttexte im Shop:** Der Katalog-Abgleich schreibt die Texte in der Sprache, die beim Abgleich
  aktiv ist (Website-Sprache). Nach einem Sprachwechsel den Abgleich erneut ausführen (Bootsprüfung › Preise ›
  „Speichern und Katalog abgleichen“ bzw. `wp bootspruefung shop setup`); von Hand geänderte Produkttexte bleiben
  dabei wie immer unangetastet.
  Die Kurzfassung der Angebotskarten im Theme (`kurswasser_offer_item()`) kürzt nur deutsche Texte; in anderen
  Sprachen zeigen die Karten die vollständigen Punkte.
- **Simulator-Vorteile** („Ein Login für alle Geräte …“): `ShopCatalog::simBenefits()` ist übersetzbar; derselbe Text
  erscheint im Lernbereich (`sim_benefits` in `Frontend/Strings.php`).
- **Datumsangaben im Lernbereich** (`assets/build/app.js`: Verlaufskurve der Prüfungsergebnisse, Ablauf des Zugangs,
  Laufzeit der Gruppen, Freischalttermine, Verlauf der Prüfungen): Format nach der Sprache der Website statt fest „de-DE“. Das Plugin gibt sie als `bpLearning.locale`
  mit (`determine_locale()`, „de_AT“ → „de-AT“, `Frontend/Router.php`); fehlt sie, gilt `<html lang>`, sonst „de-DE“.

## Vorlagen aktualisieren

Nach Textänderungen und vor jedem Release:

```bash
bash tools/i18n/make-pot.sh
```

Mit `wp-cli.phar` statt `wp` im Pfad: `WP="php /pfad/wp-cli.phar" bash tools/i18n/make-pot.sh`.

## Übersetzung anlegen

1. `.pot` in Poedit (oder einem anderen Werkzeug) als neue Übersetzung öffnen, Sprache wählen, z. B. `de_AT`.
2. Speichern als `<domain>-de_AT.po` und `.mo` im Ordner `languages/` des Teils.
3. Für den Block: `wp i18n make-json bootspruefung-learning/languages --no-purge`.
4. Website-Sprache unter Einstellungen › Allgemein auf die Sprache stellen und Lernbereich, Kasse und Mails ansehen.

Rechtstexte und Inhaltsseiten des Themes sind Inhalte, keine Übersetzungstexte: Sie werden je Land als eigene Fassung
gepflegt (siehe `docs/barrierefreiheit-oesterreich.md` und `themes/kurswasser/content/`).
