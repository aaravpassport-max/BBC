using System.Diagnostics;
using System.Text.RegularExpressions;

namespace AttendanceTracker.Desktop.Services;

/// <summary>
/// The core engine uses Node's built-in <c>node:sqlite</c>, which requires Node 22+.
/// The v1.0.0 ZIP mistakenly bundled Node 20 — first-run bootstrap failed with
/// ERR_UNKNOWN_BUILTIN_MODULE.
/// </summary>
public static class NodeRuntime
{
    public const int MinimumMajorVersion = 22;

    /** Node flag required for <c>node:sqlite</c> on current Node 22 releases. */
    public const string SqliteFlag = "--experimental-sqlite";

    public static string BuildArguments(string scriptPath, string? trailingArgs = null)
    {
        var args = $"{SqliteFlag} \"{scriptPath}\"";
        if (!string.IsNullOrWhiteSpace(trailingArgs))
            args += " " + trailingArgs;
        return args;
    }

    public static void EnsureVersionOrThrow(string nodeExecutable)
    {
        var psi = new ProcessStartInfo
        {
            FileName = nodeExecutable,
            Arguments = "-v",
            UseShellExecute = false,
            RedirectStandardOutput = true,
            CreateNoWindow = true,
        };
        using var proc = Process.Start(psi) ?? throw new InvalidOperationException("Could not run node -v");
        var version = proc.StandardOutput.ReadToEnd().Trim();
        proc.WaitForExit(3000);
        var match = Regex.Match(version, @"v?(\d+)\.");
        if (!match.Success || !int.TryParse(match.Groups[1].Value, out var major) || major < MinimumMajorVersion)
        {
            throw new InvalidOperationException(
                $"This app requires Node.js {MinimumMajorVersion} or newer (bundled runtime\\node\\node.exe). " +
                $"Found: {version}. Download the latest AttendanceTracker ZIP from GitHub releases.");
        }
    }
}
