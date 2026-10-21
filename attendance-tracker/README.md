# Workforce Attendance, Activity & Productivity Tracker

## Native Windows admin app (WPF — not Electron)

The **Workforce Attendance Console** under `desktop-wpf/` is a **native WPF** desktop application (Office-style navigation and chrome). It starts the proven `core/` API as a local sidecar and does **not** use Electron or an embedded browser for administration.

- Build & install: `docs/DESKTOP_INSTALL.md`
- Feature matrix: `docs/FEATURE_MIGRATION.md`
- Tests: `docs/TEST_REPORT.md`

---

A disclosed (not covert) employee monitoring system: automatic
attendance from sign-in/lock/sleep/sign-out events, a 5-minute
inactivity rule that backdates breaks to the last real input rather than
to when the threshold tripped, application and browser usage tracking,
and productivity classification — built for deployment on
company-owned Windows machines in India, with structural transparency
safeguards (a non-removable tray indicator, an employee self-view, and a
hard "transparency gate" that refuses to collect data until the employee
has acknowledged an on-device notice) enforced in code, not just policy.

## Status, honestly

This codebase mixes two very different kinds of "done," and conflating
them would be dishonest, so this README doesn't:

**Proven by executed tests** — `core/`, the platform-agnostic
TypeScript engine, persistence layer, and a role-based HTTP API
(`core/src/api/server.ts`). 120 tests via `npm test` (`node --test`
through `tsx`), covering: the backdated-break rule and its edge cases,
the employee state machine, idempotent/reproducible event ingestion, the
transparency gate, application-foreground and browser-URL reconciliation
(including the realistic case where Windows never sends a matching
"lost foreground" event), productivity classification with rule
precedence and time-windowed overrides, synthetic-input (mouse-jiggler)
pattern detection, the admin/employee/device role-based access-control
layer (session auth, cross-employee scoping enforced server-side, the
employee-correction-request lifecycle), the HTTP event-ingest endpoint
that a real Windows agent syncs through (8 tests — see below), and the
API layer's routes against a real HTTP socket (not mocked request/
response objects). `tsc --noEmit` is clean.

Two capabilities added after the initial 57-test baseline, both backed
by real executed verification rather than by reasoning about the code:

- **Synthetic-input ("mouse jiggler") detection**
  (`core/src/engine/anomaly-detection.ts`) — flags runs of mouse-only
  `input.activity` events whose inter-event timing is suspiciously
  regular (low coefficient of variation) AND has no corroborating
  keyboard/app/browser signal in the same window. It *flags for review*
  (`ReconciliationAnomaly`, type `synthetic_input_suspected`) rather than
  silently reclassifying time, and a flagged run is wired into
  `activity-reconciler.ts` so it can never refresh the break timer or
  end a break on its own — deliberately bounded (it can only see event
  timestamps and `payload.kind`, never cursor coordinates, by design of
  the event schema) and explicitly non-definitive, matching the request
  that led to it: flag, don't convict, and never penalize a legitimate
  low-input pattern without checking for corroboration first.
- **Role-based access control + employee correction requests**
  (`core/src/db/auth-repository.ts`, `core/src/engine/auth.ts`,
  migration `005_auth.sql`) — admin/employee/device sessions, scrypt
  password hashing, SHA-256-hashed bearer session tokens, and a
  long-lived per-device token (`enrollDevice`/`loginDevice`) that lets a
  machine get a session with no password prompt at all — the direct
  technical analog of trusting an already-established Windows identity.
  Every route past `/api/health` and `/api/auth/*` requires a valid
  session, and a non-admin session is force-scoped to its own
  `employeeId` **in `server.ts`'s routing itself**, not merely checked
  client-side — including the case where no `employeeId` query
  parameter was sent at all (`GET /api/history`). Employees can submit a
  `correction_requests` row; only an admin's explicit approval turns
  that into an actual `attendance_corrections` row — a rejection, or a
  request still pending, never touches derived data.
