#!/usr/bin/env bash
# Übernimmt die Gerätesimulation (resources/js/funkgeraet) unverändert aus dem
# Content-Snapshot ins Plugin und baut das zugehörige CSS.
#   tools/sync-device-sim.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/content-source/boatingschool/resources/js/funkgeraet"
DEST="$ROOT/bootspruefung-learning/assets/device-sim/funkgeraet"

rm -rf "$DEST"
mkdir -p "$DEST"
( cd "$SRC" && find . -type f -name '*.js' ! -name '*.test.js' ! -path './tests/*' -print0 | tar --null -cf - -T - ) | ( cd "$DEST" && tar -xf - )
( cd "$DEST" && find . -type f -print0 | sort -z | xargs -0 sha256sum ) > "$DEST/../SHA256SUMS"
SHA=$(sed -n 's/^| Commit | `\([0-9a-f]\{40\}\)` |$/\1/p' "$ROOT/content-source/boatingschool/SOURCE.md")
printf 'Unverändert übernommen aus hastla007/boatingschool@%s (resources/js/funkgeraet, ohne Tests).\nAktualisierung: tools/sync-device-sim.sh\n' "$SHA" > "$DEST/../SOURCE.txt"

cd "$ROOT/tools/device-sim"
[ -d node_modules ] || npm ci --no-audit --no-fund --silent
npm run --silent build
echo "Gerätesimulation übernommen: $(find "$DEST" -name '*.js' | wc -l) Dateien, CSS $(wc -c < "$ROOT/bootspruefung-learning/assets/device-sim/device-sim.css") Bytes"
