# Services2NRI Plugin — Changelog

All notable changes to this plugin are documented in this file.
Format: [Version] — YYYY-MM-DD — Author — Description

---

## [4.7.61] — 2026-10-05 — Cloud Agent

- **Design System Content:** Every homepage and public-page section has in-band controls — list editors (FAQ, press, partners, awards, HIW steps), registry previews (testimonials, pricing, blog, services, cities), service-page bands, marquee, and legal/footer panels. Removed generic “no text fields” dead-end message.

---

## [4.7.60] — 2026-10-05 — Cloud Agent

- **Design builder layout:** Removed embedded live preview under sections. Section bands use **full admin page scroll** (no fixed-height inner scroll panel), matching reference homepage builder UX.

---

## [4.7.59] — 2026-10-05 — Cloud Agent

- **Design System Content tab:** List-style editors for Hero slides, Why Choose cards, stats grid, plus Service Registry and City Manager previews.
- **Design tab:** Desktop / Tablet / Mobile padding triples for section CSS; live homepage reads responsive padding variables.
- **Sticky save bar** when a band is expanded (content or styling + structure).

---

## [4.7.58] — 2026-10-05 — Cloud Agent

- **Design System builder:** Reference-style **band cards** (title, subtitle, Custom design pill, in-place expand). **Content | Design** underline tabs inside each band; grouped Design panel (background, colours, typography D/T/M, spacing) with orange accent save actions.

---

## [4.7.49] — 2026-10-05 — Cloud Agent

- **Design System UX:** Replaced 22 flat tabs with a **Page → Section → Content | Design** builder. **Site Foundation** holds global colors, typography, fonts, components, spacing, layout, chrome, and presets. Homepage and all public page templates list sections in the left nav with per-section content fields (platform settings) and design overrides (colors, visibility, layout).

---

## [4.7.48] — 2026-10-05 — Cloud Agent

- **Dashboard nav parity:** mobile sidebar is overlay-only (no in-flow push); tablet/mobile hide in-flow sidebar ≤900px; scroll lock when menu open; shell overflow hardened in `portal-experience.css`.
- **Unified portal boot:** `/portal` and `/s2nri-admin` now load the same release `app.js` / CSS stack as `/admin` (via `Portal.php` + `AssetBuildStamp`), including bottom nav and mobile shell.
- **E2E:** admin overlay sidebar geometry test; `/s2nri-admin` production-shell route on E2E server.

---

## [4.7.47] — 2026-10-05 — Cloud Agent

- **S2NRI Builder mobile parity:** bottom tab nav (Home / Service / Forms / Status / Menu), sticky action bar (View Site, Save Homepage, Add Field, Preview Service), mobile app surface + touch targets, stacked builder grids, bottom-sheet modals.
- **Builder shell:** `#s2nri-builder-root.s2-mobile-app-root` in `BuilderPage.php`.
- **E2E:** `builder-shell.html`, `/s2nri-builder` route on E2E server, `builder-mobile.spec.ts`.

---

## [4.7.46] — 2026-10-05 — Cloud Agent

- **Admin portal mobile polish:** dashboard sticky “View All Requests”; homepage builder sticky “Preview Homepage”; ticket detail sticky “Send Reply”; reviews/tickets status chips in `AdminToolbar`.
- **Admin bottom nav:** Requests tab now routes to `/admin/requests` (was broken `/requests` on mobile).
- **E2E:** admin dashboard sticky bar + requests tab navigation tests.

---

## [4.7.45] — 2026-10-05 — Cloud Agent

- **Customer portal mobile parity (TSX):** `AdminScreen`, sticky action bars, and `AdminToolbar` / `AdminFormStack` on dashboard, bookings list/detail, new request, profile, and support tickets.
- **Shared `PageHeader`:** uses admin mobile header classes so primary actions hide in the header on phones and appear in the sticky bar.
- **E2E:** `customer-mobile.spec.ts` + customer mock API routes (`bookings`, `profile`, `tickets`, `notifications`).

---

## [4.7.44] — 2026-10-04 — Cloud Agent

- **Admin mobile parity (TSX):** `AdminMobileUi` — `AdminScreen`, sticky action bars, `AdminTableWrap`, toolbars, form stacks; applied across all admin list/form screens (bookings, payments, customers, services, categories, FAQs, blog, pricing, cities, diagnostics, builder).
- **Design System studio:** mobile publish sticky bar, stacked registry panels, horizontal tab rail.
- **CSS:** `.s2-dash-table` card rows outside cards; admin sticky bar above bottom nav; desktop hides duplicate header actions.
- **E2E:** `admin-mobile.spec.ts` + admin mock API routes.

