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

VERSION="$(grep -oP "define\('FNO_PLUGIN_VERSION', '\K[^']+" "$SCRIPT_DIR/fno-lab.php" || true)"

cleanup() { rm -rf "$BUILD_ROOT"; }
trap cleanup EXIT

mkdir -p "$BUILD_ROOT/$PLUGIN_SLUG"
tar -C "$SCRIPT_DIR" \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='build-plugin-zip.sh' \
  -cf - . | tar -C "$BUILD_ROOT/$PLUGIN_SLUG" -xf -

GIT_SHA="$(git -C "$SCRIPT_DIR/../.." rev-parse --short HEAD 2>/dev/null || echo unknown)"
CORE_SHA="$(sha256sum "$SCRIPT_DIR/assets/fno-lab-core.js" | awk '{print $1}')"
PHP_SHA="$(sha256sum "$SCRIPT_DIR/fno-lab.php" | awk '{print $1}')"
cat > "$BUILD_ROOT/$PLUGIN_SLUG/PLUGIN_BUILD.txt" <<EOF
FNO_PLUGIN_VERSION=${VERSION}
FNO_PLUGIN_INSTALL_ID=$(grep -oP "define\('FNO_PLUGIN_INSTALL_ID', '\K[^']+" "$SCRIPT_DIR/fno-lab.php" || echo unknown)
FNO_CORE_BUILD_MARKER=$(grep -oP "const FNO_CORE_BUILD_MARKER = '\K[^']+" "$SCRIPT_DIR/assets/fno-lab-core.js" | head -1 || echo unknown)
git_commit=${GIT_SHA}
sha256_fno-lab.php=${PHP_SHA}
sha256_fno-lab-core.js=${CORE_SHA}
built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF

rm -f "$OUT_ZIP"
(
  cd "$BUILD_ROOT"
  zip -rq "$OUT_ZIP" "$PLUGIN_SLUG"
)

echo "Built $OUT_ZIP"
echo "Top-level entries:"
unzip -l "$OUT_ZIP" | awk 'NR<=8 || /^---------/ {print}'

if [ -n "$VERSION" ]; then
  ZIP_VERSION="$(unzip -p "$OUT_ZIP" "$PLUGIN_SLUG/fno-lab.php" | grep -oP '\* Version: \K[0-9.]+' | head -1 || true)"
  if [ "$VERSION" != "$ZIP_VERSION" ]; then
    echo "ERROR: zip header Version ($ZIP_VERSION) != FNO_PLUGIN_VERSION ($VERSION)" >&2
    exit 1
  fi
  echo "Verified zip version: $VERSION"
  MARKER="$(unzip -p "$OUT_ZIP" "$PLUGIN_SLUG/assets/fno-lab-core.js" | grep -oP "const FNO_CORE_BUILD_MARKER = '\K[^']+" | head -1 || true)"
  if [ -z "$MARKER" ]; then
    echo "ERROR: zip core JS missing FNO_CORE_BUILD_MARKER" >&2
    exit 1
  fi
  echo "Verified core build marker: $MARKER"
fi
