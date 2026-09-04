# Auto-update design

Status: design, not yet implemented.
Scope: PageCore instances deployed to a Unix host from the public repository
`https://github.com/taskscape/Pagecore`.

An instance learns that a newer build of `main` exists, tells the operator in one
unobtrusive line inside the admin panel, explains the difference on a dedicated
page, and applies the change either when the operator presses **Update** or when
a keyed cron endpoint is called.

## 1. Decisions this design is built on

| Decision | Choice |
| --- | --- |
| Payload | CI builds a release archive per `main` commit and attaches it, with a per-file `manifest.json`, to a GitHub Release |
| Availability feed | `release/latest.json`, served from `raw.githubusercontent.com` |
| Cron behaviour | Applies the update unattended |
| Self-write | `update_apply` opt-in; when off, PageCore still reports and explains, but never writes to `cms/` |

`update_apply` is the master switch. With it off, every path is read-only: cron
refreshes the cache, the admin shows the notice, and the update page offers manual
instructions instead of a button. With it on, both cron (`update_auto_apply`,
default `true`) and the admin button may swap the tree.

## 2. Version identity

The identity of an installed instance is three values, not one:

- `version` — the textual PageCore version, `PAGECORE_VERSION` (`2.49.0` today)
- `commit` — the 40-hex SHA of the `main` commit the build came from
- `commit_time` — that commit's committer date, UTC, RFC 3339

They are stamped into `cms/build.json` when the archive is built:

```json
{
  "schema": 1,
  "version": "2.49.0",
  "commit": "232f2c4e9b1d0a77c3f5e2148b6a09d4c7e5f310",
  "commit_time": "2026-09-04T06:22:11Z",
  "built_at": "2026-09-04T06:31:02Z",
  "channel": "main"
}
```

`cms_build_identity()` reads it once per request and caches it in `$GLOBALS`.
Displayed as `Pagecore 2.49.0 (232f2c4 · 2026-09-04)`.

Fallbacks, in order:

1. `cms/build.json` present and well-formed — the normal case.
2. Absent, and a `.git` directory exists at or above the site root — a working
   checkout. Updates are disabled entirely; the admin shows
   `Pagecore 2.49.0 (source checkout)`. A developer's tree must never self-update.
3. Absent otherwise — a hand-copied install. `version` falls back to
   `PAGECORE_VERSION` and `commit` is `null`. The notice and update page still
   work, but `commit_time` monotonicity cannot be checked, so unattended apply is
   refused; the operator must press Update once, which writes a real stamp.

### 2.1 Asset cache busting must move to the build id

`cms_asset_url()` currently appends `?v=` + `PAGECORE_VERSION`, and `cms/.htaccess`
serves CSS/JS as `public, max-age=31536000, immutable`. Under release-only updates
that is safe. Under commit-level updates it is not: two commits on `main` can
change `cms/assets/admin-client.js` without touching `PAGECORE_VERSION`, and every
browser that already cached the old file keeps it for a year.

`cms_asset_url()` must key on the build id instead:

```php
function cms_build_id() {
    $identity = cms_build_identity();
    return $identity['commit'] === null
        ? cms_version()
        : cms_version() . '-' . substr($identity['commit'], 0, 12);
}

function cms_asset_url($filename) {
    return cms_admin_url('assets/' . ltrim((string) $filename, '/')) . '?v=' . rawurlencode(cms_build_id());
}
```

This is a prerequisite, not an optional extra. Shipping commit-level updates
without it delivers stale admin JavaScript against new PHP.

## 3. Publication (CI)

A new workflow, `.github/workflows/release-publish.yml`, runs on push to `main`.

1. Read `PAGECORE_VERSION` from `cms/engine.php`, `git rev-parse HEAD`, and
   `git log -1 --format=%cI`.
