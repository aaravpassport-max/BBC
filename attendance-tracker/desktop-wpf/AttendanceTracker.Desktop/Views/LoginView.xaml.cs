using System.IO;
using System.Windows;
using System.Windows.Controls;
using AttendanceTracker.Desktop.Services;

namespace AttendanceTracker.Desktop.Views;

public partial class LoginView : UserControl
{
    private bool _firstRun;

    public event EventHandler? LoginSucceeded;
    public event EventHandler? LoginCancelled;

    public LoginView()
    {
        InitializeComponent();
        var config = AppConfig.Load();
        if (!string.IsNullOrEmpty(config.LastAdminEmail))
            EmailBox.Text = config.LastAdminEmail;
    }

    public void ConfigureFirstRun(bool firstRun)
    {
        _firstRun = firstRun;
        FirstRunPanel.Visibility = firstRun ? Visibility.Visible : Visibility.Collapsed;
        ActionButton.Content = firstRun ? "Create account and sign in" : "Sign in";
    }

    public void ResetForm()
    {
        PasswordBox.Clear();
        ErrorText.Visibility = Visibility.Collapsed;
        ConfigureFirstRun(!File.Exists(AppPaths.DatabasePath));
    }

    private void Cancel_Click(object sender, RoutedEventArgs e) => LoginCancelled?.Invoke(this, EventArgs.Empty);

    private async void Action_Click(object sender, RoutedEventArgs e)
    {
        ErrorText.Visibility = Visibility.Collapsed;
        ActionButton.IsEnabled = false;
        try
        {
            var email = EmailBox.Text.Trim();
            var password = PasswordBox.Password;
            if (string.IsNullOrEmpty(email) || string.IsNullOrEmpty(password))
                throw new InvalidOperationException("Email and password are required.");

            if (_firstRun)
            {
                await CoreEngineHost.BootstrapDatabaseAsync(
                    email,
                    password,
                    AdminNameBox.Text.Trim().Length > 0 ? AdminNameBox.Text.Trim() : "Administrator",
                    SeedDemoCheck.IsChecked == true);
                var config = AppConfig.Load();
                config.FirstRunCompleted = true;
                config.LastAdminEmail = email;
                config.Save();
            }

            await CoreEngineHost.Instance.EnsureStartedAsync();
            var api = new AttendanceApiClient();
            await api.AdminLoginAsync(email, password);

            var cfg = AppConfig.Load();
            cfg.LastAdminEmail = email;
            cfg.Save();

            LoginSucceeded?.Invoke(this, EventArgs.Empty);
        }
        catch (Exception ex)
        {
            ErrorText.Text = ex.Message;
            ErrorText.Visibility = Visibility.Visible;
        }
        finally
        {
            ActionButton.IsEnabled = true;
        }
    }
}
