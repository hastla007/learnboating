#!/usr/bin/env bash
# Übernimmt die content-relevanten Teile von hastla007/boatingschool unverändert
# (gleiche Pfade wie im Original) nach content-source/boatingschool/.
# Das Quellrepository wird dabei nur gelesen.
#
# Nutzung:
#   tools/sync-source.sh <commit|branch|tag> [vorhandener-checkout]
set -euo pipefail

REF="${1:?Commit, Branch oder Tag angeben}"
EXISTING="${2:-}"
REPO_URL="https://github.com/hastla007/boatingschool"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/content-source/boatingschool"

# Pfade relativ zum Quellrepo, die übernommen werden.
INCLUDE=(
  README.md
  database/data
  database/migrations
  database/seeders
  app/Models
  app/Support/FunkPraxis.php
  app/Support/FunkTraining.php
  app/Support/Geraetesimulation.php
  app/Support/NavigationAnswer.php
  app/Support/ComboExamGroups.php
  app/Support/ComboVideoGroups.php
  app/Support/VideoCourseLessons.php
  app/Console/Commands/ImportContentQuestions.php
  app/Console/Commands/ImportContentFeedback.php
  resources/js/funkgeraet.js
  resources/js/funkgeraet
  public/images/courses
)

# Reine Demo-, Mandanten- und Nutzer-Seeder werden nicht übernommen.
EXCLUDE=(
  database/seeders/TenantSeeder.php
  database/seeders/UserAndEntitlementSeeder.php
  database/seeders/DemoLearningActivitySeeder.php
  database/seeders/AddonDemoSeeder.php
  database/seeders/CoursePromoBannerDemoSeeder.php
  database/seeders/PraxisAnmeldungDemoSeeder.php
  database/seeders/TenantCourseLocationDemoSeeder.php
  database/seeders/WillkommensboxDemoSeeder.php
)

if [[ -n "$EXISTING" ]]; then
  SRC="$EXISTING"
  git -C "$SRC" checkout -q "$REF" 2>/dev/null || true
else
  SRC="$(mktemp -d)"
  trap 'rm -rf "$SRC"' EXIT
  git clone -q "$REPO_URL" "$SRC"
  git -C "$SRC" checkout -q "$REF"
fi

if [[ -n "$(git -C "$SRC" status --porcelain)" ]]; then
  echo "Quell-Checkout hat lokale Änderungen – Abbruch." >&2
  exit 1
fi

SHA="$(git -C "$SRC" rev-parse HEAD)"
DATE="$(git -C "$SRC" log -1 --format=%cI)"
BRANCH="$(git -C "$SRC" rev-parse --abbrev-ref HEAD)"

rm -rf "$DEST"
mkdir -p "$DEST"
( cd "$SRC" && tar -cf - "${INCLUDE[@]}" ) | ( cd "$DEST" && tar -xf - )
for f in "${EXCLUDE[@]}"; do rm -f "$DEST/$f"; done

( cd "$DEST" && find . -type f ! -name SHA256SUMS ! -name SOURCE.md -print0 \
    | sort -z | xargs -0 sha256sum | sed 's#  \./#  #' ) > "$DEST/SHA256SUMS"

FILES="$(wc -l < "$DEST/SHA256SUMS")"
SIZE="$(du -sh --exclude=SHA256SUMS "$DEST" | cut -f1)"

{
  echo "# Content-Snapshot hastla007/boatingschool"
  echo
  echo "Unveränderte Kopie der content-relevanten Teile des Quellrepositorys,"
  echo "Pfade wie im Original. Nicht von Hand bearbeiten – Aktualisierung nur über"
  echo "\`tools/sync-source.sh <commit>\`."
  echo
  echo "| | |"
  echo "|---|---|"
  echo "| Repository | $REPO_URL |"
  echo "| Branch/Ref | \`$BRANCH\` (angefragt: \`$REF\`) |"
  echo "| Commit | \`$SHA\` |"
  echo "| Commit-Datum | $DATE |"
  echo "| Dateien | $FILES |"
  echo "| Größe | $SIZE |"
  echo
  echo "## Übernommene Pfade"
  echo
  for p in "${INCLUDE[@]}"; do echo "- \`$p\`"; done
  echo
  echo "## Bewusst nicht übernommen"
  echo
  for p in "${EXCLUDE[@]}"; do echo "- \`$p\` (Demo-/Mandanten-/Nutzerdaten)"; done
  echo "- übrige Anwendung (Controller, Views, Konfiguration, Tests außerhalb von \`resources/js/funkgeraet/\`)"
  echo
  echo "## Hinweis zu Rechten"
  echo
  echo "Rechte- und Lizenzangaben sind in den Daten selbst hinterlegt (u. a. Blatt"
  echo "„Source Catalogs“ in \`database/data/zentrale_fragenbasis.xlsx\`, Quell-URLs in"
  echo "\`database/data/elwis_bilder.csv\`). Die rechtliche Prüfung erfolgt vor"
  echo "Veröffentlichung (siehe Konzept Kap. 4.10 und 13)."
  echo
  echo "Prüfsummen aller Dateien: \`SHA256SUMS\`."
} > "$DEST/SOURCE.md"

echo "Snapshot von $SHA nach $DEST übernommen ($FILES Dateien, $SIZE)."