2. Write `cms/build.json` into the staging tree.
3. Run the existing packaging logic from `scripts/Build-PagecoreRelease.ps1`
   (pwsh runs on `ubuntu-latest`) to produce
   `pagecore-<version>-<short-sha>.zip`, containing `cms/`, `content/`,
   `uploads/`, `VERSION`, and `manifest.json` with a SHA-256 per file.
4. Create a GitHub Release tagged `build-<short-sha>` and attach the archive and
   its `.sha256`.
5. Commit `release/latest.json` back to `main`.

The archive is a stable artifact: its bytes never change once uploaded. GitHub's
auto-generated source tarballs are not stable — they can be recompressed — which
is why the feed points at a release asset and not at `codeload`.

### 3.1 `release/latest.json`

```json
{
  "schema": 1,
  "channel": "main",
  "version": "2.50.0",
  "commit": "9f1c2ab7e4d6035a81cc02fb7791a3de5b40c8e1",
  "commit_time": "2026-09-06T11:02:44Z",
  "published_at": "2026-09-06T11:07:19Z",
  "archive_url": "https://github.com/taskscape/Pagecore/releases/download/build-9f1c2ab/pagecore-2.50.0-9f1c2ab.zip",
  "archive_sha256": "6c1f…",
  "archive_bytes": 412873,
  "min_php": "8.3.0",
  "notes_url": "https://github.com/taskscape/Pagecore/compare/232f2c4...9f1c2ab"
}
```

Fetched from
`https://raw.githubusercontent.com/taskscape/Pagecore/main/release/latest.json`.
That endpoint is CDN-served with no 60-request/hour API budget, which matters
because a shared host puts many PageCore instances behind one outbound IP.

### 3.2 Loop guard

The workflow commits back to the branch that triggers it. Two guards, both
required:

- the publish workflow declares `paths-ignore: ['release/latest.json']`
- the bot commit message carries `[skip ci]`

The bot commit changes no file under `cms/`, so the build it advertises stays the
build the operator receives; the next real commit supersedes it.

## 4. Checking (read-only path)

### 4.1 Where the check runs

Page rendering never performs network I/O. `cms_admin_update_notice()` reads the
cached state file and nothing else. The cache is refreshed by, in order of
preference:

1. the cron endpoint (the intended production arrangement — always fresh, zero
   cost on page load);
2. an asynchronous `GET api.php?action=update-status&refresh=1` issued once by
   `admin-client.js` after load, only when the cache is older than
   `update_check_ttl_seconds` and `update_check_on_admin` is enabled. Same-origin,
   so `connect-src 'self'` in the CSP is untouched — the browser never talks to
   GitHub.

There is deliberately no third option. A synchronous check inside a page render
would put GitHub's availability on the critical path of the admin panel.

### 4.2 Cached state

`<private>/state/update-status.json`, written atomically (temp file + `rename`)
under the same lock as the installer:

```json
{
  "schema": 1,
  "checked_at": 1757150400,
  "http_status": 200,
  "etag": "W/\"a1b2c3\"",
  "decision": "available",
  "latest": { "...": "verbatim latest.json" },
  "error": null,
  "last_apply": {
    "at": 1757150922,
    "from": {"version": "2.49.0", "commit": "232f2c4"},
    "to": {"version": "2.50.0", "commit": "9f1c2ab"},
    "result": "success",
    "message": ""
  }
}
```

The stored `etag` is replayed as `If-None-Match`. A `304` costs nothing against
GitHub's budget and leaves the cached body in place.

### 4.3 Eligibility rules

`PagecoreUpdatePolicy::decide(array $installed, array $latest, array $env)` is a
pure function returning one of `up_to_date`, `available`, `blocked_php`,
`blocked_downgrade`, `blocked_channel`, `blocked_unknown_build`, `malformed`.

- `channel` must equal the configured channel.
- `commit` must be 40 lowercase hex and differ from the installed commit.
- `commit_time` must be **strictly greater** than the installed `commit_time`.
  This is the anti-rollback rule: a stale or replayed feed can never talk an
  instance into installing an older tip.