- **A real, live, non-sample-data employee dashboard**
  (`core/public/local-dashboard.html`, served at `GET /local-dashboard`
  by the same API server) — this is the one piece of this project's
  "dashboard" story that is NOT sample data. It authenticates via the
  device-token flow above (no password prompt), then fetches
  `/api/me`, `/api/reports/daily`, `/api/reports/monthly` and
  `/api/history` for the logged-in employee only and renders today's
  status/active/break time, a timeline bar, app/browser usage, and a
  12-month history table (absent days hidden by default, with a toggle
  to show them). Verified with `core/scripts/verify-local-dashboard.mjs`
  — a real headless Chromium (Playwright) loading the page against a
  real running server with real seeded data, cross-checking every
  rendered number against the same data fetched independently via the
  admin API. 11/11 checks passed on the run that produced this README
  update, including two real bugs this verification caught and fixed
  before it passed: a stray favicon request returning a console-visible
  401 (no unauthenticated route for it), and the 12-month history table
  rendering all ~366 calendar days — mostly absences — with no filter,
  which is real but unusable. This script isn't part of `npm test`
  because Playwright is intentionally not a dependency of `core/` (see
  its own file header); run it manually per the instructions there.
- **`POST /api/events/ingest`** (`core/src/api/server.ts`) — the first
  HTTP write path for raw events into this server; previously
  `ingestRawEvents()` was only callable from Node scripts/tests, never
  over a network. This is what closes the "multi-computer sync" gap on
  the server side: a device/employee/admin session can POST a batch of
  events, which are inserted idempotently (a resend of the same
  `eventId`s is a safe no-op) and immediately recomputed into real
  session/usage state — the same transparency gate and
  employee/device-scoping rules enforced everywhere else in this API
  apply here too (a device session cannot ingest for a foreign
  employeeId or deviceId; admin sessions may, for bulk-import). 8 tests
  in `core/test/api-ingest.test.ts` cover the success path (with an
  immediate cross-check via `GET /api/sessions/:id` that recompute
  actually ran, not just insertion), idempotent resend, cross-employee/
  cross-device 403 rejection, malformed/oversized-batch 400 rejection,
  the transparency gate's 403, and unauthenticated 401.
- **A per-device sync queue** — `raw_events.synced_at_utc`
  (migration `006_sync.sql`, a non-breaking `ALTER TABLE ADD COLUMN`)
  marks which locally-written rows have been confirmed received by the
  central server, so the Windows agent (reviewed, see below) has
  somewhere to track sync progress without re-sending its whole history
  every cycle.

Three real bugs were caught and fixed by actually *running* this layer,
not just reading or compiling it — the kind of bug "looks right, wasn't
executed" tends to hide:
1. `npm run build`'s compiled output was missing its migrations
   directory (`tsc` doesn't copy `.sql` files) — `openDatabase()` would
   crash at runtime for anyone consuming only `dist/`. Fixed with a
   build-script copy step, caught by actually importing and calling
   `dist/index.js`, not by reading the build config.
2. `recomputeSession()` never actually closed the `attendance_sessions`
   row — `closeAttendanceSession()` existed and was exported, but
   nothing anywhere called it, so every session stayed "open" forever
   regardless of a real `session.signout` event. Caught by seeding
   demo data, starting the real API server, and curling
   `/api/employees/:id/sessions` — `endUtc` came back `null` when it
   shouldn't have. Fixed, and now has its own test.
3. The demo-data seed script dated every event into the wrong month
   (`Date.UTC`'s month argument is 0-indexed; the script passed a
   1-indexed month straight through) — caught the same way, by querying
   the real API for a specific date and getting an empty result back.

See `core/scripts/seed-demo-data.mjs` to reproduce: it seeds two
employees' realistic workdays (including a genuine multi-break day and
a monitoring-gap scenario), and `core/src/api/server.ts`'s own doc
comment shows how to start the server against it and curl the routes.

**Reviewed carefully, not compiled or run** — `agent-windows/`,
`native-host/`, `browser-extension/`. This sandbox is Linux with no
.NET SDK and no real Chrome install; the Windows APIs and
Chrome Native Messaging protocol these depend on don't exist here
regardless. Each of those three folders has its own README stating
this plainly and listing exactly what would need to happen (a real
`dotnet build`, native-host registration, a round trip through actual
Chrome) before any of it touches a real employee machine. Close reading
— not a compiler — already caught four real bugs in this code (two in
the Windows agent's session handling, two in the Stage 2 pipe-server/
native-host code); each is documented where it was found, because
"reviewed, not executed" is a real limitation this project doesn't paper
over, not a disclaimer to say once and ignore.

`agent-windows/TrayPresence.cs`'s "View my activity today" menu item was
changed this pass to open the real, proven `local-dashboard.html` above
in the system's default browser (via a device token read from
`ATTENDANCE_SERVER_URL`/`ATTENDANCE_DEVICE_TOKEN`), falling back to the
original Stage 1 local-event listing when those aren't configured yet.
That change is still reviewed-not-compiled C# like the rest of this
folder — but it's a thin handoff on purpose: the part doing real work
(the dashboard page and the API it calls) is the part that's actually
built and tested, not this file.

