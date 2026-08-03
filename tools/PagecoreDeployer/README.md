# Pagecore Deployer

A small Windows app that publishes terapiawypalenia.pl over an encrypted
connection and resets the CMS admin password — the things that used to need the
file manager, the build script and a hand-edited config.

Every transfer is encrypted. FTP is always explicit TLS and required; there is no
option, and no fallback, that would send anything in the clear.

## Running it

Double-click:

    tools\PagecoreDeployer\bin\Release\net8.0-windows\win-x64\publish\PagecoreDeployer.exe

It is self-contained — no .NET install required. Pin it to the taskbar.

## Fields

| Field | terapiawypalenia.pl |
|---|---|
| Host | `ftps://mojerzeczy.com` |
| User | `admin@mojerzeczy.com` |
| Password | the FTP password for that account |
| Local engine | `C:\Projects\Pagecore\cms` |
| Local website | `C:\Projects\Pagecore\terapiawypalenia` |
| Remote website | `/terapiawypalenia.pl/public_html` |

Use the same host name your FTP client uses. `mojerzeczy.com` and
`d21.thecamels.org` are the same machine, but the login is only accepted on one
of them, and the certificate is issued for the other — so one name authenticates
with a certificate prompt to approve once, and the other verifies silently but
rejects the login. The working combination is recorded in the table above.

Neither folder has to be picked exactly. Both are recognised before anything
connects, a near miss is corrected, and anything else is refused with a message
naming the folder and what was missing.

**Local engine** is the `cms` folder, not the repository: `…\Pagecore\cms` to
ship the engine as it stands in the repository, or a site's own copy at
`…\terapiawypalenia\public_html\cms`. A repository root, a site root or a
document root is accepted and resolved to the `cms` inside it.

**Local website** is the folder holding the site's two halves:

```text
terapiawypalenia/
├── public_html/         the document root
└── pagecore-private/    config, content, uploads, state
```

Pagecore requires the private storage beside the document root rather than below
it, so a folder without both is not a website and is rejected. Picking either
half resolves to the site folder. The remote private directory is derived the
same way — the sibling of **Remote website**.

**Remote website** is the path *as the login sees it*, which is not the server's
filesystem path. The FTP account is scoped to the hosting account, so its root
already holds the domain folders:

```text
/                             what the login lands in
└── terapiawypalenia.pl/
    ├── public_html/          ← Remote website
    ├── pagecore-private/     derived, its sibling
    ├── private_html -> ./public_html
    ├── awstats/
    └── logs/
```

so `/terapiawypalenia.pl/public_html` — with no `/home/...` or `/domains/...`
above it. Open the site in any FTP client and copy what the address bar shows.

This is the one field nothing local can check, and getting it wrong is silent.
Uploads run with `ftp-create-dirs`, which they must — `assets/`,
`uploads/2026/01/` and the rest are created as the upload goes — so a wrong root
gets created too, every file reports success into a tree no web server serves,
and the live site never changes. Before an upload the app therefore lists the
remote folder, and if it is not there it says so *and lists what the account can
actually see*, so the real path is one look away rather than another guess. The
warning can be overridden with **Yes** for the rare account that may write a
directory it cannot list.

The **User** must belong to the same hosting account as the site. A neighbouring
account's login will connect, authenticate and accept every byte — into its own
filesystem, where nothing serves it.

Everything is remembered between runs. The password is encrypted with Windows
DPAPI (tied to your Windows account) in
`%APPDATA%\PagecoreDeployer\settings.json`, never stored in plain text.

## Local API

The **Local API** switch in the status bar exposes the same settings and actions
over HTTP, for driving the deployer from a script. It is off until switched on,
and issues a fresh token each time it starts — shown in the log, saved nowhere.

```bash
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8787/api/config
```

