using System.Diagnostics;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class EmployeeGuideView : System.Windows.Controls.UserControl, IRefreshable
{
    public EmployeeGuideView()
    {
        InitializeComponent();
    }

    public Task RefreshAsync()
    {
        var root = AppPaths.InstallRoot;
        var agentHint = Path.Combine(root, "agent-windows");
        AgentPathText.Text = Directory.Exists(agentHint)
            ? $"Agent source/build folder:\n{agentHint}\n\nBuild on Windows: dotnet publish agent-windows -c Release -r win-x64 --self-contained"
            : $"Agent sources ship with the full repository under attendance-tracker\\agent-windows.\nInstall folder: {root}";

        try
        {
            var url = CoreEngineHost.Instance.BaseUrl;
            DashboardUrlHint.Text = SessionState.IsAuthenticated
                ? $"API: {url}  (employee page: {url}/local-dashboard?deviceToken=…)"
                : "Sign in as admin to start the local API first.";
        }
        catch
        {
            DashboardUrlHint.Text = "Start the app and sign in to run the local API.";
        }

        return Task.CompletedTask;
    }

    private void OpenDashboard_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var url = $"{CoreEngineHost.Instance.BaseUrl}/local-dashboard";
            Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Open dashboard", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }
}
