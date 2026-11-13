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
