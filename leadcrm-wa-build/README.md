# LeadCRM — WordPress plugin

**Plugin slug:** `leadcrm-wa-build`  
**CRM URL:** `/crm/` (SPA after activation)

Enterprise lead CRM: pipeline, work types, WhatsApp capture, team inbox, automation, and admin settings — runs on any self-hosted WordPress site with PHP 7.4+ and MySQL/MariaDB.

## Install on WordPress

1. Download **`leadcrm-wa-build.zip`** from [GitHub Releases](https://github.com/aaravpassport-max/BBC/releases) (tag `leadcrm-wa-v*`) or build it locally (below).
2. In **WP Admin → Plugins → Add New → Upload Plugin**, choose the zip and **Install Now**, then **Activate**.
3. On first activation the plugin creates database tables, roles, and a published page at **`/crm/`**.
4. Open **`https://yoursite.com/crm/`** (log in as an administrator or CRM role user).

### Requirements

- WordPress 5.8+
- PHP extensions: `mysqli` or `pdo_mysql`, `mbstring`, `json`, `curl` (recommended)
- Pretty permalinks recommended (for `/crm/`)

### Upgrade

Upload and install a newer zip over the existing plugin (or replace the `wp-content/plugins/leadcrm-wa-build` folder). Your leads stay in the WordPress database; activation runs lightweight migrations automatically.

### Build the zip locally

From the repository root:

```bash
bash leadcrm-wa-build/scripts/build-plugin-zip.sh
# → ./leadcrm-wa-build.zip
```

## Windows desktop app

The same plugin is bundled inside **LeadCRM WA** (Electron). Desktop data lives in `%APPDATA%\leadcrm-wa-desktop\LeadCRM-WA\` — see `leadcrm-wa-desktop/docs/INSTALL-WINDOWS.md`.

## Source layout

| Path | Purpose |
|------|---------|
| `leadcrm.php` | Bootstrap |
| `admin/menus.php` | Admin + SPA shell |
| `includes/` | AJAX, capture, automation, WA |
| `assets/js/admin.js`, `spa.js` | CRM UI |
