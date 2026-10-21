using System.Globalization;
using System.IO;
using System.Text;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Models;
using AttendanceTracker.Desktop.Services;
using Microsoft.Win32;

namespace AttendanceTracker.Desktop.Views;

public partial class ReportsView : UserControl, IRefreshable
{
    private readonly AttendanceApiClient _api;
    private List<EmployeeRow> _employees = new();

    public ReportsView(AttendanceApiClient api)
    {
        _api = api;
        InitializeComponent();
        DayPicker.SelectedDate = DateTime.UtcNow.Date;
        for (var m = 1; m <= 12; m++)
            MonthBox.Items.Add(m);
        MonthBox.SelectedIndex = DateTime.UtcNow.Month - 1;
        for (var y = DateTime.UtcNow.Year - 2; y <= DateTime.UtcNow.Year + 1; y++)
            YearBox.Items.Add(y);
        YearBox.SelectedItem = DateTime.UtcNow.Year;
    }

    public async Task RefreshAsync()
    {
        try
        {
            _employees = await _api.GetEmployeesAsync();
            EmployeeBox.ItemsSource = _employees;
            if (_employees.Count > 0)
                EmployeeBox.SelectedIndex = 0;
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private EmployeeRow? SelectedEmployee =>
        EmployeeBox.SelectedItem as EmployeeRow;

    private async void LoadDaily_Click(object sender, RoutedEventArgs e)
    {
        var emp = SelectedEmployee;
        if (emp is null) return;
        var day = (DayPicker.SelectedDate ?? DateTime.UtcNow.Date).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        try
        {
            var report = await _api.GetDailyReportAsync(emp.EmployeeId, day);
            ReportBox.Text = report is null
                ? "No report for this day."
                : FormatDaily(report);
            StatusText.Text = "Daily report loaded.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private async void LoadMonthly_Click(object sender, RoutedEventArgs e)
    {
        var emp = SelectedEmployee;
        if (emp is null) return;
        var year = (int)(YearBox.SelectedItem ?? DateTime.UtcNow.Year);
        var month = (int)(MonthBox.SelectedItem ?? DateTime.UtcNow.Month);
        try
        {
            var report = await _api.GetMonthlyReportAsync(emp.EmployeeId, year, month);
            ReportBox.Text = report is null ? "No monthly data." : FormatMonthly(report);
            StatusText.Text = "Monthly report loaded.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private async void ExportDaily_Click(object sender, RoutedEventArgs e)
    {
        var emp = SelectedEmployee;
        if (emp is null) return;
        var day = (DayPicker.SelectedDate ?? DateTime.UtcNow.Date).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
        var dlg = new SaveFileDialog { FileName = $"daily-{emp.EmployeeId}-{day}.csv", Filter = "CSV|*.csv" };
        if (dlg.ShowDialog() != true) return;
        try
        {
            var path = $"/api/reports/daily.csv?employeeId={Uri.EscapeDataString(emp.EmployeeId)}&date={day}";
            await _api.SaveCsvToFileAsync(path, dlg.FileName);
            StatusText.Text = $"Saved {dlg.FileName}";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private async void ExportMonthly_Click(object sender, RoutedEventArgs e)
    {
        var emp = SelectedEmployee;
        if (emp is null) return;
        var year = (int)(YearBox.SelectedItem ?? DateTime.UtcNow.Year);
        var month = (int)(MonthBox.SelectedItem ?? DateTime.UtcNow.Month);
        var dlg = new SaveFileDialog { FileName = $"monthly-{emp.EmployeeId}-{year}-{month}.csv", Filter = "CSV|*.csv" };
        if (dlg.ShowDialog() != true) return;
        try
        {
            var path = $"/api/reports/monthly.csv?employeeId={Uri.EscapeDataString(emp.EmployeeId)}&year={year}&month={month}&variant=consolidated";
            await _api.SaveCsvToFileAsync(path, dlg.FileName);
            StatusText.Text = $"Saved {dlg.FileName}";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private static string FormatDaily(DailyReport r)
    {
        var sb = new StringBuilder();
        sb.AppendLine($"Date: {r.Date}");
        sb.AppendLine($"Status: {r.Status}");
        sb.AppendLine($"Active: {FormatMs(r.ActiveMs)}");
        sb.AppendLine($"Break: {FormatMs(r.BreakMs)} ({r.BreakCount} breaks)");
        sb.AppendLine($"Monitoring gaps: {FormatMs(r.MonitoringGapMs)}");
        sb.AppendLine($"Sessions: {r.SessionCount}");
        return sb.ToString();
    }

    private static string FormatMonthly(MonthlyReport r)
    {
        var t = r.Totals;
        if (t is null) return "No totals.";
        return $"Month {r.Month}/{r.Year}\nPresent: {t.PresentDays}\nAbsent: {t.AbsentDays}\nIncomplete: {t.IncompleteDays}\nActive: {FormatMs(t.TotalActiveMs)}\nBreak: {FormatMs(t.TotalBreakMs)}";
    }

    private static string FormatMs(long ms)
    {
        var ts = TimeSpan.FromMilliseconds(ms);
        return $"{(int)ts.TotalHours:D2}:{ts.Minutes:D2}:{ts.Seconds:D2}";
    }
}
