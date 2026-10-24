using System.IO;
using System.Text.Json;

namespace AttendanceTracker.Desktop.Services;

public sealed class AppConfig
{
    public int? PreferredPort { get; set; }
    public string? LastAdminEmail { get; set; }
    public bool FirstRunCompleted { get; set; }

    public static AppConfig Load()
    {
        try
        {
            if (!File.Exists(AppPaths.ConfigPath))
                return new AppConfig();
            var json = File.ReadAllText(AppPaths.ConfigPath);
            return JsonSerializer.Deserialize<AppConfig>(json) ?? new AppConfig();
        }
        catch
        {
            return new AppConfig();
        }
    }

    public void Save()
    {
        AppPaths.EnsureDirectories();
        var json = JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true });
        File.WriteAllText(AppPaths.ConfigPath, json);
    }
}
