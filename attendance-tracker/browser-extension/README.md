# Browser companion extension — status: reviewed source, not loaded or run

Like `agent-windows/`, this is source I wrote carefully and checked by
reading, but **I have not loaded it into a real Chrome instance** — there
is no browser to test it in from this sandbox, and `chrome.runtime
.connectNative` specifically requires a real native-messaging host
registration in the Windows registry, which doesn't exist anywhere except
on an actual deployed machine. That's a real gap, not a formality.

## What this is

Manifest V3 extension that reports which tab is active and which URL it's
on to the on-device agent (`../agent-windows`), so Stage 2's
`browser.tab_active_start` / `browser.tab_active_end` / `browser.navigation`
events — already proven correct by `core/test/usage-reconciler.test.ts`'s
14 executed tests — have a real source feeding them on a real employee
machine, not just synthetic test fixtures.

Only `http://` and `https://` pages are reported. Browser-internal pages
(`chrome://`, `about:`, extension pages) and, because MV3 extensions don't
run in incognito windows unless explicitly allowed, private browsing are
out of scope by construction, not by a runtime check someone could
disable.

## How it reaches the agent — the full chain

```
background.js (this extension)
   -> chrome.runtime.connectNative("com.attendancetracker.agent")
   -> Chrome spawns ../native-host/AttendanceAgentNativeHost.exe
   -> named pipe "AttendanceAgentUsageEvents"
   -> ../agent-windows's UsageIngestPipeServer.cs
   -> SqliteRepository.InsertRawEvent (same raw_events table, same
      transparency-gate check, same idempotent-insert logic as every
      other event source in this codebase)
```

A named pipe with an explicit current-user-only DACL was chosen over a
loopback HTTP server specifically to avoid an open network port, a secret
token to leak, and DNS-rebinding risk — see the comment at the top of
`UsageIngestPipeServer.cs` for the full reasoning.

## What would need to happen before this runs for real

1. **Load `AttendanceAgent.NativeHost`'s `dotnet build` output** and
   register `com.attendancetracker.agent.json` in the Windows registry
   (`HKCU\Software\Google\Chrome\NativeMessagingHosts\com.attendancetracker.agent`,
   pointing at the manifest's absolute path) — or, for an enterprise
   rollout, the HKLM equivalent plus a Group Policy / `ExtensionInstall
   ForceList` entry so employees can't simply uninstall the extension
   while the rest of the agent keeps running silently. This repo does not
   include that installer/GPO packaging; it's a named gap.
2. **Replace `REPLACE_WITH_ACTUAL_EXTENSION_ID`** in
   `com.attendancetracker.agent.json`'s `allowed_origins` with the real
   ID Chrome assigns once the extension is actually loaded (or, for an
   enterprise deploy, the fixed ID from a `key` field pinned in
   `manifest.json` so it doesn't change between installs).
3. **An actual round trip through real Chrome**: load the extension
   unpacked, confirm the popup shows "Connected" once the native host and
   agent are both running, switch tabs, and confirm rows land in
   `raw_events` with `source = 'browser-extension'`. None of this has
   happened yet.
4. **Minimum Chrome version**: this background script uses
   `chrome.storage.session`, available from Chrome 102+. Not yet stated
   anywhere in an admin-facing deployment doc — another named gap.

## What this deliberately does NOT do yet

- No offline queue: if the native host can't reach the pipe (agent not
  running, or notice not yet acknowledged), the in-flight browser event is
  dropped, not queued and retried. `UsageIngestPipeServer`'s Stage 1
  analogue (`ingestRawEvents` batch retry after an offline gap) exists on
  the Windows-agent side for its own event sources; this extension has no
  equivalent yet.
- No per-domain allow/block list (e.g. explicitly excluding a personal
  banking site even on a work browser profile) — every http/https page
  visited while the tab is active is reported, full stop. If that's not
  the right default for this deployment, it's a real product decision to
  make explicitly, not something to quietly configure around later.
