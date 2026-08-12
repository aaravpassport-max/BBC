#!/usr/bin/env bash
# Build React apps and copy into the WordPress plugin assets folder.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PLUGIN="$ROOT/wordpress-plugin/portmystuff/assets/apps"
API_URL="${WP_API_URL:-/wp-json/portmystuff/v1}"

build_app() {
  local name=$1 dir=$2
  echo "==> Building $name from $dir"
  (
    cd "$ROOT/package/$dir"
    VITE_API_BASE_URL="$API_URL" npm run build
    rm -rf "$PLUGIN/$name"
    mkdir -p "$PLUGIN/$name"
    cp -r dist/* "$PLUGIN/$name/"
  )
}

mkdir -p "$PLUGIN"

if [[ "${1:-all}" == "all" || "${1:-}" == "customer" ]]; then build_app customer frontend; fi
if [[ "${1:-all}" == "all" || "${1:-}" == "driver" ]]; then build_app driver driver-app; fi

echo "Done. Apps copied to $PLUGIN"
echo "Zip plugin: cd wordpress-plugin && zip -r portmystuff.zip portmystuff"
