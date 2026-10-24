using System.Runtime.InteropServices;
using Microsoft.Win32;

namespace AttendanceAgent;

/// <summary>
/// Wires Windows session/power/input signals to raw_events writes. This
/// class deliberately does NOT decide what counts as a "break" — it only
/// reports facts (an input happened at time T; the session locked at time
/// T) and lets the TS reconciler, already proven by executed tests,
/// derive state from them. Keeping that split is the whole point: the
/// one piece of logic worth getting subtly wrong (the backdating rule)
/// lives in exactly one place, in a language where I can actually run
/// tests against it.
/// </summary>
public sealed class SessionMonitor : IDisposable
{
    // GetLastInputInfo's dwTime is a 32-bit millisecond tick count — the
    // SAME domain as Environment.TickCount, which also wraps every ~49.7
    // days. Comparisons below use unchecked uint subtraction specifically
    // so a wraparound during that window doesn't produce a huge bogus
    // "idle time" spike. This is a well-known Win32 gotcha and worth
    // flagging explicitly rather than leaving as an unexplained cast.
    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO
    {
        public uint cbSize;
        public uint dwTime;
    }

    [DllImport("user32.dll")]
    private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

    // ---- Foreground-application tracking (Stage 2) ----
    // EVENT_SYSTEM_FOREGROUND fires exactly when the foreground window
    // changes — this is the Windows notification the usage-reconciler.ts
    // doc comment describes as "reliably tells you app X became
    // foreground but not that app Y lost it", which is why the engine is
    // built around implicit-close-on-next-start rather than requiring a
    // matching foreground_end for every start.
    private const uint EVENT_SYSTEM_FOREGROUND = 0x0003;
    private const uint WINEVENT_OUTOFCONTEXT = 0x0000;

    private delegate void WinEventDelegate(IntPtr hWinEventHook, uint eventType, IntPtr hwnd, int idObject, int idChild, uint dwEventThread, uint dwmsEventTime);

    [DllImport("user32.dll")]
    private static extern IntPtr SetWinEventHook(uint eventMin, uint eventMax, IntPtr hmodWinEventProc, WinEventDelegate lpfnWinEventProc, uint idProcess, uint idThread, uint dwFlags);

    [DllImport("user32.dll")]
    private static extern bool UnhookWinEvent(IntPtr hWinEventHook);

    [DllImport("user32.dll")]
    private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

