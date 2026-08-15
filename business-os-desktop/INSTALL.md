# Install Business OS on Windows

Business OS installs like any other Windows app — one download, one click, ready to use.

## Step 1 — Download

Download **`BusinessOS-Setup-1.0.0.exe`** from the [Releases](https://github.com/aaravpassport-max/BBC/releases) page.

> No internet is required after download. PHP, MariaDB, and the full app are bundled inside the installer.

## Step 2 — Install

1. Double-click **`BusinessOS-Setup-1.0.0.exe`**
2. Windows may show a SmartScreen prompt — click **More info** → **Run anyway** (the app is not yet code-signed)
3. The installer runs automatically — no setup wizard, no admin rights needed
4. A desktop shortcut and Start Menu entry are created
5. Business OS launches when installation finishes

Installation typically takes **1–3 minutes** depending on your PC.

## Step 3 — Log in

On first launch, Business OS sets up your local database automatically (about 30 seconds).

**Default login:**

| Field | Value |
|-------|-------|
| Email | `admin@businessos.local` |
| Password | `changeme123` |

Change this password after your first login.

## What gets installed

| Item | Location |
|------|----------|
| Application | `%LOCALAPPDATA%\Programs\Business OS\` |
| Your data (database, files) | `%APPDATA%\business-os-desktop\BusinessOS\` |
| Desktop shortcut | `Business OS` |
| Start Menu | `Business OS` |

Your business data stays on your computer. Nothing is sent to the cloud.

## Uninstall

Go to **Settings → Apps → Installed apps**, find **Business OS**, and click **Uninstall**.

Your data folder is preserved so you can reinstall without losing data.

## Troubleshooting

**App won't start after install**

Open the log folder: press `Win+R`, paste `%APPDATA%\business-os-desktop\BusinessOS\logs`, and check `mariadb.log` or `php.log`.

**Forgot password**

Delete `%APPDATA%\business-os-desktop\BusinessOS\` and relaunch — the app will recreate the database with the default login. **This erases all data.**

**Need help building the installer yourself?**

See [README.md](README.md) for developer build instructions.
