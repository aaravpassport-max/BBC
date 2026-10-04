# Services2NRI — Public Design System & Service Registry Audit

## Architecture

| Layer | Location | Role |
|-------|----------|------|
| Design tokens (defaults) | `shared/design-tokens.css` | Static fallbacks |
| Runtime token injection | `src/Design/DesignSystem.php` → `SEO.php` | CSS variables + typography utilities |
| Component utilities | `assets/public-design-system.css` | Buttons, cards, forms, alerts |
| Font library (100) | `data/font-library.json` + `FontLibrary.php` | Admin typography picker |
| Presets | `DesignPresets.php` | 10 one-click themes |
| Admin UI | `/admin/design` → `src/pages/admin/design-system.tsx` | Global controls + live preview |
| Service registry | `ServiceRegistry.php` | Visibility, navigation, forms, search |
| Public navigation API | `GET /navigation/public` | Mega-menu wired to registry |

**Inheritance:** `Global → page_type → page → section → element` (see `DesignSystem::resolve()`).

## Public pages matrix (summary)

| Page | Route | Layout | Primary tokens | Registry surfaces |
|------|-------|--------|----------------|---------------------|
| Home | `/` | `Layout`, sections in `HomePage.tsx` | `--s2-*`, `.s2-section` | `homepage`, `nav_top`, `cards` |
| Services index | `/services`, `/services/:cat` | `ServicesPage.tsx` | tabs `.s2-tabs`, grids | `directory`, `filters` |
| Service detail | `/service/:slug` | `ServiceDetailPage.tsx` | hero, sections API | `direct_url`, `forms`, `related` |
| About | `/about` | `index.tsx` | `PageHero`, body | global typography |
| Contact | `/contact` | forms → `FormInput` / `.s2-input` | forms tokens | — |
| How it works | `/how-it-works` | `.s2-how-grid` | spacing grid | — |
| FAQ | `/faq` | accordions | typography | — |
| Pricing | `/pricing` | cards | `.s2-card` | — |
| Blog | `/blog` | **PHP** `Blog.php` | inherits shell CSS from SEO | — |
| Cities | `/cities/:city` | `CityPage` | layout tokens | `cards` |
| Terms / Privacy | `/terms`, `/privacy` | content | `.s2-t-body` | — |
| Auth | `/login`, `/register` | `auth/index.tsx` | buttons | — |
| Quote shortcode | `[s2nri_quote_form]` | `Shortcodes.php` | primary color + registry forms | `forms` |

Each public React page is wrapped in `#s2nri-root.s2-ds` and `Layout` (except auth minimal layouts).

## Component → token mapping

| Component | CSS / module | Tokens |
|-----------|--------------|--------|
| Primary button | `.s2-btn--primary` | `--s2-color-primary`, `--s2-font-button` |
| Card | `.s2-card` | `--s2-shadow-card`, `--s2-radius-lg` |
| Form field | `.s2-input`, `FormInput` in `ui/index.tsx` | border, focus ring from primary |
| Mega menu | `Layout.tsx` | `navigation/public` + `--s2-font-nav` |
| Footer services | `Layout.tsx` | `services?surface=footer` |
| Service grid | `.s2-svc-grid` in theme | spacing, card |
| Bottom nav | `BottomNav.tsx` | primary, mobile breakpoints |

## Service visibility surfaces

Configured per service: `public_status`, `visibility_rules` (JSON), `direct_url_behavior`.

Consumers:

- `ServiceController::index` → `ServiceRegistry::forSurface()`
- `ServiceController::show` → `resolveDirectUrl()`
- `Layout` navigation → `GET navigation/public`
- Footer → `surface=footer`
- Homepage / directory / search → query `?surface=`

## Verification checklist

1. All public pages load `#s2nri-design-system` CSS from SEO shell.
2. Headings use `--s2-font-heading` via `.s2-ds` rules.
3. Colors resolve from `--s2-color-*` (no new hard-coded brand hex in new code paths).
4. Hiding a service removes it from nav, footer, forms, and search (same registry).
5. Historical bookings unchanged when `public_status` = hidden.
6. Admin **Design System** publishes tokens without breaking legacy `primary_color`.
7. 100 fonts searchable in admin **Fonts** tab.
8. Presets apply via `POST admin/design/preset`.

## Scenarios A–G (manual / staging)

| Scenario | Expected |
|----------|----------|
| A Hide one service | Removed from nav, homepage lists, forms, footer |
| B Restore | Reappears per visibility rules |
| C Hide parent category | Children hidden via category cascade |
| D Hide all children | Empty mega-menu columns omitted |
| E Historical data | Bookings intact |
| F Direct URL | 404 / redirect / unavailable per `direct_url_behavior` |
| G Form selectors | Hidden service not in dropdowns |
