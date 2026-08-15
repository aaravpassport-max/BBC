# Business OS Desktop

A self-contained desktop application for Business OS — CRM, invoicing, finance, and reporting. Works fully offline.

---

## For Windows users (plug and play)

1. **Download** `BusinessOS-Setup-1.0.0.exe` from [GitHub Releases](https://github.com/aaravpassport-max/BBC/releases/latest) (~150 MB)
2. **Double-click** the installer — it installs automatically in 1–3 minutes
3. **Log in** with `admin@businessos.local` / `changeme123`

No PHP, database, or technical setup required. See [INSTALL.md](INSTALL.md) for full instructions.

---

## Architecture

```
┌─────────────────────────────────────────┐
│  Electron Shell (main.js)               │
│  ┌─────────────┐  ┌─────────────────┐   │
│  │ React SPA   │  │ PHP API Server  │   │
│  │ /business/  │◄─┤ /bos/v1/*       │   │
│  └─────────────┘  └────────┬────────┘   │
│                            │              │
│                   ┌────────▼────────┐     │
│                   │ MariaDB (local) │     │
│                   └─────────────────┘     │
└─────────────────────────────────────────┘
```

Everything is bundled inside the installer — PHP, MariaDB, backend, and frontend.

---

## Building the Windows installer

Requires **Windows 10/11** (or GitHub Actions — see below).

```bash
cd business-os-desktop/business-os-win/electron
npm install
npm run build:installer
```

This automatically:
1. Downloads PHP 8.2 NTS and MariaDB 10.11 portable (~150 MB)
2. Configures PHP extensions (pdo_mysql, mbstring, openssl)
3. Packages everything into `dist/BusinessOS-Setup-1.0.0.exe`

### Build via GitHub Actions (no Windows PC needed)

1. Go to **Actions** → **Build Windows Installer** → **Run workflow**
2. When complete, download `BusinessOS-Windows-Setup` from the workflow artifacts
3. Distribute the `.exe` to users

Or push a version tag to auto-publish a release:

```bash
git tag v1.0.0
git push origin v1.0.0
```

---

## Linux development

```bash
sudo apt install php-cli php-mysql php-mbstring mariadb-server
cd business-os-desktop/business-os-win/electron
npm install
npm start
```

Build Linux AppImage: `npm run build:linux`

---

## Default login

| Email | Password |
|-------|----------|
| `admin@businessos.local` | `changeme123` |

---

## Data location

| OS | Path |
|----|------|
| Windows | `%APPDATA%\business-os-desktop\BusinessOS\` |
| Linux | `~/.config/business-os-desktop/BusinessOS/` |

---

## License

Copyright © 2024 Business OS
