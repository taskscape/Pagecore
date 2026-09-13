using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;

namespace PagecoreDeployer;

/// <summary>
/// Decides what "the website" is. A Pagecore site uses the supported production
/// layout — a document root with its private storage as a sibling, never below
/// it — so the folder to publish is the one holding both:
///
///   terapiawypalenia/
///   ├── public_html/          the document root
///   └── pagecore-private/     config, content, uploads, mutable state
///
/// As with <see cref="EngineLayout"/> the file list is an allow list. The two
/// trees have different destinations on the host and different rules about what
/// may be overwritten, so they are planned separately.
/// </summary>
public static class SiteLayout
{
    public const string PublicDir = "public_html";
    public const string PrivateDir = "pagecore-private";

    // What makes a folder a Pagecore site rather than any folder with those two
    // names in it.
    private static readonly string[] RequiredPublic = { "index.php", "cms/" };
    private static readonly string[] RequiredPrivate = { "config.php", "content/" };

    // The document root holds only public files, so the rule is by kind rather
    // than by name: a template added tomorrow ships without editing this list.
    private static readonly HashSet<string> PublicRootExtensions =
        new(StringComparer.OrdinalIgnoreCase) { ".php", ".json", ".xml", ".txt", ".ico", ".webmanifest" };

    private static readonly HashSet<string> PublicAssetDirs =
        new(StringComparer.OrdinalIgnoreCase) { "assets", "partials" };

    private static readonly HashSet<string> PublicAssetExtensions =
        new(StringComparer.OrdinalIgnoreCase)
        { ".php", ".css", ".js", ".json", ".svg", ".png", ".jpg", ".jpeg", ".gif", ".webp",
          ".ico", ".woff", ".woff2" };

    // Only these two private sub-trees are site content. config.php and state/
    // belong to the host and are never overwritten from a development machine.
    private static readonly HashSet<string> PrivateDataDirs =
        new(StringComparer.OrdinalIgnoreCase) { "content", "uploads" };

    private static readonly HashSet<string> PrivateExtensions =
        new(StringComparer.OrdinalIgnoreCase)
        { ".md", ".json", ".php", ".txt", ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".pdf" };

    public sealed class Plan
    {
        public required string Root { get; init; }

        /// <summary>True when the user chose public_html or pagecore-private and
        /// the site was found in its parent.</summary>
        public bool Redirected { get; init; }

        /// <summary>Files under public_html, relative to it. Destination is the
        /// remote document root.</summary>
        public List<(string Absolute, string Relative)> PublicFiles { get; } = new();

        /// <summary>Files under pagecore-private, relative to it. Destination is
        /// the private directory beside the remote document root.</summary>
        public List<(string Absolute, string Relative)> PrivateFiles { get; } = new();

        public List<string> Skipped { get; } = new();

        public int TotalFiles => PublicFiles.Count + PrivateFiles.Count;
    }

    // ---- recognition ------------------------------------------------------

    /// <summary>
    /// Resolves the folder the user picked to a Pagecore site folder and lists
    /// what would be uploaded from it. Throws with a message naming the folder
    /// and what was missing.
    /// </summary>
    public static Plan Resolve(string selected)
    {
        if (string.IsNullOrWhiteSpace(selected))
            throw new Exception("Choose the local website folder.");

        string root = Path.GetFullPath(selected.Trim());
        if (!Directory.Exists(root))
            throw new DirectoryNotFoundException($"The local website folder does not exist:\n{root}");

        if (IsSite(root, out _))
            return BuildPlan(root, redirected: false);

        // Picking one half of the pair is a reasonable slip: the site is its parent.
        string name = Path.GetFileName(root.TrimEnd(Path.DirectorySeparatorChar));
        if (name.Equals(PublicDir, StringComparison.OrdinalIgnoreCase)
            || name.Equals(PrivateDir, StringComparison.OrdinalIgnoreCase))
        {
            string? parent = Path.GetDirectoryName(root);
            if (parent != null && IsSite(parent, out _))
                return BuildPlan(parent, redirected: true);
        }

        throw new Exception(Explain(root));
    }

    private static bool IsSite(string dir, out List<string> missing)
    {
        missing = new List<string>();
        string publicRoot = Path.Combine(dir, PublicDir);
        string privateRoot = Path.Combine(dir, PrivateDir);

        if (!Directory.Exists(publicRoot)) missing.Add(PublicDir + "/");
        else Require(publicRoot, RequiredPublic, PublicDir, missing);

        if (!Directory.Exists(privateRoot)) missing.Add(PrivateDir + "/");
        else Require(privateRoot, RequiredPrivate, PrivateDir, missing);

        return missing.Count == 0;
    }

    private static void Require(string root, string[] required, string label, List<string> missing)
    {
        foreach (string entry in required)
        {
            bool isDir = entry.EndsWith("/", StringComparison.Ordinal);
            string path = Path.Combine(root, entry.TrimEnd('/'));
            bool present = isDir ? Directory.Exists(path) : File.Exists(path);
            if (!present) missing.Add($"{label}/{entry}");
        }
    }

