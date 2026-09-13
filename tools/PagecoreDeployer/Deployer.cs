using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;

namespace PagecoreDeployer;

/// <summary>
/// All the actual work: SFTP/FTP transfers through curl, and the CMS password
/// reset. Credentials are handed to curl through a config on standard input, so
/// the password never appears on a command line, in the process table, or in
/// the log. The UI layer only ever passes it here and shows the log.
/// </summary>
public class Deployer
{
    private readonly Action<string> _log;
    public Deployer(Action<string> log) => _log = log;

    // ---- endpoint ---------------------------------------------------------

    /// <summary>
    /// Where to connect and how. <see cref="Scheme"/> is what curl is handed;
    /// <see cref="Display"/> is what a person is shown and what gets saved.
    ///
    /// The two differ for FTP, and the difference is not cosmetic. This app never
    /// makes an unencrypted FTP connection: every FTP transfer carries curl's
    /// ssl-reqd, which demands AUTH TLS and fails rather than continuing in
    /// cleartext. But the scheme in a curl URL cannot say so — "ftps://" there
    /// means *implicit* FTPS on port 990, which hosts rarely offer, so the URL
    /// has to read "ftp://" while the connection is in fact explicit TLS.
    ///
    /// Showing that internal spelling in a settings file was a mistake: it said
    /// plain FTP and meant the opposite. So a connection is displayed and stored
    /// as "ftps://", which is what every FTP client calls it and what it is,
    /// while curl keeps the spelling it needs.
    /// </summary>
    public record Endpoint(string Scheme, string HostPort)
    {
        public bool IsFtp => Scheme.Equals("ftp", StringComparison.OrdinalIgnoreCase);

        /// <summary>The scheme as it should be written down: never a bare "ftp".</summary>
        public string DisplayScheme => IsFtp ? "ftps" : Scheme;

        public string Display => $"{DisplayScheme}://{HostPort}";

        public static Endpoint Parse(string host)
        {
            string scheme = "sftp";
            string rest = (host ?? "").Trim();
            int schemeAt = rest.IndexOf("://", StringComparison.Ordinal);
            if (schemeAt >= 0)
            {
                scheme = rest[..schemeAt].ToLowerInvariant();
                rest = rest[(schemeAt + 3)..];
            }

            // Both spellings arrive here meaning the same thing — explicit TLS —
            // because that is the only kind of FTP this app will make.
            if (scheme is "ftps" or "ftpes" or "ftp") scheme = "ftp";
            int slash = rest.IndexOf('/');           // strip any trailing path
            if (slash >= 0) rest = rest[..slash];
            return new Endpoint(scheme, rest.Trim());
        }

        public string Url(string absoluteRemotePath)
        {
            string p = "/" + absoluteRemotePath.Replace('\\', '/').TrimStart('/');
            return $"{Scheme}://{HostPort}{p}";
        }
    }

    // What each button uploads is chosen by EngineLayout and SiteLayout, which
    // allow-list the files that belong to a Pagecore installation rather than
    // skipping the junk anyone happens to have put next to it.

    // ---- public actions ---------------------------------------------------

    /// <summary>
    /// Settle the transport before the first transfer. Otherwise a curl that
    /// cannot speak the scheme fails once per file and buries the one line that
    /// explains why under dozens of identical failures.
    /// </summary>
    private void Preflight(Endpoint ep)
    {
        string exe = Curl.ResolveFor(ep.Scheme);
        string security = ep.IsFtp
            ? "FTPS — explicit TLS, required (never falls back to cleartext), certificate "
              + (CertificateApproved ? "approved by fingerprint" : "verified")
            : "SFTP — encrypted, host key not verified";
        _log($"Transport: {security}. Through {exe}.");
    }

    /// <summary>
    /// Turn the transport failures whose curl text does not say what to do into
    /// ones that do.
    /// </summary>
    private static string Explain(int exitCode, string message, Endpoint ep) => exitCode switch
    {
        60 => message + "\n\nThe server's certificate could not be verified for "
                      + $"\"{ep.HostPort}\". This is usually the host name: a shared server "
                      + "presents a certificate in its own name, not in each domain's, so the "
                      + "Host field has to carry the name the certificate is issued for. "
                      + "Nothing is sent until it verifies.",
        64 => message + "\n\nThe server refused to secure the connection, and this app will "
                      + "not fall back to sending the password and your files in the clear. "
                      + "Check whether the host still offers FTP over TLS.",
        67 => message + "\n\nThe user name or password was rejected. Check that the login "
                      + "belongs to the same hosting account as the site.",
        _ => message,
    };

