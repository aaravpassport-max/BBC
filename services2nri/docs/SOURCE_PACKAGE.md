# Full-source plugin zip

The official **`services2nri.zip`** (and identical **`services2nri-full-source.zip`**) at the repository root is a **complete source tree**, not a stripped “runtime-only” package.

## Verify you got the right file (not a cached 20 MB build)

| Check | Good (4.7.11+) | Old / wrong |
|-------|----------------|-------------|
| Download size | **~1.4 MB** | ~20 MB |
| Files in zip | **~215** | ~2,500+ |
| `node_modules` inside | **none** | thousands of paths |

After unzip, open **`services2nri/RELEASE-MANIFEST.json`** — must show `"includes_node_modules": false` and your version.

If the browser still shows 20 MB, use the **commit-pinned** or **`services2nri-full-source.zip`** link from `README.md` (bypasses CDN cache on `services2nri.zip`).

## Included

| Path | Contents |
|------|-----------|
| `src/` | PHP plugin core (Design, API, Services, Portal, …) |
| `src/` (TS/TSX) | Public SPA + admin UI source (`pages/`, `components/`, `lib/`) |
| `src-react/` | Legacy/alternate React builder source |
| `assets/` | **Pre-built** JS/CSS (works immediately after upload) |
| `builder/` | Homepage / form builder assets |
| `data/` | Font library JSON, seeds |
| `shared/` | Shared JS utilities |
| `scripts/` | Verify, package, audit scripts |
| `docs/` | Architecture, design system, staging checklists |
| `tests/` | PHP unit stubs/tests |
| `e2e/` | Playwright specs |
| `dist/` | Additional portal bundles (if present) |
| Root configs | `package.json`, `vite.config.ts`, `tsconfig.json`, `playwright.config.ts`, … |

## Excluded (reinstall locally)

- `node_modules/` (root and `src-react/`) — run `npm ci` then `npm run build` to regenerate assets after editing TS.

## Why ~20 MB before?

Older packaging accidentally included **`src-react/node_modules`** (~50 MB uncompressed). Current packaging excludes all `node_modules` while keeping **100% of source files**.

## Rebuild after editing frontend

```bash
cd services2nri
npm ci
npm run build
bash scripts/package-plugin.sh
```

WordPress only needs `assets/app.js` and related chunks at runtime; source is for your team to customize.