    private static string Explain(string root)
    {
        IsSite(root, out var missing);

        var lines = new List<string>
        {
            $"{root}",
            "is not a Pagecore website folder.",
            "",
            "Pagecore keeps its content, uploads and configuration outside the",
            "document root, so the folder to publish is the one holding both:",
            $"    {PublicDir}/        the document root",
            $"    {PrivateDir}/   config, content and uploads",
            "",
            "for example C:\\Projects\\Pagecore\\terapiawypalenia.",
            "",
            $"Missing: {string.Join(", ", missing.Take(6))}"
        };

        return string.Join(Environment.NewLine, lines);
    }

    // ---- file selection ---------------------------------------------------

    private static Plan BuildPlan(string root, bool redirected)
    {
        var plan = new Plan { Root = root, Redirected = redirected };
        CollectPublic(plan, Path.Combine(root, PublicDir));
        CollectPrivate(plan, Path.Combine(root, PrivateDir));

        if (plan.PublicFiles.Count == 0)
            throw new Exception($"Refusing to upload: no public files found under {Path.Combine(root, PublicDir)}.");

        return plan;
    }

    private static void CollectPublic(Plan plan, string publicRoot)
    {
        foreach (string file in Sorted(Directory.EnumerateFiles(publicRoot)))
        {
            string name = Path.GetFileName(file);

            // The development router stands in for the .htaccess rewrites and
            // must never reach a host that has them.
            if (name.Equals("router.php", StringComparison.OrdinalIgnoreCase))
            {
                plan.Skipped.Add($"{PublicDir}/router.php (development router)");
                continue;
            }
            // The production .htaccess carries the SetEnv line that points the
            // engine at the private config. The local file does not have it, and
            // overwriting takes the whole site down with a 500.
            if (name.Equals(".htaccess", StringComparison.OrdinalIgnoreCase))
            {
                plan.Skipped.Add($"{PublicDir}/.htaccess (carries the production SetEnv line)");
                continue;
            }
            if (IsHidden(name) || !PublicRootExtensions.Contains(Path.GetExtension(name)))
            {
                plan.Skipped.Add($"{PublicDir}/{name}");
                continue;
            }
            plan.PublicFiles.Add((file, name));
        }

        foreach (string dir in Sorted(Directory.EnumerateDirectories(publicRoot)))
        {
            string name = Path.GetFileName(dir);
            if (name.Equals("cms", StringComparison.OrdinalIgnoreCase))
            {
                plan.Skipped.Add($"{PublicDir}/cms/ (the engine — use \"Upload new engine\")");
                continue;
            }
            if (!PublicAssetDirs.Contains(name)) { plan.Skipped.Add($"{PublicDir}/{name}/"); continue; }

            foreach (string file in Sorted(Directory.EnumerateFiles(dir, "*", SearchOption.AllDirectories)))
            {
                string rel = Path.GetRelativePath(publicRoot, file).Replace('\\', '/');
                if (IsHidden(Path.GetFileName(file)) || !PublicAssetExtensions.Contains(Path.GetExtension(file)))
                    plan.Skipped.Add($"{PublicDir}/{rel}");
                else
                    plan.PublicFiles.Add((file, rel));
            }
        }
    }

    private static void CollectPrivate(Plan plan, string privateRoot)
    {
        // Nothing at the private root is uploaded: config.php is the live
        // configuration, which only "Reset password" is allowed to touch.
        foreach (string file in Sorted(Directory.EnumerateFiles(privateRoot)))
        {
            string name = Path.GetFileName(file);
            string why = name.Equals("config.php", StringComparison.OrdinalIgnoreCase)
                ? " (the live configuration)" : "";
            plan.Skipped.Add($"{PrivateDir}/{name}{why}");
        }

        foreach (string dir in Sorted(Directory.EnumerateDirectories(privateRoot)))
        {
            string name = Path.GetFileName(dir);
            if (!PrivateDataDirs.Contains(name))
            {
                // state/ holds the login-attempt budget and the audit log: the
                // host's own runtime record, not something to publish over.
                plan.Skipped.Add($"{PrivateDir}/{name}/");
                continue;
            }

            foreach (string file in Sorted(Directory.EnumerateFiles(dir, "*", SearchOption.AllDirectories)))
            {
                string rel = Path.GetRelativePath(privateRoot, file).Replace('\\', '/');
                // Backups, drafts, locks and state are the engine's own working
                // files; they sit in dot-directories and stay on the host.
                if (rel.Split('/').Any(IsHidden) || !PrivateExtensions.Contains(Path.GetExtension(file)))
                    plan.Skipped.Add($"{PrivateDir}/{rel}");
                else
                    plan.PrivateFiles.Add((file, rel));
            }
        }
    }

    private static IEnumerable<string> Sorted(IEnumerable<string> paths) =>
        paths.OrderBy(p => p, StringComparer.OrdinalIgnoreCase);

    private static bool IsHidden(string name) => name.StartsWith(".", StringComparison.Ordinal);
}
