#!/usr/bin/env bash
# shellcheck disable=SC1111  # deutsche Anführungszeichen („…“) in Meldungen sind gewollt
# Baut die Auslieferungs-ZIPs für Lern-Plugin, Theme und beide Add-ons aus dem Stand eines Commits
# (nie aus ungespeicherten Dateien) – lokal und in der CI dasselbe Skript.
#
#   tools/release/build-zips.sh [--rev <commit>] [--out <ordner>] [--tag <vX.Y.Z>]
#
#   --rev  Commit, Zweig oder Tag (Standard: HEAD)
#   --out  Zielordner (Standard: build/release; wird geleert)
#   --tag  prüft, dass der Tag zur Plugin-Version passt (Tag „v0.14.0“ ⇔ Plugin 0.14.0)
#
# Ergebnis im Zielordner:
#   bootspruefung-learning-<version>.zip            Ordner im ZIP: bootspruefung-learning/
#   kurswasser-<version>.zip                        Ordner im ZIP: kurswasser/ (mit plugins/bootspruefung-learning.zip)
#   learnboating-affiliate-connector-<version>.zip  Ordner im ZIP: learnboating-affiliate-connector/
#   learnboating-voucher-connector-<version>.zip    Ordner im ZIP: learnboating-voucher-connector/
#   SHA256SUMS, versions.env (für die CI), release-notes.md (Text des GitHub-Releases)
#
# Nicht im ZIP: tests/, tools/, node_modules/, vendor/, .git*, Composer-, PHPStan-, PHPCS- und npm-Dateien.
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
    -h|--help) sed -n '3,21p' "$0"; exit 0 ;;
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
      -o -name package.json -o -name package-lock.json -o -name .editorconfig -o -name '.DS_Store' \) \
      -prune -exec rm -rf {} +
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

PLUGIN_ZIP="$OUT/bootspruefung-learning-$PLUGIN_V.zip"
THEME_ZIP="$OUT/kurswasser-$THEME_V.zip"
AFF_ZIP="$OUT/learnboating-affiliate-connector-$AFF_V.zip"
VOU_ZIP="$OUT/learnboating-voucher-connector-$VOU_V.zip"

stage bootspruefung-learning bootspruefung-learning
pack bootspruefung-learning "$PLUGIN_ZIP"

stage themes/kurswasser kurswasser
# Das Theme liefert das Lern-Plugin für „Kurswasser einrichten“ mit (inc/setup.php, plugins/bootspruefung-learning.zip).
mkdir -p "$STAGE/kurswasser/plugins"
cp "$PLUGIN_ZIP" "$STAGE/kurswasser/plugins/bootspruefung-learning.zip"
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
}
check_zip "$PLUGIN_ZIP" bootspruefung-learning bootspruefung-learning.php
check_zip "$THEME_ZIP" kurswasser style.css
unzip -Z1 "$THEME_ZIP" | grep -qx 'kurswasser/plugins/bootspruefung-learning.zip' || fail "Theme-ZIP ohne mitgeliefertes Lern-Plugin."
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
  echo "| Theme „Kurswasser“ (mit Lern-Plugin für den Einrichtungs-Assistenten) | $THEME_V | \`kurswasser/\` |"
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