    /// <summary>
    /// Check that the remote website folder is already there, before anything is
    /// written into it.
    ///
    /// Transfers run with ftp-create-dirs, which has to be on — an upload creates
    /// assets/, uploads/2026/01/ and the rest as it goes. The cost is that a
    /// mistyped root is created too, so every file reports success into a
    /// brand-new tree while the live site never changes. A folder that must
    /// already exist is what tells the two apart.
    ///
    /// Returns the problem rather than throwing: an account that can write a
    /// directory it may not list is unusual but possible, and that should cost a
    /// confirmation, not a lock-out.
    /// </summary>
    public string? ProbeRemoteDir(Endpoint ep, string user, string pass, string remoteDir)
    {
        var (ok, _, err, _) = RunCurlList(ep, user, pass, ep.Url(remoteDir.TrimEnd('/') + "/"));
        if (ok) return null;

        return $"The remote website folder cannot be read on the host:\n\n    {remoteDir}\n\n{err}\n\n"
             + "It should already exist. This app creates the folders below it as it uploads, so a "
             + "root that is wrong by one segment gets created silently — every file then reports "
             + "success while the live site never changes."
             + WhatIsThere(ep, user, pass, remoteDir);
    }

    /// <summary>
    /// What the account can actually see, so a wrong path leads straight to the
    /// right one instead of to another guess. Looks at the nearest readable
    /// ancestor of the path given, and at the account's own home directory —
    /// which on shared hosting is often where the login lands and where the
    /// domain folders really live.
    /// </summary>
    private string WhatIsThere(Endpoint ep, string user, string pass, string remoteDir)
    {
        var report = new StringBuilder();

        foreach (string ancestor in Ancestors(remoteDir))
        {
            var (ok, _, _, listing) = RunCurlList(ep, user, pass, ep.Url(ancestor.TrimEnd('/') + "/"));
            if (!ok) continue;
            report.Append($"\n\nThe nearest folder that does exist is {ancestor}, holding:\n\n"
                        + Indent(listing));
            break;
        }

        // "~" is curl's SFTP spelling of the login's home directory.
        var (homeOk, _, _, home) = RunCurlList(ep, user, pass, $"{ep.Scheme}://{ep.HostPort}/~/");
        if (homeOk)
            report.Append("\n\nThe account's home directory holds:\n\n" + Indent(home));

        if (report.Length == 0)
            report.Append("\n\nNo parent of that path could be read either — check the User and "
                        + "Password as well as the path.");

        return report.ToString();
    }

    /// <summary>Every containing directory of a path, deepest first.</summary>
    private static IEnumerable<string> Ancestors(string remoteDir)
    {
        string path = remoteDir.Replace('\\', '/').TrimEnd('/');
        while (true)
        {
            int slash = path.LastIndexOf('/');
            if (slash < 0) yield break;
            path = slash == 0 ? "/" : path[..slash];
            yield return path;
            if (path == "/") yield break;
        }
    }

    private static string Indent(string listing)
    {
        var entries = listing.Split('\n')
            .Select(l => l.Trim())
            .Where(l => l.Length > 0 && l != "." && l != "..")
            .ToList();

        if (entries.Count == 0) return "    (empty)";

        var text = new StringBuilder();
        foreach (string entry in entries.Take(30)) text.AppendLine("    " + entry);
        if (entries.Count > 30) text.Append($"    …and {entries.Count - 30} more");
        return text.ToString().TrimEnd();
    }

