# Install Business OS on Windows

Business OS installs like any other Windows app — one download, one click, ready to use.

## Step 1 — Download

Download **`BusinessOS-Setup-1.0.9.exe`** from the [latest release](https://github.com/aaravpassport-max/BBC/releases/latest):

**Direct link:** https://github.com/aaravpassport-max/BBC/releases/download/v1.0.9/BusinessOS-Setup-1.0.9.exe (~150 MB)

> No internet is required after download. PHP, MariaDB, and the full app are bundled inside the installer.

## Step 2 — Install

> **Important:** If upgrading, uninstall the old version first. If you saw a database error, delete the folder `%APPDATA%\business-os-desktop\BusinessOS` before installing v1.0.5.

1. Open **Task Manager** (Ctrl+Shift+Esc) and end any **Business OS** or **business-os-desktop** processes
2. Uninstall old version: **Settings → Apps → Business OS → Uninstall**
3. Double-click **`BusinessOS-Setup-1.0.6.exe`**
2. Windows may show a SmartScreen prompt — click **More info** → **Run anyway** (the app is not yet code-signed)
3. The installer runs automatically — no setup wizard, no admin rights needed
4. A desktop shortcut and Start Menu entry are created
5. Business OS launches when installation finishes

Installation typically takes **1–3 minutes** depending on your PC.

## Step 3 — Log in

**Default login (works until you complete the setup wizard):**

| Field | Value |
|-------|-------|
| Email | `admin@businessos.local` (or username `Admin`) |
| Password | `changeme123` |

Use the email exactly as shown — not your personal email address. If login still fails after upgrading, delete `%APPDATA%\business-os-desktop\BusinessOS` and reinstall.

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
