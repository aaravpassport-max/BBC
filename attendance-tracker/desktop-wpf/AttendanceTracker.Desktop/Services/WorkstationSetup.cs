using System.Diagnostics;
using System.IO;
using System.Text.Json;
using AttendanceAgent;

namespace AttendanceTracker.Desktop.Services;

/// <summary>
/// Ensures the local SQLite database exists for this PC and matches install config.
/// </summary>
public static class WorkstationSetup
{
    private static readonly string SecretsPath = Path.Combine(AppPaths.AppDataRoot, "workstation-secrets.json");

    public static async Task EnsureReadyAsync(CancellationToken cancellationToken = default)
    {
        var install = AgentInstallConfig.Load();
        AppPaths.EnsureDirectories();

        if (File.Exists(SecretsPath))
            return;

        var node = AppPaths.ResolveNodeExecutable()
            ?? throw new InvalidOperationException("Node.js 22+ is required (bundled in the installer).");

        NodeRuntime.EnsureVersionOrThrow(node);

        var script = Path.Combine(AppPaths.CoreDirectory, "scripts", "bootstrap-workstation.mjs");
        if (!File.Exists(script))
            throw new FileNotFoundException("Workstation bootstrap script is missing.", script);

        var trailing =
            $"\"{AppPaths.DatabasePath}\" \"{install.EmployeeId}\" \"{install.DeviceId}\" \"{EscapeArg(install.EmployeeId)}\"";
        var psi = new ProcessStartInfo
        {
            FileName = node,
            Arguments = NodeRuntime.BuildArguments(script, trailing),
            WorkingDirectory = AppPaths.CoreDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };

        using var proc = Process.Start(psi)
            ?? throw new InvalidOperationException("Could not run workstation bootstrap.");
        var output = await proc.StandardOutput.ReadToEndAsync(cancellationToken);
        var err = await proc.StandardError.ReadToEndAsync(cancellationToken);
        await proc.WaitForExitAsync(cancellationToken);
        if (proc.ExitCode != 0)
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(err) ? output : err);

        using var doc = JsonDocument.Parse(output.Trim());
        var root = doc.RootElement;
        if (!root.TryGetProperty("deviceToken", out var tokenEl))
            throw new InvalidOperationException("Bootstrap did not return a device token.");

        var secrets = new WorkstationSecrets
        {
            EmployeeId = install.EmployeeId,
            DeviceId = install.DeviceId,
            DeviceToken = tokenEl.GetString() ?? "",
        };
        secrets.Save();
    }

    public static WorkstationSecrets LoadSecrets()
    {
        if (!File.Exists(SecretsPath))
            return new WorkstationSecrets();
        try
        {
            var json = File.ReadAllText(SecretsPath);
            return JsonSerializer.Deserialize<WorkstationSecrets>(json) ?? new WorkstationSecrets();
        }
        catch
        {
            return new WorkstationSecrets();
        }
    }

    private static string EscapeArg(string value) => value.Replace("\\", "\\\\").Replace("\"", "\\\"");
}

public sealed class WorkstationSecrets
{
    public string EmployeeId { get; set; } = "";
    public string DeviceId { get; set; } = "";
    public string DeviceToken { get; set; } = "";

    public void Save()
    {
        AppPaths.EnsureDirectories();
        var path = Path.Combine(AppPaths.AppDataRoot, "workstation-secrets.json");
        var json = JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true });
        File.WriteAllText(path, json);
    }
}
