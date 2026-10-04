#!/usr/bin/env bash
# Build frontend assets and create WordPress-ready full-SOURCE zips at repo root.
# Includes all PHP/TS/docs/tests + prebuilt assets/. Never includes node_modules.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO="$(cd "$ROOT/.." && pwd)"
STAGE="$REPO/.pack-staging/services2nri"
cd "$ROOT"
npm run build
bash scripts/verify-design-system.sh

VERSION="$(grep -oP "define\s*\(\s*'S2NRI_VERSION',\s*'\K[0-9.]+" "$ROOT/services2nri.php" | head -1)"
COMMIT="$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo unknown)"
BUILT_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

rm -rf "$STAGE"
mkdir -p "$STAGE"
tar -C "$ROOT" \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='*.map' \
  --exclude='playwright-report' \
  --exclude='test-results' \
  --exclude='.cursor' \
  -cf - . | tar -C "$STAGE" -xf -

FILE_COUNT="$(find "$STAGE" -type f | wc -l | tr -d ' ')"
cat > "$STAGE/RELEASE-MANIFEST.json" <<EOF
{
  "plugin": "Services2NRI",
  "version": "$VERSION",
  "package": "full-source",
  "git_commit": "$COMMIT",
  "built_at_utc": "$BUILT_AT",
  "file_count": $FILE_COUNT,
  "includes_node_modules": false,
  "note": "Full PHP + TypeScript source and prebuilt assets/. Run npm ci && npm run build after editing frontend."
}
EOF

cd "$REPO/.pack-staging"
rm -f "$REPO/services2nri.zip" "$REPO/services2nri-full-source.zip"
zip -rq "$REPO/services2nri.zip" services2nri
cp "$REPO/services2nri.zip" "$REPO/services2nri-full-source.zip"

if zipinfo -1 "$REPO/services2nri.zip" | grep -q node_modules; then
  echo "FAIL: node_modules found inside zip" >&2
  exit 1
fi

BYTES="$(wc -c < "$REPO/services2nri.zip" | tr -d ' ')"
MAX=$((6 * 1024 * 1024))
if [[ "$BYTES" -gt "$MAX" ]]; then
  echo "FAIL: zip larger than 6MB ($BYTES bytes) — check packaging excludes" >&2
  exit 1
fi

echo "Created $REPO/services2nri.zip ($(du -h "$REPO/services2nri.zip" | cut -f1)) — v$VERSION full source, $FILE_COUNT files, commit $COMMIT"
echo "Also: services2nri-full-source.zip (identical copy for cache-safe downloads)"
