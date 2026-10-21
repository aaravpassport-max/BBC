# Windows install — Admin and Employee

Use the **Setup.exe** installers (Start Menu, uninstall in Settings → Apps). No browser required for admin daily work.

## Direct downloads (v1.0.5)

| Role | Installer |
|------|-----------|
| **Admin / HR** | https://github.com/aaravpassport-max/BBC/releases/download/attendance-desktop-v1.0.5/WorkforceAttendance-Admin-Setup-1.0.5.exe |
| **Employee** | https://github.com/aaravpassport-max/BBC/releases/download/attendance-desktop-v1.0.5/WorkforceAttendance-Employee-Setup-1.0.5.exe |

Release page: https://github.com/aaravpassport-max/BBC/releases/tag/attendance-desktop-v1.0.5

> **SQLite `no such table: notice_acknowledgements`:** reinstall **v1.0.5** employee Setup, or delete `%ProgramData%\WorkforceAttendance\attendance.db` and start the agent again (creates a fresh database).

## Admin console

1. Run **WorkforceAttendance-Admin-Setup-1.0.3.exe**.
2. Follow the wizard (default folder: `Program Files\WorkforceAttendance\Admin`).
3. Launch **Workforce Attendance Console** from the Start Menu.
4. **First run:** create administrator email and password.

## Employee agent

1. Run **WorkforceAttendance-Employee-Setup-1.0.5.exe** on each employee PC.
2. On the **Employee ID** step, enter HR’s ID or leave blank (uses `WindowsUser@ComputerName`).
3. Keep **Start monitoring automatically when I sign in** enabled unless IT deploys startup another way.
4. When the app starts, a **monitoring notice** appears — check **“I have read and understand”** and click **Continue**. Closing the window without that **does not start** the agent (by design).
5. There is **no main window** — look for the **tray icon** (near the clock). Double-click or right-click for **View my activity today**.

Employees do **not** use `AttendanceTracker.exe`.

### Employee app “won’t open”

| Symptom | Fix |
|---------|-----|
| Nothing visible after double-click | Check the **system tray** (^ arrow). The agent runs in the background. |
| Notice flashes and closes | You closed without checking the box — run again and click **Continue**. |
| v1.0.3 install, instant exit | Reinstall **v1.0.5** or create `%ProgramData%\WorkforceAttendance\agent-config.json` with `{"employee_id":"you@PC","device_id":"PCNAME"}`. |
| Error: `no such table: notice_acknowledgements` | Reinstall **v1.0.5** employee Setup, or delete `%ProgramData%\WorkforceAttendance\attendance.db` and relaunch. |
| Error dialog | See `%LocalAppData%\WorkforceAttendance\logs\agent-startup.log`. |

## Build installers locally

On Windows with .NET 8 SDK, Node 20+, and [Inno Setup 6](https://jrsoftware.org/isinfo.php):

```powershell
cd attendance-tracker
.\scripts\build-windows.ps1
.\scripts\download-node-win.ps1
# copy runtime\node into dist\AttendanceTracker-win-x64\runtime\node
dotnet publish agent-windows\AttendanceAgent.csproj -c Release -r win-x64 --self-contained true -o dist\AttendanceAgent-win-x64
.\scripts\build-installers.ps1 -AppVersion 1.0.3
```

Output: `installer\output\WorkforceAttendance-*-Setup-1.0.3.exe`

See also: `docs/DESKTOP_INSTALL.md`, `docs/EMPLOYEE_GUIDE.md`.
