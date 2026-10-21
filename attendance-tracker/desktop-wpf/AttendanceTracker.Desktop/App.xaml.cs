using System.IO;
using System.Windows;
using System.Windows.Threading;

namespace AttendanceTracker.Desktop;

public partial class App : System.Windows.Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        DispatcherUnhandledException += OnDispatcherUnhandledException;
        AppDomain.CurrentDomain.UnhandledException += OnDomainUnhandledException;
    }

    protected override void OnExit(ExitEventArgs e)
    {
        Services.WorkplaceMonitoringHost.Stop();
        Services.CoreEngineHost.Instance.Dispose();
        base.OnExit(e);
    }

    private static void OnDispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        LogCrash("UI thread", e.Exception);
        MessageBox.Show(
            FormatUserMessage(e.Exception),
            "Workforce Attendance Console",
            MessageBoxButton.OK,
            MessageBoxImage.Error);
        e.Handled = true;
    }

    private static void OnDomainUnhandledException(object sender, UnhandledExceptionEventArgs e)
    {
        if (e.ExceptionObject is Exception ex)
            LogCrash("AppDomain", ex);
    }

    private static string FormatUserMessage(Exception ex)
    {
        var root = ex;
        while (root.InnerException is not null)
            root = root.InnerException;

        if (root.Message.Contains("core", StringComparison.OrdinalIgnoreCase)
            || root.Message.Contains("node", StringComparison.OrdinalIgnoreCase))
        {
            return root.Message + "\n\nKeep the whole extracted ZIP folder together (AttendanceTracker.exe, core\\, runtime\\). "
                + "Do not move only the .exe file.";
        }

        return root.Message;
    }

    private static void LogCrash(string source, Exception ex)
    {
        try
        {
            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "AttendanceTracker", "logs");
            Directory.CreateDirectory(dir);
            var path = Path.Combine(dir, "crash.log");
            File.AppendAllText(path, $"[{DateTime.UtcNow:O}] {source}: {ex}\n\n");
        }
        catch
        {
            // ignore logging failures
        }
    }
}