Two more additions to `agent-windows/` this pass, both reviewed and
cross-checked line-by-line against the server-side code they talk to
(which IS proven — see the ingest-endpoint tests above), but — like
everything else in this folder — not compiled or run, since this
sandbox has no .NET SDK or Windows APIs to run them against:

- **`EventSyncClient.cs`** — a background loop that reads not-yet-
  synced local events (`SqliteRepository.GetUnsyncedEvents`), exchanges
  this machine's device token for a session the same way the local
  dashboard above does, POSTs a batch to `/api/events/ingest`, and only
  marks those exact rows synced (`SqliteRepository.MarkEventsSynced`)
  *after* the server confirms receipt — never before sending, so a
  crash or dropped connection mid-sync re-sends on the next cycle
  instead of losing or double-counting anything (the server's own
  idempotent-resend behavior, tested above, is the safety net this
  relies on). Any failure — network down, DNS failure, a revoked
  device token — is caught broadly, logged, and retried with a capped
  exponential backoff; it never blocks or slows the agent's own local
  writes, and there is no "give up and drop the backlog" code path.
  Wired into `Program.cs`, started only when
  `ATTENDANCE_SERVER_URL`/`ATTENDANCE_DEVICE_TOKEN` are both configured
  — a machine with neither keeps working exactly as before, local-only.
