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
3. Add `require __DIR__ . '/cms/engine.php';` to the site's bootstrap
   (any file included by every page).
4. Emit `<?= cms_assets() ?>` once before `</body>`.
5. Replace editable fragments with `<?= cms_editable('page/region') ?>` —
   content then lives in `content/pages/<page>/<region>.md`.
6. (Optional posts) Put posts in `content/posts/<slug>.md` (front matter:
   `title`, `date`, `category`, optional `excerpt`), render listings with
   `cms_posts('category')`, single posts with `cms_post($slug)` and add
   `<?= cms_listing_controls('category') ?>` to listing pages.
7. Create empty `content/` and `uploads/` directories; copy the bundled
   `.htaccess` files from this site's `content/` and `uploads/` dirs.
8. The web server user needs **write access** to `content/`, `uploads/`,
   and the files regenerated on save (`search-index.json`, `sitemap.xml`).

## Day-to-day editing

- Log in → browse the site → hover an outlined fragment → **✎ Edytuj**.
- Markdown with tables; paste or drag images/PDFs straight into the editor.
- `Ctrl+S` saves, `Esc` cancels.
- On listing pages (**Orzeczenia / Wydarzenia / Uchwały**): **＋ Dodaj wpis**.

## Backups & restore

Every save first copies the previous version to
`content/.backups/<key>.<timestamp>.md` (last 20 kept per fragment).
To restore: copy a backup file back over the live file — that's all.

## Deleting a post

Delete its `content/posts/<slug>.md` file (FTP/SSH). Listings, search index
and sitemap update on the next save; or touch any fragment to force it.

## Requirements

PHP 7.4+ with `fileinfo` (standard). Apache honoring `.htaccess`, or map the
equivalent rules (post permalinks → `post.php?slug=…`, deny `content/`,
`cms/lib/`, `cms/config.php`, block PHP execution under `uploads/`).
For the PHP built-in server use: `php -S host:port -t <root> <root>/router.php`.
