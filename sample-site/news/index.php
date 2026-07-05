<?php
require dirname(__DIR__) . '/_bootstrap.php';
sample_header('Posts');

$active = isset($_GET['category']) ? $_GET['category'] : 'all';
$categories = cms_cfg('categories', array());
$posts = $active === 'all' ? cms_posts() : cms_posts($active);
?>
<main>
  <section class="page-title">
    <p class="eyebrow">Posts</p>
    <h1>Category listings and editor-created entries</h1>
    <p>Use the buttons below while signed in to create posts in each configured category.</p>
  </section>

  <section class="toolbar-band" aria-label="Post category filters">
    <a class="<?= $active === 'all' ? 'active' : '' ?>" href="<?= sample_url('/news/') ?>">All</a>
    <?php foreach ($categories as $slug => $def): ?>
      <a class="<?= $active === $slug ? 'active' : '' ?>" href="<?= sample_url('/news/?category=' . rawurlencode($slug)) ?>"><?= htmlspecialchars($def[0], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </section>

  <section class="band">
    <div class="listing-actions">
      <?php foreach ($categories as $slug => $def): ?>
        <?= cms_listing_controls($slug) ?>
      <?php endforeach; ?>
    </div>
    <div class="post-grid">
      <?php foreach ($posts as $post): ?>
        <?php sample_post_card($post); ?>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php sample_footer(); ?>
