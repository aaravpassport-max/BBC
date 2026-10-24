using System.IO.Pipes;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;
using System.Text.Json;

namespace AttendanceAgent;

/// <summary>
/// Stage 2: receives app.foreground_* / browser.* events from local
/// companion processes over a named pipe and writes them into the same
/// raw_events table the rest of this agent writes to, via the same
/// SqliteRepository instance (see the _writeLock discussion added there).
///
/// WHY A NAMED PIPE AND NOT A LOOPBACK HTTP SERVER: an HTTP listener on
/// 127.0.0.1 is reachable by ANY process on the machine that can guess or
/// scan the port, and is subject to DNS-rebinding attacks from a
/// malicious web page if the "server" isn't careful about Origin
/// checking. A Windows named pipe under this specific name, with an
/// explicit DACL restricting access to the CURRENT user's SID, is
/// reachable only by processes running as the same logged-in user — a
/// strictly smaller, more correct attack surface for something that only
/// ever needs to talk to this one user's own browser. No open network
/// port, nothing to firewall, nothing to leak as a "secret token".
///
/// WHO CONNECTS TO THIS PIPE: not the browser extension directly — a
/// Manifest V3 extension cannot open a named pipe or raw socket. It
/// connects via Chrome's Native Messaging protocol to a tiny separate
/// executable (AttendanceAgent.NativeHost, in the sibling project),
/// which Chrome spawns and which does nothing but relay stdin/stdout
/// JSON to this pipe. See browser-extension/README.md for the full
/// chain and exactly what's unverified about it.
/// </summary>
public sealed class UsageIngestPipeServer : IDisposable
{
    public const string PipeName = "AttendanceAgentUsageEvents";

    private readonly SqliteRepository _repo;
    private readonly string _employeeId;
    private readonly string _deviceId;
    private readonly string _sessionId;
    private readonly CancellationTokenSource _cts = new();
    private readonly Task _acceptLoop;

    public UsageIngestPipeServer(SqliteRepository repo, string employeeId, string deviceId, string sessionId)
    {
        _repo = repo;
        _employeeId = employeeId;
        _deviceId = deviceId;
        _sessionId = sessionId;
        _acceptLoop = Task.Run(() => AcceptLoopAsync(_cts.Token));
    }

    private static PipeSecurity BuildCurrentUserOnlyPipeSecurity()
    {
        // Explicit allow-list DACL: only the identity this process is
        // running as may connect. Deliberately NOT using
        // PipeOptions.CurrentUserOnly alone for the server side, because
        // that flag (added in .NET Core 3.0) covers the common case well
        // but an explicit DACL is easier to verify by reading this file —
        // "what can connect to this pipe" should be answerable without
        // knowing a runtime library default by heart.
        var security = new PipeSecurity();
        var currentUser = WindowsIdentity.GetCurrent().User
            ?? throw new InvalidOperationException("Unable to resolve the current Windows user SID.");
        security.AddAccessRule(new PipeAccessRule(currentUser, PipeAccessRights.ReadWrite, AccessControlType.Allow));
        return security;
    }

    private async Task AcceptLoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            NamedPipeServerStream server;
            try
            {
                // All arguments named deliberately, not just the ones
                // after the first named one: C# requires every argument
                // after the first named argument to also be named
                // (CS1738) — an earlier version of this call mixed
                // positional and named args and would not have compiled;
                // caught by re-reading this against the actual
                // NamedPipeServerStreamAcl.Create overload signature,
                // not by a compiler, since none is available here.
                server = NamedPipeServerStreamAcl.Create(
                    pipeName: PipeName,
                    direction: PipeDirection.InOut,
                    maxNumberOfServerInstances: 1, // one native-host process at a time is all this needs
                    transmissionMode: PipeTransmissionMode.Byte,
                    options: PipeOptions.Asynchronous | PipeOptions.CurrentUserOnly,
                    inBufferSize: 4096,
                    outBufferSize: 4096,
                    pipeSecurity: BuildCurrentUserOnlyPipeSecurity());
            }
            catch (Exception)
            {
                // Pipe creation itself failing (e.g. a stale instance held
                // by a crashed previous process) should not crash the
                // whole agent — back off and retry rather than taking
                // down attendance tracking because the browser relay
                // couldn't bind.
                await Task.Delay(TimeSpan.FromSeconds(5), ct).ConfigureAwait(false);
                continue;
            }

