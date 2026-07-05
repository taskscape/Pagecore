<?php
if (!defined('CMS_CONFIG_FILE')) {
    define('CMS_CONFIG_FILE', __DIR__ . '/config.php');
}
require dirname(__DIR__) . '/cms/engine.php';

function sample_url($path = '') {
    return '/sample-site' . $path;
}

function sample_header($title) {
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Pagecore sample</title>
  <link rel="stylesheet" href="/sample-site/assets/site.css">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="<?= sample_url('/') ?>">Pagecore Sample</a>
    <nav aria-label="Primary navigation">
      <?php foreach (cms_nav_items() as $item): ?>
        <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endforeach; ?>
    </nav>
  </header>
<?php
}

function sample_footer() {
?>
  <footer class="site-footer">
    <p>Sample site for the Pagecore database-free CMS engine.</p>
    <a href="/cms/login.php?next=<?= rawurlencode('/sample-site/') ?>">CMS sign in</a>
  </footer>
  <?= cms_assets() ?>
</body>
</html>
<?php
}

function sample_post_card(array $post) {
?>
  <article class="post-card">
    <p class="eyebrow"><?= htmlspecialchars($post['category_label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($post['date_display'], ENT_QUOTES, 'UTF-8') ?></p>
    <h3><a href="<?= htmlspecialchars($post['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></a></h3>
    <p><?= htmlspecialchars($post['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
  </article>
<?php
}
