#!/usr/bin/env bash
# Build WordPress-installable leadcrm-wa-build.zip (folder leadcrm-wa-build/ at zip root).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$ROOT/.." && pwd)"
OUT="${1:-$REPO_ROOT/leadcrm-wa-build.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a "$ROOT/" "$STAGE/leadcrm-wa-build/" \
  --exclude 'leadcrm-wa-build/' \
  --exclude 'scripts/' \
  --exclude 'tools/' \
  --exclude '.git/' \
  --exclude '*.pyc' \
  --exclude '__pycache__/'

(
  cd "$STAGE"
  rm -f "$OUT"
  zip -rq "$OUT" leadcrm-wa-build
)

echo "Wrote $OUT ($(du -h "$OUT" | cut -f1))"
