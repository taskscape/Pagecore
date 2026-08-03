using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows;
using Microsoft.Win32;

namespace PagecoreDeployer;

public partial class MainWindow : Window
{
    private const int ApiPort = 8787;

    private readonly Settings _settings;
    private readonly Deployer _deployer;
    private ControlServer? _api;

    public MainWindow()
    {
        InitializeComponent();
        _settings = Settings.Load();
        _deployer = new Deployer(Log);

        HostBox.Text = _settings.Host.Trim().Length > 0
            ? Deployer.Endpoint.Parse(_settings.Host).Display
            : _settings.Host;
        UserBox.Text = _settings.User;
        PassBox.Password = _settings.GetPassword();
        EngineBox.Text = _settings.EngineFolder;
        WebsiteBox.Text = _settings.WebsiteFolder;
        RemoteBox.Text = _settings.RemoteFolder;

        Closing += (_, _) => { SaveSettings(); _api?.Stop(); };
    }

    private void SaveSettings()
    {
        // Write the scheme down as what it actually is. An FTP connection here is
        // always explicit TLS, so it is stored and shown as ftps:// — never as a
        // bare ftp:// that would read as unencrypted.
        if (HostBox.Text.Trim().Length > 0)
        {
            string normalised = Deployer.Endpoint.Parse(HostBox.Text).Display;
            if (!normalised.Equals(HostBox.Text.Trim(), StringComparison.Ordinal)) HostBox.Text = normalised;
        }

        _settings.Host = HostBox.Text.Trim();
        _settings.User = UserBox.Text.Trim();
        _settings.SetPassword(PassBox.Password);
        _settings.EngineFolder = EngineBox.Text.Trim();
        _settings.WebsiteFolder = WebsiteBox.Text.Trim();
        _settings.RemoteFolder = RemoteBox.Text.Trim();
        try { _settings.Save(); } catch { /* non-fatal */ }
    }

    // ---- folder pickers ---------------------------------------------------

    private void BrowseEngine(object sender, RoutedEventArgs e)
    {
        if (!Pick(EngineBox)) return;
        // Picking the repository or site root is the common slip; correct it now
        // rather than at upload time. A folder that is neither is left as typed
        // and reported when the upload is attempted.
        try
        {
            var plan = EngineLayout.Resolve(EngineBox.Text);
            EngineBox.Text = plan.Root;
            StatusText.Text = $"Engine folder recognised — Pagecore {plan.Version}, {plan.Files.Count} file(s).";
        }
        catch (Exception ex)
        {
            StatusText.Text = "That folder is not a Pagecore engine.";
            MessageBox.Show(this, ex.Message, "Local engine folder", MessageBoxButton.OK, MessageBoxImage.Information);
        }
    }

    private void BrowseWebsite(object sender, RoutedEventArgs e)
    {
        if (!Pick(WebsiteBox)) return;
        try
        {
            var plan = SiteLayout.Resolve(WebsiteBox.Text);
            WebsiteBox.Text = plan.Root;
            StatusText.Text = $"Website folder recognised — {plan.TotalFiles} file(s) to publish.";
        }
        catch (Exception ex)
        {
            StatusText.Text = "That folder is not a Pagecore website.";
            MessageBox.Show(this, ex.Message, "Local website folder", MessageBoxButton.OK, MessageBoxImage.Information);
        }
    }

    private bool Pick(System.Windows.Controls.TextBox target)
    {
        var dialog = new OpenFolderDialog { Title = "Select folder" };
        if (Directory.Exists(target.Text)) dialog.InitialDirectory = target.Text;
        if (dialog.ShowDialog(this) != true) return false;
        target.Text = dialog.FolderName;
        return true;
    }

    // ---- actions ----------------------------------------------------------

    private async void OnTestConnection(object sender, RoutedEventArgs e)
    {
        if (!ValidateConnection() || !ValidateRemote()) return;
        var ep = Deployer.Endpoint.Parse(HostBox.Text);
        string user = UserBox.Text.Trim(), pass = PassBox.Password;
        string remote = RemoteBox.Text.Trim();
        if (!await ConfirmCertificate(ep)) return;
        await Run("Testing connection", () => _deployer.TestConnection(ep, user, pass, remote));
    }

