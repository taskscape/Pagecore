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
2. Copy `deployment/pagecore-config.php.example` to a private directory outside
   `DOCUMENT_ROOT`, edit it, and set `PAGECORE_CONFIG` to its absolute path.
   Raw HTML is always escaped; configuration cannot disable safe mode. Convert
   trusted embeds into template components outside editor-authored Markdown.
3. Add `require __DIR__ . '/cms/engine.php';` to the site's bootstrap
   (any file included by every page).
4. Emit `<?= cms_assets() ?>` once before `</body>`.
5. Replace editable fragments with `<?= cms_editable('page/region') ?>` —
   content then lives in `content/pages/<page>/<region>.md`.
6. (Optional posts) Put posts in `content/posts/<slug>.md` (front matter:
   `title`, `date`, `category`, optional `excerpt`), render listings with
   `cms_posts('category')`, single posts with `cms_post($slug)` and add
   `<?= cms_post_social_meta($post) ?>` inside the post template's `<head>` for
   Facebook/Open Graph previews. The helper uses the featured image and the
   authored excerpt, falling back to a summary derived from the post body.
   `<?= cms_listing_controls('category') ?>` to listing pages.
   Configure `post_url` with a literal `{slug}` placeholder. `cms_post_url()`
   derives every public URL from the stored slug and repairs malformed legacy
   patterns that omitted the placeholder.
7. Create private `content/`, `backups/`, and `uploads/` directories beside the
   document root. New media is served only through `/cms/media-file.php`.
8. The web server user needs **write access** to those private directories and the files regenerated on publish
   (`search-index.json`, `sitemap.xml`).

## Day-to-day editing

- Log in → browse the site → hover an outlined fragment → **✎ Edit**.
- Post metadata includes a featured-image drop area. Drop or choose a JPEG/PNG
  within the configured upload limit; Pagecore uploads it and saves its URL to the post draft automatically.
  For a crisp Facebook preview, use a landscape image around 1200 x 630 pixels.
- Markdown with tables; paste or drag raster images/PDFs straight into the
  editor. SVG uploads are rejected because SVG is active XML.
- Open **Content** in the toolbar to browse `/cms/content.php`, which lists
  configured pages, editable regions, posts, categories, missing Markdown
  files, and the editable navigation JSON.
- Open **Media** in the toolbar or **Media library** in the editor to browse
  `/cms/media.php`, search existing uploads, insert an existing asset, and edit
  alt text or captions stored as `<file>.meta.json` sidecar files.
- `Ctrl+S` saves a draft under `content/.drafts/`; **Preview draft** opens a
  preview link; **Publish** updates the live Markdown file.
- Use the **Revisions** list in the editor to restore an older saved
  version in one click.
- On eligible listing pages: **＋ Add post**.

- The installed Pagecore version is visible in the editor toolbar and on
  `/cms/content.php`.

## Media library

`/cms/media.php` lists files from the configured `uploads_dir` and searches by
relative path, alt text and caption. Images show as thumbnails; PDFs show as a
file tile with a download-only link. Picker mode (`/cms/media.php?picker=1`)
inserts the correct Markdown back into the active editor panel.

Metadata is stored beside the upload as JSON, for example
`uploads/2026/07/photo.jpg.meta.json`. Deleting is blocked while the asset URL
is still referenced by published pages, posts or saved drafts.

## Content inventory & navigation

`/cms/content.php` is the small content overview. It combines configured
`search_pages`, Markdown files under `content/pages/`, editable region keys
found in PHP templates, posts, categories and missing Markdown placeholders.
The post table renders 100 posts per page and supports server-side title/slug
search plus category filtering, so large content sets do not create oversized
browser tables.
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
from the browser, open the fragment and click **Restore** next to the backup
you want. Manual restore is still just copying a backup file back over the live
file.

Imported post visibility is controlled by the front-matter `status` field.
Only `publish` (or an omitted status for legacy files) is public; every other
value is excluded from listings, tags, search, sitemap, and anonymous detail
routes. A detail template may call `cms_post($slug, cms_is_logged_in())` to let
an authenticated editor review those files deliberately.

## Deleting a post

In **Content inventory**, click **Delete** beside the post's **Edit** and
**View** controls, then confirm. Pagecore removes the published Markdown file
and any draft, preserves a backup revision, and regenerates the listings,
search index, and sitemap.

## Vendored dependencies

Parsedown is pinned in `lib/Parsedown.version.json`, including its upstream tag
commit and SHA-256 checksum. Refresh the vendored file only through the checked
updater so a changed or compromised download is rejected before it is copied:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Update-Parsedown.ps1
npm run test:parsedown
```

Review upstream release notes and update the pinned version, tag commit, URL,
and checksum together. Deploy the resulting CMS only through the versioned
release artifact; individual dependency files are never copied to sites.

## Release and deployment

`npm run release:build` creates `artifacts/pagecore-X.Y.Z.zip` from the tracked
`cms/`, `content/`, and `uploads/` sources. The archive contains `VERSION` and a
SHA-256 manifest. Install it with `scripts/Install-PagecoreRelease.ps1`; the
installer validates every entry, replaces the managed CMS as a unit, preserves
only site-specific `cms/config.php`, and writes `.pagecore-release.json`.
Run `scripts/Test-PagecoreDeployment.ps1` after deployment to fail on drift or
a version mismatch. `npm run release:test` exercises build, install,
configuration preservation, checksum verification, and drift detection.

## Requirements

PHP 8.3+ on a supported PHP branch, with `fileinfo` (standard). Production configuration, content,
backups, and uploads must be outside `DOCUMENT_ROOT`; Pagecore fails closed if
they are not. The bundled PHP router is a loopback-only development facility,
started through the repository script so its explicit development opt-in and
denial policy are applied.
Production rejects HTTP by default and uses Secure, HttpOnly, SameSite=Lax
session cookies. Configure only known proxy addresses in `trusted_proxies` when
TLS is terminated upstream; client-supplied forwarding headers are otherwise
ignored.