    /// <summary>
    /// Log in and look around, writing nothing. For checking a credential or a
    /// path without an upload riding on the answer — and so that trying one is
    /// never a reason to reach for a client that speaks plaintext.
    /// </summary>
    public void TestConnection(Endpoint ep, string user, string pass, string remoteWebsite)
    {
        Preflight(ep);
        _log($"Logging in as {user}…");

        var (ok, _, err, listing) = RunCurlList(ep, user, pass, ep.Url(remoteWebsite.TrimEnd('/') + "/"));
        if (ok)
        {
            _log($"Signed in. {remoteWebsite} holds:");
            _log(Indent(listing));
            string privateDir = DerivePrivateDir(remoteWebsite);
            var (privateOk, _, _, privateListing) = RunCurlList(ep, user, pass, ep.Url(privateDir + "/"));
            _log(privateOk ? $"{privateDir} holds:" : $"{privateDir} is not there yet.");
            if (privateOk) _log(Indent(privateListing));
            _log("Connection, credential and remote path all check out.");
            return;
        }

        _log($"Could not read {remoteWebsite}: {err}");
        throw new Exception($"Could not read {remoteWebsite}.\n\n{err}{WhatIsThere(ep, user, pass, remoteWebsite)}");
    }

    // ---- scan ---------------------------------------------------------------

    private const int ScanMaxDepth = 12;
    private const int ScanMaxEntries = 40000;
    private const int QuarantineMaxFiles = 60;

    /// <summary>
    /// Walk the whole login — every domain folder it can see — and report files
    /// whose name or location is worth a look, downloading a copy of each so it
    /// can be read locally.
    ///
    /// Read-only, deliberately and permanently. Nothing here deletes, renames or
    /// moves anything on the host: a name-based rule cannot tell a backdoor from
    /// an oddly named script, and a wrong deletion on a live site is not
    /// recoverable from here. The report is for a person to act on.
    /// </summary>
    public void ScanRemote(Endpoint ep, string user, string pass, string root, string reportDir)
    {
        Preflight(ep);
        _log($"Scanning {(root.Length == 0 ? "/" : root)} — reading only, nothing will be changed.");

        var findings = new List<SuspiciousFiles.Finding>();
        int directories = 0, files = 0;
        var queue = new Queue<(string Path, int Depth)>();
        queue.Enqueue((root.TrimEnd('/'), 0));

        while (queue.Count > 0 && directories + files < ScanMaxEntries)
        {
            var (path, depth) = queue.Dequeue();
            var (ok, _, err, listing) = RunCurlList(ep, user, pass, ep.Url(path + "/"), longFormat: true);
            directories++;
            if (!ok) { _log($"  (could not read {path}/: {err})"); continue; }

            foreach (var entry in ParseListing(listing))
            {
                string child = path + "/" + entry.Name;

                // Symlinks are not followed: private_html -> ./public_html would
                // walk the same tree twice, and a loop would never end.
                if (entry.IsSymlink) { _log($"  (symlink, not followed: {child} -> {entry.Target})"); continue; }

                if (entry.IsDirectory)
                {
                    if (depth < ScanMaxDepth) queue.Enqueue((child, depth + 1));
                    else _log($"  (too deep, not descended: {child}/)");
                    continue;
                }

                files++;
                string? reason = SuspiciousFiles.Reason(child, isDirectory: false);
                if (reason == null) continue;
                findings.Add(new SuspiciousFiles.Finding(child, entry.Size, entry.Modified, reason));
                _log($"  FLAG {child}  ({entry.Size} bytes, {entry.Modified}) — {reason}");
            }
        }

        _log($"Read {files} file(s) across {directories} folder(s).");
        if (findings.Count == 0)
        {
            _log("Nothing stood out. That is not a clean bill of health — a scanner that only "
               + "reads names cannot see a backdoor appended to a legitimate file.");
            return;
        }

        string report = WriteReport(ep, user, pass, root, findings, files, directories, reportDir);
        _log("");
        _log($"{findings.Count} file(s) flagged. Report and downloaded copies: {report}");
        _log("Nothing on the host was changed. Read the copies before deciding what to remove.");
    }

