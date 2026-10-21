# Windows install — Admin and Employee

Use the **Setup.exe** installers (Start Menu, uninstall in Settings → Apps). No browser required for admin daily work.

## Direct downloads (v1.0.3)

After CI publishes release `attendance-desktop-v1.0.3`:

| Role | Installer |
|------|-----------|
| **Admin / HR** | https://github.com/aaravpassport-max/BBC/releases/download/attendance-desktop-v1.0.3/WorkforceAttendance-Admin-Setup-1.0.3.exe |
| **Employee** | https://github.com/aaravpassport-max/BBC/releases/download/attendance-desktop-v1.0.3/WorkforceAttendance-Employee-Setup-1.0.3.exe |

Release page: https://github.com/aaravpassport-max/BBC/releases/tag/attendance-desktop-v1.0.3

> If v1.0.3 is not listed yet, open **Actions → Build Attendance Desktop (Windows)** and run the workflow, or use the portable ZIP from v1.0.2 until the new release finishes.

## Admin console

1. Run **WorkforceAttendance-Admin-Setup-1.0.3.exe**.
2. Follow the wizard (default folder: `Program Files\WorkforceAttendance\Admin`).
3. Launch **Workforce Attendance Console** from the Start Menu.
4. **First run:** create administrator email and password.

## Employee agent

1. Run **WorkforceAttendance-Employee-Setup-1.0.3.exe** on each employee PC.
2. Keep **Start monitoring automatically when I sign in** enabled unless IT deploys startup another way.
3. Accept the **transparency notice** once.
4. Use the **system tray** icon — not the admin console.

Employees do **not** use `AttendanceTracker.exe`.

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
