using System.IO;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;
using AttendanceTracker.Desktop.Views;

namespace AttendanceTracker.Desktop;

public partial class MainWindow : Window
{
    private readonly AttendanceApiClient _api = new();
    private readonly Dictionary<string, UserControl> _pages = new();

    public MainWindow()
    {
        InitializeComponent();
        LoginPanel.LoginSucceeded += OnLoginSucceeded;
        Loaded += MainWindow_Loaded;
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        LoginPanel.ConfigureFirstRun(!File.Exists(AppPaths.DatabasePath));
    }

    private void OnLoginSucceeded(object? sender, EventArgs e)
    {
        LoginPanel.Visibility = Visibility.Collapsed;
        ShellGrid.Visibility = Visibility.Visible;
        SignedInLabel.Text = $"Signed in as {SessionState.SubjectId ?? "Administrator"}";
        Navigate("dashboard");
    }

    private void Navigate(string key)
    {
        SetNavStyles(key);
        if (!_pages.TryGetValue(key, out var page))
        {
            page = key switch
            {
                "dashboard" => new DashboardView(_api),
                "employees" => new EmployeesView(_api),
                "history" => new HistoryView(_api),
                "reports" => new ReportsView(_api),
                "corrections" => new CorrectionsView(_api),
                "settings" => new SettingsView(),
                _ => new DashboardView(_api),
            };
            _pages[key] = page;
        }
        MainContent.Content = page;
        if (page is IRefreshable r)
            _ = r.RefreshAsync();
    }

    private void SetNavStyles(string active)
    {
        foreach (var (name, tag) in new (Button btn, string tag)[]
        {
            (NavDashboard, "dashboard"),
            (NavEmployees, "employees"),
            (NavHistory, "history"),
            (NavReports, "reports"),
            (NavCorrections, "corrections"),
            (NavSettings, "settings"),
        })
        {
            name.Style = (Style)FindResource(tag == active ? "OfficeNavButtonSelected" : "OfficeNavButton");
        }
    }

    private void Nav_Click(object sender, RoutedEventArgs e)
    {
        if (sender is Button b && b.Tag is string tag)
            Navigate(tag);
    }

    private async void SignOut_Click(object sender, RoutedEventArgs e)
    {
        await _api.LogoutAsync();
        ShellGrid.Visibility = Visibility.Collapsed;
        LoginPanel.Visibility = Visibility.Visible;
        LoginPanel.ResetForm();
        _pages.Clear();
    }
}

public interface IRefreshable
{
    Task RefreshAsync();
}