- **`InputHook.cs`**, fixing a real integration bug found by reading
  this agent's own output against the consumer it feeds: the original
  `SessionMonitor.PollInput()` used `GetLastInputInfo`, which blends
  mouse and keyboard into one signal and can never distinguish them —
  so every `input.activity` event it wrote was hardcoded
  `payload.kind: "keyboard-or-mouse"`. The synthetic-input
  (mouse-jiggler) detector built and tested in `core/` requires
  `payload.kind === "mouse"` *exactly* to ever flag anything — meaning
  that detector, while fully proven against hand-built event streams,
  could never have fired against one real minute of actual agent data.
  `InputHook.cs` adds real `WH_MOUSE_LL`/`WH_KEYBOARD_LL` low-level
  Win32 hooks (debounced to 1 event/second/kind, so a single mouse
  swipe doesn't flood the database), and `SessionMonitor.cs` now uses
  them as its primary input source — falling back to the original
  blended `GetLastInputInfo` polling only if both hooks fail to install
  (e.g. blocked by an endpoint-security policy). `PollInput()` still
  runs unconditionally for its other, unrelated job: detecting a
  `GetLastInputInfo` failure and emitting `agent_heartbeat_missed`.

**The admin console** (`dashboard/console.html`, published as a Claude
artifact) now has a role-switcher demo (admin vs. employee nav
visibility), an employee "My correction requests" self-service view,
and an admin "Correction requests" review queue (Approve/Reject),
verified with a temporary Playwright script against the artifact's own
DOM (10/10 checks passed) — but this is explicitly a client-side VIEW
SWITCHER over seeded sample data for 8 fictional employees, not a
server-authenticated login, and it is still not wired to read real
recomputed data out of a deployed `attendance.db`. It mirrors the real
server-side RBAC rules (proven in `core/`) in its UI logic rather than
replacing them — that wiring to a real, reachable server is the gap
described below.

## Architecture

```
Windows agent (agent-windows/, C#/.NET 8)
  - SessionMonitor + InputHook: sign-in/lock/sleep/foreground-app via
    Win32 + SystemEvents, real mouse-vs-keyboard via WH_MOUSE_LL/
    WH_KEYBOARD_LL low-level hooks
  - UsageIngestPipeServer: named-pipe relay for browser events
  - NoticeForm/TrayPresence: the transparency gate + visible indicator
  - EventSyncClient: background loop, local SQLite -> central server
        |
        v  writes raw_events (SQLite, via Microsoft.Data.Sqlite)
        |                                   \
        |                                    v  EventSyncClient reads
        |                                       unsynced rows, POSTs them
        |                                       (see below), and marks
        |                                       them synced on success
        |
Browser extension (browser-extension/, Manifest V3)
  - tracks active tab / URL / navigation
        |
        v  Chrome Native Messaging
Native host shim (native-host/, thin C# relay, no identity, no SQLite)
        |
        v  named pipe -> UsageIngestPipeServer above
        |
core engine (core/, TypeScript, Node's built-in node:sqlite)
  - activity-reconciler.ts: raw_events -> state_intervals / break_intervals
  - usage-reconciler.ts:    raw_events -> app_foreground_intervals / browser_url_intervals
  - classification.ts:      + classification_rules -> productivity summary
  - repository.ts:          the transparency gate, idempotent ingest,
                             delete-and-reinsert recompute (reproducibility
                             guarantee, proven by test)
        |
        v
Role-based HTTP API (core/src/api/server.ts) — real, run, curled, proven.
  - auth-repository.ts:  admin/employee/device sessions, device-token
                          login, employee correction-request lifecycle
  - POST /api/events/ingest: the network endpoint EventSyncClient (and
                          any other machine) POSTs synced batches to —
                          same employeeId/deviceId scoping and
                          transparency gate as every other route, real
                          HTTP tests in api-ingest.test.ts
        |         \
        |          v  GET /local-dashboard (unauthenticated route serving
        |             core/public/local-dashboard.html, which then
        |             authenticates itself via device-token login) ->
        |             REAL, LIVE employee self-view — not sample data,
        |             verified by scripts/verify-local-dashboard.mjs
        v
Admin console (dashboard/console.html) — RBAC/correction-request demo
over sample data; not yet fetching from the API above (see "What's left")
```

Everything downstream of `raw_events` is a pure, fully-recomputable
projection — no hand-edited derived rows anywhere. Admin corrections go
through `attendance_corrections`, which preserves the original computed
value rather than silently overwriting it.

## Running the proven part

```bash
cd core
npm install
npm test        # 120 tests, node:test via tsx
npm run build   # tsc + copies migrations and public/ assets into dist/

# Seed realistic demo data and run the real API against it:
node scripts/seed-demo-data.mjs /tmp/attendance-demo.db
node --input-type=module -e "
import { startServer } from './dist/api/server.js';
const handle = await startServer('/tmp/attendance-demo.db', 8080);
console.log('Listening on', handle.port);
"
# then, after creating an admin/employee/device credential (see
# auth-repository.ts's createAdminUser / setEmployeePassword / enrollDevice):
# curl -H "Authorization: Bearer <token>" http://127.0.0.1:8080/api/employees
# or open http://127.0.0.1:8080/local-dashboard?deviceToken=<token> in a browser

# Real-browser verification of the local employee dashboard (not part of
# `npm test` — needs Playwright, which is intentionally not a project
# dependency; see the script's own header for why):
node --import tsx scripts/verify-local-dashboard.mjs
```

## What's left (real gaps, not hidden ones)

Resolved this pass, for the record (so this list stays honest rather
than silently shrinking): multi-computer sync now has both a tested
server-side endpoint (`POST /api/events/ingest`) and a reviewed
agent-side client (`EventSyncClient.cs`) with capped-backoff retry on
any failure — the sync *client's* retry loop satisfies the "offline,
then retry" half of that old gap; and `SessionMonitor.cs`'s
mouse-vs-keyboard blind spot is fixed via `InputHook.cs`'s low-level
hooks, so the synthetic-input detector now has a real signal to
consume once this agent actually runs on a Windows machine (still
unverified beyond review — see below).

- **Wire `dashboard/console.html` (the admin console artifact) to a
  real server.** Still not done, and still for the same structural
  reason: the dashboard is a *published Claude artifact* on claude.ai's
  own infrastructure — it cannot reach a server on an admin's private
  company network unless that server has a real, internet-reachable
  URL, which nothing in this sandbox can stand up or prove reachable.
  The artifact's RBAC/correction-request views added this pass are a
  client-side demo over sample data, built to mirror the real
  server-side rules faithfully — not a substitute for the fetch() calls
  this gap is actually about. (The *employee-facing* dashboard has no
  such limitation: `local-dashboard.html` is served by the same server
  it reads from, so it's genuinely live today.)
- The API's write surface is still narrow outside of event ingest and
  correction requests: no HTTP endpoint yet for an admin to add a
  classification rule or enroll a device — those still require calling
  the repository/auth-repository functions directly (as
  `verify-local-dashboard.mjs` and the seed script do), not through the
  API.
- **Real device/deployment provisioning.** `ATTENDANCE_EMPLOYEE_ID`,
  `ATTENDANCE_DEVICE_ID`, `ATTENDANCE_SERVER_URL`, and
  `ATTENDANCE_DEVICE_TOKEN` are all still read from bare environment
  variables in `Program.cs` — correct for proving the mechanism works,
  but not a real IT-managed config/install flow. See the Windows build
  guide below for exactly how to set these for a real test run.
- **The original spec's 24 acceptance-test scenarios.** Searched for
  across this entire repository — they do not exist anywhere in it.
  Both `README.md` (this file, in earlier revisions) and
  `agent-windows/README.md` reference "the original specification
  (§17)" as if it were available to check against, but no such document
  is present in this codebase. Rather than fabricate scenario content
  from memory or invent a plausible-sounding list, this is named here
  as an open gap: if that specification exists outside this repo, it
  needs to be added before a systematic pass against it is possible.
- **An actual Windows build, a real Chrome round trip, and native-host
  registration.** Everything in `agent-windows/`, `native-host/`, and
  `browser-extension/` is reviewed source, cross-checked line-by-line
  against the server endpoints it talks to (which ARE compiled, run,
  and tested) — but none of it has been compiled or executed, because
  this sandbox is Linux with no .NET SDK and no real Chrome install.
  See **"Building the actual Windows app"** below for the exact
  commands to do this on a real Windows machine.

## Building the actual Windows app

Everything below is a precise instruction set for YOU to run on a real
Windows machine — this sandbox has no .NET SDK, no Windows APIs, and no
real Chrome install, so none of it has been run here. What's actually
been verified (real `tsc`/`npm test`/Playwright runs) is the `core/`
server this app talks to; what follows turns the reviewed-but-unbuilt
`agent-windows/`, `native-host/`, and `browser-extension/` source into a
running system on one real PC.

### 0. Prerequisites

- Windows 10/11.
- [.NET 8 SDK](https://dotnet.microsoft.com/download/dotnet/8.0) (the
  Windows one — `agent-windows/` and `native-host/` both target
  `net8.0-windows`, which needs the Windows reference assemblies the
  SDK installer pulls in automatically on Windows; this will NOT build
  on the generic/Linux SDK).
- Google Chrome (for the browser-extension half; skip §4–5 below if you
  only want attendance/app-foreground tracking, not browser-URL
  tracking).
- Node.js 20+ (for the `core/` server — see "Running the proven part"
  above; run that section first so you have a server to point the agent
  at).

### 1. Build and run the central server

Already covered above under "Running the proven part" — do that first.
You'll need, at minimum, one admin account (`createAdminUser` +
`loginAdmin` in `core/src/db/auth-repository.ts`) and one enrolled
device (`enrollDevice(db, employeeId, deviceId)`, which returns the
device token you'll use below). The quickest way to get all of this at
once is `node scripts/seed-demo-data.mjs <path-to-db>`, which seeds
realistic demo employees — read that script if you want to seed your
own employee instead of a demo one.

Decide the server's real, reachable URL now (`http://<your-PC's-LAN-
IP>:8080` is enough for a same-network test; a real deployment needs a
stable internet-reachable URL and TLS, which is outside this repo's
scope).

### 2. Build the Windows agent

```powershell
cd agent-windows
dotnet build -c Release
```

This is the first command that will tell you, for real, whether this
code compiles — it has not been run before now. If it fails, the error
output is exactly the thing to fix next; nothing in this repo has
papered over that possibility.

Once it builds, publish a self-contained executable:

```powershell
dotnet publish -c Release -r win-x64 --self-contained true -o .\publish
```

This produces `.\publish\AttendanceAgent.exe` plus its dependencies
(`Microsoft.Data.Sqlite`'s native SQLite binary included).

**Configure and run it** (set these before launching, in the same
shell or via `setx` for a persistent machine-level install):

```powershell
$env:ATTENDANCE_EMPLOYEE_ID = "emp-1"              # must match an employeeId already seeded on the server
$env:ATTENDANCE_DEVICE_ID   = "dev-1"               # must match the deviceId enrollDevice() was called with
$env:ATTENDANCE_DB_PATH     = "C:\ProgramData\AttendanceAgent\attendance.db"   # optional; this is the default
$env:ATTENDANCE_SERVER_URL  = "http://<server-ip>:8080"     # optional; omit to run fully local-only
$env:ATTENDANCE_DEVICE_TOKEN = "<the token enrollDevice() returned>"           # optional; omit to run fully local-only

.\publish\AttendanceAgent.exe
```

On first run you should see `NoticeForm`'s on-device monitoring notice —
it must be dismissed before any collection starts (the transparency
gate, enforced in code on both the agent and server side). After that,
check the system tray for the presence indicator; right-click it for
"View my activity today", which — if `ATTENDANCE_SERVER_URL`/
`ATTENDANCE_DEVICE_TOKEN` are set — opens the real, live
`local-dashboard.html` in your default browser.

**For a permanent install**, register the published `.exe` to launch
at logon (Task Scheduler "At log on" trigger for the specific user, or
a Startup-folder shortcut) — this repo does not include that
installer packaging; it's a one-time manual step per machine, or
something to script yourself with the `.exe` path above.

### 3. Verify the agent is really syncing

With `ATTENDANCE_SERVER_URL`/`ATTENDANCE_DEVICE_TOKEN` set, within
`EventSyncClient`'s poll interval (30s–10min depending on backoff
state) you should see new rows appear via the admin API:

```bash
curl -H "Authorization: Bearer <admin-token>" http://<server-ip>:8080/api/employees/emp-1/sessions
```

If nothing appears, check: the device token is still valid (an
`enrollDevice` call issues a NEW token each time — using a stale one
returns 401 from `/api/auth/device-login`, logged to the agent's
console by `EventSyncClient`'s broad catch block); the server URL has
no trailing slash mismatch; and the server is actually reachable from
the agent's machine (`curl` the same URL from that machine first).

### 4. Build and register the native-messaging host

```powershell
cd native-host
dotnet build -c Release
dotnet publish -c Release -r win-x64 --self-contained true -o .\publish
```

Edit `com.attendancetracker.agent.json`:
- Set `"path"` to the **absolute** path of the published
  `AttendanceAgentNativeHost.exe` (native messaging hosts require an
  absolute path — a relative one will silently fail to launch).
- Leave `"allowed_origins"` with the placeholder for now; you'll fill
  in the real extension ID in step 5.

Register it in the registry (PowerShell, run as the same user who will
run Chrome):

```powershell
$manifestPath = "C:\path\to\native-host\com.attendancetracker.agent.json"
New-Item -Path "HKCU:\Software\Google\Chrome\NativeMessagingHosts\com.attendancetracker.agent" -Force
Set-ItemProperty -Path "HKCU:\Software\Google\Chrome\NativeMessagingHosts\com.attendancetracker.agent" -Name "(Default)" -Value $manifestPath
```

(For an enterprise-managed rollout across many machines, the HKLM
equivalent plus a `ExtensionInstallForceList` Group Policy entry is the
standard pattern — not packaged in this repo; a real IT deployment step.)

### 5. Load the browser extension

1. In Chrome, go to `chrome://extensions`, enable **Developer mode**
   (top-right toggle).
2. **Load unpacked** → select the `browser-extension/` folder.
3. Chrome assigns a real extension ID — copy it from the extensions
   page.
4. Edit `native-host/com.attendancetracker.agent.json`'s
   `allowed_origins` entry to
   `"chrome-extension://<the-real-id>/"` (replacing
   `REPLACE_WITH_ACTUAL_EXTENSION_ID`), then reload the extension.
5. Click the extension's toolbar icon — its popup should show
   "Connected" once both the native host and `AttendanceAgent.exe` are
   running. Switch tabs a few times, then check for
   `source = 'browser-extension'` rows in `raw_events` via the admin
   API (`GET /api/sessions/:id` on the current session, once synced).

For a permanent/enterprise install, pin the extension's ID with a
`"key"` field in `manifest.json` (so it doesn't change between
machines) and push it via Chrome's
`ExtensionInstallForcelist` policy — again, a real deployment step
this repo doesn't package.

### Troubleshooting checklist

- **Agent won't start / NoticeForm never appears**: confirm
  `ATTENDANCE_EMPLOYEE_ID` is set — `Program.cs` throws immediately if
  it's missing, by design, rather than silently running unidentified.
- **"keyboard-or-mouse" still shows up in new `input.activity` rows**:
  `InputHook`'s `IsActive` check failed (both low-level hooks must
  install for the real mouse/keyboard split to take effect) — check
  for a security product blocking `SetWindowsHookEx` for an unsigned
  executable; signing the published `.exe` is the real fix for a
  production rollout.
- **Extension popup stuck on "agent_unreachable"**: the native host
  can only reach `AttendanceAgent.exe` after the transparency notice
  has been acknowledged (the pipe only opens after that gate passes) —
  confirm the agent's tray icon is present, not just that the process
  started.
- **`EventSyncClient` never syncs anything**: see §3 above.
