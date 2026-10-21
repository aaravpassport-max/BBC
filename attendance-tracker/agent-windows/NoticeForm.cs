namespace AttendanceAgent;

/// <summary>
/// The on-device monitoring notice. Shown once per employee/device pair
/// (tracked via notice_acknowledgements) and on every policy version
/// change. There is no "skip", "remind me later", "install silently" or
/// admin-override path anywhere in this class — closing the form via the
/// X button or Alt+F4 does NOT acknowledge it (see FormClosing below),
/// and the agent's startup sequence in Program.cs will show it again on
/// next launch rather than proceeding.
///
/// This is the structural safeguard promised earlier in this build: the
/// indicator and this notice are not admin-configurable, not toggleable,
/// and not removable from this source.
/// </summary>
public sealed class NoticeForm : Form
{
    private bool _acknowledged;
    private readonly CheckBox _confirmCheckbox;
    private readonly Button _continueButton;

    public bool Acknowledged => _acknowledged;

    public NoticeForm(string policyVersion)
    {
        Text = "Workstation activity monitoring";
        Width = 560;
        Height = 480;
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        TopMost = true;

        var body = new Label
        {
            Dock = DockStyle.Top,
            Height = 330,
            Padding = new Padding(20),
            Font = new Font("Segoe UI", 9.5f),
            Text =
                "Your employer uses this software to track attendance and work activity " +
                "on this computer.\n\n" +
                "What is collected: sign-in/sign-out and lock/unlock times, sleep and " +
                "wake events, which application is in the foreground and for how long, " +
                "which website and page is active in a supported browser, and the " +
                "timing (not content) of keyboard and mouse input, used only to " +
                "distinguish active work time from idle time.\n\n" +
                "What is never collected: the content of anything you type, passwords, " +
                "clipboard contents, the content of messages, screenshots, camera, or " +
                "microphone.\n\n" +
                "You can see everything collected about you at any time from this " +
                "program's icon in the system tray, and a status indicator is always " +
                "visible while monitoring is active.\n\n" +
                $"Policy version {policyVersion}."
        };

        _confirmCheckbox = new CheckBox
        {
            Dock = DockStyle.Top,
            Height = 40,
            Padding = new Padding(20, 0, 20, 0),
            Text = "I have read and understand this notice.",
        };
        _confirmCheckbox.CheckedChanged += (_, _) => _continueButton.Enabled = _confirmCheckbox.Checked;

        _continueButton = new Button
        {
            Dock = DockStyle.Bottom,
            Height = 40,
            Text = "Continue",
            Enabled = false,
        };
        _continueButton.Click += (_, _) =>
        {
            _acknowledged = true;
            Close();
        };

        Controls.Add(_continueButton);
        Controls.Add(_confirmCheckbox);
        Controls.Add(body);

        FormClosing += OnFormClosing;
    }

    private void OnFormClosing(object? sender, FormClosingEventArgs e)
    {
        // Closing by any means OTHER than the Continue button (the X
        // button, Alt+F4, Escape, task-killing the process before this
        // handler runs) must never count as acknowledgement. If the
        // process is killed outright, _acknowledged simply stays false
        // and Program.cs's startup sequence shows this form again next
        // launch — there is no code path that treats "the window went
        // away" as consent.
        if (!_acknowledged)
        {
            // Allow the close (don't trap the user in the window forever
            // against their OS-level controls), but the caller in
            // Program.cs checks Acknowledged afterward and will not
            // proceed to start activity collection if it's false.
        }
    }
}
