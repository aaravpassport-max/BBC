using System.IO;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;
using Microsoft.Win32;

namespace AttendanceTracker.Desktop.Views;

public partial class SettingsView : UserControl, IRefreshable
{
    public SettingsView()
    {
        InitializeComponent();
    }

    public Task RefreshAsync()
    {
        DbPathBox.Text = AppPaths.DatabasePath;
        ApiUrlBox.Text = CoreEngineHost.Instance.BaseUrl;
        StatusText.Text = $"Backups folder: {AppPaths.BackupsDirectory}";
        return Task.CompletedTask;
    }

    private void Backup_Click(object sender, RoutedEventArgs e)
    {
        if (!SessionState.CanModify)
        {
            StatusText.Text = "Sign in as administrator to back up or restore the database.";
            return;
        }
        try
        {
            AppPaths.EnsureDirectories();
            if (!File.Exists(AppPaths.DatabasePath))
                throw new FileNotFoundException("Database file not found.", AppPaths.DatabasePath);
            var stamp = DateTime.UtcNow.ToString("yyyyMMdd-HHmmss");
            var dest = Path.Combine(AppPaths.BackupsDirectory, $"attendance-{stamp}.db");
            File.Copy(AppPaths.DatabasePath, dest, overwrite: false);
            StatusText.Text = $"Backup saved: {dest}";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }

    private void Restore_Click(object sender, RoutedEventArgs e)
    {
        if (!SessionState.CanModify)
        {
            StatusText.Text = "Sign in as administrator to back up or restore the database.";
            return;
        }
        var dlg = new OpenFileDialog { Filter = "SQLite database|*.db", Title = "Select backup to restore" };
        if (dlg.ShowDialog() != true)
            return;
        if (MessageBox.Show(
                "Replace the current database with the selected backup? This cannot be undone.",
                "Confirm restore",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning) != MessageBoxResult.Yes)
            return;
        try
        {
            File.Copy(dlg.FileName, AppPaths.DatabasePath, overwrite: true);
            StatusText.Text = "Database restored. Sign out and sign in again to reload data.";
        }
        catch (Exception ex)
        {
            StatusText.Text = ex.Message;
        }
    }
}
