# Pagecore

A lightweight, database-free CMS engine for PHP websites. Content is stored as
plain Markdown files on disk, and editing happens from the live site with a
draft-first workflow: a logged-in editor browses the pages exactly as visitors
see them, clicks an editable fragment, saves a draft, previews it, then
publishes when ready. Anonymous visitors are served plain rendered HTML with
zero editing overhead.

Pagecore is designed for small, single-editor sites (the bundled configuration
targets a Polish-language site) where a full CMS such as WordPress would be
overkill: no database, no admin dashboard, no build step — just PHP, Markdown
files, and a folder of uploads.

## Who it's for

| Perspective | What Pagecore gives you |
|---|---|
| **Site visitor** | A normal, fast PHP website. The CMS is invisible: no scripts, no styles, no cookies related to editing are delivered to anonymous users. |
| **Content editor** | In-place editing of any marked fragment of the site, draft previews, one-click backup restore, plus simple management of dated posts (news, rulings, events…) — all from the browser, after logging in at a private URL. |
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
   - **Save draft** stores work under `content/.drafts/` without changing what
     visitors see. **Podgląd szkicu** opens a standalone draft preview link.
     **Opublikuj** copies the current editor state to the live Markdown file.
   - **Images and PDFs** can be pasted or dragged straight into the editor —
     they are uploaded automatically and the correct Markdown snippet is
     inserted. PDFs render on the page as an embedded viewer with a download
     fallback link.
   - The **Media** link opens `/cms/media.php`, a searchable library of
     existing uploads. Editors can reuse an asset in the current editor,
     update alt text and captions stored in sidecar metadata files, preview
     the original file, and delete files that are not referenced by content.
   - The **Content** link opens `/cms/content.php`, an inventory of configured
     pages, editable regions, posts, categories, missing Markdown files, and
     the editable navigation JSON.
   - `Ctrl+S` saves a draft, `Esc` cancels (with a confirmation if there are
     unsaved changes).
4. **Manage posts** on listing pages (e.g. *Orzeczenia / Wydarzenia /
   Uchwały*): a **＋ Dodaj wpis** (Add post) button creates a new post in that
   category. Each post has a title, date, category and optional excerpt
   (editable as post metadata), plus a Markdown body edited the same way as
   any other fragment. Post URLs are generated automatically from the title
   (with Polish-character transliteration, e.g. *"Uchwała nr 5"* →
   `uchwala-nr-5`).

### Automatic housekeeping on every save

- **Drafts** — editor work is saved under `content/.drafts/` and remains
  invisible to anonymous visitors until it is published.
- **Backups** — before a fragment or post is overwritten, the previous version
  is copied to `content/.backups/` (the newest 20 versions are kept per
  fragment). Logged-in editors can restore a backup directly from the editor.
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
├── nav.json                    # optional editable navigation tree
├── .drafts/                    # unpublished editor drafts
└── .backups/                   # automatic per-save version history
uploads/
├── YYYY/MM/<name>-<random>.ext           # editor-uploaded images and PDFs
└── YYYY/MM/<name>-<random>.ext.meta.json # optional alt text and caption sidecar files
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

- Raw HTML and unsafe Markdown URL schemes are escaped or neutralized by
  default. `allow_html => true` is an explicit opt-in for content that is
  trusted or processed by a separate HTML sanitizer.
- `pdf:/uploads/path/file.pdf "Label"` on its own line becomes an embedded PDF
  viewer with a labelled download link.
- Standalone images are wrapped in `<figure>` for styling.
- Tables get a `cms-table` class hook.
- Dates display in Polish long form (e.g. *15 czerwca 2026*).

### Media library

Logged-in editors can open `/cms/media.php` directly or use **Media library**
inside an active edit panel. The library lists files under the configured
`uploads_dir`, searches by path, alt text and caption, and shows image
thumbnails or a PDF tile. In picker mode it inserts the same Markdown snippet
used by uploads, so existing assets can be reused without re-uploading.

Alt text and captions are stored beside the asset as
`<filename>.meta.json`. Deleting is intentionally conservative: the CMS scans
published pages, posts and drafts for the asset URL and refuses to delete a
file that is still referenced.

