#!/usr/bin/env bash
# Build fno-lab-standalone-app.zip with a fixed top-level folder so WordPress
# upgrades replace wp-content/plugins/fno-lab-standalone-app/ instead of adding
# a new versioned plugin folder on every release.
#
# Canonical output: <repo-root>/fno-lab-standalone-app.zip (the GitHub raw download).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="fno-lab-standalone-app"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
OUT_ZIP="$REPO_ROOT/${PLUGIN_SLUG}.zip"
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

rm -f "$OUT_ZIP"
(
  cd "$BUILD_ROOT"
  zip -rq "$OUT_ZIP" "$PLUGIN_SLUG"
)

echo "Built $OUT_ZIP"
echo "Top-level entries:"
unzip -l "$OUT_ZIP" | awk 'NR<=8 || /^---------/ {print}'

VERSION="$(grep -oP "define\('FNO_PLUGIN_VERSION', '\K[^']+" "$SCRIPT_DIR/fno-lab.php" || true)"
if [ -n "$VERSION" ]; then
  ZIP_VERSION="$(unzip -p "$OUT_ZIP" "$PLUGIN_SLUG/fno-lab.php" | grep -oP '\* Version: \K[0-9.]+' | head -1 || true)"
  if [ "$VERSION" != "$ZIP_VERSION" ]; then
    echo "ERROR: zip header Version ($ZIP_VERSION) != FNO_PLUGIN_VERSION ($VERSION)" >&2
    exit 1
  fi
  echo "Verified zip version: $VERSION"
fi
