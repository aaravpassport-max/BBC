#!/usr/bin/env bash
# Static verification for design system + service registry wiring (no WordPress required).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail=0
ok() { echo "OK  $1"; }
bad() { echo "FAIL $1"; fail=1; }

[[ -f src/Design/DesignSystem.php ]] && ok DesignSystem.php || bad DesignSystem.php
[[ -f src/Services/ServiceRegistry.php ]] && ok ServiceRegistry.php || bad ServiceRegistry.php
[[ -f assets/public-design-system.css ]] && ok public-design-system.css || bad public-design-system.css
[[ -f assets/public-page-utilities.css ]] && ok public-page-utilities.css || bad public-page-utilities.css
[[ -f assets/public-home-sections.css ]] && ok public-home-sections.css || bad public-home-sections.css
[[ -f assets/public-marketing-sections.css ]] && ok public-marketing-sections.css || bad public-marketing-sections.css

font_count=$(python3 -c "import json; print(len(json.load(open('data/font-library.json'))['fonts']))" 2>/dev/null || echo 0)
if [[ "$font_count" -ge 100 ]]; then ok "font-library ($font_count fonts)"; else bad "font-library expected >=100 got $font_count"; fi

grep -q "surface=forms" src/pages/customer/index.tsx && ok customer surface=forms || bad customer surface=forms
grep -q "admin/navigation" src/Api/Dispatcher.php && ok admin navigation routes || bad admin navigation routes
grep -q "updateCategoryVisibility" src/Api/Controllers/DesignControllers.php && ok category visibility API || bad category visibility API
grep -q "is_featured" src/Installer.php && ok is_featured migration || bad is_featured migration

if [[ $fail -ne 0 ]]; then
  echo "Verification failed."
  exit 1
fi
echo "All design-system static checks passed."
