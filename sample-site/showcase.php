<?php
require __DIR__ . '/_bootstrap.php';

$featured = array();
foreach (cms_posts() as $post) {
    if (!empty($post['image'])) {
        $featured[] = $post;
    }
}

sample_header('Showcase');
?>
<main>
  <section class="showcase-hero">
    <div class="showcase-copy">
      <?= cms_editable('showcase/intro') ?>
    </div>
    <div class="meta-preview" aria-label="File based featured image metadata">
      <div class="meta-preview-head">content/posts/launch-notes.md</div>
      <pre><code>---
title: Launch notes for the sample site
date: 2026-07-01
category: news
image: /sample-site/working-uploads/2026/07/featured-pagecore.svg
---</code></pre>
    </div>
  </section>

  <section class="band band-alt">
    <div class="section-heading">
      <p class="eyebrow">Featured images</p>
      <h2>Post cards read image paths from Markdown front matter</h2>
    </div>
    <div class="post-grid">
      <?php foreach ($featured as $post): ?>
        <?php sample_post_card($post); ?>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="band">
    <div class="section-heading">
      <p class="eyebrow">File workflow</p>
      <h2>No database table is involved</h2>
    </div>
    <div class="showcase-layout">
      <div class="prose">
        <?= cms_editable('showcase/workflow') ?>
      </div>
      <div class="file-tree" aria-label="Sample file layout">
        <strong>sample-site/working-content</strong>
        <span>pages/showcase/intro.md</span>
        <span>pages/showcase/workflow.md</span>
        <span>posts/launch-notes.md</span>
        <strong>sample-site/working-uploads</strong>
        <span>2026/07/featured-pagecore.svg</span>
      </div>
    </div>
  </section>
</main>
<?php sample_footer(); ?>
