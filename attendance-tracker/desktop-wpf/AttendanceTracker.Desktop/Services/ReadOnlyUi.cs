using System.Windows;
using System.Windows.Controls;

namespace AttendanceTracker.Desktop.Services;

public static class ReadOnlyUi
{
    public static void ApplyToRoot(DependencyObject root)
    {
        if (SessionState.CanModify)
            return;
        DisableMutatingControls(root);
    }

    private static void DisableMutatingControls(DependencyObject node)
    {
        if (node is System.Windows.Controls.Button btn && btn.Name is not "NavDashboard" and not "NavEmployees" and not "NavHistory"
            and not "NavReports" and not "NavCorrections" and not "NavEmployeesGuide" and not "NavSettings")
        {
            var content = btn.Content?.ToString() ?? "";
            if (content is "Approve" or "Reject" or "Backup database" or "Restore from backup…")
                btn.IsEnabled = false;
        }

        var count = System.Windows.Media.VisualTreeHelper.GetChildrenCount(node);
        for (var i = 0; i < count; i++)
            DisableMutatingControls(System.Windows.Media.VisualTreeHelper.GetChild(node, i));
    }
}
