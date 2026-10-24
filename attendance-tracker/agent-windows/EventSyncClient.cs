using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace AttendanceAgent;

/// <summary>
/// STATUS NOTE: reviewed, not compiled or run — same honesty discipline
/// as every other file in this folder (see README.md). What IS real,
/// executed, proven here: the server side this talks to —
/// POST /api/events/ingest in core/src/api/server.ts, covered by 8
/// passing tests in core/test/api-ingest.test.ts, including the exact
/// idempotent-resend and employeeId/deviceId-scoping behaviors this
/// client depends on.
///
/// Closes the "multi-computer sync" gap named in README.md's "What's
/// left": previously, each Windows agent's raw_events sat only in its
/// own local SQLite file (SqliteRepository.cs), with no path to a
/// central store an admin could see across machines from. This class is
/// that path — a background loop that:
///   1. Reads a batch of not-yet-synced local events (SqliteRepository.
///      GetUnsyncedEvents), oldest first.
///   2. Exchanges this machine's long-lived device token for a short
///      session via POST /api/auth/device-login — the SAME token
///      TrayPresence uses to open the local dashboard with no password
///      prompt, so there is exactly one credential per machine to
///      provision, not two.
///   3. POSTs the batch to POST /api/events/ingest.
///   4. Only on a successful (2xx) response, marks those exact eventIds
///      synced locally (SqliteRepository.MarkEventsSynced) — never
///      before sending, so a crash or dropped connection mid-request
///      just means the same rows get retried next cycle, not lost.
///
/// OFFLINE BEHAVIOR (the actual point of this class): if the network is
/// down, DNS fails, or the server is unreachable, the catch block below
/// swallows the failure, leaves every row unsynced, and the next timer
/// tick tries again — unboundedly, with a capped backoff, for as long
/// as the agent runs. There is no "give up and drop the backlog" path
/// anywhere in this file. The agent's LOCAL writes (SqliteRepository.
/// InsertRawEvent) are never blocked or slowed by sync failing — this
/// class only ever reads what's already safely on disk locally.
/// </summary>
public sealed class EventSyncClient : IDisposable
{
    private const int BatchSize = 200;
    private static readonly TimeSpan MinInterval = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan MaxBackoff = TimeSpan.FromMinutes(10);

    private readonly SqliteRepository _repo;
    private readonly HttpClient _http;
    private readonly string _serverUrl;
    private readonly string _deviceToken;
    private readonly CancellationTokenSource _cts = new();
    private readonly Task _loopTask;

    private string? _sessionToken;

    public EventSyncClient(SqliteRepository repo, string serverUrl, string deviceToken)
    {
        _repo = repo;
        _serverUrl = serverUrl.TrimEnd('/');
        _deviceToken = deviceToken;
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        _loopTask = Task.Run(() => RunLoopAsync(_cts.Token));
    }

    private async Task RunLoopAsync(CancellationToken ct)
    {
        var backoff = MinInterval;
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var syncedAnything = await SyncOneBatchAsync(ct);
                // A successful sync with more backlog than fit in one
                // batch should drain faster than a quiet period with
                // nothing to send — reset backoff on any success,
                // otherwise grow it, capped, so a genuinely offline
                // machine doesn't hammer a server it can't reach.
                backoff = syncedAnything ? MinInterval : backoff;
            }
            catch (Exception ex)
            {
                // Deliberately broad: ANY failure here (network down,
                // DNS failure, server 5xx, malformed response, the
                // device token having been revoked) must never crash
                // this background task or block the agent's own local
                // writes — it only means "try again later, with a
                // longer wait." A structured-logging sink is a named
                // gap across this whole project (see README.md); for
                // now this at least never goes silent in a way that
                // would hide a persistent misconfiguration from anyone
                // debugging locally.
                Console.Error.WriteLine($"[EventSyncClient] sync attempt failed, will retry: {ex.Message}");
                backoff = TimeSpan.FromSeconds(Math.Min(backoff.TotalSeconds * 2, MaxBackoff.TotalSeconds));
            }

            try { await Task.Delay(backoff, ct); } catch (TaskCanceledException) { /* shutting down */ }
        }
    }

    /// <returns>true if at least one event was successfully synced this cycle.</returns>
    private async Task<bool> SyncOneBatchAsync(CancellationToken ct)
    {
        var batch = _repo.GetUnsyncedEvents(BatchSize);
        if (batch.Count == 0) return false;

        await EnsureSessionAsync(ct);

        var payload = JsonSerializer.Serialize(new
        {
            events = batch.Select(e => new
            {
                eventId = e.EventId,
                employeeId = e.EmployeeId,
                deviceId = e.DeviceId,
                sessionId = e.SessionId,
                type = e.Type,
                timestampUtc = e.TimestampUtc,
                source = e.Source,
                isEstimated = e.IsEstimated,
                payload = JsonSerializer.Deserialize<object>(e.PayloadJson),
            }),
        });

        using var request = new HttpRequestMessage(HttpMethod.Post, $"{_serverUrl}/api/events/ingest")
        {
            Content = new StringContent(payload, Encoding.UTF8, "application/json"),
        };
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _sessionToken);
        using var response = await _http.SendAsync(request, ct);

        if (response.StatusCode == System.Net.HttpStatusCode.Unauthorized)
        {
            // The short-lived session token expired between cycles —
            // not a hard failure, just re-exchange the long-lived
            // device token next cycle rather than treating this as a
            // sync error that should back off.
            _sessionToken = null;
            return false;
        }
        response.EnsureSuccessStatusCode();

        // Only now, after the server has confirmed receipt, mark these
        // exact rows synced — see the class-level doc comment for why
        // this ordering (never mark-then-send) is the one that matters.
        _repo.MarkEventsSynced(batch.Select(e => e.EventId).ToArray());
        return true;
    }

    private async Task EnsureSessionAsync(CancellationToken ct)
    {
        if (_sessionToken is not null) return;
        var body = JsonSerializer.Serialize(new { deviceToken = _deviceToken });
        using var request = new HttpRequestMessage(HttpMethod.Post, $"{_serverUrl}/api/auth/device-login")
        {
            Content = new StringContent(body, Encoding.UTF8, "application/json"),
        };
        using var response = await _http.SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        var json = await response.Content.ReadAsStringAsync(ct);
        using var doc = JsonDocument.Parse(json);
        _sessionToken = doc.RootElement.GetProperty("token").GetString();
    }

    public void Dispose()
    {
        _cts.Cancel();
        try { _loopTask.Wait(TimeSpan.FromSeconds(5)); } catch { /* best-effort shutdown */ }
        _cts.Dispose();
        _http.Dispose();
    }
}
