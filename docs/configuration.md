# Pagecore configuration

Pagecore loads one PHP array from `PAGECORE_CONFIG` (or `cms/config.php`) and validates the complete array before it starts a session, creates a directory, or writes content. Copy `cms/config.example.php` to a path outside the web document root and adapt it; do not edit the example in place.

Production startup requires HTTPS, secure cookies, HSTS, an HTTPS `site_url`, a supported password hash, and `development_only`/`demo_credentials` set to `false`. The configuration file, content, backups, uploads, rate-limit state, and audit log must remain outside the document root. `content_dir`, `backup_dir`, and `uploads_dir` must be distinct absolute paths.

Identifiers and routes are checked at startup: the session name uses letters, digits, `_` or `-`; category slugs use lowercase letters, digits or `-`; every category has a label and root-relative or HTTP(S) URL; and `post_url` contains `{slug}` exactly once. Upload extensions are restricted to the passive formats supported by Pagecore.

All capacity, session, rate-limit, audit, pagination, inventory, search, image, and upload limits are required positive integers. Startup fails closed with a generic operator message, while the server error log lists the invalid keys. Set `PAGECORE_DEVELOPMENT=1` only for a loopback development server using an explicitly `development_only` profile.
