namespace AttendanceAgent;

/// <summary>
/// Mirrors core/src/domain/events.ts's RawEventType union exactly — same
/// strings, same set. Keep the two in lockstep; a mismatch here silently
/// produces raw_events rows the TS reconciler doesn't recognize.
/// </summary>
public static class EventType
{
    public const string PcStartup = "pc.startup";
    public const string PcShutdown = "pc.shutdown";
    public const string PcSleep = "pc.sleep";
    public const string PcWake = "pc.wake";
    public const string SessionSignin = "session.signin";
    public const string SessionSignout = "session.signout";
    public const string SessionLock = "session.lock";
    public const string SessionUnlock = "session.unlock";
    public const string InputActivity = "input.activity";
    public const string AppForegroundStart = "app.foreground_start";
    public const string AppForegroundEnd = "app.foreground_end";
    public const string AppLaunch = "app.launch";
    public const string AppExit = "app.exit";
    public const string BrowserTabActiveStart = "browser.tab_active_start";
    public const string BrowserTabActiveEnd = "browser.tab_active_end";
    public const string BrowserNavigation = "browser.navigation";
    public const string AgentStart = "agent.start";
    public const string AgentStop = "agent.stop";
    public const string AgentHeartbeatMissed = "agent.heartbeat_missed";
    public const string NetworkDisconnected = "network.disconnected";
    public const string NetworkReconnected = "network.reconnected";
}

/// <summary>Mirrors domain/events.ts's EventSource union.</summary>
public static class EventSource
{
    public const string WindowsAgent = "windows-agent";
    public const string BrowserExtension = "browser-extension";
    public const string AdminCorrection = "admin-correction";
}