    /// <summary>
    /// Take a working login from an Open Salamander bookmark. The password goes
    /// from Salamander's store into this app's DPAPI-protected one without being
    /// displayed or logged; only the user name is echoed.
    /// </summary>
    private void OnImportCredential(object sender, RoutedEventArgs e)
    {
        List<SalamanderImport.Bookmark> bookmarks;
        try { bookmarks = SalamanderImport.ReadBookmarks(); }
        catch (Exception ex)
        {
            MessageBox.Show(this, ex.Message, "Import", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (bookmarks.Count == 0)
        {
            MessageBox.Show(this,
                "No Open Salamander FTP bookmarks were found for this Windows account.",
                "Import", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        var picker = new ImportWindow(bookmarks) { Owner = this };
        if (picker.ShowDialog() != true || picker.Chosen == null) return;

        var bookmark = picker.Chosen;
        try
        {
            PassBox.Password = SalamanderImport.ReadPassword(bookmark);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, ex.Message, "Import", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (bookmark.User.Length > 0) UserBox.Text = bookmark.User;
        SaveSettings();     // straight into DPAPI-protected storage

        Log($"Imported the login from Open Salamander bookmark \"{bookmark.Name}\" — user {bookmark.User}, "
          + "password stored encrypted and not shown.");
        if (!bookmark.Address.Equals(Deployer.Endpoint.Parse(HostBox.Text).HostPort, StringComparison.OrdinalIgnoreCase))
            Log($"  That bookmark connects to {bookmark.Address}; Host here is "
              + $"{Deployer.Endpoint.Parse(HostBox.Text).HostPort}. Both are fine if they are the same server.");
        StatusText.Text = $"Imported the login from \"{bookmark.Name}\".";
    }

    private async void OnScanRemote(object sender, RoutedEventArgs e)
    {
        if (!ValidateConnection()) return;
        var ep = Deployer.Endpoint.Parse(HostBox.Text);
        string user = UserBox.Text.Trim(), pass = PassBox.Password;
        if (!await ConfirmCertificate(ep)) return;

        // From the login's own root, so every domain folder it can reach is
        // covered rather than just the one being published.
        await Run("Scanning", () => _deployer.ScanRemote(ep, user, pass, "", Settings.Dir));
    }

    private async void OnUploadEngine(object sender, RoutedEventArgs e)
    {
        if (!ValidateConnection() || !ValidateRemote()) return;

        // Recognise the folder before anything connects, so a wrong pick costs
        // a message box rather than a partial upload to the live site.
        EngineLayout.Plan plan;
        try
        {
            plan = EngineLayout.Resolve(EngineBox.Text);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, ex.Message, "Local engine folder", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (plan.Redirected)
        {
            // The user picked a repository or site root; keep the engine folder
            // they actually meant so the next run starts from the right place.
            EngineBox.Text = plan.Root;
            Log($"Selected folder is a Pagecore repository or site root — using its engine folder {plan.Root}");
        }

        var ep = Deployer.Endpoint.Parse(HostBox.Text);
        string user = UserBox.Text.Trim(), pass = PassBox.Password;
        string remote = RemoteBox.Text.Trim();
        if (!await ConfirmRemote(ep, user, pass, remote)) return;
        await Run("Uploading engine", () => _deployer.UploadEngine(ep, user, pass, plan, remote));
    }

    private async void OnUploadContent(object sender, RoutedEventArgs e)
    {
        if (!ValidateConnection() || !ValidateRemote()) return;

        SiteLayout.Plan plan;
        try
        {
            plan = SiteLayout.Resolve(WebsiteBox.Text);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, ex.Message, "Local website folder", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        if (plan.Redirected)
        {
            WebsiteBox.Text = plan.Root;
            Log($"Selected folder is one half of a Pagecore site — using the site folder {plan.Root}");
        }

        string remote = RemoteBox.Text.Trim();
        var ep = Deployer.Endpoint.Parse(HostBox.Text);
        string user = UserBox.Text.Trim(), pass = PassBox.Password;
        if (!await ConfirmRemote(ep, user, pass, remote)) return;

        // The private files are content and uploads, which editors change through
        // the CMS on the server. Publishing puts the local copy over the live one,
        // so say so in as many words before the first byte moves.
        var confirm = MessageBox.Show(this,
            $"Publish {plan.Root}\n\n" +
            $"  {plan.PublicFiles.Count} file(s) → {remote.TrimEnd('/')}\n" +
            $"  {plan.PrivateFiles.Count} file(s) → {Deployer.DerivePrivateDir(remote)}\n\n" +
            "The private files are the content and uploads. Anything edited through " +
            "the CMS on the server since your last copy will be overwritten.\n\n" +
            "The live config.php, the production .htaccess and the engine are not touched.\n\n" +
            "Continue?",
            "Upload new content", MessageBoxButton.OKCancel, MessageBoxImage.Warning);
        if (confirm != MessageBoxResult.OK) return;

        await Run("Uploading website", () => _deployer.UploadContent(ep, user, pass, plan, remote));
    }

    private async void OnResetPassword(object sender, RoutedEventArgs e)
    {
        if (!ValidateConnection() || !ValidateRemote()) return;
        var confirm = MessageBox.Show(this,
            "This downloads the CMS config from the server, replaces the admin password, and uploads it back.\n\n" +
            "A backup of the current config is saved locally first. Continue?",
            "Reset password", MessageBoxButton.OKCancel, MessageBoxImage.Question);
        if (confirm != MessageBoxResult.OK) return;

        var ep = Deployer.Endpoint.Parse(HostBox.Text);
        string user = UserBox.Text.Trim(), pass = PassBox.Password;
        string remote = RemoteBox.Text.Trim(), desired = NewPasswordBox.Text.Trim();
        if (!await ConfirmCertificate(ep)) return;
        await Run("Resetting password", () => _deployer.ResetPassword(ep, user, pass, remote, desired));
    }

    /// <summary>
    /// Settle the server's certificate before any credential is sent. What the
    /// system can verify goes through silently; what it cannot is written to the
    /// log in full and put to the user, and an approval is remembered against
    /// that one certificate's fingerprint.
    /// </summary>
    private async Task<bool> ConfirmCertificate(Deployer.Endpoint ep)
    {
        _deployer.CertificateApproved = false;
        if (!ep.IsFtp) return true;                 // SFTP has no certificate

        SetBusy(true, "Checking the server's certificate…");
        CertificateCheck.Result result;
        try { result = await Task.Run(() => CertificateCheck.Inspect(ep.HostPort)); }
        finally { SetBusy(false, "Ready."); }

        if (result.Certificate == null)
        {
            // Nothing to approve. Let the transfer itself report the failure,
            // which it does with the host and the curl error.
            Log($"Could not read a certificate from {ep.HostPort}: {result.Unreachable}");
            return true;
        }

        Log($"Certificate presented by {result.Host}:");
        Log(result.Describe());

        if (result.Trusted)
        {
            Log("  Verified by Windows — trusted chain and matching name.");
            return true;
        }

        Log($"  NOT verified: {result.Problems}");

        if (_settings.ApprovedCertificates.TryGetValue(result.Host, out string? approved)
            && string.Equals(approved, result.Fingerprint, StringComparison.OrdinalIgnoreCase))
        {
            Log("  Approved previously, and the fingerprint still matches.");
            _deployer.CertificateApproved = true;
            return true;
        }

        bool changed = approved != null;
        var answer = MessageBox.Show(this,
            (changed
                ? "The certificate for this host has CHANGED since it was approved.\n\n"
                : "Windows cannot verify this server's certificate.\n\n")
            + result.Describe() + "\n\n"
            + $"Why: {result.Problems}\n\n"
            + "On shared hosting this is normally the name — the server presents a certificate "
            + "in its own name rather than the domain's. Approving records this exact "
            + "certificate, and a different one will ask again.\n\n"
            + (changed ? "A change you were not expecting should not be approved.\n\n" : "")
            + "The connection is encrypted either way; this is about who is on the other end.\n\n"
            + "Approve this certificate?",
            "Server certificate", MessageBoxButton.YesNo,
            changed ? MessageBoxImage.Stop : MessageBoxImage.Warning, MessageBoxResult.No);

        if (answer != MessageBoxResult.Yes)
        {
            Log("  Not approved — nothing was sent.");
            return false;
        }

        _settings.ApprovedCertificates[result.Host] = result.Fingerprint;
        SaveSettings();
        Log($"  Approved and remembered: {result.Fingerprint}");
        _deployer.CertificateApproved = true;
        return true;
    }

    /// <summary>
    /// Look for the remote website folder before an upload starts. A wrong
    /// Remote website path is the one mistake the app cannot otherwise notice:
    /// every folder below it is created on demand, so the upload succeeds file
    /// by file into a tree nothing serves. Overridable, because an account that
    /// can write a directory it may not list should not be locked out.
    /// </summary>
    private async Task<bool> ConfirmRemote(Deployer.Endpoint ep, string user, string pass, string remote)
    {
        if (!await ConfirmCertificate(ep)) return false;

        SetBusy(true, "Checking the remote folder…");
        string? problem;
        try { problem = await Task.Run(() => _deployer.ProbeRemoteDir(ep, user, pass, remote)); }
        finally { SetBusy(false, "Ready."); }

        if (problem == null) return true;

        var answer = MessageBox.Show(this,
            problem + "\n\nUpload anyway?",
            "Remote website folder", MessageBoxButton.YesNo, MessageBoxImage.Warning, MessageBoxResult.No);
        return answer == MessageBoxResult.Yes;
    }

    // ---- run harness ------------------------------------------------------

    private async Task Run(string title, Action work)
    {
        SaveSettings();
        SetBusy(true, title + "…");
        Log($"=== {title} ===");
        try
        {
            await Task.Run(work);
            StatusText.Text = title + " — done.";
        }
        catch (Exception ex)
        {
            Log("ERROR: " + ex.Message);
            StatusText.Text = title + " — failed.";
            MessageBox.Show(this, ex.Message, title, MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            SetBusy(false, StatusText.Text);
        }
    }

    private void SetBusy(bool busy, string status)
    {
        TestButton.IsEnabled = EngineButton.IsEnabled = ContentButton.IsEnabled =
            ResetButton.IsEnabled = ScanButton.IsEnabled = !busy;
        Cursor = busy ? System.Windows.Input.Cursors.Wait : null;
        StatusText.Text = status;
    }

    // ---- validation -------------------------------------------------------

    private bool ValidateConnection()
    {
        if (string.IsNullOrWhiteSpace(HostBox.Text)) return Fail("Enter the host.");
        if (string.IsNullOrWhiteSpace(UserBox.Text)) return Fail("Enter the user name.");
        if (string.IsNullOrEmpty(PassBox.Password)) return Fail("Enter the password.");
        return true;
    }

    private bool ValidateRemote()
    {
        if (string.IsNullOrWhiteSpace(RemoteBox.Text)) return Fail("Enter the remote website folder.");
        if (!RemoteBox.Text.TrimStart().StartsWith("/")) return Fail("The remote website folder should be an absolute path, e.g. /domains/site/public_html");
        return true;
    }

    private bool ValidateLocal(System.Windows.Controls.TextBox box, string label)
    {
        if (string.IsNullOrWhiteSpace(box.Text)) return Fail($"Choose the {label} folder.");
        if (!Directory.Exists(box.Text)) return Fail($"The {label} folder does not exist:\n{box.Text}");
        return true;
    }

    private bool Fail(string message)
    {
        MessageBox.Show(this, message, "Check the form", MessageBoxButton.OK, MessageBoxImage.Information);
        return false;
    }

    // ---- local API --------------------------------------------------------

    private void OnApiToggled(object sender, RoutedEventArgs e)
    {
        if (ApiCheckBox.IsChecked == true)
        {
            try
            {
                _api ??= new ControlServer(_settings, RunApiCommand, Log);
                _api.Start(ApiPort);
                ApiText.Text = _api.Url;
                Log($"Local API listening on {_api.Url} (127.0.0.1 only).");
                Log($"  Authorization: Bearer {_api.Token}");
                Log("  The token is new for this run and is not saved anywhere.");
            }
            catch (Exception ex)
            {
                ApiCheckBox.IsChecked = false;
                MessageBox.Show(this, ex.Message, "Local API", MessageBoxButton.OK, MessageBoxImage.Warning);
            }
        }
        else
        {
            _api?.Stop();
            ApiText.Text = "";
            Log("Local API stopped.");
        }
    }

    /// <summary>
    /// Runs a command asked for over the API. Called on the server's thread, so
    /// it reads the fields on the UI thread, then does the work on another —
    /// exactly as the buttons do.
    /// </summary>
    private string RunApiCommand(string name, JsonElement? payload)
    {
        if (ControlServer.Jobs.Busy) throw new Exception("another command is already running");

        bool confirmed = payload?.TryGetProperty("confirm", out JsonElement flag) == true
                         && flag.ValueKind == JsonValueKind.True;
        string? desiredPassword = payload?.TryGetProperty("newPassword", out JsonElement pw) == true
                                  && pw.ValueKind == JsonValueKind.String ? pw.GetString() : null;

        // Read the connection out of settings rather than the controls: the API
        // is driven by whatever was last saved, and nothing here touches the UI.
        var ep = Deployer.Endpoint.Parse(_settings.Host);
        string user = _settings.User, pass = _settings.GetPassword();
        string remote = _settings.RemoteFolder;
        if (user.Length == 0 || pass.Length == 0 || remote.Length == 0)
            throw new Exception("host, user, password and remoteFolder must all be set first");

        Action work = name switch
        {
            "test-connection" => () => _deployer.TestConnection(ep, user, pass, remote),
            "scan" => () => _deployer.ScanRemote(ep, user, pass, "", Settings.Dir),
            "upload-engine" => () =>
            {
                RequireConfirmation(confirmed, "upload-engine overwrites the engine on the live site");
                _deployer.UploadEngine(ep, user, pass, EngineLayout.Resolve(_settings.EngineFolder), remote);
            },
            "upload-content" => () =>
            {
                RequireConfirmation(confirmed,
                    "upload-content overwrites the live site's templates, content and uploads, "
                    + "including anything edited through the CMS since your last copy");
                _deployer.UploadContent(ep, user, pass, SiteLayout.Resolve(_settings.WebsiteFolder), remote);
            },
            "reset-password" => () =>
            {
                RequireConfirmation(confirmed, "reset-password rewrites config.php on the live site");
                _deployer.ResetPassword(ep, user, pass, remote, desiredPassword);
            },
            _ => throw new Exception($"unknown command \"{name}\" — see /api/commands"),
        };

        ControlServer.Jobs.Begin(name);
        Log($"=== {name} (via the local API) ===");
        Task.Run(() =>
        {
            try { work(); ControlServer.Jobs.End(null); }
            catch (Exception ex)
            {
                Log("ERROR: " + ex.Message);
                ControlServer.Jobs.End(ex.Message);
            }
        });
        return "running — poll /api/status";
    }

    private static void RequireConfirmation(bool confirmed, string what)
    {
        if (!confirmed)
            throw new Exception($"{what}. Send {{\"confirm\": true}} to go ahead — the window would "
                              + "have asked, and over the API there is nobody to ask.");
    }

    // ---- log --------------------------------------------------------------

    private void Log(string line)
    {
        ControlServer.Jobs.Append(line);
        Dispatcher.Invoke(() =>
        {
            LogBox.AppendText(line + Environment.NewLine);
            LogBox.ScrollToEnd();
        });
    }
}
