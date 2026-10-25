using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class DashboardView : System.Windows.Controls.UserControl, IRefreshable
{
    private readonly AttendanceApiClient _api;

    public DashboardView(AttendanceApiClient api)
    {
        _api = api;
        InitializeComponent();
        DatePicker.SelectedDate = DateTime.UtcNow.Date;
    }

    public async Task RefreshAsync() => await LoadAsync();

    private async void Date_Changed(object? sender, SelectionChangedEventArgs e) => await LoadAsync();
    private async void Refresh_Click(object sender, RoutedEventArgs e) => await LoadAsync();

    private async Task LoadAsync()
    {
        StatusText.Text = "Loading…";
        try
        {
            var day = (DatePicker.SelectedDate ?? DateTime.UtcNow.Date).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
            var employees = await _api.GetEmployeesAsync();
            var history = await _api.GetHistoryAsync(day, day);
            KpiEmployees.Text = employees.Count.ToString();
            KpiPresent.Text = history.Count(h => h.Status == "present").ToString();
            KpiAbsent.Text = history.Count(h => h.Status == "absent").ToString();
            KpiIncomplete.Text = history.Count(h => h.Status == "incomplete").ToString();
            StatusText.Text = $"Showing UTC calendar day {day}.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }
}
