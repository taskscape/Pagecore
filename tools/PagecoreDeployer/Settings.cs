using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;

namespace PagecoreDeployer;

/// <summary>Remembered connection and folder settings, persisted to %APPDATA%.</summary>
public class Settings
{
    // Defaults for terapiawypalenia.pl. The FTP login is scoped to the hosting
    // account, so its root already holds the domain folders — the remote path
    // starts at the domain, with no /home or /domains above it.
    // ftps:// is explicit TLS over the normal FTP port, required — the only kind
    // of FTP this app makes. sftp:// is also accepted, for a login with SSH.
    public string Host { get; set; } = "ftps://mojerzeczy.com";
    public string User { get; set; } = "admin@mojerzeczy.com";
    public string EngineFolder { get; set; } = "";
    public string WebsiteFolder { get; set; } = "";
    public string RemoteFolder { get; set; } = "/terapiawypalenia.pl/public_html";

    /// <summary>DPAPI-encrypted, base64. Never the plaintext.</summary>
    public string ProtectedPassword { get; set; } = "";

    /// <summary>
    /// Host to the SHA-256 fingerprint of the certificate approved for it.
    /// Only certificates the system could not verify on its own end up here, and
    /// only after someone approved that exact certificate. A server that later
    /// presents a different one stops matching and has to be approved again,
    /// which is the point: this pins one certificate rather than turning
    /// verification off.
    /// </summary>
    public Dictionary<string, string> ApprovedCertificates { get; set; } = new(StringComparer.OrdinalIgnoreCase);

    public static string Dir =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "PagecoreDeployer");

    private static string FilePath => Path.Combine(Dir, "settings.json");

    public static Settings Load()
    {
        try
        {
            if (File.Exists(FilePath))
                return JsonSerializer.Deserialize<Settings>(File.ReadAllText(FilePath)) ?? new Settings();
        }
        catch
        {
            // A corrupt settings file must never stop the app from opening.
        }
        return new Settings();
    }

    public void Save()
    {
        Directory.CreateDirectory(Dir);
        File.WriteAllText(FilePath, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
    }

    public string GetPassword() => Dpapi.Unprotect(ProtectedPassword);
    public void SetPassword(string plain) => ProtectedPassword = Dpapi.Protect(plain);
}
