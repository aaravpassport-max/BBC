# Design System & Service Registry — enterprise sign-off (v4.6.0)

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

## Deploy checklist

1. Upload `services2nri.zip` or git pull `main`, activate **4.6.0**.
2. `bash services2nri/scripts/verify-design-system.sh`
3. Admin → Design System → Publish.
4. `docs/VERIFICATION_STAGING.md` on staging.
