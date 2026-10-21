# How employees use the attendance system

## Who uses what

| Role | Software | Purpose |
|------|----------|---------|
| **Admin / HR** | **Workforce Attendance Console** (`AttendanceTracker.exe`) | Dashboard, reports, corrections, backups |
| **Employee** | **Attendance Agent** (`AttendanceAgent.exe`) | Automatic sign-in/out, breaks, app usage on their PC |
| **Employee** | **Browser** (optional) | “View my activity today” from the tray — no daily password |
| **Employee** | **Chrome extension** (optional) | Track active website URLs |

Employees **do not** install or log in to the admin WPF app.

## Employee install (Windows)

**Recommended:** run **`WorkforceAttendance-Employee-Setup-1.0.3.exe`** from [WINDOWS_INSTALL.md](./WINDOWS_INSTALL.md) (Start Menu entry, optional start-at-login).

## Employee day-one flow

1. IT installs the **employee Setup.exe** (or `AttendanceAgent.exe`) on the employee’s Windows PC (company-owned).
2. Employee sees the **transparency notice** once and dismisses it (required before tracking).
3. A **tray icon** appears; the agent records session and activity in the background.
4. Employee opens **tray → View my activity today** to see their own hours (browser page).

## Central reporting (optional)

If the admin console runs on a server or HR PC:

1. IT enrolls the device and sets environment variables on the employee PC:
   - `ATTENDANCE_SERVER_URL` — e.g. `http://192.168.1.10:8080`
   - `ATTENDANCE_DEVICE_TOKEN` — from enrollment
   - `ATTENDANCE_EMPLOYEE_ID` / `ATTENDANCE_DEVICE_ID`
2. The agent syncs events to the admin API when online.

## Building the employee agent

On a Windows machine with .NET 8 SDK:

```powershell
cd attendance-tracker\agent-windows
dotnet publish -c Release -r win-x64 --self-contained true -o publish
```

Distribute `publish\AttendanceAgent.exe` to employee PCs.

See `agent-windows/README.md` for native-host and Chrome extension setup.
