# Install Business OS on Windows

Business OS installs like any other Windows app — one download, one click, ready to use.

## Step 1 — Download

Download **`BusinessOS-Setup-1.0.10.exe`** from the [latest release](https://github.com/aaravpassport-max/BBC/releases/latest):

**Direct link:** https://github.com/aaravpassport-max/BBC/releases/download/v1.0.10/BusinessOS-Setup-1.0.10.exe (~150 MB)

> No internet is required after download. PHP, MariaDB, and the full app are bundled inside the installer.

## Step 2 — Install

1. Open **Task Manager** (Ctrl+Shift+Esc) and end any **Business OS** or **business-os-desktop** processes
2. If reinstalling after login problems, uninstall the old version first (see **Uninstall** below — tick the data deletion box)
3. Double-click **`BusinessOS-Setup-1.0.10.exe`**
4. Windows may show a SmartScreen prompt — click **More info** → **Run anyway** (the app is not yet code-signed)
5. Click through the installer — no admin rights needed
6. A desktop shortcut and Start Menu entry are created
7. Business OS launches when installation finishes

Installation typically takes **1–3 minutes** depending on your PC.

## Step 3 — Log in

**Default login (works until you complete the setup wizard):**

| Field | Value |
|-------|-------|
| Email | `admin@businessos.local` (or username `Admin`) |
| Password | `changeme123` |

Use the email exactly as shown — not your personal email address.

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

During uninstall you will be asked:

**"Do you want to delete all Business OS application data?"**

- Click **Yes** for a completely clean removal (recommended before reinstalling, or if login stops working)
- Click **No** to keep your database and files for a future reinstall

This deletes the folder `%APPDATA%\business-os-desktop\` (database, settings, uploads).

## Troubleshooting

**"Invalid email or password" after reinstall**

You likely reinstalled without deleting old app data. Uninstall again, **tick the data deletion checkbox**, then install v1.0.10 and log in with the default credentials above.

**App won't start after install**

Open the log folder: press `Win+R`, paste `%APPDATA%\business-os-desktop\BusinessOS\logs`, and check `mariadb.log` or `php.log`.

**Forgot password**

Uninstall Business OS and **tick "Delete all application data"**, then reinstall. The app will recreate the database with the default login. **This erases all data.**

**Need help building the installer yourself?**

See [README.md](README.md) for developer build instructions.
