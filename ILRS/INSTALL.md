# Install ILRS — No Prerequisites Required

ILRS is a **standalone desktop app**. You do **not** need to install Node.js, npm, or any other software.

Everything is bundled inside the installer.

---

## Windows (recommended)

### Step 1 — Download

Download **`ILRS-Setup-1.0.0.exe`** from:

- **GitHub Releases:** https://github.com/aaravpassport-max/BBC/releases/latest  
- Or run **Actions → Build ILRS Installers → Run workflow**, then download the `ILRS-Windows-Setup` artifact.

### Step 2 — Install

1. Double-click **`ILRS-Setup-1.0.0.exe`**
2. Windows SmartScreen may appear — click **More info** → **Run anyway**
3. The installer runs automatically (one-click install)
4. ILRS opens when installation finishes

A desktop shortcut and Start Menu entry are created automatically.

**No admin rights required.**

---

## Linux

### Option A — AppImage (no install, just run)

1. Download **`ILRS-Setup-1.0.0.AppImage`**
2. Right-click → Properties → Allow executing as program  
   Or run: `chmod +x ILRS-Setup-1.0.0.AppImage`
3. Double-click to launch

### Option B — Debian/Ubuntu package

1. Download **`ILRS-Setup-1.0.0.deb`**
2. Double-click to install, or run:
   ```bash
   sudo dpkg -i ILRS-Setup-1.0.0.deb
   ```
3. Launch **ILRS** from your applications menu

---

## macOS

macOS builds are not yet published. Use the developer build instructions in [README.md](README.md) if needed.

---

## What gets installed

| Item | Location |
|------|----------|
| Application (Windows) | `%LOCALAPPDATA%\Programs\ILRS\` |
| Application (Linux deb) | `/opt/ILRS/` |
| Your reminders & data | `%APPDATA%\ilrs\` (Windows) or `~/.config/ilrs/` (Linux) |

Your data stays on your computer. Nothing is sent to the cloud.

---

## Uninstall

- **Windows:** Settings → Apps → Installed apps → ILRS → Uninstall
- **Linux (deb):** `sudo apt remove ilrs`
- **Linux (AppImage):** Delete the `.AppImage` file

---

## Troubleshooting

**"Windows protected your PC"**  
Click **More info** → **Run anyway**. The app is not code-signed yet.

**Notifications not showing**  
Open ILRS → Settings → **Send Test**. Also check Windows notification settings for ILRS.

**Need the latest build from source?**  
See [README.md](README.md) for developer instructions.
