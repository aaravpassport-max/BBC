using System.Text.Json;

namespace AttendanceAgent;

/// <summary>
/// Per-machine install config written by the employee Setup.exe (or IT).
/// Environment variables still override for advanced deployment.
/// </summary>
public sealed record AgentInstallConfig(string EmployeeId, string DeviceId)
{
    private static readonly string ConfigDir = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "WorkforceAttendance");
    private static readonly string ConfigPath = Path.Combine(ConfigDir, "agent-config.json");

    public static AgentInstallConfig Load()
    {
        var fromEnvEmployee = Environment.GetEnvironmentVariable("ATTENDANCE_EMPLOYEE_ID");
        var fromEnvDevice = Environment.GetEnvironmentVariable("ATTENDANCE_DEVICE_ID");

        AgentInstallConfig? fromFile = null;
        if (File.Exists(ConfigPath))
        {
            try
            {
                var json = File.ReadAllText(ConfigPath);
                var doc = JsonSerializer.Deserialize<AgentConfigFile>(json);
                if (doc != null && !string.IsNullOrWhiteSpace(doc.employee_id))
                {
                    fromFile = new AgentInstallConfig(
                        doc.employee_id.Trim(),
                        string.IsNullOrWhiteSpace(doc.device_id) ? Environment.MachineName : doc.device_id.Trim());
                }
            }
            catch
            {
                /* corrupt file — fall through */
            }
        }

        var employeeId = (fromEnvEmployee ?? fromFile?.EmployeeId ?? DefaultEmployeeId()).Trim();
        var deviceId = (fromEnvDevice ?? fromFile?.DeviceId ?? Environment.MachineName).Trim();

        if (string.IsNullOrWhiteSpace(employeeId))
            employeeId = DefaultEmployeeId();

        return new AgentInstallConfig(employeeId, deviceId);
    }

    /// <summary>Windows user + machine — unique enough for single-user PCs until HR assigns an ID.</summary>
    private static string DefaultEmployeeId()
    {
        var user = Environment.UserName;
        if (string.IsNullOrWhiteSpace(user)) return Environment.MachineName;
        return $"{user}@{Environment.MachineName}";
    }

    private sealed class AgentConfigFile
    {
        public string? employee_id { get; set; }
        public string? device_id { get; set; }
    }
}
