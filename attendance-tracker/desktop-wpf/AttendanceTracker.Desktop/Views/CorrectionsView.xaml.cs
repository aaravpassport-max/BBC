using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Models;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class CorrectionsView : System.Windows.Controls.UserControl, IRefreshable
{
    private readonly AttendanceApiClient _api;

    public CorrectionsView(AttendanceApiClient api)
    {
        _api = api;
        InitializeComponent();
    }

    public Task RefreshAsync() => LoadAsync();

    private async void Filter_Changed(object sender, SelectionChangedEventArgs e) => await LoadAsync();
    private async void Refresh_Click(object sender, RoutedEventArgs e) => await LoadAsync();

    private async Task LoadAsync()
    {
        StatusText.Text = "Loading…";
        try
        {
            var status = (StatusFilter.SelectedItem as ComboBoxItem)?.Content?.ToString() ?? "pending";
            Grid.ItemsSource = await _api.GetCorrectionRequestsAsync(status);
            StatusText.Text = $"{Grid.Items.Count} request(s).";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private CorrectionRequestRow? Selected => Grid.SelectedItem as CorrectionRequestRow;

    private async void Approve_Click(object sender, RoutedEventArgs e)
    {
        if (!SessionState.CanModify)
        {
            StatusText.Text = "Sign in as administrator to approve or reject.";
            return;
        }
        var row = Selected;
        if (row is null) { StatusText.Text = "Select a request."; return; }
        try
        {
            await _api.ReviewCorrectionAsync(row.RequestId, true, "Approved from desktop console.");
            await LoadAsync();
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private async void Reject_Click(object sender, RoutedEventArgs e)
    {
        if (!SessionState.CanModify)
        {
            StatusText.Text = "Sign in as administrator to approve or reject.";
            return;
        }
        var row = Selected;
        if (row is null) { StatusText.Text = "Select a request."; return; }
        try
        {
            await _api.ReviewCorrectionAsync(row.RequestId, false, "Rejected from desktop console.");
            await LoadAsync();
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }
}
