using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text;
using System.Text.Json;
using AttendanceTracker.Desktop.Models;

namespace AttendanceTracker.Desktop.Services;

public sealed class AttendanceApiClient
{
    private readonly HttpClient _http;
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
    };

    public AttendanceApiClient()
    {
        _http = new HttpClient
        {
            BaseAddress = new Uri(CoreEngineHost.Instance.BaseUrl),
            Timeout = TimeSpan.FromSeconds(30),
        };
    }

    private HttpRequestMessage Authed(HttpMethod method, string path)
    {
        var req = new HttpRequestMessage(method, path);
        if (!string.IsNullOrEmpty(SessionState.BearerToken))
            req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", SessionState.BearerToken);
        return req;
    }

    public async Task LocalViewerLoginAsync()
    {
        var res = await _http.PostAsync("/api/auth/local-viewer", null);
        if (!res.IsSuccessStatusCode)
        {
            var err = await res.Content.ReadAsStringAsync();
            throw new InvalidOperationException(ParseError(err) ?? "Could not open read-only view.");
        }
        var body = await res.Content.ReadFromJsonAsync<LoginResponse>(JsonOptions)
            ?? throw new InvalidOperationException("Empty viewer login response.");
        SessionState.BearerToken = body.Token;
        SessionState.Role = body.Role ?? "viewer";
        SessionState.SubjectId = body.DisplayName ?? "Workstation (read-only)";
    }

    public async Task<LoginResponse> AdminLoginAsync(string email, string password)
    {
        var res = await _http.PostAsJsonAsync("/api/auth/admin-login", new { email, password });
        if (!res.IsSuccessStatusCode)
        {
            var err = await res.Content.ReadAsStringAsync();
            throw new InvalidOperationException(ParseError(err) ?? "Sign-in failed.");
        }
        var body = await res.Content.ReadFromJsonAsync<LoginResponse>(JsonOptions)
            ?? throw new InvalidOperationException("Empty login response.");
        SessionState.BearerToken = body.Token;
        SessionState.Role = body.AdminId is not null ? "admin" : body.EmployeeId is not null ? "employee" : body.Role ?? "admin";
        SessionState.SubjectId = body.AdminId ?? body.EmployeeId ?? body.SubjectId ?? body.DisplayName ?? "Administrator";
        return body;
    }

    public async Task LogoutAsync()
    {
        if (!SessionState.IsAuthenticated)
            return;
        using var req = Authed(HttpMethod.Post, "/api/auth/logout");
        try { await _http.SendAsync(req); } catch { /* ignore */ }
        SessionState.Clear();
    }

    public async Task<List<EmployeeRow>> GetEmployeesAsync()
    {
        using var req = Authed(HttpMethod.Get, "/api/employees");
        var res = await _http.SendAsync(req);
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadFromJsonAsync<List<EmployeeRow>>(JsonOptions) ?? new();
    }

    public async Task<List<HistoryRow>> GetHistoryAsync(string dateFrom, string dateTo, string? employeeId = null, string? status = null)
    {
        var q = $"?dateFrom={Uri.EscapeDataString(dateFrom)}&dateTo={Uri.EscapeDataString(dateTo)}";
        if (!string.IsNullOrEmpty(employeeId))
            q += $"&employeeId={Uri.EscapeDataString(employeeId)}";
        if (!string.IsNullOrEmpty(status))
            q += $"&status={Uri.EscapeDataString(status)}";
        using var req = Authed(HttpMethod.Get, "/api/history" + q);
        var res = await _http.SendAsync(req);
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadFromJsonAsync<List<HistoryRow>>(JsonOptions) ?? new();
    }

    public async Task<DailyReport?> GetDailyReportAsync(string employeeId, string date)
    {
        var path = $"/api/reports/daily?employeeId={Uri.EscapeDataString(employeeId)}&date={Uri.EscapeDataString(date)}";
        using var req = Authed(HttpMethod.Get, path);
        var res = await _http.SendAsync(req);
        if (res.StatusCode == System.Net.HttpStatusCode.NotFound)
            return null;
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadFromJsonAsync<DailyReport>(JsonOptions);
    }

    public async Task<MonthlyReport?> GetMonthlyReportAsync(string employeeId, int year, int month)
    {
        var path = $"/api/reports/monthly?employeeId={Uri.EscapeDataString(employeeId)}&year={year}&month={month}";
        using var req = Authed(HttpMethod.Get, path);
        var res = await _http.SendAsync(req);
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadFromJsonAsync<MonthlyReport>(JsonOptions);
    }

    public async Task<List<CorrectionRequestRow>> GetCorrectionRequestsAsync(string? status = "pending")
    {
        var path = "/api/corrections/requests";
        if (!string.IsNullOrEmpty(status))
            path += $"?status={Uri.EscapeDataString(status)}";
        using var req = Authed(HttpMethod.Get, path);
        var res = await _http.SendAsync(req);
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadFromJsonAsync<List<CorrectionRequestRow>>(JsonOptions) ?? new();
    }

    public async Task ReviewCorrectionAsync(string requestId, bool approve, string? note)
    {
        var path = $"/api/corrections/requests/{Uri.EscapeDataString(requestId)}/review";
        using var req = Authed(HttpMethod.Post, path);
        req.Content = JsonContent.Create(new { approve, note });
        var res = await _http.SendAsync(req);
        if (!res.IsSuccessStatusCode)
        {
            var err = await res.Content.ReadAsStringAsync();
            throw new InvalidOperationException(ParseError(err) ?? "Review failed.");
        }
    }

    public async Task<byte[]> DownloadCsvAsync(string relativePath)
    {
        using var req = Authed(HttpMethod.Get, relativePath);
        var res = await _http.SendAsync(req);
        res.EnsureSuccessStatusCode();
        return await res.Content.ReadAsByteArrayAsync();
    }

    public async Task SaveCsvToFileAsync(string relativePath, string targetFile)
    {
        var bytes = await DownloadCsvAsync(relativePath);
        await File.WriteAllBytesAsync(targetFile, bytes);
    }

    private static string? ParseError(string json)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);
            if (doc.RootElement.TryGetProperty("error", out var err))
                return err.GetString();
        }
        catch { /* not json */ }
        return string.IsNullOrWhiteSpace(json) ? null : json;
    }
}
