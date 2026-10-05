# Enterprise CMS — phased delivery plan

## Phase 1 — Catalog ↔ frontend sync (done v4.7.70)
- Strict audit for all catalog setting keys
- Elements tab + element tree inspector
- Blog, services, home grids, marquee, footer, legal SEO

## Phase 2 — Marketing page micro-copy (v4.7.71)
- Contact form labels, placeholders, hours, success/error copy
- Pricing consultation band + compare brand labels
- How-it-works hero meta chips
- Service wizard tags, mobile CTA, upload hint, why-choose fallback JSON

## Phase 3 — Per-element visibility (v4.7.71+)
- `hide_el_{page}_{section}_{element}` toggles in Elements tab
- `CmsElement` wrappers on high-traffic blocks (home hero, blog hero)

## Phase 4 — Directory & pricing JSON (v4.7.72)
- Services directory search placeholder CMS key
- Pricing brand comparison rows JSON
- Home notice / search `CmsElement` markers

## Phase 5 — Service detail element packs (v4.7.73)
- `section-element-presets.ts` + registry hints in Elements tab
- Service hero / wizard / marquee `CmsElement` markers; platform hero chip defaults
- E2E: `hide_el_*` + service `data-s2-element` assertions

## Phase 6 — Chrome & layout CMS (v4.7.74)
- Footer quick links + location links JSON
- Site header CMS labels (WhatsApp, service request, sign in, top bar auth)
- Home hero + features `CmsElement` markers

## Phase 7 — Navigation CMS (v4.7.75)
- `nav_menu_json` overrides API/static mega-menu
- Mega-menu hint + view-all copy; About/Contact labels; mobile drawer JSON
- Home stats/testimonials/process/faq/newsletter element markers

## Phase 8 — Remaining home bands + matrix e2e (v4.7.76)
- CmsElement on tagline, press, partners, about, awards, app, locations
- `e2e/design-cms-matrix.spec.ts` (home bands + pricing/faq/services heroes + hide_el newsletter)

## Phase 9 — CI hardening (v4.7.77)
- CMS e2e suites wired into `scripts/verify-design-system.sh` (skip with `SKIP_CMS_E2E=1`)
- Home services + cities grid `CmsElement` markers; matrix cases for both bands

## Phase 10 — Admin save → public e2e (v4.7.78)
- Mock API persists `PUT admin/settings` → `GET settings/public`
- E2E shell loads public settings before app boot
- `design-cms-admin-save.spec.ts` (hero + newsletter from Design System)

## Phase 11 — Live preview iframe (removed v4.7.80)
- Was: embedded preview + draft postMessage (shipped v4.7.79). Removed per product choice; use **Preview page ↗** in the builder header.

## Phase 12 — Element styler (v4.7.81)
- Per-element design tokens under `overrides.sections.{band}.elements.{elementId}`
- Elements tab **Design** controls (color, type, spacing, borders) with inherit/clear UX
- Public CSS via `data-s2-element` selectors (PHP + SPA live sync)

## Phase 13 — (next)
