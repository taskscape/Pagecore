using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.RegularExpressions;

namespace PagecoreDeployer;

/// <summary>
/// Names and locations that are worth a person's attention on a web host.
///
/// Every rule here is a *suspicion*, never a verdict. A file is flagged by where
/// it sits and what it is called, which is enough to say "look at this" and
/// nowhere near enough to say "this is a backdoor" — only reading the file can
/// do that. Nothing in this application acts on a flag; the report goes to the
/// person who can tell the difference.
/// </summary>
public static class SuspiciousFiles
{
    // Anything that runs as PHP, however it is spelled.
    private static readonly Regex Executable =
        new(@"\.(?:php\d?|phtml|phps|pht|phar|inc)$", RegexOptions.IgnoreCase | RegexOptions.Compiled);

    // A short prefix, a hyphen, then a token with both letters and digits:
    // wp-poy4bn.php, sgz-arju3w.php. Real code is named for what it does, and a
    // required digit keeps ordinary names — media-file.php, request-guard.php,
    // config-schema.php — out of it.
    private static readonly Regex RandomSuffix =
        new(@"^[a-z]{2,12}-(?=[a-z0-9]*\d)(?=[a-z0-9]*[a-z])[a-z0-9]{5,12}\.(?:php\d?|phtml|phar)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

    // One long meaningless token: k7fj29dkslq83nf.php
    private static readonly Regex RandomName =
        new(@"^(?=[a-z0-9]*\d)(?=[a-z0-9]*[a-z])[a-z0-9]{14,}\.(?:php\d?|phtml|phar)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

    // image.jpg.php and friends — an upload filter that only looked at the first
    // extension lets these through.
    private static readonly Regex DoubleExtension =
        new(@"\.(?:jpe?g|png|gif|webp|bmp|svg|pdf|txt|csv|zip|doc x?)\.(?:php\d?|phtml|phar)$",
            RegexOptions.IgnoreCase | RegexOptions.Compiled);

    // Directories that hold data, never code. A PHP file below one of these is
    // the single strongest signal available from a listing alone.
    private static readonly HashSet<string> DataOnlyDirs =
        new(StringComparer.OrdinalIgnoreCase)
        { "uploads", "upload", "images", "image", "img", "media", "assets", "files",
          "cache", "tmp", "temp", "backup", "backups", "logs", "css", "js", "fonts",
          "wp-content", "content", "awstats" };

    // Names that have been shells for twenty years.
    private static readonly HashSet<string> KnownShellNames =
        new(StringComparer.OrdinalIgnoreCase)
        { "shell.php", "c99.php", "r57.php", "wso.php", "b374k.php", "alfa.php",
          "mini.php", "up.php", "upload.php", "cmd.php", "adminer.php", "webshell.php",
          "indoxploit.php", "marijuana.php", "0byt3m1n1.php", "gel4y.php" };

    public sealed record Finding(string Path, long Size, string Modified, string Reason);

    /// <summary>
    /// Why this path is worth looking at, or null when nothing stands out.
    /// </summary>
    public static string? Reason(string path, bool isDirectory)
    {
        if (isDirectory) return null;

        string name = path[(path.LastIndexOf('/') + 1)..];
        string[] segments = path.Split('/', StringSplitOptions.RemoveEmptyEntries);
        var directories = segments.Take(Math.Max(0, segments.Length - 1)).ToArray();

        if (KnownShellNames.Contains(name))
            return "a file name long associated with web shells";

        if (DoubleExtension.IsMatch(name))
            return "two extensions — looks like an image but executes as PHP";

        if (Executable.IsMatch(name) && directories.Any(DataOnlyDirs.Contains))
            return $"executable PHP inside {directories.First(DataOnlyDirs.Contains)}/, which should hold data only";

        if (RandomSuffix.IsMatch(name))
            return "a familiar prefix with a random suffix — the shape of a dropped file, not a written one";

        if (RandomName.IsMatch(name))
            return "a long random file name";

        if (name.Equals(".htaccess", StringComparison.OrdinalIgnoreCase)
            && directories.Any(DataOnlyDirs.Contains))
            return $"an .htaccess inside {directories.First(DataOnlyDirs.Contains)}/ — can re-enable PHP where it was disabled";

        if (name.EndsWith(".suspected", StringComparison.OrdinalIgnoreCase))
            return "already quarantined by a host-side scanner";

        return null;
    }

    /// <summary>
    /// The files that ought to be there, so a report can say what it did not
    /// flag as well as what it did.
    /// </summary>
    public static bool IsPagecoreEngineFile(string path) =>
        path.Contains("/cms/", StringComparison.OrdinalIgnoreCase)
        && !path.Contains("/uploads/", StringComparison.OrdinalIgnoreCase);
}