---

## [4.7.43] — 2026-10-04 — Cloud Agent

- **Dashboard tables (mobile):** `MobileDashTableEnhancer` sets `data-label` on cells for card-style admin/customer tables; wired globally in `App.tsx`.
- **Design System admin:** mobile tab rail (`s2-design-system-tabs`) and page shell class for small screens.
- **Service benefits:** benefit rows render `text` or `title` after normalizer aliases.
- **Audit matrix:** generator includes portal routes, React blog paths, and platform/portal CSS layers.

---

## [4.7.42] — 2026-10-04 — Cloud Agent

- **Service sections:** `service-section-normalize.ts` hydrates empty builder sections, maps FAQ/testimonial/process field aliases, skips legacy hero/marquee rows; duplicate bottom “Why Choose” hidden when `why_choose` section exists.
- **Contrast:** service CTA band and hero meta chips on dark surfaces.
- **E2E:** full mock section matrix + `service-sections.spec.ts`; contrast re-check paths unchanged.
- **Mobile admin:** card-style table rows in dashboard on phones.

---

## [4.7.41] — 2026-10-04 — Cloud Agent

- **Platform mobile app shell:** `platform-mobile-app.css` — Material-style touch targets (48px), 16px inputs, dashboard card surfaces, inline grid collapse, modal bottom sheets, auth full-bleed layout; wired in `DesignSystem.php` + SPA `global.css`.
- **Shell classes:** `s2-mobile-app-root` on `#s2nri-root`, `s2-mobile-app-surface` on dashboard content, `s2-dash-card` / `s2-ui-card`, `s2-modal-backdrop` / `s2-modal-panel`.
- **E2E:** `mobile-app.spec.ts` for viewport shell, touch targets, customer dashboard chrome.

---

## [4.7.40] — 2026-10-04 — Cloud Agent

- **Global SPA navigation:** `SpaNavigationBridge` intercepts same-origin internal anchors; `SpaLink` + `spa-navigation` helpers for programmatic routing; shell-aware (public vs `/portal` vs `/s2nri-admin` vs builder).
- **No full reload on consent accept:** analytics load client-side via `analytics-consent.ts`; cookie banner uses React Router links.
- **Blog routes** return to the React SPA (removed PHP blog short-circuit in `SEO.php`).
- **E2E:** `spa-navigation.spec.ts` guards against document reload on internal navigation.

---

## [4.7.39] — 2026-10-04 — Cloud Agent

- **Portal mobile shell:** `portal-experience.css` — frosted dashboard header, menu button, sidebar overlay; `s2-dash-mobile-header` on customer/admin layout.
- **Marketing completion:** scroll reveal on homepage about, press/partners, awards, newsletter, and app bands; About team + CTA, full How It Works timeline; About/Contact hero meta chips.
- **Platform polish:** team card hover, press/partner chip lift, how-it-works step hover, awards motion.

---

## [4.7.38] — 2026-10-04 — Cloud Agent

- **Service journey:** trust tile depth, visual process timeline, fee breakdown component (`rows` / `line_items` on charges sections), hero meta chips, mobile sticky CTA above bottom nav.
- **Marketing heroes:** optional trust/meta chips on `PageHero` (FAQ, How It Works) and services directory hero.
- **`public-service-experience.css`:** service-detail styling layered on platform experience; portal mobile background polish.

---

## [4.7.37] — 2026-10-04 — Cloud Agent

- **Platform experience layer:** `public-platform-experience.css` — section bands, premium hero glow, unified card/button motion, pricing emphasis, process/testimonial depth, FAQ micro-motion (reduced-motion safe).
- **ExperienceReveal:** intersection-observer scroll reveal for `[data-s2-reveal]` sections; wired on `PublicSection` + key homepage bands; staggered card grids via `.s2-stagger`.

---

## [4.7.36] — 2026-10-04 — Cloud Agent

- **Contrast completion:** dark-band coverage for blog/city/privacy heroes, pricing card heads, stats bar, service hero scrim; `.s2-surface-dark` on remaining marketing heroes.
- **Verification:** Playwright `contrast-audit.spec.ts` (all public dark heroes + app/stats/pricing) and `bottom-nav.spec.ts` (mobile public routes + Inquire tab).

---

## [4.7.35] — 2026-10-04 — Cloud Agent

- **Services page:** dedicated body band with responsive gap between hero, category sidebar, and card grid; hero uses contrast surface tokens for readable light typography.
- **Contrast system:** `public-contrast-system.css` overrides `.s2-ds` heading/body colors on dark bands (heroes, app download, marketing CTAs, image heroes).
- **Bottom navigation:** single `BottomNav` with public, customer portal, and admin variants; SVG tab icons; admin mobile bar + menu tab for full sidebar.

