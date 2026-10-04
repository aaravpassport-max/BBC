#!/usr/bin/env bash
# Full pre-release gate: assets, PHP paths, design system, Playwright boot + smoke.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "== verify-spa-assets =="
node scripts/verify-spa-assets.mjs

echo "== verify-preg-paths =="
php scripts/verify-preg-paths.php

echo "== verify-design-system =="
bash scripts/verify-design-system.sh

echo "== playwright boot (production shell) =="
CI=1 npx playwright test e2e/boot.spec.ts --reporter=list

echo "== playwright smoke =="
CI=1 npx playwright test e2e/smoke.spec.ts --reporter=list

echo "All verify-all checks passed."
