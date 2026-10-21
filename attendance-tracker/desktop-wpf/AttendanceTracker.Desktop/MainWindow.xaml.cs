using System.IO;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;
using AttendanceTracker.Desktop.Views;

namespace AttendanceTracker.Desktop;

public partial class MainWindow : Window
{
    private readonly AttendanceApiClient _api = new();
    private readonly Dictionary<string, System.Windows.Controls.UserControl> _pages = new();

    public MainWindow()
    {
        InitializeComponent();
        LoginPanel.LoginSucceeded += OnAdminLoginSucceeded;
        LoginPanel.LoginCancelled += (_, _) => LoginOverlay.Visibility = Visibility.Collapsed;
        Loaded += MainWindow_Loaded;
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        try
        {
            StartupStatusText.Text = "Preparing local database…";
            await WorkstationSetup.EnsureReadyAsync();

            StartupStatusText.Text = "Starting attendance engine…";
            await CoreEngineHost.Instance.EnsureStartedAsync();

            StartupStatusText.Text = "Loading live data…";
            await _api.LocalViewerLoginAsync();
            EnterViewerShell();

            WorkplaceMonitoringHost.Start();
        }
        catch (Exception ex)
        {
            StartupStatusText.Text = ex.Message;
            MessageBox.Show(ex.Message, "Workforce Attendance", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private void EnterViewerShell()
    {
        StartupPanel.Visibility = Visibility.Collapsed;
        ShellGrid.Visibility = Visibility.Visible;
        UpdateChromeForSession();
        Navigate("dashboard");
    }

    private void UpdateChromeForSession()
    {
        if (SessionState.IsAdmin)
        {
            SignedInLabel.Text = $"Administrator — {SessionState.SubjectId ?? "signed in"} (changes enabled)";
            AdminSignInButton.Visibility = Visibility.Collapsed;
            ExitAdminButton.Visibility = Visibility.Visible;
        }
        else
        {
            SignedInLabel.Text = "Live attendance data (read-only — sign in as admin to make changes)";
            AdminSignInButton.Visibility = Visibility.Visible;
            ExitAdminButton.Visibility = Visibility.Collapsed;
        }
    }

    private void OnAdminLoginSucceeded(object? sender, EventArgs e)
    {
        LoginOverlay.Visibility = Visibility.Collapsed;
        UpdateChromeForSession();
        _pages.Clear();
        Navigate("dashboard");
    }

    private void AdminSignIn_Click(object sender, RoutedEventArgs e)
    {
        LoginPanel.ConfigureFirstRun(!AppConfig.Load().FirstRunCompleted);
        LoginOverlay.Visibility = Visibility.Visible;
    }

    private void Navigate(string key)
    {
        try
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
                    "employee-guide" => new EmployeeGuideView(),
                    _ => new DashboardView(_api),
                };
                _pages[key] = page;
            }
            MainContent.Content = page;
            ReadOnlyUi.ApplyToRoot(page);
            if (page is IRefreshable r)
                _ = r.RefreshAsync();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Navigation error", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private void SetNavStyles(string active)
    {
        foreach (var (name, tag) in new (System.Windows.Controls.Button btn, string tag)[]
        {
            (NavDashboard, "dashboard"),
            (NavEmployees, "employees"),
            (NavHistory, "history"),
            (NavReports, "reports"),
            (NavCorrections, "corrections"),
            (NavEmployeesGuide, "employee-guide"),
            (NavSettings, "settings"),
        })
        {
            name.Style = (Style)FindResource(tag == active ? "OfficeNavButtonSelected" : "OfficeNavButton");
        }
    }

    private void Nav_Click(object sender, RoutedEventArgs e)
    {
        if (sender is System.Windows.Controls.Button b && b.Tag is string tag)
            Navigate(tag);
    }

    private async void SignOut_Click(object sender, RoutedEventArgs e)
    {
        await _api.LogoutAsync();
        await _api.LocalViewerLoginAsync();
        UpdateChromeForSession();
        _pages.Clear();
        Navigate("dashboard");
    }
}

public interface IRefreshable
{
    Task RefreshAsync();
}