    private string WriteReport(Endpoint ep, string user, string pass, string root,
        List<SuspiciousFiles.Finding> findings, int files, int directories, string reportDir)
    {
        string stamp = DateTime.Now.ToString("yyyyMMdd-HHmmss");
        string folder = Path.Combine(reportDir, "scan-" + stamp);
        Directory.CreateDirectory(folder);

        var text = new StringBuilder();
        text.AppendLine($"Pagecore Deployer remote scan — {DateTime.Now:yyyy-MM-dd HH:mm}");
        text.AppendLine($"Host   {ep.Scheme}://{ep.HostPort}");
        text.AppendLine($"Login  {user}");
        text.AppendLine($"Root   {(root.Length == 0 ? "/" : root)}");
        text.AppendLine($"Read   {files} files across {directories} folders");
        text.AppendLine();
        text.AppendLine("FLAGGED — suspicion only. Read each file before acting on it.");
        text.AppendLine();

        int downloaded = 0;
        foreach (var finding in findings.OrderBy(f => f.Path, StringComparer.OrdinalIgnoreCase))
        {
            text.AppendLine(finding.Path);
            text.AppendLine($"    {finding.Size} bytes, modified {finding.Modified}");
            text.AppendLine($"    {finding.Reason}");

            if (downloaded < QuarantineMaxFiles)
            {
                // Saved with a .txt suffix so nothing on this machine will run it
                // by accident.
                string local = Path.Combine(folder, finding.Path.Trim('/').Replace('/', '_') + ".txt");
                var (ok, _, err) = RunCurlTransfer(ep, user, pass, "output", local, ep.Url(finding.Path));
                text.AppendLine(ok ? $"    copy: {Path.GetFileName(local)}" : $"    copy failed: {err}");
                if (ok) downloaded++;
            }
            else
            {
                text.AppendLine("    copy skipped (limit reached)");
            }
            text.AppendLine();
        }

        text.AppendLine("Nothing was deleted, renamed or moved. This tool does not modify the host "
                      + "outside its own upload buttons.");
        string reportPath = Path.Combine(folder, "report.txt");
        File.WriteAllText(reportPath, text.ToString(), new UTF8Encoding(false));
        return folder;
    }

    // ---- listing parsing ----------------------------------------------------

    private sealed record ListEntry(string Name, bool IsDirectory, bool IsSymlink, long Size,
                                    string Modified, string Target);

    // The Unix long format that both FTP LIST and SFTP return:
    //   drwxr-xr-x 2 ftp ftp  4096 Aug  2 06:48 assets
    //   -rw-r--r-- 1 ftp ftp 26848 Aug  2 05:51 style.css
    //   lrwxrwxrwx 1 ftp ftp    13 Aug  1 22:27 private_html -> ./public_html
    private static readonly Regex ListLine = new(
        @"^(?<type>[dl\-])[rwxsStT\-]{9}[\.\+]?\s+\d+\s+\S+\s+\S+\s+(?<size>\d+)\s+" +
        @"(?<date>\w{3}\s+\d{1,2}\s+(?:\d{1,2}:\d{2}|\d{4}))\s+(?<name>.+)$",
        RegexOptions.Compiled);

    private static IEnumerable<ListEntry> ParseListing(string listing)
    {
        foreach (string raw in listing.Split('\n'))
        {
            var match = ListLine.Match(raw.TrimEnd('\r'));
            if (!match.Success) continue;

            string name = match.Groups["name"].Value.Trim();
            string target = "";
            bool isSymlink = match.Groups["type"].Value == "l";
            if (isSymlink)
            {
                int arrow = name.IndexOf(" -> ", StringComparison.Ordinal);
                if (arrow > 0) { target = name[(arrow + 4)..]; name = name[..arrow]; }
            }
            if (name is "." or "..") continue;

            yield return new ListEntry(name, match.Groups["type"].Value == "d", isSymlink,
                long.TryParse(match.Groups["size"].Value, out long size) ? size : 0,
                match.Groups["date"].Value, target);
        }
    }

    public void UploadEngine(Endpoint ep, string user, string pass, EngineLayout.Plan plan, string remoteWebsite)
    {
        Preflight(ep);
        string remoteCms = remoteWebsite.TrimEnd('/') + "/cms";
        _log($"Engine folder: {plan.Root}");
        _log($"Recognised Pagecore {plan.Version} — {plan.Files.Count} engine file(s) to upload.");
        foreach (string skipped in plan.Skipped) _log($"  not part of the engine, leaving alone: {skipped}");
        _log($"Uploading engine → {remoteCms}");

        var progress = new Progress();
        Send(ep, user, pass, plan.Files, remoteCms, progress);
        Summarize(plan.Skipped.Count, progress);
    }

