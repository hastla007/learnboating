#!/usr/bin/env bash
# Lern-Plugin als ZIP für den Einrichtungs-Assistenten (Design › Kurswasser einrichten):
#   themes/kurswasser/plugins/bootspruefung-learning.zip  (nicht im Git, siehe .gitignore)
# Nimmt den Stand eines Commits (Standard: HEAD) – nie ungespeicherte Dateien aus dem Arbeitsbaum – ohne Tests.
#   themes/kurswasser/tools/build-plugin-zip.sh [<commit>]
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
REV="${1:-HEAD}"
OUT=themes/kurswasser/plugins/bootspruefung-learning.zip
mkdir -p "$(dirname "$OUT")"
git archive --format=zip --prefix=bootspruefung-learning/ -o "$OUT" "$REV:bootspruefung-learning" -- . ':(exclude)tests'
echo "$OUT ($(du -h "$OUT" | cut -f1), Stand $(git rev-parse --short "$REV"))"
