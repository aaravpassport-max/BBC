using System.Windows;

namespace AttendanceTracker.Desktop;

public partial class App : Application
{
    protected override void OnExit(ExitEventArgs e)
    {
        Services.CoreEngineHost.Instance.Dispose();
        base.OnExit(e);
    }
}