---

## [4.7.34] — 2026-10-04 — Cloud Agent

- **Page Templates:** Inherited vs Custom badges, per-field **Clear override**, and **Reset template to Site Foundation** (colors, typography, chrome, page max width via `null` delete on save).
- **Design admin copy:** Colors, Typography, Site Chrome tabs document Site Foundation inheritance.
- Unit test: template color override clears with `null`.

---

## [4.7.33] — 2026-10-04 — Cloud Agent

- **Services directory:** edge-to-edge card images, responsive grid (1 col mobile / multi-col desktop), badge and copy polish.
- **Mock API + Playwright:** full `surface=directory` catalog; `services-directory.spec.ts` asserts card alignment and grid.
- **Spacing:** `--s2-space-page_margin` mirrors Layout Studio horizontal gutter from width tokens.

---

## [4.7.32] — 2026-10-04 — Cloud Agent

- **Service cards:** edge-to-edge hero images (zero card padding on media; 16:10 aspect on mobile).
- **Mobile spacing controls:** `padding_x_left` / `padding_x_right` in Layout Studio (inherit horizontal gutter when empty); defaults tighten mobile gutter to 12px.
- **Responsive width plumbing:** page/section overrides now emit `@media` rules at 768/1024/1280; SPA injects matching runtime CSS on route/resize; marketing sections use width gutters.
- Tests: `width-layout-responsive-test.php`.

---

## [4.7.31] — 2026-10-04 — Cloud Agent

- **Mobile app shell:** hide desktop top bar and header CTAs on phones; compact app bar with WhatsApp/contact icons and drawer utilities.
- **Homepage:** hero headline + primary CTAs overlay; cities as horizontal scroll rail; larger touch targets for tabs and cards.
- **Bottom nav:** Material-style active indicator and safe-area padding; Contact tab on non-service pages.
- New stylesheet `public-mobile-experience.css` in `DesignSystem::renderInlineCss()`.

---

## [4.7.22] — 2026-10-04 — Cloud Agent

- **CDN mixed-chunk fix:** ship and load JS from `assets/release/{BUILD_STAMP}/` (unique URL path per build; fixes Cloudflare ignoring `?v=` on `/chunks/booking.js`).
- Auto-stage release folder on build; PHP `ensureReleaseStaged()` fallback; `booking_export_ok` in `/?s2nri_boot_diag=1`.

---

## [4.7.21] — 2026-10-04 — Cloud Agent

- **Infinite loader (deep fix):** external `boot-config.js` + `boot-watchdog.js` (strict CSP / hosts that block inline scripts); import map via `/?s2nri_import_map=1`; public `/?s2nri_boot_diag=1` JSON; SEO render try/catch shows PHP fatal instead of blank/spin.
- **BUILD_STAMP** now includes boot helper JS files.

---

## [4.7.20] — 2026-10-04 — Cloud Agent

- **Infinite loader root fix:** stop using `esc_html()` on `S2NRI_CONFIG` JSON (was corrupting quotes → `JSON.parse` failure). Safe parse with `bootError` fallback; block boot server-side on `BUILD_STAMP` mismatch; richer watchdog (config missing, `app.js` load error).
- **Plugin header Version** synced with `S2NRI_VERSION` (WP admin was showing 4.6.3 while code was 4.7.x).
- **main.tsx:** try/catch around `createRoot` so render failures replace splash with an error instead of spinning forever.

---

## [4.7.19] — 2026-10-04 — Cloud Agent

- **E2E production shell:** load full public CSS stack + `s2nri-theme.css`; mock `/?s2nri_img=` placeholders; boot test asserts stylesheets, service grid, and zero broken images.

---

## [4.7.18] — 2026-10-04 — Cloud Agent

- **JS boot (verified e2e):** unified `BUILD_STAMP.txt` for `app.js`, CSS, and all import-map chunks; safe `S2NRI_CONFIG` via `application/json` + `JSON.parse`; wp-admin mixed-build notice; boot watchdog detects export mismatch.
- **PHP:** fix `#` delimiter in service/blog path regex (`Unknown modifier ']'` in diagnostics).
- **Release gate:** `verify-spa-assets.mjs`, `verify-all.sh`, Playwright production-shell boot tests, zip asset verification before shipping.

---

## [4.7.15] — 2026-10-04 — Cloud Agent

