# CMS engine — install & operations guide

A single-user, database-free CMS. Content is markdown files; the engine is
this `cms/` directory. Nothing else is required.

## Credentials

Initial login: **admin / legalizm-cms-2026** at `/cms/login.php` (the URL is
not linked anywhere on the site — bookmark it).

**Change the password now:**

```
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
```

Paste the output into `password_hash` in `cms/config.php`.

## Installing into another PHP site

1. Copy `cms/` next to the site's document-root files.
2. Edit `cms/config.php` (credentials, categories, `search_pages`, `site_url`).
   Raw HTML is disabled by default. Set `allow_html => true` only when all
   Markdown is trusted or has passed through a separate HTML sanitizer.
3. Add `require __DIR__ . '/cms/engine.php';` to the site's bootstrap
   (any file included by every page).
4. Emit `<?= cms_assets() ?>` once before `</body>`.
5. Replace editable fragments with `<?= cms_editable('page/region') ?>` —
   content then lives in `content/pages/<page>/<region>.md`.
6. (Optional posts) Put posts in `content/posts/<slug>.md` (front matter:
   `title`, `date`, `category`, optional `excerpt`), render listings with
   `cms_posts('category')`, single posts with `cms_post($slug)` and add
   `<?= cms_listing_controls('category') ?>` to listing pages.
   Configure `post_url` with a literal `{slug}` placeholder. `cms_post_url()`
   derives every public URL from the stored slug and repairs malformed legacy
   patterns that omitted the placeholder.
7. Copy the bundled `content/` and `uploads/` directories, including their
   hidden `.htaccess` files. Add content beneath them; do not replace them with
   newly created empty directories.
8. The web server user needs **write access** to `content/`,
   `content/.drafts/`, `uploads/`, and the files regenerated on publish
   (`search-index.json`, `sitemap.xml`).

## Day-to-day editing

- Log in → browse the site → hover an outlined fragment → **✎ Edytuj**.
- Markdown with tables; paste or drag images/PDFs straight into the editor.
- Open **Content** in the toolbar to browse `/cms/content.php`, which lists
  configured pages, editable regions, posts, categories, missing Markdown
  files, and the editable navigation JSON.
- Open **Media** in the toolbar or **Media library** in the editor to browse
  `/cms/media.php`, search existing uploads, insert an existing asset, and edit
  alt text or captions stored as `<file>.meta.json` sidecar files.
- `Ctrl+S` saves a draft under `content/.drafts/`; **Podgląd szkicu** opens a
  preview link; **Opublikuj** updates the live Markdown file.
- Use the **Kopie zapasowe** list in the editor to restore an older saved
  version in one click.
- On listing pages (**Orzeczenia / Wydarzenia / Uchwały**): **＋ Dodaj wpis**.

## Media library

`/cms/media.php` lists files from the configured `uploads_dir` and searches by
relative path, alt text and caption. Images show as thumbnails; PDFs show as a
file tile with a direct preview link. Picker mode (`/cms/media.php?picker=1`)
inserts the correct Markdown back into the active editor panel.

Metadata is stored beside the upload as JSON, for example
`uploads/2026/07/photo.jpg.meta.json`. Deleting is blocked while the asset URL
is still referenced by published pages, posts or saved drafts.

## Content inventory & navigation

`/cms/content.php` is the small content overview. It combines configured
`search_pages`, Markdown files under `content/pages/`, editable region keys
found in PHP templates, posts, categories and missing Markdown placeholders.
Missing region files can be created from this screen; it creates Markdown only,
not PHP templates or routes.

Navigation is stored as JSON at `content/nav.json` by default:

```json
[
  { "label": "Home", "url": "/", "children": [] },
  { "label": "News", "url": "/news/", "children": [] }
]
```

Templates can render it with `cms_nav_items()` or `cms_nav_html()`. If the file
is missing or invalid, Pagecore falls back to `search_pages`.

## Drafts, backups & restore

Drafts are stored as shadow Markdown files under `content/.drafts/pages/` and
`content/.drafts/posts/`. They are not shown to visitors. Publishing writes the
current editor state to the live file and removes the matching draft.

Every publish or direct save first copies the previous live version to
`content/.backups/<key>.<timestamp>.md` (last 20 kept per fragment). To restore
from the browser, open the fragment and click **Przywróć** next to the backup
you want. Manual restore is still just copying a backup file back over the live
file.

## Deleting a post

Delete its `content/posts/<slug>.md` file (FTP/SSH). Listings, search index
and sitemap update on the next save; or touch any fragment to force it.

## Requirements

PHP 7.4+ with `fileinfo` (standard). The reusable `cms/`, `content/`, and
`uploads/` directories ship Apache `.htaccess` hardening. Apache must honor
those files; on another web server, map the equivalent rules (post permalinks
→ `post.php?slug=…`, deny `content/`, `cms/lib/`, `cms/config.php`, and block
PHP execution under `uploads/`).
For the PHP built-in server use: `php -S host:port -t <root> <root>/router.php`.
