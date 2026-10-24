# LeadCRM Desktop

Standalone **Windows** desktop packaging for [LeadCRM](https://github.com/reymarkjpanes/LeadCRM) (Camxian Technologies CRM monorepo).

## Quick start (developers)

```bash
# 1. Build the CRM
cd ../leadcrm
npm ci
npm run desktop:build

# 2. Package desktop shell
cd ../leadcrm-desktop
node scripts/prepare-packaged-app.js
cd electron && npm install && npm start    # dev: uses ../leadcrm + system PostgreSQL
```

## Deliverables (after `electron-builder`)

| Artifact | Path |
| --- | --- |
| Windows portable (7z) | `dist/LeadCRM-Portable-1.0.0-win-x64.7z` |
| Windows unpacked | `dist/win-unpacked/LeadCRM.exe` |
| Linux smoke test | `dist/LeadCRM-Setup-1.0.0.AppImage` |
| Windows installer | `dist/LeadCRM-Setup-1.0.0.exe` (build on Windows or Wine+NSIS) |

See [INSTALL.md](docs/INSTALL.md), [COMPATIBILITY.md](docs/COMPATIBILITY.md), and [TESTING.md](docs/TESTING.md).
