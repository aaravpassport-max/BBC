# Windows agent — status: reviewed source, not compiled or executed

Everything in this folder is C#/.NET source I wrote carefully and checked by
inspection, but **I have not compiled or run it** — this sandbox is Linux,
has no .NET SDK, and even with one installed, the APIs this agent depends
on (`Microsoft.Win32.SystemEvents`, `user32.dll` P/Invoke, the
`net8.0-windows` target framework) don't exist outside Windows, so there is
no honest way to execute or prove this code from here.

That is a real gap, not a formality. Before this runs on an actual
employee machine, it needs:

1. **A `dotnet build` on Windows** (or any machine with the .NET 8 SDK and
   Windows reference assemblies) to confirm it compiles at all. See the
   top-level `README.md`'s "Building the actual Windows app" section for
   the exact commands.
2. **A manual pass through the 24 acceptance scenarios** this file
   previously described as coming from "the original specification
   (§17)" — sign-in/sign-out session boundaries, the 5-minute backdated
   break, lock/sleep exclusion, unexpected shutdown recovery, etc., run
   against the real Windows session, lock, and power APIs, not
   simulated. **Correction, found while searching for that document in
   this pass**: no such specification exists anywhere in this
   repository — only this file and the top-level `README.md` referenced
   it, and neither held its actual content. If that document exists
   outside this repo, it needs to be brought in before a systematic
   pass against it is possible; this file won't keep citing it as if it
   were available here.
3. **A security review of the SQLite write path** — this agent writes
   directly to the same `attendance.db` file the admin console reads from
   (see `SqliteRepository.cs`); file permissions and concurrent-access
   behavior need verifying on a real filesystem.

## What this agent does

Mirrors the raw event vocabulary in `core/src/domain/events.ts` exactly —
`RawEventType` there and the `EventType` enum here are meant to be kept in
lockstep; a change to one without the other is a bug. It:

- Shows the on-device notice screen (`NoticeForm.cs`) on first run and
  **refuses to start activity collection until the employee dismisses it**.
  This is not a UI nicety — it mirrors the `TransparencyGateError` enforced
  server-side in `core/src/db/repository.ts`, so the same safeguard exists
  on both ends of the pipe. There is deliberately no admin flag, config
  file key, or silent-install switch anywhere in this source that skips or
  auto-acknowledges this screen on the employee's behalf.
- Tracks session lock/unlock, sleep/wake, and sign-in/out via
  `SystemEvents` and `WTSRegisterSessionNotification`.
- Detects the 5-minute inactivity threshold via `GetLastInputInfo`
  (Win32), on a background timer — the *detection* logic (comparing a gap
  against a threshold) intentionally stays tiny and dumb here; the real
  backdating/reconciliation logic already lives in, and is proven by, the
  TypeScript engine. This agent's only job is to get raw timestamps into
  `raw_events` correctly; it does not try to re-implement the reconciler.
- Writes directly to the same SQLite schema as `core/src/db/migrations/001_init.sql`
  via `Microsoft.Data.Sqlite`, so the admin console (or the TS reconciler,
  run as a scheduled job) can read what this agent wrote with no translation layer.

## What inspection actually found

No execution, but careful reading did catch two real design bugs, fixed
in this version:

1. **Missing signin signal.** The agent is modeled as a per-logon
   process (started by a logon-triggered mechanism, exits at logoff) —
   under that model, `SystemEvents.SessionSwitch`'s `SessionLogon`
   reason would essentially never fire for the agent's own session
   (the process didn't exist yet to subscribe before the logon that
   started it). Left as originally written, every session would have
   started in the reconciler's `signed_out` state with no signin event
   ever recorded, and the reconciler flags `input.activity` observed
   while `signed_out` as an anomaly rather than transitioning — so the
   whole session's activity would have come through as anomalous,
   never active. Fixed by having `Program.cs` emit `session.signin`
   directly at startup, since "this process just started" genuinely
   *is* the true signin signal in this deployment model.
2. **Duplicate signout.** `SystemEvents.SessionEnding` (handling
   logoff/shutdown with a cancelable `Reason`) and
   `SystemEvents.SessionSwitch`'s `SessionLogoff` reason would likely
   both fire for the same real logoff, each emitting its own
   `session.signout` with a distinct event ID — not deduplicated by the
   idempotent-insert logic, since they're genuinely different event
   objects. Fixed by removing the `SessionLogon`/`SessionLogoff`
   mapping from `SessionMonitor.OnSessionSwitch` entirely, with a
   comment explaining why, and relying on `SessionEnding` alone.

Neither of these would have been caught by eyeballing any single file —
they only showed up from tracing how `Program.cs` and `SessionMonitor.cs`
interact across the same event stream the TS reconciler consumes. That's
exactly the kind of bug "looks right, wasn't run" tends to hide, which is
the whole reason this README leads with "not compiled or executed"
instead of a quieter disclaimer.

## Stage 2 additions: foreground-app tracking and the browser relay

- **`SessionMonitor.cs`** now also hooks `EVENT_SYSTEM_FOREGROUND` via
  `SetWinEventHook`, resolving the newly-foregrounded window's owning
  process and title, and emits `app.foreground_start`. There is
  deliberately no corresponding `app.foreground_end` emission on every
  switch — `core/src/engine/usage-reconciler.ts` (proven by its own 14
  executed tests) is built specifically around the real Windows
  notification model, where a new `foreground_start` implicitly closes
  whatever was previously foreground. See that file's top-of-file comment
  for the full reasoning; this agent and that engine are meant to agree
  on this, not each independently guess.
