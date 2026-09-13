using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.RegularExpressions;

namespace PagecoreDeployer;

/// <summary>
/// Decides what "the engine" is. Two jobs: recognise whether a chosen folder is
/// a Pagecore engine (or a repository/site whose <c>cms</c> folder is one), and
/// list exactly which of its files belong in an engine upload.
///
/// The file list is an allow list, not a skip list. A skip list only holds while
/// nobody puts anything new next to the engine; pointing the app at a repository
/// root with a skip list means shipping <c>.git</c>, <c>node_modules</c> and the
/// test output to the live site. What ships is the same set the release build
/// takes from <c>cms</c>: the engine's own PHP, its assets, its vendored lib and
/// its modules — nothing else, whatever else happens to be in the folder.
/// </summary>
public static class EngineLayout
{
    // Present in every Pagecore engine folder and in no other folder of the
    // repository, so their presence is what identifies one.
    private static readonly string[] RequiredFiles =
        { "engine.php", "api.php", "auth.php", "content.php", "login.php", "config-schema.php" };

    private static readonly string[] RequiredDirs = { "assets", "lib", "modules" };

    // The only sub-folders of the engine that are uploaded, and the only kinds
    // of file taken from them or from the engine root.
    private static readonly HashSet<string> UploadDirs =
        new(StringComparer.OrdinalIgnoreCase) { "assets", "lib", "modules" };

    private static readonly HashSet<string> UploadExtensions =
        new(StringComparer.OrdinalIgnoreCase) { ".php", ".css", ".js", ".json", ".md" };

    // Extensionless names that are still part of the engine.
    private static readonly HashSet<string> UploadNames =
        new(StringComparer.OrdinalIgnoreCase) { ".htaccess" };

    // The live config belongs to the host, never to a build.
    private static readonly HashSet<string> NeverUpload =
        new(StringComparer.OrdinalIgnoreCase) { "config.php" };

    public sealed class Plan
    {
        public required string Root { get; init; }
        public string? Version { get; init; }

        /// <summary>True when the user chose a repository or site root and the
        /// engine was found in its <c>cms</c> folder.</summary>
        public bool Redirected { get; init; }

        public List<(string Absolute, string Relative)> Files { get; } = new();

        /// <summary>Top-level entries of the engine folder left behind, for the log.</summary>
        public List<string> Skipped { get; } = new();
    }

    // ---- recognition ------------------------------------------------------

    /// <summary>
    /// Resolves the folder the user picked to an engine folder and lists what
    /// would be uploaded from it. Throws with a message that names the folder
    /// checked and what was missing, so a wrong pick is obvious before anything
    /// touches the network.
    /// </summary>
    public static Plan Resolve(string selected)
    {
        if (string.IsNullOrWhiteSpace(selected))
            throw new Exception("Choose the local engine folder.");

        string root = Path.GetFullPath(selected.Trim());
        if (!Directory.Exists(root))
            throw new DirectoryNotFoundException($"The local engine folder does not exist:\n{root}");

        if (IsEngine(root, out _))
            return BuildPlan(root, redirected: false);

        // A repository root or a site root is a reasonable thing to pick. In a
        // repository the engine is cms/; in a site it is inside the document
        // root. Accept either and say which one was used.
        foreach (string nested in NestedCandidates(root))
            if (Directory.Exists(nested) && IsEngine(nested, out _))
                return BuildPlan(nested, redirected: true);

        throw new Exception(Explain(root));
    }

    private static bool IsEngine(string dir, out List<string> missing)
    {
        missing = new List<string>();
        foreach (string file in RequiredFiles)
            if (!File.Exists(Path.Combine(dir, file))) missing.Add(file);
        foreach (string sub in RequiredDirs)
            if (!Directory.Exists(Path.Combine(dir, sub))) missing.Add(sub + "/");

        // engine.php carries the version constant; a file of that name without
        // it is not the Pagecore engine.
        if (missing.Count == 0 && ReadVersion(dir) == null)
            missing.Add("PAGECORE_VERSION in engine.php");

        return missing.Count == 0;
    }

    private static IEnumerable<string> NestedCandidates(string root)
    {
        yield return Path.Combine(root, "cms");
        yield return Path.Combine(root, SiteLayout.PublicDir, "cms");
    }

    private static string Explain(string root)
    {
        IsEngine(root, out var missingHere);

        var lines = new List<string>
        {
            $"{root}",
            "is not a Pagecore engine folder.",
            "",
            "Point \"Local engine\" at the engine itself — the cms folder of a",
            "Pagecore repository or site, for example:",
            "    C:\\Projects\\Pagecore\\cms",
            "    C:\\Projects\\Pagecore\\terapiawypalenia\\public_html\\cms",
            "",
            $"Missing here: {string.Join(", ", missingHere.Take(6))}"
        };

        foreach (string nested in NestedCandidates(root).Where(Directory.Exists))
        {
            IsEngine(nested, out var missingNested);
            lines.Add($"Missing in {nested}: {string.Join(", ", missingNested.Take(6))}");
        }

        return string.Join(Environment.NewLine, lines);
    }

    /// <summary>The PAGECORE_VERSION the engine folder declares, or null.</summary>
    public static string? ReadVersion(string engineRoot)
    {
        string enginePhp = Path.Combine(engineRoot, "engine.php");
        if (!File.Exists(enginePhp)) return null;
        try
        {
            var match = Regex.Match(File.ReadAllText(enginePhp), @"PAGECORE_VERSION'\s*,\s*'([^']+)'");
            return match.Success ? match.Groups[1].Value : null;
        }
        catch
        {
            return null;
        }
    }

    // ---- file selection ---------------------------------------------------

    private static Plan BuildPlan(string root, bool redirected)
    {
        var plan = new Plan { Root = root, Version = ReadVersion(root), Redirected = redirected };

        foreach (string file in Directory.EnumerateFiles(root).OrderBy(f => f, StringComparer.OrdinalIgnoreCase))
        {
            string name = Path.GetFileName(file);
            if (IsUploadableFile(name)) plan.Files.Add((file, name));
            else plan.Skipped.Add(name);
        }

        foreach (string dir in Directory.EnumerateDirectories(root).OrderBy(d => d, StringComparer.OrdinalIgnoreCase))
        {
            string name = Path.GetFileName(dir);
            if (!UploadDirs.Contains(name)) { plan.Skipped.Add(name + "/"); continue; }

            foreach (string file in Directory.EnumerateFiles(dir, "*", SearchOption.AllDirectories)
                                             .OrderBy(f => f, StringComparer.OrdinalIgnoreCase))
            {
                string rel = Path.GetRelativePath(root, file).Replace('\\', '/');
                if (IsUploadableFile(Path.GetFileName(file))) plan.Files.Add((file, rel));
                else plan.Skipped.Add(rel);
            }
        }

        // Recognition already proved these exist; this catches a plan that
        // somehow dropped them before anything is sent.
        foreach (string required in RequiredFiles)
            if (!plan.Files.Any(f => f.Relative.Equals(required, StringComparison.OrdinalIgnoreCase)))
                throw new Exception($"Refusing to upload: {required} is not in the file list for {root}.");

        return plan;
    }

    private static bool IsUploadableFile(string name)
    {
        if (NeverUpload.Contains(name)) return false;
        if (UploadNames.Contains(name)) return true;
        if (name.StartsWith(".", StringComparison.Ordinal)) return false;
        return UploadExtensions.Contains(Path.GetExtension(name));
    }
}