### Content inventory and navigation

Logged-in editors can open `/cms/content.php` directly or use **Content** in
the editor toolbar. The screen shows:

- the installed Pagecore version;
- configured `search_pages`, including whether each linked Markdown region
  exists;
- editable regions found from Markdown files, configured page regions, and
  `cms_editable()` calls scanned from PHP templates;
- missing Markdown region files, with a **Create file** action for safe
  template-backed placeholders;
- all posts and configured post categories with counts;
- editable navigation JSON stored at `content/nav.json` by default.

Navigation items use a small JSON tree:

```json
[
  { "label": "Home", "url": "/", "children": [] },
  { "label": "News", "url": "/news/", "children": [] }
]
```

Templates can render that file with `cms_nav_items()` or `cms_nav_html()`.
If `nav.json` does not exist or is invalid, Pagecore falls back to the
configured `search_pages`.

### Integration API (developer perspective)

Add `require 'cms/engine.php';` to the site bootstrap, then:

| Function | Purpose |
|---|---|
| `cms_editable('page/region')` | Render a fragment; wraps it in an editable element only for logged-in editors. |
| `cms_posts('category')` | List posts (newest first) for a listing page — title, date, excerpt, URL. |
| `cms_post($slug)` | Fetch one post with rendered body for a post template. |
| `cms_post_url($slug)` | Build a post URL from `post_url`; defensively restores a missing `{slug}` segment. |
| `cms_listing_controls('category')` | "Add post" button on listing pages (editors only). |
| `cms_nav_items()` / `cms_nav_html()` | Read or render the editable navigation tree from `content/nav.json`. |
| `cms_assets()` | Emit editor CSS/JS before `</body>` (empty for visitors). |

Site-specific settings (credentials, categories, searchable pages, site URL,
upload limits) live in `cms/config.php`, created per installation. See
[cms/README.md](cms/README.md) for the full install and operations guide.

## Converting an existing PHP website

Pagecore is meant to be added around an existing PHP site, not to replace the
site's templates. Keep your current routes, layout, CSS, JavaScript and
server-side PHP logic. Convert only the content that editors should control.

### 1. Install the engine beside the existing site

Put `cms/`, `content/` and `uploads/` at the same web-root level as the
existing PHP pages. Copy the bundled directories themselves so the hidden
`.htaccess` protections in all three directories are preserved:

```
public/
├── index.php
├── about.php
├── news.php
├── post.php
├── cms/
├── content/
└── uploads/
```

If the site has a shared bootstrap or layout include, load the engine there:

```php
<?php require __DIR__ . '/cms/engine.php'; ?>
```

If there is no shared include, add the same `require` near the top of every PHP
page that will render editable content or listings.

Then emit editor assets once before `</body>` in the shared footer:

```php
<?= cms_assets() ?>
```

`cms_assets()` returns an empty string for visitors, so anonymous traffic keeps
receiving the normal site without editor CSS or JavaScript.

### 2. Turn static page sections into editable fragments

Find pieces of hard-coded HTML that should be editable: hero text, body copy,
contact information, callouts, FAQ answers, table content and similar regions.
Replace each region with `cms_editable()`.

Before:

```php
<section class="hero">
  <h1>About our company</h1>
  <p>Long hand-written text...</p>
</section>
```

After:

```php
<section class="hero">
  <?= cms_editable('about/hero') ?>
</section>
```

Create the matching Markdown file:

```text
content/pages/about/hero.md
```

With content such as:

```markdown
# About our company

Long hand-written text...
```

Use stable, lowercase keys made from letters, numbers and hyphens:

```php
<?= cms_editable('home/intro') ?>
<?= cms_editable('services/pricing-table') ?>
<?= cms_editable('contact/opening-hours') ?>
```

Keys map directly to Markdown files under `content/pages/`. The engine accepts
up to three path segments, for example `services/websites/intro` maps to
`content/pages/services/websites/intro.md`.

### 3. Preserve important wrapper markup

Pagecore renders Markdown into HTML. Put design-critical classes and layout
containers in the PHP template, then let the editor manage the content inside.