| Endpoint | Does |
|---|---|
| `GET /api/config` | current settings; the password is never returned, only `passwordSet` |
| `PUT /api/config` | set any of `host`, `user`, `password`, `engineFolder`, `websiteFolder`, `remoteFolder` |
| `GET /api/commands` | what can be run, and which are destructive |
| `POST /api/commands/<name>` | run one — `test-connection`, `scan`, `upload-engine`, `upload-content`, `reset-password` |
| `GET /api/status` | state and log of the running or last command |

What it exposes is a stored credential and a button that overwrites a live
website, so it is fenced in accordingly: bound to `127.0.0.1`; every request must
carry the bearer token; requests arriving with a browser `Origin` header are
refused outright, so a page you happen to be visiting cannot reach it; and the
three destructive commands need `{"confirm": true}` in the body, standing in for
the dialog a person would otherwise see.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" -d '{"confirm":true}' \
     http://127.0.0.1:8787/api/commands/upload-content
```

Commands return `202` immediately; poll `GET /api/status` for progress and the
log.

## Importing a login

**Import…**, next to the Password field, copies the user name and password from a
saved Open Salamander FTP bookmark. The password goes from Salamander's store
into this app's DPAPI-protected `settings.json` without being displayed, logged
or written anywhere else — useful when a login already works in one program and
you would rather not retype it, or cannot because the field is masked.

Salamander stores a bookmark password *scrambled* when no master password is set
— a fixed substitution table, not encryption — which is what makes this possible
and is also why `wcx_ftp.ini` and the registry are not safe places for a
password. Where a master password **is** set the password is AES-encrypted under
it, and the import stops and says so rather than trying to get past it.

## Buttons

- **Test connection** — logs in over encrypted, verified FTP and lists the remote
  website folder and its private sibling. Uploads nothing, changes nothing. Use
  it to check a credential or a path; there is then never a reason to try one in
  Windows Explorer's FTP, which speaks only plaintext and would put the password
  on the wire in the clear.

- **Upload new engine** — uploads the engine to `<remote>/cms`. It sends only
  the engine's own files — the PHP in the engine root plus `assets`, `lib` and
  `modules`, which is the same set the release build takes from `cms` — and
  never `config.php`. Everything else in the folder is left alone and listed in
  the log, so pointing at a repository can no longer push `.git`,
  `node_modules`, test output or another site's content. The log names the
  folder and the `PAGECORE_VERSION` it recognised before the first transfer.
- **Upload new content** — publishes the site: `public_html` to the remote
  document root, and `pagecore-private` to its sibling on the host. Like the
  engine button it sends only what belongs to a Pagecore site — the templates,
  `assets`, `partials` and the generated `search-index.json` / `sitemap.xml` on
  the public side; `content` and `uploads` on the private side.

  Four things are deliberately left alone, and the log names each one:

  | Left alone | Why |
  |---|---|
  | `pagecore-private/config.php` | the live configuration. Only **Reset password** may touch it. |
  | `public_html/.htaccess` | carries the production `SetEnv` line the local file does not. Overwriting it 500s the whole site. |
  | `public_html/cms/` | the engine. That is the other button. |
  | `public_html/router.php`, `pagecore-private/state/`, dot-directories | the `php -S` router, the login-attempt budget and audit log, and the engine's own backups, drafts and caches. |

  Content and uploads *are* published, and editors change those through the CMS
  on the server — so a confirmation names the file counts and both destinations
  before the first byte moves.
- **Reset password** — downloads the config from
  `…/pagecore-private/config.php`, replaces the admin password, uploads it back,
  and prints the new password in the log. The previous config is backed up to
  `%APPDATA%\PagecoreDeployer\config-backups\` first, and the patched file is
  checked with PHP before upload, so a bad edit can never reach the server.
  Leave "New CMS password" blank to get a generated one.

The log pane shows every file and the result of each step.

## Transport

Transfers go through `curl.exe`, and which one matters. The curl that ships in
`C:\Windows\system32` is built against Schannel with no libssh2, so it has no
SFTP at all — and being first on PATH it is what a bare `curl.exe` resolves to,
which makes every SFTP transfer fail with *Protocol "sftp" is disabled*.

So the app picks by capability instead of by PATH order: it asks each curl it can
find what protocols it has and uses the first that supports the scheme in the
Host field, preferring the one Git for Windows installs
(`…\Git\mingw64\bin\curl.exe`, which does have SFTP). The chosen path is the
first line in the log:

```text
Transport: SFTP through C:\Program Files\Git\mingw64\bin\curl.exe
```

If nothing on the machine can speak the scheme, it says so before the first
transfer and lists what it found, rather than failing once per file. For SFTP the
fix is to install Git for Windows, or to use FTPS instead by putting `ftps://` in
front of the host.

