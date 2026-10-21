# Feature migration status

## Source project identification

| Candidate | Type | Used as foundation? |
|-----------|------|---------------------|
| **Workforce Attendance, Activity & Productivity Tracker** (this repository / `attendance-tracker.zip`) | Windows workforce monitoring + Node `core` engine | **Yes** |
| [abedputra/Attendance-QR-Plugin-Wordpress](https://github.com/abedputra/Attendance-QR-Plugin-Wordpress) | WordPress QR check-in/out plugin | **No** — different product (PHP/MySQL, mobile QR scanning) |
| [WPChef Employee Attendance Tracker](https://wordpress.org/plugins/wpchef-employee-attendance-tracker/) | WordPress employee clock | **No** |

This delivery converts the **workforce tracker** into a standalone **native WPF** admin console plus existing Windows agent/extension components — not a WordPress port.

## Module migration

| Original feature | Migration | Status |
|------------------|-----------|--------|
| Session sign-in/out, lock, sleep events (`agent-windows`) | Unchanged C# agent; sync via `POST /api/events/ingest` | **Migrated (source)** — requires Windows compile/run |
| 5-minute inactivity / backdated breaks (`core` reconciler) | Unchanged in `core/` | **Verified** (120 unit tests) |
| App foreground + browser URL usage | Agent + extension + native host | **Migrated (source)** — extension/host unverified on Windows |
| Productivity classification rules | `core` + admin API read | **Verified**; rule CRUD via API still script-only |
| Transparency gate / notice | `NoticeForm`, server-side gate | **Migrated (source)** |
| Synthetic input (mouse jiggler) detection | `core` engine | **Verified** |
| RBAC: admin / employee / device sessions | `core` API | **Verified** |
| Employee local dashboard | `local-dashboard.html` on API | **Verified** (Playwright script) |
| Admin console (`dashboard/console.html` artifact) | **Replaced** by native WPF modules | **Migrated** to WPF (Dashboard, Employees, History, Reports, Corrections, Settings) |
| Daily / monthly reports + CSV export | WPF Reports view → same API routes | **Migrated** |
| Correction request workflow | WPF Corrections view | **Migrated** |
| Attendance history search/filters | WPF History view | **Migrated** |
| Multi-PC sync | `EventSyncClient` + ingest API | **Migrated (source)** |
| Backup / restore | WPF Settings | **Migrated** |
| Device enrollment HTTP API | Repository functions only | **Partial** — use scripts/tests; no WPF UI yet |
| Classification rule admin HTTP API | Repository functions only | **Partial** |
| Published HTML admin artifact live fetch | N/A in desktop app | **Superseded** by WPF |

## WordPress-only features (not applicable)

QR generation, WordPress user import, PHP `insert-attendance.php`, and Flutter-only mobile UI from the QR WordPress plugin are **out of scope** for this codebase.

## External services

| Feature | Requires |
|---------|----------|
| Central sync from employee PCs | Network reachability to API; device tokens |
| Browser URL tracking | Chrome + native messaging host registration |
| GPS / QR (WordPress plugin) | Not part of this project |
