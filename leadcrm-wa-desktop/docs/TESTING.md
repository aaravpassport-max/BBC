# LeadCRM WA Desktop — Testing

## Visual end-to-end audit (pre-delivery)

The audit reproduces the **same HTTP path** the Windows app uses: first-run WordPress install, desktop bootstrap sign-in, then the LeadCRM plugin SPA at `/crm/`.

### Prerequisites (Linux CI / dev VM)

- PHP CLI with mysqli/pdo_mysql
- MariaDB/MySQL with user `lcrmuser` / password `testpass123` (or adjust the script)
- WordPress tree under `app/wordpress` (`node scripts/download-wordpress.js`)
- Playwright from repo root `leadcrm/node_modules/playwright` or set `PLAYWRIGHT_MODULE`

### Run

```bash
cd leadcrm-wa-desktop
node scripts/e2e-visual-audit.mjs
```

Artifacts default to `/opt/cursor/artifacts/leadcrm-wa-audit/` (`LCRM_AUDIT_OUT`). Set `LCRM_AUDIT_DIR` for a temp WordPress copy.

### What is checked

| Step | Expectation |
|------|-------------|
| Install | `install-wordpress.php` activates **leadcrm-wa-build** and runs plugin activation |
| Bootstrap | `/leadcrm-desktop-bootstrap.php` → `/crm/` |
| CRM home | Title **Dashboard — LeadCRM**, no wp-login form |
| wp-login | Redirect/bypass to CRM on localhost |
| SPA | Sidebar sections: All Leads, Pipeline, Add Lead, Settings |
| API | `admin-ajax.php` responds (heartbeat may return 400 — benign) |

Screenshots `01`–`07` are the visual record for delivery sign-off.

## Windows smoke test (manual)

1. Install **LeadCRM-WA-Setup-1.0.4.exe** (or latest release tag `leadcrm-wa-v*`).
2. First launch: splash → CRM dashboard (no WordPress login).
3. Menu: **Open CRM**, **Sign in again (local)** if session issues.
4. Data lives in `%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA\` — see `LOGIN.txt` and `logs/startup.log` on failure.

## CI

GitHub Actions workflow `.github/workflows/leadcrm-wa-desktop-win.yml` builds the NSIS installer on Windows.
