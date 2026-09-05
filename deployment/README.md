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

## Diagnosing a blank 500

By default the engine writes failures to the PHP error log and returns nothing
to the browser. To read them on the page instead, set:

```apache
SetEnv PAGECORE_DISPLAY_ERRORS 1
```

The engine reads that flag from `getenv()` and from `$_SERVER`, so the same
`SetEnv` line works under mod_php, CGI, and PHP-FPM. It turns on
`display_errors` before the configuration is parsed, so even a boot failure is
visible, and it makes the configuration validator name the settings it
rejected instead of pointing at the log. `PAGECORE_DEVELOPMENT=1` implies it.
**Remove it once the fault is found** — the output carries absolute paths,
stack traces, and configuration key names.

The most common cause of a blank 500 on a fresh deployment is the validator
refusing a development configuration: without `PAGECORE_DEVELOPMENT=1` the
engine demands `require_https`, `cookie_secure` and `hsts` set to `true`, an
HTTPS `site_url`, and `development_only`/`demo_credentials` unset or `false`.

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

## Updates

Pagecore compares this installation against the build published from `main` and
shows one line in the admin panel when a newer one exists. The full contract is
in `docs/auto-update.md`; the operational summary is:

- Checking is read-only and needs no write access. Page rendering never
  contacts the network — the notice is served from cached state that either a
  cron job or one asynchronous same-origin request refreshes.
- `update_apply` is `false` by default. While it is off, Pagecore reports and
  explains but never writes to `cms/`, and the update page prints the manual
  commands instead of a button. Turn it on only when the PHP worker may replace
  its own code, and prefer the CLI cron entry below if that trade is unwelcome.
- An update replaces `cms/` only. Content, uploads, backups, configuration, and
  site templates are never touched, and the previous engine is retained as a
  rollback snapshot under `update_work_dir`.
- Public pages keep serving throughout. The admin panel answers `503` with
  `Retry-After` for the few seconds the directory swap takes.

Schedule a check with either cron form. Servers run UTC, so pick a random
minute per instance rather than the same one everywhere:

```
37 3 * * * /usr/local/bin/php /home/USER/public_html/cms/update-cli.php >/dev/null 2>&1
```

```
37 3 * * * /usr/bin/curl -fsS -m 300 -H "X-Pagecore-Update-Key: KEY" "https://example.com/cms/update-cron.php" >/dev/null 2>&1
```

The CLI form is preferred: it needs no key, so nothing sensitive travels over
the wire. The HTTP endpoint requires `update_cron_key`, at least 32 characters,
generated with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Leave the
key empty and the endpoint answers `404`, exactly as an installation without
the feature would. A key passed as `?key=` also works, for panels that only
accept a URL, but it is recorded in the server access log — prefer the header.

Add `?dry_run=1` to check without applying while validating a new cron entry.

Updates are the only outbound connections Pagecore makes. They are HTTPS-only
with mandatory certificate verification and no option to relax it. On a host
whose PHP ships without a CA bundle the check fails closed; point
`update_ca_bundle` at one (commonly `/etc/ssl/certs/ca-certificates.crt`).

Security audit events are appended as JSON lines to `audit_log_path`. They
contain event/outcome names, UTC timestamps, correlation IDs, and keyed hashes
of the account and request source—never credentials, session/CSRF tokens,
Markdown, filenames, or absolute paths. Ship the file to restricted log
storage, retain it according to local incident-response policy, and alert on
repeated `auth.login`, `auth.csrf`, and rejected mutation events. The local
file rotates at `audit_max_bytes`; external collection is the authoritative
retention mechanism.