- **Infinite loader:** verify `assets/app.js` + core chunks exist before showing splash; 8s watchdog + JS error/rejection handlers; design CSS wrapped in try/catch so boot cannot fatal mid-page.

---

## [4.7.14] — 2026-10-04 — Cloud Agent

- **Spinner stuck:** design classes `require_once` in `DesignSystem.php`; `getJsConfig()` try/catch so PHP fatal does not blank the shell; 15s splash watchdog + `s2nri-app-mounted` when React boots.

---

## [4.7.13] — 2026-10-04 — Cloud Agent

- **Fatal fix:** register `S2NRI\Design\WidthLayout` in `services2nri.php` classmap (fixes `Class WidthLayout not found` on public site boot).

---

## [4.7.12] — 2026-10-04 — Cloud Agent

- **Packaging:** rsync staging (never copies `node_modules`); zip self-check + 6 MB cap; `RELEASE-MANIFEST.json` inside plugin; duplicate **`services2nri-full-source.zip`** for cache-safe downloads.

---

## [4.7.11] — 2026-10-04 — Cloud Agent

- **Release zip:** full source tree (PHP, TS, `src-react/`, docs, tests, scripts) + prebuilt `assets/`; **all `node_modules` excluded** (~3 MB instead of ~20 MB). See `docs/SOURCE_PACKAGE.md`.

---

## [4.7.10] — 2026-10-04 — Cloud Agent

- **Presets:** per-theme typography scale patches (heading/body sizes) so presets differ in type rhythm, not only color/fonts.
- **SPA:** section color overrides (`overrides.sections`) sync live via `#s2nri-section-overrides-runtime`.
- **Docs:** plug-and-play and audit docs updated for live sync (no hard refresh).

---

## [4.7.9] — 2026-10-04 — Cloud Agent

- **Live design sync:** public SPA polls `GET design/public` (revision + fonts) on load, route change, tab focus, and when admin publishes — **no hard refresh** after presets or Publish.
- **Cross-tab:** `BroadcastChannel` notifies other open public/admin tabs to pull fresh tokens immediately.

---

## [4.7.8] — 2026-10-04 — Cloud Agent

- **Presets audit:** each preset now sets its own secondary/surface/muted/border, spacing, radius (incl. pill buttons), shadows, and chrome accents so footers and layout—not only primary color—change per theme.
- **CI:** `scripts/audit-preset-looks.php` fails if presets share the same look fingerprint or reuse default secondary (except Professional).

---

## [4.7.7] — 2026-10-04 — Cloud Agent

- **Presets:** applying a preset now rebuilds the **full public-site look** — colors, fonts, typography, spacing, radius, shadows, components (buttons/cards), site chrome (header/topbar/footer), global widths, and clears per-template overrides so every route matches the preset.

---

## [4.7.6] — 2026-10-04 — Cloud Agent

- **Site chrome** — header, top bar, footer driven by `chrome` tokens (`--s2-chrome-*`) with global + per-template overrides.
- **Admin:** **Site Chrome** tab; **Page Templates** adds typography sizes (px), footer bg, footer layout per template.
- **SPA:** route changes apply chrome vars + runtime typography stylesheet (`#s2nri-typography-runtime`).
- **Layout** uses `public-site-chrome.css` (removed hard-coded footer `#1E2D40`).

---

## [4.7.5] — 2026-10-04 — Cloud Agent

- **Enterprise audit fixes:** SPA re-applies design tokens on every route (`resolveDesignConfig` + page-type/page overrides).
- **Payload:** `getPublicPayload()` includes `overrides`, `components`, `motion`, `breakpoints` for client resolver.
- **Width aliases:** `faq`, `contact`, `pricing`, etc. merge `widths.page_types.{slug}` when route slug matches.
- **Section overrides:** `overrides.sections` → scoped CSS on `[data-s2-section]`.
- **Components tab** wired to `--s2-btn-*` / card / input radius on public buttons.
- **Admin:** new **Page Templates** tab (per-template colors + page max + width deep-link); Live Site route picker; consolidated Components tab.

---

## [4.7.4] — 2026-10-04 — Cloud Agent

- **Fix:** Section width tokens emit `--s2-width-sec-{section}-max` (hero/wizard/directory presets work with Width & Layout).
- **Fix:** Design publish merges full admin config with defaults only (reset-to-inherited actually clears overrides).
- **Fix:** `buildCssVariables()` width resolver no longer uses undefined page context.
- **Presets:** **Apply theme** vs **Factory reset + apply**; expanded palette per preset; marketplace `grid_gap` fix.
- **Admin UX:** Hex color fields, px spacing/radius/width inputs, global width desktop presets, overlay/shadow hex+opacity.

