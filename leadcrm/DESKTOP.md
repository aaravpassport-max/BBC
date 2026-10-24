# LeadCRM desktop packaging

This repository includes desktop-specific scripts:

- `npm run desktop:build` — production build + Next.js standalone preparation
- `npm run desktop:start` — run loopback API + UI (requires PostgreSQL binaries on `PATH` or `POSTGRES_BIN`)

The Electron installer project lives in [`../leadcrm-desktop/`](../leadcrm-desktop/README.md).

Set `LEADCRM_DATA_DIR` to control where the embedded database and uploads are stored (defaults to `.leadcrm-desktop-data` in the repo root for CLI use; Electron uses `%APPDATA%\leadcrm-desktop\leadcrm-data` on Windows).
