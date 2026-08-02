# Production storage layout

Pagecore's production guard requires the configuration, content, backups, and
uploads to be outside the HTTP document root. A typical layout is:

```text
site/
├── public/                 # web server DOCUMENT_ROOT
│   ├── cms/
│   └── index.php
└── pagecore-private/
    ├── config.php          # copied from pagecore-config.php.example
    ├── content/
    ├── backups/
    └── uploads/
```

Set `PAGECORE_CONFIG` to the absolute private `config.php` path in the PHP
process environment. On a control-panel host this is normally one `SetEnv`
line in the public `.htaccess`; the engine reads the variable from both
`getenv()` and `$_SERVER`, so the same line works under mod_php, CGI and
PHP-FPM. `PAGECORE_DOCUMENT_ROOT` is only required for CLI tooling such as
`scripts/reindex.php`, where the server provides no `DOCUMENT_ROOT` for the
configuration to read.

Verify a built deployment before uploading it:

```powershell
scripts\Test-ProductionLayout.ps1 -PublicRoot .\deploy\public_html -ConfigFile .\deploy\pagecore-private\config.php
```

That boots the engine with no `PAGECORE_DEVELOPMENT`, exactly as a host would,
and reports private-storage violations, a development or demo posture, a
non-HTTPS `site_url`, an `cms/config.php` left in the public root, and
unwritable data directories. None of these are reachable through the bundled
development server, which ignores `.htaccess` and always runs with the
development opt-in set.

Grant the PHP worker write access only to the three private data
directories and to the generated public `search-index.json` and `sitemap.xml`.
The private state directory also stores the shared account/source login attempt
budget; all PHP workers for an installation must see the same filesystem path.

Production defaults require HTTPS, mark the session cookie `Secure`, and emit
HSTS on secure responses. When TLS terminates at a reverse proxy, list only its
exact addresses or CIDRs in `trusted_proxies`; forwarding headers from every
other source are ignored. Enable `hsts_include_subdomains` only when every
subdomain is permanently HTTPS-capable.

The browser never reads Markdown or upload files directly. `/cms/media-file.php`
validates the requested relative media path and supplies a fixed MIME policy;
PDFs are attachments and raster images may render inline. Content migrated from
another CMS usually carries literal `/uploads/...` URLs; map them onto that
endpoint in the fronting server rather than rewriting the content, so the
files stay outside the document root:

```apache
RewriteRule ^uploads/(.+)$ cms/media-file.php?path=$1 [L,QSA]
```

Do not set
`PAGECORE_DEVELOPMENT=1` in production. That opt-in exists only for the bundled
sample and private local migration fixture, whose routers enforce explicit
HTTP denials.

Keep the fronting server's body limit aligned with `max_request_bytes`. The
bundled Apache `.htaccess` uses `LimitRequestBody 8912896`; an equivalent Nginx
deployment should set `client_max_body_size 8704k`. Pagecore repeats the limit
in the API and independently caps content, navigation, metadata, image
dimensions, aggregate storage, upload frequency, and inventory/page sizes.

Pagecore supports PHP 8.3, 8.4, and 8.5. The minimum is reviewed at least
twice yearly against PHP's official support calendar and must be raised before
that branch reaches end of security support. The runtime guard fails startup
on older branches, while CI runs lint, policy, and browser lanes on every
declared supported branch.

Security audit events are appended as JSON lines to `audit_log_path`. They
contain event/outcome names, UTC timestamps, correlation IDs, and keyed hashes
of the account and request source—never credentials, session/CSRF tokens,
Markdown, filenames, or absolute paths. Ship the file to restricted log
storage, retain it according to local incident-response policy, and alert on
repeated `auth.login`, `auth.csrf`, and rejected mutation events. The local
file rotates at `audit_max_bytes`; external collection is the authoritative
retention mechanism.