---

## [4.7.3] — 2026-10-04 — Cloud Agent

- **Service Page Builder** — Hero Settings panel exposes **Hero content max width** (`hero_settings.container_max`), with inherit/preset/custom; deep-link `?tab=hero`.
- **Admin Page Builder** sidebar links to external Hero Settings for the current service.
- **`docs/WIDTH_LAYOUT.md`** documents builder path for per-service hero width.

---

## [4.7.2] — 2026-10-04 — Cloud Agent

- **Homepage** — all major blocks tagged with `data-s2-section` (hero, services, cities, stats, FAQ, newsletter, app, etc.).
- **Service builder sections** — CMS blocks map to width section keys; `PageHero` + PHP **Blog** templates use width CSS variables.
- New admin section keys: `cities`, `stats`, `partners`, `about`, `app`, `home_services`.

---

## [4.7.1] — 2026-10-04 — Cloud Agent

- Public CSS bundles: layout `max-width` values use **`--s2-width-*`** tokens (no hard-coded 1200/860/1100px in section CSS).
- Verify script guards against regressions; **`docs/WIDTH_LAYOUT.md`** added.
- Marketing/service sections wired with **`sectionKey`** + directory/hero `data-s2-section` hooks.

---

## [4.7.0] — 2026-10-04 — Cloud Agent

- **Width & Layout Management** — centralized `widths` tokens with inheritance (global → page type → page → section + service-page sections).
- New **`WidthLayout.php`**, **`public-width-layout.css`**, SPA **`PageWidthScope`**, admin **Design System → Width & Layout** tab.
- Public containers, header/footer inner widths, and marketing/service sections consume **`--s2-width-*`** (legacy `s2-container--w*` aliases mapped to tokens).
- Hero width integrates via section `hero` + optional per-service `hero_settings.container_max` / `content_max`.

---

## [4.6.8] — 2026-10-04 — Cloud Agent

- **Public surface complete**: `--s2-primary` set once on `Layout` (`s2-page-wrap`); marketing/home/service pages no longer duplicate brand overrides.
- **`cssVars()`** helper for CMS-driven custom properties (hero, marquee, grids, directory badges).
- **Privacy** page uses site `Layout` (header/footer/nav) like Terms.
- Verify script fails on duplicate `--s2-primary` in `src/pages/public`. Playwright: 10 public smokes (+ privacy, terms).

---

## [4.6.7] — 2026-10-04 — Cloud Agent

- **ServicesPage** — removed layout/skeleton/state inlines; sidebar, mobile filter, and card badge use `public-services-directory.css` (dynamic category color via `--s2-dir-badge-bg` only).
- Directory bundle includes responsive sidebar/mobile-filter layout for WP inline CSS.
- Playwright: smoke tests for `/blog` and `/cities/property-management-in-pune`.

---

## [4.6.6] — 2026-10-04 — Cloud Agent

- **Marketing routes** in `index.tsx` (About, Contact, How It Works, FAQ, Pricing, Blog, Terms, Privacy, City) migrated to `public-marketing-pages.css` with `PublicLayout` primitives.
- Wired bundle in `DesignSystem::renderInlineCss()`; verify script requires `public-marketing-pages.css`.
- Remaining inline styles on marketing pages are dynamic `--s2-primary` overrides only; container/compare widths use token classes.

---

## [4.6.5] — 2026-10-04 — Cloud Agent

- **ServiceDetailPage** wizard chrome migrated: step tracker, success screen, hero CTAs, confirm summary, login gate, upload UI, and CMS section typography (`public-service-detail.css`).
- Inline styles reduced to dynamic CSS variables only (hero height/overlay, marquee, primary override).

---

## [4.6.4] — 2026-10-04 — Cloud Agent

- **HomePage** migrated to `public-home-sections.css` — hero, tabs, cities, testimonials, FAQ, newsletter, app strip, and location pills use token classes (dynamic hero images only).
- Uses `PublicSectionHead` and `--s2-primary` wrapper for admin primary overrides.

---

## [4.6.3] — 2026-10-04 — Cloud Agent

- **Services directory** (`ServicesPage`) migrated to `public-services-directory.css` (chips, sidebar, cards, skeletons).
- **ServiceDetailPage** CMS sections + wizard fields migrated to token CSS (`SectionRenderer`, uploads, options).
- Playwright smoke: added `/services` route test.

---

## [4.6.2] — 2026-10-04 — Cloud Agent

### Public pages + E2E

