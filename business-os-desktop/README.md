# Business OS Desktop

A self-contained desktop application for Business OS — an all-in-one business management platform with CRM, invoicing, finance, and reporting.

## Architecture

```
┌─────────────────────────────────────────┐
│  Electron Shell (main.js)               │
│  ┌─────────────┐  ┌─────────────────┐ │
│  │ React SPA   │  │ PHP API Server  │ │
│  │ /business/  │◄─┤ /bos/v1/*       │ │
│  └─────────────┘  └────────┬────────┘ │
│                            │           │
│                   ┌────────▼────────┐  │
│                   │ MariaDB (local) │  │
│                   └─────────────────┘  │
└─────────────────────────────────────────┘
```

- **Frontend**: Pre-built React SPA served by PHP at `/business/`
- **Backend**: PHP 8.1+ API at `/bos/v1/*`
- **Database**: Embedded MariaDB with per-user data directory
- **Desktop**: Electron 28 wraps everything into a native app

## Quick Start (Development)

### Prerequisites

| Platform | Requirements |
|----------|-------------|
| **Linux** | PHP 8.1+, MariaDB/MySQL, Node.js 18+ |
| **Windows** | Node.js 18+ (bundled PHP/MariaDB for production builds) |

### Linux / macOS

```bash
# Install system dependencies (Ubuntu/Debian)
sudo apt install php-cli php-mysql php-mbstring mariadb-server

# Start the desktop app
cd business-os-desktop/business-os-win/electron
npm install
npm start
```

The app will:
1. Start a local MariaDB instance on port 3307 (data stored in `~/.config/business-os-desktop/BusinessOS/`)
2. Start PHP built-in server on port 9753
3. Open the Business OS window at `http://127.0.0.1:9753/business/`

### Windows Development

Place portable PHP 8.2 NTS and MariaDB 10.11 in the project root:

```
business-os-win/
  php/        ← PHP 8.2 NTS x64
  mariadb/    ← MariaDB 10.11 portable x64
```

Then run:

```bash
cd electron
npm install
npm start
```

## Building Installers

### Windows (.exe installer)

```bash
cd electron
npm run build
```

Output: `dist/Business OS Setup 1.0.0.exe`

For the full offline installer with bundled PHP/MariaDB, also run Inno Setup on `installer/setup.iss`.

### Linux (AppImage)

```bash
cd electron
npm run build:linux
```

Output: `dist/Business OS-1.0.0.AppImage`

## Project Structure

```
business-os-win/
├── app/           # React SPA (pre-built assets)
├── electron/      # Electron main process
│   ├── main.js    # App lifecycle, starts PHP + MariaDB
│   ├── platform.js
│   └── preload.js
├── server/        # PHP backend
│   ├── public/    # Entry point (index.php router)
│   └── src/       # API, auth, database, installer
└── installer/     # Inno Setup script (Windows)
```

## API Endpoints

All API routes are under `/bos/v1/`:

- `POST /bos/v1/auth/login` — User authentication
- `GET /bos/v1/entities/*` — CRM entities (contacts, companies, deals)
- `GET /bos/v1/invoices/*` — Invoicing
- `GET /bos/v1/finance/*` — Financial records
- `GET /bos/v1/reports/*` — Reports and analytics

## Data Location

User data is stored outside the application bundle:

| OS | Path |
|----|------|
| Windows | `%APPDATA%/business-os-desktop/BusinessOS/` |
| Linux | `~/.config/business-os-desktop/BusinessOS/` |
| macOS | `~/Library/Application Support/business-os-desktop/BusinessOS/` |

Contains: database files, logs, uploads, and `db.json` connection config.

## Default Login

On first launch, the installer seeds a default admin account. Check `server/src/Installer.php` for seed credentials, or register via the setup wizard in the app.

## License

Copyright © 2024 Business OS
