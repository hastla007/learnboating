#!/usr/bin/env bash
# shellcheck disable=SC1111  # deutsche Anführungszeichen („…“) in Meldungen sind gewollt
# Baut die Auslieferungs-ZIPs für Lern-Plugin, Theme und beide Add-ons aus dem Stand eines Commits
# (nie aus ungespeicherten Dateien) – lokal und in der CI dasselbe Skript.
#
#   tools/release/build-zips.sh [--rev <commit>] [--out <ordner>] [--tag <vX.Y.Z>]
#
#   --rev  Commit, Zweig oder Tag (Standard: HEAD)
#   --out  Zielordner (Standard: build/release; wird geleert)
#   --tag  prüft, dass der Tag zur Plugin-Version passt (Tag „v0.14.0“ ⇔ Plugin 0.14.0) und dass Theme und Add-ons
#          eine neue Version haben, wenn sich ihr Ordner seit dem vorigen Tag geändert hat (ohne vorigen Tag: übersprungen)
#
# Ergebnis im Zielordner:
#   bootspruefung-learning-<version>.zip            Ordner im ZIP: bootspruefung-learning/
#   kurswasser-<version>.zip                        Ordner im ZIP: kurswasser/ (ohne Lern-Plugin, ohne content/src)
#   learnboating-affiliate-connector-<version>.zip  Ordner im ZIP: learnboating-affiliate-connector/
#   learnboating-voucher-connector-<version>.zip    Ordner im ZIP: learnboating-voucher-connector/
#   SHA256SUMS, versions.env (für die CI), release-notes.md (Text des GitHub-Releases)
#
# Nicht im ZIP: tests/, tools/, node_modules/, vendor/, .git*, Composer-, PHPStan-, PHPCS- und npm-Dateien, die
# Markdown-Quellen der Inhaltsseiten (content/src, mit internen Redaktionsnotizen in _moderation/) und ZIP-Dateien
# in einem Ordner plugins/ (T106: wären über die Adresse des Themes öffentlich abrufbar).
# Das Lern-Plugin-ZIP bekommt eine Kopie von CREDITS.md (Fremdbestandteile und ihre Lizenzen).
# Das Plugin braucht keine Composer-Abhängigkeiten (eigener Autoloader, siehe bootspruefung-learning.php).
# Gleicher Commit ergibt gleiche ZIP-Dateien (Dateizeit = Commit-Zeit, feste Reihenfolge).
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
REV=HEAD
OUT="$ROOT/build/release"
TAG=""
while [ $# -gt 0 ]; do
  case "$1" in
    --rev) REV="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    --tag) TAG="$2"; shift 2 ;;
    -h|--help) sed -n '3,25p' "$0"; exit 0 ;;
    *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
  esac
done