    public void UploadContent(Endpoint ep, string user, string pass, SiteLayout.Plan plan, string remoteWebsite)
    {
        Preflight(ep);
        string remotePublic = remoteWebsite.TrimEnd('/');
        string remotePrivate = DerivePrivateDir(remoteWebsite);

        _log($"Website folder: {plan.Root}");
        _log($"Recognised a Pagecore site — {plan.PublicFiles.Count} public file(s), {plan.PrivateFiles.Count} private file(s).");
        foreach (string skipped in plan.Skipped) _log($"  not published, leaving alone: {skipped}");

        var progress = new Progress();

        _log($"Uploading {SiteLayout.PublicDir} → {remotePublic}");
        Send(ep, user, pass, plan.PublicFiles, remotePublic, progress);

        _log($"Uploading {SiteLayout.PrivateDir} → {remotePrivate}");
        Send(ep, user, pass, plan.PrivateFiles, remotePrivate, progress);

        Summarize(plan.Skipped.Count, progress);
    }

    // ---- folder upload ----------------------------------------------------

    private sealed class Progress
    {
        public int Uploaded;
        public List<string> Failures { get; } = new();
    }

    /// <summary>
    /// How many files may fail before the first one succeeds. Past this the
    /// problem is the host or the credential, not a flaky transfer, and there is
    /// nothing to learn from spending three attempts on every remaining file.
    /// </summary>
    private const int GiveUpAfter = 3;

    private void Send(Endpoint ep, string user, string pass,
        IReadOnlyList<(string Absolute, string Relative)> files, string remoteBase, Progress progress)
    {
        foreach (var (absolute, relative) in files)
        {
            var (ok, err) = Transfer(ep, user, pass, "upload-file", absolute,
                ep.Url(remoteBase + "/" + relative), relative);
            if (ok) { _log($"  ↑ {relative}"); progress.Uploaded++; continue; }

            _log($"  FAILED {relative}: {err}");
            progress.Failures.Add($"{relative} — {err}");

            if (progress.Uploaded == 0 && progress.Failures.Count >= GiveUpAfter)
            {
                _log($"Stopping: the first {GiveUpAfter} files all failed.");
                throw new Exception(
                    $"The first {GiveUpAfter} files all failed and nothing was uploaded, so the run was "
                    + "stopped rather than working through the rest.\n\n"
                    + Detail(progress.Failures)
                    + "\n\nThat pattern is the host, the credential or the remote path — not a flaky "
                    + "transfer. Check the Host, User, Password and Remote website fields.");
            }
        }
    }

    private void Summarize(int skip, Progress progress)
    {
        _log($"Done — {progress.Uploaded} uploaded, {skip} skipped, {progress.Failures.Count} failed.");
        if (progress.Failures.Count == 0) return;

        // Name what failed. A bare count sends you hunting back through a log of
        // a hundred lines for the one that matters.
        throw new Exception(
            $"{progress.Failures.Count} file(s) failed to upload after {Attempts} attempts each:\n\n"
            + Detail(progress.Failures)
            + $"\n\nThe other {progress.Uploaded} uploaded. Running the same button again re-sends "
            + "every file, which is safe: each upload replaces the file on the host.");
    }

    private static string Detail(List<string> failures)
    {
        var detail = new StringBuilder();
        foreach (string failure in failures.Take(10)) detail.AppendLine("  " + failure);
        if (failures.Count > 10) detail.Append($"  …and {failures.Count - 10} more — see the log.");
        return detail.ToString().TrimEnd();
    }

    // ---- password reset ---------------------------------------------------

