# Workforce Attendance Console — Windows native app

This product is a **native WPF desktop application** (Office-style shell: Segoe UI, left navigation rail, command bar). It is **not** an Electron or browser wrapper.

The UI is 100% WPF. The attendance calculation engine remains the proven TypeScript `core/` module, started as a **local sidecar** Node process on `127.0.0.1` (same API used by the Windows monitoring agent and employee dashboard).

## Requirements

- Windows 10 or 11 (64-bit)
- **.NET 8 Desktop Runtime** (included if you use the self-contained publish from `build-windows.ps1`)
- **Node.js 20+** *or* the bundled `runtime\node\node.exe` from `scripts\download-node-win.ps1`

## Direct download (Windows x64)

**Recommended — normal Windows installers (Admin + Employee):** see **`docs/WINDOWS_INSTALL.md`**

- Admin: `WorkforceAttendance-Admin-Setup-1.0.3.exe`
- Employee: `WorkforceAttendance-Employee-Setup-1.0.3.exe`

**Portable ZIP (extract and run `AttendanceTracker.exe`):**

https://github.com/aaravpassport-max/BBC/releases/download/attendance-desktop-v1.0.2/AttendanceTracker-Windows-x64-v1.0.2.zip

> Use **v1.0.2+** for portable builds. Installers ship from **v1.0.3** onward.

Employee usage (agents, not this admin app): `docs/EMPLOYEE_GUIDE.md`

Includes self-contained .NET runtime, bundled `node.exe`, and the `core` engine. No separate Node or .NET install required.

## Build from source (Windows)

```powershell
cd attendance-tracker
.\scripts\build-windows.ps1
# Optional offline Node bundle:
.\scripts\download-node-win.ps1
# Copy runtime\node into dist\AttendanceTracker-win-x64\runtime\node
```

Run:

```powershell
.\dist\AttendanceTracker-win-x64\AttendanceTracker.exe
```

## Installer

Compile on Windows with Inno Setup 6:

- `installer\setup-admin.iss` → Admin Setup.exe  
- `installer\setup-employee.iss` → Employee Setup.exe  

Or run `scripts\build-installers.ps1` after publishing both apps to `dist\`.

## First run

1. Launch **Workforce Attendance Console**.
2. On first launch, **create your administrator account** — there is no pre-made ID/password. Use any email (e.g. `admin@yourcompany.local`) and a password you choose (creates `%LocalAppData%\AttendanceTracker\data\attendance.db`).
3. Sign in with the same email and password.
4. Optional: run `node core/scripts/seed-demo-data.mjs %LocalAppData%\AttendanceTracker\data\attendance.db` for rich demo attendance (overwrites if you delete the DB first).

## Data locations

| Path | Purpose |
|------|---------|
| `%LocalAppData%\AttendanceTracker\data\attendance.db` | Primary SQLite database |
| `%LocalAppData%\AttendanceTracker\backups\` | Manual backups from Settings |
| `%LocalAppData%\AttendanceTracker\config.json` | Last admin email, preferences |

## Backup and restore

Use **Settings → Backup database** / **Restore from backup** in the app.

## Employee PCs (monitoring agent)

Publish and install `agent-windows` as `AttendanceAgent.exe` (see `agent-windows/README.md`). For central sync, set:

- `ATTENDANCE_SERVER_URL` — URL of this console’s API (or a server hosting the same `core` API)
- `ATTENDANCE_DEVICE_TOKEN` — from device enrollment in `core` auth APIs

## Troubleshooting

| Issue | Action |
|-------|--------|
| “Node.js was not found” | Install Node 20+ or bundle `runtime\node\node.exe` next to the app |
| “Core server script is missing” | Re-run `build-windows.ps1` so `core\dist` and `core\scripts` are copied beside the EXE |
| Sign-in fails after restore | Sign out and sign in; verify backup file is a valid SQLite DB |