SHA="$(git rev-parse --verify "$REV^{commit}")"
COMMIT_TIME="$(git show -s --format=%ct "$SHA")"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
rm -f "$OUT"/*.zip "$OUT/SHA256SUMS" "$OUT/versions.env" "$OUT/release-notes.md"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

fail() { echo "FEHLER: $*" >&2; exit 1; }

# Kopfzeile „Version:“ einer Datei im Commit lesen (Plugin-Kopf bzw. style.css).
header_version() {
  git show "$SHA:$1" | sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([0-9][0-9A-Za-z.+-]*\).*$/\1/p' | head -1
}

# Ordner aus dem Commit auspacken und Entwicklerdateien entfernen.
stage() { # <quellordner im Repo> <ordnername im ZIP>
  local src="$1" dir="$2"
  git archive --format=tar --prefix="$dir/" "$SHA:$src" | tar -x -C "$STAGE"
  find "$STAGE/$dir" \( -name tests -o -name tools -o -name node_modules -o -name vendor -o -name '.git*' \
      -o -name composer.json -o -name composer.lock -o -name 'phpstan*' -o -name 'phpcs*' -o -name '.phpcs*' \
      -o -name package.json -o -name package-lock.json -o -name .editorconfig -o -name '.DS_Store' -o -name _moderation \) \
      -prune -exec rm -rf {} +
  # Quellen der Inhaltsseiten (zur Laufzeit wird nur content/pages.php und content/pages/ gelesen).
  rm -rf "$STAGE/$dir/content/src"
}

# Feste Reihenfolge, Dateizeit = Commit-Zeit (git archive eines Ordners nähme die aktuelle Zeit), keine Zusatzfelder.
pack() { # <ordnername> <zieldatei>
  find "$STAGE/$1" -exec touch -h -d "@$COMMIT_TIME" {} +
  ( cd "$STAGE" && find "$1" -print | LC_ALL=C sort | TZ=UTC zip -q -X -D -@ "$2" )
}

PLUGIN_V="$(header_version bootspruefung-learning/bootspruefung-learning.php)"
THEME_V="$(header_version themes/kurswasser/style.css)"
AFF_V="$(header_version learnboating-affiliate-connector/learnboating-affiliate-connector.php)"
VOU_V="$(header_version learnboating-voucher-connector/learnboating-voucher-connector.php)"
[ -n "$PLUGIN_V" ] && [ -n "$THEME_V" ] && [ -n "$AFF_V" ] && [ -n "$VOU_V" ] || fail "Version im Dateikopf nicht gefunden."

# Versionen müssen überall gleich sein (Kopf = Konstante; Plugin auch readme.txt und Änderungsprotokoll).
const_in() { git show "$SHA:$1" | grep -qE "$2" || fail "$1: $3"; }
const_in bootspruefung-learning/src/Plugin.php "VERSION = '${PLUGIN_V//./\\.}';" "Plugin::VERSION ≠ Kopf ($PLUGIN_V)"
const_in bootspruefung-learning/readme.txt "^Stable tag: ${PLUGIN_V//./\\.}\$" "Stable tag ≠ Plugin-Version ($PLUGIN_V)"
const_in bootspruefung-learning/CHANGELOG.md "^## ${PLUGIN_V//./\\.} " "oberster Eintrag fehlt für $PLUGIN_V"
const_in themes/kurswasser/functions.php "KURSWASSER_VERSION = '${THEME_V//./\\.}';" "KURSWASSER_VERSION ≠ style.css ($THEME_V)"
const_in learnboating-affiliate-connector/learnboating-affiliate-connector.php "'LBAC_VERSION', '${AFF_V//./\\.}'" "LBAC_VERSION ≠ Kopf ($AFF_V)"
const_in learnboating-voucher-connector/learnboating-voucher-connector.php "'LBVC_VERSION', '${VOU_V//./\\.}'" "LBVC_VERSION ≠ Kopf ($VOU_V)"
if [ -n "$TAG" ] && [ "$TAG" != "v$PLUGIN_V" ]; then
  fail "Tag $TAG passt nicht zur Plugin-Version $PLUGIN_V (erwartet v$PLUGIN_V, siehe docs/installation-und-updates.md)."
fi

# Mit --tag: Hat sich ein Paket seit dem vorigen Release-Tag geändert, muss auch seine Version neu sein
# (sonst bekämen Betreiber eine geänderte Datei unter alter Versionsnummer). Ohne vorigen Tag: übersprungen.
if [ -n "$TAG" ]; then
  PREV_TAG="$(git describe --tags --abbrev=0 --match 'v[0-9]*' "$SHA^" 2>/dev/null || true)"
  if [ -z "$PREV_TAG" ]; then
    echo "Hinweis: kein voriger Release-Tag – Versionsprüfung von Theme und Add-ons übersprungen." >&2
  else
    prev_version() { git show "$PREV_TAG:$1" 2>/dev/null | sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([0-9][0-9A-Za-z.+-]*\).*$/\1/p' | head -1; }
    check_bumped() { # <ordner> <kopfdatei> <aktuelle version> <name>
      if ! git diff --quiet "$PREV_TAG" "$SHA" -- "$1" && [ "$(prev_version "$2")" = "$3" ]; then
        fail "$4 hat sich seit $PREV_TAG geändert ($1), die Version ist aber gleich geblieben ($3). Version erhöhen (docs/release-regel.md)."
      fi
    }
    check_bumped bootspruefung-learning bootspruefung-learning/bootspruefung-learning.php "$PLUGIN_V" "Lern-Plugin"
    check_bumped themes/kurswasser themes/kurswasser/style.css "$THEME_V" "Theme Kurswasser"
    check_bumped learnboating-affiliate-connector learnboating-affiliate-connector/learnboating-affiliate-connector.php "$AFF_V" "Partnerprogramm-Connector"
    check_bumped learnboating-voucher-connector learnboating-voucher-connector/learnboating-voucher-connector.php "$VOU_V" "Kursgutschein-Connector"
  fi
fi

PLUGIN_ZIP="$OUT/bootspruefung-learning-$PLUGIN_V.zip"
THEME_ZIP="$OUT/kurswasser-$THEME_V.zip"
AFF_ZIP="$OUT/learnboating-affiliate-connector-$AFF_V.zip"
VOU_ZIP="$OUT/learnboating-voucher-connector-$VOU_V.zip"

stage bootspruefung-learning bootspruefung-learning
# Fremdbestandteile und ihre Lizenzen (readme.txt verweist darauf): Kopie aus dem Repo-Stamm.
git show "$SHA:CREDITS.md" > "$STAGE/bootspruefung-learning/CREDITS.md"
pack bootspruefung-learning "$PLUGIN_ZIP"

# Das Theme enthält das Lern-Plugin nicht (T106): Der Einrichtungs-Assistent erkennt ein installiertes Plugin,
# nimmt KURSWASSER_LEARNING_PLUGIN_ZIP oder bietet „Plugin-ZIP hochladen“ an.
stage themes/kurswasser kurswasser
rm -rf "$STAGE/kurswasser/plugins"
pack kurswasser "$THEME_ZIP"

stage learnboating-affiliate-connector learnboating-affiliate-connector
pack learnboating-affiliate-connector "$AFF_ZIP"
stage learnboating-voucher-connector learnboating-voucher-connector
pack learnboating-voucher-connector "$VOU_ZIP"

# Selbstprüfung: genau ein Ordner je ZIP, Hauptdatei vorhanden, keine Entwicklerdateien.
check_zip() { # <zip> <ordner> <hauptdatei>
  local list
  list="$(unzip -Z1 "$1")"
  [ "$(printf '%s\n' "$list" | cut -d/ -f1 | sort -u)" = "$2" ] || fail "$(basename "$1"): Ordner im ZIP ist nicht nur „$2/“."
  printf '%s\n' "$list" | grep -qx "$2/$3" || fail "$(basename "$1"): $2/$3 fehlt."
  if printf '%s\n' "$list" | grep -E "(^|/)(tests|tools|node_modules|vendor)/|(^|/)\.git|(^|/)(composer\.(json|lock)|phpstan[^/]*|phpcs[^/]*|package(-lock)?\.json)$"; then
    fail "$(basename "$1"): Entwicklerdateien im ZIP (siehe oben)."
  fi
  # Verboten (T106): interne Quellen/Notizen und eingebettete Pakete, die per URL abrufbar wären.
  if printf '%s\n' "$list" | grep -iE "(^|/)content/src/|(^|/)_moderation/|\.zip$"; then
    fail "$(basename "$1"): verbotene Dateien im ZIP (content/src, _moderation oder ZIP-Datei, siehe oben)."
  fi
}
check_zip "$PLUGIN_ZIP" bootspruefung-learning bootspruefung-learning.php
unzip -Z1 "$PLUGIN_ZIP" | grep -qx 'bootspruefung-learning/CREDITS.md' || fail "Plugin-ZIP ohne CREDITS.md."
check_zip "$THEME_ZIP" kurswasser style.css
unzip -Z1 "$THEME_ZIP" | grep -q '^kurswasser/content/pages\.php$' || fail "Theme-ZIP ohne content/pages.php (Inhaltsseiten)."
check_zip "$AFF_ZIP" learnboating-affiliate-connector learnboating-affiliate-connector.php
check_zip "$VOU_ZIP" learnboating-voucher-connector learnboating-voucher-connector.php

( cd "$OUT" && sha256sum -- *.zip > SHA256SUMS )
cat > "$OUT/versions.env" <<EOF
PLUGIN_VERSION=$PLUGIN_V
THEME_VERSION=$THEME_V
AFFILIATE_CONNECTOR_VERSION=$AFF_V
VOUCHER_CONNECTOR_VERSION=$VOU_V
COMMIT=$SHA
EOF

# Oberster Eintrag eines Änderungsprotokolls (ohne Überschrift).
latest_changes() {
  git show "$SHA:$1" 2>/dev/null | awk '/^## /{n++; if(n==1){print "**" substr($0,4) "**"; next}} n==1' || true
}
{
  echo "Auslieferung aus Commit \`$SHA\`. Einspielen und Reihenfolge: \`docs/installation-und-updates.md\`."
  echo
  echo "| Paket | Version | Ordner im ZIP |"
  echo "|---|---|---|"
  echo "| Lern-Plugin „Bootsprüfung Lernplattform“ | $PLUGIN_V | \`bootspruefung-learning/\` |"
  echo "| Theme „Kurswasser“ (ohne Lern-Plugin; das Plugin-ZIP separat hochladen) | $THEME_V | \`kurswasser/\` |"
  echo "| Add-on Partnerprogramm-Connector | $AFF_V | \`learnboating-affiliate-connector/\` |"
  echo "| Add-on Kursgutschein-Connector | $VOU_V | \`learnboating-voucher-connector/\` |"
  echo
  echo "Prüfsummen: \`SHA256SUMS\` (\`sha256sum -c SHA256SUMS\`)."
  echo
  echo "## Lern-Plugin"; echo; latest_changes bootspruefung-learning/CHANGELOG.md; echo
  echo "## Theme"; echo; latest_changes themes/kurswasser/CHANGELOG.md; echo
  echo "## Partnerprogramm-Connector"; echo; latest_changes learnboating-affiliate-connector/CHANGELOG.md; echo
  echo "## Kursgutschein-Connector"; echo; latest_changes learnboating-voucher-connector/CHANGELOG.md
} > "$OUT/release-notes.md"

echo "Stand $(git rev-parse --short "$SHA") → $OUT"
( cd "$OUT" && for f in *.zip; do printf '  %-50s %6s  %5s Dateien\n' "$f" "$(du -h "$f" | cut -f1)" "$(unzip -Z1 "$f" | grep -cv '/$')"; done )
