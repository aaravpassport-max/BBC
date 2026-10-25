using System.Runtime.InteropServices;

namespace AttendanceAgent;

/// <summary>
/// STATUS NOTE: reviewed, not compiled or run — same discipline as every
/// file in this folder.
///
/// WHY THIS FILE EXISTS: GetLastInputInfo (SessionMonitor.cs's original
/// idle-detection API) tells you WHEN input last happened, but Windows
/// gives that single blended signal for keyboard AND mouse together —
/// there is no way to ask it "was the last input a key or a click?". The
/// synthetic-input ("mouse jiggler") detector built for this project
/// (core/src/engine/anomaly-detection.ts) specifically needs mouse-only
/// and keyboard-only timing kept SEPARATE — see its isMouseInput() check
/// for payload.kind === "mouse" — and the original SessionMonitor.cs
/// could never satisfy that: it hardcoded payload `{"kind":"keyboard-or-
/// mouse"}` on every emitted event, so the detector could never actually
/// identify a mouse-only run against real agent-emitted data. Found by
/// reading anomaly-detection.ts's own matching logic against what this
/// agent actually emits, not by assumption.
///
/// The fix is WH_MOUSE_LL / WH_KEYBOARD_LL low-level hooks via
/// SetWindowsHookEx, which DO distinguish the two input streams, each
/// with its own callback and its own timestamp. Debounced per kind to a
/// configurable minimum gap (default 1s) before this class raises
/// anything — without that, a single mouse swipe would fire this
/// callback hundreds of times a second, which is both wasteful and (for
/// the anomaly detector) actively counterproductive: it wants discrete,
/// meaningfully-spaced timestamps to compute a run's timing statistics
/// from, not a dense stream of sub-millisecond deltas.
///
/// Mouse MOVEMENT (not just clicks) is intentionally included as
/// "mouse" input — this is what lets the detector see a mouse-jiggler's
/// synthetic movement pattern at all; a design that only hooked clicks
/// would miss the exact case this was built to catch.
///
/// PRIVACY: only a kind ("mouse"/"keyboard") and a timestamp ever leave
/// this class — never cursor coordinates, never which key, never any
/// window/app context. This mirrors the deliberate schema limitation in
/// core/src/domain/events.ts's InputActivityEvent and the reasoning in
/// anomaly-detection.ts's own file header: the detector is bounded to
/// timing-pattern statistics by design, not an oversight, and this hook
/// is written to the same bound rather than quietly collecting more
/// than the engine it feeds was designed to receive.
/// </summary>
public sealed class InputHook : IDisposable
{
    private const int WH_MOUSE_LL = 14;
    private const int WH_KEYBOARD_LL = 13;
    private const int WM_KEYDOWN = 0x0100;
    private const int WM_SYSKEYDOWN = 0x0104;

    private delegate IntPtr LowLevelHookProc(int nCode, IntPtr wParam, IntPtr lParam);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern IntPtr SetWindowsHookEx(int idHook, LowLevelHookProc lpfn, IntPtr hMod, uint dwThreadId);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern bool UnhookWindowsHookEx(IntPtr hhk);

    [DllImport("user32.dll")]
    private static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);

    [DllImport("kernel32.dll")]
    private static extern IntPtr GetModuleHandle(string? lpModuleName);

    // Rooted as fields for the same reason SessionMonitor's
    // _foregroundEventDelegate is: SetWindowsHookEx does not keep a
    // managed reference to the delegate, so an unrooted one is eligible
    // for GC — if collected, Windows would later call a freed function
    // pointer and crash the process (or silently stop firing, in the
    // best case).
    private readonly LowLevelHookProc _mouseProc;
    private readonly LowLevelHookProc _keyboardProc;
    private readonly IntPtr _mouseHook;
    private readonly IntPtr _keyboardHook;

    private readonly TimeSpan _debounce;
    private DateTime _lastMouseEmit = DateTime.MinValue;
    private DateTime _lastKeyboardEmit = DateTime.MinValue;

    /// <summary>Fires with ("mouse" | "keyboard", the real UTC moment the hook observed it).</summary>
    public event Action<string, DateTime>? OnInput;

    /// <summary>True only if BOTH hooks installed successfully. A caller can fall back to GetLastInputInfo-only behavior if not — see SessionMonitor's constructor.</summary>
    public bool IsActive => _mouseHook != IntPtr.Zero && _keyboardHook != IntPtr.Zero;

    public InputHook(TimeSpan? debounce = null)
    {
        _debounce = debounce ?? TimeSpan.FromSeconds(1);
        _mouseProc = MouseHookCallback;
        _keyboardProc = KeyboardHookCallback;

        using var curModule = System.Diagnostics.Process.GetCurrentProcess().MainModule;
        var hModule = GetModuleHandle(curModule?.ModuleName);

        _mouseHook = SetWindowsHookEx(WH_MOUSE_LL, _mouseProc, hModule, 0);
        _keyboardHook = SetWindowsHookEx(WH_KEYBOARD_LL, _keyboardProc, hModule, 0);
        // A failed hook (IntPtr.Zero) is degraded, not fatal — same
        // posture as SessionMonitor's foreground hook: the caller checks
        // IsActive and decides what to fall back to; this class never
        // throws just because Windows refused one of the two hooks
        // (seen in practice when a restrictive endpoint-security policy
        // blocks low-level input hooks for unsigned executables — a real
        // deployment concern a signed, properly-installed build avoids).
    }

    private IntPtr MouseHookCallback(int nCode, IntPtr wParam, IntPtr lParam)
    {
        if (nCode >= 0)
        {
            var now = DateTime.UtcNow;
            if (now - _lastMouseEmit >= _debounce)
            {
                _lastMouseEmit = now;
                OnInput?.Invoke("mouse", now);
            }
        }
        return CallNextHookEx(_mouseHook, nCode, wParam, lParam);
    }

    private IntPtr KeyboardHookCallback(int nCode, IntPtr wParam, IntPtr lParam)
    {
        if (nCode >= 0 && (wParam == (IntPtr)WM_KEYDOWN || wParam == (IntPtr)WM_SYSKEYDOWN))
        {
            var now = DateTime.UtcNow;
            if (now - _lastKeyboardEmit >= _debounce)
            {
                _lastKeyboardEmit = now;
                OnInput?.Invoke("keyboard", now);
            }
        }
        return CallNextHookEx(_keyboardHook, nCode, wParam, lParam);
    }

    public void Dispose()
    {
        if (_mouseHook != IntPtr.Zero) UnhookWindowsHookEx(_mouseHook);
        if (_keyboardHook != IntPtr.Zero) UnhookWindowsHookEx(_keyboardHook);
    }
}
