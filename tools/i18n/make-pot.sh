#!/usr/bin/env bash
# T89: Übersetzungsvorlagen (.pot) für Plugin, Theme und Add-ons neu erzeugen.
# Braucht WP-CLI mit dem Befehl „i18n“ (in wp-cli.phar enthalten). Aufruf aus dem Repository:
#   bash tools/i18n/make-pot.sh            (WP-CLI als „wp“ im PATH)
#   WP="php /pfad/wp-cli.phar" bash tools/i18n/make-pot.sh
# Übersetzer erstellen daraus <domain>-de_AT.po/.mo (z. B. mit Poedit) im jeweiligen Ordner languages/ oder unter
# wp-content/languages/plugins/ bzw. wp-content/languages/themes/. JavaScript-Übersetzungen des Blocks
# „Nur mit Lernzugang“: danach `wp i18n make-json bootspruefung-learning/languages --no-purge`.
set -euo pipefail
cd "$(dirname "$0")/../.."
WP="${WP:-wp}"
H='{"Report-Msgid-Bugs-To":"","Language-Team":"","Last-Translator":""}'
$WP i18n make-pot bootspruefung-learning bootspruefung-learning/languages/bootspruefung-learning.pot \
  --domain=bootspruefung-learning --exclude=tests,assets/device-sim,vendor,node_modules --skip-audit --headers="$H"
# --skip-theme-json: sonst lädt WP-CLI die Übersetzungsliste für theme.json aus dem Internet.
$WP i18n make-pot themes/kurswasser themes/kurswasser/languages/kurswasser.pot \
  --domain=kurswasser --exclude=tests,tools,node_modules,content/src --skip-audit --skip-theme-json --headers="$H"
for addon in learnboating-affiliate-connector learnboating-voucher-connector; do
  $WP i18n make-pot "$addon" "$addon/languages/$addon.pot" --domain="$addon" --exclude=tests --skip-audit --headers="$H"
done
php tools/i18n/check-domains.php
