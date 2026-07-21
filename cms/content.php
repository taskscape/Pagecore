<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

if (!cms_is_logged_in()) {
    $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/cms/content.php';
    header('Location: /cms/login.php?next=' . rawurlencode($next));
    exit;
}

// Query parameters select one small post page before the template emits any inventory rows.
$contentPostQuery = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$contentPostCategory = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$contentPostPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$inventory = cms_content_inventory($contentPostQuery, $contentPostCategory, $contentPostPage, 100);
$postPagination = $inventory['post_pagination'];

function cms_content_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cms_content_date($ts) {
    return $ts ? date('Y-m-d H:i', (int) $ts) : '';
}

function cms_content_sources(array $sources) {
    return implode(', ', $sources);
}

/** Preserve active inventory filters while paging through server-rendered post slices. */
function cms_content_posts_url($page, $query, $category) {
    $params = array();
    if ($query !== '') { $params['q'] = $query; }
    if ($category !== '') { $params['category'] = $category; }
    if ((int) $page > 1) { $params['page'] = (int) $page; }
    return '/cms/content.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Content - Pagecore CMS</title>
  <!-- Open Sans keeps the inventory aligned with the CMS administration typography. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; background: #f7f5ef; color: #2b2620;
      font: 14px/1.5 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }
    a { color: #8c3727; }
    .shell { max-width: 1240px; margin: 0 auto; padding: 28px 20px 56px; }
    .top {
      display: flex; align-items: center; justify-content: space-between; gap: 18px;
      margin-bottom: 22px;
    }
    h1 { margin: 0; font-size: 28px; line-height: 1.15; }
    h2 { margin: 0 0 12px; font-size: 18px; }
    .sub { margin: 4px 0 0; color: #71675d; }
    .version { margin: 6px 0 0; color: #71675d; font-size: 13px; font-weight: 700; }
    .nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .nav a {
      display: inline-flex; align-items: center; justify-content: center;
      min-height: 36px; padding: 8px 13px; border: 1px solid #d8d2c4;
      border-radius: 4px; background: #fff; color: #2b2620;
      text-decoration: none; font-weight: 700;
    }
    .summary {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 12px; margin-bottom: 18px;
    }
    .summary div {
      padding: 14px; border: 1px solid #ded7ca; border-radius: 6px; background: #fff;
    }
    .summary strong { display: block; font-size: 24px; line-height: 1; }
    .summary span { color: #71675d; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .section {
      margin-top: 18px; border: 1px solid #ded7ca; border-radius: 6px; background: #fff; overflow: hidden;
    }
    .section-head {
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      padding: 14px 16px; border-bottom: 1px solid #ebe5da; background: #fbfaf6;
    }
    .section-head p { margin: 2px 0 0; color: #71675d; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #ebe5da; text-align: left; vertical-align: top; }
    th { color: #71675d; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; background: #fbfaf6; }
    tr:last-child td { border-bottom: 0; }
    code { font-family: ui-monospace, Consolas, "Courier New", monospace; font-size: 12px; }
    .muted { color: #71675d; }
    .tag {
      display: inline-block; padding: 2px 7px; border-radius: 999px;
      background: #ede7da; color: #2b2620; font-size: 12px; font-weight: 700;
    }
    .tag-ok { background: #dfece5; color: #25543f; }
    .tag-missing { background: #f4ddd8; color: #8c2f1c; }
    /* Action links share the button treatment so edit and view controls remain visually distinct from ordinary links. */
    .button {
      border: 1px solid #d8d2c4; border-radius: 4px; background: #faf8f3; color: #2b2620;
      display: inline-block; padding: 7px 10px; font: 700 12px/1.3 "Open Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      text-decoration: none;
      cursor: pointer;
    }
    .button-primary { border-color: #9c3f2e; background: #9c3f2e; color: #fff; }
    /* The destructive post action is visually distinct from edit and view controls. */
    .button-danger { border-color: #b7432f; background: #fff4f1; color: #8c2f1c; }
    .button[disabled] { opacity: .55; cursor: default; }
    /* Compact filter controls keep the inventory usable without adding client-side post processing. */
    .post-tools { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
    .post-filters { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }
    .post-filters label { color: #71675d; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .post-filters input, .post-filters select { min-height: 34px; padding: 6px 8px; border: 1px solid #d8d2c4; border-radius: 4px; color: #2b2620; background: #fff; font: inherit; }
    .post-filters input { width: min(210px, 40vw); }
    .post-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-top: 1px solid #ebe5da; background: #fbfaf6; }
    .post-pagination p { margin: 0; color: #71675d; font-size: 12px; }
    .post-pagination-nav { display: flex; align-items: center; gap: 8px; }
    /* The modal keeps post creation available from the inventory without leaving this management screen first. */
    .post-modal { position: fixed; inset: 0; z-index: 10; display: grid; place-items: center; padding: 20px; background: rgba(43, 38, 32, .45); }
    .post-modal[hidden] { display: none; }
    .post-modal-box { width: min(420px, 100%); padding: 20px; border-radius: 6px; background: #fff; box-shadow: 0 16px 40px rgba(43, 38, 32, .24); }
    .post-modal-box h3 { margin: 0 0 14px; }
    .post-modal-box label { display: grid; gap: 6px; margin-top: 12px; color: #71675d; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; }
    .post-modal-box input, .post-modal-box select { width: 100%; padding: 9px 10px; border: 1px solid #cfc8b9; border-radius: 4px; color: #2b2620; background: #fff; font: inherit; }
    .post-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    /* Keep each post's edit, public-view, and delete controls together when the inventory table narrows. */
    .post-actions { display: flex; flex-wrap: nowrap; gap: 6px; white-space: nowrap; }
    .nav-editor { display: grid; gap: 10px; padding: 14px 16px; }
    .nav-editor label { display: grid; gap: 6px; font-weight: 800; color: #71675d; text-transform: uppercase; letter-spacing: .05em; font-size: 11px; }
    textarea {
      width: 100%; min-height: 280px; resize: vertical; padding: 12px;
      border: 1px solid #cfc8b9; border-radius: 4px; color: #2b2620; background: #fff;
      font: 13px/1.55 ui-monospace, Consolas, "Courier New", monospace;
      text-transform: none; letter-spacing: 0;
    }
    .status { color: #71675d; font-size: 12px; min-height: 18px; }
    .status.error { color: #8c2f1c; font-weight: 800; }
    @media (max-width: 720px) {
      .top { display: block; }
      .nav { margin-top: 14px; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <div class="top">
      <div>
        <h1>Content inventory</h1>
        <p class="sub">Configured pages, editable regions, posts, categories, missing Markdown files, and navigation JSON.</p>
        <p class="version">Pagecore <?= cms_content_e(cms_version()) ?></p>
      </div>
      <nav class="nav" aria-label="Content navigation">
        <a href="/">View site</a>
        <a href="/cms/media.php">Media</a>
      </nav>
    </div>

    <section class="summary" aria-label="Inventory summary">
      <div><strong><?= count($inventory['pages']) ?></strong><span>Configured pages</span></div>
      <div><strong><?= count($inventory['regions']) ?></strong><span>Editable regions</span></div>
      <div><strong><?= count($inventory['missing']) ?></strong><span>Missing files</span></div>
      <div><strong><?= (int) $inventory['posts_total'] ?></strong><span>Posts</span></div>
      <div><strong><?= count($inventory['categories']) ?></strong><span>Categories</span></div>
    </section>

    <section class="section" aria-labelledby="pages-title">
      <div class="section-head">
        <div>
          <h2 id="pages-title">Configured pages</h2>
          <p>From <code>search_pages</code>; region status shows whether the linked Markdown file exists.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Title</th><th>URL</th><th>Type</th><th>Region</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($inventory['pages'] as $page): ?>
              <tr>
                <td><?= cms_content_e($page['title']) ?></td>
                <td><a href="<?= cms_content_e($page['url']) ?>"><?= cms_content_e($page['url']) ?></a></td>
                <td><?= cms_content_e($page['type']) ?></td>
                <td><?= $page['region'] !== '' ? '<code>' . cms_content_e($page['region']) . '</code>' : '<span class="muted">none</span>' ?></td>
                <td>
                  <?php if ($page['exists'] === null): ?>
                    <span class="tag">Listing</span>
                  <?php elseif ($page['exists']): ?>
                    <span class="tag tag-ok">Markdown present</span>
                  <?php else: ?>
                    <span class="tag tag-missing">Missing Markdown</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="section" aria-labelledby="regions-title">
      <div class="section-head">
        <div>
          <h2 id="regions-title">Editable regions</h2>
          <p>Union of Markdown files, configured page regions, and <code>cms_editable()</code> calls found in PHP templates.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Key</th><th>Source</th><th>Status</th><th>URL</th><th>Updated</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($inventory['regions'] as $region): ?>
              <tr data-content-region="<?= cms_content_e($region['key']) ?>"<?= !$region['exists'] ? ' data-content-missing="1"' : '' ?>>
                <td><code><?= cms_content_e($region['key']) ?></code></td>
                <td><?= cms_content_e(cms_content_sources($region['sources'])) ?></td>
                <td>
                  <?php if ($region['exists']): ?>
                    <span class="tag tag-ok">Markdown present</span>
                  <?php else: ?>
                    <span class="tag tag-missing">Missing Markdown</span>
                  <?php endif; ?>
                  <?php if ($region['draft']): ?> <span class="tag">Draft</span><?php endif; ?>
                </td>
                <td><?= $region['url'] !== '' ? '<a href="' . cms_content_e($region['url']) . '">' . cms_content_e($region['url']) . '</a>' : '<span class="muted">not mapped</span>' ?></td>
                <td><?= cms_content_e(cms_content_date($region['modified'])) ?></td>
                <td>
                  <?php if (!$region['exists']): ?>
                    <button type="button" class="button button-primary" data-action="create-region" data-key="<?= cms_content_e($region['key']) ?>">Create file</button>
                  <?php else: ?>
                    <span class="muted"><?= (int) $region['size'] ?> bytes</span>
                  <?php endif; ?>
                  <div class="status" role="status" aria-live="polite"></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="section" aria-labelledby="posts-title">
      <div class="section-head">
        <div>
          <h2 id="posts-title">Posts</h2>
          <p>Markdown posts grouped by configured categories.</p>
        </div>
        <div class="post-tools">
          <!-- This GET form keeps filtering server-side so Chrome receives only one page of rows. -->
          <form class="post-filters" method="get" action="/cms/content.php" aria-label="Filter posts">
            <label for="post-search">Search
              <input id="post-search" name="q" type="search" value="<?= cms_content_e($postPagination['query']) ?>" placeholder="Title or slug">
            </label>
            <label for="post-category-filter">Category
              <select id="post-category-filter" name="category">
                <option value="">All categories</option>
                <?php foreach ($inventory['categories'] as $category): ?>
                  <option value="<?= cms_content_e($category['slug']) ?>"<?= $postPagination['category'] === $category['slug'] ? ' selected' : '' ?>><?= cms_content_e($category['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="button">Filter</button>
            <?php if ($postPagination['query'] !== '' || $postPagination['category'] !== ''): ?>
              <a class="button" href="/cms/content.php">Clear</a>
            <?php endif; ?>
          </form>
          <!-- A global create action lets editors start a post from the inventory and choose its category explicitly. -->
          <button type="button" class="button button-primary" id="add-post">＋ Dodaj wpis</button>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <!-- The actions column separates editorial work from opening the published post. -->
          <thead><tr><th>Title</th><th>Category</th><th>Date</th><th>URL</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($inventory['posts'] as $post): ?>
              <!-- The row key lets the successful delete action remove exactly the affected post. -->
              <tr data-content-post="<?= cms_content_e($post['slug']) ?>">
                <!-- The title is an editor shortcut so an inventory is also useful for day-to-day editing. -->
                <td><a href="<?= cms_content_e($post['url']) ?>#cms-edit"><?= cms_content_e($post['title']) ?></a></td>
                <td><?= cms_content_e($post['category_label']) ?> <span class="muted">(<?= cms_content_e($post['category']) ?>)</span></td>
                <td><?= cms_content_e($post['date']) ?></td>
                <td><a href="<?= cms_content_e($post['url']) ?>"><?= cms_content_e($post['url']) ?></a></td>
                <td>
                  <!-- Keep all post actions together so deletion is deliberate but available beside Edit and View. -->
                  <div class="post-actions">
                    <a class="button button-primary" href="<?= cms_content_e($post['url']) ?>#cms-edit">Edit</a>
                    <a class="button" href="<?= cms_content_e($post['url']) ?>">View</a>
                    <button type="button" class="button button-danger" data-action="delete-post" data-slug="<?= cms_content_e($post['slug']) ?>" data-title="<?= cms_content_e($post['title']) ?>">Delete</button>
                  </div>
                  <div class="status" role="status" aria-live="polite"></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php $postStart = $postPagination['total'] ? $postPagination['offset'] + 1 : 0; ?>
      <?php $postEnd = $postPagination['offset'] + count($inventory['posts']); ?>
      <!-- Pager links preserve the active search/category selection and keep the DOM bounded to 100 posts. -->
      <div class="post-pagination" aria-label="Post pagination">
        <p>Showing <?= (int) $postStart ?>–<?= (int) $postEnd ?> of <?= (int) $postPagination['total'] ?> matching posts (<?= (int) $inventory['posts_total'] ?> total).</p>
        <nav class="post-pagination-nav" aria-label="Post pages">
          <?php if ($postPagination['has_prev']): ?>
            <a class="button" href="<?= cms_content_e(cms_content_posts_url($postPagination['page'] - 1, $postPagination['query'], $postPagination['category'])) ?>">Previous</a>
          <?php endif; ?>
          <span class="muted">Page <?= (int) $postPagination['page'] ?> of <?= (int) $postPagination['pages'] ?></span>
          <?php if ($postPagination['has_next']): ?>
            <a class="button" href="<?= cms_content_e(cms_content_posts_url($postPagination['page'] + 1, $postPagination['query'], $postPagination['category'])) ?>">Next</a>
          <?php endif; ?>
        </nav>
      </div>
    </section>

    <!-- This dialog provides the category that a public listing page normally supplies as context. -->
    <div class="post-modal" id="post-modal" role="dialog" aria-modal="true" aria-labelledby="post-modal-title" hidden>
      <form class="post-modal-box" id="post-form">
        <h3 id="post-modal-title">Nowy wpis</h3>
        <label for="post-title">Tytuł wpisu
          <input id="post-title" name="title" type="text" required autofocus>
        </label>
        <label for="post-category">Kategoria
          <select id="post-category" name="category" required>
            <?php foreach ($inventory['categories'] as $category): ?>
              <option value="<?= cms_content_e($category['slug']) ?>"><?= cms_content_e($category['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="post-modal-actions">
          <button type="button" class="button" id="cancel-post">Anuluj</button>
          <button type="submit" class="button button-primary" id="create-post">Utwórz</button>
        </div>
        <div class="status" id="post-status" role="status" aria-live="polite"></div>
      </form>
    </div>

    <section class="section" aria-labelledby="categories-title">
      <div class="section-head">
        <div>
          <h2 id="categories-title">Categories</h2>
          <p>Configured post category labels and listing URLs.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Slug</th><th>Label</th><th>URL</th><th>Posts</th></tr></thead>
          <tbody>
            <?php foreach ($inventory['categories'] as $category): ?>
              <tr>
                <td><code><?= cms_content_e($category['slug']) ?></code></td>
                <td><?= cms_content_e($category['label']) ?></td>
                <td><a href="<?= cms_content_e($category['url']) ?>"><?= cms_content_e($category['url']) ?></a></td>
                <td><?= (int) $category['posts'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="section" aria-labelledby="nav-title">
      <div class="section-head">
        <div>
          <h2 id="nav-title">Navigation JSON</h2>
          <p>Saved at <code><?= cms_content_e($inventory['nav']['file']) ?></code>.</p>
        </div>
      </div>
      <div class="nav-editor">
        <label>Navigation JSON
          <textarea id="nav-json" spellcheck="false"><?= cms_content_e($inventory['nav']['json']) ?></textarea>
        </label>
        <div>
          <button type="button" class="button button-primary" id="save-nav">Save navigation</button>
        </div>
        <div class="status" id="nav-status" role="status" aria-live="polite"></div>
      </div>
    </section>
  </main>

  <script>
    window.PAGECORE_CONTENT = {
      api: '/cms/api.php',
      token: <?= json_encode(cms_csrf_token(), JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script>
  (function () {
    'use strict';
    var cfg = window.PAGECORE_CONTENT || {};

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

    function setStatus(el, text, error) {
      el.className = error ? 'status error' : 'status';
      el.textContent = text || '';
    }

    // The inventory has no category context, so this handler collects it before calling the existing create-post API.
    var addPost = document.getElementById('add-post');
    var postModal = document.getElementById('post-modal');
    var postForm = document.getElementById('post-form');
    var postTitle = document.getElementById('post-title');
    var postCategory = document.getElementById('post-category');
    var createPost = document.getElementById('create-post');
    var cancelPost = document.getElementById('cancel-post');
    var postStatus = document.getElementById('post-status');

    function closePostModal() {
      postModal.hidden = true;
      postForm.reset();
      setStatus(postStatus, '');
    }

    addPost.addEventListener('click', function () {
      postModal.hidden = false;
      postTitle.focus();
    });
    cancelPost.addEventListener('click', closePostModal);
    postModal.addEventListener('click', function (ev) {
      if (ev.target === postModal) { closePostModal(); }
    });
    postForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var title = postTitle.value.trim();
      if (!title) { postTitle.focus(); return; }
      createPost.disabled = true;
      setStatus(postStatus, 'Creating...');
      post('create-post', { title: title, category: postCategory.value })
        .then(function (res) {
          // The destination post page loads the standard editor and opens it through this hash.
          location.href = res.url + '#cms-edit';
        })
        .catch(function (err) {
          setStatus(postStatus, err.message, true);
          createPost.disabled = false;
        });
    });

    document.addEventListener('click', function (ev) {
      var remove = ev.target.closest('[data-action="delete-post"]');
      if (remove) {
        var postRow = remove.closest('[data-content-post]');
        var postStatus = postRow.querySelector('.status');
        var title = remove.getAttribute('data-title') || remove.getAttribute('data-slug');
        // Confirmation prevents an accidental removal from the same compact action group as Edit and View.
        if (!confirm('Delete the published post “' + title + '”? Its draft will also be removed.')) { return; }
        remove.disabled = true;
        setStatus(postStatus, 'Deleting...');
        post('delete-post', { slug: remove.getAttribute('data-slug') })
          .then(function () {
            // Removing the row mirrors the server state without requiring a full inventory reload.
            postRow.remove();
          })
          .catch(function (err) {
            remove.disabled = false;
            setStatus(postStatus, err.message, true);
          });
        return;
      }
      var create = ev.target.closest('[data-action="create-region"]');
      if (create) {
        var row = create.closest('[data-content-region]');
        var status = row.querySelector('.status');
        var key = create.getAttribute('data-key');
        create.disabled = true;
        setStatus(status, 'Creating...');
        post('create-region', { key: key, markdown: '# ' + key.split('/').pop().replace(/-/g, ' ') + '\n\nNew content.\n' })
          .then(function () {
            row.removeAttribute('data-content-missing');
            row.querySelector('td:nth-child(3)').innerHTML = '<span class="tag tag-ok">Markdown present</span>';
            create.replaceWith(document.createTextNode('Created'));
            setStatus(status, 'Markdown file created.');
          })
          .catch(function (err) {
            create.disabled = false;
            setStatus(status, err.message, true);
          });
      }
    });

    var saveNav = document.getElementById('save-nav');
    var navJson = document.getElementById('nav-json');
    var navStatus = document.getElementById('nav-status');
    saveNav.addEventListener('click', function () {
      saveNav.disabled = true;
      setStatus(navStatus, 'Saving navigation...');
      post('save-nav', { json: navJson.value })
        .then(function (res) {
          navJson.value = res.json || navJson.value;
          setStatus(navStatus, 'Navigation saved.');
        })
        .catch(function (err) {
          setStatus(navStatus, err.message, true);
        })
        .then(function () {
          saveNav.disabled = false;
        });
    });
  })();
  </script>
</body>
</html>