            try
            {
                await server.WaitForConnectionAsync(ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                server.Dispose();
                return;
            }
            catch (Exception)
            {
                server.Dispose();
                continue;
            }

            // Handle this connection on its own task so a slow or
            // misbehaving client can't block the next one from being
            // accepted; AcceptLoopAsync immediately loops back to create
            // a fresh server instance for the next connection (named
            // pipes are single-connection-then-done, not a persistent
            // listener like a TCP socket).
            _ = Task.Run(() => HandleConnectionAsync(server, ct), ct);
        }
    }

    /// <summary>
    /// Wire format: newline-delimited JSON, one object per line, matching
    /// what AttendanceAgent.NativeHost relays from Chrome's native
    /// messaging frames. Each line: { "type": "...", "timestampUtc":
    /// "...", "payload": {...} }. This process (not the extension, not
    /// the relay) is the one place that knows this employee/device/
    /// session identity, so it stamps those fields itself rather than
    /// trusting them from the browser side.
    /// </summary>
    private async Task HandleConnectionAsync(NamedPipeServerStream server, CancellationToken ct)
    {
        using (server)
        {
            try
            {
                // The StreamReader overload that accepts leaveOpen also
                // requires an explicit bufferSize — there is no overload
                // with (stream, encoding, detectEncodingFromByteOrderMarks,
                // leaveOpen) and no bufferSize. 1024 chars is StreamReader's
                // own documented default buffer size when callers don't
                // otherwise specify one, used here explicitly rather than
                // relying on an overload that doesn't exist. Another catch
                // from re-reading against the actual API shape rather than
                // assuming a convenience overload existed.
                using var reader = new StreamReader(server, Encoding.UTF8, detectEncodingFromByteOrderMarks: false, bufferSize: 1024, leaveOpen: true);
                while (!ct.IsCancellationRequested)
                {
                    var line = await reader.ReadLineAsync(ct).ConfigureAwait(false);
                    if (line is null) break; // client disconnected
                    if (string.IsNullOrWhiteSpace(line)) continue;
                    HandleLine(line);
                }
            }
            catch (OperationCanceledException)
            {
                // Agent shutting down — not an error.
            }
            catch (Exception)
            {
                // A malformed line or an I/O fault on this one connection
                // must not take down the accept loop or the rest of the
                // agent. Stage 2 doesn't yet have structured logging (see
                // README) — this is a named gap, not a silent one.
            }
        }
    }

    private void HandleLine(string line)
    {
        UsageIngestMessage? msg;
        try
        {
            msg = JsonSerializer.Deserialize<UsageIngestMessage>(line, JsonOpts);
        }
        catch (JsonException)
        {
            return; // malformed frame — drop it, don't crash the connection over one bad line
        }
        if (msg is null || string.IsNullOrEmpty(msg.Type) || string.IsNullOrEmpty(msg.TimestampUtc)) return;
        if (!AllowedTypes.Contains(msg.Type)) return; // refuse to let a compromised extension write arbitrary event types

        var evt = new RawEvent(
            EventId: Guid.NewGuid().ToString(),
            EmployeeId: _employeeId,
            DeviceId: _deviceId,
            SessionId: _sessionId,
            EventType: msg.Type,
            TimestampUtc: msg.TimestampUtc,
            Source: EventSource.BrowserExtension,
            IsEstimated: false,
            PayloadJson: msg.Payload.ValueKind == JsonValueKind.Undefined ? "{}" : msg.Payload.GetRawText());

        try
        {
            _repo.InsertRawEvent(evt);
        }
        catch (TransparencyGateException)
        {
            // Same stance as SessionMonitor.TryWrite: this should be
            // impossible given Program.cs's startup ordering, but if the
            // gate somehow fires here, surface it loudly rather than
            // silently dropping employee browsing data. The caller is a
            // background task, so "surface loudly" currently means
            // letting the exception propagate out of HandleLine and be
            // caught by HandleConnectionAsync's catch-all, which is a
            // named gap (no alerting wired up yet) rather than a silent one.
            throw;
        }
    }

    /// <summary>
    /// Closed allow-list, not "anything SessionMonitor could theoretically
    /// emit" — this is the one place a message arriving from a browser
    /// extension (an inherently less-trusted source than this agent's own
    /// Win32 calls) gets validated against what a browser relay actually
    /// has legitimate reason to send.
    /// </summary>
    private static readonly HashSet<string> AllowedTypes = new()
    {
        EventType.BrowserTabActiveStart,
        EventType.BrowserTabActiveEnd,
        EventType.BrowserNavigation,
    };

    private static readonly JsonSerializerOptions JsonOpts = new() { PropertyNameCaseInsensitive = true };

    public void Dispose()
    {
        _cts.Cancel();
        try { _acceptLoop.Wait(TimeSpan.FromSeconds(2)); } catch { /* best-effort on shutdown */ }
        _cts.Dispose();
    }
}

internal sealed class UsageIngestMessage
{
    public string Type { get; set; } = "";
    public string TimestampUtc { get; set; } = "";
    public JsonElement Payload { get; set; }
}