- `version_compare(latest.version, installed.version) >= 0` unless
  `update_allow_downgrade`.
- `version_compare(PHP_VERSION, latest.min_php) >= 0`.
- `archive_bytes` within `[10240, update_max_archive_bytes]`.
- `archive_url` scheme `https` and host in `update_allowed_hosts`.

Being pure and side-effect free, this is the piece with an exhaustive table test
and no network in sight.

## 5. Transport

`PagecoreUpdateTransport` performs every outbound request. This is the first
egress path in the codebase, so the rules are explicit:

- HTTPS only; `CURLOPT_PROTOCOLS_STR = 'https'`.
- `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`. No option
  disables these; there is no `update_insecure` escape hatch.
- `CURLOPT_FOLLOWLOCATION = false`. Redirects are followed manually, at most 3
  hops, re-validating scheme and host allowlist at **every** hop. GitHub redirects
  release downloads to `objects.githubusercontent.com`, so that host is
  allowlisted; anything else terminates the fetch.
- Connect timeout 5 s; total timeout `update_http_timeout_seconds` (20) for the
  feed and `update_download_timeout_seconds` (120) for the archive.
- Response written to a temp stream through a hard byte counter that aborts past
  the cap — `CURLOPT_MAXFILESIZE` alone is advisory when no length is sent.
- No cookies, no credentials, no ambient proxy inheritance. A proxy is used only
  when `update_proxy` is configured.
- User agent `Pagecore/<version> (+https://github.com/taskscape/Pagecore)`.

Fallback when `curl` is absent: `stream_context_create` with `verify_peer`,
`verify_peer_name`, `follow_location = 0`. When both are unavailable
(`allow_url_fopen=0` and no curl), the transport fails closed with
`transport_unavailable`, surfaced verbatim on the update page. It does not
silently degrade.

## 6. Applying

`PagecoreUpdateInstaller::apply()` runs as a state machine inside an exclusive
lock (`<private>/state/update.lock`, `flock(LOCK_EX|LOCK_NB)`). A second caller
gets `busy`, never a second concurrent swap.

| # | Stage | What it does | Failure |
| --- | --- | --- | --- |
| 1 | `preflight` | `update_apply` on; not a checkout; site root and `cms/` writable; `disk_free_space` at least 3x `archive_bytes`; PHP and extensions adequate | abort, nothing touched |
| 2 | `download` | fetch archive to `<private>/updates/work-<id>/archive.zip`; compare SHA-256 to `archive_sha256` | abort, discard work dir |
| 3 | `extract` | `ZipArchive`, else `PharData` for `.tar.gz` | abort, discard work dir |
| 4 | `verify` | every `manifest.json` entry present and hash-matched; no extra files; manifest `version`/`commit` equal the feed's; new tree contains `cms/engine.php` and a `cms/build.json` naming the expected commit | abort, discard work dir |
| 5 | `snapshot` | copy live `cms/` to `<private>/updates/rollback-<version>-<ts>/`; prune to `update_keep` | abort |
| 6 | `stage` | move the extracted `cms` to `<siteroot>/cms.new-<id>` — same filesystem, so step 8 is a rename | abort, remove staged dir |
| 7 | `preserve` | copy `update_preserve` files (default `config.php`) from live into staged | abort, remove staged dir |
| 8 | `commit` | set maintenance flag; `rename(cms -> cms.old-<id>)`; `rename(cms.new-<id> -> cms)`; clear flag | rename back immediately |
| 9 | `postcheck` | structural check of the new tree; `opcache_reset()` where the SAPI shares one | roll back by swapping `cms.old-<id>` in |
| 10 | `finalize` | write `last_apply`, refresh the stamp, audit, delete `cms.old-<id>` | logged, not fatal |

### 6.1 The running-code hazard