    public void ResetPassword(Endpoint ep, string user, string pass, string remoteWebsite, string? desiredPassword)
    {
        Preflight(ep);
        string remoteConfig = DeriveConfigPath(remoteWebsite);
        _log($"Config on host: {remoteConfig}");

        string newPassword = string.IsNullOrWhiteSpace(desiredPassword) ? GeneratePassword() : desiredPassword.Trim();
        string hash = PhpHash(newPassword);
        _log("Generated a new bcrypt hash.");

        string tmpIn = Path.Combine(Path.GetTempPath(), "pcd-config-" + Guid.NewGuid().ToString("N") + ".php");
        var (dok, derr) = Transfer(ep, user, pass, "output", tmpIn, ep.Url(remoteConfig), "config.php");
        if (!dok) throw new Exception($"Could not download the config: {derr}");
        if (!File.Exists(tmpIn) || new FileInfo(tmpIn).Length == 0)
            throw new Exception("Downloaded config is empty — check the remote website path and credentials.");

        string text = File.ReadAllText(tmpIn);

        // Keep a timestamped backup so the previous file can always be restored.
        string backupDir = Path.Combine(Settings.Dir, "config-backups");
        Directory.CreateDirectory(backupDir);
        string backup = Path.Combine(backupDir, $"config-{DateTime.Now:yyyyMMdd-HHmmss}.php");
        File.Copy(tmpIn, backup, true);
        _log($"Backed up the current config to {backup}");

        var pattern = new Regex(@"(?m)^(\s*'password_hash'\s*=>\s*')[^']*(',)");
        if (!pattern.IsMatch(text))
            throw new Exception("No password_hash line found in the config; nothing changed on the server.");
        string patched = pattern.Replace(text, m => m.Groups[1].Value + hash + m.Groups[2].Value, 1);
        if (patched == text || !patched.Contains(hash))
            throw new Exception("Patching the config produced no change; nothing was uploaded.");

        string tmpOut = Path.Combine(Path.GetTempPath(), "pcd-config-out-" + Guid.NewGuid().ToString("N") + ".php");
        File.WriteAllText(tmpOut, patched, new UTF8Encoding(false));

        if (!PhpLint(tmpOut))
            throw new Exception("The patched config does not parse; nothing was uploaded. Original is safe on the server.");

        var (uok, uerr) = Transfer(ep, user, pass, "upload-file", tmpOut, ep.Url(remoteConfig), "config.php");
        TryDelete(tmpIn); TryDelete(tmpOut);
        if (!uok) throw new Exception($"Upload of the patched config failed: {uerr}. The live site is unchanged.");

        _log("Uploaded the new config.");

        string? siteUrl = ExtractSiteUrl(text);
        if (siteUrl != null)
        {
            string login = siteUrl.TrimEnd('/') + "/cms/login.php";
            int code = HttpStatus(login);
            _log($"Login page {login} → {code}");
            if (code != 200)
                _log($"WARNING: login page did not return 200. Restore with the backup at {backup} if needed.");
        }

        _log("");
        _log("========================================");
        _log("  NEW CMS LOGIN");
        _log("  username: admin");
        _log($"  password: {newPassword}");
        _log("========================================");
    }

    /// <summary>
    /// The private storage directory on the host. Pagecore requires it beside
    /// the document root rather than below it, so it is the remote website's
    /// sibling.
    /// </summary>
    public static string DerivePrivateDir(string remoteWebsite)
    {
        string trimmed = remoteWebsite.Replace('\\', '/').TrimEnd('/');
        int lastSlash = trimmed.LastIndexOf('/');
        string parent = lastSlash > 0 ? trimmed[..lastSlash] : "";
        return parent + "/" + SiteLayout.PrivateDir;
    }

    private static string DeriveConfigPath(string remoteWebsite) =>
        DerivePrivateDir(remoteWebsite) + "/config.php";

    private static string? ExtractSiteUrl(string configText)
    {
        var m = Regex.Match(configText, @"'site_url'\s*=>.*?['""](https?://[^'""]+)['""]", RegexOptions.Singleline);
        return m.Success ? m.Groups[1].Value : null;
    }

    // ---- curl -------------------------------------------------------------

    /// <summary>How many times a single file is attempted before it is a failure.</summary>
    private const int Attempts = 3;

