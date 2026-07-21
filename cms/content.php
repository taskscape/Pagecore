<?php
require __DIR__ . '/engine.php';
require __DIR__ . '/auth.php';

if (!cms_is_logged_in()) {
    $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/cms/content.php';
    header('Location: /cms/login.php?next=' . rawurlencode($next));
    exit;
}

$inventory = cms_content_inventory();

function cms_content_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cms_content_date($ts) {
    return $ts ? date('Y-m-d H:i', (int) $ts) : '';
}

function cms_content_sources(array $sources) {
    return implode(', ', $sources);
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Content - Pagecore CMS</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; background: #f7f5ef; color: #2b2620;
      font: 14px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
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
    .button {
      border: 1px solid #d8d2c4; border-radius: 4px; background: #faf8f3; color: #2b2620;
      padding: 7px 10px; font: 700 12px/1.3 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      cursor: pointer;
    }
    .button-primary { border-color: #9c3f2e; background: #9c3f2e; color: #fff; }
    .button[disabled] { opacity: .55; cursor: default; }
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
      <div><strong><?= count($inventory['posts']) ?></strong><span>Posts</span></div>
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
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Title</th><th>Category</th><th>Date</th><th>URL</th></tr></thead>
          <tbody>
            <?php foreach ($inventory['posts'] as $post): ?>
              <tr>
                <td><?= cms_content_e($post['title']) ?></td>
                <td><?= cms_content_e($post['category_label']) ?> <span class="muted">(<?= cms_content_e($post['category']) ?>)</span></td>
                <td><?= cms_content_e($post['date']) ?></td>
                <td><a href="<?= cms_content_e($post['url']) ?>"><?= cms_content_e($post['url']) ?></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

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

    document.addEventListener('click', function (ev) {
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
