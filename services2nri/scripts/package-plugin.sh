#!/usr/bin/env bash
# Build frontend assets and create WordPress-ready services2nri.zip at repo root.
# Full SOURCE distribution: all PHP/TS/docs/scripts/tests + prebuilt assets/.
# node_modules are excluded (run npm ci in services2nri/ after unzip to rebuild).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO="$(cd "$ROOT/.." && pwd)"
cd "$ROOT"
npm run build
bash scripts/verify-design-system.sh
cd "$REPO"
rm -f services2nri.zip
zip -rq services2nri.zip services2nri \
  -x 'services2nri/node_modules/*' \
  -x 'services2nri/src-react/node_modules/*' \
  -x 'services2nri/**/node_modules/*' \
  -x 'services2nri/.git/*' \
  -x 'services2nri/**/.git/*' \
  -x 'services2nri/**/*.map' \
  -x 'services2nri/**/playwright-report/*' \
  -x 'services2nri/**/test-results/*' \
  -x 'services2nri/.cursor/*'
echo "Created $REPO/services2nri.zip ($(du -h services2nri.zip | cut -f1)) — full source, no node_modules"