- **`UsageIngestPipeServer.cs`** is new: a named pipe
  (`AttendanceAgentUsageEvents`, current-user-only DACL) that receives
  `browser.tab_active_start` / `browser.tab_active_end` /
  `browser.navigation` events relayed from the browser extension's
  native-messaging host (`../native-host`) and writes them through the
  same `SqliteRepository.InsertRawEvent` as every other event source —
  same transparency-gate check, same idempotent insert. A closed
  allow-list of event types is enforced here specifically because this is
  the one event source that's one hop further from this agent's own
  trusted Win32 calls.
- **`SqliteRepository.cs`** gained a `_writeLock` around every method
  that touches the shared `SqliteConnection`, now that a second thread
  (the pipe server's per-connection handler) can call into it concurrently
  with `SessionMonitor`'s poll-timer thread.

### What inspection caught in the Stage 2 code specifically

Same discipline as the two Stage 1 bugs above — found by reading, not by
a compiler, since none is available here:

3. **Mixed named/positional arguments in `NamedPipeServerStreamAcl.Create`**
   (`UsageIngestPipeServer.cs`): an earlier draft had
   `maxNumberOfServerInstances: 1` (named) followed by
   `PipeTransmissionMode.Byte` (positional) — C# requires every argument
   after the first named one to also be named (CS1738), so this would not
   have compiled. Fixed by naming every argument in that call.
4. **Missing `bufferSize` argument on the `StreamReader` constructor**
   (same file): the overload that accepts `leaveOpen` also requires an
   explicit `bufferSize` — there is no overload with
   `(stream, encoding, detectEncodingFromByteOrderMarks, leaveOpen)` and
   no buffer size. Fixed by passing `bufferSize: 1024` (StreamReader's own
   documented default) explicitly.

Both were caught by checking the actual, specific overload signatures
these calls resolve to rather than assuming a convenience overload
existed — the same kind of mistake "looks right, wasn't compiled" tends
to produce, now demonstrably caught twice in this codebase without a
compiler, not just claimed as a risk in the abstract.

## Stage 3 additions: central-server sync and real mouse/keyboard input

- **`EventSyncClient.cs`** is new: a background loop, started from
  `Program.cs` only when `ATTENDANCE_SERVER_URL` and
  `ATTENDANCE_DEVICE_TOKEN` are both configured, that reads not-yet-
  synced rows (`SqliteRepository.GetUnsyncedEvents`, backed by the new
  `raw_events.synced_at_utc` column — migration `006_sync.sql` on the
  `core/` side), exchanges the device token for a session via
  `POST /api/auth/device-login`, POSTs the batch to
  `POST /api/events/ingest`, and marks exactly those rows synced
  (`SqliteRepository.MarkEventsSynced`) only after a confirmed 2xx
  response. This directly answers this file's own former "what this
  agent still does NOT do" gap (network-outage event queuing): the
  queue itself is the existing local SQLite `raw_events` table (nothing
  new to buffer), and this class is the retry loop — any failure
  (network down, DNS failure, a 5xx, a revoked token) is caught
  broadly, logged, and retried with a capped exponential backoff,
  without ever blocking the agent's own local writes or giving up on
  the backlog. What's unverified beyond review: everything in this
  class, same as everything else in this folder — the **server side**
  it talks to is compiled, run, and covered by 8 passing HTTP tests
  (`core/test/api-ingest.test.ts`), but this client itself has not been
  built or run against a real server over a real network.
- **`InputHook.cs`** fixes a real integration bug this review found by
  reading this agent's own output against the engine it feeds: the
  original `SessionMonitor.PollInput()` used `GetLastInputInfo`, which
  cannot distinguish mouse from keyboard input, so every
  `input.activity` event this agent ever wrote was hardcoded
  `payload.kind: "keyboard-or-mouse"`. `core/src/engine/
  anomaly-detection.ts`'s synthetic-input (mouse-jiggler) detector —
  itself fully proven by executed tests — requires `payload.kind ===
  "mouse"` exactly to ever flag anything, meaning that detector could
  never have fired against one real minute of this agent's actual
  output, only against the hand-built event streams its own tests
  construct. `InputHook.cs` adds real `WH_MOUSE_LL`/`WH_KEYBOARD_LL`
  low-level Win32 hooks via `SetWindowsHookEx` (rooting its delegates
  as fields, same pattern `SessionMonitor`'s foreground hook already
  uses, for the same GC-pitfall reason), debounced to at most one
  emitted event per kind per second so a single mouse swipe doesn't
  flood `raw_events` with near-duplicate rows. `SessionMonitor.cs` uses
  the hook as its primary input source when both hooks install
  successfully, and falls back to the original blended
  `GetLastInputInfo` polling only if they don't (e.g. an endpoint-
  security policy blocking low-level input hooks for an unsigned
  executable — a real concern a signed, properly-installed build
  avoids). `PollInput()` keeps its separate, unrelated job
  unconditionally: detecting a `GetLastInputInfo` failure and emitting
  `agent_heartbeat_missed`.

## What this agent still does NOT do

- This agent still has no real config/provisioning file —
  `ATTENDANCE_EMPLOYEE_ID`, `ATTENDANCE_DEVICE_ID`,
  `ATTENDANCE_SERVER_URL`, and `ATTENDANCE_DEVICE_TOKEN` are all read
  from bare environment variables in `Program.cs`. Fine for proving the
  mechanism works end to end; not an IT-managed deployment story.
- No crash-recovery reconciliation for an *unexpected* shutdown
  specifically (power loss, not a clean sign-out/shutdown) — the spec's
  "never fabricate a precise logout time" requirement is honored
  (`OnSessionEnding`'s best-effort write either succeeds with a real
  timestamp or is silently skipped, never guessed), but nothing on next
  startup yet looks for "last session never got a signout row" and
  reconciles it as an estimated gap.