### Encryption

Nothing is ever sent in the clear, and there is no setting that would allow it.
Write the Host as `ftps://` — explicit TLS over the normal FTP port, the same
thing a GUI client calls FTPS. Typing `ftp://` is accepted and means exactly the
same; the app rewrites it to `ftps://` when it saves, so the stored setting never
claims to be something weaker than it is.

Internally the URL handed to curl still reads `ftp://`, because curl takes a
literal `ftps://` to mean *implicit* FTPS on port 990, which the host does not
offer. The security comes from the option beside it, not the spelling.

The option behind it is curl's `ssl-reqd`, not `ssl`. The difference matters: 
`ssl` is opportunistic — it asks for AUTH TLS and carries on **in cleartext** if
the server declines, which would put the password and every file on the wire with
no error and nothing in the log. `ssl-reqd` fails the transfer instead.

The certificate is verified, and the first line of the log says how:

```text
Transport: FTP with required explicit TLS, certificate verified, through …\curl.exe
```

Windows completes the chain itself — this host sends only its leaf certificate,
and Schannel fetches the missing Let's Encrypt intermediate through the AIA
extension — so `d21.thecamels.org` verifies with nothing to approve.

A certificate the system *cannot* verify is neither refused nor waved through.
It is written to the log in full — names, issuer, validity, SHA-256 — with the
reason it failed, and put to you:

```text
Certificate presented by mojerzeczy.com:
    Issued to  d21.thecamels.org
    Names      d21.thecamels.org
    Issued by  YE2
    Valid      2026-06-20 to 2026-09-18
    SHA-256    6B0EC9FA…
  NOT verified: the name in the certificate is not the host being connected to
```

Approving records *that one certificate's fingerprint*, against that host, in
`settings.json`. Later connections check against it, so this pins one certificate
rather than switching verification off — and if the server ever presents a
different one, the approval stops matching and you are asked again, with the
change called out. The inspection is an `AUTH TLS` handshake and nothing else:
no user name, no password, no file, before you have decided.

SFTP has no certificate; its host key is not checked, which is the one thing here
left unverified — the session is still encrypted.

### When a transfer fails

Each file is its own curl run and its own SSH session, so across a hundred files
a single dropped connection is close to expected. A file that fails on something
that looks like the link — a reset, a timeout, a truncated transfer, a lost SSH
session — is tried up to three times, backing off 2 then 4 seconds, and each
retry is logged:

```text
  retrying uploads/2026/01/psycholog-w-branzy-it-3.jpg in 2s (attempt 1 of 3): …
```

Failures that say the *request* is wrong are not retried: access or login denied,
no such file, a malformed URL. Repeating those only delays the answer, and a
repeated login is how an account gets locked out.

Two things follow from that:

- If files still fail, the error names each one with its curl error rather than
  giving a bare count. Press the same button again — every file is re-sent, which
  is safe, since an upload replaces whatever the failure left behind.
- If the first three files fail with nothing uploaded, the run stops there. That
  pattern is the host, the credential or the remote path, and there is nothing to
  learn from spending three attempts on each of the remaining hundred.

## Rebuilding after a code change

    dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true

Uses the bundled PHP at `..\..\php\php.exe` for password hashing and `curl.exe`
for transport — no other dependencies.
