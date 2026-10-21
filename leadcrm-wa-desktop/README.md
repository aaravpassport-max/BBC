# LeadCRM WA Desktop

Windows desktop wrapper for your **WordPress plugin** [`leadcrm-wa-build.zip`](https://github.com/aaravpassport-max/BBC/raw/main/leadcrm-wa-build.zip) (LeadCRM v5.1.4 — WhatsApp CRM, team inbox, SPA at `/crm/`).

Stack: **Electron + portable PHP + MariaDB + WordPress core + your plugin** (no separate WordPress install required).

## Build Windows installer

```bash
cd leadcrm-wa-desktop
npm run prepare:win          # WordPress + plugin + PHP + MariaDB (Windows x64)
cd electron && npm install
npm run build                # NSIS installer → dist/LeadCRM-WA-Setup-1.0.0.exe
```

## Develop / smoke test (Linux, system PHP + MariaDB)

```bash
npm run prepare
cd electron && npm install && npm start
```

First launch creates `%APPDATA%/LeadCRM-WA/` (or `~/.../LeadCRM-WA` on Linux) with database, WordPress files, and `LOGIN.txt`.

## Source plugin

The plugin under `app/wordpress/wp-content/plugins/leadcrm-wa-build/` is copied from the repo zip at build time. To refresh from GitHub:

```bash
curl -L -o /tmp/leadcrm-wa-build.zip https://github.com/aaravpassport-max/BBC/raw/main/leadcrm-wa-build.zip
unzip -o /tmp/leadcrm-wa-build.zip -d /tmp && cp -a /tmp/leadcrm-wa-build app/wordpress/wp-content/plugins/
```
