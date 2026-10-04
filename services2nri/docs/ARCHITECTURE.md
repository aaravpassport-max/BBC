# Services2NRI Plugin — Architecture Reference

**Version:** 4.6.0
**Last Updated:** 2026-07-01
**Stack:** PHP 8.0+, React 18, WordPress 6.0+, MySQL 5.7+

---

## Overview

Services2NRI is a WordPress plugin that replaces the entire WordPress theme with a custom React SPA for the public-facing website, plus three additional SPAs for admin/builder/portal functions. WordPress is used only as a runtime container (hooks, DB access, REST API routing, auth cookies) — it renders no WordPress theme output.

---

## Four Dashboards

| URL | PHP Entry | JS Bundle | Purpose | Auth |
|-----|-----------|-----------|---------|------|
| `/` + `/service/*` + `/blog/*` | `SEO.php` | `assets/app.js` | Public website + React SPA | Public |
| `/admin/` | `SEO.php` → `app.js` | `assets/chunks/admin-DC3AMdvm.js` | Admin SPA (services, bookings, settings) | WP admin |
| `/s2nri-admin/` | `Portal.php` (ADMIN_SLUG) | `dist/assets/s2nri-portal.*.js` | Staff operations portal | Staff role |
| `/s2nri-builder/` | `BuilderPage.php` | `builder/s2nri-builder.js` | Homepage + Service + Form builder | WP admin |
| `/portal/` | `Portal.php` (CUSTOMER_SLUG) | `dist/assets/s2nri-portal.*.js` | Customer self-service portal | s2nri_customer |

> `/s2nri-admin/` and `/portal/` share the same compiled JS file. `Portal.php` passes
> `window.S2NRI_CFG.isAdmin = true/false` and `role` to switch modes.

### Cross-dashboard navigation (added 2026-07-01)

