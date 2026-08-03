using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;

namespace PagecoreDeployer;

/// <summary>
/// Finds a curl that can actually speak the scheme in the Host field.
///
/// Windows ships curl.exe in system32, but it is built against Schannel with no
/// libssh2, so it has no SFTP — and being first on PATH it is the one a bare
/// "curl.exe" resolves to. Git for Windows ships a curl that does have SFTP.
/// Picking by capability rather than by PATH order is the difference between a
/// working upload and every file failing with "Protocol sftp not supported".
/// </summary>
public static class Curl
{
    private static readonly Dictionary<string, HashSet<string>?> Protocols =
        new(StringComparer.OrdinalIgnoreCase);

    /// <summary>
    /// The full path of a curl that supports <paramref name="scheme"/>. Throws
    /// with what was found and what to do about it when there is none.
    /// </summary>
    public static string ResolveFor(string scheme)
    {
        var candidates = Candidates().ToList();
        foreach (string exe in candidates)
            if (Supports(exe)?.Contains(scheme) == true) return exe;

        throw new Exception(Explain(scheme, candidates));
    }

    /// <summary>The protocols a curl reports, or null when it cannot be run.</summary>
    private static HashSet<string>? Supports(string exe)
    {
        if (Protocols.TryGetValue(exe, out var cached)) return cached;

        HashSet<string>? found = null;
        try
        {
            var psi = new ProcessStartInfo(exe)
            {
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            psi.ArgumentList.Add("--version");
            using var proc = Process.Start(psi)!;
            string output = proc.StandardOutput.ReadToEnd();
            proc.StandardError.ReadToEnd();
            proc.WaitForExit();

            string? line = output.Split('\n')
                .FirstOrDefault(l => l.StartsWith("Protocols:", StringComparison.OrdinalIgnoreCase));
            if (line != null)
            {
                found = new HashSet<string>(
                    line["Protocols:".Length..].Split(' ', StringSplitOptions.RemoveEmptyEntries),
                    StringComparer.OrdinalIgnoreCase);
            }
        }
        catch
        {
            // Not runnable — treated the same as one that cannot do the job.
        }

        Protocols[exe] = found;
        return found;
    }

    /// <summary>
    /// Every curl worth asking, in preference order: the ones that come with Git
    /// for Windows first, since those are the builds with SFTP, then whatever is
    /// on PATH.
    /// </summary>
    private static IEnumerable<string> Candidates()
    {
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        foreach (string root in GitRoots())
        {
            foreach (string relative in new[] { @"mingw64\bin\curl.exe", @"mingw32\bin\curl.exe", @"usr\bin\curl.exe" })
            {
                string exe = Path.Combine(root, relative);
                if (File.Exists(exe) && seen.Add(exe)) yield return exe;
            }
        }

        foreach (string dir in (Environment.GetEnvironmentVariable("PATH") ?? "")
                     .Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            string exe;
            try { exe = Path.Combine(dir.Trim(), "curl.exe"); }
            catch { continue; }   // a malformed PATH entry is not worth failing over
            if (File.Exists(exe) && seen.Add(exe)) yield return exe;
        }
    }

    private static IEnumerable<string> GitRoots()
    {
        foreach (var folder in new[] { Environment.SpecialFolder.ProgramFiles,
                                       Environment.SpecialFolder.ProgramFilesX86,
                                       Environment.SpecialFolder.LocalApplicationData })
        {
            string root = Environment.GetFolderPath(folder);
            if (root.Length == 0) continue;
            yield return Path.Combine(root, "Git");
            yield return Path.Combine(root, "Programs", "Git");
        }
    }

    private static string Explain(string scheme, List<string> candidates)
    {
        var lines = new List<string>
        {
            $"This host is set to {scheme.ToUpperInvariant()}, but no curl on this machine supports it.",
            "",
            "Found:"
        };

        foreach (string exe in candidates)
        {
            var protocols = Supports(exe);
            lines.Add(protocols == null
                ? $"  {exe}  (could not be run)"
                : $"  {exe}  ({string.Join(", ", protocols.OrderBy(p => p, StringComparer.Ordinal))})");
        }
        if (candidates.Count == 0) lines.Add("  no curl.exe at all");

        lines.Add("");
        if (scheme.Equals("sftp", StringComparison.OrdinalIgnoreCase))
        {
            lines.Add("The curl that ships with Windows is built without libssh2, so it");
            lines.Add("cannot speak SFTP. Either:");
            lines.Add("");
            lines.Add("  • install Git for Windows — its curl does support SFTP, and this");
            lines.Add("    app will find it on its own; or");
            lines.Add("  • use FTPS instead, by putting ftp:// in front of the host.");
        }
        else
        {
            lines.Add("Install a curl built with this protocol, or choose another scheme");
            lines.Add("in the Host field.");
        }

        return string.Join(Environment.NewLine, lines);
    }
}