The script executing this lives inside `cms/`. After step 8 the running file's
inode survives the rename, but **any `require` of a `cms/…` path after step 8
loads the new tree** — potentially a half-swapped mix.

The installer therefore warms every class and function it needs for steps 8–10
before step 8, and emits its response from already-loaded code. A test asserts
this: it runs `apply()` against a fixture with a poisoned post-swap tree and
requires the call to complete normally.

`ignore_user_abort(true)` is set so a `curl` client timeout in cron cannot kill
the process mid-swap, and `set_time_limit(0)` is attempted (frequently disabled
on shared hosting, hence keeping the archive small — `cms/` is a few hundred KB).

### 6.2 What is never touched

Content, uploads, backups, the private config, and site templates. The update
replaces `cms/` only. `content/` and `uploads/` inside the archive are install
seeds; the updater ignores them. A production layout keeps all of it outside the
document root anyway, but the flat sample layout must survive an update too,
which is what `update_preserve` covers.

### 6.3 Maintenance window

`<private>/state/maintenance.json` (`{until, reason, id}`) is set around steps 8–9
only. While set, `engine.php` returns `503` with `Retry-After: 15` for admin
pages and API mutations. **Public pages keep serving** — an update must not take
the site down, and it does not need to: content is untouched and served by code
that is already loaded. The flag auto-expires after 120 s so a crashed update
cannot wedge the admin panel.

### 6.4 Honest limits of `postcheck`

An in-process check cannot prove the new tree boots. What step 9 actually does is
structural: every manifest file present and hash-matched, and `engine.php`
tokenizes cleanly via `token_get_all` (which catches truncation and partial
writes). Real boot verification requires a second process; where a `php` binary is
discoverable the installer runs `php -l` across the new tree and, failing that,
accepts the structural check. Rollback is one rename either way, and the retained
snapshot makes it recoverable by hand.

## 7. Admin surface

### 7.1 The notice

`cms_admin_update_notice()` in `cms/admin-view.php`. Renders nothing when up to
date. When an update is available, one line of text — no banner, no colour alarm:

```html
<p class="pc-update-notice" role="status">
  Update available: 2.50.0 · <a href="/cms/update.php">Details</a>
</p>
```

Placed in the sidebar foot beside the existing `Version 2.49.0`, and under the
`Pagecore 2.49.0` line in `content.php`'s page head.

### 7.2 `cms/update.php`

Session-gated. Shows:

- installed identity and available identity, both as version + short SHA + date
- the commit range, linked to the GitHub compare view from `notes_url`
- preflight results as plain pass/fail rows: `update_apply`, writability, disk,
  PHP version, archive reachability, `ZipArchive`/`PharData`
- what changes — file counts added, modified, removed, computed locally by
  diffing the installed `manifest.json` against the fetched one
- what is preserved — content, uploads, backups, config, stated explicitly
- rollback snapshots on disk with their versions and dates
- the **Update** button, or, when `update_apply` is off, the exact manual
  commands to do the same thing by hand

The button POSTs `api.php?action=update-apply` (session + CSRF), and the page
polls `action=update-status` for the outcome.

### 7.3 Wiring

- `pagecore_api_registry()` gains `update-status` (GET, `session`) and
  `update-apply` (POST, `session+csrf`).
- `pagecore_request_is_denied()` gains `/cms/update.php` and
  `/cms/update-cron.php` to the `$publicEndpoints` allowlist — everything under
  `/cms` is denied unless listed.
- `cms/.htaccess` adds `update-cli` to the denied `FilesMatch` group (CLI only).

## 8. The cron endpoint

### 8.1 `cms/update-cron.php`

No session, no CSRF, no HTML. Authorised by a key that exists only in the private
`config.php`.

- Accepts `GET` and `POST`. The key arrives in the `X-Pagecore-Update-Key` header
  (preferred) **or** the `key` query parameter. The query form is supported
  because the host's own documentation uses exactly that shape
  (`…/import.php?file1=…&securekey=…`), and it is documented with its cost: query
  strings land in server access logs and in the panel's history.