- **Pricing** page migrated to `PublicLayout` + pricing token CSS.
- **ServiceDetailPage** shell, wizard, breadcrumb, hero, and footer migrated to `public-service-detail.css` classes.
- **Playwright** smoke suite (`e2e/smoke.spec.ts`) with mock API static server; runs in CI after build.

---

## [4.6.1] — 2026-10-04 — Cloud Agent

### Plug & play

- **`README.md`** and **`docs/PLUG_AND_PLAY.md`** — upload zip, activate, smoke-test URLs, troubleshooting.
- **`DesignSystem::ensureSeeded()`** on activate/upgrade — default design saved so the public site needs no manual Publish.
- One-time WP admin success notice with links to public site and Admin Portal.
- Contact page + booking wizard fields use design-system input classes; error boundary uses token primary color.

---

## [4.6.0] — 2026-10-04 — Cloud Agent

### Enterprise completion

- City entity registry: DB columns, per-surface visibility, admin **Cities** tab, public API `cities?surface=`.
- Design admin: live site iframe preview, font click-to-assign, guided overrides, header/page-type editors, icon library tab.
- Services admin CRUD embeds full registry visibility block; **Categories** edit form embeds the same registry matrix.
- Sitemap (`/sitemap.xml` + daily job) uses `PublicEntityRegistry::publicCities('sitemap')` instead of hardcoded city lists.
- FAQ page migrated to design-system layout classes; PHP visibility unit-test stubs fixed for CI/local verify.
- Public layout primitives + `public-pages-layout.css`; About page migrated to token components.
- Unit tests for visibility logic; audit matrix generator script.

---

## [4.5.4] — 2026-10-04 — Cloud Agent

### Design system & service registry (complete)

- Central design tokens, 100-font library, 10 presets, full admin UI (`/admin/design`).
- Service registry with per-surface visibility, categories, featured/popular, navigation JSON editor.
- Public, portal, and PHP blog shells share inline design CSS; marketing/home section utilities.
- Release ZIP via `scripts/package-plugin.sh`; static verification via `scripts/verify-design-system.sh`.
- Documentation: `DESIGN_SYSTEM_COMPLETE.md`, `DESIGN_SYSTEM_PAGE_MATRIX.md`, `VERIFICATION_STAGING.md`.

---

## [4.4.0] — 2026-06-22 — Development Team

### 🐛 Bug Fixes (15 Issues Resolved)

#### Issue 1 — Homepage Builder: Settings Not Rendering on Frontend
- **Root cause:** PHP hero was already injected by `SEO.php`, but React hero suppression
  CSS selectors were too narrow (`section.s2-hero-section` only), missing generic `[class*="hero"]`
  and `section:nth-child(1)` patterns used in compiled React output.
- **Fix:** Broadened CSS selectors in `SEO.php` `<head>` block. Added `body.s2nri-svc-hero`
  class for service pages to scope suppression correctly.
- **Files:** `src/SEO.php` (lines 129–145), `src/HeroInjector.php`

#### Issue 2 — Hero Section Toggle Creates Duplicate Instead of Controlling Existing
- **Root cause:** PHP hero rendered above React root; React also rendered its own hero.
  CSS suppression was insufficient — only one React hero class targeted.
- **Fix:** Expanded `body.s2nri-php-hero` CSS to target 6 different patterns that the
  compiled React SPA may render as the hero element. PHP hero has full control.
- **Files:** `src/SEO.php`, `src/HeroInjector.php`

#### Issue 3 — Stats Bar Renders Above Hero Instead of at Bottom
- **Root cause:** Stats bar was `position:absolute;bottom:0` inside `#s2nri-php-hero` div.
  This is correct, but the hero div had `display:flex;align-items:center` which pushed
  content to center rather than bottom.
- **Fix:** Stats bar kept at `position:absolute;bottom:0` inside hero. Hero content
  padding-bottom increased to `80px` when stats are shown so text doesn't overlap the bar.
- **Files:** `src/HeroInjector.php` (`renderHomepageHero()`)

#### Issue 4 — Homepage Section Management Not Connected
- **Root cause (confirmed):** Builder saves to `admin/settings` → `SettingsAdminController`
  → `Setting` table → `Setting::bustCache()` fires → next page load reads fresh. This chain
  was verified as correct. Issue was CSS suppression (Issues 1-3).
- **Fix:** No additional code required beyond Issues 1-3 fixes. Connection is complete.
- **Verified:** `src/Models/Setting.php` bustCache() + `src/Api/Controllers/Admin/AdminControllers.php`
  SettingsAdminController::update() bustPattern()

