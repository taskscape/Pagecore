<?php
require __DIR__ . '/_bootstrap.php';

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
$post = cms_post($slug);
if (!$post) {
    http_response_code(404);
    sample_header('Post not found');
    echo '<main><section class="page-title"><h1>Post not found</h1></section></main>';
    sample_footer();
    exit;
}

sample_header($post['title']);
?>
<main>
  <article class="article">
    <p class="eyebrow"><?= htmlspecialchars($post['category_label'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($post['date_display'], ENT_QUOTES, 'UTF-8') ?></p>
    <h1><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($post['lead'] !== ''): ?>
      <p class="lead"><?= htmlspecialchars($post['lead'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
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
<?php sample_footer(); ?>
