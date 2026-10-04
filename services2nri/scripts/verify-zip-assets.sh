#!/usr/bin/env bash
# After packaging, confirm zip contains coherent BUILD_STAMP + booking export check.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ZIP="${1:-$REPO/services2nri-full-source.zip}"
STAGE="$REPO/.zip-verify-staging"

if [[ ! -f "$ZIP" ]]; then
  echo "FAIL: zip not found: $ZIP" >&2
  exit 1
fi

rm -rf "$STAGE"
mkdir -p "$STAGE"
unzip -q "$ZIP" -d "$STAGE"

PLUGIN="$STAGE/services2nri"
[[ -f "$PLUGIN/assets/BUILD_STAMP.txt" ]] || { echo "FAIL: BUILD_STAMP.txt missing in zip"; exit 1; }
STAMP="$(tr -d '\n' < "$PLUGIN/assets/BUILD_STAMP.txt")"

cd "$PLUGIN"
node scripts/verify-spa-assets.mjs
php scripts/verify-preg-paths.php

VER="$(grep -oP "define\s*\(\s*'S2NRI_VERSION',\s*'\K[0-9.]+" services2nri.php | head -1)"
echo "OK  zip verified — v$VER BUILD_STAMP=$STAMP ($(du -h "$ZIP" | cut -f1))"
rm -rf "$STAGE"