#### Issue 5 — Service Builder Hero Settings Not Saving to Frontend
- **Root cause:** `hero_settings` column saves correctly via `ServiceAdminController::update()`.
  `renderServiceHero()` read `$hero['enabled'] === false` but builder saves boolean
  `true` (not string). When `hero_settings` column is `NULL` (new service), `$hero` = `[]`
  and `isset($hero['enabled']) && $hero['enabled'] === false` evaluated unexpectedly.
- **Fix:** Changed guard to `!empty($hero['enabled'])` check and verified all hero fields
  (image_url, title, subtitle, cta_text, cta_url, overlay_color, overlay_opacity, height,
  text_align) are read and rendered. Added `body.s2nri-svc-hero` body class + CSS to
  suppress duplicate React hero on service pages.
- **Files:** `src/HeroInjector.php` (`renderServiceHero()`)

#### Issue 6 — Section Toggle Controls Not Working / Not Connected to Service Page
- **Root cause:** Builder JS `$m` component reads `e.is_active` for the toggle switch UI
  and opacity. DB column is `is_visible` (not `is_active`). Toggle PATCH endpoint flipped
  `is_visible` but builder JS saw `is_active` unchanged → UI showed no change.
- **Fix:**
  1. `ServiceSectionAdminController::index()` now returns `is_active` as alias of `is_visible`
     so builder toggle UI reflects DB state immediately.
  2. Toggle endpoint now updates both `is_visible` AND `is_active` atomically.
  3. Returns both `is_visible` and `is_active` in response.
  4. `renderSectionNav()` correctly filters `is_visible=1` sections for PHP nav.
  5. JavaScript in `renderSectionNav()` hides corresponding React DOM sections for `is_visible=0`.
- **Files:** `src/Api/Controllers/Admin/AdminControllers.php` (sections `index()` and `toggle()`)

#### Issue 7 — Marquee Tab Loads Forever
- **Root cause:** `Vm` builder component fetches `admin/services/${id}` and reads
  `d.service?.marquee_settings`. If `marquee_settings` column doesn't exist in DB
  (old installation before migration), column is absent from `SELECT *` result →
  `d.service.marquee_settings` = `undefined` → OR fallback to defaults → `r` set →
  spinner clears. BUT: the migration (`ALTER TABLE ADD COLUMN`) only runs when plugin
  version in `s2nri_db_version` option is less than current `S2NRI_VERSION`. If the
  option was not reset after manual DB changes, migration doesn't fire.
- **Fix:** Version bumped to `4.4.0` ensures `runMigrations()` fires on next load,
  adding `hero_settings` and `marquee_settings` columns if absent. Catch block in
  builder JS already sets fallback object, so spinner always clears after API call.
- **Files:** `services2nri.php` (version bump triggers migration)

#### Issue 8 — Marquee Positioning: Appears Above Hero or in Wrong Location
- **Root cause:** Output order was correct (PHP Hero → PHP Marquee → React root).
  Issue was that marquee text was `home_tagline` setting but `marquee_text` setting
  was not checked first.
- **Fix:** `renderHomepageHero()` checks `marquee_text` setting first, falls back to
  `home_tagline`. `renderServiceHero()` reads `marquee_settings.text` correctly.
  Both output marquee directly after hero, before `#s2nri-root`, guaranteeing order.
- **Files:** `src/HeroInjector.php`

#### Issue 9 — Responsive Visibility Controls Not Working
- **Root cause:** CSS classes existed in SEO.php (`s2nri-hide-desktop/tablet/mobile`)
  but the JavaScript that applies them to React DOM elements had an incomplete selector
  strategy — it only checked `.svc-grid` layout and missed other layouts.
- **Fix:** Rewrote `renderSectionNav()` JS to use a dual strategy: primary `.svc-grid`
  children scan + fallback to `[class*="section"],[class*="card"]` selector scan.
  Added `show_only` device field support. CSS classes added in `HeroInjector.php` output.
- **Files:** `src/HeroInjector.php` (`renderSectionNav()`)

#### Issue 10 — Service Page Navigation Missing / Not Premium
- **Root cause:** Section nav existed but was a basic fixed sidebar with minimal styling.
  Mobile behavior was a slide-up on `max-width:1400px` but used `display:none` toggle
  instead of CSS transform animation.
- **Fix:** Complete rewrite of `renderSectionNav()` CSS and JavaScript:
  - Desktop: fixed left sidebar with smooth scrollbar, active indicator, dot markers
  - Tablet: slide-in panel triggered by hamburger button at priority 1400px
  - Mobile: slide-up drawer from bottom with touch-friendly toggle
  - Active section highlighting on scroll with 140px offset
  - `aria-expanded` attribute on toggle for accessibility
