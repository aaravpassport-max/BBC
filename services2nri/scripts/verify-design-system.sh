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
[[ -f assets/public-pages-layout.css ]] && ok public-pages-layout.css || bad public-pages-layout.css
[[ -f assets/public-marketing-pages.css ]] && ok public-marketing-pages.css || bad public-marketing-pages.css
[[ -f assets/public-service-detail.css ]] && ok public-service-detail.css || bad public-service-detail.css
[[ -f assets/public-services-directory.css ]] && ok public-services-directory.css || bad public-services-directory.css
[[ -f playwright.config.ts ]] && ok playwright.config || bad playwright.config
[[ -f e2e/smoke.spec.ts ]] && ok e2e smoke spec || bad e2e smoke spec
[[ -f README.md ]] && ok README.md || bad README.md
[[ -f docs/PLUG_AND_PLAY.md ]] && ok PLUG_AND_PLAY.md || bad PLUG_AND_PLAY.md
grep -q "ensureSeeded" src/Design/DesignSystem.php && ok design ensureSeeded || bad design ensureSeeded

php tests/unit/visibility-logic-test.php && ok visibility unit tests || bad visibility unit tests
php scripts/generate-audit-matrix.php >/dev/null && ok audit matrix generator || bad audit matrix generator

font_count=$(python3 -c "import json; print(len(json.load(open('data/font-library.json'))['fonts']))" 2>/dev/null || echo 0)
if [[ "$font_count" -ge 100 ]]; then ok "font-library ($font_count fonts)"; else bad "font-library expected >=100 got $font_count"; fi

grep -q "surface=forms" src/pages/customer/index.tsx && ok customer surface=forms || bad customer surface=forms
grep -q "admin/navigation" src/Api/Dispatcher.php && ok admin navigation routes || bad admin navigation routes
grep -q "updateCategoryVisibility" src/Api/Controllers/DesignControllers.php && ok category visibility API || bad category visibility API
grep -q "is_featured" src/Installer.php && ok is_featured migration || bad is_featured migration
grep -q "CategoryRegistryVisibilityBlock" src/components/admin/RegistryVisibilityBlock.tsx && ok category admin visibility block || bad category admin visibility block
grep -q "publicCities( 'sitemap' )" services2nri.php && ok sitemap city registry || bad sitemap city registry

# Public SPA pages: brand primary comes from Layout; no per-section --s2-primary duplicates.
if rg -q "style=\{\{ \['--s2-primary'" src/pages/public 2>/dev/null; then
  bad "duplicate --s2-primary inline on public pages (use Layout root)"
else
  ok public primary scope
fi

if [[ $fail -ne 0 ]]; then
  echo "Verification failed."
  exit 1
fi
echo "All design-system static checks passed."
