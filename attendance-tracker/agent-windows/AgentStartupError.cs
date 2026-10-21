namespace AttendanceAgent;

internal static class AgentStartupError
{
    private static readonly string LogDir = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "WorkforceAttendance", "logs");

    public static void Log(Exception ex)
    {
        try
        {
            Directory.CreateDirectory(LogDir);
            var path = Path.Combine(LogDir, "agent-startup.log");
            File.AppendAllText(path, $"[{DateTime.UtcNow:o}] {ex}\r\n");
        }
        catch
        {
            /* best effort */
        }
    }

    public static void ShowFatal(string message, Exception? ex = null)
    {
        if (ex != null) Log(ex);
        MessageBox.Show(
            message + (ex != null ? $"\n\nTechnical detail: {ex.Message}" : "") +
            "\n\nIf this keeps happening, ask IT to check:\n" +
            $"%ProgramData%\\WorkforceAttendance\\agent-config.json\n" +
            $"%LocalAppData%\\WorkforceAttendance\\logs\\agent-startup.log",
            "Workforce Attendance Agent",
            MessageBoxButtons.OK,
            MessageBoxIcon.Error);
    }
}
