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
[[ -f assets/public-width-layout.css ]] && ok public-width-layout.css || bad public-width-layout.css
[[ -f assets/public-site-chrome.css ]] && ok public-site-chrome.css || bad public-site-chrome.css
[[ -f assets/public-mobile-experience.css ]] && ok public-mobile-experience.css || bad public-mobile-experience.css
[[ -f assets/public-contrast-system.css ]] && ok public-contrast-system.css || bad public-contrast-system.css
grep -q 'public-contrast-system.css' src/Design/DesignSystem.php && ok contrast css inlined || bad contrast css inlined
[[ -f assets/public-platform-experience.css ]] && ok public-platform-experience.css || bad public-platform-experience.css
grep -q 'public-platform-experience.css' src/Design/DesignSystem.php && ok platform experience css inlined || bad platform experience css inlined
[[ -f assets/public-service-experience.css ]] && ok public-service-experience.css || bad public-service-experience.css
grep -q 'public-service-experience.css' src/Design/DesignSystem.php && ok service experience css inlined || bad service experience css inlined
[[ -f src/Design/WidthLayout.php ]] && ok WidthLayout.php || bad WidthLayout.php
[[ -f playwright.config.ts ]] && ok playwright.config || bad playwright.config
[[ -f e2e/smoke.spec.ts ]] && ok e2e smoke spec || bad e2e smoke spec
[[ -f README.md ]] && ok README.md || bad README.md
[[ -f docs/PLUG_AND_PLAY.md ]] && ok PLUG_AND_PLAY.md || bad PLUG_AND_PLAY.md
[[ -f docs/WIDTH_LAYOUT.md ]] && ok WIDTH_LAYOUT.md || bad WIDTH_LAYOUT.md
grep -q "ensureSeeded" src/Design/DesignSystem.php && ok design ensureSeeded || bad design ensureSeeded
grep -q '\$stored = self::loadStored' src/Design/DesignSystem.php && ok design save merges stored || bad design save merges stored
grep -q 'renderPageTypeSectionCss' src/Design/WidthLayout.php && ok width page-type sections css || bad width page-type sections css
grep -q 'pruneInheritedWidthLayers' src/Design/WidthLayout.php && ok width inheritance prune || bad width inheritance prune
grep -q 'deepMergeWithDeletes' src/Design/DesignSystem.php && ok design merge deletes || bad design merge deletes
grep -q 'pruneInheritedWidthLayers' src/lib/width-inheritance.ts && ok width inheritance client || bad width inheritance client

php tests/unit/visibility-logic-test.php && ok visibility unit tests || bad visibility unit tests
php tests/unit/design-system-save-test.php && ok design save unit tests || bad design save unit tests
php tests/unit/width-layout-responsive-test.php && ok width responsive unit tests || bad width responsive unit tests
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

if rg -q 'max-width:\s*(1200|1280|1100|1000|900|860)px' assets/public-*.css -g '!public-width-layout.css' 2>/dev/null; then
  bad "hard-coded layout max-width in public CSS (use --s2-width-* tokens)"
else
  ok public width tokens
fi

if rg -q 'max-width:1200px' src/Blog.php 2>/dev/null; then
  bad "Blog.php hard-coded 1200px (use --s2-width-page-max)"
else
  ok blog width tokens
fi

grep -q 'sectionMaxFromLayer' src/Design/WidthLayout.php && grep -q 'sec-.*-max' src/Design/WidthLayout.php && ok section width max shorthand || bad section width max shorthand
[[ -f src/lib/design-resolve.ts ]] && grep -q 'resolveDesignConfig' src/lib/design-resolve.ts && ok design client resolver || bad design client resolver
[[ -f src/lib/design-admin-config.ts ]] && grep -q 'normalizeAdminDesignConfig' src/lib/design-admin-config.ts && ok design admin config normalize || bad design admin config normalize
grep -q "'revision'" src/Api/Controllers/DesignControllers.php && ok design admin revision api || bad design admin revision api
grep -q 'applySectionOverrideCss' src/lib/design-resolve.ts && ok section override live sync || bad section override live sync
grep -q 'renderSectionOverrideCss' src/Design/DesignSystem.php && ok section override css || bad section override css
grep -q 'configFromPreset' src/Design/DesignSystem.php && ok preset full look builder || bad preset full look builder
grep -q "Design\\\\WidthLayout" services2nri.php && grep -q 'Design/WidthLayout.php' services2nri.php && ok WidthLayout classmap || bad WidthLayout classmap
grep -q 'publicRevision' src/Design/DesignSystem.php && ok design public revision || bad design public revision
[[ -f src/lib/design-live-sync.ts ]] && grep -q 'syncDesignFromServer' src/lib/design-live-sync.ts && ok design live sync || bad design live sync
php scripts/audit-preset-looks.php && ok preset look audit || bad preset look audit
grep -q 's2-btn-primary-bg' assets/public-design-system.css && ok component css vars || bad component css vars

if [[ $fail -ne 0 ]]; then
  echo "Verification failed."
  exit 1
fi
echo "All design-system static checks passed."
