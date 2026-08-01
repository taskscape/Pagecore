<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

if (!cms_is_logged_in()) {
    $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/cms/media.php';
    header('Location: /cms/login.php?next=' . rawurlencode($next));
    exit;
}

$query = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
$picker = isset($_GET['picker']);
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$mediaPage = cms_media_assets_page($query, $page);
$assets = $mediaPage['items'];

function cms_media_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cms_media_bytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024) { return round($bytes / 1024, 1) . ' KB'; }
    return $bytes . ' B';
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Media library - Pagecore CMS</title>
  <!-- Open Sans gives the media library the same default typography as the other CMS administration pages. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style nonce="<?= cms_media_e(cms_csp_nonce()) ?>">
    * { box-sizing: border-box; }
    body {
      margin: 0; background: #f7f5ef; color: #2b2620;
      font: 14px/1.5 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }
    a { color: #8c3727; }
    .shell { max-width: 1200px; margin: 0 auto; padding: 28px 20px 56px; }
    .top {
      display: flex; align-items: center; justify-content: space-between; gap: 18px;
      margin-bottom: 22px;
    }
    h1 { margin: 0; font-size: 28px; line-height: 1.15; }
    .sub { margin: 4px 0 0; color: #71675d; }
    .nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .nav a, .button {
      display: inline-flex; align-items: center; justify-content: center;
      min-height: 36px; padding: 8px 13px; border: 1px solid #d8d2c4;
      border-radius: 4px; background: #fff; color: #2b2620;
      text-decoration: none; font-weight: 600; cursor: pointer;
    }
    .search {
      display: grid; grid-template-columns: 1fr auto; gap: 10px;
      padding: 14px; border: 1px solid #e0dacd; background: #fff;
      margin-bottom: 18px;
    }
    .search input {
      min-width: 0; width: 100%; padding: 10px 12px; border: 1px solid #cfc8b9;
      border-radius: 4px; font: inherit; background: #fff;
    }
    .search button, .primary {
      border: 0; background: #9c3f2e; color: #fff; font-weight: 700;
      border-radius: 4px; padding: 10px 16px; cursor: pointer;
    }
    .grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: 14px;
    }
    .card {
      display: flex; flex-direction: column; min-height: 100%;
      border: 1px solid #ded7ca; background: #fff; border-radius: 6px; overflow: hidden;
    }
    .thumb {
      display: flex; align-items: center; justify-content: center;
      aspect-ratio: 4 / 3; background: #ece7dc; border-bottom: 1px solid #ded7ca;
    }
    .thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .pdf {
      display: grid; place-items: center; width: 72px; height: 92px;
      border: 2px solid #b9432e; color: #b9432e; background: #fff; font-weight: 800;
      letter-spacing: .05em;
    }
    .body { display: flex; flex-direction: column; gap: 10px; padding: 12px; flex: 1; }
    .name { margin: 0; font-weight: 700; overflow-wrap: anywhere; }
    .meta { margin: 0; color: #71675d; font-size: 12px; overflow-wrap: anywhere; }
    label { display: grid; gap: 4px; font-size: 11px; font-weight: 700; color: #71675d; text-transform: uppercase; letter-spacing: .04em; }
    label input, label textarea {
      width: 100%; padding: 8px 9px; border: 1px solid #cfc8b9; border-radius: 4px;
      background: #fff; color: #2b2620; font: 13px/1.4 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      text-transform: none; letter-spacing: 0;
    }
    label textarea { min-height: 64px; resize: vertical; }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; }
    .actions button {
      border: 1px solid #d8d2c4; border-radius: 4px; background: #faf8f3; color: #2b2620;
      padding: 7px 10px; font: 700 12px/1.3 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      cursor: pointer;
    }
    .actions .insert { background: #2f6b52; border-color: #2f6b52; color: #fff; }
    .actions .delete { color: #8c2f1c; }
    .actions button[disabled] { opacity: .55; cursor: default; }
    .status { min-height: 18px; color: #71675d; font-size: 12px; }
    .status.error { color: #8c2f1c; font-weight: 700; }
    .empty {
      padding: 28px; border: 1px dashed #cfc8b9; background: #fff; color: #71675d;
      text-align: center;
    }
    .pagination { display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 18px; }
    @media (max-width: 700px) {
      .top { display: block; }
      .nav { margin-top: 14px; }
      .search { grid-template-columns: 1fr; }
    }
  </style>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20,400,1,0&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/cms/assets/admin.css">
</head>
<body class="pc-admin">
  <div class="pc-app">
    <aside class="pc-sidebar">
      <a class="pc-brand" href="/" aria-label="Pagecore site home">
        <span class="pc-brand-mark"><span class="material-symbols-rounded" aria-hidden="true">check</span></span>
        <span class="pc-brand-copy"><strong>Pagecore</strong><span>Content workspace</span></span>
      </a>
      <div class="pc-nav-group">
        <p class="pc-nav-label">Workspace</p>
        <nav class="pc-nav" aria-label="CMS navigation">
          <a href="/cms/content.php"><span class="material-symbols-rounded" aria-hidden="true">dashboard</span>Overview</a>
          <a href="/cms/content.php#posts-title"><span class="material-symbols-rounded" aria-hidden="true">edit_note</span>Posts</a>
          <a href="/cms/content.php#pages-title"><span class="material-symbols-rounded" aria-hidden="true">article</span>Pages</a>
          <a href="/cms/media.php" aria-current="page"><span class="material-symbols-rounded" aria-hidden="true">photo_library</span>Media</a>
        </nav>
      </div>
      <div class="pc-nav-group">
        <p class="pc-nav-label">Library</p>
        <nav class="pc-nav" aria-label="Media shortcuts">
          <a href="/cms/media.php"><span class="material-symbols-rounded" aria-hidden="true">grid_view</span>All files</a>
          <?php if (!$picker): ?><a href="/cms/media.php?picker=1"><span class="material-symbols-rounded" aria-hidden="true">add_photo_alternate</span>Picker mode</a><?php endif; ?>
        </nav>
      </div>
      <div class="pc-sidebar-foot">Images and documents</div>
    </aside>
    <div class="pc-main">
  <main class="shell">
    <div class="top pc-page-head">
      <div>
        <p class="pc-eyebrow"><?= $picker ? 'Choose an asset' : 'Asset workspace' ?></p>
        <h1>Media library</h1>
        <p class="sub">Browse uploads, reuse assets, edit alt text and captions, and delete files that are not referenced by content.</p>
      </div>
      <nav class="nav pc-page-actions" aria-label="Media navigation">
        <a href="/"><span class="material-symbols-rounded" aria-hidden="true">open_in_new</span>View site</a>
        <input id="media-upload-input" type="file" hidden>
        <button id="media-upload-button" type="button" class="button primary"><span class="material-symbols-rounded" aria-hidden="true">upload</span>Upload media</button>
      </nav>
    </div>

    <div class="media-search-row">
    <form class="search" method="get" action="/cms/media.php">
      <?php if ($picker): ?><input type="hidden" name="picker" value="1"><?php endif; ?>
      <input type="search" name="q" value="<?= cms_media_e($query) ?>" placeholder="Search filename, alt text, or caption" aria-label="Search media">
      <button type="submit">Search</button>
    </form>
    </div>

    <div class="pc-content media-content">

    <?php if (!$assets): ?>
      <div class="empty">No media files found.</div>
    <?php else: ?>
      <section class="grid" aria-label="Uploaded media">
        <?php foreach ($assets as $asset): ?>
          <?php $alt = $asset['meta']['alt'] !== '' ? $asset['meta']['alt'] : $asset['filename_base']; ?>
          <article class="card"
              data-media-card
              data-media-rel="<?= cms_media_e($asset['rel']) ?>"
              data-media-url="<?= cms_media_e($asset['url']) ?>"
              data-media-revision="<?= cms_media_e($asset['revision']) ?>"
              data-media-meta-revision="<?= cms_media_e($asset['meta_revision']) ?>"
              data-media-markdown="<?= cms_media_e($asset['markdown']) ?>">
            <a class="thumb" href="<?= cms_media_e($asset['url']) ?>" target="_blank" rel="noopener">
              <?php if ($asset['kind'] === 'image'): ?>
                <img src="<?= cms_media_e($asset['url']) ?>" alt="<?= cms_media_e($alt) ?>">
              <?php else: ?>
                <span class="pdf">PDF</span>
              <?php endif; ?>
            </a>
            <div class="body">
              <p class="name"><?= cms_media_e($asset['filename']) ?></p>
              <p class="meta">
                <?= cms_media_e($asset['rel']) ?><br>
                <?= cms_media_e(cms_media_bytes($asset['size'])) ?> · <?= cms_media_e(date('Y-m-d H:i', $asset['modified'])) ?>
                <?php if (isset($asset['width'], $asset['height'])): ?>
                  · <?= (int) $asset['width'] ?> x <?= (int) $asset['height'] ?>
                <?php endif; ?>
              </p>
              <label>Alt text
                <input type="text" name="alt" value="<?= cms_media_e($asset['meta']['alt']) ?>">
              </label>
              <label>Caption
                <textarea name="caption"><?= cms_media_e($asset['meta']['caption']) ?></textarea>
              </label>
              <div class="actions">
                <?php if ($picker): ?><button type="button" class="insert" data-action="insert">Insert</button><?php endif; ?>
                <button type="button" data-action="save">Save metadata</button>
                <button type="button" class="delete" data-action="delete">Delete</button>
              </div>
              <div class="status" role="status" aria-live="polite"></div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
      <?php if ($mediaPage['pages'] > 1): ?>
        <nav class="pagination" aria-label="Media pages">
          <?php if ($mediaPage['page'] > 1): ?>
            <a class="button" href="?q=<?= rawurlencode($query) ?>&amp;page=<?= $mediaPage['page'] - 1 ?><?= $picker ? '&amp;picker=1' : '' ?>">Previous</a>
          <?php endif; ?>
          <span>Page <?= (int) $mediaPage['page'] ?> of <?= (int) $mediaPage['pages'] ?></span>
          <?php if ($mediaPage['page'] < $mediaPage['pages']): ?>
            <a class="button" href="?q=<?= rawurlencode($query) ?>&amp;page=<?= $mediaPage['page'] + 1 ?><?= $picker ? '&amp;picker=1' : '' ?>">Next</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
      <?php if ($mediaPage['truncated']): ?><p class="status">Results were capped by the configured inventory limit.</p><?php endif; ?>
    <?php endif; ?>
    </div>
  </main>
    </div>
  </div>

  <script nonce="<?= cms_media_e(cms_csp_nonce()) ?>">
    window.PAGECORE_MEDIA = {
      api: '/cms/api.php',
      token: <?= json_encode(cms_csrf_token(), JSON_UNESCAPED_SLASHES) ?>,
      picker: <?= $picker ? 'true' : 'false' ?>
    };
  </script>
  <script nonce="<?= cms_media_e(cms_csp_nonce()) ?>">
  (function () {
    'use strict';
    var cfg = window.PAGECORE_MEDIA || {};

    function post(action, data) {
      var body = new URLSearchParams();
      Object.keys(data || {}).forEach(function (key) { body.append(key, data[key]); });
      return fetch(cfg.api + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: { 'X-CMS-Token': cfg.token || '' },
        body: body
      }).then(function (res) {
        return res.json().then(function (json) {
          if (!res.ok || !json.ok) { throw new Error(json.error || 'Request failed.'); }
          return json;
        });
      });
    }

    function setStatus(card, text, error) {
      var status = card.querySelector('.status');
      if (!status) return;
      status.className = error ? 'status error' : 'status';
      status.textContent = text || '';
    }

    function setBusy(card, busy) {
      Array.prototype.forEach.call(card.querySelectorAll('button'), function (button) {
        button.disabled = busy;
      });
    }

    function save(card) {
      setBusy(card, true);
      setStatus(card, 'Saving metadata...');
      post('save-media-meta', {
        rel: card.getAttribute('data-media-rel'),
        revision: card.getAttribute('data-media-meta-revision'),
        alt: card.querySelector('[name="alt"]').value,
        caption: card.querySelector('[name="caption"]').value
      }).then(function (res) {
        card.setAttribute('data-media-markdown', res.asset.markdown || '');
        card.setAttribute('data-media-meta-revision', res.asset.meta_revision || 'missing');
        setStatus(card, 'Metadata saved.');
      }).catch(function (err) {
        setStatus(card, err.message, true);
      }).then(function () {
        setBusy(card, false);
      });
    }

    function remove(card) {
      var rel = card.getAttribute('data-media-rel');
      if (!confirm('Delete this media file? This is only allowed when content does not reference it.')) { return; }
      setBusy(card, true);
      setStatus(card, 'Deleting...');
      post('delete-media', { rel: rel, revision: card.getAttribute('data-media-revision') }).then(function () {
        card.remove();
        if (!document.querySelector('[data-media-card]')) {
          location.reload();
        }
      }).catch(function (err) {
        setStatus(card, err.message, true);
        setBusy(card, false);
      });
    }

    function insert(card) {
      var markdown = card.getAttribute('data-media-markdown') || '';
      if (window.opener && !window.opener.closed && typeof window.opener.cmsInsertMedia === 'function') {
        window.opener.cmsInsertMedia(markdown);
        window.close();
        return;
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(markdown).then(function () {
          setStatus(card, 'Markdown copied.');
        }).catch(function () {
          setStatus(card, markdown);
        });
      } else {
        setStatus(card, markdown);
      }
    }

    var uploadButton = document.getElementById('media-upload-button');
    var uploadInput = document.getElementById('media-upload-input');
    if (uploadButton && uploadInput) {
      uploadButton.addEventListener('click', function () { uploadInput.click(); });
      uploadInput.addEventListener('change', function () {
        var file = uploadInput.files && uploadInput.files[0];
        if (!file) return;
        var form = new FormData();
        form.append('file', file);
        uploadButton.disabled = true;
        uploadButton.lastChild.textContent = ' Uploading…';
        fetch(cfg.api + '?action=upload', {
          method: 'POST',
          headers: { 'X-CMS-Token': cfg.token || '' },
          body: form
        }).then(function (res) {
          return res.json().then(function (json) {
            if (!res.ok || !json.ok) { throw new Error(json.error || 'Upload failed.'); }
            location.href = '/cms/media.php?q=' + encodeURIComponent(json.asset.rel || file.name);
          });
        }).catch(function (err) {
          alert(err.message || 'Upload failed.');
          uploadButton.disabled = false;
          uploadButton.lastChild.textContent = ' Upload media';
        });
      });
    }

    document.addEventListener('click', function (ev) {
      var button = ev.target.closest('button[data-action]');
      if (!button) return;
      var card = button.closest('[data-media-card]');
      if (!card) return;
      var action = button.getAttribute('data-action');
      if (action === 'save') save(card);
      if (action === 'delete') remove(card);
      if (action === 'insert') insert(card);
    });
  })();
  </script>
</body>
</html>