Good:

```php
<section class="section section--narrow">
  <div class="prose">
    <?= cms_editable('privacy/body') ?>
  </div>
</section>
```

Avoid moving required layout wrappers, JavaScript hooks, forms or PHP business
logic into Markdown. Pagecore content is best used for editorial HTML generated
from Markdown, not for application code.

If you need the editable wrapper to be a specific element, pass the tag name:

```php
<?= cms_editable('home/sidebar-note', 'aside') ?>
```

Visitors still receive only the rendered Markdown. Logged-in editors receive
the same content wrapped with editor attributes.

### 4. Convert repeating news/listing pages to posts

Use Pagecore posts when editors need to add dated items such as news, events,
articles, rulings or announcements.

First configure categories in `cms/config.php`. Each category entry is used by
the editor UI, listing URLs and sitemap generation:

```php
'categories' => array(
    'news' => array('News', '/news/'),
    'events' => array('Events', '/events/'),
),
'post_url' => '/post/{slug}/',
```

On the listing page, replace hard-coded repeated items with `cms_posts()`:

```php
<?php require __DIR__ . '/cms/engine.php'; ?>

<main>
  <h1>News</h1>

  <?= cms_listing_controls('news') ?>

  <div class="news-list">
    <?php foreach (cms_posts('news') as $post): ?>
      <article class="news-card">
        <time datetime="<?= htmlspecialchars($post['date']) ?>">
          <?= htmlspecialchars($post['date_display']) ?>
        </time>
        <h2>
          <a href="<?= htmlspecialchars($post['url']) ?>">
            <?= htmlspecialchars($post['title']) ?>
          </a>
        </h2>
        <p><?= htmlspecialchars($post['excerpt']) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</main>

<?= cms_assets() ?>
```

`cms_listing_controls('news')` renders the "Add post" button only for logged-in
editors. It is invisible to visitors.

### 5. Add a post detail template

Create or adapt a detail route such as `post.php`. Fetch the slug from your
router or query string, load the post with `cms_post()`, and render its
metadata plus editable body.

```php
<?php
require __DIR__ . '/cms/engine.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
$post = cms_post($slug);
if (!$post) {
    http_response_code(404);
    echo 'Post not found';
    exit;
}
?>

<main>
  <article>
    <p class="eyebrow">
      <?= htmlspecialchars($post['category_label']) ?>
      · <?= htmlspecialchars($post['date_display']) ?>
    </p>
    <h1><?= htmlspecialchars($post['title']) ?></h1>

    <div class="prose">
      <?php if (cms_is_logged_in()): ?>
        <div class="cms-editable" data-cms-key="post:<?= htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8') ?>">
          <?= $post['body_html'] ?>
        </div>
      <?php else: ?>
        <?= $post['body_html'] ?>
      <?php endif; ?>
    </div>
  </article>
</main>

<?= cms_assets() ?>
```

The special key `post:<slug>` edits the Markdown body inside
`content/posts/<slug>.md`. Post detail templates render `body_html` for
visitors, and add the `cms-editable` wrapper only for logged-in editors. The
editor panel also exposes post metadata: title, date, category and optional
excerpt.

If your server supports pretty URLs, route `/post/my-title/` to
`post.php?slug=my-title`. Otherwise set `post_url` to a query-string pattern,
for example `/post.php?slug={slug}`.

Keep the literal `{slug}` placeholder in the configured pattern. Pagecore
recomputes cached listing URLs from each post slug and defensively appends the
placeholder if a migrated configuration accidentally omitted it.

### 6. Move existing list items into Markdown posts

Each existing list item becomes one file in `content/posts/`:

```markdown
---
title: Existing announcement
date: 2026-06-15
category: news
excerpt: Optional summary shown on listing pages.
---
Full post body in Markdown.
```

The filename is the slug used in URLs:

```text
content/posts/existing-announcement.md
```

The listing page is sorted newest first by `date`. If `excerpt` is omitted,
Pagecore derives one from the post body.

### 7. Configure search and sitemap inputs

