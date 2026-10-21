using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class EmployeesView : UserControl, IRefreshable
{
    private readonly AttendanceApiClient _api;

    public EmployeesView(AttendanceApiClient api)
    {
        _api = api;
        InitializeComponent();
    }

    public async Task RefreshAsync()
    {
        StatusText.Text = "Loading…";
        try
        {
            Grid.ItemsSource = await _api.GetEmployeesAsync();
            StatusText.Text = $"{Grid.Items.Count} employee(s). Enroll devices via the core API or seed scripts.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }
}
