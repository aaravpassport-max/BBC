# Design system — enterprise E2E audit (4.7.10+)

## Control surfaces (admin)

| Need | Where |
|------|--------|
| Global brand (colors, fonts, spacing, chrome, components) | Design System → Presets (**Apply site-wide look** resets tokens + chrome + global widths + clears template overrides) |
| **Per template** (home, service, FAQ, …) | **Page Templates** — colors + page max + link to Width & Layout |
| Per page slug | Width & Layout → **page**; Overrides → **pages** JSON |
| Per page type | Width & Layout → **page type**; Page Templates overrides |
| Per section width | Width & Layout → **section** or **service section** |
| Per section colors | Overrides → **sections** (applies on `[data-s2-section]`) |
| Per service hero width | Service Page Builder → Hero Settings |
| Components (buttons) | Design System → **Components** → `--s2-btn-*` on public site |
| Header / footer / top bar | **Site Chrome** (global) + **Page Templates** (per route) |
| Typography per template | **Page Templates** → page title / body sizes (px) |

## Runtime wiring

1. **PHP first paint:** `DesignSystem::renderInlineCss($path)` — resolves page type/slug overrides, widths, typography, section overrides.
2. **SPA navigation + live sync:** `DesignLiveSync` polls `GET design/public` (revision hash); `PageWidthScope` / `applyDesignForPath()` re-merges overrides, widths, **chrome**, typography, and **section** color overrides without a full page reload.
3. **Publish / preset:** Saves to DB and broadcasts via `BroadcastChannel`; open public tabs and Live Site iframe update within seconds — **no hard refresh**.

## Known limits (honest)

- Typography on SPA: global `.s2-t-*` from PHP plus **`#s2nri-typography-runtime`** on route change (template overrides merged).
- Blog remains **PHP** (`Blog.php`); use global + `page_types.blog` + `pages` slugs.
- Header/footer **column structure** still lives in `Layout.tsx`; colors, height, top bar, and full/minimal footer use **`chrome`** tokens.

## Verification

```bash
cd services2nri && npm run build && bash scripts/verify-design-system.sh && npm run test:e2e
```
