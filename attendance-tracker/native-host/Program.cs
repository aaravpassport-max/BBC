using System.IO.Pipes;
using System.Text;
using System.Text.Json;

namespace AttendanceAgent.NativeHost;

/// <summary>
/// Thin relay between Chrome's Native Messaging protocol (on stdin/stdout,
/// framed with a 4-byte little-endian length prefix per message — see
/// https://developer.chrome.com/docs/extensions/develop/concepts/native-messaging
/// for the protocol this implements against) and the main agent's named
/// pipe (UsageIngestPipeServer.cs, in ../agent-windows).
///
/// This process does NOT know the employee/device/session identity, does
/// NOT touch SQLite, and does NOT decide what counts as a valid event — it
/// only moves bytes from one transport to the other. That split exists so
/// the one process that holds real identity and writes real data
/// (AttendanceAgent.exe) never has to trust, or even run as, a
/// Chrome-spawned child process with Chrome's own lifecycle.
///
/// STATUS: reviewed source, not compiled or run — same disclosure as
/// agent-windows/README.md, and for the identical reason (no .NET SDK,
/// no Windows APIs, in this Linux sandbox). The Native Messaging framing
/// logic below follows the documented protocol closely enough that it
/// should be mechanically correct, but "should be" is exactly the kind of
/// claim this codebase's own discipline says not to round up to "is" —
/// native-host-manifest.json registration and an actual round trip
/// through a real Chrome install are the two things that would prove it,
/// and neither has happened.
/// </summary>
internal static class Program
{
    // MUST match UsageIngestPipeServer.PipeName in ../agent-windows exactly.
    // Duplicated here rather than shared via a project reference,
    // deliberately: this process is a separate, minimal executable Chrome
    // spawns on demand, and should not pull in the main agent's WinForms/
    // SystemEvents/SQLite dependency graph just to read one string
    // constant. Same cross-project mirroring discipline as EventType.cs
    // mirroring core/src/domain/events.ts — a change to one without the
    // other is a bug, and there is exactly one place in each project
    // that needs updating if the pipe name ever changes.
    private const string PipeName = "AttendanceAgentUsageEvents";

    private static async Task<int> Main()
    {
        using var stdin = Console.OpenStandardInput();
        using var stdout = Console.OpenStandardOutput();

        NamedPipeClientStream? pipe = null;
        StreamWriter? pipeWriter = null;

        async Task<bool> EnsurePipeConnectedAsync()
        {
            if (pipe is { IsConnected: true }) return true;
            pipe?.Dispose();
            pipe = new NamedPipeClientStream(".", PipeName, PipeDirection.InOut, PipeOptions.Asynchronous);
            try
            {
                // Short timeout, deliberately: this process's whole job is
                // to relay events promptly. If the main agent isn't
                // running (not yet started this logon, or the employee
                // hasn't acknowledged the notice screen yet — the agent
                // only opens this pipe AFTER that gate passes), failing
                // fast and reporting "unreachable" to the extension is
                // more honest than blocking silently.
                await pipe.ConnectAsync(1500).ConfigureAwait(false);
                pipeWriter = new StreamWriter(pipe, Encoding.UTF8) { AutoFlush = true, NewLine = "\n" };
                return true;
            }
            catch
            {
                pipe.Dispose();
                pipe = null;
                pipeWriter = null;
                return false;
            }
        }

        await SendToExtensionAsync(stdout, new { status = await EnsurePipeConnectedAsync() ? "connected" : "agent_unreachable" }).ConfigureAwait(false);

        while (true)
        {
            JsonElement? message;
            try
            {
                message = await ReadFrameAsync(stdin).ConfigureAwait(false);
            }
            catch (EndOfStreamException)
            {
                break; // Chrome closed the pipe — this is the normal way a native host process ends, not an error.
            }
            if (message is null) continue;

            var connected = await EnsurePipeConnectedAsync().ConfigureAwait(false);
            if (!connected)
            {
                await SendToExtensionAsync(stdout, new { status = "agent_unreachable" }).ConfigureAwait(false);
                continue; // drop this one message — no local queue/retry buffer in this version (named gap, see README)
            }

            try
            {
                // Forward verbatim as one compact JSON line — the main
                // agent's UsageIngestPipeServer is the one place that
                // validates shape and event-type allow-listing, so this
                // relay deliberately does not duplicate that logic.
                var raw = message.Value.GetRawText();
                if (raw.Contains('\n'))
                {
                    // A newline inside the JSON text itself (e.g. inside a
                    // string value) would corrupt the server's
                    // newline-delimited framing. Re-serialize compactly to
                    // guarantee no embedded raw newline survives, rather
                    // than trusting GetRawText()'s formatting.
                    raw = JsonSerializer.Serialize(message.Value);
                }
                await pipeWriter!.WriteLineAsync(raw).ConfigureAwait(false);
                await SendToExtensionAsync(stdout, new { status = "connected" }).ConfigureAwait(false);
            }
            catch
            {
                pipe?.Dispose();
                pipe = null;
                pipeWriter = null;
                await SendToExtensionAsync(stdout, new { status = "agent_unreachable" }).ConfigureAwait(false);
            }
        }

        pipe?.Dispose();
        return 0;
    }

    private static async Task<JsonElement?> ReadFrameAsync(Stream stdin)
    {
        var lengthBuf = new byte[4];
        var read = await ReadExactAsync(stdin, lengthBuf, 0, 4).ConfigureAwait(false);
        if (!read) throw new EndOfStreamException();
        var length = BitConverter.ToUInt32(lengthBuf, 0);
        // Chrome's documented ceiling for a single native-messaging
        // message is 1MB from the host; guard against a corrupt/hostile
        // length prefix driving an unbounded allocation.
        if (length == 0 || length > 1024 * 1024) throw new InvalidDataException($"Implausible native-messaging frame length: {length}");
        var body = new byte[length];
        if (!await ReadExactAsync(stdin, body, 0, (int)length).ConfigureAwait(false)) throw new EndOfStreamException();
        using var doc = JsonDocument.Parse(body);
        return doc.RootElement.Clone();
    }

    private static async Task<bool> ReadExactAsync(Stream stream, byte[] buffer, int offset, int count)
    {
        var total = 0;
        while (total < count)
        {
            var n = await stream.ReadAsync(buffer.AsMemory(offset + total, count - total)).ConfigureAwait(false);
            if (n == 0) return false; // stream closed mid-frame
            total += n;
        }
        return true;
    }

    private static async Task SendToExtensionAsync(Stream stdout, object payload)
    {
        var json = JsonSerializer.SerializeToUtf8Bytes(payload);
        var lengthPrefix = BitConverter.GetBytes((uint)json.Length);
        await stdout.WriteAsync(lengthPrefix).ConfigureAwait(false);
        await stdout.WriteAsync(json).ConfigureAwait(false);
        await stdout.FlushAsync().ConfigureAwait(false);
    }
}
