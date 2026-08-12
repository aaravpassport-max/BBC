# PORTMYSTUFF WordPress Plugin

**100% standalone** ride + parcel logistics platform for WordPress. No Node.js backend, no external Postgres — everything runs inside WordPress using custom tables and the REST API.

## Features

- **Customer app** — `[portmystuff_customer]` or `[portmystuff_book]` shortcode
- **Driver app** — `[portmystuff_driver]` shortcode
- **Admin dashboards** — Bookings, Drivers, Rate Cards, Analytics, KYC, Support, Marketing, Settlement
- **Ops control room** — SOS Queue, Dispatch Monitor, Live Map
- **REST API** — `wp-json/portmystuff/v1/*` (auth, pricing, bookings, driver, wallet)
- **Dual booking types** — Parcel delivery + passenger rides
- **Demo accounts** — Customer `9000000001` / OTP `111111`, Driver `9000000002` / OTP `222222`

## Installation

1. Download `wordpress-plugin/portmystuff.zip` or copy the `portmystuff` folder to `wp-content/plugins/`
2. Activate **PORTMYSTUFF Logistics Platform** in WordPress admin
3. Go to **PORTMYSTUFF → Dashboard** to confirm tables were created
4. Create a page and add shortcode `[portmystuff_customer]` for the customer app
5. Create a page and add shortcode `[portmystuff_driver]` for the driver partner app

## Optional: Bundle full React apps

To embed the complete React UIs (instead of the built-in mini apps):

```bash
cd wordpress-plugin/portmystuff/scripts
chmod +x build-apps.sh
./build-apps.sh all
```

This builds `frontend` and `driver-app` with the WordPress REST API base URL and copies assets into `assets/apps/`.

## REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/portmystuff/v1/auth/otp/request` | POST | Request OTP |
| `/wp-json/portmystuff/v1/auth/otp/verify` | POST | Verify OTP → JWT |
| `/wp-json/portmystuff/v1/pricing/quote` | POST | Get fare quotes |
| `/wp-json/portmystuff/v1/bookings` | POST/GET | Create / list bookings |
| `/wp-json/portmystuff/v1/driver/*` | * | Driver partner APIs |
| `/wp-json/portmystuff/admin/v1/*` | * | Admin (WP capability required) |
| `/wp-json/portmystuff/ops/v1/*` | * | Ops control room |

## Roles

On activation, these roles are created:

- `pms_ops_admin` — full admin console access
- `pms_control_room` — SOS + dispatch + live map
- `pms_driver` / `pms_customer` — app users

WordPress administrators receive all capabilities automatically.

## Architecture

```
portmystuff/
├── portmystuff.php          # Plugin bootstrap
├── includes/                  # PHP services + REST API
├── admin/views/               # WP admin dashboard pages
├── assets/                    # CSS, JS, optional React app bundles
└── scripts/build-apps.sh      # Bundle React apps into plugin
```

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+

## Uninstall

Deactivate the plugin. Custom tables are preserved by default. To remove data, delete tables prefixed with `{wpdb_prefix}pms_`.
