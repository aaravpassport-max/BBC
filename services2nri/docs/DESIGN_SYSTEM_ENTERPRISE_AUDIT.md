# Design system — enterprise E2E audit (4.7.5+)

## Control surfaces (admin)

| Need | Where |
|------|--------|
| Global brand (colors, fonts, spacing) | Design System → Colors, Fonts, Spacing, Presets |
| **Per template** (home, service, FAQ, …) | **Page Templates** — colors + page max + link to Width & Layout |
| Per page slug | Width & Layout → **page**; Overrides → **pages** JSON |
| Per page type | Width & Layout → **page type**; Page Templates overrides |
| Per section width | Width & Layout → **section** or **service section** |
| Per section colors | Overrides → **sections** (applies on `[data-s2-section]`) |
| Per service hero width | Service Page Builder → Hero Settings |
| Components (buttons) | Design System → **Components** → `--s2-btn-*` on public site |

## Runtime wiring

1. **PHP first paint:** `DesignSystem::renderInlineCss($path)` — resolves page type/slug overrides, widths, typography, section overrides.
2. **SPA navigation:** `PageWidthScope` calls `applyDesignForPath()` — re-merges `overrides` + widths from `S2NRI_CONFIG.design`.
3. **Publish:** Admin **Publish design** saves full config; hard-refresh once to reload PHP inline CSS bundle.

## Known limits (honest)

- Typography utility classes (`.s2-t-*`) are generated on **first HTML load**; SPA route changes update **CSS variables**, not every typography rule.
- Blog remains **PHP** (`Blog.php`); use global + `page_types.blog` + `pages` slugs.
- Header/footer **layout** (not colors) still lives in `Layout.tsx` — token colors apply via variables.

## Verification

```bash
cd services2nri && npm run build && bash scripts/verify-design-system.sh && npm run test:e2e
```
