# Pagecore configuration

Pagecore loads one PHP array from `PAGECORE_CONFIG` (or `cms/config.php`) and validates the complete array before it starts a session, creates a directory, or writes content. Copy `cms/config.example.php` to a path outside the web document root and adapt it; do not edit the example in place.

## Reader statistics

Set `'statistics_enabled' => true` to collect engaged reads from templates that call the tracker. The default is `false`. Add the call once per public content document, after the visible content and before `</body>`.

```php
<?= cms_statistics_tracker('page', 'about', 'About') ?>
<?= cms_statistics_tracker('post', $post['slug'], $post['title'], 'article .prose') ?>
```

Use the first example for a page and the second for a post detail template. Use a stable key for each page. For a page with Markdown regions such as `content/pages/about/intro.md`, use `about` as the key for the whole page. The optional selector defaults to `main`. Choose the element that contains the content visitors read. Use the call only on public pages and after the site's analytics preference check, if it has one. Serve tracked HTML per browser session so cached pages cannot share reading tickets across visitors.

Open **Statistics** in the admin sidebar. Reports show distinct browsers across the selected local calendar day, Monday-start week, month, or year. A reader is **new** if their first engaged read anywhere on the site falls in that period. A **returning** reader first qualified earlier and qualified again during the period.

An engaged read requires at least 15 seconds with the content visible and, on longer content, reaching its midpoint. Short visits, background tabs, known bots, common browser automation, and signed-in admins are filtered. The estimates depend on JavaScript, cookies, and observable browsing behavior. Clearing the first-party reader cookie, switching browsers, or rotating the site's admin password hash resets recognition. Collection starts when enabled.

Each post stores its reads in `content/posts/<slug>.readers.jsonl` beside its Markdown. Each page stores its reads in `content/pages/<key>/.readers.jsonl` beside its Markdown regions. Each line records the local read date, hashed reader ID, first-read date, and title. Reports cover the latest 730 days. An active sidecar drops older entries and merges repeated readers per day when its next calendar month starts receiving reads. Inactive sidecars remain on disk until another qualifying read or operator cleanup. Keep these files in Pagecore's private content directory and include them in backups if you need the reporting history. Preserve the sidecar alongside the matching Markdown when moving or archiving content.

## Other settings

Production startup requires HTTPS, secure cookies, HSTS, an HTTPS `site_url`, a supported password hash, and `development_only`/`demo_credentials` set to `false`. The configuration file, content, backups, uploads, rate-limit state, and audit log must remain outside the document root. `content_dir`, `backup_dir`, and `uploads_dir` must be distinct absolute paths.

Identifiers and routes are checked at startup: the session name uses letters, digits, `_` or `-`; category slugs use lowercase letters, digits or `-`; every category has a label and root-relative or HTTP(S) URL; and `post_url` contains `{slug}` exactly once. Upload extensions are restricted to the passive formats supported by Pagecore.

All capacity, session, rate-limit, audit, pagination, inventory, search, image, and upload limits are required positive integers. Startup fails closed with a generic operator message, while the server error log lists the invalid keys. Set `PAGECORE_DISPLAY_ERRORS=1` (Apache `SetEnv`, or the equivalent PHP-FPM `env[]` entry) to put those keys on the page without skipping production validation; `PAGECORE_DEVELOPMENT=1` implies it. Remove the diagnostic flag once the fault is found — the output carries absolute paths, stack traces, and configuration key names. Set `PAGECORE_DEVELOPMENT=1` only for a loopback development server using an explicitly `development_only` profile.
