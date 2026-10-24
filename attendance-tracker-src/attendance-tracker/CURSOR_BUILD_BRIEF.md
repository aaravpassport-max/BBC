# Cursor build brief — Attendance, Activity & Productivity Tracker (Windows app)

## Read this first

You (Cursor, running on a real Windows machine) are being handed a
repository that is **split into two honesty tiers**:

1. **`core/` — proven.** TypeScript engine + a role-based HTTP API
   (`core/src/api/server.ts`). 120 tests pass (`npm test`), `tsc
   --noEmit` is clean, and `npm run build` produces a working `dist/`.
   This has been compiled and run for real, repeatedly, in a Linux
   sandbox. **Do not redesign this layer.** If you need to touch it,
   keep changes additive and re-run `npm test` + `tsc --noEmit`
   afterward — treat a regression here as a hard stop.

2. **`agent-windows/`, `native-host/`, `browser-extension/` — reviewed
   source, never compiled.** All of this C#/.NET and JS was written and
   checked line-by-line by reading, cross-referenced against the proven
   `core/` API it talks to — but it has **never been run through a real
   C# compiler or a real Chrome instance**, because it was written in a
   Linux sandbox with no .NET SDK and no Windows APIs. This is the part
   your job is to actually finish: compile it, fix whatever a real
   compiler finds (there will very likely be something — "reviewed,
   not compiled" is stated honestly throughout this repo, not as a
   formality), publish it, and wire it end-to-end to a running `core/`
   server.

Your job, in order:

1. `dotnet build` both `agent-windows/` and `native-host/` on this
   machine. **Fix every real compiler error or warning you find.**
   Don't silently patch around a design intent you don't understand —
   each file has doc comments explaining *why* things are structured
   the way they are (P/Invoke delegate rooting, event-ordering
   decisions, etc.); read those before changing logic, not just
   syntax.
2. `dotnet publish -r win-x64 --self-contained true` both projects.
3. Stand up `core/` (see below) and confirm the published
   `AttendanceAgent.exe` can talk to it end to end: notice
   acknowledgment → tray icon → local dashboard opens → events show up
   server-side → (if configured) sync to the central server works.
4. Register the native-messaging host and load the browser extension
   in a real Chrome, and confirm a tab switch produces a real
   `browser-extension`-sourced row server-side.
5. Report back, concretely: what compiled as-is vs. what you had to
   fix, and the output of an actual end-to-end run (not "should work").

**There is a known gap you cannot close by coding harder**: the
original product spec's "24 acceptance scenarios" (referenced by
`agent-windows/README.md` as "the original specification §17") do not
exist anywhere in this repository. If you don't have that document
either, don't invent scenario content — just do the structural build
and verification above, and say plainly that the scenario-by-scenario
pass is still blocked on that missing document.

---

## 0. Prerequisites on this machine

- Windows 10/11.
- .NET 8 SDK (Windows build — `net8.0-windows` needs the Windows
  reference assemblies the Windows SDK installer adds automatically).
- Google Chrome.
- Node.js 20+.

## 1. Stand up the proven server first

```powershell
cd core
npm install
npm test        # expect: 120 pass, 0 fail
npx tsc --noEmit  # expect: clean
npm run build

node scripts/seed-demo-data.mjs C:\temp\attendance-demo.db
node --input-type=module -e "
import { startServer } from './dist/api/server.js';
const handle = await startServer('C:\\temp\\attendance-demo.db', 8080);
console.log('Listening on', handle.port);
"
```

Keep that running in a terminal. You'll need an admin credential and an
enrolled device/employee to drive the agent against it — read
`core/scripts/seed-demo-data.mjs` and `core/src/db/auth-repository.ts`
(`createAdminUser`, `loginAdmin`, `enrollDevice`) to get real
credentials and a device token; the demo seed already gives you
employees to use.

## 2. Build the Windows agent

```powershell
cd agent-windows
dotnet build -c Release
```

**This is the first real compile of this code.** Expected files and
what to watch for in each, based on what careful reading (not
compiling) already flagged:

