#!/usr/bin/env bash
# Build fno-lab-standalone-app.zip with a fixed top-level folder so WordPress
# upgrades replace wp-content/plugins/fno-lab-standalone-app/ instead of adding
# a new versioned plugin folder on every release.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ ! -f "$SCRIPT_DIR/fno-data-layer.php" ]] || [[ ! -d "$SCRIPT_DIR/autonomous-driver" ]]; then
  echo "ERROR: Incomplete plugin source tree — refusing to build zip." >&2
  echo "This folder is missing fno-data-layer.php and/or autonomous-driver/." >&2
  echo "Restore the full plugin (or extract the last good fno-lab-standalone-app.zip) before running build-plugin-zip.sh." >&2
  exit 1
fi
PLUGIN_SLUG="fno-lab-standalone-app"
OUT_DIR="${1:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BUILD_ROOT="$(mktemp -d)"

cleanup() { rm -rf "$BUILD_ROOT"; }
trap cleanup EXIT

mkdir -p "$BUILD_ROOT/$PLUGIN_SLUG"
tar -C "$SCRIPT_DIR" \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='build-plugin-zip.sh' \
  -cf - . | tar -C "$BUILD_ROOT/$PLUGIN_SLUG" -xf -

OUT_ZIP="$OUT_DIR/${PLUGIN_SLUG}.zip"
rm -f "$OUT_ZIP"
(
  cd "$BUILD_ROOT"
  zip -rq "$OUT_ZIP" "$PLUGIN_SLUG"
)

echo "Built $OUT_ZIP"
echo "Top-level entries:"
unzip -l "$OUT_ZIP" | awk 'NR<=8 || /^---------/ {print}'
