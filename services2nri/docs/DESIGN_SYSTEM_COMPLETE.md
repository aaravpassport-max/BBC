# Design System & Service Registry — completion sign-off

Plugin **4.5.4+** implements the centralized design and visibility architecture requested for Services2NRI public surfaces.

## Requirements coverage

| # | Area | Status | Implementation |
|---|------|--------|----------------|
| 1–10 | Tokens, typography (100 fonts), colors, spacing, components | Done | `DesignSystem.php`, presets, admin tabs, CSS bundles |
| 11–16 | Admin global controls, live preview, publish | Done | `/admin/design`, `PUT admin/design` |
| 17 | Page audit matrix | Done | `DESIGN_SYSTEM_PAGE_MATRIX.md` |
| 18–20 | Inheritance global → page → section | Done | `DesignSystem::resolve()`, Overrides admin tab |
| 21–25 | Service registry, surfaces, direct URL | Done | `ServiceRegistry.php`, admin matrix |
| 26–28 | Nav, footer, forms, search wiring | Done | API `?surface=`, Layout, Shortcodes, customer portal |
| 29–30 | SEO / sitemap | Done | `shouldIndexInSeo`, `SitemapJob` |
| 31–33 | Performance, a11y utilities, reduced motion | Done | CSS utilities, motion flag |
| 34 | Other entities | Done | `PublicEntityRegistry.php`, cities + visa scope |
| 35 | Verification scenarios | Documented | `VERIFICATION_STAGING.md` + `verify-design-system.sh` |

## Deploy artifacts

- **Folder:** `services2nri/` in repository
- **ZIP:** `services2nri.zip` at repository root (`scripts/package-plugin.sh`)
- **Version constant:** `S2NRI_VERSION` in `services2nri.php`

## Post-deploy (once per environment)

1. Activate or re-save plugin to run migrations (`is_featured`, visibility columns).
2. Admin → **Design System** → publish tokens once.
3. Run staging checklist in `VERIFICATION_STAGING.md`.
