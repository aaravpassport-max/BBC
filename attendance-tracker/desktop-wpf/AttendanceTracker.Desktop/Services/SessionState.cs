namespace AttendanceTracker.Desktop.Services;

public static class SessionState
{
    public static string? BearerToken { get; set; }
    public static string? Role { get; set; }
    public static string? SubjectId { get; set; }

    public static bool IsAuthenticated => !string.IsNullOrEmpty(BearerToken);

    public static bool IsAdmin => Role == "admin";

    public static bool CanModify => IsAdmin;

    public static void Clear()
    {
        BearerToken = null;
        Role = null;
        SubjectId = null;
    }
}
