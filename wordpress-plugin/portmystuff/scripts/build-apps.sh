#!/usr/bin/env bash
# Build React + TypeScript standalone web app into plugin assets.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/web"
npm install
npm run build
echo "Built to $ROOT/assets/dist/"
echo "Zip: cd ../../ && zip -r portmystuff-1.2.0.zip portmystuff"