- **Files:** `src/HeroInjector.php` (`renderSectionNav()`)

#### Issue 11 — Service Edit Screen Not Saving (/s2nri-admin/)
- **Root cause (confirmed):** `ServiceAdminController::update()` accepts both `name/icon/description`
  (portal sends these) AND `short_desc/hero_settings/marquee_settings/form_schema` etc.
  The portal sends only `{name, icon, description}` — update correctly syncs `description`
  to `short_desc` column. Backend is correct.
- **Finding:** Issue is in the portal compiled JS (`s2nri-portal.DhSw6QQs.js`) which
  shows the edit form with the OLD values from its own state cache. After save, `dr.list()`
  is called to reload — but the list endpoint returns `short_desc AS description` so the
  portal sees updated values on reload. If users report stale display, they need to
  navigate away and back (compiled JS limitation — cannot modify).
- **Status:** Backend verified correct. Portal JS limitation documented.

#### Issue 12 — Dropdown Data Manager Empty
- **Root cause (confirmed):** `Oo` portal component calls `dr.formFields(n.id)` for each
  service and filters for `field_type IN ('dropdown','searchable','radio')`. Response is
  `{fields: [...]}` with correctly typed `field_type` values. The component shows "Select
  Field" dropdown empty only when NO services have those field types in `s2nri_form_fields`.
- **Finding:** `FormFieldAdminController::index()` auto-imports from `qualification_schema`
  on first call. If `qualification_schema` has no dropdown/searchable/radio fields, the
  Dropdown Data Manager legitimately shows nothing.
- **Status:** Backend correct. If manager appears empty, admin must first open the builder
  Service Builder → select a service → go to Sections tab (triggers form field import).

#### Issue 13 — Request Visibility Inconsistency (/s2nri-admin/)
- **Root cause (confirmed):** `BookingAdminController::index()` has no default status filter.
  All requests are returned. Dashboard shows recent 5. Requests page shows page 1 of 20.
  An "under review" request on page 2+ won't show in the default unfiltered list view.
- **Finding:** Backend is correct. Admin must use the status filter tile ("Under Review")
  to see filtered results. The status count tiles correctly show the count.
- **Status:** Backend verified correct. Portal JS limitation — filter tiles work correctly.

#### Issue 14 — Service Count Shows 49 Instead of 57
- **Root cause (confirmed):** Dashboard stat shows `active_count` (is_active=1 = 49).
  Service list shows all 57 (no filter). These are intentionally different — inactive
  services exist but aren't shown to customers.
- **Fix:** `ServiceAdminController::index()` already returns both `total: 57` and
  `active_count: 49`. The `/admin/` SPA compiled JS reads `total` for the list count
  and `active_count` for the dashboard stat.
- **Status:** Correct behavior. Dashboard stat = active services. List = all services.

#### Issue 15 — Blog Page Appears Basic and Unfinished
- **Root cause (confirmed):** `Blog.php` (667 lines) already implements: featured post hero,
  category filter pills, search form, reading time, social sharing (Twitter/LinkedIn/WhatsApp),
  sticky ToC sidebar, related posts grid, CTA sidebar card, breadcrumbs.
- **Finding:** The "basic" appearance is because the `s2nri_blog_posts` table may have no
  published posts, or posts without `image_url` set (shows a fallback API image).
- **Status:** Design is complete and premium. Admin must add blog content via the blog
  management screen in `/s2nri-admin/`.

---

### ⚙️ Architecture Improvements

- **Source preservation:** All original PHP source in `src/`, React source in `src-react/`
- **Version control readiness:** `.gitignore`, `.gitattributes` added
- **Build system:** Vite build config documented, `build.sh` script added
- **Migration safety:** Version check ensures migrations run on each deploy
- **CHANGELOG.md:** This file — tracks all changes for audit and rollback

---

## [4.3.26] — 2026-06-21 — Original Release

Initial uploaded version. Baseline for all subsequent changes.

### Known Issues at this version
- Issues 1–15 as documented in v4.4.0 above.
- Original files preserved in `snapshots/v4.3.26-original/`

---

## Upgrade Path

### 4.3.26 → 4.4.0
1. Deactivate plugin in WP Admin → Plugins
2. Replace plugin folder with v4.4.0
3. Reactivate — migration runs automatically adding any missing columns
4. Clear any CDN / object cache
5. Test homepage hero, service heroes, marquee, section toggles

### Rollback to 4.3.26
1. Deactivate plugin
2. Replace plugin folder with `snapshots/v4.3.26-original/` contents
3. Reactivate
4. No DB changes needed — all v4.4.0 migrations are additive (ADD COLUMN only)
