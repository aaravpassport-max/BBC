# Windows install — one app

Install **`WorkforceAttendance-Setup-x.y.z.exe`** from the GitHub release tagged `attendance-desktop-v*.*.*` (not the repo’s default “Latest” release if that points at another product).

## What you get

One application on each PC:

1. **Main window** — dashboard, employees, history, and reports with **live data** (read-only).
2. **Admin sign in** (top right) — required to approve corrections, back up/restore, or other changes.
3. **Background monitoring** — tray icon; transparency notice on first run.

There is no separate “employee agent” vs “admin console” anymore.

## First launch

1. Run the installer; optionally set **Employee ID** (defaults to `WindowsUser@PCNAME`).
2. Open **Workforce Attendance** from the Start menu.
3. Accept the monitoring notice if prompted.
4. Browse live attendance data immediately.
5. To create the first administrator account, click **Admin sign in** and complete first-run setup.

## Data location

| Item | Path |
|------|------|
| Database | `%LocalAppData%\AttendanceTracker\data\attendance.db` |
| Install config (employee/device id) | `%ProgramData%\WorkforceAttendance\agent-config.json` |

## Portable ZIP

`AttendanceTracker-Windows-x64-v*.zip` — extract the folder intact (exe + `core\` + `runtime\`) and run `AttendanceTracker.exe`.