| File | What it does | What to double-check on a real compile |
|---|---|---|
| `SessionMonitor.cs` | Session/power/foreground/input tracking. | The newest change wires `InputHook` in via a constructor lambda subscription (`_inputHook.OnInput += (kind, atUtc) => EmitInputActivity(kind, atUtc)`) — confirm the delegate signature (`Action<string, DateTime>`) matches exactly. `PollInput()` intentionally returns early (no-op) when `_inputHook is not null`, to avoid double-emitting input events — don't "simplify" this away. |
| `InputHook.cs` | New file. Low-level `WH_MOUSE_LL`/`WH_KEYBOARD_LL` hooks via `SetWindowsHookEx`, debounced 1/sec/kind. | `SetWindowsHookEx` requires a message loop on the thread that installed it (`Application.Run()` in `Program.cs` provides this — `InputHook` must be constructed on that same STA thread, which it is, via `SessionMonitor`'s constructor). The hook delegates are rooted as readonly fields specifically to avoid GC collecting them — don't refactor them into local lambdas. |
| `EventSyncClient.cs` | New file. Background sync loop, local SQLite → `POST /api/events/ingest`. | Never compiled. Check the `System.Net.Http`/`System.Text.Json` usage compiles cleanly against net8.0-windows (should be fine, but this is genuinely unverified). The mark-synced-only-after-confirmed-success ordering in `SyncOneBatchAsync` is load-bearing — don't reorder it for "efficiency". |
| `SqliteRepository.cs` | Added `GetUnsyncedEvents` / `MarkEventsSynced` + a `SyncableEvent` record, backing the sync queue (`raw_events.synced_at_utc`, migration `core/src/db/migrations/006_sync.sql`). | Confirm these new methods respect the existing `_writeLock` pattern used by every other write method in this class — a second un-locked write path here would reintroduce exactly the concurrency bug this class's lock was added to prevent. |
| `Program.cs` | Entry point. | `EventSyncClient` is only constructed when both `ATTENDANCE_SERVER_URL` and `ATTENDANCE_DEVICE_TOKEN` env vars are set (`using var _ = syncClient;` for null-safe disposal) — confirm this builds; nullable reference handling here is new. |
| `UsageIngestPipeServer.cs`, `NoticeForm.cs`, `TrayPresence.cs`, `EventType.cs` | Unchanged this pass (or, for `TrayPresence.cs`, changed to open `local-dashboard.html` via `Process.Start`). | `agent-windows/README.md` documents two previously-caught bugs in the pipe server (named-argument ordering, missing `StreamReader` buffer-size arg) — already fixed in source, but this will be the first compiler confirmation either fix is actually correct. |

Fix whatever the compiler actually says. If something here was simply
wrong, fix it directly — these doc comments describe *intent*, not a
guarantee the code is bug-free; "reviewed, not compiled" means exactly
that.

Then publish:

```powershell
dotnet publish -c Release -r win-x64 --self-contained true -o .\publish
```

### Run it

```powershell
$env:ATTENDANCE_EMPLOYEE_ID = "emp-1"          # must exist on the server (seed script creates it)
$env:ATTENDANCE_DEVICE_ID   = "dev-1"           # must match an enrolled device
$env:ATTENDANCE_SERVER_URL  = "http://127.0.0.1:8080"   # omit both this and the token line to run local-only
$env:ATTENDANCE_DEVICE_TOKEN = "<token from enrollDevice()>"

.\publish\AttendanceAgent.exe
```

Expect: the on-device notice window appears first (must be dismissed
before anything is collected — this is the transparency gate, enforced
identically here and server-side); then a tray icon appears; right-
click → "View my activity today" should open a real browser tab at
`http://127.0.0.1:8080/local-dashboard?deviceToken=...` showing real
data for `emp-1`, not a stub page.

Confirm sync (if configured) by polling the admin API for new rows
within a few minutes:

```bash
curl -H "Authorization: Bearer <admin-token>" http://127.0.0.1:8080/api/employees/emp-1/sessions
```

## 3. Build and register the native-messaging host

```powershell
cd native-host
dotnet build -c Release          # also never compiled — fix whatever's wrong
dotnet publish -c Release -r win-x64 --self-contained true -o .\publish
```

Edit `com.attendancetracker.agent.json`: set `"path"` to the
**absolute** path of the published `AttendanceAgentNativeHost.exe`.

```powershell
$manifestPath = "C:\path\to\native-host\com.attendancetracker.agent.json"
New-Item -Path "HKCU:\Software\Google\Chrome\NativeMessagingHosts\com.attendancetracker.agent" -Force
Set-ItemProperty -Path "HKCU:\Software\Google\Chrome\NativeMessagingHosts\com.attendancetracker.agent" -Name "(Default)" -Value $manifestPath
```

## 4. Load the browser extension

1. `chrome://extensions` → enable Developer mode → **Load unpacked** →
   select `browser-extension/`.
2. Copy the real extension ID Chrome assigns.
3. Put `"chrome-extension://<real-id>/"` into
   `com.attendancetracker.agent.json`'s `allowed_origins`, replacing
   `REPLACE_WITH_ACTUAL_EXTENSION_ID`. Reload the extension.
4. With `AttendanceAgent.exe` running (notice already dismissed),
   click the extension's toolbar icon — popup should say "Connected".
   Switch tabs a few times.
5. Confirm server-side: a `GET /api/sessions/:id` for the current
   session should show `source: "browser-extension"` rows once synced
   (or query the local SQLite directly if running local-only).

## 5. Acceptance checklist — don't report "done" without checking these

- [ ] `agent-windows` and `native-host` both `dotnet build` clean, zero
      errors (warnings reviewed, not silently suppressed).
- [ ] `AttendanceAgent.exe` runs, shows the notice, writes to its local
      SQLite file (`%ProgramData%\AttendanceAgent\attendance.db` by
      default).
- [ ] New `input.activity` rows show `payload.kind` as `"mouse"` or
      `"keyboard"` — **not** `"keyboard-or-mouse"` — confirming
      `InputHook`'s low-level hooks actually installed on this machine.
      If you only ever see `"keyboard-or-mouse"`, the hooks failed to
      install (check for endpoint-security software blocking
      `SetWindowsHookEx` for an unsigned exe) and the agent fell back
      as designed — note this explicitly rather than treating it as
      silently fine.
- [ ] With `ATTENDANCE_SERVER_URL`/`ATTENDANCE_DEVICE_TOKEN` set,
      events actually appear server-side within a few minutes (not
      just "the client didn't throw").
- [ ] The native host responds `{"status":"connected"}` to the
      extension (visible via the extension popup) once both the agent
      and the registry entry are in place.
- [ ] A real tab switch in Chrome produces a real
      `source: "browser-extension"` row server-side.
- [ ] `local-dashboard.html` (opened from the tray menu) shows real
      numbers for the actual logged-in employee, not zeros or a blank
      page.

## Reference

The full project README (`README.md`, repo root) has the complete
status breakdown, architecture diagram, and the same build steps in
more narrative form — read it if anything above is ambiguous.
`agent-windows/README.md`, `native-host/Program.cs`'s own header
comment, and `browser-extension/README.md` each carry the specific
reasoning/known-bug history for their own folder — read those before
changing logic in a file, not just before compiling it.
