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

## Phase 4 — Service detail element packs (next)
- Catalog element defs per service section band
- Platform defaults when CMS section rows empty
- Playwright: change setting → assert DOM

## Phase 5 — Chrome & layout CMS (next)
- Footer column titles/links JSON
- Header mega-menu structure editor sync

## Phase 6 — CI hardening (ongoing)
- `audit-design-cms-sync.mjs --strict` in verify-design-system.sh
- E2E design-cms-content.spec.ts on mock settings