    [DllImport("user32.dll")]
    private static extern int GetWindowTextLength(IntPtr hWnd);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr hWnd, System.Text.StringBuilder lpString, int nMaxCount);

    // Kept as a field, not a local: SetWinEventHook does not root the
    // delegate on the managed side, and an unrooted delegate is eligible
    // for GC — if that happened, Windows would later invoke a freed
    // function pointer. This is a well-known P/Invoke callback pitfall,
    // worth flagging explicitly rather than leaving as an easy-to-miss
    // "why does this field exist" field.
    private readonly WinEventDelegate _foregroundEventDelegate;
    private readonly IntPtr _foregroundHook;
    private string? _lastReportedExecutableName;
    private string? _lastReportedWindowTitle;

    // Low-level WH_MOUSE_LL/WH_KEYBOARD_LL hooks (InputHook.cs) are the
    // fix for a real integration bug found by reading this agent's own
    // emitted payload against core/src/engine/anomaly-detection.ts's
    // isMouseInput() check: that detector requires payload.kind ===
    // "mouse" exactly, but PollInput() below always wrote the blended
    // "keyboard-or-mouse" string, so the detector could never fire
    // against real agent data. When the hook installs successfully,
    // it — not PollInput — becomes the source of input.activity events,
    // each correctly tagged "mouse" or "keyboard". PollInput still runs
    // unconditionally for its OTHER job (detecting a GetLastInputInfo
    // failure and emitting agent_heartbeat_missed), and only falls back
    // to emitting its own blended input.activity event when the hook
    // failed to install (e.g. blocked by an endpoint-security policy) —
    // degraded idle-detection coverage in that case, never a hard
    // failure, same posture as the foreground hook above.
    private readonly InputHook? _inputHook;

    private readonly SqliteRepository _repo;
    private readonly string _employeeId;
    private readonly string _deviceId;
    private readonly string _sessionId;
    private readonly System.Threading.Timer _pollTimer;
    private uint _lastReportedInputTick;

    // Poll every 10s. This is a detection-latency knob, NOT the 5-minute
    // inactivity threshold — that threshold lives entirely in the TS
    // engine's ReconcilerConfig and is never duplicated here. A larger
    // poll interval only means a slightly later timestamp on the raw
    // input.activity event if the agent process were killed between
    // polls; it does not change when a break is considered to have
    // started, because that's computed from the event's own timestamp,
    // not from when the agent happened to notice it.
    private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(10);

    public SessionMonitor(SqliteRepository repo, string employeeId, string deviceId, string sessionId)
    {
        _repo = repo;
        _employeeId = employeeId;
        _deviceId = deviceId;
        _sessionId = sessionId;

        SystemEvents.SessionSwitch += OnSessionSwitch;
        SystemEvents.PowerModeChanged += OnPowerModeChanged;
        SystemEvents.SessionEnding += OnSessionEnding;

        _lastReportedInputTick = ReadLastInputTick();
        _pollTimer = new System.Threading.Timer(_ => PollInput(), null, PollInterval, PollInterval);

        _inputHook = new InputHook();
        if (_inputHook.IsActive)
        {
            _inputHook.OnInput += (kind, atUtc) => EmitInputActivity(kind, atUtc);
        }
        else
        {
            // Both low-level hooks failed to install — fall back entirely
            // to PollInput's blended detection below rather than running
            // a half-working hook (e.g. mouse hook installed but keyboard
            // hook didn't): a consistently-blended signal is less
            // confusing to debug than a partially-split one.
            _inputHook.Dispose();
            _inputHook = null;
        }

        _foregroundEventDelegate = OnForegroundChanged;
        _foregroundHook = SetWinEventHook(
            EVENT_SYSTEM_FOREGROUND, EVENT_SYSTEM_FOREGROUND,
            IntPtr.Zero, _foregroundEventDelegate, 0, 0, WINEVENT_OUTOFCONTEXT);
        // A failed hook (returns IntPtr.Zero) means no foreground-app
        // events will be recorded this session — degraded, not fatal:
        // attendance/break tracking (the Stage 1 core this whole design
        // separates from Stage 2 usage tracking) is unaffected either way.
        if (_foregroundHook != IntPtr.Zero)
        {
            // Report whatever is already foreground at startup, not just
            // future changes — otherwise the first app_foreground_interval
            // wouldn't start until the employee switches windows once.
            ReportCurrentForeground();
        }
    }

    private void ReportCurrentForeground()
    {
        var hwnd = GetForegroundWindowSafe();
        if (hwnd != IntPtr.Zero)
        {
            EmitForegroundStartIfChanged(hwnd);
        }
    }

    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();

    private static IntPtr GetForegroundWindowSafe()
    {
        try { return GetForegroundWindow(); }
        catch { return IntPtr.Zero; }
    }

    private void OnForegroundChanged(IntPtr hWinEventHook, uint eventType, IntPtr hwnd, int idObject, int idChild, uint dwEventThread, uint dwmsEventTime)
    {
        // idObject != 0 / idChild != 0 filters out notifications for
        // sub-elements of a window rather than the window itself — the
        // OBJID_WINDOW (0) / CHILDID_SELF (0) case is the one that means
        // "the foreground TOP-LEVEL window changed".
        if (idObject != 0 || idChild != 0) return;
        if (hwnd == IntPtr.Zero) return;
        EmitForegroundStartIfChanged(hwnd);
    }

    private void EmitForegroundStartIfChanged(IntPtr hwnd)
    {
        string executableName;
        try
        {
            GetWindowThreadProcessId(hwnd, out var pid);
            using var process = System.Diagnostics.Process.GetProcessById((int)pid);
            executableName = process.ProcessName + ".exe";
        }
        catch
        {
            // A handful of legitimate reasons this can fail: the process
            // exited between the event firing and this lookup running, or
            // it's a privileged system process this user's token can't
            // query. Either way, silently skipping this one notification
            // is correct — there will be another EVENT_SYSTEM_FOREGROUND
            // the next time the user actually switches to something we
            // CAN resolve, so no permanent gap results from one miss.
            return;
        }

        string? windowTitle = null;
        try
        {
            var length = GetWindowTextLength(hwnd);
            if (length > 0)
            {
                var sb = new System.Text.StringBuilder(length + 1);
                GetWindowText(hwnd, sb, sb.Capacity);
                windowTitle = sb.ToString();
            }
        }
        catch { /* window title is optional context, never load-bearing */ }

        if (executableName == _lastReportedExecutableName && windowTitle == _lastReportedWindowTitle)
        {
            return; // genuinely nothing changed — do not emit a redundant event
        }
        _lastReportedExecutableName = executableName;
        _lastReportedWindowTitle = windowTitle;

        var payload = windowTitle is null
            ? $$"""{"executableName":"{{JsonEscape(executableName)}}"}"""
            : $$"""{"executableName":"{{JsonEscape(executableName)}}","windowTitle":"{{JsonEscape(windowTitle)}}"}""";

        TryWrite(new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: _employeeId,
            DeviceId: _deviceId,
            SessionId: _sessionId,
            EventType: EventType.AppForegroundStart,
            TimestampUtc: DateTime.UtcNow.ToString("o"),
            Source: EventSource.WindowsAgent,
            IsEstimated: false,
            PayloadJson: payload
        ));
    }

    /// <summary>
    /// Minimal JSON string escaping for the two fields built by hand above
    /// (executableName, windowTitle — the latter is arbitrary,
    /// attacker-influenceable window-title text, e.g. "My Doc \" DROP
    /// TABLE"). Not a general-purpose JSON writer; exists specifically
    /// because this file has no JSON library dependency elsewhere and
    /// adding one for two string fields was judged not worth it — if a
    /// third hand-built payload shows up anywhere in this file, that
    /// judgment call should be revisited in favor of System.Text.Json.
    /// </summary>
    private static string JsonEscape(string s)
    {
        var sb = new System.Text.StringBuilder(s.Length);
        foreach (var c in s)
        {
            switch (c)
            {
                case '"': sb.Append("\\\""); break;
                case '\\': sb.Append("\\\\"); break;
                case '\n': sb.Append("\\n"); break;
                case '\r': sb.Append("\\r"); break;
                case '\t': sb.Append("\\t"); break;
                default:
                    if (c < 0x20) sb.Append($"\\u{(int)c:x4}");
                    else sb.Append(c);
                    break;
            }
        }
        return sb.ToString();
    }

    private static uint ReadLastInputTick()
    {
        var lii = new LASTINPUTINFO { cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>() };
        if (!GetLastInputInfo(ref lii))
        {
            // Should essentially never happen, but never silently pretend
            // "no input" if the API call itself failed — surface it as a
            // monitoring-relevant fact rather than guessing.
            throw new InvalidOperationException("GetLastInputInfo failed — unable to read idle state.");
        }
        return lii.dwTime;
    }

    private void PollInput()
    {
        uint currentTick;
        try
        {
            currentTick = ReadLastInputTick();
        }
        catch (InvalidOperationException)
        {
            EmitHeartbeatMissed();
            return;
        }

        if (currentTick == _lastReportedInputTick)
        {
            return; // nothing new since the last report — emit nothing
        }

        // Reconstruct the actual wall-clock moment of this input from the
        // tick delta, rather than stamping it "now" — the gap between
        // when input happened and when this poll noticed it should not
        // leak into the recorded timestamp.
        uint nowTick = unchecked((uint)Environment.TickCount);
        uint msSinceInput = unchecked(nowTick - currentTick);
        var inputAtUtc = DateTime.UtcNow.AddMilliseconds(-(double)msSinceInput);

        _lastReportedInputTick = currentTick;

        if (_inputHook is not null)
        {
            // The low-level hooks are installed and already emitting
            // correctly-kinded events of their own (see EmitInputActivity
            // below) — PollInput's only remaining job on this branch is
            // the heartbeat-missed detection above; it must not ALSO
            // write a second, blended-kind event for the same input, or
            // every real input would produce two raw_events instead of
            // one, with conflicting payload.kind values.
            return;
        }

        // Fallback path only: the hooks did not install (see
        // constructor), so this blended signal is the best information
        // available — degraded (anomaly-detection's isMouseInput() still
        // cannot match it), but strictly better than no idle-detection
        // signal at all.
        TryWrite(new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: _employeeId,
            DeviceId: _deviceId,
            SessionId: _sessionId,
            EventType: EventType.InputActivity,
            TimestampUtc: inputAtUtc.ToString("o"),
            Source: EventSource.WindowsAgent,
            IsEstimated: false,
            PayloadJson: """{"kind":"keyboard-or-mouse"}"""
        ));
    }

    /// <summary>
    /// The real fix's write path: called directly from InputHook's
    /// OnInput event with a genuine "mouse" or "keyboard" kind and the
    /// hook's own timestamp (not "now" — same no-latency-leak principle
    /// PollInput already follows for its own tick-delta reconstruction
    /// above). Also advances _lastReportedInputTick to the current
    /// GetLastInputInfo tick so PollInput's own, separate
    /// heartbeat-missed check keeps working unchanged — it does not
    /// depend on which path produced the most recent input.activity row.
    /// </summary>
    private void EmitInputActivity(string kind, DateTime atUtc)
    {
        try { _lastReportedInputTick = ReadLastInputTick(); }
        catch (InvalidOperationException) { /* heartbeat check on the next poll tick will surface this */ }

        TryWrite(new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: _employeeId,
            DeviceId: _deviceId,
            SessionId: _sessionId,
            EventType: EventType.InputActivity,
            TimestampUtc: atUtc.ToString("o"),
            Source: EventSource.WindowsAgent,
            IsEstimated: false,
            PayloadJson: kind == "mouse" ? """{"kind":"mouse"}""" : """{"kind":"keyboard"}"""
        ));
    }

    private void OnSessionSwitch(object? sender, SessionSwitchEventArgs e)
    {
        // Deliberately NOT mapping SessionLogon/SessionLogoff here, even
        // though SessionSwitchReason has them. This agent is modeled as a
        // per-logon process (started by a logon-triggered mechanism —
        // Task Scheduler "at logon" or the Startup folder — and exited at
        // logoff), not a Windows Service watching multiple WTS sessions.
        // Under that model:
        //   - the TRUE signin signal is "this process just started",
        //     which Program.cs emits directly at startup — by the time
        //     this handler could fire for SessionLogon, the signal would
        //     be redundant at best, and for the CURRENT session it may
        //     never fire at all, since the process didn't exist to
        //     subscribe before the logon that spawned it.
        //   - logoff is already reported, more authoritatively and with
        //     a cancelable Reason, by OnSessionEnding below. Mapping
        //     SessionLogoff here too would emit a second, duplicate
        //     session.signout for the same real-world event.
        // If this agent is ever redeployed as a multi-session Windows
        // Service, THIS is the method that needs SessionLogon/Logoff
        // handling added back — deliberately left as a comment here
        // rather than silently absent, so the next person doesn't have
        // to rediscover this by hitting the duplicate-event bug.
        string? eventType = e.Reason switch
        {
            SessionSwitchReason.SessionLock => EventType.SessionLock,
            SessionSwitchReason.SessionUnlock => EventType.SessionUnlock,
            _ => null,
        };
        if (eventType is null) return;
        EmitNow(eventType, isEstimated: false);
    }

    private void OnPowerModeChanged(object? sender, PowerModeChangedEventArgs e)
    {
        string? eventType = e.Mode switch
        {
            PowerModes.Suspend => EventType.PcSleep,
            PowerModes.Resume => EventType.PcWake,
            _ => null, // PowerModes.StatusChange (e.g. battery status) — irrelevant here.
        };
        if (eventType is null) return;
        EmitNow(eventType, isEstimated: false);
    }

    private void OnSessionEnding(object? sender, SessionEndingEventArgs e)
    {
        // SessionEndReasons.Logoff vs SystemShutdown lets us distinguish
        // "signed out, PC stays on" from "machine is shutting down" — the
        // spec explicitly asks these be recorded as distinct event types,
        // not collapsed into one.
        string eventType = e.Reason == SessionEndReasons.SystemShutdown
            ? EventType.PcShutdown
            : EventType.SessionSignout;
        // Best-effort: a shutdown handler has a strict, short time budget
        // before Windows kills the process. This write must be fast and
        // must not throw past this handler, or the agent could be blamed
        // for delaying shutdown.
        try
        {
            EmitNow(eventType, isEstimated: false);
        }
        catch
        {
            // Swallowed deliberately and ONLY here: if this specific
            // last-gasp write fails, the next startup's crash-recovery
            // logic (not yet built — see README item "unexpected
            // shutdown handling") is responsible for reconciling the gap
            // as an estimated event, per the spec's "never fabricate a
            // precise logout time" requirement. Every other write path in
            // this file deliberately does NOT swallow exceptions.
        }
    }

    private void EmitNow(string eventType, bool isEstimated) => TryWrite(new RawEvent(
        EventId: Guid.NewGuid().ToString(),
        EmployeeId: _employeeId,
        DeviceId: _deviceId,
        SessionId: _sessionId,
        EventType: eventType,
        TimestampUtc: DateTime.UtcNow.ToString("o"),
        Source: EventSource.WindowsAgent,
        IsEstimated: isEstimated,
        PayloadJson: "{}"
    ));

    private void EmitHeartbeatMissed() => TryWrite(new RawEvent(
        EventId: Guid.NewGuid().ToString(),
        EmployeeId: _employeeId,
        DeviceId: _deviceId,
        SessionId: _sessionId,
        EventType: EventType.AgentHeartbeatMissed,
        TimestampUtc: DateTime.UtcNow.ToString("o"),
        Source: EventSource.WindowsAgent,
        IsEstimated: false,
        PayloadJson: """{"reason":"GetLastInputInfo failed"}"""
    ));

    private void TryWrite(RawEvent evt)
    {
        try
        {
            _repo.InsertRawEvent(evt);
        }
        catch (TransparencyGateException)
        {
            // This is not a bug to swallow-and-continue: if the gate
            // fires here, either the notice flow was skipped (should be
            // impossible given Program.cs's startup order) or the
            // acknowledgement row was deleted/corrupted. Surface loudly
            // rather than silently dropping employee data — but also
            // never crash-loop; the caller (Program.cs) decides whether
            // to re-show the notice screen.
            throw;
        }
        // Deliberately no broad catch beyond this: a write failure for
        // any other reason (disk full, locked file) should be visible,
        // not hidden. Stage 1 doesn't yet have the offline-queue fallback
        // the spec asks for — that's a named gap, not a silent one.
    }

    public void Dispose()
    {
        SystemEvents.SessionSwitch -= OnSessionSwitch;
        SystemEvents.PowerModeChanged -= OnPowerModeChanged;
        SystemEvents.SessionEnding -= OnSessionEnding;
        _pollTimer.Dispose();
        _inputHook?.Dispose();
        if (_foregroundHook != IntPtr.Zero)
        {
            UnhookWinEvent(_foregroundHook);
        }
    }
}
