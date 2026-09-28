# Install ILRS — No Prerequisites Required

ILRS is a **standalone desktop app**. You do **not** need to install Node.js, npm, or any other software.

Everything is bundled inside the installer.

---

## Windows (recommended)

### Option A — One-click installer (`.exe`)

Download **`ILRS-Setup-1.0.0.exe`** from [GitHub Releases](https://github.com/aaravpassport-max/BBC/releases/latest).

1. Double-click the `.exe`
2. SmartScreen may appear — click **More info** → **Run anyway**
3. Installation completes automatically and ILRS launches

### Option B — Portable (no installer, just unzip and run)

Download **`ILRS-Portable-1.0.0-Windows-x64.zip`** from [GitHub Releases](https://github.com/aaravpassport-max/BBC/releases/latest).

1. Right-click the zip → **Extract All**
2. Open the extracted `win-unpacked` folder
3. Double-click **`ILRS.exe`**

No Node.js or other software required. Nothing is installed to Program Files — delete the folder to uninstall.

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
