# Delivery audit — LeadCRM WA Desktop

## Release gate (required)

Every tagged Windows release must pass **CI job `E2E visual audit (release gate)`** before the installer build runs.

Local run (same as CI):

```bash
cd leadcrm-wa-desktop
npm ci
npx playwright install chromium
node scripts/download-wordpress.js
npm run verify
```

### Checks include

- WordPress + plugin install
- Bootstrap and cold `/crm/` load (no login)
- **WordPress `.maintenance` does not block CRM**
- wp-login bypass
- SPA sections + screenshots
- admin-ajax

## User-facing fixes (v1.0.7+)

- Stale `.maintenance` removed on startup
- Desktop mu-plugin disables maintenance mode on `127.0.0.1`

Recommended installer: **`leadcrm-wa-v1.0.7`** — `LeadCRM-WA-Setup-1.0.7.exe`
