# Test report — Workforce Attendance Console (native WPF)

**Environment note:** This cloud build VM is **Linux**. The WPF application **cannot be executed or visually inspected here**. Windows-specific results below are **unverified** unless marked otherwise.

## Passed (executed on Linux, 2026-10-21)

| # | Area | Result |
|---|------|--------|
| 1 | `core/` unit + API tests (`npm test`) | **Pass** — 120/120 |
| 2 | `core/` TypeScript build (`npm run build`) | **Pass** |
| 3 | Bootstrap script syntax | **Pass** — `node core/scripts/bootstrap-desktop-db.mjs` (see automated smoke below) |
| 4 | Sidecar server start | **Pass** — `start-server.mjs` binds port and `/api/health` |

### Automated smoke (Linux)

```bash
cd core && npm run build
node scripts/bootstrap-desktop-db.mjs /tmp/wpf-smoke.db admin@local.test 'test-pass-123' 'Admin'
ATTENDANCE_DB_PATH=/tmp/wpf-smoke.db ATTENDANCE_PORT_FILE=/tmp/wpf-port.json node scripts/start-server.mjs &
curl -s http://127.0.0.1:$(jq -r .port /tmp/wpf-port.json)/api/health
```

## Unverified (requires Windows 10/11)

| # | Area | Reason |
|---|------|--------|
| 1 | WPF `dotnet publish` compile | `net8.0-windows` + WPF requires Windows SDK |
| 2 | Installation (Inno Setup) | Not run on Linux |
| 3 | First-run UI, login, navigation | WPF not launched |
| 4 | Dashboard KPI load | Needs running WPF + API |
| 5 | CSV export file save dialog | Windows UI |
| 6 | Backup/restore from Settings | Windows paths + WPF |
| 7 | `agent-windows` compile and tray agent | No .NET Windows SDK in Linux VM |
| 8 | Chrome extension + native host round trip | No Chrome/Windows |
| 9 | Layout at multiple window sizes | Visual inspection pending |
| 10 | Screenshots of running Windows app | **Not captured** — blocked on platform |

## Recommended Windows verification checklist

After `scripts\build-windows.ps1`:

1. First launch → create admin → sign in.
2. Seed demo data (optional) and refresh Dashboard.
3. History search 7-day range; Reports CSV export.
4. Sign out / sign in session persistence.
5. Backup DB, delete a row via API, restore backup.
6. Publish `AttendanceAgent.exe` against local API with device token.

## Known limitations

- Admin UI does not yet include device enrollment or classification rule editors (API gap documented in README).
- Restore does not restart the Node sidecar automatically — sign out/in after restore.
