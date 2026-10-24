namespace AttendanceTracker.Desktop.Models;

public sealed class LoginResponse
{
    public string Token { get; set; } = "";
    public string? Role { get; set; }
    public string? SubjectId { get; set; }
    public string? AdminId { get; set; }
    public string? EmployeeId { get; set; }
    public string? DisplayName { get; set; }
}

public sealed class EmployeeRow
{
    public string EmployeeId { get; set; } = "";
    public string DisplayName { get; set; } = "";
    public string? Department { get; set; }
    public string? Designation { get; set; }
}

public sealed class HistoryRow
{
    public string Date { get; set; } = "";
    public string EmployeeId { get; set; } = "";
    public string EmployeeName { get; set; } = "";
    public string? Department { get; set; }
    public string? DeviceId { get; set; }
    public int SessionCount { get; set; }
    public string? FirstLoginUtc { get; set; }
    public string? LastActivityUtc { get; set; }
    public bool HasOpenSession { get; set; }
    public string Status { get; set; } = "";
}

public sealed class CorrectionRequestRow
{
    public string RequestId { get; set; } = "";
    public string EmployeeId { get; set; } = "";
    public string SessionId { get; set; } = "";
    public string FieldName { get; set; } = "";
    public string RequestedValue { get; set; } = "";
    public string Reason { get; set; } = "";
    public string Status { get; set; } = "";
    public string CreatedAtUtc { get; set; } = "";
}

public sealed class DailyReport
{
    public string Date { get; set; } = "";
    public string EmployeeId { get; set; } = "";
    public string Status { get; set; } = "";
    public long ActiveMs { get; set; }
    public long BreakMs { get; set; }
    public int BreakCount { get; set; }
    public long MonitoringGapMs { get; set; }
    public int SessionCount { get; set; }
}

public sealed class MonthlyReport
{
    public int Year { get; set; }
    public int Month { get; set; }
    public string EmployeeId { get; set; } = "";
    public MonthlyTotals? Totals { get; set; }
}

public sealed class MonthlyTotals
{
    public int PresentDays { get; set; }
    public int AbsentDays { get; set; }
    public int IncompleteDays { get; set; }
    public long TotalActiveMs { get; set; }
    public long TotalBreakMs { get; set; }
}
