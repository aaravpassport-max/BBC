# LeadCRM Desktop — Testing Report

**Build:** 1.0.0  
**Environment:** Linux cloud VM (Node 22.23, PostgreSQL 16 client tools)  
**Runtime under test:** `scripts/desktop-launcher.cjs` (same stack embedded in Electron)

## Verified (2026-10-21)

| Test | Result |
| --- | --- |
| PostgreSQL init + migrations on empty data dir | Pass |
| First-run bootstrap (admin, Sales users, sample leads/deals) | Pass |
| API `GET /health` | Pass |
| API `POST /auth/login` (local admin) | Pass |
| UI login via `/api/proxy` (after desktop CSRF fix) | Pass |
| UI `/crm/leads` — list, columns, sample records | Pass (screenshot) |
| UI `/crm/contacts` | Pass (screenshot) |
| UI `/crm/deals` | Pass (screenshot) |
| UI `/operations/taskboard` | Pass (screenshot) |
| UI `/settings` | Pass (screenshot) |
| Data persistence across launcher restart | Pass (same PostgreSQL data directory) |
| `npm run desktop:build` production artifacts | Pass |
| Electron-builder **win-unpacked** bundle | Pass (~2.5 GB with app + PostgreSQL) |
| Electron-builder **Linux AppImage** launch packaging | Pass (377 MB) |

## Not fully verified in this run

| Area | Reason |
| --- | --- |
| Windows NSIS `LeadCRM-Setup-1.0.0.exe` | Wine/NSIS execution failed in VM; portable archive supplied |
| Gmail / Brevo / TextBee live sends | No production credentials in test environment |
| Full workflow execution matrix | Upstream integration suite not re-run (`npm run qa:local`) |
| Windows GUI on real Windows 10/11 | Requires user machine; portable bundle intended for that test |

## Evidence

Screenshots (Playwright, 1400×900):

- `/opt/cursor/artifacts/screenshots/01-login.png`
- `/opt/cursor/artifacts/screenshots/02-dashboard.png`
- `/opt/cursor/artifacts/screenshots/03-leads.png`
- `/opt/cursor/artifacts/screenshots/04-contacts.png`
- `/opt/cursor/artifacts/screenshots/05-deals.png`
- `/opt/cursor/artifacts/screenshots/06-tasks.png`
- `/opt/cursor/artifacts/screenshots/07-settings.png`

## Suggested regression on Windows

1. Extract `LeadCRM-Portable-1.0.0-win-x64.7z` and run `LeadCRM.exe`.
2. Confirm first-run credentials via **File → Show login credentials**.
3. Create, edit, and delete a lead; restart app; confirm persistence.
4. Build installer on Windows: `cd leadcrm-desktop/electron && npm run build:installer`.