    /// <summary>
    /// curl exit codes worth trying again. Every one of these is a fault in the
    /// link rather than in the request: a dropped SSH session, a reset, a
    /// timeout, a truncated transfer. A whole upload is one SSH session per
    /// file, so over a hundred files a single blip is close to expected — and
    /// re-sending the file is the correct response, since an upload replaces
    /// whatever partial copy the failure left behind.
    ///
    /// Deliberately absent: 1 (protocol disabled), 3 (malformed URL), 9 and 67
    /// (access and login denied), 78 (no such file). Those say the request is
    /// wrong, and repeating it only delays the answer — a repeated login is also
    /// how an account gets locked out.
    /// </summary>
    private static readonly HashSet<int> TransientExitCodes = new()
    {
        6,   // could not resolve host
        7,   // failed to connect
        18,  // partial file
        23,  // write error
        28,  // operation timed out
        35,  // SSL/TLS connect error
        52,  // nothing returned
        55,  // failure sending network data
        56,  // failure receiving network data
        79,  // SSH session error
    };

    /// <summary>
    /// One transfer, retried while the failure looks like the network. Logs each
    /// retry so a run that limped is distinguishable from one that was clean.
    /// </summary>
    private (bool ok, string error) Transfer(Endpoint ep, string user, string pass,
        string direction, string localFile, string url, string label)
    {
        string error = "";
        for (int attempt = 1; attempt <= Attempts; attempt++)
        {
            var (ok, code, message) = RunCurlTransfer(ep, user, pass, direction, localFile, url);
            if (ok) return (true, "");

            error = message;
            if (!TransientExitCodes.Contains(code) || attempt == Attempts) break;

            // Back off a little: an overloaded or rate-limiting host needs a
            // moment more than an instant retry gives it.
            int delaySeconds = attempt * 2;
            _log($"  retrying {label} in {delaySeconds}s (attempt {attempt} of {Attempts}): {message}");
            Thread.Sleep(TimeSpan.FromSeconds(delaySeconds));
        }
        return (false, error);
    }

    /// <summary>
    /// List a remote directory. Nothing is written, and notably ftp-create-dirs
    /// is absent: the whole point is to find out whether the directory is there.
    /// </summary>
    private (bool ok, int exitCode, string error, string output) RunCurlList(
        Endpoint ep, string user, string pass, string url, bool longFormat = false)
    {
        var config = BaseConfig(ep, user, pass, url, createDirs: false);
        // list-only gives bare names; the long form carries the type, size and
        // date a scan needs to tell a folder from a file.
        if (!longFormat) config.AppendLine("list-only");
        return RunCurl(config, ep);
    }

    /// <summary>
    /// One transfer through curl. <paramref name="direction"/> is "upload-file"
    /// or "output"; <paramref name="localFile"/> is the source or destination.
    /// </summary>
    private (bool ok, int exitCode, string error) RunCurlTransfer(Endpoint ep, string user, string pass,
        string direction, string localFile, string url)
    {
        var config = BaseConfig(ep, user, pass, url, createDirs: true);
        config.AppendLine($"{direction} = \"{CurlEscape(localFile.Replace('\\', '/'))}\"");
        var (ok, exitCode, error, _) = RunCurl(config, ep);
        return (ok, exitCode, error);
    }

    /// <summary>
    /// The options every call shares. The credential goes in through stdin with
    /// the rest, so nothing sensitive touches the command line, the process
    /// table, or the log.
    /// </summary>
    /// <summary>
    /// Set when this host's certificate was approved by fingerprint rather than
    /// verified by the system. curl's own verification is then stood down —
    /// the fingerprint check that replaced it has already run.
    /// </summary>
    public bool CertificateApproved { get; set; }

    private StringBuilder BaseConfig(Endpoint ep, string user, string pass, string url, bool createDirs)
    {
        var config = new StringBuilder();
        config.AppendLine("silent");
        config.AppendLine("show-error");
        config.AppendLine("connect-timeout = 30");
        if (createDirs) config.AppendLine("ftp-create-dirs");

        if (ep.IsFtp)
        {
            // ssl-reqd, never plain "ssl". curl's "ssl" is opportunistic: it asks
            // for AUTH TLS and carries on in cleartext if the server declines —
            // so a server that quietly stopped offering TLS would send the
            // password and every file in the clear, with no error and nothing in
            // the log to notice. ssl-reqd fails the transfer instead.
            config.AppendLine("ssl-reqd");
            // Certificates are verified. On Windows curl uses Schannel, which
            // fetches a missing intermediate through the AIA extension, so a
            // server that sends only its leaf still verifies. The name in the
            // certificate has to be the name in the Host field.
            //
            // Unless this exact certificate was approved by fingerprint, in
            // which case the check has already been made, and made more
            // strictly: curl would accept any certificate that verifies,
            // approval accepts one.
            if (CertificateApproved) config.AppendLine("insecure");
        }
        else
        {
            // SFTP. The session is encrypted either way; this skips the
            // known_hosts check, which no first connection can satisfy.
            config.AppendLine("insecure");
        }

        config.AppendLine($"user = \"{CurlEscape(user)}:{CurlEscape(pass)}\"");
        config.AppendLine($"url = \"{CurlEscape(url)}\"");
        return config;
    }

