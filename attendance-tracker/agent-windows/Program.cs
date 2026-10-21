namespace AttendanceAgent;

internal static class Program
{
    private const string PolicyVersion = "v2026.1";

    [STAThread]
    private static void Main()
    {
        ApplicationConfiguration.Initialize();

        AgentInstallConfig install;
        try
        {
            install = AgentInstallConfig.Load();
        }
        catch (Exception ex)
        {
            AgentStartupError.ShowFatal("Could not read employee install settings.", ex);
            return;
        }

        var employeeId = install.EmployeeId;
        var deviceId = install.DeviceId;
        var dbPath = Environment.GetEnvironmentVariable("ATTENDANCE_DB_PATH")
            ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WorkforceAttendance", "attendance.db");

        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(dbPath)!);
        }
        catch (Exception ex)
        {
            AgentStartupError.ShowFatal("Could not create the data folder for this agent.", ex);
            return;
        }

        SqliteRepository repo;
        try
        {
            repo = new SqliteRepository(dbPath);
        }
        catch (Exception ex)
        {
            AgentStartupError.ShowFatal("Could not open the local attendance database.", ex);
            return;
        }

        using (repo)
        {
            try
            {
                RunAgent(repo, employeeId, deviceId);
            }
            catch (Exception ex)
            {
                AgentStartupError.ShowFatal("The attendance agent stopped because of an unexpected error.", ex);
            }
        }
    }

    private static void RunAgent(SqliteRepository repo, string employeeId, string deviceId)
    {

        // ---- The transparency gate, enforced at startup, not just at
        // write time. This ordering is the whole point: the notice is
        // shown and must be acknowledged BEFORE any monitoring object is
        // constructed, let alone started. ----
        if (!repo.HasAcknowledgedNotice(employeeId, deviceId))
        {
            repo.RecordNoticeShown(employeeId, deviceId, PolicyVersion);

            using var notice = new NoticeForm(PolicyVersion);
            Application.Run(notice);

            if (!notice.Acknowledged)
            {
                // Exit cleanly rather than starting collection anyway.
                // The next launch (next logon, or the next time a
                // scheduled task starts this agent) shows the notice
                // again — there is no retry-without-asking path.
                return;
            }

            repo.RecordNoticeAcknowledged(employeeId, deviceId);
        }

        // This agent is modeled as a per-logon process: launched by a
        // logon-triggered mechanism and exited at logoff. Under that
        // model, "this process just started" IS the true signin signal —
        // not an estimate — which is why session.signin is emitted here
        // directly rather than waited for from a SystemEvents callback
        // (see the comment in SessionMonitor.OnSessionSwitch for why that
        // callback deliberately does NOT also handle logon/logoff).
        var sessionId = Guid.NewGuid().ToString();
        var startedAtUtc = DateTime.UtcNow.ToString("o");
        repo.EnsureAttendanceSession(sessionId, employeeId, deviceId, startedAtUtc);
        repo.InsertRawEvent(new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: employeeId,
            DeviceId: deviceId,
            SessionId: sessionId,
            EventType: EventType.SessionSignin,
            TimestampUtc: startedAtUtc,
            Source: EventSource.WindowsAgent,
            IsEstimated: false,
            PayloadJson: "{}"
        ));
        repo.InsertRawEvent(new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: employeeId,
            DeviceId: deviceId,
            SessionId: sessionId,
            EventType: EventType.AgentStart,
            TimestampUtc: startedAtUtc,
            Source: EventSource.WindowsAgent,
            IsEstimated: false,
            PayloadJson: "{}"
        ));

        // Constructed together, deliberately: TrayPresence (the visible
        // indicator + employee self-view) exists for exactly as long as
        // SessionMonitor does. There is no code path in this file that
        // starts the monitor without it.
        using var tray = new TrayPresence(repo, employeeId, deviceId);
        using var monitor = new SessionMonitor(repo, employeeId, deviceId, sessionId);
        // Stage 2: accepts app-foreground/browser events relayed from the
        // native-messaging host the browser extension talks to. Started
        // only after the transparency gate above has already passed —
        // same ordering guarantee as SessionMonitor, for the same reason.
        using var usageIngest = new UsageIngestPipeServer(repo, employeeId, deviceId, sessionId);

        // Multi-computer sync (README.md's named gap): started only when
        // this install has actually been enrolled with a central server
        // and device token — both read the same ad-hoc way
        // ATTENDANCE_EMPLOYEE_ID already is above (Stage 1's own named
        // gap, not new here: there is still no real config file or
        // device-provisioning flow). A machine with neither configured
        // keeps working exactly as before — local-only, same as every
        // prior stage — so this is additive, never a new requirement to
        // get the agent running at all.
        var serverUrl = Environment.GetEnvironmentVariable("ATTENDANCE_SERVER_URL");
        var deviceToken = Environment.GetEnvironmentVariable("ATTENDANCE_DEVICE_TOKEN");
        EventSyncClient? syncClient = null;
        if (!string.IsNullOrWhiteSpace(serverUrl) && !string.IsNullOrWhiteSpace(deviceToken))
        {
            syncClient = new EventSyncClient(repo, serverUrl, deviceToken);
        }
        using var _ = syncClient;

        Application.Run();
    }
}