`s2nri-admin-bar` (a separate must-run plugin, `wp-content/plugins/s2nri-admin-bar/`)
injects a persistent dropdown into every page for admin/manager/agent roles,
via the same output-buffer HTML-rewrite technique used elsewhere in this doc
(see Pitfall #6 below for why that technique has to be done carefully). Its
menu links to `/admin/*` sub-pages, `/s2nri-builder`, and `/s2nri-admin` —
letting staff move between all three admin surfaces without hunting for
URLs. **It explicitly excludes `/portal/*` from rendering at all** (checked
by URL prefix, not just role) — clients must never see or reach any admin
link, which is enforced at the injection layer, not just via role-gating.

### Shared design tokens (added 2026-07-01)

Previously the Builder (`src-react/`) had its own hardcoded brand color
(`--brand: #0d7ab5`), completely disconnected from the site's actual
admin-configured `primary_color` setting, AND `global.css`/`SEO.php` on the
main app defined their own separate `--s2-primary` tokens — two unrelated
naming schemes, two places a color could drift out of sync, zero shared
source.

Fixed with one canonical file, `shared/design-tokens.css`, imported by both
`src/styles/global.css` and `src-react/src/styles/global.css` (Vite resolves
plain relative-path `@import`s across directory boundaries fine — verified
by actually rebuilding both projects and grepping the compiled CSS, not
assumed). The Builder's own `--brand` now aliases the canonical
`--s2-primary` (`--brand: var(--s2-primary, #0d7ab5)`) instead of
redefining it — every one of the 18 existing `var(--brand)` call sites
across `HomepageBuilder`/`ServiceBuilder`/`FormBuilder` updates
automatically, with zero changes to any of that component code.
`BuilderPage.php` and `SEO.php` now both inject the same variable name,
`--s2-primary`, from the same setting — one convention instead of two.

### Mobile bottom navigation (added 2026-07-01)

`src/components/layout/BottomNav.tsx` — a fixed app-style tab bar (Home /
Services / Bookings / Account), rendered from `Layout.tsx` (public + auth
pages) and `SidebarLayout.tsx` (customer dashboard only — never for staff,
who keep the desktop sidebar). Visible only ≤768px via `.s2-bottom-nav` in
`global.css`. This is currently the only piece of genuinely shared UI
between the public app and the customer portal — see "Unified Architecture
Status" near the end of this document for what is and isn't shared yet.

---

## Request Flow

```
Browser request
    │
    ├── /wp-admin/* /wp-json/* → WordPress normal routing
    │
    ├── /portal/* /s2nri-admin/* → Portal::boot() → renders HTML shell
    │                              → dist/assets/s2nri-portal.*.js hydrates
    │
    ├── /s2nri-builder/* → BuilderPage::render() → renders HTML shell
    │                      → builder/s2nri-builder.js hydrates
    │
    └── /* (everything else) → SEO.php::render()
            │
            ├── /blog/* → Blog::render() (full PHP-rendered pages, no React)
            │
            ├── / → HeroInjector::renderHomepageHero() → outputs PHP hero
            │       → #s2nri-root → app.js hydrates React SPA
            │
            ├── /service/:slug → HeroInjector::renderServiceHero()
            │                  → HeroInjector::renderSectionNav()
            │                  → #s2nri-root → app.js hydrates React SPA
            │
            └── /services /about /contact etc → #s2nri-root → app.js
```

---

## API Architecture

All API calls go through WordPress REST API at `/wp-json/s2nri/v1/`.

`Bootstrap::registerRestRoutes()` registers a wildcard route `s2nri/v1/{path}` that
forwards all requests to `Dispatcher::dispatch()`.

### Dispatcher

`src/Api/Dispatcher.php` — maps `METHOD path` strings to controller classes.

```php
$this->add('GET',  'admin/services',         ServiceAdminController::class, 'index',  true);
$this->add('PUT',  'admin/services/{id}',    ServiceAdminController::class, 'update', true);
$this->add('PATCH','admin/services/{id}/sections/{section_id}/toggle',
                   ServiceSectionAdminController::class, 'toggle', true);
```

`true` = protected route (requires staff auth). `false` = public.

### Authentication

`src/Api/Middleware/Auth.php` — 4-method auth chain:
1. Portal session token (`X-S2NRI-Token` header or `_s2nri_token` URL param)
2. WP nonce (`X-WP-Nonce: wp_rest` or `X-WP-Nonce: s2nri_api`)
3. WP logged-in cookie (`is_user_logged_in()`)
4. Raw WP auth cookie validation

### Controllers

All controllers extend `BaseController` which provides:
- `$this->requireStaff()` — super_admin / manager / agent / finance / wp administrator
- `$this->requireManager()` — super_admin / manager / wp administrator
- `$this->requireAdmin()` — super_admin / wp administrator
- `$this->user` — authenticated user array or null

---

## Database Schema

Tables are prefixed with `{$wpdb->prefix}s2nri_`.

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `s2nri_services` | Service catalog | id, name, slug, hero_settings, marquee_settings, is_active |
| `s2nri_service_sections` | Per-service page sections | id, service_id, type, title, content (JSON), is_visible, sort_order |
| `s2nri_categories` | Service categories | id, name, icon, color, sort_order |
| `s2nri_bookings` | Customer bookings/requests | id, booking_ref, status, secondary_status, service_id, customer_id |
| `s2nri_customers` | Customer profiles | id, wp_user_id, phone, country |
| `s2nri_settings` | Key-value settings store | setting_key, setting_value, is_public |
| `s2nri_blog_posts` | Blog content | id, title, slug, content, excerpt, category, image_url, is_published |
| `s2nri_form_fields` | Service intake form fields | id, service_id, field_key, field_type, label, options (JSON) |
| `s2nri_form_field_options` | Dropdown/radio options | id, field_id, option_value |
| `s2nri_messages` | Booking messages/chat | id, booking_id, sender_id, sender_type, message |
| `s2nri_documents` | Uploaded documents | id, booking_id, doc_type, file_url, is_from_staff |
| `s2nri_payments` | Payment records | id, booking_id, razorpay_order_id, amount, status |
| `s2nri_staff` | Staff members | id, wp_user_id, is_active |
| `s2nri_tickets` | Support tickets | id, booking_id, category, status |

### Key column notes
- `hero_settings` (LONGTEXT, JSON): `{enabled, image_url, title, subtitle, cta_text, cta_url, overlay_color, overlay_opacity, height, text_align}`
- `marquee_settings` (LONGTEXT, JSON): `{enabled, text, speed, bg_color, text_color, pause_hover}`
- `is_visible` vs `is_active` in `s2nri_service_sections`: both are synonymous (1=shown). `is_visible` is the source of truth; `is_active` is returned as an alias for backwards compatibility with the builder JS.

---

## Settings Architecture

Settings are stored in `s2nri_settings` as key-value pairs.

**Public settings** (`is_public=1`) are exposed via `S2NRI_CONFIG` JavaScript variable on
every page and read by `Setting::getPublic()`.

**Builder saves settings:** `PUT admin/settings` → `SettingsAdminController::update()` →
batch `INSERT ... ON DUPLICATE KEY UPDATE` → `Setting::bustCache()` + `CacheService::bustPattern('settings_')`.

**SEO.php reads:** `Bootstrap::getJsConfig()` → `Setting::getPublic()` → `$config['settings']` →
`$settings_flat` → passed to `HeroInjector::renderHomepageHero()`.

### Key settings used by Homepage Builder
```
hero_heading_1, hero_heading_2, hero_subheading, hero_description
hero_banners        — comma-separated background image URLs
hero_cta_text/url   — primary CTA button
hero_cta2_text/url  — secondary CTA button
hero_overlay_color/opacity
hero_show           — '0' or '1'
hero_show_stats     — '0' or '1'
stat_1_number through stat_4_label
home_tagline        — marquee/tagline strip text
marquee_text        — alternative marquee text (checked first)
marquee_show/speed/bg/color/pause_hover
custom_css_homepage — injected in <head> on homepage only
custom_css_global   — injected in <head> on all pages
css_hero_minheight/textcolor/bg/padding
```

---

## PHP Hero System

The PHP hero system lets the builder control hero sections without recompiling JS.

### Homepage Flow
```
Builder (/s2nri-builder/) saves hero_* settings
    → PUT admin/settings
    → s2nri_settings table
    → Setting::bustCache()
    ↓
Next public page request → SEO.php::render()
    → $settings_flat from buildMeta()
    → body.s2nri-php-hero class added to <body>
    → HeroInjector::renderHomepageHero($settings_flat)
    → Outputs PHP hero HTML above #s2nri-root
    → CSS in <head> hides React hero: body.s2nri-php-hero [class*="hero"]:first-of-type
    → React SPA mounts inside #s2nri-root (hero section hidden)
```

### Service Page Flow
```
Builder saves hero_settings + marquee_settings via PUT admin/services/{id}
    → ServiceAdminController::update()
    → hero_settings column (JSON) in s2nri_services
    ↓
/service/:slug request → SEO.php::render()
    → body.s2nri-svc-hero class added
    → HeroInjector::renderServiceHero($slug, $settings_flat)
    → HeroInjector::renderSectionNav($slug, $settings_flat)
    → React SPA mounts, section nav JS injects IDs into React DOM
```

---

## Builder React Source (`src-react/`)

The builder SPA is a Vite + React 18 application compiled to `builder/s2nri-builder.js`.

### Build
```bash
cd src-react
npm install
npm run build   # outputs to builder/
```

### Pages
- `HomepageBuilder/index.jsx` — 3-panel layout: section list + controls + live preview iframe
- `ServiceBuilder/index.jsx` — service list + tabs (Sections / Hero / Marquee / Nav)
- `FormBuilder/index.jsx` — drag-and-drop form field editor with step management
- `StatusManager/index.jsx` — booking status configuration

### Key Components
- `components/common/index.jsx` — Button, Modal, FormGroup, Alert, Spinner, StatusBadge
- `hooks/useApi.js` — `useData(endpoint)` hook with loading/error/reload
- `hooks/useToast.js` — toast notification system
- `utils/api.js` — fetch wrapper reading `window.S2NRI_BUILDER` config
- `utils/statuses.js` — status color/icon/label maps

---

## Compiled Assets (Do Not Edit Directly)

| File | Source | Edit via |
|------|--------|---------|
| `builder/s2nri-builder.js` | `src-react/` | `npm run build` in `src-react/` |
| `builder/s2nri-builder.css` | `src-react/` | `npm run build` in `src-react/` |
| `assets/app.js`, `assets/app.css`, `assets/chunks/*.js` | **`src/` (root-level: `src/App.tsx`, `src/pages/`, `src/components/`, `src/styles/global.css`)** | `npm install && npx vite build` in the plugin root (uses root `vite.config.ts` / `package.json`) |
| `dist/assets/s2nri-portal.*.js` | Unknown (pre-compiled) — serves both `/s2nri-admin/` and `/portal/` | Unknown — preserve as-is |

> ⚠️ **Corrected 2026-07-01, previously wrong in this doc:** `assets/app.js` was
> long assumed to have no buildable source ("source unknown — preserve"). This
> was FALSE and caused real wasted effort before it was caught. The root
> `vite.config.ts` builds `src/main.tsx` → `assets/app.js` + `assets/chunks/*.js`
> + `assets/app.css`, and a clean rebuild reproduces the live bundle almost
> byte-for-byte (verified: app.css matched exactly, app.js within ~2%, likely
> explained by small hand-patches made directly to the live bundle over time
> that were never backported to `src/`). **Before assuming any compiled asset
> has "no source," actually look for a root-level `vite.config.ts` / `package.json`
> first** — do not trust a stale doc comment or a first-glance directory scan.
> The ONLY genuinely sourceless bundle in this plugin is
> `dist/assets/s2nri-portal.*.js` (serves `/s2nri-admin/` + `/portal/`).

---

## Migration System

Migrations run automatically when `S2NRI_VERSION` > `s2nri_db_version` option.

`services2nri.php` bootstrap:
```php
if ( version_compare( get_option('s2nri_db_version', '0.0.0'), S2NRI_VERSION, '<' ) ) {
    Installer::runMigrations();
    update_option('s2nri_db_version', S2NRI_VERSION);
}
```

All migrations in `Installer::runMigrations()` use `IF NOT EXISTS` / `IF EXISTS` guards
so they are safe to run multiple times (idempotent).

---

## ⚠️ Known Pitfalls & Debugging Playbook

Every item below is a **real, confirmed bug found and fixed** in this codebase,
not a theoretical concern. Each cost real debugging time before the root
cause was found. Read this before chasing a "why doesn't my change show up"
or "why is this still broken on mobile" report.

### 1. Two stylesheets, both loaded, cascade order matters more than usual

`assets/app.css` (compiled from `src/styles/global.css`) loads **first**.
`assets/s2nri-theme.css` (hand-edited, NOT compiled, holds the "Homepage
Builder Target Selectors" the admin's visual builder targets) loads
**second** — see the `<link>` order in `SEO.php`.

**Confirmed failure pattern (hit 3 separate times in one session):** a class
defined in both files, with the later file's rule NOT using `!important`,
silently loses to whichever rule the later file's cascade resolves to —
even if the earlier file's media query is objectively "more correct." A CSS
fix can be 100% correctly written and still visibly do nothing, because a
same-specificity rule in the other file wins by load order alone.

**Rule going forward:** if a class name might exist in both `global.css` and
`s2nri-theme.css`, either (a) grep both files before touching either, or
(b) just add `!important` defensively, or (c) best — pick ONE file as the
single source of truth for that class and delete the other definition
entirely (done for `.s2-svc-grid`, kept only in `s2nri-theme.css`).

### 2. Inline `style={{ gridTemplateColumns: ... }}` cannot be fixed by CSS alone

React inline styles beat any ordinary CSS rule regardless of media query.
13 instances of this were found across `HomePage.tsx`, `ServiceDetailPage.tsx`,
`index.tsx`, and `Layout.tsx` (footer) — all fixed-column grids with no
mobile fallback, silently unfixable by CSS without `!important`. Fixed via
a shared `.s2-mobile-stack` utility class (`!important`, single column
≤768px) applied at each call site. **Any future inline `gridTemplateColumns`
with a fixed column count needs this same treatment, or it WILL break on
mobile no matter what `global.css` says.**

### 3. Chunk files had no cache-busting — `SEO.php`'s import map

`app.js`, `app.css`, and `modulepreload` links all get a `?v=<filemtime>`
query string. The `<script type="importmap">` entries that the browser
*actually* uses to resolve `import("./chunks/booking.js")` at runtime did
NOT — meaning a chunk file could be replaced on the server and browsers/CDN
had zero signal to ever re-fetch it. Fixed by giving the import map entries
the same `?v=` as everything else. If you change any file under
`assets/chunks/` and it doesn't seem to reach visitors, check this first.

### 4. Service worker could silently freeze the site in time

`Installer.php` writes a static `sw.js` to the WordPress **root** (not the
plugin folder) at install time, via `@file_put_contents()` with the error
suppressed and the return value never checked. On hosts where the web root
isn't writable (confirmed on this exact site — see recurring
`file_put_contents(...): Permission denied` entries for `.htaccess` in
`debug.log`), this write fails silently, `sw.js` never gets created, and
`/sw.js` requests fall through to `PWA::serveServiceWorker()`'s PHP fallback
— which used to be a cache-first strategy with no expiry. A visitor's browser
could get stuck serving stale JS/CSS forever, immune to Cloudflare purges,
immune to cache-busting query strings, immune to every server-side fix,
because the service worker never even asks the network. Fixed three ways:
(a) `assets/sw.js` now exists as a real, safe, network-first, self-cleaning
file — single source of truth for both the static writer and the PHP
fallback; (b) `Installer.php` now checks the write's return value and logs
on failure instead of pretending success; (c) `SEO.php` no longer registers
a service worker at all — instead it actively unregisters any existing one
and clears its caches on every page load, so this bug class can't recur
even if a stale worker is already installed in someone's browser.
**If "my fix isn't showing up" survives a Cloudflare purge AND an incognito
test, suspect the service worker next, before assuming the code is wrong.**

### 5. Cloudflare sits in front of this site

Confirmed via response headers and a comment already in `SEO.php`. The HTML
page itself is served with `Cache-Control: no-store` specifically to stop
Cloudflare caching stale settings. Static assets (JS/CSS) rely on the `?v=`
query-string mechanism above instead. When debugging "changes don't show
up," the checklist is: (1) confirm the fix is actually in the deployed
files, (2) purge Cloudflare, (3) test in incognito to rule out local browser
cache and service worker state — in that order, not skipped.

### 6. `s2ab_run_expensive_yp_scan()` and nested `ob_start()`

Historical, already fixed — kept here because the failure mode is worth
knowing about if it's ever reintroduced. PHP forbids calling `ob_start()`
from inside another buffer's flush callback. `s2nri-admin-bar.php` wraps
every page in an output buffer; a nested call to fetch Yellow Pencil CSS
used to call `ob_start()` again from inside that buffer's own flush handler,
causing an intermittent fatal (only when a 60-second transient cache had
expired) — the original cause of the "homepage goes white sometimes" report.
Fixed by precomputing the CSS *before* the outer `ob_start()` call, not
during its flush.

---

## Roles & Capabilities

| Role slug | Capabilities | Used for |
|-----------|-------------|---------|
| `super_admin` | Full access | Site owner |
| `manager` | All admin ops | Senior staff |
| `agent` | Bookings + messages | Support staff |
| `finance` | Payments + reports | Finance team |
| `s2nri_customer` | Own bookings only | End users |
| `administrator` | Treated as super_admin | WP administrators |

Custom capabilities registered via `Bootstrap::registerRoles()`.

---

## Unified Architecture Status (as of 2026-07-01)

The four dashboards are conceptually one platform (shared database, shared
REST API, shared auth chain, shared design tokens in principle), but they
are **not yet one codebase**, and it would be dishonest to claim otherwise.
Concrete status, so this doesn't get re-litigated from a false starting
assumption later:

**Already shared / unified:**
- Database (`s2nri_*` tables), REST API (`/wp-json/s2nri/v1/`), and the
  4-method auth chain — genuinely one backend for all four dashboards.
- Role/capability system (`Bootstrap::registerRoles()`) — same roles checked
  everywhere, no per-dashboard duplicate auth logic.
- The public app + `/admin/*` + `/dashboard/*` (customer) already **are**
  one real codebase — same `src/`, same `App.tsx` router, same `Layout.tsx`,
  same component library, same CSS. This is not a separate app pretending
  to be unified; it compiles from one `npm run build`.
- Cross-dashboard navigation between the three admin surfaces (this
  session's addition, see above).

**Not shared — and why, concretely, not vaguely:**
- `/s2nri-admin/` + `/portal/` run on `dist/assets/s2nri-portal.*.js`, a
  **separate compiled bundle with no known source in this repository.**
  It cannot share components, design tokens, or conventions with `src/`
  through normal code-sharing (imports, shared component libraries) because
  there is nothing to import from — only compiled, minified output exists.
  Any "unification" of this dashboard today can only happen at the level
  this document itself uses: consistent PHP-side conventions (auth
  middleware, REST endpoints, role checks) and visual consistency (same
  color tokens, same fonts) achieved by eyeballing the two apps side by
  side, not by shared code.
- `src-react/` (the Builder, at `/s2nri-builder/`) is a **separate Vite
  project** with its own `package.json`, own component library
  (`src-react/src/components/common/`), own API client
  (`src-react/src/utils/api.js`), and own hooks — parallel to, not shared
  with, `src/`'s equivalents. Nothing technically prevents merging these
  into one monorepo/shared package, but nothing has done so yet.

**What real unification would actually require** (roadmap, not done):
1. Recover or rebuild source for `dist/assets/s2nri-portal.*.js` — until
   this exists, that dashboard can't share React code with anything, full
   stop. This is the single biggest blocker.
2. Extract `src/components/`, `src/lib/api.ts`, and design tokens
   (`src/styles/global.css` custom properties) into a shared package
   `src-react/` and any future portal source could both import, instead of
   three independent component libraries.
3. Standardize on one auth/API client pattern across all three source trees
   (currently: `src/lib/api.ts` vs `src-react/src/utils/api.js` — two
   different fetch wrappers doing conceptually the same job).
4. Only after 1–3: a shared design system (buttons, cards, form inputs) used
   identically across all four surfaces, instead of each reimplementing
   inline styles.

None of the above is started. Item 1 has to come before 2–4 can mean
anything for the portal/admin dashboard specifically. This section exists so
the next person (human or AI) reading this document knows exactly how far
"unified" actually goes today, instead of assuming from the word "platform"
that it's further along than it is.

## Unification Roadmap — Phase by Phase

Sequenced by dependency and risk, not by ambition. Each phase is meant to be
independently verifiable (buildable, testable) before the next one starts —
none of this should ever be a single unreviewed sweeping change across two
live codebases.

**Phase 0 — Documentation + cross-dashboard nav.** ✅ Done (2026-07-01).
This document, the Known Pitfalls playbook, and `s2nri-admin-bar`'s links
between `/admin/`, `/s2nri-admin/`, `/s2nri-builder/` (never `/portal/`).

**Phase 1 — Shared design tokens.** ✅ Done (2026-07-01).
`shared/design-tokens.css` is now the single canonical source for
`--s2-primary` and friends, imported by both `src/styles/global.css` and
`src-react/src/styles/global.css`. The Builder's own `--brand` token now
aliases `--s2-primary` (`--brand: var(--s2-primary, #0d7ab5)`) instead of a
disconnected hardcoded value — zero changes to any of the 18 existing
`var(--brand)` call sites. `BuilderPage.php` and `SEO.php` both now set the
same variable name (`--s2-primary`) from the same setting
(`primary_color`), the first time both PHP shells share one convention
instead of two. **Verified, not assumed:** both projects were actually
rebuilt (`vite build` succeeded in both `plugin-fixed/` and
`plugin-fixed/src-react/`) and the compiled CSS was grepped to confirm the
alias chain resolves correctly.

**Phase 2 — Shared API client conventions.** ✅ Done (2026-07-01).
`shared/api-core.js` now holds the low-level fetch plumbing (URL building,
standard headers, fetch call, JSON parsing) that was genuinely identical
between `src/lib/api.ts` and `src-react/src/utils/api.js` — both now call
it internally. Deliberately did NOT unify the parts that differ for real
reasons: token resolution strategy (main app has 3-tier fallback + caching
+ localStorage persistence; Builder doesn't need this, it's WP-admin/staff
context only), error shape (`{message, fields, status}` object vs plain
`Error`), and 401 session-expiry handling (main app only). One genuine
behavioral difference was caught before it could be silently merged away:
the two projects disagreed on when to set `Content-Type` for non-upload
requests (unconditionally vs. only when a body is present) — preserved as
an explicit `alwaysJsonContentType` parameter rather than picking one
behavior for both. **Verified, not assumed:** both projects rebuilt
successfully (`tsc --noEmit` clean, both `vite build`s succeeded), and the
compiled output was grepped for behavior-specific strings (the main app's
401 message, the Builder's plain-Error message) to confirm each project's
distinct behavior survived the refactor intact.

**Phase 3 — Shared component primitives.** Evaluated 2026-07-01, **deliberately
deferred by decision, not skipped by neglect.**

Investigated the first and smallest candidate, `Button`, before touching
anything: `src/components/ui/index.tsx`'s version and
`src-react/src/components/common/index.jsx`'s version turned out to be
architecturally different, not just stylistically different —

- Main app: inline styles computed in JS, reads live `accent_color` from
  the Zustand store at render time (dynamic per-render theming), variants
  `primary/secondary/danger/ghost/accent`, no `size` or `icon` prop.
- Builder: pure CSS classes (`.btn`, `.btn-primary`, etc.), static theming
  via the CSS variables Phase 1 already unified, has `size` and `icon`
  props the main app's version doesn't.

59 total call sites across both codebases (32 + 27), with two genuinely
different prop APIs. Merging means picking a theming approach to
standardize on (CSS classes vs. inline JS) and either porting the dynamic
`accent_color` theming into CSS variables (if standardizing on CSS
classes) or reimplementing the `size`/`icon` variant system in inline
styles (if standardizing on JS) — real design work, not mechanical
deduplication, and wrong on either side risks a visual regression across
dozens of call sites at once.

**Decision: do not merge Button (or, by extension, Modal/Alert/Spinner —
same pattern expected). Leave both component libraries as they are.**
Phase 1 (shared color tokens) already closed most of the *visual*
inconsistency between them without touching component code at all — the
remaining gap is now mostly at the code-sharing level, not something a
user looking at either dashboard would actually notice. Revisit only if a
future concrete need (e.g. a genuinely new dashboard, or Phase 5's monorepo
restructure) makes the cost of staying separate outweigh the cost of a
careful one-component-at-a-time migration.

**Phase 4 — Recover source for the sourceless portal bundle.** Started
2026-07-01, genuinely partial, not claimed as more than it is.

Confirmed `dist/assets/s2nri-portal.v3.js` has **no source map**
(`//# sourceMappingURL=` absent) — recovery means manual work, no shortcut.
Two things exist now, in `docs/portal-recovery/`:

- `portal-beautified.js` — the same 504KB bundle, mechanically re-indented
  (via `js-beautify`) into ~28,000 readable lines. Variable/function names
  are still the original minified ones — this makes the file *readable*,
  not *understood*.
- `PORTAL_ROUTE_MAP.md` — every route for both `/s2nri-admin/` and
  `/portal/` (19 routes total, both switch on the same `window.S2NRI_CFG.isAdmin`
  flag, confirmed by reading the actual router setup) mapped to its
  component and real purpose, with the exact on-screen text strings found
  inside each component as evidence — not inferred from minified names.

**What was deliberately NOT done, and why:** no variables were renamed, no
JSX was reconstructed, no component was verified against the live rendered
app (no source map + no staging environment to click through = no way to
confirm a rebuilt component is pixel-correct, and shipping an unverified
"reconstruction" as if it were real source would be worse than not having
one). Most importantly: **`dist/assets/s2nri-portal.v3.js` itself was never
touched** — this is a parallel reference, zero risk to what's currently
running, by construction.

This is real, usable progress (anyone — human or AI — working on this
dashboard next can jump straight to the right ~500-line block instead of
searching 28,000 lines blind) but it is NOT "Phase 4 done." A full
reconstruction — real names, real JSX, verified against the live app one
route at a time, starting with something self-contained like `analytics`
before attempting something stateful like the booking detail flow — remains
future work, ideally with a staging environment available to test against.

**Phase 5 — True shared package structure.** Only meaningful after Phase 4.
Once all three dashboards have real source, restructure into a monorepo
(or npm workspace) where `shared/` becomes a real importable package
(tokens, API client, and component primitives all in one place) rather
than the file-path-relative imports Phases 1–2 use as a pragmatic
stopgap. This is a build-tooling change, not a feature change — highest
total effort, lowest urgency, since Phases 1–3 already deliver most of the
practical benefit without it.
