using System.Diagnostics;

namespace AttendanceAgent;

/// <summary>
/// The always-visible monitoring indicator and the employee's own
/// self-view, as one object so Program.cs can't start the SessionMonitor
/// without also starting this. There is deliberately no method on this
/// class (or anywhere else in this agent) that hides the tray icon while
/// monitoring continues — the two lifecycles are tied together on
/// purpose. If you're reading this wondering where the "silent mode"
/// setting is: there isn't one, and that's not an oversight.
///
/// STATUS NOTE (honesty discipline, same as every other file in
/// agent-windows/): this file is reviewed, not compiled or run — there is
/// no Windows/.NET runtime in the sandbox that built and tested this
/// project. What changed in this revision (opening the local dashboard
/// page instead of the old in-process ListBox) is a THIN change for
/// exactly that reason: it hands off to code that IS real, executed,
/// proven proof — core/public/local-dashboard.html, served by
/// GET /local-dashboard in core/src/api/server.ts, verified end-to-end
/// with a real headless browser against a real running server and real
/// seeded data in core/scripts/verify-local-dashboard.mjs (11/11 checks
/// passing). The C# side only needs to open a URL; the dashboard itself
/// is the part that was actually built and tested.
/// </summary>
public sealed class TrayPresence : IDisposable
{
    private readonly NotifyIcon _icon;
    private readonly SqliteRepository _repo;
    private readonly string _employeeId;
    private readonly string _deviceId;

    public TrayPresence(SqliteRepository repo, string employeeId, string deviceId)
    {
        _repo = repo;
        _employeeId = employeeId;
        _deviceId = deviceId;

        var menu = new ContextMenuStrip();
        menu.Items.Add("View my activity today", null, (_, _) => ShowSelfView());
        menu.Items.Add("What is being monitored?", null, (_, _) => ShowNoticeText());
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("About", null, (_, _) => MessageBox.Show(
            "Attendance monitoring agent. Your employer can see attendance and activity " +
            "data collected by this program. See \"What is being monitored?\" for details.",
            "Attendance Agent", MessageBoxButtons.OK, MessageBoxIcon.Information));

        _icon = new NotifyIcon
        {
            Icon = SystemIcons.Information, // placeholder — a real build ships a proper .ico
            Text = "Attendance monitoring: active",
            Visible = true,
            ContextMenuStrip = menu,
        };
        _icon.DoubleClick += (_, _) => ShowSelfView();
    }

    /// <summary>
    /// Opens the employee's own live dashboard — the SAME page proven by
    /// verify-local-dashboard.mjs — in the system's default browser,
    /// authenticated with this machine's enrolled device token so there
    /// is no password prompt (the direct analog of "already securely
    /// established through the Windows account": trust comes from a
    /// credential this PC already holds, not a fresh login).
    ///
    /// Both ATTENDANCE_SERVER_URL (where the core API/dashboard server
    /// lives — normally a server on the company's own network, not this
    /// PC) and ATTENDANCE_DEVICE_TOKEN (minted once by an admin via
    /// enrollDevice() in core/src/db/auth-repository.ts and distributed
    /// to this machine at install time) are read the same ad-hoc way
    /// ATTENDANCE_EMPLOYEE_ID already is in Program.cs — Stage 1's own
    /// named gap, not new to this change: there is still no real config
    /// file or device-provisioning flow, and multi-machine deployment of
    /// that token remains the unaddressed sync gap this project's README
    /// already calls out. Falls back to the old local Stage-1 raw-event
    /// list only when those aren't configured yet, so this never
    /// regresses behavior on a machine nobody has finished setting up.
    /// </summary>
    private void ShowSelfView()
    {
        var serverUrl = Environment.GetEnvironmentVariable("ATTENDANCE_SERVER_URL");
        var deviceToken = Environment.GetEnvironmentVariable("ATTENDANCE_DEVICE_TOKEN");
        if (!string.IsNullOrWhiteSpace(serverUrl) && !string.IsNullOrWhiteSpace(deviceToken))
        {
            var url = $"{serverUrl.TrimEnd('/')}/local-dashboard?deviceToken={Uri.EscapeDataString(deviceToken)}";
            try
            {
                // UseShellExecute: true is required here — without it,
                // Process.Start tries to execute the URL as a program
                // rather than handing it to the OS's default browser.
                Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
                return;
            }
            catch (Exception ex)
            {
                MessageBox.Show(
                    $"Couldn't open your activity dashboard in a browser: {ex.Message}\n\n" +
                    "Showing a local fallback summary instead.",
                    "Attendance Agent", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
        }

        // Fallback: this machine hasn't been enrolled with a device token
        // yet (ATTENDANCE_SERVER_URL / ATTENDANCE_DEVICE_TOKEN unset), so
        // there's no live dashboard to open. Keep the original Stage 1
        // local raw-event listing rather than showing nothing.
        using var form = new EmployeeSelfViewForm(_repo, _employeeId, _deviceId);
        form.ShowDialog();
    }

    private void ShowNoticeText()
    {
        // Re-shows the same notice text the install-time NoticeForm
        // displayed, so "what is collected" is never more than two
        // clicks away, on demand, for the life of the install — not
        // just at the moment of first acknowledgement.
        MessageBox.Show(
            "What is collected: sign-in/sign-out and lock/unlock times, sleep and wake " +
            "events, which application is in the foreground and for how long, which " +
            "website is active in a supported browser, and the timing (not content) of " +
            "keyboard and mouse input.\n\n" +
            "Never collected: keystroke content, passwords, clipboard, message content, " +
            "screenshots, camera, or microphone.",
            "What is being monitored?", MessageBoxButtons.OK, MessageBoxIcon.Information);
    }

    public void Dispose()
    {
        _icon.Visible = false;
        _icon.Dispose();
    }
}

/// <summary>
/// Employee's own view of today's data. Reads the SAME raw_events table
/// the admin console's data ultimately comes from (via the TS reconciler)
/// — deliberately no separate, friendlier dataset; what the employee sees
/// here is drawn from the same facts the employer sees, not a filtered or
/// softened copy.
/// </summary>
public sealed class EmployeeSelfViewForm : Form
{
    public EmployeeSelfViewForm(SqliteRepository repo, string employeeId, string deviceId)
    {
        Text = "My activity today";
        Width = 420;
        Height = 320;
        StartPosition = FormStartPosition.CenterScreen;

        var list = new ListBox { Dock = DockStyle.Fill };
        // Minimal read for Stage 1: list today's raw input/lock/sleep
        // event count and the most recent few timestamps. A full Stage 3
        // build renders this from state_intervals (same shape the admin
        // console's session-detail view uses), once the recompute job
        // runs on this machine rather than only centrally.
        foreach (var line in repo.GetTodaySummaryLines(employeeId, deviceId))
        {
            list.Items.Add(line);
        }
        Controls.Add(list);
    }
}
