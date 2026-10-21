using System.IO;

namespace AttendanceTracker.Desktop.Services;

public static class AppPaths
{
    public static string AppDataRoot =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AttendanceTracker");

    public static string DataDirectory => Path.Combine(AppDataRoot, "data");
    public static string DatabasePath => Path.Combine(DataDirectory, "attendance.db");
    public static string ConfigPath => Path.Combine(AppDataRoot, "config.json");
    public static string PortFilePath => Path.Combine(AppDataRoot, "server-port.json");
    public static string BackupsDirectory => Path.Combine(AppDataRoot, "backups");

    public static string InstallRoot
    {
        get
        {
            var baseDir = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
            // Published layout: .../AttendanceTracker/ with sibling core/ and runtime/node/
            var candidate = Path.GetFullPath(Path.Combine(baseDir, ".."));
            if (Directory.Exists(Path.Combine(candidate, "core", "dist")))
                return candidate;
            // Dev layout: repo root attendance-tracker/
            candidate = Path.GetFullPath(Path.Combine(baseDir, "..", "..", ".."));
            if (Directory.Exists(Path.Combine(candidate, "core", "dist")))
                return candidate;
            return baseDir;
        }
    }

    public static string CoreDirectory => Path.Combine(InstallRoot, "core");
    public static string StartServerScript => Path.Combine(CoreDirectory, "scripts", "start-server.mjs");
    public static string BootstrapScript => Path.Combine(CoreDirectory, "scripts", "bootstrap-desktop-db.mjs");

    public static string? ResolveNodeExecutable()
    {
        var bundled = Path.Combine(InstallRoot, "runtime", "node", "node.exe");
        if (File.Exists(bundled))
            return bundled;

        foreach (var name in new[] { "node.exe", "node" })
        {
            var pathEnv = Environment.GetEnvironmentVariable("PATH") ?? "";
            foreach (var dir in pathEnv.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
            {
                var full = Path.Combine(dir.Trim(), name);
                if (File.Exists(full))
                    return full;
            }
        }

        return null;
    }

    public static void EnsureDirectories()
    {
        Directory.CreateDirectory(DataDirectory);
        Directory.CreateDirectory(BackupsDirectory);
    }
}
