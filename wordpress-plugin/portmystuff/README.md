# PORTMYSTUFF WordPress Plugin

**100% standalone** ride + parcel logistics platform for WordPress. React + TypeScript apps open as **independent full-screen experiences** at `/portmystuff/` — not inside wp-admin.

## Standalone app URLs

After activation + permalinks save:

| App | URL |
|-----|-----|
| Launcher | `https://yoursite.com/portmystuff/` |
| Customer | `https://yoursite.com/portmystuff/customer` |
| Driver | `https://yoursite.com/portmystuff/driver` |
| Admin | `https://yoursite.com/portmystuff/admin` |
| Ops | `https://yoursite.com/portmystuff/ops` |

## Install

1. Upload `portmystuff-1.2.0.zip` via **Plugins → Add New → Upload**
2. Activate the plugin
3. **Settings → Permalinks → Save** (required for routes)
4. Open `https://yoursite.com/portmystuff/`

## Demo accounts

| Role | Phone | OTP |
|------|-------|-----|
| Customer | 9000000001 | 111111 |
| Driver | 9000000002 | 222222 |

Admin/Ops require a WordPress user with the appropriate PORTMYSTUFF role.

## Architecture

```
WordPress (backend only)
├── MySQL tables (wp_pms_*)
├── REST API (wp-json/portmystuff/v1)
└── React + TypeScript SPA (assets/dist/)
    ├── /portmystuff/          Launcher
    ├── /portmystuff/customer   Customer app
    ├── /portmystuff/driver     Driver app
    ├── /portmystuff/admin      Admin console
    └── /portmystuff/ops        Control room
```

## Development

```bash
cd wordpress-plugin/portmystuff/web
npm install
npm run build   # outputs to ../assets/dist/
```

## Shortcodes (optional)

Shortcodes render a button linking to the standalone app:

- `[portmystuff_app]` — launcher
- `[portmystuff_customer]` — customer app
- `[portmystuff_driver]` — driver app

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
