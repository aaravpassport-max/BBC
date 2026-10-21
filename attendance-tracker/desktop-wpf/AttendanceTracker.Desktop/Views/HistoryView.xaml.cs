using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class HistoryView : UserControl, IRefreshable
{
    private readonly AttendanceApiClient _api;

    public HistoryView(AttendanceApiClient api)
    {
        _api = api;
        InitializeComponent();
        var today = DateTime.UtcNow.Date;
        FromPicker.SelectedDate = today.AddDays(-7);
        ToPicker.SelectedDate = today;
    }

    public Task RefreshAsync()
    {
        return SearchAsync();
    }

    private async void Search_Click(object sender, RoutedEventArgs e) => await SearchAsync();

    private async Task SearchAsync()
    {
        StatusText.Text = "Loading…";
        try
        {
            var from = (FromPicker.SelectedDate ?? DateTime.UtcNow.Date).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
            var to = (ToPicker.SelectedDate ?? DateTime.UtcNow.Date).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
            string? status = null;
            if (StatusBox.SelectedItem is ComboBoxItem item && item.Content?.ToString() is { } s && s != "All")
                status = s;
            Grid.ItemsSource = await _api.GetHistoryAsync(from, to, status: status);
            StatusText.Text = $"{Grid.Items.Count} row(s).";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }
}
