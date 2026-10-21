using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Text.Json;

namespace AttendanceTracker.Desktop.Services;

/// <summary>
/// Starts the proven TypeScript core API as a local sidecar (Node process).
/// The WPF shell is fully native; only the calculation engine runs in Node.
/// </summary>
public sealed class CoreEngineHost : IDisposable
{
    public static CoreEngineHost Instance { get; } = new();

    private Process? _process;
    private int _port;
    private bool _started;

    public int Port => _port;
    public string BaseUrl => $"http://127.0.0.1:{_port}";

    private CoreEngineHost() { }

    public async Task EnsureStartedAsync(CancellationToken cancellationToken = default)
    {
        if (_started)
            return;

        AppPaths.EnsureDirectories();
        var config = AppConfig.Load();

        if (!File.Exists(AppPaths.DatabasePath))
        {
            throw new InvalidOperationException(
                "No database found. Complete first-run setup from the sign-in screen.");
        }

        if (File.Exists(AppPaths.PortFilePath))
        {
            try { File.Delete(AppPaths.PortFilePath); } catch { /* best effort */ }
        }

        var node = AppPaths.ResolveNodeExecutable()
            ?? throw new InvalidOperationException(
                "Node.js was not found. Use the full Windows ZIP (includes runtime\\node\\node.exe) or install Node.js 22+.");

        NodeRuntime.EnsureVersionOrThrow(node);

        if (!File.Exists(AppPaths.StartServerScript))
            throw new FileNotFoundException("Core server script is missing from the installation.", AppPaths.StartServerScript);

        var psi = new ProcessStartInfo
        {
            FileName = node,
            Arguments = NodeRuntime.BuildArguments(AppPaths.StartServerScript),
            WorkingDirectory = AppPaths.CoreDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        psi.Environment["ATTENDANCE_DB_PATH"] = AppPaths.DatabasePath;
        psi.Environment["ATTENDANCE_PORT_FILE"] = AppPaths.PortFilePath;
        if (config.PreferredPort is > 0)
            psi.Environment["ATTENDANCE_PORT"] = config.PreferredPort.Value.ToString();

        _process = Process.Start(psi)
            ?? throw new InvalidOperationException("Failed to start the core API process.");

        _port = await WaitForPortFileAsync(cancellationToken);
        _started = true;

        // Health check
        using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
        var health = await client.GetAsync($"{BaseUrl}/api/health", cancellationToken);
        health.EnsureSuccessStatusCode();
    }

    public static async Task BootstrapDatabaseAsync(string email, string password, string displayName, bool seedDemo)
    {
        AppPaths.EnsureDirectories();
        var node = AppPaths.ResolveNodeExecutable()
            ?? throw new InvalidOperationException("Node.js 22+ is required for first-run setup (bundled in the ZIP).");

        NodeRuntime.EnsureVersionOrThrow(node);

        if (!File.Exists(AppPaths.BootstrapScript))
            throw new FileNotFoundException("Bootstrap script missing.", AppPaths.BootstrapScript);

        var trailing = $"\"{AppPaths.DatabasePath}\" \"{email}\" \"{password}\" \"{displayName}\"";
        if (seedDemo)
            trailing += " --seed-demo";

        var psi = new ProcessStartInfo
        {
            FileName = node,
            Arguments = NodeRuntime.BuildArguments(AppPaths.BootstrapScript, trailing),
            WorkingDirectory = AppPaths.CoreDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };

        using var proc = Process.Start(psi)
            ?? throw new InvalidOperationException("Could not run database bootstrap.");
        var err = await proc.StandardError.ReadToEndAsync();
        var output = await proc.StandardOutput.ReadToEndAsync();
        await proc.WaitForExitAsync();
        if (proc.ExitCode != 0)
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(err) ? output : err);
    }

    private static async Task<int> WaitForPortFileAsync(CancellationToken cancellationToken)
    {
        var deadline = DateTime.UtcNow.AddSeconds(30);
        while (DateTime.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (File.Exists(AppPaths.PortFilePath))
            {
                var json = await File.ReadAllTextAsync(AppPaths.PortFilePath, cancellationToken);
                using var doc = JsonDocument.Parse(json);
                if (doc.RootElement.TryGetProperty("port", out var portEl) && portEl.TryGetInt32(out var port))
                    return port;
            }
            await Task.Delay(200, cancellationToken);
        }
        throw new TimeoutException("Timed out waiting for the attendance engine to start.");
    }

    public void Dispose()
    {
        try
        {
            if (_process is { HasExited: false })
            {
                _process.Kill(entireProcessTree: true);
                _process.WaitForExit(3000);
            }
        }
        catch
        {
            // ignore on shutdown
        }
        finally
        {
            _process?.Dispose();
            _process = null;
            _started = false;
        }
    }
}
