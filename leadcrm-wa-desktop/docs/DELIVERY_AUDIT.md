# Delivery audit — LeadCRM WA Desktop v1.0.5

**Product:** Standalone Windows app wrapping **leadcrm-wa-build** (WordPress plugin SPA at `/crm/`).  
**Not in scope:** The separate `leadcrm/` Next.js monorepo or release tag `leadcrm-desktop-v1.0.0`.

## Automated visual E2E (2026-10-21)

Command: `node scripts/e2e-visual-audit.mjs`

| Check | Result |
|-------|--------|
| WordPress + plugin first-run install | PASS |
| Desktop bootstrap → `/crm/` | PASS |
| CRM loads without wp-login | PASS (Dashboard — LeadCRM) |
| `wp-login.php` bypass on localhost | PASS |
| Sidebar (14 items) | PASS |
| All Leads, Pipeline, Settings, Add Lead UI | PASS |
| `admin-ajax.php` | PASS (heartbeat 400 — expected without full WP heartbeat payload) |
| Browser console | PASS (no critical errors) |

Screenshot set: `01-after-bootstrap` through `07-add-lead-form` (see CI artifact dir or `/opt/cursor/artifacts/leadcrm-wa-audit/` on audit runners).

## What the audit proves

- Same plugin UI as the zip build: dashboard KPIs, leads grid, pipeline kanban, settings pipeline stages, add-lead modal (including WhatsApp tab).
- Desktop session hooks (mu-plugin + bootstrap) deliver authenticated CRM without showing WordPress login in the happy path.

## What still requires Windows confirmation

- NSIS install, MariaDB/PHP bundled binaries, splash → main window, and `%APPDATA%` data paths (see [TESTING.md](./TESTING.md)).
- Recommended release: **`leadcrm-wa-v1.0.5`** (`LeadCRM-WA-Setup-1.0.5.exe`).

## If something fails on Windows

Collect `%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA\logs\startup.log` and `LOGIN.txt`.
