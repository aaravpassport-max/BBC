# LeadCRM Desktop — Installation

## Windows (recommended)

### Option A: NSIS installer

1. Download `LeadCRM-Setup-1.0.0.exe` from the release artifacts (or `leadcrm-desktop/dist/` after a local build).
2. Run the installer and follow the prompts (desktop and Start Menu shortcuts are created).
3. Launch **LeadCRM** from the Start Menu or desktop shortcut.
4. On first launch, the app creates a local PostgreSQL database and sample tenant data.
5. Open **File → Show login credentials** in the menu to view the auto-generated admin email and password, or read `%APPDATA%\leadcrm-desktop\leadcrm-data\LOGIN.txt`.

### Option B: Portable folder

If the NSIS build is unavailable, use `LeadCRM-Portable-1.0.0-win-x64.zip`:

1. Extract the ZIP to any folder (for example `C:\LeadCRM`).
2. Run `LeadCRM.exe`.
3. Data is stored under `%APPDATA%\leadcrm-desktop\leadcrm-data\`.

## Build from source (developers)

Prerequisites on the build machine: Node.js 20.19+ or 22.13+, npm 11.19+, sufficient disk space (~3 GB for a Windows bundle).

```bash
cd leadcrm
npm ci
npm run desktop:build

cd ../leadcrm-desktop
node scripts/download-windows-postgres.js   # Windows runtime only
node scripts/prepare-packaged-app.js
cd electron && npm install
npm run build:installer                     # Windows NSIS (Wine on Linux)
```

## First-run setup

- **No WordPress, PHP, or Docker** is required.
- **Internet** is optional. Core CRM (Leads, Contacts, Deals, Tasks, Workflows, Forms UI, Settings) runs fully offline.
- **Email (Brevo), SMS (TextBee), and Gmail Inbox** require valid provider credentials configured in Settings after install.

## Backup

Copy the entire data directory:

- Windows: `%APPDATA%\leadcrm-desktop\leadcrm-data\`
- Includes PostgreSQL files (`postgres/`), uploaded files (`private-files/`), and `settings.json`.

Stop LeadCRM before copying the `postgres` folder for a cold backup.

## Troubleshooting

| Symptom | Action |
| --- | --- |
| Blank window on launch | Wait 30–60s on first run (migrations). Check `desktop.log` in the data folder. |
| Port in use | Close other LeadCRM instances. Launcher uses `127.0.0.1:13100` and `13101`. |
| Forgot password | Read `LOGIN.txt` in the data folder (first-run admin) or reset via database tools. |
| Antivirus blocks app | Allow `LeadCRM.exe` and bundled PostgreSQL binaries in `resources/postgres`. |
