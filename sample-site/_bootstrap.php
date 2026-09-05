<?php
if (!defined('CMS_CONFIG_FILE')) {
    // PAGECORE_CONFIG has to win here. The engine reads it only when this
    // constant is unset — pinning the sample path unconditionally would let
    // public pages and /cms load two different configurations on any
    // deployment that sets the variable. Same getenv()/$_SERVER pair the
    // engine uses, because SetEnv reaches only one of them depending on the SAPI.
    $siteConfig = getenv('PAGECORE_CONFIG');
    if (!$siteConfig && isset($_SERVER['PAGECORE_CONFIG'])) { $siteConfig = (string) $_SERVER['PAGECORE_CONFIG']; }
    define('CMS_CONFIG_FILE', $siteConfig ?: __DIR__ . '/config.php');
    unset($siteConfig);
}
require dirname(__DIR__) . '/cms/engine.php';

function sample_url($path = '') {
    return '/sample-site' . $path;
}

function sample_header($title, $head = '') {
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Pagecore sample</title>
  <?= $head ?>
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
    <a href="<?= htmlspecialchars(cms_admin_url('login.php'), ENT_QUOTES, 'UTF-8') ?>?next=<?= rawurlencode(cms_site_url()) ?>">CMS sign in</a>
  </footer>
  <?= cms_assets() ?>
</body>
</html>
<?php
}

function sample_post_image(array $post, $className) {
    if (empty($post['image'])) { return; }
?>
  <img class="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>"
       src="<?= htmlspecialchars($post['image'], ENT_QUOTES, 'UTF-8') ?>"
       alt="<?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?>"
       loading="lazy">
<?php
}

function sample_post_card(array $post) {
?>
  <article class="post-card">
    <?php sample_post_image($post, 'post-card-image'); ?>
    <p class="eyebrow"><?= htmlspecialchars($post['category_label'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($post['date_display'], ENT_QUOTES, 'UTF-8') ?></p>
    <h3><a href="<?= htmlspecialchars($post['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></a></h3>
    <p><?= htmlspecialchars($post['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
  </article>
<?php
}