For pages that should appear in `search-index.json` and `sitemap.xml`, add
entries to `search_pages` in `cms/config.php`:

```php
'search_pages' => array(
    '/' => array('Home', 'Page', 'home/intro'),
    '/about/' => array('About', 'Page', 'about/hero'),
    '/news/' => array('News', 'Listing', null),
),
```

The third value is an optional editable fragment key used to generate a search
excerpt for that page. Posts and category listing URLs are added automatically
from `content/posts/` and `categories`.

### 8. Deployment checklist

- `cms/config.php` exists and has the production password hash, `site_url`,
  `site_root`, `content_dir`, `uploads_dir`, categories and search pages.
- The web-server user can write to `content/`, `content/.drafts/`, `uploads/`,
  `search-index.json` and `sitemap.xml`.
- Direct HTTP access to `content/`, `cms/config.php`, `cms/engine.php`,
  `cms/auth.php` and `cms/lib/` is denied.
- PHP execution is blocked under `uploads/`.
- The bundled `.htaccess` files remain present under `cms/`, `content/`, and
  `uploads/`; equivalent rules are configured when Apache is not used.
- Post URL rewrites match the configured `post_url`.
- Every page that calls `cms_editable()`, `cms_posts()`, `cms_post()` or
  `cms_listing_controls()` has loaded `cms/engine.php`.
- `<?= cms_assets() ?>` appears once before `</body>` on pages where editors
  should edit content.

### Conversion rule of thumb

Keep structure in PHP. Move words, tables, images, PDFs and post bodies into
Markdown. This keeps the existing site design intact while giving editors the
Pagecore in-place editing workflow.

## Security

- **Single-account login** with a bcrypt password hash; brute-force lockout
  (5 failures → 5-minute lock) and a 1-second delay on failed attempts.
- **Sessions** are HttpOnly, SameSite=Lax, secure over HTTPS, with an absolute
  lifetime; the session ID is regenerated on login.
- **CSRF protection** — every state-changing API call requires a per-session
  token header.
- **Path safety** — fragment keys and post slugs are strictly validated and
  resolved inside `content/` only.
- **Safe Markdown by default** — raw HTML is escaped and unsafe Markdown URL
  schemes are neutralized. Raw HTML requires an explicit `allow_html => true`
  opt-in and should only be used with trusted or separately sanitized content.
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
- Write access for the web-server user to `content/`, `content/.drafts/`,
  `uploads/`,
  `search-index.json` and `sitemap.xml`.

## Sample site and tests

This repository includes a working sample site under `sample-site/`. It uses
the reusable `cms/` directory directly, but points the engine at
`sample-site/config.php` through the `PAGECORE_CONFIG` environment variable.
You can also define a `CMS_CONFIG_FILE` constant before requiring
`cms/engine.php` if an integration needs a per-site config file.

Install the test runner and start the sample site:

```powershell
npm install
npm run sample:start
```

Open `http://127.0.0.1:8765/sample-site/` and sign in at `/cms/login.php` with
`admin / pagecore-demo`.

Run the Playwright suite against the sample site:

```powershell
npm run test:e2e
```

The Playwright config starts the PHP built-in server with `php/php.exe`. Test
content is reset from `sample-site/fixtures/` into ignored runtime folders
before each run. The suite covers visitor rendering, drafts, preview, publish,
revision restore, post creation, upload validation, media-library search,
metadata sidecars, picker insertion, deletion of unused uploads, content
inventory, missing Markdown creation and editable navigation.

## Repository layout

```
cms/                  # the reusable engine
├── engine.php        # core: config, rendering, content model, index generation
├── auth.php          # login/logout, CSRF, brute-force lockout
├── api.php           # JSON API used by the in-browser editor
├── assets/           # editor UI (vanilla JS + CSS, no build step)
├── lib/Parsedown.php # Markdown renderer
└── README.md         # install & operations guide
sample-site/          # runnable demo site and content fixtures
scripts/              # sample reset/start helpers
tests/                # Playwright browser tests
content/              # protected site-content root; add Markdown beneath it
uploads/              # protected media root; PHP execution is blocked here
```
