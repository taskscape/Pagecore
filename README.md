# Pagecore

A lightweight, database-free CMS engine for PHP websites. Content is stored as
plain Markdown files on disk, and editing happens **directly on the live site**:
a logged-in editor browses the pages exactly as visitors see them, clicks an
editable fragment, and edits it in place. Anonymous visitors are served plain
rendered HTML with zero editing overhead.

Pagecore is designed for small, single-editor sites (the bundled configuration
targets a Polish-language site) where a full CMS such as WordPress would be
overkill: no database, no admin dashboard, no build step — just PHP, Markdown
files, and a folder of uploads.

## Who it's for

| Perspective | What Pagecore gives you |
|---|---|
| **Site visitor** | A normal, fast PHP website. The CMS is invisible: no scripts, no styles, no cookies related to editing are delivered to anonymous users. |
| **Content editor** | In-place editing of any marked fragment of the site, plus simple management of dated posts (news, rulings, events…) — all from the browser, after logging in at a private URL. |
| **Developer / integrator** | A drop-in `cms/` directory and a handful of template functions (`cms_editable()`, `cms_posts()`, `cms_post()`, `cms_assets()`) that add editing to any existing PHP site without touching its markup structure. |

## How editing works (user perspective)

1. **Log in** at `/cms/login.php` (the URL is deliberately not linked anywhere
   on the site). A toolbar appears confirming you are logged in, with a logout
   link.
2. **Browse the site normally.** Every editable fragment is outlined; hovering
   reveals an **✎ Edytuj** (Edit) button. Empty fragments show a placeholder
   so they can still be found and filled in.
3. **Edit in a panel** that opens over the page:
   - Content is written in **Markdown**, including tables.
   - A server-side **preview** shows exactly how the fragment will render.
   - **Images and PDFs** can be pasted or dragged straight into the editor —
     they are uploaded automatically and the correct Markdown snippet is
     inserted. PDFs render on the page as an embedded viewer with a download
     fallback link.
   - `Ctrl+S` saves, `Esc` cancels (with a confirmation if there are unsaved
     changes).
4. **Manage posts** on listing pages (e.g. *Orzeczenia / Wydarzenia /
   Uchwały*): a **＋ Dodaj wpis** (Add post) button creates a new post in that
   category. Each post has a title, date, category and optional excerpt
   (editable as post metadata), plus a Markdown body edited the same way as
   any other fragment. Post URLs are generated automatically from the title
   (with Polish-character transliteration, e.g. *"Uchwała nr 5"* →
   `uchwala-nr-5`).

### Automatic housekeeping on every save

- **Backups** — before a fragment or post is overwritten, the previous version
  is copied to `content/.backups/` (the newest 20 versions are kept per
  fragment). Restoring means copying a backup file back over the live file.
- **Search index** — `search-index.json` is regenerated from configured pages
  and all posts, powering the site's search page.
- **Sitemap** — `sitemap.xml` is regenerated with all pages, posts and
  category listings.
- **Post excerpts** — if no excerpt is provided, one is derived automatically
  from the first ~28 words of the body.

## Functional overview

### Content model

All content lives under `content/` as Markdown files — the engine never
modifies PHP templates:

```
content/
├── pages/<page>/<region>.md    # editable page fragments
├── posts/<slug>.md             # dated posts with front matter
└── .backups/                   # automatic per-save version history
uploads/
└── YYYY/MM/<name>-<random>.ext # editor-uploaded images and PDFs
```

Posts carry simple front matter:

```markdown
---
title: Uchwała nr 5/2026
date: 2026-06-15
category: uchwaly
excerpt: Optional hand-written summary.
---
Body of the post in Markdown…
```

Because everything is plain files, the whole site can be backed up, versioned
in git, migrated, or edited over FTP/SSH with any text editor. Deleting a post
is simply deleting its `.md` file.

### Rendering

Markdown is rendered server-side with Parsedown, with a few site-friendly
extras:

- `pdf:/uploads/path/file.pdf "Label"` on its own line becomes an embedded PDF
  viewer with a labelled download link.
- Standalone images are wrapped in `<figure>` for styling.
- Tables get a `cms-table` class hook.
- Dates display in Polish long form (e.g. *15 czerwca 2026*).

### Integration API (developer perspective)

Add `require 'cms/engine.php';` to the site bootstrap, then:

| Function | Purpose |
|---|---|
| `cms_editable('page/region')` | Render a fragment; wraps it in an editable element only for logged-in editors. |
| `cms_posts('category')` | List posts (newest first) for a listing page — title, date, excerpt, URL. |
| `cms_post($slug)` | Fetch one post with rendered body for a post template. |
| `cms_listing_controls('category')` | "Add post" button on listing pages (editors only). |
| `cms_assets()` | Emit editor CSS/JS before `</body>` (empty for visitors). |

Site-specific settings (credentials, categories, searchable pages, site URL,
upload limits) live in `cms/config.php`, created per installation. See
[cms/README.md](cms/README.md) for the full install and operations guide.

### Security

- **Single-account login** with a bcrypt password hash; brute-force lockout
  (5 failures → 5-minute lock) and a 1-second delay on failed attempts.
- **Sessions** are HttpOnly, SameSite=Lax, secure over HTTPS, with an absolute
  lifetime; the session ID is regenerated on login.
- **CSRF protection** — every state-changing API call requires a per-session
  token header.
- **Path safety** — fragment keys and post slugs are strictly validated and
  resolved inside `content/` only.
- **Upload validation** — extension allowlist, size limit, server-side MIME
  sniffing (never trusts the client), image integrity check, and rejection of
  SVGs containing scripts or event handlers. Uploaded files get randomized
  names, and PHP execution is blocked under `uploads/`.
- **Engine internals** (`config.php`, `engine.php`, `auth.php`, `cms/lib/`)
  are not reachable over HTTP.
- **Atomic writes** (temp file + rename, Windows-safe) so a failed save never
  corrupts live content.

## Requirements

- PHP **7.4+** (no PHP 8-only syntax); the `fileinfo` extension is used when
  present, with a magic-byte fallback otherwise.
- Apache with `.htaccess` support, or an equivalent server configuration
  (post permalinks, denied access to `content/` and engine internals, no PHP
  under `uploads/`). For local development the PHP built-in server works via
  a router script.
- Write access for the web-server user to `content/`, `uploads/`,
  `search-index.json` and `sitemap.xml`.

## Repository layout

```
cms/                  # the reusable engine
├── engine.php        # core: config, rendering, content model, index generation
├── auth.php          # login/logout, CSRF, brute-force lockout
├── api.php           # JSON API used by the in-browser editor
├── assets/           # editor UI (vanilla JS + CSS, no build step)
├── lib/Parsedown.php # Markdown renderer
└── README.md         # install & operations guide
content/              # site content (Markdown) — created per installation
uploads/              # editor-uploaded media — created per installation
```