    private (bool ok, int exitCode, string error, string output) RunCurl(StringBuilder config, Endpoint ep)
    {
        var psi = new ProcessStartInfo(Curl.ResolveFor(ep.Scheme))
        {
            RedirectStandardInput = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        psi.ArgumentList.Add("--config");
        psi.ArgumentList.Add("-");

        try
        {
            using var proc = Process.Start(psi)!;
            proc.StandardInput.Write(config.ToString());
            proc.StandardInput.Close();
            var outTask = proc.StandardOutput.ReadToEndAsync();
            var errTask = proc.StandardError.ReadToEndAsync();
            proc.WaitForExit();
            string err = errTask.Result.Trim();
            if (proc.ExitCode == 0) return (true, 0, "", outTask.Result);
            // Keep the exit code as well as the text: the code is what decides
            // whether trying again is worth anything.
            string message = err.Length > 0 ? $"{err} (curl exit {proc.ExitCode})" : $"curl exit {proc.ExitCode}";
            return (false, proc.ExitCode, Explain(proc.ExitCode, message, ep), "");
        }
        catch (Exception ex)
        {
            // Failing to start curl at all is not a network fault; -1 keeps it
            // out of the transient set.
            return (false, -1, ex.Message, "");
        }
    }

    // curl config strings are double-quoted with backslash escaping.
    private static string CurlEscape(string s) => s.Replace("\\", "\\\\").Replace("\"", "\\\"");

    // ---- php (bundled with the repo) --------------------------------------

    private static string FindPhp()
    {
        for (var dir = new DirectoryInfo(AppContext.BaseDirectory); dir != null; dir = dir.Parent)
        {
            string candidate = Path.Combine(dir.FullName, "php", "php.exe");
            if (File.Exists(candidate)) return candidate;
        }
        return "php"; // fall back to PATH
    }

    private string PhpHash(string password)
    {
        var psi = new ProcessStartInfo(FindPhp())
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        psi.ArgumentList.Add("-r");
        psi.ArgumentList.Add("echo password_hash(getenv('PCD_PW'), PASSWORD_DEFAULT);");
        psi.Environment["PCD_PW"] = password;          // keep the password off the command line

        using var proc = Process.Start(psi)!;
        string outp = proc.StandardOutput.ReadToEnd().Trim();
        string err = proc.StandardError.ReadToEnd().Trim();
        proc.WaitForExit();
        if (proc.ExitCode != 0 || !outp.StartsWith("$2"))
            throw new Exception($"Could not hash the password with PHP ({FindPhp()}): {err}");
        return outp;
    }

    private bool PhpLint(string file)
    {
        var psi = new ProcessStartInfo(FindPhp())
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        psi.ArgumentList.Add("-l");
        psi.ArgumentList.Add(file);
        using var proc = Process.Start(psi)!;
        proc.StandardOutput.ReadToEnd();
        proc.StandardError.ReadToEnd();
        proc.WaitForExit();
        return proc.ExitCode == 0;
    }

    // ---- misc -------------------------------------------------------------

    private static int HttpStatus(string url)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
            using var resp = http.GetAsync(url).Result;
            return (int)resp.StatusCode;
        }
        catch { return 0; }
    }

    private static string GeneratePassword()
    {
        string[] words = { "brzeg", "kotwica", "lampa", "migdal", "nurek", "oliwka",
                           "pastel", "rytm", "wiatr", "zegar" };
        var rng = new Random();
        return $"{words[rng.Next(words.Length)]}-{words[rng.Next(words.Length)]}-{rng.Next(1000, 9999)}";
    }

    private static void TryDelete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch { }
    }
}
