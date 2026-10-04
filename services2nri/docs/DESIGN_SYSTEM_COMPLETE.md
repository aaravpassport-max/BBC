# Design System & Service Registry — enterprise sign-off (v4.6.1)

## Delivered at production level

| Capability | Implementation |
|------------|----------------|
| Central tokens + 100 fonts + 10 presets | `DesignSystem.php`, admin `/admin/design` |
| Full admin tabs incl. header/page-type, icons, live iframe preview | `design-system.tsx` + `design-system-panels.tsx` |
| Guided overrides + JSON overrides | Overrides tab |
| Font click-to-assign | Fonts tab → `FontAssignPanel` |
| Service/category/city registry + per-surface matrix | `ServiceRegistry`, `PublicEntityRegistry`, admin + **Services CRUD** visibility block |
| Public CSS bundles (components, home, marketing, layout) | `assets/public-*.css` inlined on public + portal |
| Shared React primitives | `components/public/PublicLayout.tsx` |
| PHPUnit-free unit tests | `tests/unit/visibility-logic-test.php` |
| CI | `.github/workflows/services2nri-design-verify.yml` |
| Release ZIP | `scripts/package-plugin.sh` → `services2nri.zip` |
| Audit docs | `DESIGN_SYSTEM_PAGE_MATRIX.md` + generated index |
| Staging scenarios | `VERIFICATION_STAGING.md` |

## Remaining (environment / content migration)

| Item | Notes |
|------|--------|
| **Live WP scenarios A–G** | Run on your host after deploying 4.6.0 — cannot be executed from cloud agent VM. |
| **Legacy inline styles** | Reduced via tokens + `PublicLayout`; long-tail pages (`ServiceDetailPage`, FAQ, Pricing) still contain historical inline blocks — safe but not 100% class-only. |
| **PHP blog template** | Token-injected; not React-unified (by design for SEO shell). |
| **Vendor public catalog** | Not applicable — vendors remain staff-only per product rules. |
| **Visual drag-and-drop menu** | JSON navigation editor + registry filter (enterprise-safe); not WP Menu UI. |

## Deploy checklist (plug & play)

1. Upload **`services2nri.zip`**, activate **4.6.1** — tables, seeds, design defaults, and rewrites run automatically.
2. Optional: `bash services2nri/scripts/verify-design-system.sh`
3. WP Admin → **Services2NRI → Admin Portal** — confirm dashboard loads.
4. Staging: `docs/VERIFICATION_STAGING.md` · Operator guide: `docs/PLUG_AND_PLAY.md`