- `update_cron_key` must be at least 32 characters and must not be the example
  placeholder. Empty or too short means the endpoint responds `404` and logs a
  configuration error — it fails closed, so an install that never sets a key has
  no reachable endpoint.
- Comparison is `hash_equals(hash('sha256', $expected), hash('sha256', $given))`,
  so neither content nor length leaks through timing.
- A wrong key returns `404` with an empty body — indistinguishable from an
  instance that has the feature off — and records an audit event with the keyed
  source hash the audit log already uses.
- `update_cron_min_interval_seconds` (default 300) throttles **before** any
  network egress, valid key or not. A leaked key cannot be turned into a request
  amplifier.
- HTTPS required when `require_https`. `Cache-Control: no-store` and
  `X-Robots-Tag: noindex, nofollow`.

Response is one line of JSON:

```json
{"status":"applied","from":"2.49.0+232f2c4","to":"2.50.0+9f1c2ab","duration_ms":8412}
```

`status` is one of `applied`, `up_to_date`, `available` (found but
`update_auto_apply` off), `throttled`, `busy`, `failed`, `disabled`.

### 8.2 `cms/update-cli.php`

Guarded by `PHP_SAPI === 'cli'` and denied by `.htaccess` and the request guard.
Needs no key: filesystem access is the authorisation. Exit codes `0` success or
no-op, `1` failed, `2` misconfigured — so cron mail and panel monitoring report
something useful.

Prefer this form. It keeps the key off the wire entirely and gives the web worker
no reason to hold write access to code.

### 8.3 Cron entries for this host

The panel runs on UTC and supports both forms. Pick a random minute per instance
so a hundred sites do not hit the CDN on the same second.

PHP CLI, preferred:

```
37 3 * * * /usr/local/bin/php /home/USER/public_html/cms/update-cli.php >/dev/null 2>&1
```

HTTP with the key in a header:

```
37 3 * * * /usr/bin/curl -fsS -m 300 -H "X-Pagecore-Update-Key: KEY" "https://example.com/cms/update-cron.php" >/dev/null 2>&1
```

HTTP in the shape the host's article uses:

```
37 3 * * * /usr/bin/curl -fsS -m 300 "https://example.com/cms/update-cron.php?key=KEY" >/dev/null 2>&1
```

Generate a key with:

```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

## 9. Configuration

New keys, all optional with safe defaults, validated in `cms_validate_config()`:

```php
'update_channel' => 'main',              // 'main' | 'off'
'update_manifest_url' => 'https://raw.githubusercontent.com/taskscape/Pagecore/main/release/latest.json',
'update_apply' => false,                 // may this instance rewrite its own code?
'update_auto_apply' => true,             // cron applies, once update_apply is on
'update_check_on_admin' => true,         // async refresh from the admin panel
'update_cron_key' => '',                 // '' disables the endpoint
'update_cron_min_interval_seconds' => 300,
'update_check_ttl_seconds' => 21600,
'update_state_dir' => $privateRoot . '/state',
'update_work_dir' => $privateRoot . '/updates',
'update_keep' => 3,
'update_http_timeout_seconds' => 20,
'update_download_timeout_seconds' => 120,
'update_max_archive_bytes' => 26214400,
'update_allow_downgrade' => false,
'update_preserve' => array('config.php'),
'update_allowed_hosts' => array('raw.githubusercontent.com', 'github.com', 'objects.githubusercontent.com'),
```

Validation: `update_channel` enum; `update_manifest_url` HTTPS with its host in
`update_allowed_hosts`; `update_cron_key` empty or at least 32 characters and not
the placeholder; positive integers; booleans strictly boolean; and when
`update_apply` is true, `update_state_dir` and `update_work_dir` must be outside
the document root, reusing the existing `cms_private_storage_violations()` check.

## 10. Threat model

| # | Threat | Mitigation | Residual |
| --- | --- | --- | --- |
| T1 | Hostile payload via repo or CI compromise | Per-file SHA-256 manifest, TLS, host allowlist | **Accepted.** Whoever controls CI controls the update. Ed25519 signing is the fix; §12 says where it plugs in |
| T2 | Downgrade / replay to a vulnerable build | Strict `commit_time` monotonicity plus a version floor | none material |
| T3 | MITM or DNS hijack | `verify_peer` + `verify_host`, HTTPS only, no insecure option | CA trust |
| T4 | Cron key leakage | Header form keeps it off logs; throttle; audit; rotation is a config edit. The key grants "install the official latest build", not arbitrary code | query form is logged where used |
| T5 | SSRF via a crafted feed | `archive_url` host allowlist, HTTPS only, manual redirect handling with per-hop revalidation | none material |
| T6 | Zip bomb or path traversal in the archive | Entry count cap, uncompressed byte cap, compression ratio cap, path normalisation, symlink rejection, everything outside `cms/` refused | none material |
| T7 | Interrupted write, power loss | Staging outside the target, one rename to commit, retained snapshot, auto-expiring maintenance flag | a crash between the two renames leaves `cms.old-<id>`; documented recovery |
| T8 | The self-write surface itself | `update_apply` opt-in and off by default; CLI-only cron path available | **Real.** With `update_apply` on, any PHP RCE gains easy persistence. High-value sites should leave it off and use the CLI path |
| T9 | Many instances behind one IP | CDN feed instead of the API, TTL, ETag/304, jittered cron minute | none material |

## 11. Tests

Matching the repo's convention of a pure module plus a `tests/*.php` file plus a
`scripts/Test-*.ps1` runner:

| File | Covers |
| --- | --- |
| `tests/update-policy.php` | the full decision table — up to date, available, downgrade, stale `commit_time`, wrong channel, PHP floor, malformed feed |
| `tests/update-manifest.php` | manifest verification: tampered hash, missing file, extra file, traversal path, symlink entry, ratio bomb |
| `tests/update-transport.php` | URL and host allowlists, redirect hop limits, per-hop revalidation, size caps — against an injected fetcher, no real egress |
| `tests/update-cron-auth.php` | key comparison, disabled-when-empty, `404` semantics, throttle before egress |
| `tests/update-installer.php` | apply and rollback against a temp fixture: config preservation, rollback on postcheck failure, lock exclusivity, no post-swap `require` |
| `tests/sample-site.spec.js` | notice appears when the cache says available; update page renders; button absent when `update_apply` is off |

`scripts/Test-VersionSync.ps1` extends to assert that `release/latest.json` and
generated `cms/build.json` agree with `PAGECORE_VERSION`.

No test in this suite performs a real network request.

## 12. Phasing

| Phase | Ships | Risk |
| --- | --- | --- |
| P1 | Build identity, `build_id` asset busting, feed check, cached state, admin notice, update page — **no apply** | none: read-only |
| P2 | `update_apply`, installer, admin Update button, rollback snapshots | first self-write |
| P3 | `update-cron.php`, `update-cli.php`, auto-apply, host cron docs | unattended change |
| P4 | Rollback from the admin UI; Ed25519 signature over `manifest.json`, public key shipped in `cms/`, verified between installer steps 3 and 4 | closes T1 |

P1 is worth shipping alone: it answers "is this instance current?" across an
estate with no write risk at all.

## 13. Open items

- Retention of `<private>/updates/` on hosts with tight disk quotas —
  `update_keep = 3` snapshots of `cms/` is a few MB, but a quota-limited plan may
  want 1.
- Whether `update-cron.php` should also accept a `dry_run=1` parameter for
  operators validating a new cron entry. Cheap to add; useful during rollout.
- Key rotation ergonomics if the estate grows past a handful of instances — a
  per-instance key edited by hand does not scale to hundreds.
