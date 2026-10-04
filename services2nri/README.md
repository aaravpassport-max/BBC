# Services2NRI — WordPress plugin (plug & play)

Production-ready NRI service marketplace: public site, booking wizards, customer portal, staff admin, design system, and service visibility registry.

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| MySQL / MariaDB | 5.7+ |
| Permalinks | **Post name** (recommended) |

## Install (5 minutes)

1. Download **`services2nri.zip`** from the repository root — **full source** (PHP + TS/React + docs + prebuilt `assets/`). See `docs/SOURCE_PACKAGE.md`. Build with `bash scripts/package-plugin.sh` from this folder.
2. WordPress → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. On activation the plugin creates tables, seeds categories/services, registers rewrite rules, and applies the default design system.
4. Visit your site **home URL** — the public SPA loads at `/` (and `/services`, `/service/{slug}`, etc.).
5. WordPress admin → **Services2NRI → Admin Portal** — staff dashboard at `/s2nri-admin/` (token link from WP menu).

### First-time admin

- Default staff login uses your WordPress administrator account when opening the portal from WP Admin.
- Optional: **Settings → Platform** for name, WhatsApp, email, and legacy colors.
- **Design System** (`/admin/design`) — change fonts/colors and **Publish** (pre-seeded on first activate in 4.6.1+). Public marketing pages use centralized CSS bundles (4.6.8+).

## Verify

```bash
cd services2nri
bash scripts/verify-design-system.sh
```

On staging, run the checklist in `docs/VERIFICATION_STAGING.md`.

## Documentation

| Doc | Purpose |
|-----|---------|
| `docs/WIDTH_LAYOUT.md` | Centralized width tokens & admin Width & Layout tab (4.7+) |
| `docs/PLUG_AND_PLAY.md` | Full deployment, URLs, troubleshooting |
| `docs/DESIGN_SYSTEM_COMPLETE.md` | Design + registry sign-off |
| `docs/VERIFICATION_STAGING.md` | Registry scenarios A–G |

## Build from source (optional)

```bash
cd services2nri
npm ci
npm run build
bash scripts/package-plugin.sh
```

Output: `../services2nri.zip` (source included; `node_modules` excluded — run `npm ci` to develop)

## Support paths

- **404 on /services or /s2nri-admin/** — re-save **Settings → Permalinks**, or deactivate/reactivate the plugin.
- **Blank admin portal** — confirm `assets/app.js` exists in the uploaded zip (use official `services2nri.zip`, not a partial copy).
- **API errors** — REST base is `/wp-json/s2nri/v1/`; ensure no security plugin blocks anonymous REST for public routes.

Version is defined in `services2nri.php` (`S2NRI_VERSION`).
