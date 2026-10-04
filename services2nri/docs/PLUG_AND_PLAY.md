# Plug & play deployment guide

This document is the operator checklist for a **fresh WordPress install** with no manual SQL or code edits.

## What happens on activate

1. **Database** — all plugin tables created (`IF NOT EXISTS`).
2. **Seed data** — service categories, sample services, cities, forms, and platform settings (idempotent).
3. **Design system** — default preset saved to `design_system_v1` so public CSS variables match admin (4.6.1+).
4. **Rewrites** — `/`, `/services`, `/service/*`, `/s2nri-admin/*`, `/portal/*`, `/wp-json/s2nri/v1/*` registered and flushed.
5. **`.htaccess`** — API passthrough rule added when writable.
6. **Cron** — notifications, sitemap, cleanup jobs scheduled.

## Public URLs (after permalinks flush)

| URL | Purpose |
|-----|---------|
| `/` | Homepage |
| `/services` | Service directory |
| `/service/{slug}` | Service detail + booking wizard |
| `/contact`, `/faq`, `/pricing`, `/about` | Marketing pages |
| `/blog`, `/blog/{slug}` | Blog |
| `/cities/{slug}` | City landing pages |
| `/sitemap.xml` | Dynamic sitemap (registry-aware) |

## Staff URLs

| URL | Purpose |
|-----|---------|
| WP Admin → **Services2NRI** | Links into SPA with session token |
| `/s2nri-admin/` | Admin portal (bookings, services, design system) |
| `/portal/` | Customer portal entry |

## Post-install smoke test (2 minutes)

1. Open `/` — homepage loads with categories and services.
2. Open `/service/complete-property-management` (or any seeded slug) — wizard renders.
3. WP Admin → Services2NRI → Admin Portal — dashboard loads.
4. Admin → **Design System** — change primary color → Publish → switch to `/` (or wait ~45s) — buttons update via live sync (4.7.9+).
5. **Design System → Width & Layout** (4.7.0+) — adjust global/page/section max widths → Publish — public SPA picks up changes automatically.

Automated equivalent (no WordPress): from `services2nri/`, run `npm run build && npm run test:e2e` (10 Playwright smokes, 4.6.8+).

## Live WordPress checklist (operator)

Run on your host after uploading **`services2nri.zip`** (version **4.6.8+**):

| Step | URL / action | Pass criteria |
|------|----------------|---------------|
| A | `/` | Hero, categories, footer; no broken JS in browser console |
| B | `/services` | Directory search + category chips |
| C | `/service/{slug}` | Hero + `#booking-form` wizard |
| D | `/contact` | Form submits (row in admin or success message) |
| E | `/pricing`, `/faq`, `/blog` | Marketing pages styled (no unstyled blocks) |
| F | `/cities/property-management-in-pune` | City hero + service grid |
| G | Design System → Publish | Primary color visible on public tab without hard refresh (live sync) |

## Registry (visibility)

- **Design System → Service Registry** — global matrix for services, categories, cities.
- **Services** / **Categories** edit forms — same visibility block when editing an existing row.

Hidden services disappear from nav, search, forms, and sitemap per surface rules; direct URLs follow **Direct URL behavior**.

## Troubleshooting

### Permalink 404

1. Settings → Permalinks → Save (no change needed).
2. If still failing: deactivate plugin → activate again.

### Incomplete upload

Symptoms: white screen, missing JS. Fix: upload the official **`services2nri.zip`** from repo root; folder must be `wp-content/plugins/services2nri/` with `assets/app.js` present.

### PHP version

Plugin requires PHP 8.0+. Below 8.0 shows an admin notice and does not load.

### Email / contact form

Contact submissions are stored in `s2nri_contact_messages` before `wp_mail()`. Check admin if mail fails on shared hosting.

## Upgrades

Upload new zip over existing plugin folder (or use WP updater). Activation hook runs migrations. Compare `S2NRI_VERSION` in `services2nri.php` with **Plugins** list after upgrade.

## Uninstall

Deactivation clears cron. Uninstall optionally removes data per `uninstall.php` and site option `s2nri_delete_data_on_uninstall`.
