using AttendanceAgent;

namespace AttendanceTracker.Desktop.Services;

/// <summary>
/// Background session monitoring (tray + activity capture) inside the unified desktop app.
/// </summary>
public static class WorkplaceMonitoringHost
{
    private const string PolicyVersion = "v2026.1";
    private static WorkplaceMonitoringHostImpl? _instance;

    public static void Start()
    {
        if (_instance is not null)
            return;
        _instance = new WorkplaceMonitoringHostImpl();
        _instance.Start();
    }

    public static void Stop()
    {
        _instance?.Dispose();
        _instance = null;
    }

    private sealed class WorkplaceMonitoringHostImpl : IDisposable
    {
        private SqliteRepository? _repo;
        private TrayPresence? _tray;
        private SessionMonitor? _monitor;
        private UsageIngestPipeServer? _usageIngest;
        private System.Timers.Timer? _recomputeTimer;

        public void Start()
        {
            var install = AgentInstallConfig.Load();
            var dbPath = AppPaths.DatabasePath;

            _repo = new SqliteRepository(dbPath);

            if (!_repo.HasAcknowledgedNotice(install.EmployeeId, install.DeviceId))
            {
                _repo.RecordNoticeShown(install.EmployeeId, install.DeviceId, PolicyVersion);
                using var notice = new NoticeForm(PolicyVersion);
                notice.ShowDialog();
                if (!notice.Acknowledged)
                    return;
                _repo.RecordNoticeAcknowledged(install.EmployeeId, install.DeviceId);
            }

            var sessionId = Guid.NewGuid().ToString();
            var startedAtUtc = DateTime.UtcNow.ToString("o");
            _repo.EnsureAttendanceSession(sessionId, install.EmployeeId, install.DeviceId, startedAtUtc);
            _repo.InsertRawEvent(new RawEvent(
                EventId: Guid.NewGuid().ToString(),
                EmployeeId: install.EmployeeId,
                DeviceId: install.DeviceId,
                SessionId: sessionId,
                EventType: EventType.SessionSignin,
                TimestampUtc: startedAtUtc,
                Source: EventSource.WindowsAgent,
                IsEstimated: false,
                PayloadJson: "{}"
            ));
            _repo.InsertRawEvent(new RawEvent(
                EventId: Guid.NewGuid().ToString(),
                EmployeeId: install.EmployeeId,
                DeviceId: install.DeviceId,
                SessionId: sessionId,
                EventType: EventType.AgentStart,
                TimestampUtc: startedAtUtc,
                Source: EventSource.WindowsAgent,
                IsEstimated: false,
                PayloadJson: "{}"
            ));

            _tray = new TrayPresence(_repo, install.EmployeeId, install.DeviceId);
            _monitor = new SessionMonitor(_repo, install.EmployeeId, install.DeviceId, sessionId);
            _usageIngest = new UsageIngestPipeServer(_repo, install.EmployeeId, install.DeviceId, sessionId);

            _recomputeTimer = new System.Timers.Timer(TimeSpan.FromMinutes(1).TotalMilliseconds);
            _recomputeTimer.Elapsed += async (_, _) => await TriggerRecomputeAsync();
            _recomputeTimer.AutoReset = true;
            _recomputeTimer.Start();
            _ = TriggerRecomputeAsync();
        }

        private static async Task TriggerRecomputeAsync()
        {
            try
            {
                using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(10) };
                await client.PostAsync($"{CoreEngineHost.Instance.BaseUrl}/api/maintenance/recompute-open", null);
            }
            catch
            {
                /* core may still be starting */
            }
        }

        public void Dispose()
        {
            _recomputeTimer?.Stop();
            _recomputeTimer?.Dispose();
            _usageIngest?.Dispose();
            _monitor?.Dispose();
            _tray?.Dispose();
            _repo?.Dispose();
        }
    }
}
